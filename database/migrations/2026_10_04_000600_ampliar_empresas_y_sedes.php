<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de SEGCAT que faltaban: dirección completa y teléfono de la sede
 * (antes "hoteles") y RFC único por empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->string('ciudad', 100)->nullable()->after('nombre');
            $table->string('entidad', 100)->nullable()->after('ciudad');
            $table->string('colonia', 150)->nullable()->after('direccion');
            $table->string('codigo_postal', 10)->nullable()->after('colonia');
            $table->string('telefono', 20)->nullable()->after('codigo_postal');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->unique('rfc');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique(['rfc']);
        });

        Schema::table('sedes', function (Blueprint $table) {
            $table->dropColumn(['ciudad', 'entidad', 'colonia', 'codigo_postal', 'telefono']);
        });
    }
};
