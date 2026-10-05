<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de cada empresa que se ajustan desde Configuración
 * (por ejemplo, qué avisos se mandan por correo). JSON para crecer sin
 * agregar una columna por cada opción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->json('preferencias')->nullable()->after('moneda');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('preferencias');
        });
    }
};
