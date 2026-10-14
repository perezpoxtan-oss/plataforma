<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas de salida: la hora de fin del recorrido es opcional (se sabe a qué
 * hora sale de la sede, no siempre a qué hora termina). Las llegadas la
 * siguen exigiendo en la aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ruta_horarios', function (Blueprint $table) {
            $table->time('hora_fin')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin hora de fin: se toma la de inicio para poder volver a exigirla
        DB::table('ruta_horarios')->whereNull('hora_fin')->update(['hora_fin' => DB::raw('hora_inicio')]);
        DB::table('rutas')->whereNull('hora_fin')->whereNotNull('hora_inicio')->update(['hora_fin' => DB::raw('hora_inicio')]);
        Schema::table('ruta_horarios', function (Blueprint $table) {
            $table->time('hora_fin')->nullable(false)->change();
        });
    }
};
