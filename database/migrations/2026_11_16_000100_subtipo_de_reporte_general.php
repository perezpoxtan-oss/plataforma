<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 6 · C-32 (D-17): en condominio y corporativo
 * todo caía en «Reporte General». Su detalle ahora lleva un subtipo opcional
 * (Ruido, Mascotas, Fuga de agua…) de una lista que configura cada empresa
 * (Configuración → Bitácora de novedades → Avanzado; de fábrica según el giro).
 *
 * down(): quita la columna (los reportes conservan lo demás).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novedad_reportes_generales', function (Blueprint $table) {
            $table->string('subtipo', 60)->nullable()->after('novedad_id');
        });
    }

    public function down(): void
    {
        Schema::table('novedad_reportes_generales', fn (Blueprint $table) => $table->dropColumn('subtipo'));
    }
};
