<?php

use App\Models\Rol;
use App\Services\Plataforma\ProvisionarEmpresa;
use Database\Seeders\RolesPlantillaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rol "Asistente" (nivel 40), como en SEGCAT ("Asistente de Seguridad -
 * Apoyo de Gestión Local"), entre el Jefe de seguridad (30) y el Supervisor (50).
 *
 * 1. Crea la plantilla si falta (misma regla que RolesPlantillaSeeder).
 * 2. La copia, con sus permisos, a cada empresa que ya existía y no tiene un
 *    rol llamado "Asistente". Si la empresa ya usa el nivel 40 para otro rol
 *    propio, no se crea (el nivel es único por empresa): el administrador
 *    puede darlo de alta a mano en otro nivel.
 *
 * Idempotente. En una instalación nueva no hace nada: el catálogo aún no
 * existe y la plantilla la crea el seeder; las empresas nuevas la reciben al
 * darse de alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plantilla = RolesPlantillaSeeder::asegurarPlantilla('Asistente');
        if ($plantilla === null) {
            return;
        }

        $plantilla = Rol::with('permisos')->findOrFail($plantilla->id);
        $provisionar = app(ProvisionarEmpresa::class);

        foreach (DB::table('empresas')->orderBy('id')->pluck('id') as $empresaId) {
            $provisionar->copiarPlantillaSiFalta($plantilla, (int) $empresaId);
        }
    }

    public function down(): void
    {
        // Sin reversa: el rol pudo ya asignarse a usuarios o ajustarse en la
        // Matriz de permisos. Si sobra, se elimina desde Roles.
    }
};
