<?php

namespace App\Http\Controllers\Cuenta;

use App\Http\Controllers\Controller;
use App\Models\Delegacion;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Rules\ContrasenaSegura;
use App\Services\Autorizaciones\Delegaciones;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Cuenta\CuentaPropia;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\Notificaciones\PreferenciasAvisos;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Mi cuenta: datos de la propia cuenta y «Cambiar mi contraseña». Sin permiso
 * propio: cualquiera que inició sesión. Rol, sedes y correo solo se consultan
 * (los administra Usuarios).
 */
class MiCuentaController extends Controller
{
    public function __construct(
        private readonly CuentaPropia $cuenta,
        private readonly Tenant $tenant,
        private readonly PreferenciasAvisos $preferencias,
    ) {}

    public function index(Request $request): View
    {
        $usuario = $request->user()->fresh() ?? $request->user();
        $asignacion = UsuarioRol::where('user_id', $usuario->id)->first(['rol_id', 'sede_id']);
        // Varias sedes por cuenta (ADR-0016): se listan todas
        $sedesIds = UsuarioRol::where('user_id', $usuario->id)->whereNotNull('sede_id')->distinct()->pluck('sede_id');
        $todas = UsuarioRol::where('user_id', $usuario->id)->whereNull('sede_id')->exists();
        $sede = $todas || $sedesIds->isEmpty() ? null
            : $this->tenant->conEmpresa((int) $usuario->empresa_id, fn () => Sede::whereIn('id', $sedesIds)->orderBy('nombre')->pluck('nombre')->join(', '));
        $sedes = $usuario->empresa_id === null ? 0
            : $this->tenant->conEmpresa((int) $usuario->empresa_id, fn () => Sede::where('activo', true)->count());

        return view('cuenta.mi-cuenta', [
            'cuenta' => $usuario,
            'rolNombreCuenta' => $usuario->nombreRolPrincipal(),
            'sedeNombre' => $sede,
            'todasLasSedes' => $asignacion !== null && $todas,
            'sedesEmpresa' => $sedes,
            'empresaNombre' => $usuario->empresa?->nombre_comercial,
            'debeCambiar' => (bool) $usuario->debe_cambiar_contrasena,
            // Mejoras estructura (ADR-0016): «Mis avisos»
            'opcionesAvisos' => $usuario->empresa_id === null ? ['campana' => [], 'correo' => []]
                : $this->tenant->conEmpresa((int) $usuario->empresa_id, fn () => $this->preferencias->opciones($usuario)),
            'silenciados' => PreferenciasAvisos::silenciados($usuario),
            // C-12: «Delegar mis aprobaciones» (antes «No molestar» en Autorizaciones)
            'delegar' => $this->datosDelegar($usuario),
            // Verificación en dos pasos (docs/tecnico/dos-pasos.md)
            'dosPasos' => [
                'activa' => $usuario->tieneDosPasos(),
                'desde' => $usuario->two_factor_confirmado_en,
                'restantes' => app(VerificacionDosPasos::class)->codigosRestantes($usuario),
                'exigida' => app(VerificacionDosPasos::class)->exigida($usuario),
            ],
        ]);
    }

    /**
     * «Mis avisos» (ADR-0016): cada persona silencia los avisos que no quiere
     * recibir en la campana o por correo. Lo que espera su respuesta sigue en
     * «Mis pendientes».
     */
    public function avisos(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_if($usuario->empresa_id === null, 404);
        $request->validate(['campana' => ['nullable', 'array'], 'correo' => ['nullable', 'array']]);

        $nuevos = $this->tenant->conEmpresa((int) $usuario->empresa_id, fn () => $this->preferencias->guardar($usuario, $request->only(['campana', 'correo'])));
        $total = count($nuevos['campana']) + count($nuevos['correo']);

        return redirect()->to(route('mi-cuenta.index').'#mis-avisos')->with('ok', $total === 0
            ? 'Listo: recibes todos los avisos. Lo que espera tu respuesta también está siempre en «Mis pendientes».'
            : "Listo: silenciaste {$total} aviso(s). Lo que espera tu respuesta sigue apareciendo en «Mis pendientes».");
    }

