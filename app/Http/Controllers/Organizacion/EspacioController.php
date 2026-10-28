<?php

namespace App\Http\Controllers\Organizacion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Services\Bajas\AntesDeDarDeBaja;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Padrones\AvisoDuplicado;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Espacios\Etiquetas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Zonas y áreas (réplica de modules/ubicaciones de SEGCAT):
 * Zonas / Edificios -> Pisos -> Habitaciones -> Detalle (áreas y elementos).
 * Las cuatro pantallas son vistas del mismo árbol de espacios.
 */
class EspacioController extends Controller
{
    public function __construct(
        private readonly AdministradorEspacios $administrador,
        private readonly Autorizador $autorizador,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
    ) {}

    /**
     * Paso previo: tarjetas de las sedes visibles. Con una sola sede se entra
     * directo a ella (los enlaces viejos a /espacios siguen funcionando).
     */
    public function index(Request $request): View|RedirectResponse
    {
        Gate::authorize('espacios.ver');
        $empresaId = $this->empresa->id($request->user());

        if ($empresaId === null) {
            return view('organizacion.espacios.index', ['sinEmpresa' => true, 'etiquetas' => Etiquetas::para(null)]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId) {
            $sedes = $this->sedesVisibles($request);
            if ($sedes->count() === 1) {
                return redirect()->route('espacios.sede', array_filter([
                    'sede' => $sedes->first()->id,
                    'pestana' => $request->query('pestana') === 'secciones' ? 'secciones' : null,
                ]));
            }
            $empresa = Empresa::with('rubro')->findOrFail($empresaId);
            $sedeIds = $sedes->pluck('id');

            // Conteos por sede y nivel: zonas/edificios y pisos (todos), habitaciones activas
            $porNivel = Espacio::whereIn('sede_id', $sedeIds)
                ->whereIn('nivel', [Espacio::EDIFICIO, Espacio::AREA, Espacio::AREA_ESPECIFICA])
                ->where(fn ($q) => $q->where('nivel', '!=', Espacio::AREA_ESPECIFICA)->orWhere('activo', true))
                ->selectRaw('sede_id, nivel, COUNT(*) as total')->groupBy('sede_id', 'nivel')
                ->get()->groupBy('sede_id');
            $secciones = GrupoEspacio::whereIn('sede_id', $sedeIds)
                ->selectRaw('sede_id, COUNT(*) as total')->groupBy('sede_id')->pluck('total', 'sede_id');

            $resumen = $sedes->mapWithKeys(function (Sede $sede) use ($porNivel, $secciones) {
                $niveles = $porNivel->get($sede->id, collect())->pluck('total', 'nivel');

                return [$sede->id => [
                    'zonas' => (int) ($niveles[Espacio::EDIFICIO] ?? 0),
                    'pisos' => (int) ($niveles[Espacio::AREA] ?? 0),
                    'habitaciones' => (int) ($niveles[Espacio::AREA_ESPECIFICA] ?? 0),
                    'secciones' => (int) ($secciones[$sede->id] ?? 0),
                ]];
            });

            return view('organizacion.espacios.index', [
                'sinEmpresa' => false,
                'empresa' => $empresa,
                'sedes' => $sedes,
                'resumen' => $resumen,
                'etiquetas' => Etiquetas::para($empresa),
            ]);
        });
    }

