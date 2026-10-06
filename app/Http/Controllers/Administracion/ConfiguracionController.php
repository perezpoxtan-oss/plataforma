<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Mail\CorreoDePrueba;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Respaldos\Respaldos;
use App\Support\CorreoPlataforma;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Configuración. Crece por secciones conforme se necesitan:
 *  - Plataforma (solo Super Administrador): Correo saliente y Respaldos.
 *  - Empresa (configuracion.editar): Avisos por correo.
 */
class ConfiguracionController extends Controller
{
    public function __construct(
        private readonly CorreoPlataforma $correo,
        private readonly Respaldos $respaldos,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly AdministradorRoles $auditoria,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('configuracion.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        $correo = $this->correo->datos();

        return view('administracion.configuracion.index', [
            'esSuperadmin' => $actor->es_superadmin,
            'correo' => array_merge($correo, ['contrasena' => null]),
            'tieneContrasena' => ! empty($correo['contrasena']),
            'correoListo' => $this->correo->configurado(),
            'cifrados' => CorreoPlataforma::CIFRADOS,
            'respaldos' => $actor->es_superadmin ? $this->respaldos->listar() : collect(),
            'empresa' => $empresaId === null ? null : Empresa::find($empresaId),
            'avisos' => Empresa::AVISOS,
            'puedeEditar' => $actor->can('configuracion.editar'),
        ]);
    }

    // --------------------------------------------------------- Correo (plataforma)

    public function correo(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $datos = $request->validate([
            // Seguridad (SSRF): solo servidores que no sean direcciones reservadas y solo puertos de correo
            'host' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9.-]+$/', function (string $campo, mixed $valor, \Closure $falla) {
                $problema = CorreoPlataforma::problemaDestino(Entrada::texto($valor), CorreoPlataforma::PUERTOS[0]);
                if ($problema !== null) {
                    $falla($problema);
                }
            }],
            'puerto' => ['required', 'integer', Rule::in(CorreoPlataforma::PUERTOS)],
            'cifrado' => ['required', Rule::in(array_keys(CorreoPlataforma::CIFRADOS))],
            'usuario' => ['nullable', 'string', 'max:150'],
            'contrasena' => ['nullable', 'string', 'max:200'],
            'remitente_correo' => ['required', 'email:rfc', 'max:150'],
            'remitente_nombre' => ['nullable', 'string', 'max:80'],
        ], [
            'host.regex' => 'Escribe solo el nombre del servidor (ej. mail.tudominio.com), sin https:// ni espacios.',
            'puerto.in' => 'Usa un puerto de correo: '.implode(', ', CorreoPlataforma::PUERTOS).'.',
        ], [
            'host' => 'servidor', 'puerto' => 'puerto', 'remitente_correo' => 'correo del remitente', 'remitente_nombre' => 'nombre del remitente',
        ]);

        $contrasena = $datos['contrasena'] ?? null;
        unset($datos['contrasena']);
        $this->correo->guardar($datos, $contrasena, $request->boolean('quitar_contrasena'));

        // En la bitácora nunca va la contraseña: solo si se cambió
        $this->auditar($request, 'configuracion.correo', ['host' => $datos['host'], 'puerto' => $datos['puerto'], 'usuario' => $datos['usuario'] ?? null,
            'remitente' => $datos['remitente_correo'], 'contrasena' => $contrasena ? 'cambiada' : ($request->boolean('quitar_contrasena') ? 'quitada' : 'sin cambio')]);

        return redirect()->route('configuracion.index')->with('ok', 'Configuración de correo guardada. Envía un correo de prueba para confirmar que funciona.');
    }

    public function probarCorreo(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $para = $request->validate(['para' => ['required', 'email:rfc', 'max:150']], [], ['para' => 'correo de destino'])['para'];

        if (! $this->correo->configurado()) {
            return back()->with('error', 'Primero guarda la configuración del correo.');
        }

        $enviado = $this->correo->enviar($para, new CorreoDePrueba($request->user()->name));
        $this->auditar($request, 'configuracion.correo_prueba', ['para' => $para, 'resultado' => $enviado ? 'enviado' : 'error']);

        return back()->with($enviado ? 'ok' : 'error', $enviado
            ? "Correo de prueba enviado a {$para}. Revisa la bandeja de entrada (y la de spam)."
            : 'No se pudo enviar: '.($this->correo->datos()['ultimo_error'] ?? 'revisa servidor, puerto, usuario y contraseña.'));
    }

    // -------------------------------------------------------- Avisos (empresa)

    public function avisos(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $empresa = Empresa::findOrFail($empresaId);
        $antes = $empresa->preferencias['avisos'] ?? [];
        $avisos = collect(Empresa::AVISOS)->mapWithKeys(fn ($v, $clave) => [$clave => $request->boolean("avisos.{$clave}")])->all();
        // Avisos con lista de correos (p. ej. vales de taxi): uno por línea o separados por coma
        $destinatarios = $this->destinatariosAvisos($request);
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['avisos' => $avisos, 'avisos_destinatarios' => $destinatarios])])->save();

        $this->auditoria->auditar($request->user(), 'configuracion.avisos', $empresa, ['avisos' => $antes], ['avisos' => $avisos, 'avisos_destinatarios' => $destinatarios]);

        return redirect()->route('configuracion.index')->with('ok', 'Avisos por correo actualizados.');
    }

    // ------------------------------------------------------ Respaldos (plataforma)

    public function respaldar(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);

        try {
            $r = $this->respaldos->crear('manual');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo crear el respaldo: '.$e->getMessage());
        }
        $this->auditar($request, 'configuracion.respaldo_creado', ['archivo' => $r['archivo'], 'bytes' => $r['bytes']]);

        return back()->with('ok', "Respaldo {$r['archivo']} creado.");
    }

    public function descargar(Request $request, string $archivo): BinaryFileResponse
    {
        $this->soloSuperadmin($request);
        $ruta = $this->respaldos->ruta($archivo);
        abort_if($ruta === null, 404);

        // Descargar la base completa queda en la bitácora
        $this->auditar($request, 'configuracion.respaldo_descargado', ['archivo' => $archivo]);

        return response()->download($ruta, $archivo, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * Listas de correos de Empresa::AVISOS_CON_DESTINATARIOS (máximo 20 por aviso).
     *
     * @return array<string, list<string>>
     */
    private function destinatariosAvisos(Request $request): array
    {
        $resultado = [];
        $errores = [];
        foreach (Empresa::AVISOS_CON_DESTINATARIOS as $clave) {
            $texto = $request->input("destinatarios.{$clave}");
            $correos = collect(preg_split('/[\s,;]+/', is_string($texto) ? mb_strtolower($texto) : '') ?: [])->filter()->unique()->values();
            $invalidos = $correos->reject(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($c) <= 150);
            if ($invalidos->isNotEmpty()) {
                $errores["destinatarios.{$clave}"] = 'Revisa estos correos: '.$invalidos->take(3)->implode(', ').'.';
            } elseif ($correos->count() > 20) {
                $errores["destinatarios.{$clave}"] = 'Captura máximo 20 correos por aviso.';
            }
            $resultado[$clave] = $correos->all();
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $resultado;
    }

    // -------------------------------------------------------------------------

    private function soloSuperadmin(Request $request): void
    {
        abort_unless($request->user()->es_superadmin, 403);
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(Request $request, string $evento, array $despues): void
    {
        $registro = ConfiguracionPlataforma::firstOrCreate(['clave' => CorreoPlataforma::CLAVE], ['valor' => []]);
        $this->auditoria->auditar($request->user(), $evento, $registro, null, $despues);
    }
}
