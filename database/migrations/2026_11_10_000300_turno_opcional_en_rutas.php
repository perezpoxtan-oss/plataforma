<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-06: el turno de una ruta de transporte es
 * opcional (es informativo: solo se muestra en la hoja del día y el
 * itinerario). Así Transporte no depende de que la empresa use Turnos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->unsignedBigInteger('turno_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rutas sin turno: se les pone el primer turno de su empresa para poder volver a exigirlo
        foreach (DB::table('rutas')->whereNull('turno_id')->get(['id', 'empresa_id']) as $ruta) {
            $turno = DB::table('turnos')->where('empresa_id', $ruta->empresa_id)->orderByDesc('activo')->orderBy('id')->value('id');
            if ($turno === null) {
                throw new RuntimeException("La ruta {$ruta->id} no tiene turno y su empresa no tiene ninguno: da de alta un turno y asígnaselo antes de revertir.");
            }
            DB::table('rutas')->where('id', $ruta->id)->update(['turno_id' => $turno]);
        }
        Schema::table('rutas', function (Blueprint $table) {
            $table->unsignedBigInteger('turno_id')->nullable(false)->change();
        });
    }
};