    /**
     * C-12 · «Delegar mis aprobaciones»: a quién y hasta cuándo. Mientras
     * esté activa, lo que me toca aprobar (visitas, pases, reservas,
     * entrevistas) le llega a mi delegado por campana, Mis pendientes y correo.
     */
    public function delegar(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_if($usuario->empresa_id === null, 404);
        $empresaId = (int) $usuario->empresa_id;
        $delegaciones = app(Delegaciones::class);
        abort_unless($this->tenant->conEmpresa($empresaId, fn () => $delegaciones->puedeDelegar($usuario)), 403);
        $entrada = $request->only(['delegado_id', 'hasta', 'motivo']);
        // «Desde» viene de Avanzado; si no, desde ahora
        $entrada['desde'] = $request->filled('desde') ? $request->input('desde') : app(HoraLocal::class)->formatear(now(), 'Y-m-d\TH:i');

        $d = $this->tenant->conEmpresa($empresaId, fn () => $delegaciones->crear($usuario, $empresaId, $entrada, false));

        return redirect()->to(route('mi-cuenta.index').'#delegar')->with('ok', "Listo: mientras esté activa, lo que te toque aprobar le llega a {$d->delegado?->name}. Tú puedes cancelarla aquí cuando regreses.");
    }

    public function cancelarDelegacion(Request $request, int $delegacion): RedirectResponse
    {
        $usuario = $request->user();
        abort_if($usuario->empresa_id === null, 404);

        try {
            $this->tenant->conEmpresa((int) $usuario->empresa_id, function () use ($usuario, $delegacion) {
                $d = Delegacion::with('usuario:id,name', 'delegado:id,name')->where('user_id', $usuario->id)->find($delegacion);
                abort_if($d === null, 404);
                app(Delegaciones::class)->cancelar($usuario, $d);
            });
        } catch (CambioNoPermitido $e) {
            return redirect()->to(route('mi-cuenta.index').'#delegar')->with('error', $e->getMessage());
        }

        return redirect()->to(route('mi-cuenta.index').'#delegar')->with('ok', 'Delegación cancelada: lo que te toque aprobar vuelve a llegarte a ti.');
    }

    /** @return array<string, mixed>|null */
    private function datosDelegar(User $usuario): ?array
    {
        if ($usuario->empresa_id === null) {
            return null;
        }

        return $this->tenant->conEmpresa((int) $usuario->empresa_id, function () use ($usuario) {
            $delegaciones = app(Delegaciones::class);
            $que = $delegaciones->queAprueba($usuario);
            if ($que === []) {
                return null;
            }

            return [
                'que' => $que,
                'candidatos' => $delegaciones->candidatosADelegado((int) $usuario->empresa_id)->reject(fn ($u) => $u->id === $usuario->id)->values(),
                'vigentes' => Delegacion::with('delegado:id,name')->where('user_id', $usuario->id)->whereNull('cancelada_en')
                    ->where('hasta', '>=', now())->orderBy('desde')->get(),
            ];
        });
    }

    public function contrasena(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $datos = $request->validate([
            'contrasena_actual' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(),
                new ContrasenaSegura((string) $usuario->username, (string) $usuario->email)],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
        ], [
            'contrasena_actual' => 'contraseña actual',
            'password' => 'contraseña nueva',
        ]);

        $this->cuenta->cambiarContrasena($usuario, $datos['contrasena_actual'], $datos['password'], $request->session()->getId(), $request->ip());

        return redirect()->route('mi-cuenta.index')->with('ok', 'Tu contraseña cambió y cerramos tus otras sesiones. Ya puedes seguir trabajando: toca «Inicio» para volver al panel.');
    }
}
