<?php

namespace App\Services\Responsivas;

use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Responsiva;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Equipos\AdministradorEquipos;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reglas de Responsivas y firmas (réplica de responsiva_proceso.php de
 * SEGCAT: prestar_lote y devolver_lote).
 *
 * - Un resguardo (lote) es de una sede y de un colaborador, con uno o más
 *   equipos DISPONIBLES de esa sede y la firma del colaborador (obligatoria,
 *   en el disco privado).
 * - Al guardarlo, cada equipo pasa a ASIGNADO (AdministradorEquipos::
 *   asignarPorResponsiva) y al recibir el lote completo vuelve a DISPONIBLE.
 *
 * Alcance: como Préstamo de llaves, por la sede del resguardo.
 */
class AdministradorResponsivas
{
    /** Equipos por resguardo (evita formularios inflados a mano). */
    public const MAX_EQUIPOS = 25;

    /** Movimientos que muestra el historial de un equipo. */
    public const HISTORIAL_EQUIPO = 15;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly AdministradorEquipos $equipos,
        private readonly AdministradorPrestamosLlaves $prestamos,
        private readonly Firmas $firmas,
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
     * Sedes activas donde el actor puede crear resguardos.
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaCrear(User $actor): Collection
    {
        $permitidas = $actor->can('responsivas.crear') ? $this->autorizador->sedesPermitidas($actor, 'responsivas.crear') : [];

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre', 'codigo']);
    }

    /**
     * @param  Builder<Responsiva>  $consulta
     * @return Builder<Responsiva>
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
            ->when($sedes !== null, fn ($q) => $q->whereIn('responsivas.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('responsivas.creado_por', $actor->id));
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todos los que ve.
     *
     * @param  Collection<int, Responsiva>  $entre
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

        return $this->limitar(Responsiva::query(), $actor, $permiso)->whereIn('responsivas.id', $entre->pluck('id'))
            ->pluck('responsivas.id')->map(fn ($id) => (int) $id)->all();
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * Nuevo resguardo (lote) con la firma del colaborador (SEGCAT: prestar_lote).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Responsiva
    {
        $datos = $this->validar($entrada);
        $sede = $this->sedesParaCrear($actor)->firstWhere('id', (int) $datos['sede_id']);
        if ($sede === null) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una de tus sedes activas.']);
        }
        $colaborador = $this->prestamos->colaboradorValido((int) $datos['colaborador_id'], $sede);
        $filas = $this->filasValidas($datos);
        $this->equiposDisponibles(array_keys($filas), $sede);

        // La firma se guarda al final de las validaciones; si algo falla después, se borra
        $firma = $this->firmas->guardar($datos['firma'] ?? null, 'responsivas', 'firma', 'la firma del colaborador');

        try {
            $responsiva = DB::transaction(function () use ($actor, $sede, $colaborador, $filas, $firma) {
                $numero = (int) Responsiva::query()->lockForUpdate()->max('numero') + 1;
                $responsiva = new Responsiva(['sede_id' => $sede->id, 'colaborador_id' => $colaborador->id]);
                $responsiva->forceFill([
                    'numero' => $numero,
                    'folio' => Responsiva::folioPara($sede, $numero),
                    'firma_ruta' => $firma,
                    'estado' => Responsiva::EN_CAMPO,
                    'entregado_en' => now(),
                    'entregado_por' => $actor->id,
                ])->save();

                foreach ($filas as $equipoId => $modalidad) {
                    // Bloquea el equipo y revisa otra vez su estado dentro de la transacción
                    $equipo = Equipo::whereKey($equipoId)->lockForUpdate()->firstOrFail();
                    $this->equipos->asignarPorResponsiva($actor, $equipo, true);
                    $responsiva->equipos()->create(['equipo_id' => $equipoId, 'modalidad' => $modalidad]);
                }

                return $responsiva;
            });
        } catch (UniqueConstraintViolationException) {
            $this->firmas->borrar($firma);
            throw ValidationException::withMessages(['equipos' => 'Uno de los equipos acaba de entregarse en otro resguardo. Revisa la lista e intenta de nuevo.']);
        } catch (Throwable $e) {
            $this->firmas->borrar($firma);
            throw $e;
        }

        $this->auditoria->auditar($actor, 'responsivas.creado', $responsiva, null, $this->foto($responsiva));

        return $responsiva;
    }

    /**
     * "Recibir Lote Completo (OK)": todos los equipos vuelven a DISPONIBLE
     * (SEGCAT: devolver_lote). Un equipo que se dio de baja mientras estaba
     * en campo se registra como tal y no cambia de estado.
     *
     * @return int equipos que regresaron a DISPONIBLE
     */
    public function recibir(User $actor, Responsiva $responsiva): int
    {
        if (! $responsiva->enCampo()) {
            throw ValidationException::withMessages(['responsiva' => "El resguardo {$responsiva->folio} ya se había recibido."]);
        }
        $antes = $this->foto($responsiva);

        $regresaron = DB::transaction(function () use ($actor, $responsiva) {
            $regresaron = 0;
            $ahora = now();
            foreach ($responsiva->equipos()->whereNull('devuelto_en')->get() as $fila) {
                $equipo = Equipo::whereKey($fila->equipo_id)->lockForUpdate()->first();
                $estado = 'ok';
                if ($equipo !== null && $equipo->estado === 'asignado') {
                    $this->equipos->asignarPorResponsiva($actor, $equipo, false);
                    $regresaron++;
                } elseif ($equipo !== null && $equipo->estado === 'baja') {
                    $estado = 'baja';
                }
                $fila->forceFill(['devuelto_en' => $ahora, 'estado_devolucion' => $estado])->save();
            }
            $responsiva->forceFill(['estado' => Responsiva::DEVUELTA, 'devuelto_en' => $ahora, 'recibido_por' => $actor->id])->save();

            return $regresaron;
        });

        $this->auditoria->auditar($actor, 'responsivas.recibido', $responsiva, $antes, $this->foto($responsiva));

        return $regresaron;
    }

