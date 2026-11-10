<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DosPasos\VerificacionDosPasos;
use Illuminate\Console\Command;

/**
 * Quita la verificación en dos pasos de una cuenta desde la consola (por
 * ejemplo, el Super Administrador que perdió su teléfono y sus códigos de
 * respaldo). Queda en la Bitácora de auditoría. Al entrar, la activa de nuevo.
 */
class RestablecerDosPasos extends Command
{
    protected $signature = 'plataforma:dos-pasos {cuenta : Usuario o correo} {--forzar : Sin pedir confirmación}';

    protected $description = 'Restablece la verificación en dos pasos de una cuenta';

    public function handle(VerificacionDosPasos $dosPasos): int
    {
        $cuenta = trim((string) $this->argument('cuenta'));
        $usuario = User::where('username', $cuenta)->orWhere('email', $cuenta)->first();
        if ($usuario === null) {
            $this->error('No existe una cuenta con ese usuario o correo.');

            return self::FAILURE;
        }
        if (! $usuario->tieneDosPasos()) {
            $this->info("«{$usuario->name}» no tiene activa la verificación en dos pasos.");

            return self::SUCCESS;
        }
        if (! $this->option('forzar') && ! $this->confirm("¿Quitar la verificación en dos pasos de «{$usuario->name}»?")) {
            return self::FAILURE;
        }
        $dosPasos->restablecerDesdeConsola($usuario);
        $this->info("Listo: «{$usuario->name}» la activará de nuevo al entrar.");

        return self::SUCCESS;
    }
}
