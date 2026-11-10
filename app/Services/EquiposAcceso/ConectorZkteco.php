<?php

namespace App\Services\EquiposAcceso;

use App\Models\EquipoAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * ZKTeco por el protocolo PUSH («ADMS», rutas /iclock/…). El equipo llama:
 *
 *   GET  /iclock/cdata?SN=…&options=all   saludo: la plataforma responde sus opciones
 *   POST /iclock/cdata?SN=…&table=ATTLOG  marcajes, un renglón por marcaje:
 *        PIN \t AAAA-MM-DD HH:MM:SS \t estado \t verificación \t código …
 *   GET  /iclock/getrequest?SN=…          «¿hay algo para mí?»: «OK» o «C:<id>:<comando>»
 *   POST /iclock/devicecmd?SN=…           resultado: «ID=<id>&Return=<n>&CMD=…»
 *
 * El equipo se identifica SOLO por su número de serie (SN): por eso cada
 * equipo puede limitarse a las IP desde las que llama (ips_permitidas). La
 * hora del marcaje viene sin zona: es la hora local de la sede.
 * Fuentes: docs/tecnico/equipos-acceso.md § Fuentes.
 */
class ConectorZkteco implements Conector
{
    /** Renglones de marcaje que se leen por petición (el equipo manda el resto en la siguiente). */
    public const MAX_RENGLONES = 2000;

    /** Comandos que se entregan por pregunta. */
    public const COMANDOS_POR_PREGUNTA = 20;

    /** Segundos entre preguntas que se le piden al equipo (Delay). */
    public const ESPERA_SEGUNDOS = 30;

    /** Estado del marcaje (ATTLOG) => sentido. 0 (o sin teclas de estado) no dice nada. */
    public const ESTADOS = [1 => 'salida', 2 => 'salida', 5 => 'salida', 3 => 'entrada', 4 => 'entrada'];

    public const SN = '/^[A-Za-z0-9_-]{4,40}$/';

    public function marca(): string
    {
        return 'zkteco';
    }

    public function nombre(): string
    {
        return 'ZKTeco';
    }

    public function sincronizaUsuarios(): bool
    {
        return true;
    }

    /** Solo la tabla ATTLOG trae marcajes; las demás (OPERLOG, fotos…) se reciben y se descartan. */
    public function marcajes(Request $request, EquipoAcceso $equipo): array
    {
        if (strtoupper((string) $request->query('table')) !== 'ATTLOG') {
            return [];
        }
        $zona = $equipo->sede?->zonaHoraria() ?? config('app.timezone');
        $leidos = [];
        $renglones = preg_split('/\r\n|\n|\r/', (string) $request->getContent()) ?: [];
        foreach (array_slice($renglones, 0, self::MAX_RENGLONES) as $renglon) {
            $leido = $this->leerRenglon($renglon, $zona);
            if ($leido !== null) {
                $leidos[] = $leido;
            }
        }

        return $leidos;
    }

