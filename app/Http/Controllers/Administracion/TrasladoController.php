<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\Respaldos\Respaldos;
use App\Services\Traslado\Paquetes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Respaldos y traslado a otro servidor (Configuración, solo Super
 * Administrador) y la exportación de una empresa (Empresas: Super
 * Administrador, o quien tiene «empresas.exportar» con alcance de empresa
 * para la suya). Ver docs/tecnico/traslado.md.
 *
 * Seguridad: los paquetes llevan datos personales. Viven fuera de la carpeta
 * pública, se descargan solo con un enlace temporal firmado (y con sesión del
 * usuario autorizado) y todo queda en la Bitácora de auditoría.
 */
class TrasladoController extends Controller
{
    /** Minutos que vale un enlace de descarga. */
    public const MINUTOS_ENLACE = 30;

    public function __construct(
        private readonly Paquetes $paquetes,
        private readonly Respaldos $respaldos,
        private readonly Autorizador $autorizador,
    ) {}

    // -------------------------------------------- Respaldos y traslado (plataforma)

    public function index(Request $request): View
    {
        $this->soloSuperadmin($request);
        $trabajos = $this->paquetes->trabajos();

        return view('administracion.traslado.index', [
            'paquetes' => $this->conEnlaces($this->paquetes->listar()),
            'activo' => $trabajos->first(fn ($t) => $t['tipo'] === 'traslado' && $t['estado'] === 'en_proceso'),
            'fallido' => $trabajos->first(fn ($t) => $t['tipo'] === 'traslado' && $t['estado'] !== 'en_proceso'),
            'verificaciones' => $trabajos->where('tipo', 'verificacion')->take(5)->values(),
            'ajustes' => $this->paquetes->ajustes(),
            'respaldos' => $this->respaldos->listar(),
            'exportaciones' => Empresa::query()->orderBy('nombre_comercial')->get(['id', 'nombre_comercial'])
                ->map(fn ($e) => ['empresa' => $e, 'ultima' => $this->paquetes->listar($e->id)->first()])
                ->filter(fn ($x) => $x['ultima'] !== null)->values(),
            'tamanoArchivos' => $this->paquetes->tamanoArchivos(),
            'limiteSubida' => self::limiteSubida(),
        ]);
    }

    public function generar(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        try {
            $t = $this->paquetes->iniciarTraslado($request->user());
            $t = $this->paquetes->avanzar($t['id']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo generar el paquete: '.$e->getMessage());
        }

        return $this->despuesDeAvanzar($t, route('traslado.index'));
    }

    public function ajustes(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $semanal = $request->boolean('semanal');
        $registro = $this->paquetes->registroAjustes();
        $antes = $this->paquetes->ajustes();
        $registro->forceFill(['valor' => ['semanal' => $semanal]])->save();
        $this->paquetes->auditar($request->user()->id, 'configuracion.traslado_ajustes', $registro, ['antes' => $antes, 'semanal' => $semanal]);

        return redirect()->to(route('traslado.index').'#t-semanal')->with('ok', $semanal
            ? 'Listo: cada semana se generará un paquete de traslado automático (si hay espacio en el servidor). Recuerda descargarlo y guardarlo fuera del servidor.'
            : 'Listo: ya no se generará el paquete automático semanal.');
    }

    /**
     * Revisa un paquete sin restaurarlo: uno de la lista o uno que se sube.
     */
    public function verificar(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $request->validate([
            'archivo' => ['nullable', 'string', 'max:120'],
            'paquete' => ['nullable', 'file', 'max:'.max(1, (int) floor(self::limiteSubida() / 1024))],
        ], ['paquete.max' => 'El paquete pesa más de lo que el servidor deja subir. Revísalo desde la consola con la orden de verificación (ver el manual).',
            'paquete.uploaded' => 'El paquete pesa más de lo que el servidor deja subir. Revísalo desde la consola con la orden de verificación (ver el manual).'],
            ['paquete' => 'paquete']);

        if ($request->filled('archivo')) {
            $archivo = (string) $request->input('archivo');
            $ruta = $this->paquetes->ruta($archivo);
            abort_if($ruta === null, 404);
            $t = $this->paquetes->iniciarVerificacion($request->user(), $ruta, $archivo, false);
        } elseif ($request->hasFile('paquete') && $request->file('paquete')->isValid()) {
            $subido = $request->file('paquete');
            $nombre = mb_substr(preg_replace('/[^\w.\- ]/u', '_', (string) $subido->getClientOriginalName()) ?: 'paquete.zip', 0, 120);
            $destino = $this->paquetes->carpeta('verificar').'/'.bin2hex(random_bytes(8)).'.zip';
            $subido->move(dirname($destino), basename($destino));
            @chmod($destino, 0600);
            $t = $this->paquetes->iniciarVerificacion($request->user(), $destino, $nombre, true);
        } else {
            return back()->with('error', 'Elige un paquete de la lista o sube uno (.zip).');
        }

        $t = $this->paquetes->avanzar($t['id']);

        return $this->despuesDeAvanzar($t, route('traslado.index').'#t-verificar');
    }

    // ----------------------------------------------------- comunes a los dos

    /**
     * Avanza un trabajo unos segundos (la página lo pide sola mientras está abierta).
     */
    public function continuar(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $t = $this->paquetes->trabajo($id);
        abort_if($t === null, 404);
        $this->autorizarTrabajo($request->user(), $t);

        try {
            $r = $this->paquetes->avanzar($id);
        } catch (Throwable $e) {
            report($e);
            abort(500, 'No se pudo avanzar el paquete.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'estado' => $r['estado'], 'avance' => $r['avance'], 'paso' => $r['paso'], 'tamano' => Paquetes::legible($r['bytes']),
                'aviso' => $r['aviso'], 'error' => $r['error'],
            ]);
        }

        return $this->despuesDeAvanzar($r, $t['tipo'] === 'empresa' ? route('empresas.exportaciones', $t['empresa_id']) : route('traslado.index'));
    }

