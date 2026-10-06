<?php

namespace App\Services\PrestamoLlaves;

use App\Models\Colaborador;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del Préstamo de llaves (réplica de bitacora_llaves_proceso.php de
 * SEGCAT: prestar, recibir, anular y reactivar).
 *
 * Alcance: cada préstamo es de la sede de la llave. Con alcance de sede se
 * ven y se tocan solo los préstamos de sus sedes (y solo se presta en ellas);
 * con alcance "propios", además, solo los que el usuario registró.
 *
 * Todo corre con la empresa de trabajo ya fijada en el Tenant.
 */
class AdministradorPrestamosLlaves
{
    /** Movimientos que muestra el historial de una llave (SEGCAT: 15). */
    public const HISTORIAL_LLAVE = 15;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Sedes en las que el usuario usa el permiso: null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    /**
     * Sedes activas donde el actor puede prestar (las del formulario).
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaPrestar(User $actor): Collection
    {
        $permitidas = $this->sedes($actor, 'prestamo_llaves.crear');

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre', 'codigo']);
    }

    /**
     * @param  Builder<PrestamoLlave>  $consulta
     * @return Builder<PrestamoLlave>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->whereIn('prestamos_llaves.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('prestamos_llaves.creado_por', $actor->id));
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todos los que ve.
     *
     * @param  Collection<int, PrestamoLlave>  $entre  préstamos de la pantalla
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso, Collection $entre): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin) {
            return null;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso];
        if ($efectivo->alcance === Alcance::Empresa || ($efectivo->sedes === null && $efectivo->alcance !== Alcance::Propios)) {
            return null;
        }

        return $this->limitar(PrestamoLlave::query(), $actor, $permiso)->whereIn('prestamos_llaves.id', $entre->pluck('id'))
            ->pluck('prestamos_llaves.id')->map(fn ($id) => (int) $id)->all();
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * Prestar (SEGCAT: accion=prestar). La sede, la llave y el colaborador se
     * revalidan aquí: nunca se confía en lo que filtró la pantalla.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function prestar(User $actor, array $entrada): PrestamoLlave
    {
        $datos = $this->validar($entrada);
        $sede = $this->sedeValida($actor, (int) $datos['sede_id']);
        $colaborador = $this->colaboradorValido((int) $datos['colaborador_id'], $sede);

        try {
            $prestamo = DB::transaction(function () use ($actor, $datos, $sede, $colaborador) {
                // Bloquea la llave mientras se revisa que no esté prestada (dos casetas a la vez)
                $llave = Llave::whereKey((int) $datos['llave_id'])->lockForUpdate()->first();
                $this->llaveDisponible($llave, $sede);

                $prestamo = new PrestamoLlave([
                    'sede_id' => $sede->id,
                    'llave_id' => $llave->id,
                    'colaborador_id' => $colaborador->id,
                    'tipo_garantia' => $datos['tipo_garantia'],
                    'folio_garantia' => $datos['folio_garantia'] ?? null,
                ]);
                $prestamo->forceFill(['estado' => PrestamoLlave::EN_USO, 'prestado_en' => now(), 'entregado_por' => $actor->id])->save();

                return $prestamo;
            });
        } catch (UniqueConstraintViolationException) {
            // La base impide dos préstamos vigentes de la misma llave
            throw ValidationException::withMessages(['llave_id' => 'Esa llave ya está fuera — alguien más la tiene en este momento.']);
        }

        $this->auditoria->auditar($actor, 'prestamo_llaves.creado', $prestamo, null, $this->foto($prestamo));

        return $prestamo;
    }

    /**
     * Recibir la llave en caseta: EN USO → DEVUELTA (SEGCAT no revisaba el
     * estado y podía "recibir" dos veces, cambiando la hora de regreso).
     */
    public function recibir(User $actor, PrestamoLlave $prestamo): void
    {
        if ($prestamo->anulado) {
            throw ValidationException::withMessages(['prestamo' => 'Este préstamo está anulado: no hay llave que recibir.']);
        }
        if ($prestamo->estado !== PrestamoLlave::EN_USO) {
            throw ValidationException::withMessages(['prestamo' => 'Esta llave ya se había recibido en caseta.']);
        }

        $antes = $this->foto($prestamo);
        $prestamo->forceFill(['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now(), 'recibido_por' => $actor->id])->save();
        $this->auditoria->auditar($actor, 'prestamo_llaves.recibido', $prestamo, $antes, $this->foto($prestamo));
    }

    /**
     * Anular, no borrar: para una captura equivocada. Se conserva para la
     * auditoría y la llave vuelve a estar disponible de inmediato. Solo un
     * préstamo EN USO (como el botón de SEGCAT).
     */
    public function anular(User $actor, PrestamoLlave $prestamo): void
    {
        if ($prestamo->anulado) {
            throw ValidationException::withMessages(['prestamo' => 'Este préstamo ya está anulado.']);
        }
        if ($prestamo->estado !== PrestamoLlave::EN_USO) {
            throw ValidationException::withMessages(['prestamo' => 'Solo se anula un préstamo en uso. Este ya se recibió en caseta.']);
        }

        $antes = $this->foto($prestamo);
        $prestamo->forceFill(['anulado' => true, 'anulado_en' => now(), 'anulado_por' => $actor->id])->save();
        $this->auditoria->auditar($actor, 'prestamo_llaves.anulado', $prestamo, $antes, $this->foto($prestamo));
    }

    /**
     * Reactivar un préstamo anulado: vuelve a contar como válido, salvo que
     * esa llave ya se haya prestado otra vez mientras tanto.
     */
    public function reactivar(User $actor, PrestamoLlave $prestamo): void
    {
        if (! $prestamo->anulado) {
            throw ValidationException::withMessages(['prestamo' => 'Este préstamo no está anulado.']);
        }

        $antes = $this->foto($prestamo);
        try {
            DB::transaction(function () use ($prestamo) {
                if ($prestamo->estado === PrestamoLlave::EN_USO) {
                    Llave::whereKey($prestamo->llave_id)->lockForUpdate()->first();
                    $otro = PrestamoLlave::vigentes()->where('llave_id', $prestamo->llave_id)->whereKeyNot($prestamo->id)->exists();
                    if ($otro) {
                        throw ValidationException::withMessages(['prestamo' => 'No se puede reactivar — esa llave ya se volvió a prestar en otro registro mientras tanto.']);
                    }
                }
                $prestamo->forceFill(['anulado' => false, 'anulado_en' => null, 'anulado_por' => null])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['prestamo' => 'No se puede reactivar — esa llave ya se volvió a prestar en otro registro mientras tanto.']);
        }

        $this->auditoria->auditar($actor, 'prestamo_llaves.reactivado', $prestamo, $antes, $this->foto($prestamo));
    }

    // --------------------------------------------------------------- Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(array $entrada): array
    {
        $entrada = array_intersect_key($entrada, array_flip(['sede_id', 'llave_id', 'colaborador_id', 'tipo_garantia', 'folio_garantia']));
        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $valor = trim((string) preg_replace('/\s+/u', ' ', $valor));
                $entrada[$campo] = $valor === '' ? null : $valor;
            }
        }
        if (isset($entrada['folio_garantia']) && is_string($entrada['folio_garantia'])) {
            $entrada['folio_garantia'] = mb_strtoupper($entrada['folio_garantia']);
        }

        return Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'llave_id' => ['required', 'integer'],
            'colaborador_id' => ['required', 'integer'],
            'tipo_garantia' => ['required', 'string', Rule::in(array_keys(PrestamoLlave::GARANTIAS))],
            'folio_garantia' => ['nullable', 'string', 'max:80'],
        ], [
            'sede_id.required' => 'Elige la sede donde se presta la llave.',
            'llave_id.required' => 'Escanea o busca la llave a prestar.',
            'llave_id.integer' => 'Escanea o busca la llave a prestar.',
            'colaborador_id.required' => 'Escanea el gafete o busca al colaborador que se lleva la llave.',
            'colaborador_id.integer' => 'Escanea el gafete o busca al colaborador que se lleva la llave.',
            'tipo_garantia.required' => 'Elige la identificación que deja en garantía (o «Ninguna»).',
            'tipo_garantia.in' => 'Elige la identificación que deja en garantía de la lista.',
            'folio_garantia.max' => 'El folio o detalle de la identificación es muy largo (máximo 80 caracteres).',
        ])->validate();
    }

    private function sedeValida(User $actor, int $sedeId): Sede
    {
        $sede = $this->sedesParaPrestar($actor)->firstWhere('id', $sedeId);
        if ($sede === null) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una de tus sedes activas.']);
        }

        return $sede;
    }

    /**
     * La llave debe existir en la empresa, estar activa, ser de la sede y no
     * estar prestada (un préstamo anulado no cuenta).
     */
    private function llaveDisponible(?Llave $llave, Sede $sede): void
    {
        if ($llave === null) {
            throw ValidationException::withMessages(['llave_id' => 'Esa llave no existe en el catálogo de tu empresa.']);
        }
        if (! $llave->activo) {
            throw ValidationException::withMessages(['llave_id' => "La llave {$llave->nomenclatura} está dada de baja: no se puede prestar."]);
        }
        if ((int) $llave->sede_id !== $sede->id) {
            throw ValidationException::withMessages(['llave_id' => "La llave {$llave->nomenclatura} es de otra sede. Elige la sede correcta o escanea otra llave."]);
        }
        $vigente = PrestamoLlave::vigentes()->with('colaborador:id,nombre,apellido_paterno,apellido_materno')->where('llave_id', $llave->id)->first();
        if ($vigente !== null) {
            throw ValidationException::withMessages(['llave_id' => "Esa llave ya está fuera — la tiene {$vigente->colaborador?->nombreCompleto()} en este momento."]);
        }
    }

    /**
     * Colaborador activo de la empresa (SEGCAT: estatus = 1) que trabaja en
     * la sede o es corporativo (sin sede fija). Los provisionales de la caseta
     * cuentan: Recursos Humanos los valida después y, si eran un duplicado,
     * sus préstamos pasan al registro correcto.
     */
    public function colaboradorValido(int $id, Sede $sede, string $campo = 'colaborador_id'): Colaborador
    {
        $colaborador = Colaborador::with('sedesAdicionales:sedes.id')->find($id);
        if ($colaborador === null || ! $colaborador->activo || $colaborador->fusionado_en_id !== null) {
            throw ValidationException::withMessages([$campo => 'El colaborador elegido no existe en tu empresa o está dado de baja.']);
        }
        $trabajaAhi = $colaborador->sede_id === null || (int) $colaborador->sede_id === $sede->id
            || $colaborador->sedesAdicionales->contains('id', $sede->id);
        if (! $trabajaAhi) {
            throw ValidationException::withMessages([$campo => "{$colaborador->nombreCompleto()} no trabaja en la sede {$sede->nombre}. El colaborador elegido no es válido para esta sede."]);
        }

        return $colaborador;
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Últimos movimientos de una llave (SEGCAT: llave_historial_ajax.php).
     *
     * Con $actor, solo los préstamos dentro de su alcance (Seguridad AZ-04: "solo los propios").
     *
     * @return Collection<int, PrestamoLlave>
     */
    public function historialDe(Llave $llave, ?User $actor = null): Collection
    {
        $consulta = PrestamoLlave::query();
        if ($actor !== null) {
            $consulta = $this->limitar($consulta, $actor, 'prestamo_llaves.ver');
        }

        return $consulta->with(['colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'entrego:id,name', 'recibio:id,name', 'anulo:id,name'])
            ->where('prestamos_llaves.llave_id', $llave->id)->orderByDesc('prestado_en')->orderByDesc('id')
            ->limit(self::HISTORIAL_LLAVE)->get();
    }

    /**
     * Foto para la bitácora de auditoría (sin datos de más).
     *
     * @return array<string, mixed>
     */
    public function foto(PrestamoLlave $p): array
    {
        return [
            'sede_id' => $p->sede_id,
            'llave_id' => $p->llave_id,
            'llave' => $p->loadMissing('llave:id,nomenclatura')->llave?->nomenclatura,
            'colaborador_id' => $p->colaborador_id,
            'garantia' => $p->etiquetaGarantia(),
            'folio_garantia' => $p->folio_garantia,
            'estado' => $p->etiquetaEstado(),
            'prestado_en' => $p->prestado_en?->toIso8601String(),
            'devuelto_en' => $p->devuelto_en?->toIso8601String(),
        ];
    }
}