    /**
     * Nivel 1 de una sede: sus Zonas / Edificios (y pestaña de Secciones).
     */
    public function sede(Request $request, int $sede): View
    {
        Gate::authorize('espacios.ver');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $sede) {
            $sedes = $this->sedesVisibles($request);
            $modelo = $sedes->firstWhere('id', $sede);
            abort_if($modelo === null, 404);
            $empresa = Empresa::with('rubro')->findOrFail($empresaId);

            $edificios = Espacio::with('tipo:id,nombre')
                ->withCount(['hijos as pisos_count' => fn ($q) => $q->where('nivel', Espacio::AREA)])
                ->where('nivel', Espacio::EDIFICIO)
                ->where('espacios.sede_id', $modelo->id)
                ->leftJoin('users as ua', 'ua.id', '=', 'espacios.actualizado_por')
                ->addSelect('ua.name as actualizado_por_nombre')
                ->orderBy('espacios.nombre')
                ->get();

            $secciones = GrupoEspacio::where('sede_id', $modelo->id)
                ->withCount(['espacios as total' => fn ($q) => $q->where('activo', true)])
                ->orderBy('nombre')
                ->get();

            // Para "Asignar habitaciones" en la pestaña Secciones
            $porAsignar = $request->query('pestana') === 'secciones'
                ? Espacio::with('padre:id,nombre,padre_id,nivel', 'padre.padre:id,nombre')
                    ->where('nivel', Espacio::AREA_ESPECIFICA)->where('activo', true)->where('sede_id', $modelo->id)
                    ->orderBy('nombre')->get(['id', 'sede_id', 'padre_id', 'nombre', 'grupo_espacio_id'])
                : collect();

            return view('organizacion.espacios.sede', [
                'porAsignar' => $porAsignar,
                'empresa' => $empresa,
                'sede' => $modelo,
                'edificios' => $edificios,
                'secciones' => $secciones,
                'tiposEdificio' => TipoEspacio::disponiblesPara($empresaId, Espacio::EDIFICIO)->get(['id', 'nombre']),
                'etiquetas' => Etiquetas::para($empresa),
                'puede' => $this->puede($request),
            ]);
        });
    }

    /**
     * Pisos de un edificio, habitaciones de un piso o detalle de una habitación.
     */
    public function show(Request $request, int $espacio): View|RedirectResponse
    {
        Gate::authorize('espacios.ver');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);

        // Áreas y elementos se ven dentro de su habitación
        if (in_array($nodo->nivel, [Espacio::SUBAREA, Espacio::ELEMENTO], true)) {
            $habitacion = Espacio::whereIn('id', $nodo->idsAncestros())->where('nivel', Espacio::AREA_ESPECIFICA)->first();

            return redirect()->route('espacios.show', $habitacion?->id ?? $nodo->padre_id);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $nodo, $empresaId) {
            $empresa = Empresa::with('rubro')->findOrFail($empresaId);
            $comun = [
                'nodo' => $nodo,
                'empresa' => $empresa,
                'migas' => Espacio::whereIn('id', $nodo->idsAncestros())->orderBy('profundidad')->get(['id', 'nombre', 'nivel']),
                'etiquetas' => Etiquetas::para($empresa),
                'puede' => $this->puede($request),
            ];

            return match ($nodo->nivel) {
                Espacio::EDIFICIO => view('organizacion.espacios.pisos', $comun + [
                    'pisos' => $nodo->hijos()->where('nivel', Espacio::AREA)
                        ->withCount(['hijos as habitaciones_count' => fn ($q) => $q->where('nivel', Espacio::AREA_ESPECIFICA)])
                        ->with('tipo:id,nombre')->get(),
                    'otrosEdificios' => Espacio::where('nivel', Espacio::EDIFICIO)->whereIn('sede_id', $this->sedesVisibles($request)->pluck('id'))
                        ->whereKeyNot($nodo->id)->where('activo', true)->withCount(['hijos as pisos_count' => fn ($q) => $q->where('nivel', Espacio::AREA)])
                        ->orderBy('nombre')->get(),
                    'tiposPiso' => TipoEspacio::disponiblesPara($empresaId, Espacio::AREA)->get(['id', 'nombre']),
                ]),
                Espacio::AREA => view('organizacion.espacios.habitaciones', $comun + [
                    'habitaciones' => $nodo->hijos()->where('nivel', Espacio::AREA_ESPECIFICA)
                        ->with(['grupo:id,nombre', 'tipo:id,nombre'])
                        ->withCount(['hijos as areas_count' => fn ($q) => $q->where('nivel', Espacio::SUBAREA)])->get(),
                    'secciones' => GrupoEspacio::where('sede_id', $nodo->sede_id)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                    'tiposHabitacion' => TipoEspacio::disponiblesPara($empresaId, Espacio::AREA_ESPECIFICA)->get(['id', 'nombre']),
                ]),
                default => view('organizacion.espacios.detalle', $comun + [
                    'areas' => $nodo->hijos()->where('nivel', Espacio::SUBAREA)
                        ->with(['tipo:id,nombre', 'hijos' => fn ($q) => $q->where('nivel', Espacio::ELEMENTO)->with('tipo:id,nombre')])->get(),
                    'elementosSueltos' => $nodo->hijos()->where('nivel', Espacio::ELEMENTO)->with('tipo:id,nombre')->get(),
                    'secciones' => GrupoEspacio::where('sede_id', $nodo->sede_id)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                    'tiposArea' => TipoEspacio::disponiblesPara($empresaId, Espacio::SUBAREA)->get(['id', 'nombre']),
                    'tiposElemento' => TipoEspacio::disponiblesPara($empresaId, Espacio::ELEMENTO)->get(['id', 'nombre']),
                    'tiposHabitacion' => TipoEspacio::disponiblesPara($empresaId, Espacio::AREA_ESPECIFICA)->get(['id', 'nombre']),
                ]),
            };
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('espacios.crear');
        $datos = $this->validar($request, true);

        [$sede, $padre, $empresaId] = $this->ubicacion($request, $datos);
        $nodo = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->crear($request->user(), $sede, $padre, $datos['nivel'], $datos));

        return $this->volver($nodo->padre ?? null, $nodo->sede_id)->with('ok', "«{$nodo->nombre}» agregado correctamente.");
    }

    public function update(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.editar');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);
        Gate::authorize('espacios.editar', $nodo); // Seguridad (AZ-04): alcance sobre el registro ("solo los propios")
        $datos = $this->validar($request, false);

        $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->actualizar($request->user(), $nodo, $datos));

        return $this->volver($nodo->nivel === Espacio::AREA_ESPECIFICA && $request->boolean('desde_detalle') ? $nodo : $nodo->padre, $nodo->sede_id)
            ->with('ok', "«{$nodo->nombre}» actualizado correctamente.");
    }

    public function estado(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.eliminar');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);
        Gate::authorize('espacios.eliminar', $nodo); // Seguridad (AZ-04): alcance sobre el registro ("solo los propios")
        $activo = $request->boolean('activo');
        // C-20: «Antes de dar de baja» si tiene reservas futuras o llaves que la abren
        $revision = $activo ? null : $this->tenant->conEmpresa($empresaId, fn () => app(AntesDeDarDeBaja::class)->exigirRevisado('espacios', $nodo, $request));

        $afectados = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->cambiarEstado($request->user(), $nodo, $activo));
        if ($revision !== null && $revision['items'] !== []) {
            $this->tenant->conEmpresa($empresaId, fn () => app(AntesDeDarDeBaja::class)->aplicar('espacios', $nodo, $revision, $request->all(), $request->user()));
        }
        $extra = $afectados > 1 ? ' junto con '.($afectados - 1).' espacio(s) que dependen de él' : '';

        return $this->volver($nodo->padre, $nodo->sede_id)->with($activo ? 'ok' : 'aviso', $activo
            ? "«{$nodo->nombre}» reactivado{$extra}."
            : "«{$nodo->nombre}» desactivado{$extra}. Su historial se conserva y puedes reactivarlo con el mismo botón.");
    }

    /**
     * "Mover a…": lugares válidos para el espacio (JSON para el diálogo).
     */
    public function destinos(Request $request, int $espacio): JsonResponse
    {
        Gate::authorize('espacios.editar');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);
        Gate::authorize('espacios.editar', $nodo);

        return response()->json([
            'nombre' => $nodo->nombre,
            'destinos' => $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->destinos($nodo)),
        ]);
    }

    /**
     * "Mover a…": cambia el lugar del espacio dentro de su misma sede, con todo lo que tiene dentro.
     */
    public function mover(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.editar');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);
        Gate::authorize('espacios.editar', $nodo); // alcance sobre el registro ("solo los propios")
        $destinoId = (int) $request->validate(['destino_id' => ['required', 'integer']], ['destino_id.required' => 'Elige a dónde moverlo.'])['destino_id'];
        [$destino] = $this->buscar($request, $destinoId);

        $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->mover($request->user(), $nodo, $destino));

        return back()->with('ok', "«{$nodo->nombre}» se movió a «{$destino->nombre}» con todo lo que tiene dentro.")
            ->with('siguientes', [['Ir a '.$destino->nombre, route('espacios.show', $this->visible($destino)->id), 'bi-box-arrow-in-right']]);
    }

    /**
     * "Eliminar definitivamente las marcadas": solo las que nadie usó (mismo
     * criterio que el botón individual); de las demás se dice por qué no.
     */
    public function eliminarVarios(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.borrar');
        [$padre, $empresaId] = $this->buscar($request, $espacio);

        $datos = $request->validate([
            'espacios' => ['required', 'array', 'max:'.AdministradorEspacios::MAX_ELIMINAR],
            'espacios.*' => ['integer'],
            'confirmacion' => ['required', 'string', 'max:20'],
        ], [
            'espacios.required' => 'Marca al menos uno.',
            'espacios.max' => 'Puedes eliminar hasta '.AdministradorEspacios::MAX_ELIMINAR.' a la vez.',
            'confirmacion.required' => 'Para confirmar escribe la cantidad marcada o ELIMINAR.',
        ]);
        $ids = array_values(array_unique(array_map('intval', $datos['espacios'])));
        $confirmacion = mb_strtoupper(trim(Entrada::texto($datos['confirmacion'])));
        if ($confirmacion !== 'ELIMINAR' && $confirmacion !== (string) count($ids)) {
            return back()->with('error', 'Para confirmar escribe '.count($ids).' (la cantidad marcada) o ELIMINAR.');
        }

        $r = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->eliminarVarios($request->user(), $padre, $ids));
        $ok = count($r['eliminadas']);
        $mal = count($r['fallidas']);
        if ($ok === 0 && $mal === 0) {
            return $this->volver($padre)->with('error', 'Ninguno de los marcados está en esta lista: no se eliminó nada.');
        }
        $partes = [];
        if ($ok > 0 || $mal === 0) {
            $partes[] = "Se eliminaron {$ok}";
        }
        if ($mal > 0) {
            $motivos = array_slice($r['fallidas'], 0, 10);
            $mas = $mal > 10 ? ' y '.($mal - 10).' más' : '';
            $partes[] = ($mal === 1 ? '1 no se pudo' : "{$mal} no se pudieron").': '.implode('; ', $motivos).$mas
                .'. Lo que está en uso puedes darlo de baja.';
        }
        $mensaje = implode('; ', $partes).($mal === 0 ? '. Solo queda su copia en la Bitácora de auditoría.' : '');
        $mensaje = $ok === 0 && $mal > 0 ? ucfirst($mensaje) : $mensaje;

        return $this->volver($padre)->with($mal === 0 ? 'ok' : ($ok > 0 ? 'aviso' : 'error'), $mensaje);
    }

    /**
     * Crear habitaciones por lote: rango numérico o lista de nombres.
     */
    public function lote(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.crear');
        [$piso, $empresaId] = $this->buscar($request, $espacio);
        abort_unless($piso->nivel === Espacio::AREA, 404);

        $datos = $request->validate([
            'modo' => ['required', 'in:rango,lista'],
            'prefijo' => ['nullable', 'string', 'max:20'],
            'rango_desde' => ['required_if:modo,rango', 'nullable', 'integer', 'min:0', 'max:999999'],
            'rango_hasta' => ['required_if:modo,rango', 'nullable', 'integer', 'min:0', 'max:999999'],
            'relleno_ceros' => ['nullable', 'boolean'],
            'lista_nombres' => ['required_if:modo,lista', 'nullable', 'string', 'max:20000'],
            'grupo_espacio_id' => ['nullable', 'integer'],
            'tipo_espacio_id' => ['nullable', 'integer'],
        ], ['rango_desde.required_if' => 'Indica desde qué número.', 'rango_hasta.required_if' => 'Indica hasta qué número.', 'lista_nombres.required_if' => 'Escribe al menos un nombre.']);

        $nombres = $datos['modo'] === 'rango'
            ? $this->nombresPorRango((int) $datos['rango_desde'], (int) $datos['rango_hasta'], trim((string) ($datos['prefijo'] ?? '')), $request->boolean('relleno_ceros'))
            : preg_split('/[\n,]+/', (string) $datos['lista_nombres']);

        $resultado = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->crearLote(
            $request->user(), $piso, $nombres, $datos['grupo_espacio_id'] ?? null, $datos['tipo_espacio_id'] ?? null,
        ));

        $mensaje = "Se crearon {$resultado['creadas']}.".($resultado['omitidas'] > 0 ? " Se omitieron {$resultado['omitidas']} que ya existían." : '');

        return $this->volver($piso)->with($resultado['creadas'] > 0 ? 'ok' : 'aviso', $mensaje);
    }

    public function copiarPisos(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.crear');
        [$destino, $empresaId] = $this->buscar($request, $espacio);
        $origenId = (int) $request->validate(['origen_id' => ['required', 'integer']])['origen_id'];
        [$origen] = $this->buscar($request, $origenId);

        $copiados = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->copiarPisos($request->user(), $origen, $destino));

        return $this->volver($destino)->with($copiados > 0 ? 'ok' : 'aviso', $copiados > 0
            ? "Se copiaron {$copiados} piso(s) de «{$origen->nombre}»."
            : 'No había pisos nuevos que copiar: este edificio ya tiene los mismos nombres.');
    }

    /**
     * Agregar un tipo propio de la empresa (p. ej. "Jacuzzi" como elemento).
     */
    public function tipo(Request $request): RedirectResponse
    {
        Gate::authorize('espacios.crear');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $datos = $request->validate(['nivel' => ['required', 'in:'.implode(',', array_keys(Espacio::PADRES))], 'nombre' => ['required', 'string', 'max:60']]);
        $tipo = $this->administrador->crearTipo($request->user(), $empresaId, $datos['nivel'], $datos['nombre']);

        // Ronda 5 (Z-07): si ya existía se dice, en vez de fingir que se agregó
        return $tipo->wasRecentlyCreated
            ? back()->with('ok', "Tipo «{$tipo->nombre}» disponible para tu empresa.")
            : back()->with('aviso', "El tipo «{$tipo->nombre}» ya existía en la lista: no se duplicó y ya puedes elegirlo.");
    }

    /**
     * Nueva sección (agrupación de habitaciones) de una sede.
     */
    public function seccion(Request $request): RedirectResponse
    {
        Gate::authorize('espacios.crear');
        $datos = $request->validate(['sede_id' => ['required', 'integer'], 'nombre' => ['required', 'string', 'max:50']]);
        $sede = $this->sedesVisibles($request)->firstWhere('id', (int) $datos['sede_id']);
        abort_if($sede === null, 404);

        $grupo = $this->tenant->conEmpresa($sede->empresa_id, fn () => $this->administrador->crearGrupo($request->user(), $sede, $datos['nombre']));

        return redirect()->route('espacios.sede', ['sede' => $sede->id, 'pestana' => 'secciones'])->with('ok', "Sección «{$grupo->nombre}» creada.");
    }

    /**
     * Asignar habitaciones a una sección (las desmarcadas salen de ella).
     */
    public function asignarSeccion(Request $request, int $grupo): RedirectResponse
    {
        Gate::authorize('espacios.editar');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $seccion = $this->tenant->conEmpresa($empresaId, fn () => GrupoEspacio::whereKey($grupo)
            ->whereIn('sede_id', $this->sedesVisibles($request)->pluck('id'))->first());
        abort_if($seccion === null, 404);

        $datos = $request->validate(['espacios' => ['array'], 'espacios.*' => ['integer']]);
        $ids = array_map('intval', $datos['espacios'] ?? []);
        $total = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->asignarGrupo($request->user(), $seccion, $ids));

        return redirect()->route('espacios.sede', ['sede' => $seccion->sede_id, 'pestana' => 'secciones'])->with('ok', "Sección «{$seccion->nombre}»: {$total} asignada(s).");
    }

    /**
     * Aviso de duplicado en vivo (Ronda 5, Z-02 / Z-07): mientras se escribe
     * el nombre o el código de un espacio, un tipo propio o una sección.
     * ?campo=nombre|codigo|tipo|seccion&valor=…&nivel=…&padre_id=…&sede_id=…&excluir={id}
     * Nombre repetido en el mismo lugar = "existe" (no se guardará; si está
     * desactivado se ofrece reactivarlo). Código igual sin espacios ni guiones
     * (TB = T-B = T B) o nombre parecido = "parecido" (solo aviso).
     */
    public function duplicado(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('espacios.crear') || $actor->can('espacios.editar'), 403);
        $avisos = app(AvisoDuplicado::class);
        $empresaId = $this->empresa->id($actor);
        $campo = Entrada::texto($request->query('campo'));
        $valor = mb_substr(Entrada::texto($request->query('valor')), 0, 100);
        if ($empresaId === null || ! in_array($campo, ['nombre', 'codigo', 'tipo', 'seccion'], true) || $avisos->clave($valor) === '') {
            return $avisos->nada();
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $avisos, $campo, $valor, $empresaId) {
            $sedes = $this->sedesVisibles($request);
            $nivel = Entrada::texto($request->query('nivel'));

            if ($campo === 'tipo') {
                abort_unless(array_key_exists($nivel, Espacio::PADRES), 422);
                $existente = TipoEspacio::disponiblesPara($empresaId, $nivel)->get(['id', 'nombre'])
                    ->first(fn ($t) => $avisos->claveNombre($t->nombre) === $avisos->claveNombre($valor));

                return $existente === null
                    ? $avisos->libre('Tipo nuevo: se agregará a la lista.')
                    : $avisos->existe("«{$existente->nombre}» ya existe en la lista: no hace falta agregarlo, ya puedes elegirlo.");
            }

            // Sin sede indicada (formulario con una sola sede) se usa la única visible
            $sede = $request->filled('sede_id')
                ? $sedes->firstWhere('id', (int) Entrada::texto($request->query('sede_id')))
                : ($sedes->count() === 1 ? $sedes->first() : null);
            if ($campo === 'seccion') {
                if ($sede === null) {
                    return $avisos->nada();
                }
                $existente = GrupoEspacio::where('sede_id', $sede->id)->get(['id', 'nombre'])
                    ->first(fn ($g) => $avisos->claveNombre($g->nombre) === $avisos->claveNombre($valor));

                return $existente === null
                    ? $avisos->libre()
                    : $avisos->existe("Ya existe la sección «{$existente->nombre}» en {$sede->nombre}.");
            }

            // Dónde va: el nodo que se edita, su contenedor (padre_id) o la sede (zonas / edificios)
            $excluir = (int) Entrada::texto($request->query('excluir')) ?: null;
            $nodo = $excluir !== null ? Espacio::whereKey($excluir)->whereIn('sede_id', $sedes->pluck('id'))->first() : null;
            $padreId = (int) Entrada::texto($request->query('padre_id')) ?: null;
            $padre = $nodo !== null ? $nodo->padre : ($padreId !== null ? Espacio::whereKey($padreId)->whereIn('sede_id', $sedes->pluck('id'))->first() : null);
            $sedeId = $nodo?->sede_id ?? $padre?->sede_id ?? $sede?->id;
            $nivel = $nodo?->nivel ?? $nivel;
            if ($sedeId === null || ($padreId !== null && $padre === null) || ! array_key_exists($nivel, Espacio::PADRES)) {
                return $avisos->nada();
            }

            $candidatos = Espacio::where('sede_id', $sedeId)
                ->when($campo === 'codigo', fn ($q) => $q->where('nivel', $nivel)->whereNotNull('codigo'),
                    fn ($q) => $padre === null ? $q->whereNull('padre_id') : $q->where('padre_id', $padre->id))
                ->when($nodo !== null, fn ($q) => $q->whereKeyNot($nodo->id))
                ->orderByDesc('activo')->orderBy('nombre')->limit(500)
                ->get(['id', 'nombre', 'codigo', 'activo']);
            $reactivar = fn (Espacio $e) => $actor->can('espacios.eliminar') ? route('espacios.estado', $e->id) : null;
            $titulo = fn (Espacio $e) => $e->nombre.($e->codigo ? " ({$e->codigo})" : '');
            $detalle = fn (Espacio $e) => $e->activo ? null : 'Desactivado';

            if ($campo === 'codigo') {
                $iguales = $candidatos->filter(fn ($e) => $avisos->clave($e->codigo) === $avisos->clave($valor))->take(3);

                return $iguales->isEmpty()
                    ? $avisos->libre('Código disponible.')
                    : $avisos->parecido('Se parece a '.$iguales->map(fn ($e) => '«'.$e->nombre.'» ('.$e->codigo.')')->implode(', ').': los espacios y guiones no cuentan, revisa que no sea el mismo.',
                        $iguales->map(fn ($e) => $avisos->coincidencia($titulo($e), $detalle($e), ! $e->activo, $reactivar($e)))->values()->all());
            }

            $igual = $candidatos->first(fn ($e) => mb_strtolower(trim($e->nombre)) === mb_strtolower(trim($valor)));
            if ($igual !== null) {
                return $avisos->existe($igual->activo
                    ? "Ya existe «{$igual->nombre}» en este mismo lugar."
                    : "Ya existe «{$igual->nombre}» en este mismo lugar, pero está desactivado. ¿Lo reactivas en lugar de crearlo de nuevo?",
                    [$avisos->coincidencia($titulo($igual), $detalle($igual), ! $igual->activo, $reactivar($igual))]);
            }
            $parecidos = $candidatos->filter(fn ($e) => $avisos->seParecen($e->nombre, $valor))->take(3);

            return $parecidos->isEmpty()
                ? $avisos->libre()
                : $avisos->parecido('Se parece a '.$parecidos->map(fn ($e) => '«'.$titulo($e).'»')->implode(', ').'. Revisa que no sea el mismo.',
                    $parecidos->map(fn ($e) => $avisos->coincidencia($titulo($e), $detalle($e), ! $e->activo, $reactivar($e)))->values()->all());
        });
    }

    // ------------------------------------------------------------------------

    /**
     * Sedes activas de la empresa de trabajo que el usuario puede ver aquí.
     *
     * @return Collection<int, Sede>
     */
    private function sedesVisibles(Request $request): Collection
    {
        $empresaId = $this->empresa->id($request->user());
        $permitidas = $this->autorizador->sedesPermitidas($request->user(), 'espacios.ver');

        return $this->tenant->conEmpresa($empresaId, fn () => Sede::query()
            ->where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')
            ->get(['id', 'empresa_id', 'nombre', 'codigo']));
    }

    /**
     * @return array{0: Espacio, 1: int}
     */
    private function buscar(Request $request, int $id): array
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $sedes = $this->sedesVisibles($request)->pluck('id');
        $nodo = $this->tenant->conEmpresa($empresaId, fn () => Espacio::whereKey($id)->whereIn('sede_id', $sedes)->first());
        abort_if($nodo === null, 404);

        return [$nodo, $empresaId];
    }

    /**
     * @return array{0: Sede, 1: ?Espacio, 2: int}
     */
    private function ubicacion(Request $request, array $datos): array
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        if (! empty($datos['padre_id'])) {
            [$padre] = $this->buscar($request, (int) $datos['padre_id']);
            $sede = $this->sedesVisibles($request)->firstWhere('id', $padre->sede_id);

            return [$sede, $padre, $empresaId];
        }

        $sede = $this->sedesVisibles($request)->firstWhere('id', (int) ($datos['sede_id'] ?? 0));
        abort_if($sede === null, 422, 'Elige una sede válida.');

        return [$sede, null, $empresaId];
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $nuevo): array
    {
        $request->merge(['codigo' => $request->filled('codigo') ? mb_strtoupper(trim(Entrada::texto($request->input('codigo')))) : null]);

        $datos = $request->validate([
            'nivel' => [$nuevo ? 'required' : 'prohibited', 'in:'.implode(',', array_keys(Espacio::PADRES))],
            'padre_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            // Sin nombre se usa el del tipo (Cama, Cama 2…), como en SEGCAT
            'nombre' => ['required_without:tipo_espacio_id', 'nullable', 'string', 'max:100'],
            'codigo' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/'],
            'tipo_espacio_id' => ['nullable', 'integer'],
            'grupo_espacio_id' => ['nullable', 'integer'],
            'area_comun' => ['nullable', 'boolean'], // revisión Etapa 5: vivienda o área común (giros residenciales)
        ], [
            'codigo.regex' => 'El código solo puede tener letras, números y guiones (sin espacios ni acentos).',
            'nombre.required_without' => 'Escribe un nombre o elige un tipo.',
        ]);

        $datos['nombre'] = trim((string) ($datos['nombre'] ?? ''));

        return $datos;
    }

    /**
     * Sin contenedor (zonas / edificios) se regresa a la pantalla de su sede.
     */
    private function volver(?Espacio $nodo, ?int $sedeId = null): RedirectResponse
    {
        if ($nodo === null) {
            return $sedeId !== null ? redirect()->route('espacios.sede', $sedeId) : redirect()->route('espacios.index');
        }

        // Áreas y elementos se muestran en su habitación: se va directo para no perder el aviso
        while (in_array($nodo->nivel, [Espacio::SUBAREA, Espacio::ELEMENTO], true) && $nodo->padre !== null) {
            $nodo = $nodo->padre;
        }

        return redirect()->route('espacios.show', $nodo->id);
    }

    /**
     * Pantalla donde se ve el espacio (áreas y elementos se ven en su habitación).
     */
    private function visible(Espacio $nodo): Espacio
    {
        while (in_array($nodo->nivel, [Espacio::SUBAREA, Espacio::ELEMENTO], true) && $nodo->padre !== null) {
            $nodo = $nodo->padre;
        }

        return $nodo;
    }

    /**
     * @return list<string>
     */
    private function nombresPorRango(int $desde, int $hasta, string $prefijo, bool $ceros): array
    {
        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        $hasta = min($hasta, $desde + AdministradorEspacios::MAX_LOTE - 1);
        // Con ceros, mínimo dos dígitos: prefijo 10 + 1..5 -> 1001..1005
        $digitos = max(2, strlen((string) $hasta));

        return array_map(fn ($n) => $prefijo.($ceros ? str_pad((string) $n, $digitos, '0', STR_PAD_LEFT) : (string) $n), range($desde, $hasta));
    }

    /**
     * @return array{crear: bool, editar: bool, estado: bool, borrar: bool}
     */
    private function puede(Request $request): array
    {
        return [
            'crear' => $request->user()->can('espacios.crear'),
            'editar' => $request->user()->can('espacios.editar'),
            'estado' => $request->user()->can('espacios.eliminar'),
            'borrar' => $request->user()->can('espacios.borrar'),
        ];
    }
}
