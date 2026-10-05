<?php

namespace App\Services\Equipos;

use App\Models\Equipo;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Inventarios\Vouchers;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Identificable;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de Equipos de seguridad (réplica de equipo_proceso.php de SEGCAT).
 *
 * Alcance: el equipo pertenece a una sede. Con alcance de sede se ven y se
 * tocan solo los equipos de sus sedes (y se dan de alta solo en ellas); con
 * alcance "propios", además, solo los que el usuario dio de alta.
 *
 * Estados: nace DISPONIBLE; al editar solo se elige DISPONIBLE o EN
 * MANTENIMIENTO. ASIGNADO lo pone Responsivas (asignarPorResponsiva) y
 * BAJA/PERDIDO solo se alcanza con la baja con voucher.
 *
 * Todo corre con la empresa de trabajo fijada en el Tenant.
 */
class AdministradorEquipos
{
    /** Valor del combo "Tipo de equipo" para crear uno nuevo al vuelo (SEGCAT: __nuevo__). */
    public const TIPO_NUEVO = '__nuevo__';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Vouchers $vouchers,
        private readonly Tenant $tenant,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * @param  Builder<Equipo>  $consulta
     * @return Builder<Equipo>
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
        if ($efectivo->alcance === Alcance::Empresa) {
            return $consulta;
        }
        if ($efectivo->sedes !== null) {
            $consulta->whereIn('equipos.sede_id', $efectivo->sedes);
        }
        if ($efectivo->alcance === Alcance::Propios) {
            $consulta->where('equipos.creado_por', $actor->id);
        }

        return $consulta;
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todos los que ve.
     *
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
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

