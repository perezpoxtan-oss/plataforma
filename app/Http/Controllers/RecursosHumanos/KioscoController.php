<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\Kiosco;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\Entrada;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Kiosco de auto-registro (SIN sesión): el candidato abre el enlace de su QR
 * (o escribe el código corto) en su celular y llena su CV. Lo que envía
 * llega a su ficha «Por revisar». Solo ve su propio formulario.
 *
 * Seguridad: enlace de un solo candidato, con vencimiento y usos limitados;
 * límite de peticiones por equipo (rutas); formulario con token CSRF; la
 * página no se indexa (X-Robots-Tag + meta) y no usa JavaScript en línea.
 */
class KioscoController extends Controller
{
    public function __construct(
        private readonly Kiosco $kiosco,
        private readonly Tenant $tenant,
    ) {}

    /** «Escribe tu código» (lo que abre el QR de la tableta, con el código ya escrito). */
    public function codigo(Request $request): Response
    {
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Entrada::texto($request->query('codigo'))) ?? '');

        return $this->sinIndexar(view('kiosco.codigo', ['codigo' => mb_substr($codigo, 0, 6)]));
    }

    public function canjear(Request $request): RedirectResponse
    {
        $enlace = $this->kiosco->porCodigo(Entrada::texto($request->input('codigo')));
        if ($enlace === null) {
            return redirect()->route('kiosco.codigo')->withInput()->withErrors(['codigo' => 'Ese código no existe o ya venció. Pide uno nuevo en recepción.']);
        }

        return redirect()->route('kiosco.mostrar', $this->kiosco->tokenParaCodigo($enlace));
    }

    public function mostrar(Request $request, string $token): Response
    {
        $enlace = $this->kiosco->porToken($token);
        if (session('kiosco_enviado') === true) {
            return $this->sinIndexar(view('kiosco.gracias', ['empresa' => $enlace ? Empresa::find($enlace->empresa_id)?->nombre_comercial : null]));
        }
        if ($enlace === null || ! $enlace->vigente()) {
            return $this->sinIndexar(view('kiosco.vencido', ['motivo' => $enlace === null ? 'no_existe' : ($enlace->usos >= $enlace->usos_maximos ? 'usado' : 'vencido')]), 404);
        }

        return $this->tenant->conEmpresa((int) $enlace->empresa_id, function () use ($enlace, $token) {
            $empresa = Empresa::findOrFail($enlace->empresa_id);
            $candidato = Candidato::with('puesto:id,nombre')->findOrFail($enlace->candidato_id);

            return $this->sinIndexar(view('kiosco.formulario', [
                'c' => $candidato,
                'token' => $token,
                'enlace' => $enlace,
                'empresa' => $empresa->nombre_comercial,
                'privacidad' => app(AjustesRecepcion::class)->textoPrivacidad($empresa),
                'puestos' => Puesto::where('activo', true)->orderBy('nombre')->pluck('nombre'),
            ]));
        });
    }

    public function guardar(Request $request, string $token): RedirectResponse|Response
    {
        $enlace = $this->kiosco->porToken($token);
        if ($enlace === null || ! $enlace->vigente()) {
            return $this->sinIndexar(view('kiosco.vencido', ['motivo' => $enlace === null ? 'no_existe' : 'vencido']), 404);
        }
        $request->validate([
            'cv' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'ine' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
            'comprobante' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ], [
            '*.max' => 'El archivo pesa más de 5 MB: toma la foto con menos calidad.',
            '*.mimes' => 'Sube un PDF o una foto (JPG o PNG).',
            '*.file' => 'No se pudo recibir el archivo.',
        ]);

        try {
            $this->tenant->conEmpresa((int) $enlace->empresa_id, fn () => $this->kiosco->guardar($enlace,
                $request->except(['_token', 'cv', 'ine', 'comprobante', 'departamento_id', 'puesto_id']),
                ['cv' => $request->file('cv'), 'ine' => $request->file('ine'), 'comprobante' => $request->file('comprobante')],
                (string) $request->ip()));
        } catch (CambioNoPermitido $e) {
            return $this->sinIndexar(view('kiosco.vencido', ['motivo' => 'usado']), 404);
        }

        return redirect()->route('kiosco.mostrar', $token)->with('kiosco_enviado', true);
    }

    private function sinIndexar(View $vista, int $estado = 200): Response
    {
        return response($vista, $estado)->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
