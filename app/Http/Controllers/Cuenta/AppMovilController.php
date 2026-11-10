<?php

namespace App\Http\Controllers\Cuenta;

use App\Http\Controllers\Controller;
use App\Services\AppMovil\ApkInstalada;
use App\Support\AppMovil;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Mi cuenta → App para tu celular: descargar la app de Android que trae la
 * versión instalada de la plataforma. Sin permiso propio (como Mi cuenta): la app
 * es de la plataforma y no lleva datos de ninguna empresa.
 *  - Pantalla y «Descargar app»: con sesión (límite por cuenta).
 *  - Código QR: enlace firmado que caduca (app_movil.minutos_enlace) para bajarla en un
 *    celular sin sesión; la ruta va sin el grupo «web» (sin sesión ni cookies), límite por equipo.
 * Ver docs/tecnico/app-movil.md, «Descarga desde la plataforma».
 */
class AppMovilController extends Controller
{
    public function __construct(private readonly ApkInstalada $apks) {}

    public function mostrar(Request $request): View
    {
        $apk = $this->apks->actual();
        $minutos = (int) config('app_movil.minutos_enlace', 30);
        $enlace = $apk ? URL::temporarySignedRoute('app-movil.enlace', now()->addMinutes($minutos)) : null;

        return view('cuenta.app-movil', [
            'apk' => $apk,
            'enlace' => $enlace,
            'qr' => $enlace ? $this->qr($enlace) : null,
            'minutos' => $minutos,
            'enApp' => AppMovil::es($request),
            'historial' => $request->user()->es_superadmin ? $this->apks->historial() : null,
        ]);
    }

    public function descargar(): BinaryFileResponse
    {
        $apk = $this->apks->actual();
        abort_if($apk === null, 404);

        return $this->archivo($apk);
    }

    /** Enlace del código QR: sin sesión, solo con firma vigente. */
    public function enlace(Request $request): BinaryFileResponse|Response
    {
        if (! $request->hasValidSignature()) {
            return response()->view('errors.plantilla', [
                'codigo' => 403, 'icono' => 'bi-clock-history', 'color' => '#d97706',
                'titulo' => 'Este enlace ya no sirve',
                'mensaje' => 'El enlace para descargar la app dura '.(int) config('app_movil.minutos_enlace', 30).' minutos. En la computadora abre otra vez Mi cuenta → App para tu celular y escanea el código nuevo.',
                'accion' => 'inicio',
            ], 403)->header('Cache-Control', 'no-store');
        }
        $apk = $this->apks->actual();
        if ($apk === null) {
            return response()->view('errors.plantilla', [
                'codigo' => 404, 'icono' => 'bi-phone', 'color' => '#2563eb',
                'titulo' => 'La app todavía no está disponible',
                'mensaje' => 'La app todavía no está disponible en esta versión de la plataforma. Vuelve a intentarlo más tarde.',
                'accion' => 'inicio',
            ], 404)->header('Cache-Control', 'no-store');
        }

        return $this->archivo($apk);
    }

    /** @param array<string, mixed> $apk */
    private function archivo(array $apk): BinaryFileResponse
    {
        // Seguridad: BinaryFileResponse se marca «public» por defecto
        return response()->download($apk['ruta'], $this->apks->nombreDescarga($apk), [
            'Content-Type' => ApkInstalada::TIPO,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            // Huella del archivo (RFC 9530) para quien quiera comprobarlo; también se ve en la pantalla
            'Repr-Digest' => 'sha-256=:'.base64_encode((string) hex2bin($apk['sha256'])).':',
        ])->setPrivate();
    }

    private function qr(string $texto): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($texto);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }
}