        return $this->limitar(Equipo::query(), $actor, $permiso)->pluck('equipos.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Sedes activas donde el actor puede usar el permiso (alta o cambio de sede).
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permiso): Collection
    {
        $permitidas = $this->autorizador->sedesPermitidas($actor, $permiso);

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre']);
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Equipo
    {
        return DB::transaction(function () use ($actor, $entrada) {
            $datos = $this->validar($actor, $entrada, null);
            $equipo = new Equipo(Arr::except($datos, 'estado'));
            $equipo->estado = 'disponible';
            $equipo->save();

            $this->auditoria->auditar($actor, 'equipos.creado', $equipo, null, $this->foto($equipo));

            return $equipo;
        });
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Equipo $equipo, array $entrada): Equipo
    {
        return DB::transaction(function () use ($actor, $equipo, $entrada) {
            $antes = $this->foto($equipo);
            $datos = $this->validar($actor, $entrada, $equipo);

            $equipo->fill(Arr::except($datos, 'estado'));
            // ASIGNADO y BAJA/PERDIDO no se cambian desde aquí (Responsivas y Reactivar)
            if (in_array($equipo->estado, Equipo::ESTADOS_EDITABLES, true)) {
                $equipo->estado = $datos['estado'];
            }
            $equipo->save();

            $this->auditoria->auditar($actor, 'equipos.actualizado', $equipo, $antes, $this->foto($equipo));

            return $equipo;
        });
    }

    /**
     * Baja con voucher de reposición (Extraviado | Dañado | Robado). El
     * voucher lo emite el servicio común, en la misma transacción.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function darDeBaja(User $actor, Equipo $equipo, array $entrada): VoucherReposicion
    {
        if ($equipo->estado === 'baja') {
            throw ValidationException::withMessages(['motivo' => 'Este equipo ya está dado de baja.']);
        }
        $datos = $this->vouchers->validar($entrada);
        $equipo->loadMissing('tipo:id,nombre');
        $antes = $this->foto($equipo);

        $voucher = $this->vouchers->darDeBaja($actor, $equipo, 'equipo', $equipo->descripcion(), $datos,
            fn () => $equipo->forceFill(['estado' => 'baja'])->save(),
            $this->referenciaCosto($equipo));

        $this->auditoria->auditar($actor, 'equipos.desactivado', $equipo, $antes, $this->foto($equipo) + ['voucher' => $voucher->folio]);

        return $voucher;
    }

    /**
     * Se encontró o se recuperó: vuelve a DISPONIBLE. El voucher se conserva
     * como historial (no se borra ni se modifica), como en SEGCAT.
     */
    public function reactivar(User $actor, Equipo $equipo): void
    {
        if ($equipo->estado !== 'baja') {
            throw ValidationException::withMessages(['estado' => 'Este equipo no está dado de baja.']);
        }
        $equipo->forceFill(['estado' => 'disponible'])->save();
        $this->auditoria->auditar($actor, 'equipos.reactivado', $equipo, ['estado' => 'baja'], ['estado' => 'disponible']);
    }

    /**
     * Punto de enganche para Responsivas (aún no migrado): al prestar un
     * equipo queda ASIGNADO y al devolverlo vuelve a DISPONIBLE. Solo cambia
     * equipos disponibles o asignados (uno en mantenimiento o de baja no se presta).
     */
    public function asignarPorResponsiva(User $actor, Equipo $equipo, bool $asignado): void
    {
        $esperado = $asignado ? 'disponible' : 'asignado';
        if ($equipo->estado !== $esperado) {
            throw ValidationException::withMessages(['equipo' => "El equipo {$equipo->numero_serie} no está ".mb_strtolower(Equipo::ESTADOS[$esperado]).'.']);
        }
        $nuevo = $asignado ? 'asignado' : 'disponible';
        $equipo->forceFill(['estado' => $nuevo])->save();
        $this->auditoria->auditar($actor, $asignado ? 'equipos.asignado' : 'equipos.devuelto', $equipo, ['estado' => $esperado], ['estado' => $nuevo]);
    }

    // --------------------------------------------------------------- Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function validar(User $actor, array $entrada, ?Equipo $actual): array
    {
        $entrada = $this->normalizar($entrada);

        $v = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'tipo_equipo_id' => ['required', 'string', 'max:20'],
            'nombre_tipo_nuevo' => ['nullable', 'required_if:tipo_equipo_id,'.self::TIPO_NUEVO, 'string', 'max:100'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:80'],
            'numero_serie' => ['required', 'string', 'max:100'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'etiqueta_nfc' => ['nullable', 'string', 'max:64'],
            'estado' => ['nullable', 'string'],
        ], [
            'sede_id.required' => 'Elige la sede donde está el equipo.',
            'tipo_equipo_id.required' => 'Elige el tipo de equipo (o «+ Nuevo tipo...»).',
            'nombre_tipo_nuevo.required_if' => 'Escribe el nombre del nuevo tipo de equipo.',
            'numero_serie.required' => 'El número de serie / ID es obligatorio.',
            'numero_serie.max' => 'El número de serie / ID admite máximo 100 caracteres.',
            'costo.numeric' => 'El costo debe ser un número (por ejemplo 4800.00).',
            'costo.min' => 'El costo no puede ser negativo.',
            'costo.max' => 'Revisa el costo: es demasiado alto.',
        ], [
            'marca' => 'marca', 'modelo' => 'modelo', 'observaciones' => 'observaciones', 'etiqueta_nfc' => 'etiqueta NFC / RFID',
        ]);
        $datos = $v->validate();

        $sedeId = $this->sedeValida((int) $datos['sede_id'], $actor, $actual);

        $serie = $datos['numero_serie'];
        $existente = Equipo::with(['tipo:id,nombre', 'sede:id,nombre'])->where('numero_serie', $serie)
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->first();
        if ($existente !== null) {
            throw ValidationException::withMessages(['numero_serie' => "El número de serie «{$serie}» ya está registrado en esta empresa ("
                .trim(($existente->tipo?->nombre ?? 'equipo').' · '.($existente->sede?->nombre ?? ''), ' ·').')'
                .($existente->estaActivo() ? '.' : ', dado de baja: reactívalo en lugar de registrarlo otra vez.')]);
        }

        $etiqueta = $datos['etiqueta_nfc'] ?? null;
        if ($etiqueta !== null) {
            $this->etiquetaLibre($etiqueta, $actual);
        }

        $estado = $datos['estado'] ?? 'disponible';
        if ($actual !== null && in_array($actual->estado, Equipo::ESTADOS_EDITABLES, true) && ! in_array($estado, Equipo::ESTADOS_EDITABLES, true)) {
            throw ValidationException::withMessages(['estado' => 'Elige DISPONIBLE o EN MANTENIMIENTO. La baja se hace con el botón «Dar de baja» (genera voucher).']);
        }

        return [
            'sede_id' => $sedeId,
            'tipo_equipo_id' => $this->tipoValido($actor, $datos['tipo_equipo_id'], $datos['nombre_tipo_nuevo'] ?? null, $actual),
            'marca' => $datos['marca'] ?? null,
            'modelo' => $datos['modelo'] ?? null,
            'numero_serie' => $serie,
            'costo' => isset($datos['costo']) ? number_format((float) $datos['costo'], 2, '.', '') : null,
            'observaciones' => $datos['observaciones'] ?? null,
            'etiqueta_nfc' => $etiqueta,
            'estado' => $estado,
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada): array
    {
        $entrada = array_intersect_key($entrada, array_flip([
            'sede_id', 'tipo_equipo_id', 'nombre_tipo_nuevo', 'marca', 'modelo', 'numero_serie', 'costo', 'observaciones', 'etiqueta_nfc', 'estado',
        ]));
        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $entrada[$campo] = $campo === 'observaciones' ? trim($valor) : trim((string) preg_replace('/\s+/u', ' ', $valor));
            }
        }
        foreach (['marca', 'modelo', 'numero_serie'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = mb_strtoupper($entrada[$campo]);
            }
        }
        if (isset($entrada['tipo_equipo_id']) && ! is_array($entrada['tipo_equipo_id'])) {
            $entrada['tipo_equipo_id'] = (string) $entrada['tipo_equipo_id'];
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $entrada);
    }

