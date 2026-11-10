<?php

use App\Models\Rol;
use App\Services\Plataforma\ProvisionarEmpresa;
use Database\Seeders\RolesPlantillaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles base «Solicitante» (nivel 70) y «Jefe de departamento» (nivel 45):
 * el personal de otros departamentos levanta solicitudes (pases de salida) y
 * su jefe las aprueba y responde las autorizaciones de su departamento.
 *
 * 1. Crea las plantillas si faltan (misma regla que RolesPlantillaSeeder).
 * 2. Las copia, con sus permisos, a cada empresa que ya existía y no tiene un
 *    rol con ese nombre. Si la empresa ya usa ese nivel para otro rol propio,
 *    no se crea (el nivel es único por empresa).
 *
 * Idempotente. En una instalación nueva no hace nada: las plantillas las crea
 * el seeder y las empresas nuevas las reciben al darse de alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisionar = app(ProvisionarEmpresa::class);

        foreach ([RolesPlantillaSeeder::SOLICITANTE, RolesPlantillaSeeder::JEFE_DEPARTAMENTO] as $nombre) {
            $plantilla = RolesPlantillaSeeder::asegurarPlantilla($nombre);
            if ($plantilla === null) {
                continue;
            }

            $plantilla = Rol::with('permisos')->findOrFail($plantilla->id);
            foreach (DB::table('empresas')->orderBy('id')->pluck('id') as $empresaId) {
                $provisionar->copiarPlantillaSiFalta($plantilla, (int) $empresaId);
            }
        }
    }

    public function down(): void
    {
        // Sin reversa: los roles pudieron ya asignarse a usuarios o ajustarse en
        // la Matriz de permisos. Si sobran, se eliminan desde Roles.
    }
};
