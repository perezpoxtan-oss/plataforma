<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión del dueño (mapa de procesos): las ocho categorías del Padrón
 * Vehicular se funden en dos.
 *
 *  - particular: propio_huesped, propio_visitante, propio_familiar, propio_colaborador
 *  - empresa:    agencia_renta, empresa_proveedor, taxi_app, transporte_personal
 *
 * La categoría original de cada vehículo se guarda en
 * `vehiculos.categoria_anterior` para que down() la restaure exacta. Los
 * vehículos dados de alta después (sin categoría anterior) regresan a la
 * más cercana: particular con colaborador → propio_colaborador; particular
 * sin colaborador → propio_visitante; de una empresa → empresa_proveedor.
 *
 * Se puede correr más de una vez: solo convierte lo que aún tiene una clave vieja.
 */
return new class extends Migration
{
    /** categoría anterior => nueva (copia fija de Vehiculo::CATEGORIAS_ANTERIORES) */
    private const MAPA = [
        'propio_huesped' => 'particular',
        'propio_visitante' => 'particular',
        'propio_familiar' => 'particular',
        'propio_colaborador' => 'particular',
        'agencia_renta' => 'empresa',
        'empresa_proveedor' => 'empresa',
        'taxi_app' => 'empresa',
        'transporte_personal' => 'empresa',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('vehiculos', 'categoria_anterior')) {
            Schema::table('vehiculos', function (Blueprint $table) {
                // Categoría de las ocho originales, para poder regresar exacto
                $table->string('categoria_anterior', 30)->nullable()->after('propiedad');
            });
        }

        Schema::table('vehiculos', function (Blueprint $table) {
            $table->string('propiedad', 30)->default('particular')->change();
        });

        foreach (self::MAPA as $anterior => $nueva) {
            DB::table('vehiculos')->where('propiedad', $anterior)->update([
                'categoria_anterior' => $anterior,
                'propiedad' => $nueva,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vehiculos', 'categoria_anterior')) {
            DB::table('vehiculos')->whereIn('propiedad', ['particular', 'empresa'])->whereNotNull('categoria_anterior')
                ->update(['propiedad' => DB::raw('categoria_anterior')]);
        }
        DB::table('vehiculos')->where('propiedad', 'particular')->whereNotNull('colaborador_id')->update(['propiedad' => 'propio_colaborador']);
        DB::table('vehiculos')->where('propiedad', 'particular')->update(['propiedad' => 'propio_visitante']);
        DB::table('vehiculos')->where('propiedad', 'empresa')->update(['propiedad' => 'empresa_proveedor']);

        Schema::table('vehiculos', function (Blueprint $table) {
            $table->string('propiedad', 30)->default('propio_visitante')->change();
        });

        if (Schema::hasColumn('vehiculos', 'categoria_anterior')) {
            Schema::table('vehiculos', function (Blueprint $table) {
                $table->dropColumn('categoria_anterior');
            });
        }
    }
};