    /**
     * La sede debe ser de la empresa, estar activa y dentro de su alcance.
     * Al editar se conserva la actual aunque ya no cumpla.
     */
    private function sedeValida(int $sedeId, User $actor, ?Equipo $actual): int
    {
        if ($actual !== null && $sedeId === (int) $actual->sede_id) {
            return $sedeId;
        }
        $permiso = $actual === null ? 'equipos.crear' : 'equipos.editar';
        if (! $this->sedesParaElegir($actor, $permiso)->contains('id', $sedeId)) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
        }

        return $sedeId;
    }

    /**
     * Tipo del catálogo de la empresa, o uno nuevo (SEGCAT: "+ Nuevo tipo...").
     * Si el nombre ya existía (sin importar mayúsculas) se usa ese y se reactiva.
     */
    private function tipoValido(User $actor, string $valor, ?string $nombreNuevo, ?Equipo $actual): int
    {
        if ($valor === self::TIPO_NUEVO) {
            $nombre = trim((string) $nombreNuevo);
            $tipo = TipoEquipo::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first();
            if ($tipo === null) {
                $tipo = TipoEquipo::create(['nombre' => $nombre]);
                $this->auditoria->auditar($actor, 'equipos.tipo_creado', $tipo, null, ['nombre' => $tipo->nombre]);
            } elseif (! $tipo->activo) {
                $tipo->forceFill(['activo' => true])->save();
            }

            return $tipo->id;
        }

        $id = ctype_digit($valor) ? (int) $valor : 0;
        $tipo = $id > 0 ? TipoEquipo::find($id) : null;
        if ($tipo === null || (! $tipo->activo && $id !== (int) $actual?->tipo_equipo_id)) {
            throw ValidationException::withMessages(['tipo_equipo_id' => 'Elige un tipo de equipo de la lista.']);
        }

        return $tipo->id;
    }

    /**
     * La etiqueta NFC/RFID no puede estar en otro registro de la empresa
     * (equipo, llave, gafete, colaborador…): el lector no sabría cuál es.
     */
    private function etiquetaLibre(string $etiqueta, ?Equipo $actual): void
    {
        foreach (config('lector.tipos', []) as $tipo => $clase) {
            if (! is_subclass_of($clase, Identificable::class) || ! method_exists($clase, 'etiquetaOcupada')) {
                continue;
            }
            $ocupada = $clase::etiquetaOcupada($etiqueta, $clase === Equipo::class ? $actual?->id : null);
            if ($ocupada !== null) {
                $quien = $ocupada->resumenLector()['titulo'];
                $que = ['vehiculo' => 'Vehículo'][$tipo] ?? ucfirst(str_replace('_', ' ', (string) $tipo));
                throw ValidationException::withMessages(['etiqueta_nfc' => "Esa etiqueta NFC / RFID ya está asignada a otro registro: {$que} «{$quien}». Usa otra etiqueta o quítasela primero a ese registro."]);
            }
        }
    }

    // ------------------------------------------------------------------ Lectura

    /** "MARCA|MODELO": con eso se recuerda el costo de reposición. */
    public function referenciaCosto(Equipo $equipo): ?string
    {
        return $equipo->marca !== null && $equipo->modelo !== null ? $equipo->marca.'|'.$equipo->modelo : null;
    }

    /**
     * Monto sugerido para el voucher: lo último que se cobró por esa marca y
     * modelo; si no hay, el costo capturado al equipo. Para una lista, pasa
     * $memoria = costosDeVouchers() y no se consulta por cada equipo.
     *
     * @param  array<string, string>|null  $memoria
     */
    public function costoSugerido(Equipo $equipo, ?array $memoria = null): ?string
    {
        $referencia = $this->referenciaCosto($equipo);
        $recordado = $memoria !== null
            ? ($referencia !== null ? ($memoria[$referencia] ?? null) : null)
            : $this->vouchers->costoSugerido('equipo', $referencia);

        return $recordado ?? ($equipo->costo !== null ? number_format((float) $equipo->costo, 2, '.', '') : null);
    }

    /**
     * Costos recordados por los vouchers de equipos: "MARCA|MODELO" => monto.
     *
     * @return array<string, string>
     */
    public function costosDeVouchers(): array
    {
        return DB::table('costos_reposicion')->where('empresa_id', $this->tenant->empresaId())->where('origen_tipo', 'equipo')
            ->pluck('monto', 'referencia')->map(fn ($m) => number_format((float) $m, 2, '.', ''))->all();
    }

    /**
     * Sugerencias para el formulario (SEGCAT las pedía por AJAX mientras se
     * escribía): marcas y modelos ya usados en la empresa y el costo
     * conocido de cada "MARCA|MODELO" (el último capturado; manda el del voucher).
     *
     * @return array{marcas: list<string>, modelos: list<string>, costos: array<string, string>}
     */
    public function sugerencias(): array
    {
        $filas = Equipo::query()->whereNotNull('marca')->orderBy('id')->get(['marca', 'modelo', 'costo']);
        $costos = [];
        foreach ($filas as $f) {
            if ($f->modelo !== null && $f->costo !== null) {
                $costos[$f->marca.'|'.$f->modelo] = number_format((float) $f->costo, 2, '.', '');
            }
        }
        foreach ($this->costosDeVouchers() as $referencia => $monto) {
            $costos[(string) $referencia] = $monto;
        }

        return [
            'marcas' => $filas->pluck('marca')->unique()->sort()->values()->all(),
            'modelos' => Equipo::query()->whereNotNull('modelo')->distinct()->orderBy('modelo')->pluck('modelo')->all(),
            'costos' => $costos,
        ];
    }

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(Equipo $e): array
    {
        return $e->only(['sede_id', 'tipo_equipo_id', 'marca', 'modelo', 'numero_serie', 'costo', 'observaciones', 'etiqueta_nfc', 'estado']);
    }
}