    /**
     * Ronda 8 (RS-04): «Recibir» un equipo del lote (devolución parcial).
     *  - ok: vuelve a DISPONIBLE.
     *  - danado: queda EN MANTENIMIENTO, o de BAJA con voucher si se pide.
     *  - faltante: siempre de BAJA con voucher de reposición (motivo extraviado o robado).
     * El voucher lo emite el flujo común (AdministradorEquipos::darDeBaja →
     * Inventarios\Vouchers::darDeBaja); el responsable del cobro, si aplica, es
     * el resguardante. Cuando regresa el último equipo, el lote pasa a
     * LOTE CERRADO (Historial Devueltos).
     *
     * @param  array<string, mixed>  $entrada  estado_recepcion, nota, generar_voucher, motivo, aplica_cobro, monto, firma_modo, firmas
     * @return array{estado: string, voucher: ?VoucherReposicion, cerrado: bool, faltan: int}
     */
    public function recibirEquipo(User $actor, Responsiva $responsiva, EquipoResponsiva $fila, array $entrada, bool $puedeVoucher): array
    {
        if (! $responsiva->enCampo() || $fila->responsiva_id !== $responsiva->id) {
            throw ValidationException::withMessages(['estado_recepcion' => "El resguardo {$responsiva->folio} ya se había recibido."]);
        }
        if (! $fila->pendiente()) {
            throw ValidationException::withMessages(['estado_recepcion' => 'Este equipo ya se había recibido.']);
        }
        $datos = Validator::make($entrada, [
            'estado_recepcion' => ['required', Rule::in(EquipoResponsiva::ESTADOS_AL_RECIBIR)],
            'nota' => ['nullable', 'required_unless:estado_recepcion,ok', 'string', 'max:500'],
            'generar_voucher' => ['nullable', 'boolean'],
        ], [
            'estado_recepcion.required' => 'Elige cómo regresa el equipo: OK, Dañado o Faltante.',
            'estado_recepcion.in' => 'Elige cómo regresa el equipo: OK, Dañado o Faltante.',
            'nota.required_unless' => 'Escribe una nota: qué daño tiene o qué pasó con el equipo.',
            'nota.max' => 'La nota es muy larga (máximo 500 caracteres).',
        ])->validate();
        $estado = $datos['estado_recepcion'];
        $nota = isset($datos['nota']) ? trim((string) $datos['nota']) : null;
        $conVoucher = $estado === 'faltante' || ($estado === 'danado' && (bool) ($datos['generar_voucher'] ?? false));
        if ($conVoucher && ! $puedeVoucher) {
            throw ValidationException::withMessages(['generar_voucher' => 'Tu rol no puede dar de baja con voucher. '
                .($estado === 'faltante' ? 'Pide a un supervisor que reciba este equipo faltante.' : 'Recíbelo como dañado sin voucher (queda EN MANTENIMIENTO).')]);
        }
        $antes = $this->foto($responsiva);

        $resultado = DB::transaction(function () use ($actor, $responsiva, $fila, $entrada, $estado, $nota, $conVoucher) {
            $equipo = Equipo::with('tipo:id,nombre')->whereKey($fila->equipo_id)->lockForUpdate()->first();
            $voucher = null;
            $final = $estado;
            if ($equipo === null || $equipo->estado === 'baja') {
                $final = 'baja';
            } elseif ($conVoucher) {
                $motivos = $estado === 'faltante' ? ['extraviado', 'robado'] : ['danado'];
                $voucher = $this->equipos->darDeBaja($actor, $equipo, [
                    'motivo' => in_array($entrada['motivo'] ?? null, $motivos, true) ? $entrada['motivo'] : $motivos[0],
                    'descripcion' => 'Responsiva '.$responsiva->folio.': '.$nota,
                    'colaborador_id' => ! empty($entrada['aplica_cobro']) ? $responsiva->colaborador_id : null,
                ] + array_intersect_key($entrada, array_flip(['aplica_cobro', 'monto', 'firma_modo', 'firma_seguridad', 'firma_responsable'])));
            } elseif ($estado === 'danado') {
                $this->equipos->recibirDanadoDeResponsiva($actor, $equipo);
            } else {
                $this->equipos->asignarPorResponsiva($actor, $equipo, false);
            }
            $fila->forceFill(['devuelto_en' => now(), 'estado_devolucion' => $final, 'nota_devolucion' => $nota,
                'recibido_por' => $actor->id, 'voucher_id' => $voucher?->id])->save();

            $faltan = $responsiva->equipos()->whereNull('devuelto_en')->count();
            if ($faltan === 0) {
                $responsiva->forceFill(['estado' => Responsiva::DEVUELTA, 'devuelto_en' => now(), 'recibido_por' => $actor->id])->save();
            }

            return ['estado' => $final, 'voucher' => $voucher, 'cerrado' => $faltan === 0, 'faltan' => $faltan, 'serie' => $equipo?->numero_serie];
        });

        $this->auditoria->auditar($actor, 'responsivas.equipo_recibido', $responsiva, null, [
            'folio' => $responsiva->folio, 'equipo' => $resultado['serie'], 'estado' => EquipoResponsiva::ESTADOS_DEVOLUCION[$resultado['estado']],
            'nota' => $nota, 'voucher' => $resultado['voucher']?->folio, 'faltan' => $resultado['faltan'],
        ]);
        if ($resultado['cerrado']) {
            $this->auditoria->auditar($actor, 'responsivas.recibido', $responsiva, $antes, $this->foto($responsiva));
        }

        return $resultado;
    }

