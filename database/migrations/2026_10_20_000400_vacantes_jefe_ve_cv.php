<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos, fase 2 (ADR-0009): casilla «El jefe puede ver el CV» de la
 * vacante (apagada por omisión). Con ella, quien entrevista ve el CV en PDF
 * del candidato en la pantalla Entrevistar (ruta autorizada, disco privado);
 * sin ella, solo el resumen. Las plazas ya existían (por omisión 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vacantes', 'jefe_ve_cv')) {
            Schema::table('vacantes', function (Blueprint $table) {
                $table->boolean('jefe_ve_cv')->default(false)->after('plazas');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vacantes', 'jefe_ve_cv')) {
            Schema::table('vacantes', function (Blueprint $table) {
                $table->dropColumn('jefe_ve_cv');
            });
        }
    }
};
