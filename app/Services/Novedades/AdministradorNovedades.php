<?php

namespace App\Services\Novedades;

use App\Models\Colaborador;
use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundReportePerdida;
use App\Models\Novedad;
use App\Models\NovedadNota;
use App\Models\RoboDetalle;
use App\Models\Sede;
use App\Models\SiniestroDetalle;
use App\Models\User;
use App\Services\Novedades\Formatos\Accidente;
use App\Services\Novedades\Formatos\Formato;
use App\Services\Novedades\Formatos\LostFound;
use App\Services\Novedades\Formatos\RecorridoPc;
use App\Services\Novedades\Formatos\ReporteGeneral;
use App\Services\Novedades\Formatos\Robo;
use App\Services\Novedades\Formatos\Siniestro;
use App\Services\Novedades\Formatos\ValoresVista;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de la Bitácora de Novedades (SEGCAT: novedades_proceso.php).
 *
 * Permisos: "novedades.*" da acceso a todas las categorías; "lost_found.*"
 * (submódulo) permite trabajar SOLO los tickets de Lost & Found, como en
 * SEGCAT (por ejemplo, Ama de Llaves). Con alcance de sede se ve y se toca
 * solo lo de sus sedes; con "propios", solo lo que él registró.
 *
 * Estatus: ABIERTO ⇄ PENDIENTE DE TURNO → RESUELTO (exige resolución y fija
 * la fecha de cierre). Un caso RESUELTO no se edita: primero "Reabrir Caso"
 * (novedades.reabrir) con justificación, que queda en el Minuto a Minuto.
 */
class AdministradorNovedades
{
    /** categoría => formato que la atiende */
    public const FORMATOS = [
        'incidente_general' => ReporteGeneral::class,
        'accidente' => Accidente::class,
        'habitacion' => ValoresVista::class,
        'proteccion_civil' => Siniestro::class,
        'recorrido_pc' => RecorridoPc::class,
        'lost_found' => LostFound::class,
        'robo' => Robo::class,
    ];

    /** Cuántos casos resueltos se muestran en el historial (el resto, en Exportar). */
    public const MAX_RESUELTOS = 200;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    public function formato(string $categoria): ?Formato
    {
        $clase = self::FORMATOS[$categoria] ?? null;

        return $clase === null ? null : app($clase);
    }

    // ------------------------------------------------------------------ Alcance

    /**
     * Cómo aplica un permiso al actor: null = no lo tiene.
     *
     * @return array{sedes: list<int>|null, propios: bool}|null
     */
    private function condicion(User $actor, string $permiso): ?array
    {
        if ($actor->es_superadmin) {
            return ['sedes' => null, 'propios' => false];
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return null;
        }

        return ['sedes' => $this->autorizador->sedesPermitidas($actor, $permiso), 'propios' => $efectivo->alcance === Alcance::Propios];
    }

    /** ¿Tiene la acción en novedades o en Lost & Found? */
    public function puedeModulo(User $actor, string $accion): bool
    {
        return $actor->can('novedades.'.$accion) || $actor->can('lost_found.'.$accion);
    }

    /** Solo trabaja Lost & Found (tiene lost_found.accion pero no novedades.accion). */
    public function soloLostFound(User $actor, string $accion = 'ver'): bool
    {
        return ! $actor->can('novedades.'.$accion) && $actor->can('lost_found.'.$accion);
    }

