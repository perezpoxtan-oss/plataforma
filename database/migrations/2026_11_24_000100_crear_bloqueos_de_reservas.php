<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de sitios en Reservas de áreas (docs/tecnico/reservas.md,
 * «Bloqueos»): un área o espacio interior se bloquea por un rango de fecha y
 * hora con su motivo (mantenimiento, evento de dirección…). Mientras está
 * bloqueado nadie puede reservar ese horario; bloquear una zona bloquea sus
 * espacios interiores y al revés (la misma regla que las reservas).
 *
 *  - reserva_bloqueos (nueva): empresa, sede, área y espacio bloqueados,
 *    desde / hasta en hora universal, motivo, activo y la baja (cuándo, quién
 *    y por qué), con auditoría creado_por / actualizado_por.
 *  - reservas.bloqueo_id (opcional): la reserva quedó «Afectada por
 *    bloqueo». Nada se da de baja solo: quien reservó la mueve o la da de baja.
 *
 * down(): quita la llave y la columna de `reservas` (sin reconstruir la
 * tabla en SQLite, igual que 2026_11_07_000100) y borra la tabla nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_bloqueos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->foreignId('reserva_area_id')->constrained('reserva_areas')->cascadeOnDelete();
            $table->foreignId('espacio_id')->constrained('espacios')->cascadeOnDelete();
            $table->dateTime('desde');
            $table->dateTime('hasta');
            $table->string('motivo', 255);
            $table->boolean('activo')->default(true);
            $table->timestamp('dado_de_baja_en')->nullable();
            $table->foreignId('dado_de_baja_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_baja', 255)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'activo', 'hasta']);
            $table->index(['espacio_id', 'desde', 'hasta']);
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('bloqueo_id')->nullable()->after('estado');
        });
        // La llave aparte: en SQLite, sin reconstruir la tabla (sus hijos tienen llave restrict, docs/tecnico/integridad-bd.md)
        Restricciones::agregarForanea('reservas', 'bloqueo_id', 'reserva_bloqueos', 'set null');
    }

    public function down(): void
    {
        Restricciones::quitarForanea('reservas', 'bloqueo_id');
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('bloqueo_id');
        });
        Schema::dropIfExists('reserva_bloqueos');
    }
};
