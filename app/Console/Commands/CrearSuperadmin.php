<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crea o actualiza la cuenta de Super Administrador de la plataforma.
 * --hash permite conservar la contrasena de SEGCAT (hash bcrypt $2y$).
 */
class CrearSuperadmin extends Command
{
    protected $signature = 'plataforma:superadmin
        {email : Correo de acceso}
        {--nombre=Super Administrador : Nombre visible}
        {--usuario= : Nombre de usuario (opcional)}
        {--password= : Contrasena nueva (si no se da, se genera una)}
        {--hash= : Hash bcrypt existente (conserva la contrasena de SEGCAT)}';

    protected $description = 'Crea o actualiza el Super Administrador de la plataforma';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Correo no valido.');

            return self::FAILURE;
        }

        $hash = $this->option('hash');
        if ($hash !== null && ! preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $hash)) {
            $this->error('El hash no tiene formato bcrypt.');

            return self::FAILURE;
        }

        $usuario = User::firstOrNew(['email' => $email]);
        $usuario->name = $this->option('nombre');
        $usuario->username = $this->option('usuario') ?: $usuario->username;
        $usuario->empresa_id = null;
        $usuario->activo = true;
        $usuario->forceFill(['es_superadmin' => true]);

        $generada = null;
        if ($hash !== null) {
            // Asignacion directa para no volver a cifrar el hash
            $usuario->setRawAttributes(['password' => $hash] + $usuario->getAttributes());
        } elseif ($this->option('password') !== null) {
            $usuario->password = $this->option('password');
        } elseif (! $usuario->exists) {
            $generada = Str::password(16);
            $usuario->password = $generada;
        }

        $usuario->save();

        $this->info("Super Administrador listo: {$email}");
        if ($generada !== null) {
            $this->warn("Contrasena temporal: {$generada}  (cambiala al entrar y borra este registro)");
        }

        return self::SUCCESS;
    }
}
