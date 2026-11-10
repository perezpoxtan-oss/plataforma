<?php

namespace App\Services\Cuenta;

use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mi cuenta: lo que cada persona hace con su propia cuenta sin permiso
 * especial ni ayuda de nadie (criterio 6 de docs/criterios-ux.md). Aquí no
 * se cambian rol, sedes ni correo: eso lo administra Usuarios.
 */
class CuentaPropia
{
    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly AvisosCuenta $avisos,
    ) {}

    /**
     * Cambia la contraseña propia: exige la actual, guarda la nueva, quita la
     * marca de «debe cambiar», cierra las demás sesiones, audita (sin datos
     * sensibles) y avisa por campana.
     */
    public function cambiarContrasena(User $usuario, string $actual, string $nueva, ?string $sesionActual, ?string $ip): void
    {
        if (! Hash::check($actual, $usuario->password)) {
            throw ValidationException::withMessages(['contrasena_actual' => 'La contraseña actual no es correcta.']);
        }
        if (Hash::check($nueva, $usuario->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña nueva debe ser distinta de la actual.']);
        }

        $primera = (bool) $usuario->debe_cambiar_contrasena;
        $usuario->forceFill([
            'password' => $nueva,
            'debe_cambiar_contrasena' => false,
            'actualizado_por' => $usuario->id,
        ])->save();

        AvisosCuenta::cerrarSesiones($usuario, $sesionActual);
        $this->auditoria->auditar($usuario, 'usuarios.contrasena_cambiada', $usuario, null, [
            'contrasena' => 'cambiada', 'primer_acceso' => $primera, 'origen' => AvisosCuenta::ipRecortada($ip),
        ]);
        $this->avisos->contrasenaCambiada($usuario, $primera
            ? 'La creaste en tu primer acceso; ya nadie más la conoce.'
            : 'La cambiaste desde Mi cuenta; tus otras sesiones se cerraron.');
    }
}
