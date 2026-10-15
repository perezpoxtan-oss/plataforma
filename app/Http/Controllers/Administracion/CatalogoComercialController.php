<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionPlataforma;
use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\SolicitudCompra;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo comercial (Estructura → Plataforma): productos, planes, paquetes,
 * ajustes y solicitudes de la página pública de planes. Solo el Super
 * Administrador: módulo de tipo plataforma (catalogo_comercial.ver /
 * catalogo_comercial.editar), mismo criterio que Identidad y Respaldos.
 * Datos globales: sin empresa ni filtro de empresa.
 */
class CatalogoComercialController extends Controller
{
    public const PESTANAS = [
        'solicitudes' => ['Solicitudes', 'bi-inbox'],
        'planes' => ['Planes', 'bi-layers'],
        'paquetes' => ['Paquetes', 'bi-boxes'],
        'productos' => ['Productos', 'bi-grid'],
        'ajustes' => ['Ajustes', 'bi-sliders'],
    ];

    /** tipo => [modelo, nombre en singular, artículo] */
    private const TIPOS = [
        'productos' => [Producto::class, 'producto', 'el'],
        'planes' => [Plan::class, 'plan', 'el'],
        'paquetes' => [Paquete::class, 'paquete', 'el'],
    ];

    public function __construct(
        private readonly AjustesComerciales $ajustes,
        private readonly AdministradorRoles $auditoria,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('catalogo_comercial.ver');
        $pestana = Entrada::texto($request->query('pestana'), 'solicitudes');
        $estado = Entrada::texto($request->query('estado'), 'abiertas');

        $solicitudes = SolicitudCompra::query()
            ->when($estado === 'abiertas', fn ($q) => $q->whereIn('estado', ['nueva', 'contactada', 'cotizada']))
            ->when(isset(SolicitudCompra::ESTADOS[$estado]), fn ($q) => $q->where('estado', $estado))
            ->orderByRaw("CASE WHEN estado = 'nueva' THEN 0 ELSE 1 END")->orderByDesc('id')
            ->paginate(25, pageName: 'pagina')->withQueryString();

        return view('administracion.catalogo-comercial.index', [
            'pestana' => isset(self::PESTANAS[$pestana]) ? $pestana : 'solicitudes',
            'productos' => Producto::query()->with('modulos:id,clave,nombre')->withCount('planes')->orderBy('orden')->orderBy('id')->get(),
            'planes' => Plan::query()->with('productos:id,nombre')->orderBy('orden')->orderBy('id')->get(),
            'paquetes' => Paquete::query()->with(['productos:id,nombre', 'planes:id,nombre'])->orderBy('orden')->orderBy('id')->get(),
            'solicitudes' => $solicitudes,
            'estado' => $estado === 'todas' || $estado === 'abiertas' || isset(SolicitudCompra::ESTADOS[$estado]) ? $estado : 'abiertas',
            'nuevas' => SolicitudCompra::where('estado', 'nueva')->count(),
            'ajustes' => $this->ajustes->todos(),
            'puedeEditar' => $request->user()->can('catalogo_comercial.editar'),
        ]);
    }

    public function vistaPrevia(CatalogoPublico $catalogo): View
    {
        Gate::authorize('catalogo_comercial.ver');

        return view('publico.planes.inicio', $catalogo->pagina() + ['vistaPrevia' => true]);
    }

    // ------------------------------------------------------------------ Productos, planes y paquetes

    public function crear(string $tipo): View
    {
        Gate::authorize('catalogo_comercial.editar');
        [$clase] = $this->tipo($tipo);

        return $this->formulario($tipo, new $clase);
    }

    public function editar(string $tipo, int $id): View
    {
        Gate::authorize('catalogo_comercial.editar');
        [$clase] = $this->tipo($tipo);

        return $this->formulario($tipo, $clase::findOrFail($id));
    }

    public function guardar(Request $request, string $tipo, ?int $id = null): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        [$clase, $singular] = $this->tipo($tipo);
        /** @var Model $registro */
        $registro = $id === null ? new $clase : $clase::findOrFail($id);
        $nuevo = ! $registro->exists;
        $datos = $this->validar($request, $tipo, $registro);
        $antes = $nuevo ? null : $this->instantanea($tipo, $registro);

