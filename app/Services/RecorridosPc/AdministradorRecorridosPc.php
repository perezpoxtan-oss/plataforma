<?php

namespace App\Services\RecorridosPc;

use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\Novedad;
use App\Models\RecorridoPc;
use App\Models\RevisionRecorridoPc;
use App\Models\Sede;
use App\Models\User;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los Recorridos de Protección Civil (SEGCAT:
 * recorridos_pc_proceso.php).
 *
 *  - Iniciar (recorridos_pc.crear): sede de su alcance, edificio o zona
 *    opcional de esa sede, observaciones generales. Nace EN PROCESO.
 *  - Punto de inspección (recorridos_pc.crear, uno a la vez): el equipo
 *    escaneado del catálogo (o capturado a mano), el resultado de cada
 *    criterio y observaciones. OK si todos los criterios están sanos y no hay
 *    observación; si no, FALLA. El primer hallazgo abre el ticket de
 *    Protección Civil en la Bitácora de Novedades (con su propio servicio,
 *    AdministradorNovedades); los siguientes se anotan en ese ticket.
 *  - Guardar y Continuar Después / Finalizar Recorrido: finalizar exige al
 *    menos un equipo y deja COMPLETO o CON HALLAZGOS. Un recorrido
 *    finalizado ya no cambia.
 *
 * Todo corre con la empresa de trabajo fijada en el Tenant.
 */
