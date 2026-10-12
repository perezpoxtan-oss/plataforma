<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa de procesos: el turno se liga al colaborador.
 *
 * - colaboradores.turno_id es opcional (los colaboradores existentes quedan
 *   «Sin turno»). Si un turno se borra, sus colaboradores quedan sin turno
 *   (nullOnDelete); «Eliminar definitivamente» ya los cuenta como
 *   dependencia porque lee las llaves foráneas.
 * - El índice propio se crea ANTES de la llave foránea para que MariaDB lo
 *   use como soporte de la llave; down() quita primero la llave, luego el
 *   índice y al final la columna (en ese orden no choca con el error 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->unsignedBigInteger('turno_id')->nullable()->after('puesto_id');
            $table->index('turno_id'); // colaboradores_turno_id_index
            $table->foreign('turno_id')->references('id')->on('turnos')->nullOnDelete(); // colaboradores_turno_id_foreign
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropForeign(['turno_id']);
        });
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropIndex(['turno_id']);
            $table->dropColumn('turno_id');
        });
    }
};
