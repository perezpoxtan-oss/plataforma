<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/**
 * Carga o actualiza el catalogo base (areas, modulos, acciones, rubros y
 * plantillas de rol). Idempotente; se ejecuta en cada despliegue.
 */
class InstalarPlataforma extends Command
{
    protected $signature = 'plataforma:instalar';

    protected $description = 'Carga o actualiza el catalogo base de la plataforma';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        $this->info('Catalogo de la plataforma actualizado.');

        return self::SUCCESS;
    }
}
