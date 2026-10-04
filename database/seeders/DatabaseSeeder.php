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
            RolesPlantillaSeeder::class,
            MenuSeeder::class,
            TiposEspacioSeeder::class,
        ]);
    }
}
