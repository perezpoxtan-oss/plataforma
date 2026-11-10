<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de áreas por niveles (docs/tecnico/reservas.md, «Espacios interiores»).
 *
 * `reserva_areas.modo` dice cómo se reserva una zona que tiene espacios
 * interiores en Zonas y edificios:
 *  - completa: la zona se reserva como un solo espacio (lo de siempre);
 *  - interiores: la zona solo agrupa; se reservan sus espacios interiores
 *    (cada uno con su propia configuración en reserva_areas).
 *
 * Solo agrega una columna con valor por omisión («completa»): las áreas que ya
 * existen siguen igual. No toca la tabla espacios. down() quita la columna
 * (seguro en MariaDB y SQLite; no hay índices ni llaves sobre ella).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_areas', function (Blueprint $table) {
            $table->string('modo', 12)->default('completa')->after('reservable');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_areas', function (Blueprint $table) {
            $table->dropColumn('modo');
        });
    }
};
