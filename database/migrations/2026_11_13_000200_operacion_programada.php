<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 4 · DD-17 (operación programada): la fecha de
 * la próxima recarga de un equipo de Protección Civil (opcional). La
 * frecuencia de revisión por tipo vive en empresas.preferencias.
 *
 * down(): quita la columna (las fechas capturadas se pierden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos_pc', function (Blueprint $table) {
            $table->date('proxima_recarga')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('equipos_pc', function (Blueprint $table) {
            $table->dropColumn('proxima_recarga');
        });
    }
};