        DB::transaction(function () use ($tipo, $registro, $datos, $nuevo, $clase) {
            $relaciones = array_intersect_key($datos, array_flip(['modulos', 'productos', 'planes']));
            $registro->fill(array_diff_key($datos, $relaciones));
            if ($nuevo) {
                $registro->orden = (int) $clase::max('orden') + 1;
            }
            $registro->save();
            if ($tipo === 'productos' && array_key_exists('modulos', $relaciones)) {
                $registro->modulos()->sync(Modulo::whereIn('clave', $relaciones['modulos'])->pluck('id'));
            }
            foreach (['productos', 'planes'] as $relacion) {
                if ($tipo !== 'productos' && array_key_exists($relacion, $relaciones)) {
                    $registro->{$relacion}()->sync($relaciones[$relacion]);
                }
            }
            // Un solo plan destacado
            if ($tipo === 'planes' && $registro->destacado) {
                Plan::whereKeyNot($registro->id)->where('destacado', true)->update(['destacado' => false]);
            }
        });

        $this->auditoria->auditar($request->user(), "catalogo_comercial.{$singular}_".($nuevo ? 'creado' : 'actualizado'), $registro,
            $antes, $this->instantanea($tipo, $registro->fresh()));

        $nombre = $registro->nombre;
        $siguientes = [['Ver la página como la verá el público', route('catalogo-comercial.vista-previa'), 'bi-eye']];
        if ($nuevo) {
            $siguientes[] = ['Dar de alta otro '.$singular, route('catalogo-comercial.crear', $tipo), 'bi-plus-circle'];
        }
        $aviso = ucfirst($singular)." «{$nombre}» guardado.";
        if ($tipo !== 'productos' && ! $registro->publicado) {
            $aviso .= ' Aún no se ve en la página: marca «Mostrar en la página pública» cuando esté listo.';
        }