class AdministradorRecorridosPc
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly AdministradorNovedades $novedades,
        private readonly Ubicaciones $ubicaciones,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * @param  Builder<RecorridoPc>  $consulta
     * @return Builder<RecorridoPc>
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
            ->when($sedes !== null, fn ($q) => $q->whereIn('recorridos_pc.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('recorridos_pc.creado_por', $actor->id));
    }

    /** ¿Puede usar el permiso sobre este recorrido? (empresa, sede y, con "propios", autor). */
    public function puede(User $actor, string $permiso, RecorridoPc $recorrido): bool
    {
        return $this->autorizador->puede($actor, $permiso, $recorrido);
    }

    /**
     * Sedes activas donde puede usar el permiso.
     *
     * @return Collection<int, Sede>
     */
    public function sedes(User $actor, string $permiso): Collection
    {
        if (! $actor->can($permiso)) {
            return collect();
        }
        $permitidas = $this->autorizador->sedesPermitidas($actor, $permiso);

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre', 'zona_horaria']);
    }

    // ---------------------------------------------------------------- Escritura

    /**
     * «Nuevo Recorrido»: sede, edificio o zona (opcional) y observaciones generales.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function iniciar(User $actor, array $entrada): RecorridoPc
    {
        $datos = Validator::make($this->limpiar($entrada), [
            'sede_id' => ['required', 'integer'],
            'espacio_id' => ['nullable', 'integer'],
            'observaciones_generales' => ['nullable', 'string', 'max:2000'],
        ], [
            'sede_id.required' => 'Elige la sede donde harás el recorrido.',
            'observaciones_generales.max' => 'Las observaciones generales admiten máximo 2000 caracteres.',
        ])->validate();

        $sedeId = (int) $datos['sede_id'];
        if (! $this->sedes($actor, 'recorridos_pc.crear')->contains('id', $sedeId)) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una de tus sedes activas.']);
        }
        $espacioId = isset($datos['espacio_id']) ? (int) $datos['espacio_id'] : null;
        if ($espacioId !== null && ! Espacio::where('sede_id', $sedeId)->where('nivel', Espacio::EDIFICIO)->where('activo', true)->whereKey($espacioId)->exists()) {
            throw ValidationException::withMessages(['espacio_id' => 'El edificio o zona elegido no pertenece a la sede o está desactivado.']);
        }

        return DB::transaction(function () use ($actor, $sedeId, $espacioId, $datos) {
            $recorrido = new RecorridoPc(['sede_id' => $sedeId, 'espacio_id' => $espacioId, 'observaciones_generales' => $datos['observaciones_generales'] ?? null]);
            $recorrido->numero = (int) RecorridoPc::query()->lockForUpdate()->max('numero') + 1;
            $recorrido->estatus = RecorridoPc::EN_PROCESO;
            $recorrido->save();
            $this->auditoria->auditar($actor, 'recorridos_pc.creado', $recorrido, null, $this->foto($recorrido));

            return $recorrido;
        });
    }

    /**
     * Un punto de inspección. Con equipo_pc_id: el equipo del catálogo
     * (escaneado o elegido de los pendientes); sin él: identificador y
     * categoría capturados a mano (equipo sin etiqueta o fuera del catálogo).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function registrarPunto(User $actor, RecorridoPc $recorrido, array $entrada): RevisionRecorridoPc
    {
        $this->exigirEnProceso($recorrido);
        $entrada = $this->limpiar($entrada);

        $datos = Validator::make($entrada, [
            'equipo_pc_id' => ['nullable', 'integer'],
            'identificador' => ['nullable', 'required_without:equipo_pc_id', 'string', 'max:100'],
            'categoria' => ['nullable', 'required_without:equipo_pc_id', 'string', 'in:'.implode(',', array_keys(EquipoPc::CATEGORIAS))],
            'zona_id' => ['nullable', 'integer'],
            'criterios' => ['nullable', 'array'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'identificador.required_without' => 'Escanea el equipo o escribe su Núm. de Serie / ID.',
            'categoria.required_without' => 'Elige la Categoría del Equipo.',
            'categoria.in' => 'Elige una Categoría del Equipo de la lista.',
            'identificador.max' => 'El identificador admite máximo 100 caracteres.',
            'observaciones.max' => 'Las observaciones admiten máximo 2000 caracteres.',
        ])->validate();

        [$equipo, $identificador, $categoria, $ubicacion] = $this->equipoDelPunto($recorrido, $datos);

        // Cada criterio que no llegue marcado es una falla (SEGCAT los perdía: una casilla desmarcada no se envía)
        $marcados = array_keys(array_filter((array) ($datos['criterios'] ?? []), fn ($v) => $v === '1' || $v === 1 || $v === true || $v === 'on'));
        $criterios = [];
        foreach (array_keys(EquipoPc::criterios($categoria)) as $clave) {
            $criterios[$clave] = in_array($clave, $marcados, true);
        }
        $observaciones = $datos['observaciones'] ?? null;
        $resultado = ! in_array(false, $criterios, true) && $observaciones === null ? RevisionRecorridoPc::OK : RevisionRecorridoPc::FALLA;

        return DB::transaction(function () use ($actor, $recorrido, $equipo, $identificador, $categoria, $ubicacion, $criterios, $resultado, $observaciones) {
            // Se bloquea el recorrido: dos guardias guardando a la vez no abren dos tickets
            $recorrido = RecorridoPc::query()->lockForUpdate()->findOrFail($recorrido->id);
            $this->exigirEnProceso($recorrido);

            $revision = RevisionRecorridoPc::create([
                'recorrido_pc_id' => $recorrido->id, 'equipo_pc_id' => $equipo?->id, 'identificador' => $identificador,
                'categoria' => $categoria, 'ubicacion' => $ubicacion, 'criterios' => $criterios, 'resultado' => $resultado,
                'observaciones' => $observaciones,
            ]);
            if ($revision->esFalla()) {
                $this->reportarHallazgo($actor, $recorrido, $revision);
            }
            $recorrido->touch();

            return $revision;
        });
    }

    /**
     * «Guardar y Continuar Después» (solo observaciones generales) o
     * «Finalizar Recorrido» (exige al menos un equipo).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, RecorridoPc $recorrido, array $entrada, bool $finalizar): RecorridoPc
    {
        $this->exigirEnProceso($recorrido);
        $datos = Validator::make($this->limpiar($entrada), [
            'observaciones_generales' => ['nullable', 'string', 'max:2000'],
        ], ['observaciones_generales.max' => 'Las observaciones generales admiten máximo 2000 caracteres.'])->validate();

        return DB::transaction(function () use ($actor, $recorrido, $datos, $finalizar) {
            $recorrido = RecorridoPc::query()->lockForUpdate()->findOrFail($recorrido->id);
            $this->exigirEnProceso($recorrido);
            $antes = $this->foto($recorrido);
            $recorrido->observaciones_generales = $datos['observaciones_generales'] ?? null;

            if ($finalizar) {
                $puntos = $recorrido->revisiones()->count();
                if ($puntos === 0) {
                    throw ValidationException::withMessages(['finalizar' => 'Agrega al menos un equipo al recorrido antes de finalizarlo.']);
                }
                $conFalla = $recorrido->revisiones()->where('resultado', RevisionRecorridoPc::FALLA)->exists();
                $recorrido->forceFill([
                    'estatus' => $conFalla ? RecorridoPc::CON_HALLAZGOS : RecorridoPc::COMPLETO,
                    'finalizado_en' => now(), 'finalizado_por' => $actor->id,
                ])->save();
                $this->auditoria->auditar($actor, 'recorridos_pc.finalizado', $recorrido, $antes, $this->foto($recorrido) + ['puntos' => $puntos]);
            } elseif ($recorrido->isDirty('observaciones_generales')) {
                $recorrido->save();
                $this->auditoria->auditar($actor, 'recorridos_pc.actualizado', $recorrido, $antes, $this->foto($recorrido));
            }

            return $recorrido;
        });
    }

    // ------------------------------------------------------------------- Apoyo

    private function exigirEnProceso(RecorridoPc $recorrido): void
    {
        if (! $recorrido->enProceso()) {
            throw ValidationException::withMessages(['recorrido' => "El recorrido {$recorrido->folio()} ya se finalizó ({$recorrido->etiquetaEstatus()}): ya no se le pueden agregar puntos ni cambiar. Inicia un recorrido nuevo."]);
        }
    }

    /**
     * El equipo del punto y lo que se copia de él.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: ?EquipoPc, 1: string, 2: string, 3: ?string}
     */
    private function equipoDelPunto(RecorridoPc $recorrido, array $datos): array
    {
        if (isset($datos['equipo_pc_id'])) {
            $equipo = EquipoPc::with('espacio:id,nombre,ruta')->find((int) $datos['equipo_pc_id']);
            if ($equipo === null || (int) $equipo->sede_id !== (int) $recorrido->sede_id) {
                throw ValidationException::withMessages(['equipo_pc_id' => 'Ese equipo no es de la sede de este recorrido. Escanea un equipo de '.($recorrido->sede?->nombre ?? 'esta sede').'.']);
            }
            if (! $equipo->activo) {
                throw ValidationException::withMessages(['equipo_pc_id' => "El equipo {$equipo->numero_serie} está dado de baja en el catálogo: pide que lo reactiven o captúralo a mano."]);
            }
            $ubicacion = $equipo->espacio !== null ? $this->ubicaciones->texto($equipo->espacio) : null;
            $ubicacion = trim(implode(' · ', array_filter([$ubicacion, $equipo->referencia]))) ?: null;

            return [$equipo, $equipo->numero_serie, $equipo->categoria, $ubicacion];
        }

        $identificador = EquipoPc::normalizarSerie((string) $datos['identificador']);
        $ubicacion = null;
        if (isset($datos['zona_id'])) {
            $nodo = Espacio::where('sede_id', $recorrido->sede_id)->where('activo', true)->whereIn('nivel', Ubicaciones::NIVELES)->find((int) $datos['zona_id']);
            if ($nodo === null) {
                throw ValidationException::withMessages(['zona_id' => 'La ubicación elegida no pertenece a la sede del recorrido o está desactivada.']);
            }
            $ubicacion = $this->ubicaciones->texto($nodo);
        }
        // Si el ID sí está en el catálogo de la sede, se liga (aunque se haya tecleado)
        $equipo = EquipoPc::where('sede_id', $recorrido->sede_id)->where('numero_serie', $identificador)->where('activo', true)->first();

        return [$equipo, $identificador, $equipo?->categoria ?? (string) $datos['categoria'], $ubicacion];
    }

    /**
     * Primer hallazgo: abre el ticket de Protección Civil en la Bitácora de
     * Novedades. Siguientes: se anotan en el Minuto a Minuto de ese ticket
     * (si ya se resolvió, se abre uno nuevo para que el hallazgo no se pierda).
     */
    private function reportarHallazgo(User $actor, RecorridoPc $recorrido, RevisionRecorridoPc $revision): void
    {
        $ticket = $recorrido->novedad_id !== null ? Novedad::find($recorrido->novedad_id) : null;
        if ($ticket !== null && ! $ticket->resuelto()) {
            $this->novedades->anotar($ticket, $actor, "Nuevo hallazgo en el Recorrido de Protección Civil {$recorrido->folio()}:\n".$revision->resumenHallazgo(), 'sistema');

            return;
        }

        $permitidas = $this->novedades->sedes($actor, 'crear', 'proteccion_civil');
        if (! $actor->can('novedades.crear') || ($permitidas !== null && ! in_array((int) $recorrido->sede_id, $permitidas, true))) {
            throw ValidationException::withMessages(['observaciones' => 'Este punto tiene un hallazgo y debe abrir un ticket en la Bitácora de Novedades, pero tu rol no puede crear tickets en esta sede. '
                .'Pide a tu supervisor el permiso «Bitácora de novedades → Crear».']);
        }

        $recorrido->loadMissing('sede', 'espacio');
        $edificio = $recorrido->espacio !== null && $recorrido->espacio->activo ? $recorrido->espacio->id : null;
        $ticket = $this->novedades->crear($actor, [
            'sede_id' => $recorrido->sede_id,
            'categoria' => 'proteccion_civil',
            'reportado_por' => mb_substr($actor->name, 0, 150),
            'ubicacion' => 'Ver detalle en Recorrido PC '.$recorrido->folio(),
            'descripcion' => "Hallazgo durante Recorrido de Protección Civil {$recorrido->folio()}:\n".$revision->resumenHallazgo(),
            'area_edificio_id' => $edificio,
            'ocurrio_en' => CarbonImmutable::now($recorrido->sede?->zonaHoraria() ?? config('app.timezone'))->format('Y-m-d\TH:i'),
        ]);
        $this->novedades->anotar($ticket, $actor, "Ticket abierto automáticamente desde el Recorrido de Protección Civil {$recorrido->folio()}.", 'sistema');

        $anterior = $recorrido->novedad_id;
        $recorrido->forceFill(['novedad_id' => $ticket->id])->save();
        $this->auditoria->auditar($actor, 'recorridos_pc.ticket_generado', $recorrido, ['novedad_id' => $anterior], ['novedad_id' => $ticket->id, 'ticket' => $ticket->folio()]);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function limpiar(array $entrada): array
    {
        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $valor = trim($valor);
                $entrada[$campo] = $valor === '' ? null : $valor;
            }
        }

        return $entrada;
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Avance del recorrido: equipos activos del catálogo en su alcance (la
     * sede o, si se eligió, el edificio o zona) contra los ya revisados.
     *
     * @return array{total: int, revisados: int, pendientes: Collection<int, EquipoPc>, fallas: int, puntos: int}
     */
    public function progreso(RecorridoPc $recorrido): array
    {
        $catalogo = EquipoPc::where('sede_id', $recorrido->sede_id)->where('activo', true)
            ->when($recorrido->espacio !== null, fn ($q) => $q->whereIn('espacio_id', $this->ubicaciones->subarbol($recorrido->espacio)))
            ->orderBy('categoria')->orderBy('numero_serie')
            ->get(['id', 'sede_id', 'categoria', 'numero_serie', 'espacio_id', 'referencia', 'activo']);
        $revisados = $recorrido->revisiones->pluck('equipo_pc_id')->filter()->unique();

        return [
            'total' => $catalogo->count(),
            'revisados' => $catalogo->whereIn('id', $revisados)->count(),
            'pendientes' => $catalogo->whereNotIn('id', $revisados)->values(),
            'fallas' => $recorrido->revisiones->where('resultado', RevisionRecorridoPc::FALLA)->count(),
            'puntos' => $recorrido->revisiones->count(),
        ];
    }

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(RecorridoPc $r): array
    {
        return $r->only(['numero', 'sede_id', 'espacio_id', 'estatus', 'observaciones_generales', 'novedad_id'])
            + ['finalizado_en' => $r->finalizado_en?->toIso8601String()];
    }
}
