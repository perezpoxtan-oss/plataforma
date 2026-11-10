<?php

namespace App\Services\EquiposAcceso;

use App\Models\EquipoAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Hikvision por la notificación de eventos ISAPI («HTTP listening» / «HTTP
 * host»): el equipo empuja cada evento a
 * POST /equipos-acceso/hikvision/{token} (o con el encabezado
 * X-Equipo-Token), en JSON o en multipart (el JSON en el campo
 * «event_log» o «AccessControllerEvent» y, a veces, una foto, que se
 * descarta sin guardarla).
 *
 * Evento que cuenta: eventType = AccessControllerEvent con
 * majorEventType = 5 (evento de acceso) y un subEventType de paso válido
 * (MENORES_ACEPTADOS). El reconocimiento facial NO se usa (decisión del
 * dueño): el paso por rostro se registra como evento ignorado. La
 * plataforma no puede llamar al equipo: las altas y bajas de tarjetas se
 * cargan en el equipo o con el puente local (fase siguiente).
 * Fuentes: docs/tecnico/equipos-acceso.md § Fuentes.
 */
class ConectorHikvision implements Conector
{
    /** Evento de acceso (MAJOR_EVENT). */
    public const MAYOR_ACCESO = 5;

    /**
     * Pasos válidos que se registran: 1 tarjeta válida, 38 y 113 (tarjeta o
     * huella según el modelo). Se confirma con el equipo real (PI-EQ-04).
     */
    public const MENORES_ACEPTADOS = [1, 38, 113];

    /** attendanceStatus => sentido. */
    public const ASISTENCIA = ['checkIn' => 'entrada', 'breakIn' => 'entrada', 'overtimeIn' => 'entrada',
        'checkOut' => 'salida', 'breakOut' => 'salida', 'overtimeOut' => 'salida'];

    /** Tamaño máximo de un JSON de evento (bytes). */
    public const MAX_JSON = 65536;

    /** Eventos por petición (activePostCount suele ser 1). */
    public const MAX_EVENTOS = 50;

    public function marca(): string
    {
        return 'hikvision';
    }

    public function nombre(): string
    {
        return 'Hikvision';
    }

    public function sincronizaUsuarios(): bool
    {
        return false;
    }

    public function comandoAlta(string $pin, ?string $tarjeta): ?string
    {
        return null;
    }

    public function comandoBaja(string $pin): ?string
    {
        return null;
    }

    public function marcajes(Request $request, EquipoAcceso $equipo): array
    {
        $zona = $equipo->sede?->zonaHoraria() ?? config('app.timezone');
        $leidos = [];
        foreach (array_slice($this->eventos($request), 0, self::MAX_EVENTOS) as $evento) {
            $leido = $this->leerEvento($evento, $zona);
            if ($leido !== null) {
                $leidos[] = $leido;
            }
        }

        return $leidos;
    }

    /** ¿La petición es solo un latido (heartBeat)? */
    public function esLatido(Request $request): bool
    {
        $eventos = $this->eventos($request);

        return $eventos !== [] && collect($eventos)->every(fn (array $e) => strcasecmp((string) ($e['eventType'] ?? ''), 'heartBeat') === 0);
    }