    // --------------------------------------------------------------- Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(array $entrada): array
    {
        $entrada = array_intersect_key($entrada, array_flip(['sede_id', 'colaborador_id', 'equipos', 'modalidades', 'firma']));

        return Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'colaborador_id' => ['required', 'integer'],
            'equipos' => ['required', 'array', 'min:1', 'max:'.self::MAX_EQUIPOS],
            'equipos.*' => ['nullable', 'integer'],
            'modalidades' => ['nullable', 'array', 'max:'.self::MAX_EQUIPOS],
            'modalidades.*' => ['nullable', 'string', Rule::in(array_keys(EquipoResponsiva::MODALIDADES))],
            'firma' => ['nullable', 'string'],
        ], [
            'sede_id.required' => 'Elige la sede de origen.',
            'colaborador_id.required' => 'Escanea el gafete o busca al colaborador responsable.',
            'colaborador_id.integer' => 'Escanea el gafete o busca al colaborador responsable.',
            'equipos.required' => 'Debe añadir al menos 1 equipo al lote.',
            'equipos.min' => 'Debe añadir al menos 1 equipo al lote.',
            'equipos.max' => 'Un resguardo admite hasta '.self::MAX_EQUIPOS.' equipos.',
            'equipos.*.integer' => 'Seleccione un equipo en todas las filas agregadas.',
            'modalidades.*.in' => 'Elige «Turno» o «Fijo» en cada equipo.',
        ])->validate();
    }

    /**
     * equipo_id => modalidad, sin filas vacías ni repetidas.
     *
     * @param  array<string, mixed>  $datos
     * @return array<int, string>
     */
    private function filasValidas(array $datos): array
    {
        $equipos = array_values($datos['equipos'] ?? []);
        $modalidades = array_values($datos['modalidades'] ?? []);
        $filas = [];
        foreach ($equipos as $i => $id) {
            if ($id === null || $id === '') {
                throw ValidationException::withMessages(['equipos' => 'Seleccione un equipo en todas las filas agregadas (o quite la fila vacía).']);
            }
            $id = (int) $id;
            if (isset($filas[$id])) {
                throw ValidationException::withMessages(['equipos' => 'Un mismo equipo está en dos filas. Quita la fila repetida.']);
            }
            $filas[$id] = $modalidades[$i] ?? 'prestado';
        }
        if ($filas === []) {
            throw ValidationException::withMessages(['equipos' => 'Debe añadir al menos 1 equipo al lote.']);
        }

        return $filas;
    }

    /**
     * Cada equipo debe ser de la empresa, de la sede elegida, estar DISPONIBLE
     * y no seguir en otro resguardo sin recibir.
     *
     * @param  list<int>  $ids
     */
    private function equiposDisponibles(array $ids, Sede $sede): void
    {
        $equipos = Equipo::with(['resguardoActual.responsiva:id,folio'])->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $equipo = $equipos[$id] ?? null;
            if ($equipo === null) {
                throw ValidationException::withMessages(['equipos' => 'Uno de los equipos no existe en el inventario de tu empresa.']);
            }
            if ((int) $equipo->sede_id !== $sede->id) {
                throw ValidationException::withMessages(['equipos' => "El equipo {$equipo->numero_serie} es de otra sede: solo se resguardan equipos de {$sede->nombre}."]);
            }
            if ($equipo->resguardoActual !== null) {
                throw ValidationException::withMessages(['equipos' => "El equipo {$equipo->numero_serie} sigue en el resguardo {$equipo->resguardoActual->responsiva?->folio} sin recibir."]);
            }
            if ($equipo->estado !== 'disponible') {
                throw ValidationException::withMessages(['equipos' => "El equipo {$equipo->numero_serie} no está DISPONIBLE (está ".(Equipo::ESTADOS[$equipo->estado] ?? $equipo->estado).').']);
            }
        }
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Últimos resguardos de un equipo (SEGCAT: equipo_historial_ajax.php).
     *
     * @return Collection<int, EquipoResponsiva>
     */
    public function historialDe(Equipo $equipo, ?User $actor = null): Collection
    {
        return EquipoResponsiva::query()
            // Seguridad (AZ-04): con $actor, solo las responsivas dentro de su alcance ("solo los propios")
            ->when($actor !== null, fn ($q) => $q->whereHas('responsiva', fn ($r) => $this->limitar($r, $actor, 'responsivas.ver')))
            ->with(['responsiva:id,folio,colaborador_id,entregado_en,entregado_por,recibido_por,devuelto_en',
                'responsiva.colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'responsiva.entrego:id,name', 'responsiva.recibio:id,name'])
            ->where('equipo_id', $equipo->id)->orderByDesc('id')->limit(self::HISTORIAL_EQUIPO)->get();
    }

    /**
     * Foto para la bitácora de auditoría (sin la ruta de la firma).
     *
     * @return array<string, mixed>
     */
    public function foto(Responsiva $r): array
    {
        $r->load('equipos.equipo:id,numero_serie');

        return [
            'folio' => $r->folio,
            'sede_id' => $r->sede_id,
            'colaborador_id' => $r->colaborador_id,
            'estado' => $r->enCampo() ? 'EN CAMPO' : 'LOTE CERRADO',
            'equipos' => $r->equipos->map(fn (EquipoResponsiva $f) => ($f->equipo?->numero_serie ?? '#'.$f->equipo_id).' ('.$f->etiquetaModalidad().')'.($f->estado_devolucion ? ' '.mb_strtoupper($f->estado_devolucion) : ''))->all(),
            'entregado_en' => $r->entregado_en?->toIso8601String(),
            'devuelto_en' => $r->devuelto_en?->toIso8601String(),
        ];
    }
}
