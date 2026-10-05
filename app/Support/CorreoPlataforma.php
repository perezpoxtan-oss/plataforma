<?php

namespace App\Support;

use App\Models\ConfiguracionPlataforma;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Correo saliente de la plataforma (avisos, pruebas). Se configura desde
 * Configuración → Correo, no en el .env: el Super Administrador captura el
 * servidor SMTP y la contraseña se guarda cifrada con la APP_KEY (nunca se
 * vuelve a mostrar ni viaja al navegador).
 */
class CorreoPlataforma
{
    public const CLAVE = 'correo';

    public const MAILER = 'plataforma';

    public const CIFRADOS = ['tls' => 'TLS / STARTTLS (puerto 587)', 'ssl' => 'SSL (puerto 465)', 'ninguno' => 'Sin cifrado (no recomendado)'];

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $guardados = Schema::hasTable('configuracion_plataforma')
            ? (ConfiguracionPlataforma::where('clave', self::CLAVE)->value('valor') ?? [])
            : [];

        return array_merge([
            'host' => null, 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => null, 'contrasena' => null,
            'remitente_correo' => null, 'remitente_nombre' => null, 'ultimo_envio' => null, 'ultimo_error' => null,
        ], $guardados);
    }

    public function configurado(): bool
    {
        $d = $this->datos();

        return ! empty($d['host']) && ! empty($d['remitente_correo']);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardar(array $datos, ?string $contrasenaNueva, bool $quitarContrasena = false): void
    {
        $actual = $this->datos();
        $contrasena = match (true) {
            $quitarContrasena => null,
            $contrasenaNueva !== null && $contrasenaNueva !== '' => Crypt::encryptString($contrasenaNueva),
            default => $actual['contrasena'],
        };

        $this->escribir(array_merge($actual, $datos, ['contrasena' => $contrasena, 'ultimo_error' => null]));
    }

    /**
     * Envía un correo con la configuración guardada. Nunca lanza excepción:
     * devuelve false y deja el error para mostrarlo en Configuración.
     *
     * @param  string|list<string>  $para
     */
    public function enviar(string|array $para, Mailable $correo): bool
    {
        if (! $this->configurado()) {
            return false;
        }

        try {
            $this->aplicar();
            Mail::mailer(self::MAILER)->to($para)->send($correo);
            $this->anotar(['ultimo_envio' => now()->toIso8601String(), 'ultimo_error' => null]);

            return true;
        } catch (Throwable $e) {
            // El detalle técnico va al log; en pantalla, un resumen sin la contraseña
            Log::warning('Correo de la plataforma: no se pudo enviar', ['error' => $e->getMessage()]);
            $this->anotar(['ultimo_error' => mb_substr(now()->toIso8601String().' · '.$e->getMessage(), 0, 300)]);

            return false;
        }
    }

    /**
     * Configura el mailer "plataforma" con lo guardado.
     */
    public function aplicar(): void
    {
        $d = $this->datos();
        $contrasena = null;
        if (! empty($d['contrasena'])) {
            try {
                $contrasena = Crypt::decryptString($d['contrasena']);
            } catch (DecryptException) {
                $contrasena = null; // cambió la APP_KEY: hay que volver a capturarla
            }
        }

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'scheme' => $d['cifrado'] === 'ssl' ? 'smtps' : 'smtp',
                'host' => $d['host'],
                'port' => (int) $d['puerto'],
                'username' => $d['usuario'],
                'password' => $contrasena,
                'timeout' => 15,
            ],
            'mail.from' => ['address' => $d['remitente_correo'], 'name' => $d['remitente_nombre'] ?: app(Identidad::class)->get('nombre')],
        ]);
        Mail::purge(self::MAILER);
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function anotar(array $cambios): void
    {
        $this->escribir(array_merge($this->datos(), $cambios));
    }

    /**
     * @param  array<string, mixed>  $valor
     */
    private function escribir(array $valor): void
    {
        ConfiguracionPlataforma::updateOrCreate(['clave' => self::CLAVE], ['valor' => $valor]);
    }
}
