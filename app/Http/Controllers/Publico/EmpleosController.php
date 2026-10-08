<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\Vacante;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Vacantes\AdministradorVacantes;
use App\Services\Vacantes\BolsaTrabajo;
use App\Support\Entrada;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Bolsa de trabajo pública (SIN sesión, lección 36): /empleos/{empresa}.
 *
 * Seguridad: solo empresas con la bolsa encendida (si no, 404, igual que una
 * dirección inventada); solo vacantes publicadas y vigentes; formulario con
 * token CSRF, campo trampa para robots, límite de peticiones por equipo
 * (rutas), archivos validados por su contenido y guardados en el disco
 * privado; sin JavaScript en línea; «noindex» salvo que la empresa permita
 * que los buscadores la muestren. Nunca muestra datos de candidatos.
 */
class EmpleosController extends Controller
{
    /** Campo trampa: invisible para personas; si llega con algo, es un robot. */
    public const TRAMPA = 'sitio_web';

    public function __construct(
        private readonly BolsaTrabajo $bolsa,
        private readonly Tenant $tenant,
    ) {}

    public function index(Request $request, string $empresa): Response
    {
        $e = $this->empresaOFallar($empresa);
        $q = mb_substr(trim(Entrada::texto($request->query('q'))), 0, 80);
        $sede = (int) Entrada::texto($request->query('sede'), '0');
        $departamento = (int) Entrada::texto($request->query('departamento'), '0');

        return $this->tenant->conEmpresa($e->id, function () use ($e, $q, $sede, $departamento) {
            $hoy = AdministradorVacantes::hoy($e);
            $base = Vacante::vigentes($hoy);
            $vacantes = (clone $base)
                ->when($q !== '', fn ($c) => $c->where('vacantes.titulo', 'like', '%'.addcslashes($q, '%_\\').'%'))
                ->when($sede > 0, fn ($c) => $c->aplicanEn([$sede]))
                ->when($departamento > 0, fn ($c) => $c->where('vacantes.departamento_id', $departamento))
                ->with(['sedes:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
                ->orderByDesc('fecha_publicacion')->orderByDesc('id')->limit(100)->get();
            // Filtros solo con lo que tiene vacantes publicadas
            $publicadas = (clone $base)->with(['sedes:id,nombre', 'departamento:id,nombre'])->get(['id', 'todas_las_sedes', 'departamento_id']);
            $sedes = $publicadas->contains('todas_las_sedes', true)
                ? Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                : $publicadas->flatMap->sedes->unique('id')->sortBy('nombre')->values();

            return $this->pagina(view('empleos.index', [
                'empresa' => $e,
                'ajustes' => $this->bolsa->ajustes($e),
                'vacantes' => $vacantes,
                'sedes' => $sedes,
                'departamentos' => $publicadas->pluck('departamento')->filter()->unique('id')->sortBy('nombre')->values(),
                'filtros' => ['q' => $q, 'sede' => $sede, 'departamento' => $departamento],
            ]), $e);
        });
    }

    public function show(string $empresa, string $vacante): Response
    {
        $e = $this->empresaOFallar($empresa);

        return $this->tenant->conEmpresa($e->id, function () use ($e, $vacante) {
            $v = $this->bolsa->vacantePublica($e, $vacante);
            abort_if($v === null, 404);

            return $this->pagina(view('empleos.show', ['empresa' => $e, 'v' => $v, 'ajustes' => $this->bolsa->ajustes($e)]), $e);
        });
    }

    public function formulario(string $empresa, string $vacante): Response
    {
        $e = $this->empresaOFallar($empresa);

        return $this->tenant->conEmpresa($e->id, function () use ($e, $vacante) {
            $v = $this->bolsa->vacantePublica($e, $vacante);
            abort_if($v === null, 404);

            return $this->pagina(view('empleos.postular', [
                'empresa' => $e,
                'v' => $v,
                'sedes' => $v->todas_las_sedes ? Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']) : $v->sedes->values(),
                'privacidad' => app(AjustesRecepcion::class)->textoPrivacidad($e),
            ]), $e, conFormulario: true);
        });
    }

    public function postular(Request $request, string $empresa, string $vacante): RedirectResponse|Response
    {
        $e = $this->empresaOFallar($empresa);
        // Robot (llenó el campo trampa): se le contesta igual que a una persona, sin guardar nada
        if (trim(Entrada::texto($request->input(self::TRAMPA))) !== '') {
            return redirect()->route('empleos.gracias', $e->bolsa_slug)->with('postulacion_enviada', true);
        }
        $request->validate([
            'cv' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'cv.max' => 'Tu CV pesa más de 5 MB: tómale foto con menos calidad o reduce el PDF.',
            'cv.mimes' => 'Sube tu CV en PDF o como foto (JPG o PNG).',
            'cv.file' => 'No se pudo recibir el archivo.',
        ]);

        $this->tenant->conEmpresa($e->id, function () use ($request, $e, $vacante) {
            $v = $this->bolsa->vacantePublica($e, $vacante);
            abort_if($v === null, 404);
            $this->bolsa->postular($e, $v, $request->except(['_token', 'cv', self::TRAMPA, 'departamento_id', 'puesto_id', 'vacante', 'vacante_id']),
                $request->file('cv'), (string) $request->ip());
        });

        return redirect()->route('empleos.gracias', $e->bolsa_slug)->with('postulacion_enviada', true);
    }

    public function gracias(string $empresa): Response|RedirectResponse
    {
        $e = $this->empresaOFallar($empresa);
        if (session('postulacion_enviada') !== true) {
            return redirect()->route('empleos.index', $e->bolsa_slug);
        }

        return $this->pagina(view('empleos.gracias', ['empresa' => $e]), $e);
    }

    // ------------------------------------------------------------------ Ayudas

    private function empresaOFallar(string $slug): Empresa
    {
        $empresa = $this->bolsa->empresaPorSlug($slug);
        abort_if($empresa === null, 404);

        return $empresa;
    }

    private function pagina(View $vista, Empresa $empresa, bool $conFormulario = false): Response
    {
        $respuesta = response($vista)->header('Referrer-Policy', 'no-referrer');
        if (! $this->bolsa->ajustes($empresa)['indexar'] || $conFormulario) {
            $respuesta->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $conFormulario ? $respuesta->header('Cache-Control', 'no-store, private') : $respuesta;
    }
}
