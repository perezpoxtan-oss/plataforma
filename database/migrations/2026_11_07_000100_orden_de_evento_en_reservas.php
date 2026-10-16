<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas con formato de «orden de evento» (docs/tecnico/reservas.md,
 * «Orden de evento»). Solo agrega columnas opcionales a `reservas`; las
 * reservas que ya existen siguen igual (nulos y falsos = lo de antes):
 *
 *  - departamento_solicitante_id: departamento que pide el evento («Solicitado
 *    por: Juan Pérez — Capacitación»). Se propone el del colaborador del usuario.
 *  - excede_aforo + motivo_excepcion_aforo: la reserva pasa del aforo del área
 *    o del montaje por excepción, con su motivo (lo ve quien aprueba).
 *  - dia_completo: se reservó «Día completo» (el horario del área de ese día).
 *  - montaje_minutos / desmontaje_minutos: tiempo antes y después de ESTA
 *    reserva; null = el del área (lo de siempre).
 *  - indicaciones_montaje: texto corto resaltado en la hoja («Tener montaje
 *    listo 30 min antes»), separado de las notas para quien aprueba.
 *
 * down(): quita la llave foránea y las columnas (seguro en MariaDB y SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('departamento_solicitante_id')->nullable()->after('solicitante_id')->constrained('departamentos')->nullOnDelete();
            $table->boolean('excede_aforo')->default(false)->after('asistentes');
            $table->string('motivo_excepcion_aforo', 255)->nullable()->after('excede_aforo');
            $table->boolean('dia_completo')->default(false)->after('fin');
            $table->unsignedSmallInteger('montaje_minutos')->nullable()->after('bloqueo_hasta');
            $table->unsignedSmallInteger('desmontaje_minutos')->nullable()->after('montaje_minutos');
            $table->string('indicaciones_montaje', 255)->nullable()->after('desmontaje_minutos');
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('departamento_solicitante_id');
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn(['excede_aforo', 'motivo_excepcion_aforo', 'dia_completo', 'montaje_minutos', 'desmontaje_minutos', 'indicaciones_montaje']);
        });
    }
};