    /**
     * Limita una consulta de novedades a las que el actor puede tocar con una acción.
     *
     * @param  Builder<Novedad>  $consulta
     * @return Builder<Novedad>
     */
    public function limitar(Builder $consulta, User $actor, string $accion): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }
        $general = $this->condicion($actor, 'novedades.'.$accion);
        $lostFound = $this->condicion($actor, 'lost_found.'.$accion);
        if ($general === null && $lostFound === null) {
            return $consulta->whereRaw('1 = 0');
        }

        // Un grupo sin condiciones se descartaría del OR: se fuerza "verdadero"
        $aplicar = function (Builder $q, array $c) use ($actor) {
            $q->whereRaw('1 = 1')
                ->when($c['sedes'] !== null, fn ($x) => $x->whereIn('novedades.sede_id', $c['sedes']))
                ->when($c['propios'], fn ($x) => $x->where('novedades.creado_por', $actor->id));
        };

        return $consulta->where(function (Builder $q) use ($general, $lostFound, $aplicar) {
            if ($general !== null) {
                $q->orWhere(fn (Builder $x) => $aplicar($x, $general));
            }
            if ($lostFound !== null) {
                $q->orWhere(fn (Builder $x) => $aplicar($x->where('novedades.categoria', 'lost_found'), $lostFound));
            }
        });
    }

    /**
     * ¿Puede el actor hacer la acción sobre este ticket? (misma regla que limitar).
     */
    public function permite(User $actor, string $accion, Novedad $novedad): bool
    {
        foreach (['novedades' => null, 'lost_found' => 'lost_found'] as $modulo => $soloCategoria) {
            if ($soloCategoria !== null && $novedad->categoria !== $soloCategoria) {
                continue;
            }
            $c = $this->condicion($actor, $modulo.'.'.$accion);
            if ($c === null || ($c['sedes'] !== null && ! in_array((int) $novedad->sede_id, $c['sedes'], true))
                || ($c['propios'] && (int) $novedad->creado_por !== (int) $actor->id)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Ticket en el alcance de la acción; de otra empresa o fuera del alcance: 404.
     */
    public function buscar(User $actor, int $id, string $accion): Novedad
    {
        $novedad = $this->limitar(Novedad::query(), $actor, $accion)->find($id);
        abort_if($novedad === null, 404);

        return $novedad;
    }

    /**
     * Sedes donde puede usar la acción: null = todas; [] = ninguna. Con una
     * categoría que no es Lost & Found solo cuenta el permiso general.
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $accion, ?string $categoria = null): ?array
    {
        $general = $this->condicion($actor, 'novedades.'.$accion);
        $lostFound = $categoria === null || $categoria === 'lost_found' ? $this->condicion($actor, 'lost_found.'.$accion) : null;
        if ($general === null && $lostFound === null) {
            return [];
        }
        if (($general !== null && $general['sedes'] === null) || ($lostFound !== null && $lostFound['sedes'] === null)) {
            return null;
        }

        return array_values(array_unique([...($general['sedes'] ?? []), ...($lostFound['sedes'] ?? [])]));
    }

    /**
     * Categorías que el actor puede elegir al despachar (crear) o al atender
     * (editar) un ticket.
     *
     * @return list<string>
     */
    public function categorias(User $actor, string $accion, ?Novedad $novedad = null): array
    {
        $general = $novedad === null ? $actor->can('novedades.'.$accion) : $this->permiteGeneral($actor, $accion, $novedad);
        $lista = $general ? Novedad::CATEGORIAS_ELEGIBLES : ['lost_found'];
        // Robo — seguimiento (submódulo "robo"): atiende su caso sin cambiarle la categoría
        if (! $general && $novedad !== null && $novedad->categoria === 'robo' && $this->permiteRobo($actor, $accion, $novedad)) {
            return ['robo'];
        }
        // Recorrido PC solo se conserva en los tickets que ya la traían
        if ($novedad !== null && $novedad->categoria === 'recorrido_pc' && $general) {
            $lista[] = 'recorrido_pc';
        }

        return $lista;
    }

    // ------------------------------------------------- Robo — seguimiento

    /**
     * Casos de Robo que el actor puede tocar con "robo.{accion}" (submódulo
     * Robo — seguimiento), con su alcance de sede o de propios.
     *
     * @param  Builder<Novedad>  $consulta
     * @return Builder<Novedad>
     */
    public function limitarRobo(Builder $consulta, User $actor, string $accion): Builder
    {
        $consulta->where('novedades.categoria', 'robo');
        if ($actor->es_superadmin) {
            return $consulta;
        }
        $c = $this->condicion($actor, 'robo.'.$accion);
        if ($c === null) {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta
            ->when($c['sedes'] !== null, fn ($q) => $q->whereIn('novedades.sede_id', $c['sedes']))
            ->when($c['propios'], fn ($q) => $q->where('novedades.creado_por', $actor->id));
    }

    /** ¿Puede hacer "robo.{accion}" sobre este caso? (misma regla que limitarRobo). */
    public function permiteRobo(User $actor, string $accion, Novedad $novedad): bool
    {
        if ($novedad->categoria !== 'robo') {
            return false;
        }
        $c = $this->condicion($actor, 'robo.'.$accion);

        return $c !== null && ($c['sedes'] === null || in_array((int) $novedad->sede_id, $c['sedes'], true))
            && (! $c['propios'] || (int) $novedad->creado_por === (int) $actor->id);
    }

    private function permiteGeneral(User $actor, string $accion, Novedad $novedad): bool
    {
        $c = $this->condicion($actor, 'novedades.'.$accion);

        return $c !== null && ($c['sedes'] === null || in_array((int) $novedad->sede_id, $c['sedes'], true))
            && (! $c['propios'] || (int) $novedad->creado_por === (int) $actor->id);
    }

    // ---------------------------------------------------------------- Escritura

    /**
     * Despachar Ticket (SEGCAT: accion=crear).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Novedad
    {
        // Ronda 8 (NV-01): el ticket no se despacha sin clasificación (SEGCAT lo
        // dejaba «Sin clasificar»); quien solo tiene una categoría (Lost & Found) no elige
        $permitidas = array_values(array_diff($this->categorias($actor, 'crear'), ['sin_clasificar']));
        if (count($permitidas) === 1) {
            $entrada['categoria'] = $permitidas[0];
        }
        $v = $this->validarBase($entrada, true, $permitidas);
        $categoria = $v['categoria'];

        $sede = $this->sedeValida($actor, (int) $v['sede_id'], $categoria);
        $novedad = new Novedad([
            'sede_id' => $sede->id,
            'categoria' => $categoria,
            'reportado_por' => mb_strtoupper($v['reportado_por']),
            'reportado_colaborador_id' => $this->colaboradorValido($v['reportado_colaborador_id'] ?? null),
            'ubicacion' => mb_strtoupper($v['ubicacion']),
            'descripcion' => $v['descripcion'],
        ]);
        $novedad->setRelation('sede', $sede);
        $this->aplicarComunes($novedad, $v);

        DB::transaction(function () use ($novedad) {
            $novedad->numero = $this->siguienteNumero();
            $novedad->save();
            // Robo vive también en su pantalla de seguimiento: el caso existe ahí desde el primer momento
            if ($novedad->categoria === 'robo') {
                RoboDetalle::create(['novedad_id' => $novedad->id]);
            }
        });

        $this->auditoria->auditar($actor, 'novedades.creado', $novedad, null, $this->foto($novedad));

        return $novedad;
    }

    /**
     * Guardar Expediente (SEGCAT: accion=actualizar): preguntas base, formato
     * de la categoría, nota del Minuto a Minuto y estatus.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Novedad $novedad, array $entrada): Novedad
    {
        if ($novedad->resuelto()) {
            throw ValidationException::withMessages(['estatus' => 'Este caso ya está Resuelto. Para editarlo usa «Reabrir Caso para Editar» y escribe el motivo.']);
        }
        $novedad->loadMissing('sede');
        $antes = $this->foto($novedad);
        $estatusAntes = $novedad->estatus;

        $permitidas = $this->categorias($actor, 'editar', $novedad);
        $v = $this->validarBase($entrada, false);
        if (! in_array($v['categoria'], $permitidas, true)) {
            throw ValidationException::withMessages(['categoria' => 'Elige una de las categorías de la lista.']);
        }
        if ($v['estatus'] === Novedad::RESUELTO && $this->texto($v['resolucion'] ?? null) === null) {
            throw ValidationException::withMessages(['resolucion' => 'Para marcar el caso como Resuelto, primero escribe cómo se concluyó en «Estatus Final / Resolución».']);
        }

        $novedad->categoria = $v['categoria'];
        $this->aplicarComunes($novedad, $v);
        $novedad->resolucion = $this->texto($v['resolucion'] ?? null);
        if (array_key_exists('area_especifica_id', $v)) {
            $novedad->area_especifica_id = $this->habitacionValida($v['area_especifica_id'], $novedad);
        }

        // El formato se valida con el ticket ya ajustado (su Área General, su sede)
        $formato = $this->formato($novedad->categoria);
        if ($formato !== null) {
            $novedad->loadMissing($formato->relaciones());
        }
        $datosFormato = $formato?->validar($entrada, $novedad);
        $nota = $this->texto($v['nueva_nota'] ?? null);

        DB::transaction(function () use ($actor, $novedad, $v, $formato, $datosFormato, $nota, $estatusAntes) {
            $novedad->estatus = $v['estatus'];
            if ($v['estatus'] === Novedad::RESUELTO) {
                $novedad->cerrado_en = now();
                $novedad->cerrado_por = $actor->id;
            }
            $novedad->save();
            $formato?->guardar($novedad, $datosFormato, $actor);

            if ($nota !== null) {
                $this->anotar($novedad, $actor, $nota);
            }
            if ($estatusAntes !== $novedad->estatus) {
                $this->anotar($novedad, $actor, $novedad->resuelto()
                    ? 'CERRÓ EL CASO — Resolución: '.$novedad->resolucion
                    : 'Cambió el estatus a '.$novedad->etiquetaEstatus().'.', 'sistema');
            }
            if ($novedad->categoria === 'proteccion_civil') {
                $this->generarAccidente($actor, $novedad);
            }
        });

        $despues = $this->foto($novedad);
        if ($antes !== $despues || $nota !== null || $datosFormato !== null) {
            $this->auditoria->auditar($actor, $novedad->resuelto() && $estatusAntes !== Novedad::RESUELTO ? 'novedades.resuelto' : 'novedades.actualizado',
                $novedad, $antes, $despues + ($nota !== null ? ['nota' => $nota] : []));
        }

        return $novedad;
    }

    /**
     * Reabrir Caso (solo un caso Resuelto, con motivo).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function reabrir(User $actor, Novedad $novedad, array $entrada): void
    {
        $v = Validator::make($entrada, ['motivo' => ['required', 'string', 'min:5', 'max:1000']], [
            'motivo.required' => 'Escribe el motivo antes de continuar: un caso resuelto no se puede reabrir sin justificación.',
            'motivo.min' => 'Explica un poco más el motivo de la reapertura (mínimo 5 letras).',
            'motivo.max' => 'El motivo es muy largo (máximo 1000 caracteres).',
        ])->validate();
        if (! $novedad->resuelto()) {
            throw ValidationException::withMessages(['motivo' => 'Este caso no está Resuelto: no hace falta reabrirlo.']);
        }

        $motivo = $this->texto($v['motivo']);
        DB::transaction(function () use ($actor, $novedad, $motivo) {
            $novedad->forceFill(['estatus' => Novedad::ABIERTO, 'cerrado_en' => null, 'cerrado_por' => null])->save();
            $this->anotar($novedad, $actor, 'REABRIÓ EL CASO — Motivo: '.$motivo, 'reapertura');
        });
        $this->auditoria->auditar($actor, 'novedades.reabierto', $novedad, ['estatus' => Novedad::RESUELTO], ['estatus' => Novedad::ABIERTO, 'motivo' => $motivo]);
    }

    /**
     * Vincular un reporte de pérdida con un artículo encontrado. No cierra el
     * artículo: la entrega sigue pasando por "Cerrar / Entregar".
     */
    public function vincularPerdida(User $actor, LostFoundReportePerdida $reporte, LostFoundArticulo $articulo): void
    {
        if ($reporte->estatus !== LostFoundReportePerdida::BUSCANDO) {
            throw ValidationException::withMessages(['articulo' => "El reporte {$reporte->folio} ya no está en búsqueda."]);
        }
        if (! $articulo->enResguardo()) {
            throw ValidationException::withMessages(['articulo' => "El artículo {$articulo->folio} ya no está en resguardo."]);
        }
        DB::transaction(function () use ($actor, $reporte, $articulo) {
            $reporte->forceFill(['estatus' => LostFoundReportePerdida::VINCULADO, 'articulo_vinculado_id' => $articulo->id,
                'vinculado_en' => now(), 'vinculado_por' => $actor->id])->save();
            $this->anotar($reporte->novedad, $actor, "Vinculó el reporte de pérdida {$reporte->folio} con el hallazgo {$articulo->folio} ({$articulo->objeto}).", 'sistema');
        });
        $this->auditoria->auditar($actor, 'lost_found.vinculado', $reporte, ['estatus' => LostFoundReportePerdida::BUSCANDO],
            ['estatus' => LostFoundReportePerdida::VINCULADO, 'articulo' => $articulo->folio]);
    }

    /**
     * Robo: resultó que el objeto solo se extravió y ya se encontró.
     */
    public function vincularRobo(User $actor, Novedad $robo, LostFoundArticulo $articulo): void
    {
        if ($robo->categoria !== 'robo') {
            throw ValidationException::withMessages(['articulo' => 'Solo un caso de Robo se puede vincular con un hallazgo.']);
        }
        if ($robo->resuelto()) {
            throw ValidationException::withMessages(['articulo' => 'Este caso ya está Resuelto. Reábrelo antes de vincular un hallazgo.']);
        }
        if (! $articulo->enResguardo()) {
            throw ValidationException::withMessages(['articulo' => "El artículo {$articulo->folio} ya no está en resguardo."]);
        }
        DB::transaction(function () use ($actor, $robo, $articulo) {
            RoboDetalle::updateOrCreate(['novedad_id' => $robo->id], ['articulo_vinculado_id' => $articulo->id]);
            $this->anotar($robo, $actor, "Vinculó el hallazgo {$articulo->folio} ({$articulo->objeto}) con este caso de robo.", 'sistema');
        });
        $this->auditoria->auditar($actor, 'novedades.hallazgo_vinculado', $robo, null, ['articulo' => $articulo->folio]);
    }

    /**
     * Agrega una línea al Minuto a Minuto ("[fecha] autor: texto").
     */
    public function anotar(Novedad $novedad, User $actor, string $texto, string $tipo = 'nota'): NovedadNota
    {
        return NovedadNota::create([
            'novedad_id' => $novedad->id, 'tipo' => $tipo, 'autor_nombre' => mb_substr($actor->name, 0, 150), 'texto' => mb_substr($texto, 0, 5000),
        ]);
    }

    /**
     * Siniestro con lesionados: abre (una sola vez) un ticket de Accidente
     * ligado, para documentar a cada persona lesionada.
     */
    private function generarAccidente(User $actor, Novedad $siniestro): void
    {
        $detalle = SiniestroDetalle::where('novedad_id', $siniestro->id)->first();
        if ($detalle === null || ! $detalle->hubo_lesionados || ! $detalle->num_lesionados || $detalle->accidente_novedad_id !== null) {
            return;
        }

        $accidente = new Novedad([
            'sede_id' => $siniestro->sede_id, 'categoria' => 'accidente', 'reportado_por' => mb_strtoupper(mb_substr($actor->name, 0, 150)),
            'area_id' => $siniestro->area_id, 'area_especifica_id' => $siniestro->area_especifica_id, 'ocurrio_en' => $siniestro->ocurrio_en,
            'ubicacion' => 'VER DETALLE EN SINIESTRO PC '.$siniestro->folio(),
            'descripcion' => "Lesionado(s) durante siniestro de Protección Civil (ticket {$siniestro->folio()}) — {$detalle->num_lesionados} persona(s) reportada(s).",
            'origen_novedad_id' => $siniestro->id,
        ]);
        $accidente->numero = $this->siguienteNumero();
        $accidente->save();
        $detalle->forceFill(['accidente_novedad_id' => $accidente->id])->save();

        $this->anotar($siniestro, $actor, "Se abrió el ticket de Accidente {$accidente->folio()} para documentar a los lesionados.", 'sistema');
        $this->anotar($accidente, $actor, "Ticket abierto automáticamente desde el Siniestro de Protección Civil {$siniestro->folio()}.", 'sistema');
        $this->auditoria->auditar($actor, 'novedades.creado', $accidente, null, $this->foto($accidente) + ['origen' => $siniestro->folio()]);
    }

    // --------------------------------------------------------------- Validación

    /**
     * Preguntas base del ticket.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validarBase(array $entrada, bool $alta, array $categoriasAlta = []): array
    {
        $entrada = array_map(fn ($v) => is_string($v) ? trim((string) preg_replace('/[ \t]+/u', ' ', $v)) : $v, $entrada);
        $reglas = [
            'asignado_a' => ['nullable', 'integer'],
            'area_edificio_id' => ['nullable', 'integer'],
            'area_piso_id' => ['nullable', 'integer'],
            'involucrados' => ['nullable', 'string', 'max:255'],
            'ocurrio_en' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'como_sucedio' => ['nullable', 'string', 'max:5000'],
        ];
        $reglas += $alta ? [
            'sede_id' => ['required', 'integer'],
            'categoria' => ['required', 'string', 'in:'.implode(',', $categoriasAlta)],
            'reportado_por' => ['required', 'string', 'max:150'],
            'reportado_colaborador_id' => ['nullable', 'integer'],
            'ubicacion' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:5000'],
        ] : [
            'categoria' => ['required', 'string'],
            'area_especifica_id' => ['nullable', 'integer'],
            'nueva_nota' => ['nullable', 'string', 'max:2000'],
            'estatus' => ['required', 'in:'.implode(',', array_keys(Novedad::ESTATUS))],
            'resolucion' => ['nullable', 'string', 'max:5000'],
        ];

        return Validator::make($entrada, $reglas, [
            'sede_id.required' => 'Elige la sede.',
            'categoria.required' => 'Elige la clasificación del ticket (Categoría). Si aún no estás seguro, elige la más cercana: se puede corregir al atenderlo.',
            'categoria.in' => 'Elige la clasificación del ticket (Categoría). Si aún no estás seguro, elige la más cercana: se puede corregir al atenderlo.',
            'reportado_por.required' => 'Escribe quién reporta (o toca «Fui yo quien lo observó»).',
            'ubicacion.required' => 'Escribe la ubicación específica (por ejemplo: Piso 2, cerca del elevador).',
            'descripcion.required' => 'Describe qué sucedió.',
            'estatus.in' => 'Elige un estatus de la lista.',
            'max' => 'El campo «:attribute» es muy largo (máximo :max caracteres).',
            'date_format' => 'Revisa la fecha y hora de «:attribute».',
        ], [
            'sede_id' => 'Sede', 'reportado_por' => '¿Quién reporta?', 'asignado_a' => '¿A quién se canaliza?', 'area_edificio_id' => 'Área General (edificio)', 'area_piso_id' => 'Área General (piso)',
            'area_especifica_id' => 'Habitación específica', 'ubicacion' => 'Ubicación específica', 'involucrados' => '¿Involucra a más personas o áreas?',
            'ocurrio_en' => '¿Cuándo sucedió?', 'descripcion' => '¿Qué sucedió?', 'como_sucedio' => '¿Cómo sucedió?', 'nueva_nota' => 'Minuto a Minuto',
            'estatus' => 'Estatus Final del Expediente', 'resolucion' => 'Estatus Final / Resolución', 'categoria' => 'Categoría',
        ])->validate();
    }

    /**
     * Lo que se captura igual al despachar y al atender.
     *
     * @param  array<string, mixed>  $v
     */
    private function aplicarComunes(Novedad $novedad, array $v): void
    {
        // Un campo que no llegó (p. ej. desde otro proceso) conserva lo que ya tenía el ticket
        if (array_key_exists('asignado_a', $v)) {
            $novedad->asignado_a = $this->asignadoValido($v['asignado_a'], (int) $novedad->sede_id, $novedad->asignado_a);
        }
        if (array_key_exists('area_edificio_id', $v) || array_key_exists('area_piso_id', $v)) {
            $novedad->area_id = $this->areaValida($v['area_edificio_id'] ?? null, $v['area_piso_id'] ?? null, (int) $novedad->sede_id, $novedad->area_id);
        }
        if (array_key_exists('involucrados', $v)) {
            $novedad->involucrados = $this->texto($v['involucrados'], true);
        }
        if (array_key_exists('como_sucedio', $v)) {
            $novedad->como_sucedio = $this->texto($v['como_sucedio']);
        }
        if (array_key_exists('ocurrio_en', $v)) {
            $novedad->ocurrio_en = $this->momento($v['ocurrio_en'], $novedad);
        }
    }

    private function sedeValida(User $actor, int $sedeId, string $categoria): Sede
    {
        $sede = Sede::find($sedeId);
        $permitidas = $this->sedes($actor, 'crear', $categoria);
        if ($sede === null || ! $sede->activo || ($permitidas !== null && ! in_array($sedeId, $permitidas, true))) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una de tus sedes activas.']);
        }

        return $sede;
    }

    /**
     * Ronda 8 (NV-01): acciones que distinguen al personal de Seguridad en la
     * Bitácora (atiende: Agente, Supervisor, Jefe; o la supervisa: Director,
     * Administrador). Lo decide el motor de permisos, no el nombre del rol.
     */
    public const ACCIONES_PERSONAL_SEGURIDAD = ['editar', 'reabrir', 'exportar'];

    /**
     * «¿A quién se canaliza?»: usuarios activos de la empresa con rol de
     * Seguridad en la Bitácora, en toda la empresa o en alguna de esas sedes
     * (antes: cualquier usuario, p. ej. Recursos Humanos). Una consulta.
     *
     * @param  list<int>  $sedes
     * @return Collection<int, array{id: int, nombre: string, sedes: string}>
     */
    public function personalCanalizable(array $sedes): Collection
    {
        $empresaId = app(Tenant::class)->empresaId();
        $asignaciones = DB::table('usuario_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->join('roles as r', 'r.id', '=', 'ur.rol_id')
            ->where('u.empresa_id', $empresaId)->where('u.activo', true)->where('r.activo', true)
            ->where(fn ($q) => $q->where('r.empresa_id', $empresaId)->orWhereNull('r.empresa_id'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('rol_permisos as rp')
                ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
                ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
                ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->whereColumn('rp.rol_id', 'r.id')->where('m.clave', 'novedades')
                ->whereIn('a.clave', self::ACCIONES_PERSONAL_SEGURIDAD))
            ->get(['u.id', 'u.name', 'ur.sede_id'])
            ->groupBy('id');

        return $asignaciones->map(function ($filas) {
            $todas = $filas->contains(fn ($f) => $f->sede_id === null);

            return ['id' => (int) $filas->first()->id, 'nombre' => $filas->first()->name,
                'sedes' => $todas ? 'todas' : $filas->pluck('sede_id')->unique()->join(' ')];
        })
            ->filter(fn ($u) => $u['sedes'] === 'todas' || array_intersect(explode(' ', $u['sedes']), array_map('strval', $sedes)) !== [])
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * ¿A quién se canaliza?: personal de Seguridad activo de la empresa con
     * acceso a la sede (rol en toda la empresa o en esa sede). SEGCAT lo
     * ignoraba en silencio. Quien ya tenía el ticket se conserva.
     */
    private function asignadoValido(mixed $id, int $sedeId, ?int $actual): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = (int) $id;
        if ($id === (int) $actual) {
            return $id;
        }
        if (! $this->personalCanalizable([$sedeId])->contains('id', $id)) {
            throw ValidationException::withMessages(['asignado_a' => 'Solo se canaliza al personal de Seguridad activo de esa sede (agentes, supervisores, jefes o mandos).']);
        }

        return $id;
    }

    /**
     * Área General (SEGCAT: edificio y sección): el piso si se eligió, si no
     * el edificio. Deben ser de la sede y estar activos; el piso, de ese
     * edificio. Lo que ya tenía el ticket se conserva aunque hoy esté inactivo.
     */
    private function areaValida(mixed $edificio, mixed $piso, int $sedeId, ?int $actual): ?int
    {
        $edificio = $edificio === null || $edificio === '' ? null : (int) $edificio;
        $piso = $piso === null || $piso === '' ? null : (int) $piso;
        $id = $piso ?? $edificio;
        if ($id === null) {
            return null;
        }
        if ($id === (int) $actual) {
            return $id;
        }
        $nodo = Espacio::where('sede_id', $sedeId)->where('activo', true)->find($id);
        $valido = $nodo !== null && ($piso === null
            ? $nodo->nivel === Espacio::EDIFICIO
            : $nodo->nivel === Espacio::AREA && ($edificio === null || (int) $nodo->padre_id === $edificio));
        if (! $valido) {
            throw ValidationException::withMessages(['area_edificio_id' => 'El Área General elegida (edificio y piso) no pertenece a la sede o está desactivada.']);
        }

        return $id;
    }

    /** Habitación específica: de la sede y, si hay Área General, dentro de ella. */
    private function habitacionValida(mixed $id, Novedad $novedad): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = (int) $id;
        if ($id === (int) $novedad->getOriginal('area_especifica_id') && (int) $novedad->area_id === (int) $novedad->getOriginal('area_id')) {
            return $id;
        }
        $habitacion = Espacio::where('nivel', Espacio::AREA_ESPECIFICA)->where('sede_id', $novedad->sede_id)->find($id);
        if ($habitacion === null || ($novedad->area_id !== null && ! str_contains((string) $habitacion->ruta, '/'.$novedad->area_id.'/'))) {
            throw ValidationException::withMessages(['area_especifica_id' => 'La habitación elegida no pertenece al Área General (edificio y piso) del ticket.']);
        }

        return $id;
    }

    private function colaboradorValido(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $colaborador = Colaborador::where('activo', true)->whereNull('fusionado_en_id')->find((int) $id);
        if ($colaborador === null) {
            throw ValidationException::withMessages(['reportado_colaborador_id' => 'El colaborador que reporta no existe en esta empresa o está dado de baja.']);
        }

        return $colaborador->id;
    }

    /**
     * ¿Cuándo sucedió? llega en la hora local de la sede; se guarda en UTC.
     * No puede ser posterior a este momento.
     */
    private function momento(?string $valor, Novedad $novedad): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $momento = Carbon::createFromFormat('Y-m-d\TH:i', $valor, $novedad->zonaSede())->utc();
        if ($momento->greaterThan(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['ocurrio_en' => '«¿Cuándo sucedió?» no puede ser una fecha u hora que todavía no llega.']);
        }

        return $momento;
    }

    private function texto(mixed $valor, bool $mayusculas = false): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $limpio = trim((string) preg_replace('/[ \t]+/u', ' ', $valor));

        return $limpio === '' ? null : ($mayusculas ? mb_strtoupper($limpio) : $limpio);
    }

    /** Consecutivo de la empresa (#00001, #00002…). */
    private function siguienteNumero(): int
    {
        return (int) Novedad::query()->lockForUpdate()->max('numero') + 1;
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(Novedad $novedad): array
    {
        return $novedad->only([
            'numero', 'sede_id', 'categoria', 'estatus', 'reportado_por', 'reportado_colaborador_id', 'asignado_a', 'area_id', 'area_especifica_id',
            'ubicacion', 'involucrados', 'descripcion', 'como_sucedio', 'resolucion',
        ]) + ['ocurrio_en' => $novedad->ocurrio_en?->toIso8601String()];
    }
}