        return redirect()->route('catalogo-comercial.index', ['pestana' => $tipo])->with('ok', $aviso)->with('siguientes', $siguientes);
    }

    public function estado(Request $request, string $tipo, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        [$clase, $singular] = $this->tipo($tipo);
        $registro = $clase::findOrFail($id);
        $activo = $request->boolean('activo');
        $registro->activo = $activo;
        $registro->save();
        $this->auditoria->auditar($request->user(), "catalogo_comercial.{$singular}_".($activo ? 'reactivado' : 'dado_de_baja'), $registro,
            ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('catalogo-comercial.index', ['pestana' => $tipo])
            ->with('ok', ucfirst($singular)." «{$registro->nombre}» ".($activo ? 'reactivado.' : 'dado de baja: ya no se ve en la página pública. Su historial y las solicitudes se conservan.'));
    }

    /** Subir o bajar un lugar en el orden de la página. */
    public function mover(Request $request, string $tipo, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        [$clase] = $this->tipo($tipo);
        $lista = $clase::query()->orderBy('orden')->orderBy('id')->get(['id', 'orden', 'nombre']);
        $posicion = $lista->search(fn ($r) => $r->id === $id);
        abort_if($posicion === false, 404);
        $destino = $request->input('direccion') === 'arriba' ? $posicion - 1 : $posicion + 1;
        if ($destino >= 0 && $destino < $lista->count()) {
            $ordenados = $lista->all();
            [$ordenados[$posicion], $ordenados[$destino]] = [$ordenados[$destino], $ordenados[$posicion]];
            DB::transaction(function () use ($ordenados, $clase) {
                foreach (array_values($ordenados) as $i => $r) {
                    $clase::whereKey($r->id)->update(['orden' => $i + 1]);
                }
            });
        }

        return redirect()->to(route('catalogo-comercial.index', ['pestana' => $tipo]).'#'.$tipo.'-'.$id)
            ->with('ok', 'Orden guardado: así aparece en la página pública.');
    }

    // ------------------------------------------------------------------ Ajustes

    public function ajustes(Request $request): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $datos = $request->validate([
            'landing_publicada' => ['nullable', 'boolean'],
            'moneda' => ['required', Rule::in(array_keys(AjustesComerciales::MONEDAS))],
            'iva_porcentaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'leyenda_iva' => ['nullable', 'string', 'max:30'],
            'portada_titulo' => ['required', 'string', 'max:120'],
            'portada_subtitulo' => ['nullable', 'string', 'max:300'],
            'beneficios' => ['nullable', 'string', 'max:1200'],
            'preguntas' => ['nullable', 'string', 'max:8000'],
            'correos_solicitudes' => ['nullable', 'string', 'max:600'],
            'texto_legal' => ['nullable', 'string', 'max:1000'],
            'aviso_privacidad' => ['nullable', 'string', 'max:20000'],
        ], [
            'iva_porcentaje.max' => 'El IVA es un porcentaje entre 0 y 100.',
            'portada_titulo.required' => 'Escribe el título de la portada.',
        ], [
            'iva_porcentaje' => 'IVA', 'portada_titulo' => 'título de la portada', 'correos_solicitudes' => 'correos que reciben las solicitudes',
        ]);

        $correos = collect(preg_split('/[\s,;]+/', (string) ($datos['correos_solicitudes'] ?? '')))->map(fn ($c) => mb_strtolower(trim($c)))->filter()->unique()->values();
        $malos = $correos->reject(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL));
        if ($malos->isNotEmpty()) {
            return back()->withInput()->withErrors(['correos_solicitudes' => 'Revisa estos correos: '.$malos->implode(', ').'.']);
        }

        $antes = $this->ajustes->todos();
        $publicar = $request->boolean('landing_publicada');
        $this->ajustes->guardar([
            'landing_publicada' => $publicar,
            'moneda' => $datos['moneda'],
            'iva_porcentaje' => round((float) $datos['iva_porcentaje'], 2),
            'leyenda_iva' => trim((string) ($datos['leyenda_iva'] ?? '')),
            'portada_titulo' => trim($datos['portada_titulo']),
            'portada_subtitulo' => trim((string) ($datos['portada_subtitulo'] ?? '')),
            'beneficios' => collect(explode("\n", (string) ($datos['beneficios'] ?? '')))->map(fn ($b) => mb_substr(trim($b), 0, 150))->filter()->take(8)->values()->all(),
            'preguntas' => AjustesComerciales::preguntasDesdeTexto((string) ($datos['preguntas'] ?? '')),
            'correos_solicitudes' => $correos->take(10)->all(),
            'texto_legal' => trim((string) ($datos['texto_legal'] ?? '')),
            'aviso_privacidad' => trim((string) ($datos['aviso_privacidad'] ?? '')),
        ]);
        $registro = ConfiguracionPlataforma::where('clave', AjustesComerciales::CLAVE)->first();
        if ($registro !== null) {
            $this->auditoria->auditar($request->user(), 'catalogo_comercial.ajustes_actualizados', $registro,
                array_diff_key($antes, ['sembrado_en' => 1]), array_diff_key($this->ajustes->todos(), ['sembrado_en' => 1]));
        }

        $cambioPublicacion = $antes['landing_publicada'] !== $publicar;
        $aviso = match (true) {
            $cambioPublicacion && $publicar => 'Ajustes guardados y página publicada: los visitantes ya la ven en la dirección de la plataforma.',
            $cambioPublicacion => 'Ajustes guardados y página retirada: los visitantes vuelven a ver la pantalla de acceso.',
            default => 'Ajustes guardados.',
        };

        return redirect()->route('catalogo-comercial.index', ['pestana' => 'ajustes'])->with('ok', $aviso)
            ->with('siguientes', [['Ver la página como la verá el público', route('catalogo-comercial.vista-previa'), 'bi-eye']]);
    }

    // ------------------------------------------------------------------ Solicitudes

    public function solicitud(int $id): View
    {
        Gate::authorize('catalogo_comercial.ver');

        return view('administracion.catalogo-comercial.solicitud', [
            's' => SolicitudCompra::with('plan:id,nombre')->findOrFail($id),
            'puedeEditar' => auth()->user()->can('catalogo_comercial.editar'),
        ]);
    }

    public function atender(Request $request, int $id, CentroNotificaciones $centro): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = SolicitudCompra::findOrFail($id);
        $datos = $request->validate([
            'estado' => ['required', Rule::in(array_keys(SolicitudCompra::ESTADOS))],
            'referencia_pago' => ['nullable', 'string', 'max:100'],
            'notas_internas' => ['nullable', 'string', 'max:5000'],
        ]);
        $antes = $s->only(['estado', 'referencia_pago', 'notas_internas']);
        $s->fill([
            'estado' => $datos['estado'],
            'referencia_pago' => trim((string) ($datos['referencia_pago'] ?? '')) ?: null,
            'notas_internas' => trim((string) ($datos['notas_internas'] ?? '')) ?: null,
        ]);
        if ($s->estado !== 'nueva' && $s->atendida_en === null) {
            $s->atendida_en = now();
        }
        $s->save();
        // Ya la atendieron: el aviso de la campana pierde su urgencia para todos
        if ($s->estado !== 'nueva') {
            $centro->resolver('solicitud_compra', $s->id);
        }
        $this->auditoria->auditar($request->user(), 'catalogo_comercial.solicitud_atendida', $s, $antes, $s->only(['estado', 'referencia_pago', 'notas_internas']));

        $siguiente = SolicitudCompra::where('estado', 'nueva')->whereKeyNot($s->id)->orderBy('id')->first();
        $siguientes = $siguiente !== null
            ? [['Atender la siguiente nueva ('.$siguiente->folio.')', route('catalogo-comercial.solicitudes.show', $siguiente->id), 'bi-inbox']]
            : [['Volver a las solicitudes', route('catalogo-comercial.index', ['pestana' => 'solicitudes']), 'bi-inbox']];

        return redirect()->route('catalogo-comercial.solicitudes.show', $s->id)
            ->with('ok', "Solicitud {$s->folio} guardada como «{$s->estadoTexto()}».")->with('siguientes', $siguientes);
    }

    // ------------------------------------------------------------------ Ayudas

    /** @return array{0: class-string<Model>, 1: string, 2: string} */
    private function tipo(string $tipo): array
    {
        abort_unless(isset(self::TIPOS[$tipo]), 404);

        return self::TIPOS[$tipo];
    }

    private function formulario(string $tipo, Model $registro): View
    {
        return view('administracion.catalogo-comercial.formulario', [
            'tipo' => $tipo,
            'singular' => self::TIPOS[$tipo][1],
            'r' => $registro,
            'modulos' => Modulo::query()->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->whereNull('padre_id')
                ->orderBy('area_id')->orderBy('orden')->get(['id', 'clave', 'nombre', 'icono']),
            'productos' => Producto::query()->orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'icono', 'activo']),
            'planes' => Plan::query()->orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'activo']),
            'marcados' => [
                'modulos' => $registro instanceof Producto && $registro->exists ? $registro->modulos()->pluck('clave')->all() : [],
                'productos' => ($registro instanceof Plan || $registro instanceof Paquete) && $registro->exists ? $registro->productos()->pluck('productos.id')->all() : [],
                'planes' => $registro instanceof Paquete && $registro->exists ? $registro->planes()->pluck('planes.id')->all() : [],
            ],
            'ajustes' => $this->ajustes->todos(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, string $tipo, Model $registro): array
    {
        $tabla = $registro->getTable();
        $slug = fn () => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($tabla, 'slug')->ignore($registro->getKey())];
        $mensajes = [
            'clave.regex' => 'La clave solo lleva minúsculas, números y guion bajo (por ejemplo caseta_accesos).',
            'clave.unique' => 'Ya existe un producto con esa clave.',
            'slug.regex' => 'La dirección corta solo lleva minúsculas, números y guiones (por ejemplo plan-profesional).',
            'slug.unique' => 'Ya existe otro con esa dirección corta.',
            'icono.regex' => 'El ícono se escribe como bi-nombre (por ejemplo bi-door-open).',
            'precio_por_sede_mensual.required_unless' => 'Escribe el precio por sede al mes, o marca «Cotizar» para no mostrar precio.',
            'nombre.required' => 'Escribe el nombre.',
        ];
        $atributos = ['precio_por_sede_mensual' => 'precio por sede al mes', 'sedes_minimas' => 'sedes mínimas', 'usuarios_por_sede' => 'usuarios por sede'];
        $productos = ['nullable', 'array', 'max:50'];
        $productoId = ['integer', Rule::exists('productos', 'id')];

        $datos = match ($tipo) {
            'productos' => $request->validate([
                'clave' => $registro->exists ? ['prohibited'] : ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('productos', 'clave')],
                'nombre' => ['required', 'string', 'max:100'],
                'descripcion' => ['nullable', 'string', 'max:255'],
                'icono' => ['nullable', 'string', 'max:40', 'regex:/^bi-[a-z0-9-]+$/'],
                'modulos' => ['nullable', 'array', 'max:80'],
                'modulos.*' => ['string', Rule::exists('modulos', 'clave')->where(fn ($q) => $q->where('tipo', '!=', Modulo::TIPO_PLATAFORMA))],
            ], $mensajes),
            'planes' => $request->validate([
                'nombre' => ['required', 'string', 'max:60'],
                'slug' => $slug(),
                'frase' => ['nullable', 'string', 'max:150'],
                'descripcion' => ['nullable', 'string', 'max:2000'],
                'cotizar' => ['nullable', 'boolean'],
                'precio_por_sede_mensual' => ['required_unless:cotizar,1', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
                'sedes_minimas' => ['nullable', 'integer', 'min:1', 'max:999'],
                'usuarios_por_sede' => ['nullable', 'integer', 'min:1', 'max:9999'],
                'destacado' => ['nullable', 'boolean'],
                'publicado' => ['nullable', 'boolean'],
                'productos' => $productos,
                'productos.*' => $productoId,
            ], $mensajes, $atributos),
            'paquetes' => $request->validate([
                'nombre' => ['required', 'string', 'max:80'],
                'slug' => $slug(),
                'descripcion' => ['nullable', 'string', 'max:255'],
                'modalidad' => ['required', Rule::in(array_keys(Paquete::MODALIDADES))],
                'precio' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
                'por_sede' => ['nullable', 'boolean'],
                'publicado' => ['nullable', 'boolean'],
                'productos' => $productos,
                'productos.*' => $productoId,
                'planes' => ['nullable', 'array', 'max:50'],
                'planes.*' => ['integer', Rule::exists('planes', 'id')],
            ], $mensajes + ['precio.required' => 'Escribe el precio.']),
        };

        if ($tipo === 'productos') {
            $datos['icono'] = $datos['icono'] ?? null ?: 'bi-box';
            $datos['modulos'] = array_values(array_unique($datos['modulos'] ?? []));
            if ($registro->exists) {
                unset($datos['clave']);
            }

            return $datos;
        }

        // Dirección corta automática a partir del nombre
        $base = $datos['slug'] ?? null ?: Str::slug($datos['nombre']) ?: $tipo;
        $datos['slug'] = $this->slugLibre($registro, mb_substr($base, 0, 55));
        foreach ($tipo === 'planes' ? ['publicado', 'cotizar', 'destacado'] : ['publicado', 'por_sede'] as $casilla) {
            $datos[$casilla] = $request->boolean($casilla);
        }
        if ($tipo === 'planes') {
            $datos['precio_por_sede_mensual'] = $datos['precio_por_sede_mensual'] ?? 0;
            $datos['sedes_minimas'] = $datos['sedes_minimas'] ?? 1;
            $datos['usuarios_por_sede'] = $datos['usuarios_por_sede'] ?? null;
        } else {
            $datos['planes'] = array_values(array_unique(array_map('intval', $datos['planes'] ?? [])));
        }
        $datos['productos'] = array_values(array_unique(array_map('intval', $datos['productos'] ?? [])));

        return $datos;
    }

    private function slugLibre(Model $registro, string $base): string
    {
        $slug = $base;
        $n = 2;
        while ($registro::query()->where('slug', $slug)->whereKeyNot($registro->getKey() ?? 0)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function instantanea(string $tipo, Model $registro): array
    {
        $datos = collect($registro->attributesToArray())->except(['created_at', 'updated_at', 'creado_por', 'actualizado_por'])->all();
        if ($tipo === 'productos') {
            $datos['modulos'] = $registro->modulos()->pluck('clave')->all();
        } else {
            $datos['productos'] = $registro->productos()->pluck('productos.nombre')->all();
            if ($tipo === 'paquetes') {
                $datos['planes'] = $registro->planes()->pluck('planes.nombre')->all();
            }
        }

        return $datos;
    }
}
