<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos base de la plataforma. Idempotente: seguro de correr en cada despliegue.
 * No crea empresas ni usuarios; el Super Administrador se crea con
 * `php artisan plataforma:superadmin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogoSeeder::class,
            RubrosSeeder::class,
            // El menu va antes de las plantillas: la del Agente distingue Padrones de Operacion
            MenuSeeder::class,
            RolesPlantillaSeeder::class,
            TiposEspacioSeeder::class,
        ]);
    }
}