    /**
     * Los JSON de evento que trae la petición: el cuerpo (JSON) o los campos y
     * partes JSON de un multipart. Las imágenes no se leen.
     *
     * @return list<array<string, mixed>>
     */
    public function eventos(Request $request): array
    {
        $crudos = [];
        if (str_contains(strtolower((string) $request->header('Content-Type')), 'multipart/')) {
            foreach ($request->request->all() as $valor) {
                if (is_string($valor)) {
                    $crudos[] = $valor;
                }
            }
            foreach ($request->allFiles() as $archivo) {
                if ($archivo instanceof UploadedFile && $archivo->isValid() && $archivo->getSize() <= self::MAX_JSON
                    && str_contains(strtolower((string) $archivo->getClientMimeType()), 'json')) {
                    $crudos[] = (string) file_get_contents($archivo->getRealPath());
                }
            }
        } else {
            $crudos[] = (string) $request->getContent();
        }

        $eventos = [];
        foreach ($crudos as $crudo) {
            if (strlen($crudo) > self::MAX_JSON) {
                continue;
            }
            try {
                $json = json_decode($crudo, true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($json) && isset($json['eventType']) && is_string($json['eventType'])) {
                $eventos[] = $json;
            }
        }

        return $eventos;
    }

    /** @param  array<string, mixed>  $e */
    public function leerEvento(array $e, string $zona): ?MarcajeLeido
    {
        $tipo = (string) $e['eventType'];
        if (strcasecmp($tipo, 'heartBeat') === 0) {
            return null;
        }
        $cuando = $this->hora($e['dateTime'] ?? null, $zona);
        $ace = is_array($e['AccessControllerEvent'] ?? null) ? $e['AccessControllerEvent'] : [];
        $mayor = $this->entero($ace['majorEventType'] ?? null);
        $menor = $this->entero($ace['subEventType'] ?? null);
        $serie = $this->entero($ace['serialNo'] ?? null);
        $pin = $this->texto($ace['employeeNoString'] ?? ($ace['employeeNo'] ?? null), '/^[A-Za-z0-9-]{1,30}$/');
        $tarjeta = $this->texto($ace['cardNo'] ?? null, '/^[A-Za-z0-9]{1,32}$/');
        $clave = $serie !== null
            ? 'serie|'.$serie.'|'.($e['dateTime'] ?? '')
            : implode('|', [$tipo, $e['dateTime'] ?? '', $mayor, $menor, $pin, $tarjeta]);

        if ($tipo !== 'AccessControllerEvent' || $cuando === null) {
            return new MarcajeLeido($clave, null, null, $cuando, null, 'Evento «'.mb_substr($tipo, 0, 40).'» del equipo');
        }
        if ($mayor !== self::MAYOR_ACCESO || ! in_array($menor, self::MENORES_ACEPTADOS, true)) {
            return new MarcajeLeido($clave, null, null, $cuando, null, "Evento {$mayor}/{$menor} (no es un paso válido)");
        }
        if ($pin === null && $tarjeta === null) {
            return new MarcajeLeido($clave, null, null, $cuando, null, 'Paso sin número de empleado ni tarjeta');
        }

        return new MarcajeLeido($clave, $pin, $tarjeta, $cuando, self::ASISTENCIA[(string) ($ace['attendanceStatus'] ?? '')] ?? null);
    }

    public function comoConfigurar(EquipoAcceso $equipo, ?string $token): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $host = parse_url($url, PHP_URL_HOST) ?: 'la dirección de tu plataforma';
        $https = str_starts_with($url, 'https');

        return [
            'Entra a la página web del equipo (desde la red del cliente) con la cuenta de administrador.',
            'Ve a Configuración → Red → Configuración avanzada → «Escucha HTTP» (en otros modelos: Evento → «Host de alarma HTTP» o «HTTP Listening»).',
            "Dirección (IP o dominio): {$host} · Puerto: ".($https ? '443 · Protocolo: HTTPS' : '80 · Protocolo: HTTP').'.',
            'Ruta (URL): /equipos-acceso/hikvision/'.($token ?? '<token del equipo>').($token === null ? ' — el token se muestra una sola vez al dar de alta el equipo o al tocar «Generar otro token».' : ''),
            'Formato: JSON. Autenticación: ninguna (el token de la ruta identifica al equipo). Si el equipo ofrece «latido» (heartbeat), actívalo cada 1 a 5 minutos: así esta pantalla sabe que está en línea.',
            'Guarda y pasa una tarjeta: en «Últimos marcajes» debe aparecer.',
            'Las altas y bajas de tarjetas se cargan en el equipo (su página web o su programa) mientras no exista el puente local.',
        ];
    }

    private function hora(mixed $valor, string $zona): ?CarbonImmutable
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $valor)) {
            return null;
        }
        try {
            $conZona = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $valor);

            return CarbonImmutable::parse($valor, $conZona ? null : $zona)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function entero(mixed $v): ?int
    {
        return is_int($v) || (is_string($v) && preg_match('/^\d{1,10}$/', $v)) ? (int) $v : null;
    }

    private function texto(mixed $v, string $patron): ?string
    {
        if (is_int($v)) {
            $v = (string) $v;
        }

        return is_string($v) && preg_match($patron, trim($v)) ? trim($v) : null;
    }
}
