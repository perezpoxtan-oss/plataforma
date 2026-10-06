<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Procedimiento;
use App\Models\ProcedimientoAcuse;
use App\Models\ProcedimientoAdjunto;
use App\Models\ProcedimientoCategoria;
use App\Models\ProcedimientoEvento;
use App\Models\ProcedimientoVersion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Firmas\Firmas;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Procedimientos (manual de procedimientos operativos; no existe en SEGCAT):
 * lista en fichas por categoría (Emergencias primero), ficha con pestañas
 * Contenido · Versiones · Acuses, modo lectura para el celular de la caseta,
 * hoja impresa con QR, circuito Borrador → En revisión → Publicado con firma,
 * versiones y acuse «Leí y entendí».
 *
 * Permisos: ver (consultar, leer, imprimir y firmar el acuse), crear, editar
 * (borradores, versión nueva, categorías), aprobar (aprobar o rechazar),
 * eliminar (retirar / reactivar) y borrar (eliminar definitivamente un
 * borrador que nunca se publicó; ver RegistroBorrado). El alcance lo decide
 * AdministradorProcedimientos.
 */
class ProcedimientoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorProcedimientos $procedimientos,
        private readonly AdministradorPasesSalida $pases,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        Gate::authorize('procedimientos.ver');

        return $this->lista($request, null);
    }

    /** Mis procedimientos por leer (y firmar de enterado). */
    public function porLeer(Request $request): View|RedirectResponse
    {
        Gate::authorize('procedimientos.ver');

        return $this->lista($request, 'por_leer');
    }

    private function lista(Request $request, ?string $forzar): View|RedirectResponse
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('seguridad.procedimientos.index', ['sinEmpresa' => true]);
        }

        $filtros = $request->validate([
            'filtro' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'],
            'categoria' => ['nullable', 'integer'],
        ]);
        $texto = trim(Entrada::texto($filtros['q'] ?? ''));
        $categoriaFiltro = isset($filtros['categoria']) ? (int) $filtros['categoria'] : null;

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros, $texto, $categoriaFiltro, $forzar) {
            $srv = $this->procedimientos;

            // QR de la hoja impresa leído con un lector que "escribe" (o pegado): abre el modo lectura
            if ($texto !== '' && preg_match('#(?:/e/)?([a-z0-9]{24})/?$#i', $texto, $m)) {
                $porQr = $srv->limitar(Procedimiento::query(), $actor)->where('codigo_qr', mb_strtolower($m[1]))->value('id');
                if ($porQr !== null) {
                    return redirect()->route('procedimientos.leer', $porQr);
                }
            }

            $lector = $srv->soloLectura($actor);
            $disponibles = collect(AdministradorProcedimientos::FILTROS)
                ->filter(fn ($t, $clave) => match ($clave) {
                    'todos', 'por_leer' => true,
                    'por_aprobar' => $actor->can('procedimientos.aprobar'),
                    default => ! $lector,
                });
            $filtro = $forzar ?? ($disponibles->has($filtros['filtro'] ?? '') ? $filtros['filtro'] : 'todos');

            $categorias = $srv->categorias();
            $base = fn () => $srv->buscar($srv->limitar(Procedimiento::query(), $actor), $texto)
                ->when($categoriaFiltro !== null, fn ($q) => $q->where('procedimientos.categoria_id', $categoriaFiltro));
            $conteos = $disponibles->map(fn ($t, $clave) => $srv->filtrar($base(), $clave, $actor)->count());

            $conVersion = fn ($q) => $q->with('aplicaciones')->withCount(['pasos', 'pasos as criticos_count' => fn ($p) => $p->where('critico', true)]);
            $lista = $srv->filtrar($base(), $filtro, $actor)
                ->with(['categoria', 'vigente' => $conVersion, 'trabajo' => $conVersion, 'creador:id,name', 'editor:id,name'])
                ->orderBy(ProcedimientoCategoria::select('orden')->whereColumn('procedimiento_categorias.id', 'procedimientos.categoria_id'))
                ->orderBy('procedimientos.clave')
                ->paginate(AdministradorProcedimientos::POR_PAGINA)->withQueryString();

            $veAcuses = $srv->veAcuses($actor);
            $porLeer = $srv->pendientesDe($actor)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $puedeCrear = $actor->can('procedimientos.crear');

            return view('seguridad.procedimientos.index', [
                'sinEmpresa' => false,
                'forzado' => $forzar !== null,
                'lista' => $lista,
                'filtro' => $filtro,
                'filtros' => $disponibles,
                'conteos' => $conteos,
                'texto' => $texto,
                'categorias' => $categorias,
                'categoriaFiltro' => $categoriaFiltro,
                'porLeer' => $porLeer,
                'porAprobar' => $srv->idsPorAprobar($actor),
                'cumplimientos' => $veAcuses ? $srv->cumplimientos($lista->getCollection()->pluck('vigente')->filter()->values()) : [],
                'lector' => $lector,
                'empresaNombre' => Empresa::whereKey(app(Tenant::class)->empresaId())->value('nombre_comercial'),
                'puede' => [
                    'crear' => $puedeCrear,
                    'categorias' => $actor->can('procedimientos.editar'),
                    'categoriasEmpresa' => $actor->can('procedimientos.editar') && app(Autorizador::class)->alcanceDeEmpresa($actor, 'procedimientos.editar'),
                    'acuses' => $veAcuses,
                ],
                'formulario' => $puedeCrear ? $srv->opcionesFormulario($actor, 'procedimientos.crear') + ['clave' => $srv->claveSugerida()] : null,
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('procedimientos.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $p = $this->tenant->conEmpresa($empresaId, fn () => $this->procedimientos->crear($request->user(), $request->all()));
        } catch (ValidationException $e) {
            return redirect()->route('procedimientos.index')->withErrors($e->errors())
                ->withInput($request->except(['_token', 'adjuntos']) + ['_dialogo' => 'nuevo']);
        }

        $enviado = $p->estado === Procedimiento::EN_REVISION;

        return redirect()->route('procedimientos.show', $p->id)
            ->with('ok', $enviado ? "Procedimiento {$p->clave} guardado y enviado a revisión." : "Procedimiento {$p->clave} guardado como borrador.");
    }

    /**
     * Ficha: Contenido · Versiones · Acuses, con las acciones que el usuario puede hacer.
     */
    public function show(Request $request, int $procedimiento): View
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento) {
            $actor = $request->user();
            $p = $this->buscarEnAlcance($actor, $procedimiento);

            return view('seguridad.procedimientos.show', $this->datosFicha($request, $actor, $p));
        });
    }

    /**
     * Modo lectura (letra grande, pasos numerados, críticos resaltados) y,
     * al final, el acuse «Leí y entendí».
     */
    public function leer(Request $request, int $procedimiento): View
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento) {
            $actor = $request->user();
            $p = $this->buscarEnAlcance($actor, $procedimiento);
            $version = $this->versionVisible($request, $actor, $p);
            $esVigente = $version->estado === ProcedimientoVersion::PUBLICADA && $p->estaPublicado();
            $miAcuse = $esVigente ? $version->acuses()->where('user_id', $actor->id)->first() : null;

            return view('seguridad.procedimientos.leer', [
                'p' => $p,
                'version' => $version,
                'esVigente' => $esVigente,
                'miAcuse' => $miAcuse,
                'meAplica' => $esVigente && $this->procedimientos->pendientesDe($actor)->contains('id', $p->id),
                'firmaGuardada' => $this->pases->firmaGuardada($actor) !== null,
            ]);
        });
    }

    /**
     * Hoja impresa: logo, clave, versión, fechas, aprobado por (con firma), pasos y QR.
     */
    public function imprimir(Request $request, int $procedimiento): View
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento, $empresaId) {
            $actor = $request->user();
            $p = $this->buscarEnAlcance($actor, $procedimiento);
            $version = $this->versionVisible($request, $actor, $p);
            $empresa = Empresa::whereKey($empresaId)->first(['id', 'nombre_comercial', 'razon_social', 'logo_ruta']);
            $logo = $empresa->logo_ruta;
            $url = route('lector.ir', $p->codigo_qr);

            return view('seguridad.procedimientos.imprimir', [
                'p' => $p,
                'version' => $version,
                'empresa' => $empresa,
                'logo' => is_string($logo) && $logo !== '' && ! str_contains($logo, '..') && is_file(public_path($logo)) ? asset($logo) : null,
                'qr' => (string) preg_replace('/^<\?xml[^>]*>\s*/', '', (new Writer(new ImageRenderer(new RendererStyle(150, 1), new SvgImageBackEnd)))->writeString($url)),
                'urlQr' => $url,
                'aplicaA' => $this->aplicaA($version),
            ]);
        });
    }

    /** Corregir el borrador de trabajo (la versión publicada no se edita). */
    public function update(Request $request, int $procedimiento): RedirectResponse
    {
        abort_unless($request->user()->can('procedimientos.editar') || $request->user()->can('procedimientos.crear'), 403);

        return $this->accion($request, $procedimiento, 'editar', fn ($p) => $this->procedimientos->actualizar($request->user(), $p, $request->all()),
            fn ($p) => ['ok', $p->estado_trabajo === Procedimiento::EN_REVISION ? "Borrador de {$p->clave} guardado y enviado a revisión." : "Borrador de {$p->clave} guardado."],
            ['_token', '_method', 'adjuntos']);
    }

    public function nuevaVersion(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.editar');

        return $this->accion($request, $procedimiento, null, fn ($p) => $this->procedimientos->nuevaVersion($request->user(), $p),
            fn ($p) => ['ok', "Se creó la versión {$p->version_trabajo} de {$p->clave} como borrador. La versión {$p->version_vigente} sigue vigente hasta que se apruebe la nueva."]);
    }

    public function enviar(Request $request, int $procedimiento): RedirectResponse
    {
        abort_unless($request->user()->can('procedimientos.editar') || $request->user()->can('procedimientos.crear'), 403);

        return $this->accion($request, $procedimiento, 'enviar', fn ($p) => $this->procedimientos->enviar($request->user(), $p, $request->all()),
            fn ($p) => ['ok', "Versión {$p->version_trabajo} de {$p->clave} enviada a revisión. Quien tiene «Aprobar» la verá en su lista."], ['_token']);
    }

    public function aprobar(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.aprobar');

        return $this->accion($request, $procedimiento, 'aprobar', fn ($p) => $this->procedimientos->aprobar($request->user(), $p, $request->all()),
            fn ($p) => ['ok', "Versión {$p->version_vigente} de {$p->clave} aprobada y publicada. Se avisó al personal que debe leerla y firmarla."],
            ['_token', 'firma']);
    }

    public function rechazar(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.aprobar');

        return $this->accion($request, $procedimiento, 'rechazar', fn ($p) => $this->procedimientos->rechazar($request->user(), $p, $request->all()),
            fn ($p) => ['aviso', "Versión {$p->version_trabajo} de {$p->clave} rechazada: regresó a borrador con tu comentario."], ['_token']);
    }

    public function descartar(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.editar');

        return $this->accion($request, $procedimiento, null, fn ($p) => $this->procedimientos->descartar($request->user(), $p),
            fn ($p) => ['aviso', "Se descartó el borrador de {$p->clave}. Sigue vigente la versión {$p->version_vigente}."]);
    }

    public function retirar(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.eliminar');

        return $this->accion($request, $procedimiento, 'retirar', fn ($p) => $this->procedimientos->retirar($request->user(), $p, $request->all()),
            fn ($p) => ['aviso', "{$p->clave} quedó retirado (obsoleto): ya no se consulta ni se pide firmar."], ['_token']);
    }

    public function reactivar(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.eliminar');

        return $this->accion($request, $procedimiento, null, fn ($p) => $this->procedimientos->reactivar($request->user(), $p),
            fn ($p) => ['ok', "{$p->clave} se reactivó: vuelve a regir la versión {$p->version_vigente}."]);
    }

    /** «Leí y entendí este procedimiento» con firma. */
    public function acusar(Request $request, int $procedimiento): RedirectResponse
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $p = $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento) {
                $p = $this->buscarEnAlcance($request->user(), $procedimiento);
                $this->procedimientos->acusar($request->user(), $p, $request->all());

                return $p;
            });
        } catch (ValidationException $e) {
            return redirect()->to(route('procedimientos.leer', $procedimiento).'#acuse')->withErrors($e->errors())
                ->withInput(['entendido' => $request->boolean('entendido') ? '1' : null, 'guardar_firma' => $request->boolean('guardar_firma') ? '1' : null]);
        }

        $siguiente = $this->tenant->conEmpresa($empresaId, fn () => $this->procedimientos->pendientesDe($request->user())->first());

        return redirect()->route($siguiente ? 'procedimientos.por-leer' : 'procedimientos.show', $siguiente ? [] : $p->id)
            ->with('ok', "Listo: firmaste de enterado {$p->clave} (versión {$p->version_vigente})."
                .($siguiente ? ' Te falta leer otros procedimientos.' : ''));
    }

    /** Acuses de una versión en CSV (con App\Support\Csv). */
    public function exportarAcuses(Request $request, int $procedimiento): StreamedResponse
    {
        abort_unless($this->procedimientos->veAcuses($request->user()), 403);
        $empresaId = $this->empresaDeTrabajo($request);
        $hora = app(HoraLocal::class);

        [$p, $version, $datos] = $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento) {
            $actor = $request->user();
            $p = $this->buscarEnAlcance($actor, $procedimiento);
            $version = $p->vigente()->with('aplicaciones')->first() ?? abort(404);
            [$sede, $depto] = $this->filtrosAcuses($request);

            return [$p, $version, $this->procedimientos->cumplimiento($actor, $version, $sede, $depto)];
        });

        return response()->streamDownload(function () use ($p, $version, $datos, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Clave', 'Procedimiento', 'Versión', 'Nombre', 'Núm. de empleado', 'Sede', 'Departamento', 'Puesto', 'Estado', 'Fecha de firma']);
            foreach ($datos['filas'] as $fila) {
                $c = $fila['usuario']->colaborador;
                Csv::fila($salida, [
                    $p->clave, $version->titulo, $version->numero, $fila['usuario']->name, $c?->num_empleado, $c?->sede?->nombre ?? 'Corporativo',
                    $c?->departamento?->nombre, $c?->puesto?->nombre, $fila['acuse'] ? 'Firmó' : 'Falta', $fila['acuse'] ? $hora->formatear($fila['acuse']->leido_en) : '',
                ]);
            }
            foreach ($datos['extra'] as $a) {
                Csv::fila($salida, [$p->clave, $version->titulo, $version->numero, $a->nombre, '', '', '', '', 'Firmó (sin estar obligado)', $hora->formatear($a->leido_en)]);
            }
            fclose($salida);
        }, 'Acuses_'.preg_replace('/[^A-Za-z0-9\-]/', '', $p->clave).'_v'.$version->numero.'_'.Carbon::now($hora->zona())->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Adjunto de una versión (disco privado): solo con permiso y alcance. */
    public function adjunto(Request $request, int $procedimiento, int $adjunto): StreamedResponse
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento, $adjunto) {
            $actor = $request->user();
            $p = $this->buscarEnAlcance($actor, $procedimiento);
            $registro = ProcedimientoAdjunto::whereKey($adjunto)
                ->whereIn('version_id', $this->versionesVisibles($actor, $p)->pluck('id'))->first();
            abort_if($registro === null || ! str_starts_with($registro->ruta, 'procedimientos/'.$p->empresa_id.'/') || str_contains($registro->ruta, '..'), 404);
            abort_unless(Storage::disk('local')->exists($registro->ruta), 404);

            return Storage::disk('local')->response($registro->ruta, $registro->nombre, [
                'Content-Type' => $registro->mime,
                'Cache-Control' => 'private, max-age=600',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => 'sandbox',
            ], $registro->esImagen() ? 'inline' : 'attachment');
        });
    }

    /** Firma de quien aprobó una versión. */
    public function firmaAprobacion(Request $request, int $procedimiento, int $version, Firmas $firmas): StreamedResponse
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento, $version, $firmas) {
            $p = $this->buscarEnAlcance($request->user(), $procedimiento);
            $registro = $this->versionesVisibles($request->user(), $p)->firstWhere('id', $version);
            abort_if($registro === null || $registro->firma_ruta === null, 404);

            return $firmas->respuesta($registro->firma_ruta);
        });
    }

    /** Firma de un acuse: su dueño, o quien ve la pestaña Acuses (dentro de su alcance). */
    public function firmaAcuse(Request $request, int $procedimiento, int $acuse, Firmas $firmas): StreamedResponse
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $procedimiento, $acuse, $firmas) {
            $actor = $request->user();
            $p = $this->buscarEnAlcance($actor, $procedimiento);
            $registro = ProcedimientoAcuse::whereIn('version_id', ProcedimientoVersion::where('procedimiento_id', $p->id)->select('id'))->find($acuse);
            abort_if($registro === null, 404);
            if ((int) $registro->user_id !== (int) $actor->id) {
                abort_unless($this->procedimientos->veAcuses($actor), 403);
                $version = $registro->version()->with('aplicaciones')->first();
                $visibles = $this->procedimientos->cumplimiento($actor, $version);
                $ids = $visibles['filas']->pluck('usuario.id')->merge($visibles['extra']->pluck('user_id'))->map(fn ($id) => (int) $id)->all();
                abort_unless(in_array((int) $registro->user_id, $ids, true), 404);
            }

            return $firmas->respuesta($registro->firma_ruta);
        });
    }

    /** Mi firma guardada (la misma de Pases de salida; solo la ve su dueño). */
    public function miFirma(Request $request, Firmas $firmas): StreamedResponse
    {
        Gate::authorize('procedimientos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $firmas) {
            $guardada = $this->pases->firmaGuardada($request->user());
            abort_if($guardada === null, 404);

            return $firmas->respuesta($guardada->firma_ruta);
        });
    }

    /** Alta de una categoría (Editar con alcance de empresa). */
    public function guardarCategoria(Request $request): RedirectResponse
    {
        Gate::authorize('procedimientos.editar');

        return $this->categoria($request, null);
    }

    public function actualizarCategoria(Request $request, int $categoria): RedirectResponse
    {
        Gate::authorize('procedimientos.editar');

        return $this->categoria($request, $categoria);
    }

    private function categoria(Request $request, ?int $id): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $guardada = $this->tenant->conEmpresa($empresaId, function () use ($request, $id) {
                $categoria = $id === null ? null : (ProcedimientoCategoria::find($id) ?? abort(404));

                return $this->autorizar(fn () => $this->procedimientos->guardarCategoria($request->user(), $categoria, $request->all()));
            });
        } catch (ValidationException $e) {
            return redirect()->route('procedimientos.index')->withErrors($e->errors())
                ->withInput($request->only(['nombre', 'color', 'orden']) + ['_dialogo' => 'categorias', '_categoria' => $id]);
        }

        return redirect()->route('procedimientos.index')->with('ok', "Categoría «{$guardada->nombre}» guardada.");
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Acción sobre la ficha: errores de validación dentro de su diálogo y
     * reglas del circuito como 403 con su explicación.
     *
     * @param  list<string>  $excluir  campos que no regresan al formulario
     */
    private function accion(Request $request, int $id, ?string $dialogo, callable $accion, callable $mensaje, array $excluir = ['_token']): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $p = $this->tenant->conEmpresa($empresaId, function () use ($request, $id, $accion) {
                $p = $this->buscarEnAlcance($request->user(), $id);
                $this->autorizar(fn () => $accion($p));

                return $p->refresh();
            });
        } catch (ValidationException $e) {
            $volver = redirect()->route('procedimientos.show', $id)->withErrors($e->errors());

            return $dialogo === null ? $volver : $volver->withInput($request->except($excluir) + ['_dialogo' => $dialogo]);
        }

        [$tipo, $texto] = $mensaje($p);

        return redirect()->route('procedimientos.show', $p->id)->with($tipo, $texto);
    }

    private function autorizar(callable $accion): mixed
    {
        try {
            return $accion();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    /**
     * Todo lo que pinta la ficha.
     *
     * @return array<string, mixed>
     */
    private function datosFicha(Request $request, User $actor, Procedimiento $p): array
    {
        $srv = $this->procedimientos;
        $p->load(['categoria', 'creador:id,name', 'editor:id,name', 'retiro:id,name']);
        $versiones = $this->versionesVisibles($actor, $p)->load(['autor:id,name', 'aprobador:id,name', 'envio:id,name', 'rechazador:id,name', 'categoria']);
        $vigente = $versiones->firstWhere('estado', ProcedimientoVersion::PUBLICADA);
        $trabajo = $versiones->first(fn ($v) => in_array($v->estado, [ProcedimientoVersion::BORRADOR, ProcedimientoVersion::EN_REVISION], true));
        $version = $this->versionVisible($request, $actor, $p, $versiones);
        $version->load(['pasos', 'adjuntos', 'aplicaciones.sede:id,nombre', 'aplicaciones.departamento:id,nombre', 'aplicaciones.puesto:id,nombre']);
        $trabajo?->load('aplicaciones', 'adjuntos', 'pasos');
        $vigente?->load('aplicaciones');

        $veAcuses = $srv->veAcuses($actor) && $vigente !== null;
        [$sedeAcuses, $deptoAcuses] = $this->filtrosAcuses($request);
        $editarBorrador = $srv->puedeEditar($actor, $p, $trabajo);
        $motivoNoAprueba = $trabajo?->estado === ProcedimientoVersion::EN_REVISION ? $srv->motivoNoAprueba($actor, $p, $trabajo) : null;
        $publicado = $p->estado === Procedimiento::PUBLICADO;
        $sedesVer = $srv->sedes($actor, $actor->can('procedimientos.editar') ? 'procedimientos.editar' : 'procedimientos.aprobar');

        return [
            'p' => $p,
            'version' => $version,
            'versiones' => $versiones,
            'vigente' => $vigente,
            'trabajo' => $trabajo,
            'eventos' => ProcedimientoEvento::where('procedimiento_id', $p->id)
                ->where(fn ($q) => $q->whereIn('version_id', $versiones->pluck('id'))->orWhereNull('version_id'))->orderBy('id')->get()->groupBy('version_id'),
            'aplicaA' => $this->aplicaA($version),
            'miAcuse' => $vigente ? $vigente->acuses()->where('user_id', $actor->id)->first() : null,
            'pendienteMio' => $publicado && $srv->pendientesDe($actor)->contains('id', $p->id),
            'acuses' => $veAcuses ? $srv->cumplimiento($actor, $vigente, $sedeAcuses, $deptoAcuses) : null,
            'acusesFiltros' => ['sede' => $sedeAcuses, 'departamento' => $deptoAcuses],
            'sedesAcuses' => $veAcuses ? Sede::orderBy('nombre')->get(['id', 'nombre'])->filter(fn ($s) => $sedesVer === null || in_array((int) $s->id, $sedesVer, true))->values() : collect(),
            'departamentosAcuses' => $veAcuses ? Departamento::orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'firmaGuardada' => $this->pases->firmaGuardada($actor) !== null,
            'puede' => [
                'editar' => $editarBorrador,
                'enviar' => $editarBorrador && $trabajo?->estado === ProcedimientoVersion::BORRADOR,
                'aprobar' => $trabajo?->estado === ProcedimientoVersion::EN_REVISION && $motivoNoAprueba === null,
                'porQueNo' => $actor->can('procedimientos.aprobar') ? $motivoNoAprueba : null,
                'nuevaVersion' => $publicado && $trabajo === null && $vigente !== null && $srv->cubre($actor, 'procedimientos.editar', $vigente, $p),
                'descartar' => $trabajo?->estado === ProcedimientoVersion::BORRADOR && $trabajo->numero > 1 && $srv->cubre($actor, 'procedimientos.editar', $trabajo, $p),
                'retirar' => $publicado && $vigente !== null && $srv->cubre($actor, 'procedimientos.eliminar', $vigente, $p),
                'reactivar' => $p->estado === Procedimiento::RETIRADO && $vigente !== null && $srv->cubre($actor, 'procedimientos.eliminar', $vigente, $p),
                'acuses' => $veAcuses,
                'borrar' => $actor->can('procedimientos.borrar') && $vigente === null && ! $versiones->contains(fn ($v) => $v->aprobado_en !== null),
            ],
            'formulario' => $editarBorrador ? $srv->opcionesFormulario($actor, $actor->can('procedimientos.editar') ? 'procedimientos.editar' : 'procedimientos.crear') : null,
        ];
    }

    /**
     * Versiones que el actor puede ver: quien solo consulta, solo la vigente;
     * los demás, todas (incluidas las reemplazadas y descartadas).
     *
     * @return Collection<int, ProcedimientoVersion>
     */
    private function versionesVisibles(User $actor, Procedimiento $p)
    {
        return ProcedimientoVersion::where('procedimiento_id', $p->id)
            ->when($this->procedimientos->soloLectura($actor), fn ($q) => $q->where('estado', ProcedimientoVersion::PUBLICADA))
            ->orderBy('numero')->get();
    }

    /**
     * Versión pedida con ?version=N (si la puede ver); si no, la vigente o la de trabajo.
     */
    private function versionVisible(Request $request, User $actor, Procedimiento $p, $versiones = null): ProcedimientoVersion
    {
        $versiones ??= $this->versionesVisibles($actor, $p);
        $pedida = (int) Entrada::texto($request->query('version'), '0');
        $version = ($pedida > 0 ? $versiones->firstWhere('numero', $pedida) : null)
            ?? $versiones->firstWhere('estado', ProcedimientoVersion::PUBLICADA)
            ?? $versiones->first(fn ($v) => in_array($v->estado, [ProcedimientoVersion::BORRADOR, ProcedimientoVersion::EN_REVISION], true))
            ?? $versiones->last();
        abort_if($version === null, 404);

        return $version->load(['pasos', 'adjuntos', 'aplicaciones.sede:id,nombre', 'aplicaciones.departamento:id,nombre', 'aplicaciones.puesto:id,nombre', 'categoria', 'autor:id,name', 'aprobador:id,name']);
    }

    /**
     * «A quién aplica» en palabras: «Todas las sedes · Seguridad, Recepción».
     *
     * @return array{sedes: string, personal: string}
     */
    private function aplicaA(ProcedimientoVersion $v): array
    {
        $v->loadMissing(['aplicaciones.sede:id,nombre', 'aplicaciones.departamento:id,nombre', 'aplicaciones.puesto:id,nombre']);
        $nombres = fn (string $tipo) => $v->aplicaciones->where('tipo', $tipo)->map(fn ($a) => $a->{$tipo}?->nombre)->filter()->sort()->values()->all();
        $personal = array_filter([
            $nombres('departamento') ? 'Departamentos: '.implode(', ', $nombres('departamento')) : null,
            $nombres('puesto') ? 'Puestos: '.implode(', ', $nombres('puesto')) : null,
        ]);

        return [
            'sedes' => $v->aplica_todas_sedes ? 'Todas las sedes' : (implode(', ', $nombres('sede')) ?: '—'),
            'personal' => $personal === [] ? 'Todo el personal' : implode(' · ', $personal),
        ];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function filtrosAcuses(Request $request): array
    {
        $sede = (int) Entrada::texto($request->query('sede'), '0');
        $depto = (int) Entrada::texto($request->query('departamento'), '0');

        return [$sede > 0 ? $sede : null, $depto > 0 ? $depto : null];
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /** Un procedimiento de otra empresa o fuera de su alcance responde 404. */
    private function buscarEnAlcance(User $actor, int $id): Procedimiento
    {
        $p = $this->procedimientos->limitar(Procedimiento::query(), $actor)->with(['vigente', 'categoria'])->find($id);
        abort_if($p === null, 404);

        return $p;
    }
}
