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
use App\Services\Espacios\Lugares;
use App\Services\Novedades\Formatos\Accidente;
use App\Services\Novedades\Formatos\Formato;
use App\Services\Novedades\Formatos\LostFound;
use App\Services\Novedades\Formatos\MantenimientoPc;
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
        'mantenimiento_pc' => MantenimientoPc::class,
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
        // Recorrido PC y Mantenimiento de equipo PC solo se conservan en los tickets que ya la traían
        if ($novedad !== null && isset(Novedad::CATEGORIAS_AUTOMATICAS[$novedad->categoria]) && $general) {
            $lista[] = $novedad->categoria;
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
    public function crear(User $actor, array $entrada, ?string $categoriaAutomatica = null): Novedad
    {
        // Ronda 8 (NV-01): el ticket no se despacha sin clasificación (SEGCAT lo
        // dejaba «Sin clasificar»); quien solo tiene una categoría (Lost & Found) no elige
        $permitidas = array_values(array_diff($this->categorias($actor, 'crear'), ['sin_clasificar']));
        // Mejoras Incidentes: la plataforma abre tickets de una categoría que no se elige a mano (hallazgo de recorrido)
        if ($categoriaAutomatica !== null && isset(Novedad::CATEGORIAS_AUTOMATICAS[$categoriaAutomatica]) && $actor->can('novedades.crear')) {
            $permitidas[] = $categoriaAutomatica;
            $entrada['categoria'] = $categoriaAutomatica;
        }
        if (count($permitidas) === 1) {
            $entrada['categoria'] = $permitidas[0];
        }
        $v = $this->validarBase($entrada, true, $permitidas);
        $categoria = $v['categoria'];

        $sede = $this->sedeValida($actor, (int) $v['sede_id'], $categoria);
        // C-30: el lugar del árbol (zona, piso, habitación…) y un detalle opcional
        $lugar = null;
        if (! empty($v['lugar_id'])) {
            $lugar = app(Lugares::class)->valido($v['lugar_id'], [(int) $sede->id]);
            if ($lugar === null) {
                throw ValidationException::withMessages(['lugar_id' => 'Elige un lugar de la lista (Zonas y edificios de esa sede).']);
            }
        }
        // Lo escrito en «¿Dónde pasó?» sin elegirlo de la lista se queda como texto (sin catálogo)
        $detalle = $this->texto($v['ubicacion'] ?? null, true) ?? ($lugar === null ? $this->texto($v['lugar_texto'] ?? null, true) : null);
        $novedad = new Novedad([
            'sede_id' => $sede->id,
            'categoria' => $categoria,
            'reportado_por' => mb_strtoupper($v['reportado_por']),
            'reportado_colaborador_id' => $this->colaboradorValido($v['reportado_colaborador_id'] ?? null),
            'ubicacion' => mb_strtoupper(mb_substr($lugar !== null ? app(Lugares::class)->camino($lugar).($detalle ? ' · '.$detalle : '') : (string) $detalle, 0, 255)),
            'descripcion' => $v['descripcion'],
        ]);
        $novedad->setRelation('sede', $sede);
        $this->aplicarComunes($novedad, $v);
        if ($lugar !== null) {
            $this->ubicarEnArbol($novedad, $lugar);
        }

        // Mejoras Incidentes: el objeto encontrado se captura desde el ticket rápido (no queda un ticket a medias)
        $objeto = $categoria === 'lost_found' ? $this->objetoDelAlta($entrada) : null;

        DB::transaction(function () use ($novedad, $objeto, $actor) {
            $novedad->numero = $this->siguienteNumero();
            $novedad->save();
            // Robo vive también en su pantalla de seguimiento: el caso existe ahí desde el primer momento
            if ($novedad->categoria === 'robo') {
                RoboDetalle::create(['novedad_id' => $novedad->id]);
            }
            if ($objeto !== null) {
                $this->guardarObjeto($novedad, $objeto, $actor);
            }
        });

        $this->auditoria->auditar($actor, 'novedades.creado', $novedad, null, $this->foto($novedad));
        app(AvisosNovedades::class)->canalizado($novedad, $actor);
        if ($novedad->departamento_id !== null) {
            $novedad->loadMissing('departamento:id,nombre');
            $this->anotar($novedad, $actor, 'Canalizado a '.$novedad->departamento?->nombre.'.', 'sistema');
            app(CanalizarDepartamento::class)->avisar($novedad, $actor); // C-28
        }

        return $novedad;
    }

    // ----------------------------------------- Mejoras Incidentes: objeto encontrado

    /**
     * «Registrar objeto encontrado» (Lost & Found): crea por detrás el ticket de
     * Lost & Found con su artículo en un solo paso. Con «novedad_id», agrega el
     * objeto a un ticket de Lost & Found que todavía no tiene artículos (los
     * tickets a medias se completan sin abrir el expediente).
     *
     * @param  array<string, mixed>  $entrada  sede_id, objeto, tipo_valor, marca, color, ubicacion, ubicacion_bodega, reportado_por, reportado_colaborador_id, ocurrio_en | novedad_id
     * @return array{0: Novedad, 1: LostFoundArticulo}
     */
    public function registrarObjeto(User $actor, array $entrada): array
    {
        if (empty($entrada['novedad_id'])) {
            // Lo indispensable de una vez (criterio 8), con los mismos mensajes del ticket rápido
            Validator::make($entrada, [
                'sede_id' => ['required'], 'lf_objeto' => ['required'], 'ubicacion' => ['required'], 'reportado_por' => ['required'],
            ], [
                'sede_id.required' => 'Elige la sede.',
                'lf_objeto.required' => 'Escribe qué objeto se encontró (por ejemplo: paraguas, cartera, celular).',
                'ubicacion.required' => 'Escribe dónde se encontró (por ejemplo: Lobby, junto a los sillones).',
                'reportado_por.required' => 'Escribe quién lo encontró o lo entregó (o toca «Fui yo»).',
            ])->validate();
        }
        $objeto = $this->objetoDelAlta($entrada, true);
        if (! empty($entrada['novedad_id'])) {
            $novedad = $this->buscar($actor, (int) $entrada['novedad_id'], 'editar');
            if ($novedad->categoria !== 'lost_found' || $novedad->resuelto()) {
                throw ValidationException::withMessages(['objeto' => 'Solo se agregan objetos a un ticket de Lost & Found que siga abierto.']);
            }
            $articulo = DB::transaction(function () use ($novedad, $objeto, $actor) {
                $articulo = $this->guardarObjeto($novedad, $objeto, $actor);
                $this->anotar($novedad, $actor, "Registró el objeto encontrado {$articulo->folio} ({$articulo->objeto}).", 'sistema');

                return $articulo;
            });
        } else {
            $descripcion = 'Objeto encontrado: '.trim($objeto['objeto'].' '.($objeto['marca'] ?? '').' '.($objeto['color'] ?? '')).'.';
            $novedad = $this->crear($actor, [
                'sede_id' => $entrada['sede_id'] ?? null, 'categoria' => 'lost_found',
                'reportado_por' => $entrada['reportado_por'] ?? null, 'reportado_colaborador_id' => $entrada['reportado_colaborador_id'] ?? null,
                'ubicacion' => $entrada['ubicacion'] ?? null, 'descripcion' => $descripcion,
                'ocurrio_en' => $entrada['ocurrio_en'] ?? null,
                'lf_objeto' => $objeto['objeto'], 'lf_tipo_valor' => $objeto['tipo_valor'], 'lf_marca' => $objeto['marca'],
                'lf_color' => $objeto['color'], 'lf_ubicacion_bodega' => $objeto['ubicacion_bodega'],
            ]);
            $articulo = $novedad->articulos()->latest('id')->firstOrFail();
        }
        $this->auditoria->auditar($actor, 'lost_found.objeto_registrado', $articulo, null,
            ['folio' => $articulo->folio, 'objeto' => $articulo->objeto, 'ticket' => $novedad->folio()]);

        return [$novedad, $articulo];
    }

    /**
     * Objeto del alta rápida (lf_objeto, lf_tipo_valor…): null si no se escribió.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{objeto: string, tipo_valor: string, marca: ?string, color: ?string, ubicacion_bodega: ?string}|null
     */
    private function objetoDelAlta(array $entrada, bool $obligatorio = false): ?array
    {
        $v = Validator::make($entrada, [
            'lf_objeto' => [$obligatorio ? 'required' : 'nullable', 'string', 'max:150'],
            'lf_tipo_valor' => ['nullable', 'in:'.implode(',', array_keys(LostFoundArticulo::TIPOS_VALOR))],
            'lf_marca' => ['nullable', 'string', 'max:100'],
            'lf_color' => ['nullable', 'string', 'max:50'],
            'lf_ubicacion_bodega' => ['nullable', 'string', 'max:100'],
        ], [
            'lf_objeto.required' => 'Escribe qué objeto se encontró (por ejemplo: paraguas, cartera, celular).',
            'max' => 'El campo «:attribute» es muy largo (máximo :max caracteres).',
            'in' => 'Elige un tipo de valor de la lista.',
        ], [
            'lf_objeto' => '¿Qué se encontró?', 'lf_marca' => 'Marca', 'lf_color' => 'Color', 'lf_ubicacion_bodega' => 'Ubicación en bodega',
        ])->validate();
        $objeto = $this->texto($v['lf_objeto'] ?? null, true);
        if ($objeto === null) {
            return null;
        }

        return [
            'objeto' => $objeto, 'tipo_valor' => ($v['lf_tipo_valor'] ?? '') ?: 'OTRO',
            'marca' => $this->texto($v['lf_marca'] ?? null, true), 'color' => $this->texto($v['lf_color'] ?? null, true),
            'ubicacion_bodega' => $this->texto($v['lf_ubicacion_bodega'] ?? null, true),
        ];
    }

    /**
     * El artículo nace EN RESGUARDO con su folio LF, en la habitación del ticket si la tiene.
     *
     * @param  array{objeto: string, tipo_valor: string, marca: ?string, color: ?string, ubicacion_bodega: ?string}  $objeto
     */
    private function guardarObjeto(Novedad $novedad, array $objeto, User $actor): LostFoundArticulo
    {
        $formato = $this->formato('lost_found');
        $novedad->load($formato->relaciones());
        $actuales = $formato->valores($novedad);
        $filas = array_map(fn (array $a) => ['id' => $a['id'], 'objeto' => $a['objeto'], 'tipo_valor' => $a['tipo_valor'], 'marca' => $a['marca'],
            'color' => $a['color'], 'area_especifica_id' => $a['area_especifica_id'], 'ubicacion_bodega' => $a['ubicacion_bodega'],
            'lugar_detalle' => $a['lugar_detalle']], $actuales['lf_articulos']);
        $filas[] = $objeto + ['id' => null, 'area_especifica_id' => $novedad->area_especifica_id, 'lugar_detalle' => null];
        $reportes = array_map(fn (array $r) => array_intersect_key($r, array_flip(['id', 'objeto', 'tipo_valor', 'marca', 'color', 'nombre_huesped',
            'area_especifica_id', 'fecha_aproximada', 'telefono', 'correo', 'descripcion'])), $actuales['rp_reportes']);
        $formato->guardar($novedad, [
            'detalle' => ['folio_externo' => $actuales['lf_folio_externo'], 'enlace_externo' => $actuales['lf_enlace_externo']],
            'articulos' => $filas, 'reportes' => $reportes,
        ], $actor);

        return $novedad->articulos()->latest('id')->firstOrFail();
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
        $asignadoAntes = $novedad->asignado_a;
        $departamentoAntes = $novedad->departamento_id;

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

        DB::transaction(function () use ($actor, $novedad, $v, $formato, $datosFormato, $nota, $estatusAntes, $departamentoAntes) {
            $novedad->estatus = $v['estatus'];
            if ($v['estatus'] === Novedad::RESUELTO) {
                $novedad->cerrado_en = now();
                $novedad->cerrado_por = $actor->id;
            }
            // Mejoras Incidentes: «Pendiente de turno» guarda desde cuándo (reloj y aviso al turno que lo recibe)
            if ($v['estatus'] === Novedad::PENDIENTE_TURNO && $estatusAntes !== Novedad::PENDIENTE_TURNO) {
                $novedad->forceFill(['pendiente_turno_en' => now(), 'turno_avisado_en' => null, 'turno_escalado_en' => null]);
            }
            $novedad->save();
            $formato?->guardar($novedad, $datosFormato, $actor);
            if ((int) $departamentoAntes !== (int) $novedad->departamento_id && $novedad->departamento_id !== null) {
                $novedad->loadMissing('departamento:id,nombre');
                $this->anotar($novedad, $actor, 'Canalizado a '.$novedad->departamento?->nombre.'.', 'sistema');
            }

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
        $this->avisarCambios($actor, $novedad, $estatusAntes, $asignadoAntes);
        // C-28: el departamento recibe su aviso; un accidente de colaborador avisa a RR. HH. (el formato no cambia)
        $departamentos = app(CanalizarDepartamento::class);
        if ((int) $departamentoAntes !== (int) $novedad->departamento_id || $novedad->resuelto()) {
            $departamentos->avisar($novedad, $actor);
        }
        if ($novedad->categoria === 'accidente' && ($datosFormato['tipo'] ?? null) === 'COLABORADOR') {
            $departamentos->accidenteDeColaborador($novedad, $actor);
        }

        return $novedad;
    }

    /**
     * Mejoras Incidentes (criterio 1): canalizar avisa, cerrar avisa a quien lo
     * levantó y «Pendiente de turno» tiene dueño, hora y aviso.
     */
    private function avisarCambios(User $actor, Novedad $novedad, string $estatusAntes, ?int $asignadoAntes): void
    {
        $avisos = app(AvisosNovedades::class);
        if ($novedad->resuelto()) {
            if ($estatusAntes !== Novedad::RESUELTO) {
                $avisos->resuelto($novedad, $actor);
            }

            return;
        }
        if ((int) $asignadoAntes !== (int) $novedad->asignado_a) {
            $avisos->canalizado($novedad, $actor);
        }
        if ($novedad->estatus === Novedad::PENDIENTE_TURNO && $estatusAntes !== Novedad::PENDIENTE_TURNO) {
            $turnos = app(TurnosNovedades::class);
            $turno = $novedad->sede !== null ? $turnos->siguienteTurno($novedad->sede, now()) : null;
            if ($turno === null) {
                // Sin turnos en la sede no hay a qué hora avisar: se avisa ya al dueño o al personal de la sede
                $avisos->inicioDeTurno($novedad, null, $actor);
                $novedad->forceFill(['turno_avisado_en' => now()])->saveQuietly();
            } else {
                $avisos->pendienteTurno($novedad, $actor, $turno['nombre']);
            }
        } elseif ($estatusAntes === Novedad::PENDIENTE_TURNO && $novedad->estatus !== Novedad::PENDIENTE_TURNO) {
            $avisos->turnoAtendido($novedad);
        }
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
            // C-30 (D-15): el titular es el lugar real del siniestro, no «Ver detalle…»
            'ubicacion' => mb_substr(trim((string) $siniestro->ubicacion) !== '' ? (string) $siniestro->ubicacion : 'SINIESTRO PC '.$siniestro->folio(), 0, 255),
            'descripcion' => "Lesionado(s) durante siniestro de Protección Civil (ticket {$siniestro->folio()}) — {$detalle->num_lesionados} persona(s) reportada(s).",
            'origen_novedad_id' => $siniestro->id,
        ]);
        // C-24 (D-04): hereda a quien se canalizó el siniestro (o a quien lo documenta) y lleva su reloj
        $accidente->asignado_a = $siniestro->asignado_a ?? $actor->id;
        $accidente->numero = $this->siguienteNumero();
        $accidente->save();
        $detalle->forceFill(['accidente_novedad_id' => $accidente->id])->save();
        app(AvisosNovedades::class)->canalizado($accidente->setRelation('sede', $siniestro->sede), $actor);

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
            'departamento_id' => ['nullable', 'integer'], // C-28: o a un departamento
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
            // C-30: el lugar sale del árbol; el detalle escrito es opcional (se pide al menos uno de los dos)
            'lugar_id' => ['nullable', 'integer'],
            'lugar_texto' => ['nullable', 'string', 'max:150'],
            'ubicacion' => ['required_without_all:lugar_id,lugar_texto', 'nullable', 'string', 'max:255'],
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
            'ubicacion.required_without_all' => 'Elige el lugar de la lista o escribe dónde pasó (por ejemplo: Piso 2, cerca del elevador).',
            'descripcion.required' => 'Describe qué sucedió.',
            'estatus.in' => 'Elige un estatus de la lista.',
            'max' => 'El campo «:attribute» es muy largo (máximo :max caracteres).',
            'date_format' => 'Revisa la fecha y hora de «:attribute».',
        ], [
            'sede_id' => 'Sede', 'reportado_por' => '¿Quién reporta?', 'asignado_a' => '¿A quién se canaliza?', 'departamento_id' => 'O a un departamento', 'lugar_id' => 'Lugar', 'area_edificio_id' => 'Área General (edificio)', 'area_piso_id' => 'Área General (piso)',
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
        if (array_key_exists('departamento_id', $v)) {
            $novedad->departamento_id = app(CanalizarDepartamento::class)->valido($v['departamento_id'], (int) $novedad->sede_id, $novedad->departamento_id);
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

    /**
     * C-30: Área General (piso, o edificio si no hay piso) y habitación
     * específica a partir del lugar elegido en el árbol.
     */
    private function ubicarEnArbol(Novedad $novedad, Espacio $lugar): void
    {
        $cadena = Espacio::whereIn('id', [...$lugar->idsAncestros(), $lugar->id])->get(['id', 'nivel'])->keyBy('id');
        $piso = $cadena->firstWhere('nivel', Espacio::AREA);
        $edificio = $cadena->firstWhere('nivel', Espacio::EDIFICIO);
        $novedad->area_id = $piso?->id ?? $edificio?->id;
        $especifica = $cadena->firstWhere('nivel', Espacio::AREA_ESPECIFICA);
        $novedad->area_especifica_id = $especifica?->id;
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
            'numero', 'sede_id', 'categoria', 'estatus', 'reportado_por', 'reportado_colaborador_id', 'asignado_a', 'departamento_id', 'area_id', 'area_especifica_id',
            'ubicacion', 'involucrados', 'descripcion', 'como_sucedio', 'resolucion',
        ]) + ['ocurrio_en' => $novedad->ocurrio_en?->toIso8601String()];
    }
}
