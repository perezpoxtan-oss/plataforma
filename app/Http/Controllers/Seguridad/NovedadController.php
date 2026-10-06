<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\AccidenteFirma;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundReportePerdida;
use App\Models\LostFoundUmbral;
use App\Models\Novedad;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Firmas\Firmas;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Novedades\CoincidenciasLostFound;
use App\Services\Novedades\FichaHechos\FichaDeHechos;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de Novedades y Despacho (réplica de modules/bitacora/novedades_*
 * de SEGCAT): pestañas Tickets Abiertos / Asignados e Historial Resueltos,
 * "Generar Ticket Rápido" y el "Expediente de Novedad" con el formato de cada
 * categoría (Blade del lado del servidor, sin inyectar HTML por AJAX).
 *
 * Las reglas viven en App\Services\Novedades; aquí solo se arma la pantalla.
 */
class NovedadController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorNovedades $novedades,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($this->novedades->puedeModulo($actor, 'ver'), 403);
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.novedades.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $base = fn () => $this->novedades->limitar(Novedad::query(), $actor, 'ver')
                ->with(['sede:id,nombre', 'area:id,nombre,nivel,padre_id', 'area.padre:id,nombre', 'areaEspecifica:id,nombre',
                    'creador:id,name', 'editor:id,name', 'asignado:id,name', 'cerrador:id,name', 'articulos:id,novedad_id,folio'])
                ->when($request->routeIs('lost_found.index'), fn ($q) => $q->where('categoria', 'lost_found'));
            $abiertas = $base()->where('estatus', '!=', Novedad::RESUELTO)->orderByDesc('created_at')->get();
            $resueltas = $base()->where('estatus', Novedad::RESUELTO)->orderByDesc('cerrado_en')->orderByDesc('id')->limit(AdministradorNovedades::MAX_RESUELTOS)->get();

            $soloLostFound = $this->novedades->soloLostFound($actor);
            $sedesVer = $this->novedades->sedes($actor, 'ver');
            $sedesCrear = $this->novedades->sedes($actor, 'crear');
            $todasSedes = Sede::orderBy('nombre')->get(['id', 'nombre', 'activo', 'zona_horaria', 'empresa_id']);
            $sedesAlta = $todasSedes->filter(fn ($s) => $s->activo && ($sedesCrear === null || in_array($s->id, $sedesCrear, true)))->values();

            // Expediente que se abre (?abrir=ID), como el ?abrir= de SEGCAT
            $expediente = null;
            if ($request->filled('abrir') && ctype_digit(Entrada::texto($request->query('abrir')))) {
                $expediente = $this->expediente($actor, (int) $request->query('abrir'));
            }

            $puedeCrear = $this->novedades->puedeModulo($actor, 'crear') && $sedesAlta->isNotEmpty();
            $sedesCatalogo = $sedesAlta->pluck('id')->all();
            if ($expediente !== null) {
                $sedesCatalogo[] = $expediente['novedad']->sede_id;
            }

            return view('seguridad.novedades.index', [
                'sinEmpresa' => false,
                'abiertas' => $abiertas,
                'resueltas' => $resueltas,
                'soloLostFound' => $soloLostFound,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'variasSedes' => ($sedesVer === null ? $todasSedes->count() : count($sedesVer)) > 1,
                'sedesFiltro' => $todasSedes->filter(fn ($s) => $sedesVer === null || in_array($s->id, $sedesVer, true))->values(),
                'sedesAlta' => $sedesAlta,
                'categoriasAlta' => $this->novedades->categorias($actor, 'crear'),
                'catalogos' => $puedeCrear || $expediente !== null ? $this->catalogos(array_values(array_unique($sedesCatalogo)), $expediente['novedad'] ?? null) : null,
                'expediente' => $expediente,
                'ahoraLocal' => app(HoraLocal::class)->formatear(now(), 'Y-m-d\TH:i'),
                'editables' => $this->idsEditables($actor, $abiertas),
                'puede' => [
                    'crear' => $puedeCrear,
                    'exportar' => $actor->can('novedades.exportar'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'crear'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        $novedad = $this->tenant->conEmpresa($empresaId, fn () => $this->novedades->crear($request->user(), $request->all()));

        return redirect()->to(route('novedades.index').'#novedad-'.$novedad->id)
            ->with('ok', "Ticket {$novedad->folio()} despachado correctamente. Ábrelo con «Abrir Expediente» para darle seguimiento.");
    }

    public function update(Request $request, int $novedad): RedirectResponse
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'editar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad) {
            $modelo = $this->novedades->buscar($request->user(), $novedad, 'editar');

            return $this->novedades->actualizar($request->user(), $modelo, $request->except(['_token', '_method', '_dialogo']));
        });

        return redirect()->to(route('novedades.index').'#novedad-'.$modelo->id)->with('ok', "Expediente {$modelo->folio()} guardado correctamente.");
    }

    public function reabrir(Request $request, int $novedad): RedirectResponse
    {
        Gate::authorize('novedades.reabrir');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad) {
            $modelo = $this->novedades->buscar($request->user(), $novedad, 'reabrir');
            $this->novedades->reabrir($request->user(), $modelo, $request->only('motivo'));

            return $modelo;
        });

        return redirect()->route('novedades.index', ['abrir' => $modelo->id])
            ->with('ok', "Caso {$modelo->folio()} reabierto. El motivo quedó en el Minuto a Minuto; ya puedes editar el expediente.");
    }

    /**
     * Impresión del expediente completo (HTML para imprimir o guardar en PDF).
     */
    public function imprimir(Request $request, int $novedad): View
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'imprimir'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad, $empresaId) {
            $modelo = $this->novedades->buscar($request->user(), $novedad, 'imprimir');
            $this->cargar($modelo);

            return view('seguridad.novedades.imprimir', [
                'n' => $modelo,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'umbrales' => LostFoundUmbral::vigentes(),
            ]);
        });
    }

    /**
     * Acuse de Recibo de Lost & Found (SEGCAT: lf_acuse.php): comprobante
     * para quien encontró y entregó los artículos.
     */
    public function acuse(Request $request, int $novedad): View
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'imprimir'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad, $empresaId) {
            $modelo = $this->novedades->buscar($request->user(), $novedad, 'imprimir');
            abort_unless($modelo->categoria === 'lost_found', 404);
            $modelo->load(['sede:id,nombre', 'articulos.areaEspecifica:id,nombre']);

            return view('seguridad.novedades.acuse', ['n' => $modelo, 'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial')]);
        });
    }

    /**
     * CSV de la lista con los filtros de la pantalla (BOM para Excel).
     */
    public function exportar(Request $request, HoraLocal $hora): StreamedResponse
    {
        Gate::authorize('novedades.exportar');
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'in:'.implode(',', array_keys(Novedad::CATEGORIAS))],
            'sede' => ['nullable', 'integer'],
            'pestana' => ['nullable', 'in:abiertas,resueltas'],
        ]);

        $lista = $this->tenant->conEmpresa($empresaId, function () use ($request, $filtros) {
            $lista = $this->novedades->limitar(Novedad::query(), $request->user(), 'exportar')
                ->with(['sede:id,nombre', 'area:id,nombre,nivel,padre_id', 'area.padre:id,nombre', 'areaEspecifica:id,nombre',
                    'creador:id,name', 'editor:id,name', 'asignado:id,name', 'cerrador:id,name', 'articulos:id,novedad_id,folio'])
                ->when(isset($filtros['categoria']), fn ($q) => $q->where('categoria', $filtros['categoria']))
                ->when(isset($filtros['sede']), fn ($q) => $q->where('sede_id', (int) $filtros['sede']))
                ->when(($filtros['pestana'] ?? null) === 'abiertas', fn ($q) => $q->where('estatus', '!=', Novedad::RESUELTO))
                ->when(($filtros['pestana'] ?? null) === 'resueltas', fn ($q) => $q->where('estatus', Novedad::RESUELTO))
                ->orderByDesc('created_at')->get();
            if (! empty($filtros['q'])) {
                $texto = mb_strtolower($filtros['q']);
                $lista = $lista->filter(fn (Novedad $n) => str_contains(self::textoBusqueda($n), $texto))->values();
            }

            return $lista;
        });

        return response()->streamDownload(function () use ($lista, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            Csv::fila($salida, ['Ticket', 'Fecha de reporte', 'Sede', 'Categoría', 'Estatus', '¿Quién reporta?', '¿A quién se canaliza?', 'Área General',
                'Habitación', 'Ubicación específica', '¿Cuándo sucedió?', '¿Qué sucedió?', '¿Cómo sucedió?', 'Involucrados', 'Resolución',
                'Fecha de cierre', 'Cerró', 'Creó', 'Último en dar seguimiento', 'Folios Lost & Found']);
            foreach ($lista as $n) {
                Csv::fila($salida, [
                    $n->folio(), $hora->formatear($n->created_at), $n->sede?->nombre, $n->etiquetaCategoria(), $n->etiquetaEstatus(),
                    $n->reportado_por, $n->asignado?->name, $n->textoArea(false), $n->areaEspecifica?->nombre, $n->ubicacion,
                    $hora->formatear($n->ocurrio_en), $n->descripcion, $n->como_sucedio, $n->involucrados, $n->resolucion,
                    $hora->formatear($n->cerrado_en), $n->cerrador?->name, $n->creador?->name, $n->editor?->name, $n->articulos->pluck('folio')->join(', '),
                ]);
            }
            fclose($salida);
        }, 'bitacora-novedades-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Firma del expediente de Accidente (disco privado), solo con permiso de
     * ver el ticket y dentro del alcance de sede.
     */
    public function firma(Request $request, int $novedad, string $rol): StreamedResponse
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'ver'), 403);
        abort_unless(isset(AccidenteFirma::ROLES[$rol]), 404);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad, $rol) {
            $modelo = $this->novedades->buscar($request->user(), $novedad, 'ver');
            $firma = AccidenteFirma::where('novedad_id', $modelo->id)->where('rol', $rol)->first();
            abort_if($firma === null, 404);

            return app(Firmas::class)->respuesta($firma->ruta);
        });
    }

    /**
     * Ficha de Hechos de una habitación alrededor de una fecha.
     */
    public function fichaHechos(Request $request, FichaDeHechos $ficha): View
    {
        $actor = $request->user();
        abort_unless($this->novedades->puedeModulo($actor, 'ver'), 403);
        $empresaId = $this->empresaDeTrabajo($request);
        $datos = $request->validate([
            'habitacion' => ['required', 'integer'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'novedad' => ['nullable', 'integer'],
            'origen' => ['nullable', 'in:perdida,robo'],
            'origen_id' => ['nullable', 'integer'],
        ]);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $ficha, $datos) {
            $sedes = $this->novedades->sedes($actor, 'ver');
            $habitacion = Espacio::with(['sede:id,nombre,zona_horaria,empresa_id', 'padre:id,nombre,nivel,padre_id,ruta', 'padre.padre:id,nombre'])
                ->where('nivel', Espacio::AREA_ESPECIFICA)
                ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
                ->find((int) $datos['habitacion']);
            $origen = isset($datos['novedad']) ? $this->novedades->limitar(Novedad::query(), $actor, 'ver')->find((int) $datos['novedad']) : null;
            $fecha = $datos['fecha'] ?? app(HoraLocal::class)->formatear(now(), 'Y-m-d');

            // "Vincular a este…" solo si se llegó desde un reporte de pérdida o un robo que se puede editar
            $vincularUrl = null;
            if (($datos['origen'] ?? null) === 'perdida' && isset($datos['origen_id'])) {
                $reporte = LostFoundReportePerdida::with('novedad')->find((int) $datos['origen_id']);
                if ($reporte !== null && $this->novedades->permite($actor, 'editar', $reporte->novedad) && ! $reporte->novedad->resuelto()) {
                    $vincularUrl = route('novedades.perdidas.vincular', $reporte->id);
                }
            } elseif (($datos['origen'] ?? null) === 'robo' && $origen !== null && $origen->categoria === 'robo'
                && $this->novedades->permite($actor, 'editar', $origen) && ! $origen->resuelto()) {
                $vincularUrl = route('novedades.robo.vincular', $origen->id);
            }

            return view('seguridad.novedades.ficha-hechos', [
                'habitacion' => $habitacion,
                'fecha' => $fecha,
                'ficha' => $habitacion !== null ? $ficha->armar($actor, $habitacion, $fecha, $origen) : null,
                'origen' => $datos['origen'] ?? null,
                'vincularUrl' => $vincularUrl,
            ]);
        });
    }

    /**
     * Buscar Coincidencias en Lost & Found (JSON).
     */
    public function coincidencias(Request $request, CoincidenciasLostFound $buscador): JsonResponse
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'ver'), 403);
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $request->validate([
            'tipo_valor' => ['nullable', 'string', 'max:20'], 'objeto' => ['nullable', 'string', 'max:150'],
            'marca' => ['nullable', 'string', 'max:100'], 'color' => ['nullable', 'string', 'max:50'], 'fecha' => ['nullable', 'string', 'max:10'],
        ]);

        $resultados = $this->tenant->conEmpresa($empresaId, fn () => $buscador->buscar($request->user(), $filtros));

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * Vincular un reporte de pérdida con un artículo encontrado.
     */
    public function vincularPerdida(Request $request, int $reporte): JsonResponse|RedirectResponse
    {
        abort_unless($this->novedades->puedeModulo($request->user(), 'editar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);
        // Seguridad (AZ-03): primero el registro (404 si es ajeno), luego la captura
        $buscar = function () use ($request, $reporte) {
            $modelo = LostFoundReportePerdida::with('novedad')->find($reporte);
            abort_if($modelo === null || ! $this->novedades->permite($request->user(), 'editar', $modelo->novedad), 404);

            return $modelo;
        };
        $this->tenant->conEmpresa($empresaId, $buscar);
        $datos = $request->validate(['articulo_id' => ['required', 'integer']], ['articulo_id.required' => 'Elige el artículo encontrado.']);

        $this->tenant->conEmpresa($empresaId, function () use ($request, $datos, $buscar) {
            $modelo = $buscar();
            abort_if($modelo->novedad->resuelto(), 422, 'Este caso ya está Resuelto. Reábrelo antes de vincular un hallazgo.');
            $this->novedades->vincularPerdida($request->user(), $modelo, $this->articuloVisible($request->user(), (int) $datos['articulo_id']));
        });

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'mensaje' => 'Vinculado — cierra el artículo desde su propia ficha cuando lo entregues.'])
            : back()->with('ok', 'Reporte de pérdida vinculado con el hallazgo.');
    }

    /**
     * Robo: vincular el hallazgo que resultó ser lo "robado".
     */
    public function vincularRobo(Request $request, int $novedad): JsonResponse|RedirectResponse
    {
        Gate::authorize('novedades.editar');
        $empresaId = $this->empresaDeTrabajo($request);
        // Seguridad (AZ-03): primero el registro (404 si es ajeno), luego la captura
        $this->tenant->conEmpresa($empresaId, fn () => $this->novedades->buscar($request->user(), $novedad, 'editar'));
        $datos = $request->validate(['articulo_id' => ['required', 'integer']], ['articulo_id.required' => 'Elige el artículo encontrado.']);

        $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad, $datos) {
            $robo = $this->novedades->buscar($request->user(), $novedad, 'editar');
            $this->novedades->vincularRobo($request->user(), $robo, $this->articuloVisible($request->user(), (int) $datos['articulo_id']));
        });

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'mensaje' => 'Vinculado con este caso de robo.'])
            : back()->with('ok', 'Hallazgo vinculado con el caso de robo.');
    }

    /**
     * Texto con el que se busca un ticket (el mismo en la pantalla y en la exportación).
     */
    public static function textoBusqueda(Novedad $n): string
    {
        return mb_strtolower(implode(' ', array_filter([
            $n->folio(), $n->numero, $n->etiquetaCategoria(), $n->ubicacion, $n->textoArea(), $n->descripcion, $n->reportado_por,
            $n->asignado?->name, $n->sede?->nombre, $n->creador?->name, $n->articulos->pluck('folio')->join(' '),
        ])));
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Datos del expediente que se abre: el ticket con su formato cargado y
     * los valores del formulario (los guardados o, si regresó con errores,
     * los que se capturaron).
     *
     * @return array<string, mixed>|null
     */
    private function expediente(User $actor, int $id): ?array
    {
        $novedad = $this->novedades->limitar(Novedad::query(), $actor, 'ver')->find($id);
        if ($novedad === null) {
            return null;
        }
        $this->cargar($novedad);

        $formatos = [];
        foreach (array_keys(AdministradorNovedades::FORMATOS) as $categoria) {
            $formatos[$categoria] = $this->novedades->formato($categoria);
        }
        $novedad->load(collect($formatos)->flatMap(fn ($f) => $f->relaciones())->unique()->values()->all());

        $trasError = old('_dialogo') === 'expediente-'.$novedad->id;
        $valores = [];
        foreach ($formatos as $formato) {
            $valores += $formato->valores($novedad);
        }
        $valores = [
            'categoria' => $novedad->categoria, 'asignado_a' => $novedad->asignado_a,
            'area_edificio_id' => $novedad->area?->nivel === Espacio::AREA ? $novedad->area->padre_id : $novedad->area_id,
            'area_piso_id' => $novedad->area?->nivel === Espacio::AREA ? $novedad->area_id : null,
            'area_especifica_id' => $novedad->area_especifica_id, 'involucrados' => $novedad->involucrados, 'como_sucedio' => $novedad->como_sucedio,
            'ocurrio_en' => $novedad->localParaCampo($novedad->ocurrio_en), 'estatus' => $novedad->estatus, 'resolucion' => $novedad->resolucion,
            'nueva_nota' => null,
        ] + $valores;
        if ($trasError) {
            $valores = array_replace($valores, array_intersect_key(old(), $valores), ['m_herida' => old('m_herida', [])]);
            foreach (['c_causa_terceros', 'c_causa_acto', 'c_causa_condicion', 'g_alcohol', 'g_acto', 'g_condicion', 'robo_canalizado_gerencia', 'robo_canalizado_legal'] as $casilla) {
                $valores[$casilla] = (bool) old($casilla);
            }
        }

        $editable = ! $novedad->resuelto() && $this->novedades->permite($actor, 'editar', $novedad);

        return [
            'novedad' => $novedad,
            'v' => $valores,
            'trasError' => $trasError,
            'editable' => $editable,
            'categorias' => $editable ? $this->novedades->categorias($actor, 'editar', $novedad) : [$novedad->categoria],
            'puedeReabrir' => $novedad->resuelto() && $actor->can('novedades.reabrir') && $this->novedades->permite($actor, 'reabrir', $novedad),
            'puedeImprimir' => $this->novedades->permite($actor, 'imprimir', $novedad),
            'umbrales' => LostFoundUmbral::vigentes(),
            'sugerenciasBodega' => LostFoundArticulo::where('sede_id', $novedad->sede_id)->whereNotNull('ubicacion_bodega')
                ->distinct()->orderBy('ubicacion_bodega')->limit(100)->pluck('ubicacion_bodega'),
        ];
    }

    private function cargar(Novedad $novedad): void
    {
        $novedad->load([
            'sede:id,nombre,zona_horaria,empresa_id', 'area:id,nombre,nivel,padre_id', 'area.padre:id,nombre', 'areaEspecifica:id,nombre',
            'creador:id,name', 'editor:id,name', 'asignado:id,name', 'cerrador:id,name', 'origen:id,numero,categoria',
            'reportadoColaborador:id,departamento_id,puesto_id', 'reportadoColaborador.departamento:id,nombre', 'reportadoColaborador.puesto:id,nombre',
            'notas', 'testigos',
        ]);
        $formato = $this->novedades->formato($novedad->categoria);
        if ($formato !== null) {
            $novedad->load($formato->relaciones());
        }
    }

    /**
     * Opciones de los formularios de las sedes indicadas: zonas y áreas,
     * usuarios a quienes canalizar y colaboradores (sugerencias de nombre).
     *
     * @param  list<int>  $sedes
     * @return array<string, mixed>
     */
    private function catalogos(array $sedes, ?Novedad $abierta): array
    {
        $usados = array_filter([$abierta?->area_id, $abierta?->area?->padre_id, $abierta?->area_especifica_id]);
        $espacios = Espacio::whereIn('sede_id', $sedes)->whereIn('nivel', [Espacio::EDIFICIO, Espacio::AREA, Espacio::AREA_ESPECIFICA])
            ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $usados))
            ->orderBy('ruta')->get(['id', 'sede_id', 'padre_id', 'nivel', 'nombre', 'ruta', 'activo'])
            ->sortBy('nombre', SORT_NATURAL)->values();

        $roles = UsuarioRol::whereIn('user_id', User::where('empresa_id', $this->tenant->empresaId())->where('activo', true)->select('id'))
            ->get(['user_id', 'sede_id'])->groupBy('user_id');
        $usuarios = User::whereIn('id', $roles->keys())->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name,
                'sedes' => $roles[$u->id]->contains(fn ($r) => $r->sede_id === null) ? 'todas' : $roles[$u->id]->pluck('sede_id')->unique()->join(' ')])
            ->filter(fn ($u) => $u['sedes'] === 'todas' || array_intersect(explode(' ', $u['sedes']), array_map('strval', $sedes)) !== [])
            ->values();

        $colaboradores = Colaborador::with(['departamento:id,nombre', 'puesto:id,nombre', 'sedesAdicionales:sedes.id'])
            ->where('activo', true)->whereNull('fusionado_en_id')
            ->where(fn ($q) => $q->whereNull('sede_id')->orWhere(fn ($x) => $x->enSedes($sedes)))
            ->orderBy('nombre')->orderBy('apellido_paterno')->limit(3000)
            ->get(['id', 'sede_id', 'nombre', 'apellido_paterno', 'apellido_materno', 'departamento_id', 'puesto_id', 'num_empleado'])
            ->map(fn (Colaborador $c) => [
                'n' => mb_strtoupper($c->nombreCompleto()), 'd' => $c->departamento?->nombre, 'p' => $c->puesto?->nombre, 'id' => $c->id,
                's' => $c->sede_id === null ? 'todas' : collect([$c->sede_id])->merge($c->sedesAdicionales->pluck('id'))->unique()->join(' '),
            ])->values();

        return ['espacios' => $espacios, 'usuarios' => $usuarios, 'colaboradores' => $colaboradores];
    }

    /**
     * Ids de los tickets abiertos que el actor puede editar (botón "Abrir" vs "Ver").
     *
     * @param  Collection<int, Novedad>  $abiertas
     * @return list<int>
     */
    private function idsEditables(User $actor, Collection $abiertas): array
    {
        return $abiertas->filter(fn (Novedad $n) => $this->novedades->permite($actor, 'editar', $n))->pluck('id')->all();
    }

    private function articuloVisible(User $actor, int $id): LostFoundArticulo
    {
        $articulo = LostFoundArticulo::whereHas('novedad', fn (Builder $q) => $this->novedades->limitar($q, $actor, 'ver'))->find($id);
        abort_if($articulo === null, 404);

        return $articulo;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