    /** Un renglón ATTLOG; null si no cumple el formato (se descarta). */
    public function leerRenglon(string $renglon, string $zona): ?MarcajeLeido
    {
        $campos = explode("\t", trim($renglon, " \r\n"));
        if (count($campos) < 2) {
            return null;
        }
        $pin = trim($campos[0]);
        $hora = trim($campos[1]);
        $estado = isset($campos[2]) && preg_match('/^\d{1,3}$/', trim($campos[2])) ? (int) trim($campos[2]) : null;
        if (! preg_match('/^[A-Za-z0-9]{1,30}$/', $pin) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $hora)) {
            return null;
        }
        try {
            $cuando = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $hora, $zona);
        } catch (Throwable) {
            return null;
        }
        if ($cuando === null || $cuando->format('Y-m-d H:i:s') !== $hora) {
            return null; // fecha imposible (31 de febrero…)
        }

        return new MarcajeLeido($pin.'|'.$hora.'|'.($estado ?? ''), $pin, null, $cuando->utc(), self::ESTADOS[$estado] ?? null);
    }

    /**
     * Respuesta al saludo: lo que el equipo debe mandar y cada cuánto.
     * `ATTLOGStamp` es la marca del último paquete recibido (el equipo
     * manda lo que siga); la primera vez, «None» = todo lo que tenga, y la
     * plataforma descarta lo anterior al alta del equipo.
     */
    public function opciones(EquipoAcceso $equipo): string
    {
        $estampa = preg_match('/^\d{1,20}$/', (string) $equipo->estampa_marcajes) ? $equipo->estampa_marcajes : 'None';

        return implode("\n", [
            'GET OPTION FROM: '.$equipo->numero_serie,
            'ATTLOGStamp='.$estampa,
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=None',
            'ErrorDelay=60',
            'Delay='.self::ESPERA_SEGUNDOS,
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog',
            'Realtime=1',
            'Encrypt=None',
        ])."\n";
    }

    /**
     * Resultados de comandos (POST /iclock/devicecmd), uno por renglón:
     * «ID=12&Return=0&CMD=DATA». Lo que no tenga ID numérico se ignora.
     *
     * @return array<int, int> id del comando => Return
     */
    public function resultados(string $cuerpo): array
    {
        $r = [];
        foreach (array_slice(preg_split('/\r\n|\n|\r/', $cuerpo) ?: [], 0, 500) as $renglon) {
            parse_str(trim($renglon), $campos);
            $id = $campos['ID'] ?? null;
            $ret = $campos['Return'] ?? null;
            if (is_string($id) && preg_match('/^\d{1,18}$/', $id) && is_string($ret) && preg_match('/^-?\d{1,6}$/', $ret)) {
                $r[(int) $id] = (int) $ret;
            }
        }

        return $r;
    }

    /**
     * Alta o actualización: el PIN es el número de empleado y la tarjeta el
     * número de la etiqueta del colaborador (en decimal, como lo guardan los
     * lectores de 125 kHz). El nombre NO se manda: el equipo solo necesita
     * el número (privacidad).
     */
    public function comandoAlta(string $pin, ?string $tarjeta): ?string
    {
        return "DATA UPDATE USERINFO PIN={$pin}\tName=\tPri=0\tPasswd=\tCard=".self::tarjeta($tarjeta)."\tGrp=1";
    }

    public function comandoBaja(string $pin): ?string
    {
        return "DATA DELETE USERINFO PIN={$pin}";
    }

    /** PIN válido para el equipo (solo letras y números; los equipos más comunes solo aceptan números). */
    public static function pin(?string $numEmpleado): ?string
    {
        $pin = preg_replace('/[^A-Za-z0-9]/', '', (string) $numEmpleado);

        return $pin === '' || strlen((string) $pin) > 24 ? null : $pin;
    }

    /** Número de tarjeta en decimal: lo que ya es decimal se queda; el hexadecimal se convierte. */
    public static function tarjeta(?string $etiqueta): string
    {
        $t = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $etiqueta));
        if ($t === '') {
            return '';
        }
        if (ctype_digit($t)) {
            return ltrim($t, '0') ?: '0';
        }
        if (ctype_xdigit($t) && strlen($t) <= 14) {
            return (string) hexdec($t);
        }

        return '';
    }

    public function comoConfigurar(EquipoAcceso $equipo, ?string $token): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'la dirección de tu plataforma';
        $https = str_starts_with((string) config('app.url'), 'https');

        return [
            'En el equipo entra a Menú → Comunicación → Servidor en la nube (en algunos modelos «ADMS» o «Configuración del servidor en la nube»).',
            "Dirección del servidor: {$host}. Activa «Nombre de dominio» si el equipo lo pide.",
            'Puerto: '.($https ? '443 y activa HTTPS (si tu modelo no tiene HTTPS, consulta al soporte de la plataforma)' : '80').'. Proxy: desactivado.',
            "Revisa que el número de serie del equipo sea exactamente {$equipo->numero_serie} (Menú → Información del sistema).",
            'Guarda y reinicia el equipo. En menos de un minuto esta pantalla debe decir «En línea».',
            'Toca «Sincronizar» para cargarle los colaboradores con credencial vigente de la sede.',
        ];
    }
}
