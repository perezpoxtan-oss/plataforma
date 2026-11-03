<?php

namespace App\Http\Controllers\Publico\EquiposAcceso;

use App\Models\EquipoAcceso;
use App\Services\EquiposAcceso\ConectorHikvision;
use App\Services\EquiposAcceso\RegistroMarcajes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Notificación de eventos ISAPI de Hikvision («Escucha HTTP»): el equipo
 * empuja cada evento. Se identifica con el token del equipo en la ruta
 * (/equipos-acceso/hikvision/{token}) o en el encabezado X-Equipo-Token.
 * Ver docs/tecnico/equipos-acceso.md.
 */
class HikvisionController extends LlamadaDeEquipo
{
    /** Con foto del evento (que se descarta) el cuerpo puede pesar más. */
    protected const MAX_BYTES = 1048576;

    public function __construct(
        private readonly ConectorHikvision $conector,
        private readonly RegistroMarcajes $marcajes,
    ) {}

    public function __invoke(Request $request, ?string $token = null): Response
    {
        if ($this->demasiadoGrande($request)) {
            return $this->texto('ERROR', 413);
        }
        $token ??= (string) $request->header('X-Equipo-Token');
        if (! preg_match('/^[A-Za-z0-9]{32,64}$/', $token)) {
            return $this->rechazo($request, 'Token con formato inválido');
        }
        $equipo = EquipoAcceso::withoutGlobalScopes()->where('marca', 'hikvision')->where('token_hash', EquipoAcceso::hashToken($token))->where('activo', true)->first();
        if ($equipo === null) {
            return $this->rechazo($request, 'Token no registrado');
        }
        if (! $this->ipValida($equipo, $request)) {
            return $this->rechazo($request, 'IP no permitida', 403);
        }
        if ($this->conector->eventos($request) === []) {
            return $this->texto('ERROR', 400); // ni JSON ni un evento: no se toca nada
        }
        $this->anotarContacto($equipo, $request);
        $cuenta = $this->enSuEmpresa($equipo, fn () => $this->marcajes->procesar($equipo, $this->conector->marcajes($request, $equipo->loadMissing('sede'))));

        return $this->texto('OK: '.array_sum($cuenta));
    }
}
