<?php

namespace App\Services\PrestamoLlaves;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\Sede;
use App\Models\User;
use App\Services\Activos\CampanaActivos;
use App\Services\Activos\CustodiaActivos;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\AjustesCaseta;
use App\Support\EstadoActivo;
use App\Support\Receptor;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
        // C-16: se presta a un colaborador, a una persona del padrón (p. ej. el técnico de un proveedor) o a una empresa externa
        [$tipoReceptor, $receptor] = $this->receptorValido($entrada, $sede);
        $colaborador = $receptor instanceof Colaborador ? $receptor : null;

        $excepcion = null;
        try {
            $prestamo = DB::transaction(function () use ($actor, $datos, $sede, $colaborador, $tipoReceptor, $receptor, &$excepcion) {
                // Bloquea la llave mientras se revisa que no esté prestada (dos casetas a la vez)
                $llave = Llave::whereKey((int) $datos['llave_id'])->lockForUpdate()->first();
                $this->llaveDisponible($llave, $sede);
                // C-17 (DD-09): reglas de la llave; si no las cumple, se presta solo con motivo
                $reglas = $this->reglasNoCumplidas($llave, $receptor, $sede);
                if ($reglas !== [] && ($datos['excepcion_motivo'] ?? null) === null) {
                    throw ValidationException::withMessages(['excepcion_motivo' => 'La llave '.$llave->nomenclatura.' no cumple sus reglas: '
                        .implode(' ', $reglas).' Si de todos modos se presta, escribe el motivo (queda registrado).']);
                }
                $excepcion = $reglas === [] ? null : ['motivo' => $datos['excepcion_motivo'], 'reglas' => $reglas];

                $prestamo = new PrestamoLlave([
                    'sede_id' => $sede->id,
                    'llave_id' => $llave->id,
                    'colaborador_id' => $colaborador?->id,
                    'receptor_tipo' => $tipoReceptor,
                    'receptor_id' => $receptor->getKey(),
                    'tipo_garantia' => $datos['tipo_garantia'],
                    'folio_garantia' => $datos['folio_garantia'] ?? null,
                    'excepcion_motivo' => $excepcion['motivo'] ?? null,
                ]);
                $prestamo->forceFill(['estado' => PrestamoLlave::EN_USO, 'prestado_en' => now(), 'entregado_por' => $actor->id])->save();

                return $prestamo;
            });
        } catch (UniqueConstraintViolationException) {
            // La base impide dos préstamos vigentes de la misma llave
            throw ValidationException::withMessages(['llave_id' => 'Esa llave ya está fuera — alguien más la tiene en este momento.']);
        }

        $this->auditoria->auditar($actor, 'prestamo_llaves.creado', $prestamo, null, $this->foto($prestamo));
        if ($excepcion !== null) {
            $this->auditoria->auditar($actor, 'prestamo_llaves.excepcion', $prestamo, null, ['llave' => $prestamo->llave?->nomenclatura,
                'no_cumplia' => $excepcion['reglas'], 'motivo' => $excepcion['motivo']]);
        }

        return $prestamo;
    }

    /**
     * Recibir la llave en caseta: EN USO → DEVUELTA (SEGCAT no revisaba el
     * estado y podía "recibir" dos veces, cambiando la hora de regreso).
     * C-14: también una llave marcada «No regresó» que apareció: el préstamo
     * queda DEVUELTA y la llave vuelve a DISPONIBLE (si no se dio de baja).
     */
    public function recibir(User $actor, PrestamoLlave $prestamo): void
    {
        if ($prestamo->anulado) {
            throw ValidationException::withMessages(['prestamo' => 'Este préstamo está anulado: no hay llave que recibir.']);
        }
        if (! in_array($prestamo->estado, [PrestamoLlave::EN_USO, PrestamoLlave::NO_REGRESO], true)) {
            throw ValidationException::withMessages(['prestamo' => 'Esta llave ya se había recibido en caseta.']);
        }

        $antes = $this->foto($prestamo);
        $eraNoRegreso = $prestamo->estado === PrestamoLlave::NO_REGRESO;
        DB::transaction(function () use ($actor, $prestamo, $eraNoRegreso) {
            $prestamo->forceFill(['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now(), 'recibido_por' => $actor->id])->save();
            $llave = Llave::whereKey($prestamo->llave_id)->lockForUpdate()->first();
            if ($eraNoRegreso && $llave !== null && $llave->noRegreso()) {
                $llave->forceFill(['estado' => EstadoActivo::DISPONIBLE])->save();
            }
        });
        $this->auditoria->auditar($actor, 'prestamo_llaves.recibido', $prestamo, $antes, $this->foto($prestamo));
        app(CampanaActivos::class)->resolverLlave($prestamo);
        if ($eraNoRegreso && $prestamo->llave !== null) {
            app(CustodiaActivos::class)->resolverNoRegreso($prestamo->llave, 'llave');
        }
    }

    /**
     * C-14: «No regresó». El préstamo deja de estar EN USO y la llave queda
     * NO REGRESÓ: no se puede volver a prestar hasta que se reciba (si
     * aparece) o se dé de baja con voucher a nombre de quien la tenía. Si
     * quien lo marca no puede dar de baja llaves, lo ve en la campana quien
     * sí puede en esa sede.
     *
     * @return bool ¿quien lo marcó puede darla de baja él mismo?
     */
    public function noRegreso(User $actor, PrestamoLlave $prestamo): bool
    {
        if (! $prestamo->vigente()) {
            throw ValidationException::withMessages(['prestamo' => $prestamo->anulado
                ? 'Este préstamo está anulado: no hay llave que marcar.' : 'Esta llave ya no está en uso (se recibió o ya se marcó «No regresó»).']);
        }

        $antes = $this->foto($prestamo);
        $llave = DB::transaction(function () use ($prestamo) {
            $prestamo->forceFill(['estado' => PrestamoLlave::NO_REGRESO])->save();
            $llave = Llave::whereKey($prestamo->llave_id)->lockForUpdate()->first();
            if ($llave !== null && $llave->activo) {
                $llave->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
            }

            return $llave;
        });
        $this->auditoria->auditar($actor, 'prestamo_llaves.no_regreso', $prestamo, $antes, $this->foto($prestamo));
        app(CampanaActivos::class)->resolverLlave($prestamo);

        $puede = $actor->can('llaves.eliminar') && ($llave === null || app(Autorizador::class)->puede($actor, 'llaves.eliminar', $llave));
        if ($llave !== null && ! $puede) {
            app(CustodiaActivos::class)->avisarNoRegreso($llave, 'llave', 'llave '.$llave->nomenclatura,
                'La tenía '.$prestamo->nombreReceptor().' (préstamo '.$prestamo->folio().'); la marcó '.$actor->name.'.', $actor);
        }

        return $puede;
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
        app(CampanaActivos::class)->resolverLlave($prestamo);
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
                    $llave = Llave::whereKey($prestamo->llave_id)->lockForUpdate()->first();
                    // Revisión Etapa 2 (A-4): si mientras tanto la llave quedó «No regresó» o se dio de baja, el préstamo no vuelve a contar
                    if ($llave !== null && $llave->estado !== EstadoActivo::DISPONIBLE) {
                        throw ValidationException::withMessages(['prestamo' => 'No se puede reactivar — la llave '.$llave->nomenclatura.' ahora está '
                            .EstadoActivo::texto($llave->estado).'. Si ya regresó, recíbela primero.']);
                    }
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
        $receptor = Receptor::deEntrada($entrada);
        $entrada = array_intersect_key($entrada, array_flip(['sede_id', 'llave_id', 'tipo_garantia', 'folio_garantia', 'excepcion_motivo']));
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
            'tipo_garantia' => ['required', 'string', Rule::in(array_keys(PrestamoLlave::GARANTIAS))],
            'folio_garantia' => ['nullable', 'string', 'max:80'],
            'excepcion_motivo' => ['nullable', 'string', 'max:250'],
        ], [
            'excepcion_motivo.max' => 'El motivo es muy largo (máximo 250 caracteres).',
            'sede_id.required' => 'Elige la sede donde se presta la llave.',
            'llave_id.required' => 'Escanea o busca la llave a prestar.',
            'llave_id.integer' => 'Escanea o busca la llave a prestar.',
            'tipo_garantia.required' => 'Elige la identificación que deja en garantía (o «Ninguna»).',
            'tipo_garantia.in' => 'Elige la identificación que deja en garantía de la lista.',
            'folio_garantia.max' => 'El folio o detalle de la identificación es muy largo (máximo 80 caracteres).',
        ])->after(function ($v) use ($receptor) {
            if ($receptor === null) {
                $v->errors()->add('colaborador_id', 'Escanea el gafete o busca al colaborador que se lleva la llave.');
            }
        })->validate();
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
        if ($llave->noRegreso()) {
            throw ValidationException::withMessages(['llave_id' => "La llave {$llave->nomenclatura} está marcada «No regresó»: si ya apareció, recíbela en Préstamo de llaves antes de prestarla."]);
        }
        if ((int) $llave->sede_id !== $sede->id) {
            throw ValidationException::withMessages(['llave_id' => "La llave {$llave->nomenclatura} es de otra sede. Elige la sede correcta o escanea otra llave."]);
        }
        $vigente = PrestamoLlave::vigentes()->with('colaborador:id,nombre,apellido_paterno,apellido_materno')->where('llave_id', $llave->id)->first();
        if ($vigente !== null) {
            throw ValidationException::withMessages(['llave_id' => "Esa llave ya está fuera — la tiene {$vigente->nombreReceptor()} en este momento."]);
        }
    }

    /**
     * C-17 (DD-09): lo que la llave pide y no se cumple ahora: caducidad,
     * horario (en la hora de la sede), departamento o puesto objetivo y
     * responsable permanente. Lista vacía = se presta sin más.
     *
     * @return list<string>
     */
    public function reglasNoCumplidas(Llave $llave, Model $receptor, Sede $sede, ?\DateTimeInterface $ahora = null): array
    {
        $zona = (Sede::find($sede->id) ?? $sede)->zonaHoraria();
        $local = Carbon::instance($ahora ?? now())->setTimezone($zona);
        $reglas = [];

        if ($llave->fecha_caducidad !== null && $llave->fecha_caducidad->format('Y-m-d') < $local->format('Y-m-d')) {
            $reglas[] = 'está VENCIDA desde el '.$llave->fecha_caducidad->format('d/m/Y').'.';
        }
        $horarios = $llave->horarios()->get();
        if ($horarios->isNotEmpty()) {
            $hora = $local->format('H:i');
            $dentro = $horarios->contains(fn ($h) => $h->cruzaMedianoche()
                ? ($hora >= $h->inicio() || $hora < $h->fin())
                : ($hora >= $h->inicio() && $hora < $h->fin()));
            if (! $dentro) {
                $reglas[] = 'está fuera de su horario ('.$horarios->map(fn ($h) => $h->inicio().'–'.$h->fin())->join(', ').').';
            }
        }
        $colaborador = $receptor instanceof Colaborador ? $receptor : null;
        if ($llave->puesto_id !== null && (int) ($colaborador?->puesto_id) !== (int) $llave->puesto_id) {
            $reglas[] = 'es para el puesto «'.($llave->puesto?->nombre ?? '—').'».';
        } elseif ($llave->puesto_id === null && $llave->departamento_id !== null && (int) ($colaborador?->departamento_id) !== (int) $llave->departamento_id) {
            $reglas[] = 'es para el departamento «'.($llave->departamento?->nombre ?? '—').'».';
        }
        if ($llave->colaborador_id !== null && (int) ($colaborador?->id) !== (int) $llave->colaborador_id) {
            $reglas[] = 'está ASIGNADA a '.($llave->colaborador?->nombreCompleto() ?? '—').' (responsable permanente).';
        }

        return $reglas;
    }

    /**
     * C-16: a quién se le entrega. Un colaborador sigue las reglas de siempre
     * (activo y de la sede); una persona del padrón o una empresa externa debe
     * estar activa y sin acceso restringido.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: string, 1: Model}
     */
    public function receptorValido(array $entrada, Sede $sede, string $mensaje = 'Escanea el gafete o busca al colaborador que se lleva la llave.'): array
    {
        $receptor = Receptor::deEntrada($entrada);
        if ($receptor === null) {
            throw ValidationException::withMessages(['colaborador_id' => $mensaje]);
        }
        [$tipo, $id] = $receptor;
        if ($tipo === Receptor::COLABORADOR) {
            return [$tipo, $this->colaboradorValido($id, $sede)];
        }

        return [$tipo, Receptor::buscar($tipo, $id, 'colaborador_id')];
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

    // ------------------------------------------ Semáforo de llaves fuera (ADR-0014)

    /**
     * Cuánto lleva fuera y su color según Configuración → Caseta y activos:
     * null = sin semáforo o en tiempo; ambar = recordatorio; rojo = escalado.
     *
     * @param  array<string, int|string>  $ajustes  AjustesCaseta::de()
     * @return array{horas: float, nivel: ?string}
     */
    public function semaforo(PrestamoLlave $p, array $ajustes, ?\DateTimeInterface $ahora = null): array
    {
        $horas = $p->prestado_en === null ? 0.0 : max(0, $p->prestado_en->diffInMinutes($ahora ?? now(), true)) / 60;

        return ['horas' => $horas, 'nivel' => $p->vigente()
            ? AjustesCaseta::nivel($horas, (int) $ajustes['llaves_horas_aviso'], (int) $ajustes['llaves_horas_escalar']) : null];
    }

    /** ¿La empresa usa el semáforo de llaves? */
    public static function conSemaforo(array $ajustes): bool
    {
        return (int) $ajustes['llaves_horas_aviso'] > 0 || (int) $ajustes['llaves_horas_escalar'] > 0;
    }

    /**
     * Recordatorios en la campana de las llaves que pasaron sus horas
     * (comando plataforma:activos-pendientes, en proceso desde desplegar.sh).
     * Cada préstamo avisa una vez en ámbar (a la caseta) y una en rojo (al jefe);
     * si ya estaba en rojo la primera vez, salen los dos.
     *
     * @return int préstamos recordados
     */
    public function recordatorios(): int
    {
        $cuenta = 0;
        foreach (Empresa::where('activo', true)->get(['id', 'preferencias']) as $empresa) {
            $ajustes = AjustesCaseta::de($empresa);
            if (! self::conSemaforo($ajustes)) {
                continue;
            }
            $cuenta += app(Tenant::class)->conEmpresa($empresa->id, function () use ($ajustes) {
                $n = 0;
                foreach (PrestamoLlave::vigentes()->orderBy('prestado_en')->get() as $p) {
                    ['horas' => $horas, 'nivel' => $nivel] = $this->semaforo($p, $ajustes);
                    if ($nivel === null) {
                        continue;
                    }
                    $campana = app(CampanaActivos::class);
                    // La caseta siempre se entera (aunque ya haya pasado directo a rojo); en rojo, además, el jefe.
                    // Cada nivel sale una sola vez
                    $avisados = $campana->llaveFuera($p, 'ambar', $horas);
                    if ($nivel === 'rojo') {
                        $avisados += $campana->llaveFuera($p, 'rojo', $horas);
                    }
                    $n += $avisados > 0 ? 1 : 0;
                }

                return $n;
            });
        }

        return $cuenta;
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
            'receptor' => $p->receptor_tipo !== null && $p->receptor_tipo !== Receptor::COLABORADOR ? $p->nombreReceptor() : null,
            'garantia' => $p->etiquetaGarantia(),
            'folio_garantia' => $p->folio_garantia,
            'estado' => $p->etiquetaEstado(),
            'excepcion' => $p->excepcion_motivo,
            'prestado_en' => $p->prestado_en?->toIso8601String(),
            'devuelto_en' => $p->devuelto_en?->toIso8601String(),
        ];
    }
}
