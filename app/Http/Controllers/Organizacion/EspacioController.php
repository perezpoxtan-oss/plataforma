<?php

namespace App\Http\Controllers\Organizacion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\Autorizador;
use App\Support\Espacios\Etiquetas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
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
     * Nivel 1: Zonas / Edificios (y pestaña de Secciones).
     */
    public function index(Request $request): View
    {
        Gate::authorize('espacios.ver');
        $empresaId = $this->empresa->id($request->user());

        if ($empresaId === null) {
            return view('organizacion.espacios.index', ['sinEmpresa' => true, 'etiquetas' => Etiquetas::para(null)]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId) {
            $empresa = Empresa::with('rubro')->findOrFail($empresaId);
            $sedes = $this->sedesVisibles($request);
            $sedeIds = $sedes->pluck('id');

            $edificios = Espacio::with('tipo:id,nombre')
                ->withCount(['hijos as pisos_count' => fn ($q) => $q->where('nivel', Espacio::AREA)])
                ->where('nivel', Espacio::EDIFICIO)
                ->whereIn('sede_id', $sedeIds)
                ->leftJoin('users as ua', 'ua.id', '=', 'espacios.actualizado_por')
                ->addSelect('ua.name as actualizado_por_nombre')
                ->orderBy('espacios.nombre')
                ->get();

            $secciones = GrupoEspacio::whereIn('sede_id', $sedeIds)
                ->withCount(['espacios as total' => fn ($q) => $q->where('activo', true)])
                ->orderBy('nombre')
                ->get()
                ->groupBy('sede_id');

            // Para "Asignar habitaciones" en la pestaña Secciones
            $porAsignar = $request->query('pestana') === 'secciones'
                ? Espacio::with('padre:id,nombre,padre_id,nivel', 'padre.padre:id,nombre')
                    ->where('nivel', Espacio::AREA_ESPECIFICA)->where('activo', true)->whereIn('sede_id', $sedeIds)
                    ->orderBy('nombre')->get(['id', 'sede_id', 'padre_id', 'nombre', 'grupo_espacio_id'])->groupBy('sede_id')
                : collect();

            return view('organizacion.espacios.index', [
                'sinEmpresa' => false,
                'porAsignar' => $porAsignar,
                'empresa' => $empresa,
                'sedes' => $sedes,
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

        return $this->volver($nodo->padre ?? null)->with('ok', "«{$nodo->nombre}» agregado correctamente.");
    }

    public function update(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.editar');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);
        $datos = $this->validar($request, false);

        $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->actualizar($request->user(), $nodo, $datos));

        return $this->volver($nodo->nivel === Espacio::AREA_ESPECIFICA && $request->boolean('desde_detalle') ? $nodo : $nodo->padre)
            ->with('ok', "«{$nodo->nombre}» actualizado correctamente.");
    }

    public function estado(Request $request, int $espacio): RedirectResponse
    {
        Gate::authorize('espacios.eliminar');
        [$nodo, $empresaId] = $this->buscar($request, $espacio);
        $activo = $request->boolean('activo');

        $afectados = $this->tenant->conEmpresa($empresaId, fn () => $this->administrador->cambiarEstado($request->user(), $nodo, $activo));
        $extra = $afectados > 1 ? ' junto con '.($afectados - 1).' espacio(s) que dependen de él' : '';

        return $this->volver($nodo->padre)->with($activo ? 'ok' : 'aviso', $activo
            ? "«{$nodo->nombre}» reactivado{$extra}."
            : "«{$nodo->nombre}» desactivado{$extra}. Su historial se conserva y puedes reactivarlo con el mismo botón.");
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

        return back()->with('ok', "Tipo «{$tipo->nombre}» disponible para tu empresa.");
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

        return redirect()->route('espacios.index', ['pestana' => 'secciones'])->with('ok', "Sección «{$grupo->nombre}» creada.");
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

        return redirect()->route('espacios.index', ['pestana' => 'secciones'])->with('ok', "Sección «{$seccion->nombre}»: {$total} asignada(s).");
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
        $request->merge(['codigo' => $request->filled('codigo') ? mb_strtoupper(trim((string) $request->input('codigo'))) : null]);

        $datos = $request->validate([
            'nivel' => [$nuevo ? 'required' : 'prohibited', 'in:'.implode(',', array_keys(Espacio::PADRES))],
            'padre_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            // Sin nombre se usa el del tipo (Cama, Cama 2…), como en SEGCAT
            'nombre' => ['required_without:tipo_espacio_id', 'nullable', 'string', 'max:100'],
            'codigo' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/'],
            'tipo_espacio_id' => ['nullable', 'integer'],
            'grupo_espacio_id' => ['nullable', 'integer'],
        ], [
            'codigo.regex' => 'El código solo puede tener letras, números y guiones (sin espacios ni acentos).',
            'nombre.required_without' => 'Escribe un nombre o elige un tipo.',
        ]);

        $datos['nombre'] = trim((string) ($datos['nombre'] ?? ''));

        return $datos;
    }

    private function volver(?Espacio $nodo): RedirectResponse
    {
        if ($nodo === null) {
            return redirect()->route('espacios.index');
        }

        // Áreas y elementos se muestran en su habitación: se va directo para no perder el aviso
        while (in_array($nodo->nivel, [Espacio::SUBAREA, Espacio::ELEMENTO], true) && $nodo->padre !== null) {
            $nodo = $nodo->padre;
        }

        return redirect()->route('espacios.show', $nodo->id);
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
     * @return array{crear: bool, editar: bool, estado: bool}
     */
    private function puede(Request $request): array
    {
        return [
            'crear' => $request->user()->can('espacios.crear'),
            'editar' => $request->user()->can('espacios.editar'),
            'estado' => $request->user()->can('espacios.eliminar'),
        ];
    }
}