    public function descargar(Request $request, string $archivo): BinaryFileResponse
    {
        $ruta = $this->paquetes->ruta($archivo);
        abort_if($ruta === null, 404);
        $empresa = $this->autorizarArchivo($request->user(), $archivo);
        // Enlace temporal firmado (se revisa después de la empresa: lo ajeno sigue respondiendo 404)
        abort_unless($request->hasValidSignature(), 403, 'El enlace de descarga venció: recarga la página para obtener uno nuevo.');

        if ($empresa !== null) {
            $this->paquetes->auditar($request->user()->id, 'empresas.exportacion_descargada', $empresa, ['paquete' => $archivo, 'bytes' => (int) filesize($ruta)]);
        } else {
            $this->paquetes->auditar($request->user()->id, 'configuracion.traslado_descargado', $this->paquetes->registroAjustes(), ['paquete' => $archivo, 'bytes' => (int) filesize($ruta)]);
        }

        // Seguridad: BinaryFileResponse se marca «public» por defecto
        return response()->download($ruta, $archivo, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'])->setPrivate();
    }

    public function borrar(Request $request, string $archivo): RedirectResponse
    {
        abort_if($this->paquetes->ruta($archivo) === null, 404);
        $empresa = $this->autorizarArchivo($request->user(), $archivo);
        $this->paquetes->borrar($archivo);

        if ($empresa !== null) {
            $this->paquetes->auditar($request->user()->id, 'empresas.exportacion_borrada', $empresa, ['paquete' => $archivo]);

            return redirect()->route('empresas.exportaciones', $empresa->id)->with('ok', 'Exportación borrada del servidor.');
        }
        $this->paquetes->auditar($request->user()->id, 'configuracion.traslado_borrado', $this->paquetes->registroAjustes(), ['paquete' => $archivo]);

        return redirect()->route('traslado.index')->with('ok', 'Paquete borrado del servidor.');
    }

    // ------------------------------------------------- Exportar una empresa

    public function exportaciones(Request $request, Empresa $empresa): View
    {
        $this->autorizarEmpresa($request->user(), $empresa);
        $trabajos = $this->paquetes->trabajos('empresa', $empresa->id);

        return view('administracion.traslado.empresa', [
            'empresa' => $empresa,
            'paquetes' => $this->conEnlaces($this->paquetes->listar($empresa->id)),
            'activo' => $trabajos->first(fn ($t) => $t['estado'] === 'en_proceso'),
            'fallido' => $trabajos->first(fn ($t) => $t['estado'] !== 'en_proceso'),
            'esSuperadmin' => $request->user()->es_superadmin,
        ]);
    }

    public function exportar(Request $request, Empresa $empresa): RedirectResponse
    {
        $this->autorizarEmpresa($request->user(), $empresa);
        try {
            $t = $this->paquetes->iniciarEmpresa($request->user(), $empresa);
            $t = $this->paquetes->avanzar($t['id']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo exportar la empresa: '.$e->getMessage());
        }

        return $this->despuesDeAvanzar($t, route('empresas.exportaciones', $empresa->id));
    }

    /**
     * ¿Puede exportar esa empresa? Super Administrador, o quien tiene
     * «empresas.exportar» con alcance de empresa y es de esa empresa.
     */
    public function puedeExportar(User $usuario, Empresa $empresa): bool
    {
        if ($usuario->es_superadmin) {
            return true;
        }

        return (int) $usuario->empresa_id === $empresa->id
            && $usuario->can('empresas.exportar')
            && $this->autorizador->alcanceDeEmpresa($usuario, 'empresas.exportar');
    }

    // ---------------------------------------------------------------- apoyo

    /**
     * @param  Collection<int, array<string, mixed>>  $paquetes
     * @return Collection<int, array<string, mixed>>
     */
    private function conEnlaces($paquetes)
    {
        return $paquetes->map(fn ($p) => $p + [
            'enlace' => URL::temporarySignedRoute('traslado.descargar', now()->addMinutes(self::MINUTOS_ENLACE), ['archivo' => $p['archivo']]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function despuesDeAvanzar(array $t, string $volver): RedirectResponse
    {
        $destino = redirect()->to($volver);
        // Criterio 5: el aviso de «listo» trae el siguiente paso (descargar el paquete recién hecho)
        if ($t['estado'] === 'listo' && $t['tipo'] !== 'verificacion' && ! empty($t['paquete'])) {
            $destino->with('siguientes', [['Descargar ahora', URL::temporarySignedRoute('traslado.descargar', now()->addMinutes(self::MINUTOS_ENLACE), ['archivo' => $t['paquete']]), 'bi-download']]);
        }

        return match ($t['estado']) {
            'error' => $destino->with('error', 'No se pudo terminar: '.$t['error']),
            'listo' => $destino->with('ok', match ($t['tipo']) {
                'verificacion' => $t['resultado']['valido']
                    ? "Paquete «{$t['nombre']}» revisado: está completo y ningún archivo cambió. Puedes usarlo para restaurar."
                    : "El paquete «{$t['nombre']}» tiene problemas; revisa el detalle abajo.",
                'empresa' => 'Exportación lista ('.Paquetes::legible($t['bytes']).'). Descárgala abajo y guárdala en un lugar seguro.',
                default => 'Paquete de traslado listo ('.Paquetes::legible($t['bytes']).'). Descárgalo abajo y guárdalo fuera del servidor.',
            }),
            default => $destino->with('ok', 'Se está preparando; puedes dejar esta página abierta mientras avanza.'),
        };
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function autorizarTrabajo(User $usuario, array $t): void
    {
        if ($t['tipo'] === 'empresa') {
            $empresa = Empresa::find($t['empresa_id']);
            abort_if($empresa === null, 404);
            $this->autorizarEmpresa($usuario, $empresa);

            return;
        }
        abort_unless($usuario->es_superadmin, 403);
    }

    /**
     * Paquete de traslado: solo Super Administrador. Exportación de empresa:
     * quien puede exportar esa empresa. Devuelve la empresa si es exportación.
     */
    private function autorizarArchivo(User $usuario, string $archivo): ?Empresa
    {
        if (preg_match('/^empresa_(\d+)_/', $archivo, $m)) {
            $empresa = Empresa::find((int) $m[1]);
            // Seguridad (AZ-03): la de otra empresa responde 404 sin revelar que existe
            abort_if($empresa === null || (! $usuario->es_superadmin && (int) $usuario->empresa_id !== $empresa->id), 404);
            abort_unless($this->puedeExportar($usuario, $empresa), 403);

            return $empresa;
        }
        abort_unless($usuario->es_superadmin, 403);

        return null;
    }

    private function autorizarEmpresa(User $usuario, Empresa $empresa): void
    {
        abort_unless($usuario->es_superadmin || $empresa->id === (int) $usuario->empresa_id, 404);
        abort_unless($this->puedeExportar($usuario, $empresa), 403, 'Exportar la empresa requiere el permiso «exportar» de Empresas con alcance de empresa.');
    }

    private function soloSuperadmin(Request $request): void
    {
        abort_unless($request->user()->es_superadmin, 403);
    }

    /**
     * Lo más grande que el servidor deja subir (bytes).
     */
    public static function limiteSubida(): int
    {
        $a = fn (string $v) => (int) $v * match (strtolower(substr(trim($v), -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
        };
        $limites = array_filter([$a((string) ini_get('upload_max_filesize')), $a((string) ini_get('post_max_size'))]);

        return $limites === [] ? 2097152 : min($limites);
    }
}
