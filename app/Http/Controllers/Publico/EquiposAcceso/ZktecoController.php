<?php

namespace App\Http\Controllers\Publico\EquiposAcceso;

use App\Models\EquipoAcceso;
use App\Services\EquiposAcceso\ConectorZkteco;
use App\Services\EquiposAcceso\RegistroMarcajes;
use App\Services\EquiposAcceso\SincronizacionUsuarios;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Protocolo PUSH de ZKTeco («iclock»). Rutas fijas del protocolo, sin sesión:
 * el equipo se identifica por su número de serie (SN) registrado en
 * Configuración → Equipos de acceso y, si se configuró, por su IP.
 * Ver docs/tecnico/equipos-acceso.md.
 */
class ZktecoController extends LlamadaDeEquipo
{
    public function __construct(
        private readonly ConectorZkteco $conector,
        private readonly RegistroMarcajes $marcajes,
        private readonly SincronizacionUsuarios $sincronizacion,
    ) {}

    /** GET: saludo con las opciones · POST: datos (marcajes y otras tablas). */
    public function cdata(Request $request): Response
    {
        [$equipo, $error] = $this->equipo($request);
        if ($error !== null) {
            return $error;
        }
        if ($request->isMethod('GET')) {
            $this->anotarContacto($equipo, $request, array_filter(['version_equipo' => $this->version($request)]));

            return $this->texto($this->conector->opciones($equipo));
        }
        $this->anotarContacto($equipo, $request);
        $tabla = strtoupper((string) $request->query('table'));
        if (! preg_match('/^[A-Z_]{2,20}$/', $tabla)) {
            return $this->texto('ERROR', 400);
        }
        $cuenta = $this->enSuEmpresa($equipo, function () use ($equipo, $request, $tabla) {
            $leidos = $this->conector->marcajes($request, $equipo->loadMissing('sede'));
            $cuenta = $this->marcajes->procesar($equipo, $leidos);
            $estampa = (string) $request->query('Stamp');
            if ($tabla === 'ATTLOG' && preg_match('/^\d{1,20}$/', $estampa)) {
                EquipoAcceso::whereKey($equipo->id)->update(['estampa_marcajes' => $estampa]);
            }

            return array_sum($cuenta);
        });

        return $this->texto('OK: '.$cuenta);
    }

    /** «¿Hay algo para mí?»: los comandos en cola o «OK». */
    public function getrequest(Request $request): Response
    {
        [$equipo, $error] = $this->equipo($request);
        if ($error !== null) {
            return $error;
        }
        $this->anotarContacto($equipo, $request, array_filter(['version_equipo' => $this->version($request)]));
        $renglones = $this->enSuEmpresa($equipo, fn () => $this->sincronizacion->entregar($equipo));

        return $this->texto($renglones === [] ? 'OK' : implode("\n", $renglones)."\n");
    }

    /** Resultado de los comandos: «ID=<id>&Return=<n>&CMD=…». */
    public function devicecmd(Request $request): Response
    {
        [$equipo, $error] = $this->equipo($request);
        if ($error !== null) {
            return $error;
        }
        $this->anotarContacto($equipo, $request);
        $this->enSuEmpresa($equipo, fn () => $this->sincronizacion->confirmar($equipo, $this->conector->resultados((string) $request->getContent())));

        return $this->texto('OK');
    }

    /** @return array{0: ?EquipoAcceso, 1: ?Response} */
    private function equipo(Request $request): array
    {
        if ($this->demasiadoGrande($request)) {
            return [null, $this->texto('ERROR', 413)];
        }
        $sn = (string) $request->query('SN');
        if (! preg_match(ConectorZkteco::SN, $sn)) {
            return [null, $this->rechazo($request, 'SN con formato inválido', 400)];
        }
        $equipo = EquipoAcceso::withoutGlobalScopes()->where('marca', 'zkteco')->where('numero_serie', $sn)->where('activo', true)->first();
        if ($equipo === null) {
            return [null, $this->rechazo($request, 'SN no registrado')];
        }
        if (! $this->ipValida($equipo, $request)) {
            return [null, $this->rechazo($request, 'IP no permitida', 403)];
        }

        return [$equipo, null];
    }

    /** «INFO=Ver 6.60…,…» que algunos equipos mandan al preguntar: solo la versión, recortada. */
    private function version(Request $request): ?string
    {
        $info = (string) ($request->query('INFO') ?? $request->query('pushver') ?? '');
        $info = trim((string) preg_replace('/[^\w .,:\/-]/u', '', $info));

        return $info === '' ? null : mb_substr($info, 0, 120);
    }
}
