<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 5 · C-34 (D-10, D-11):
 *
 *  - reservas.residente_id y reservas.vivienda_id: en condominio y
 *    fraccionamiento, «¿Para quién?» es un residente y su vivienda (historial
 *    por vivienda del uso de las áreas comunes).
 *  - reserva_ordenes.sin_quien_atienda: la orden de servicio se creó y su
 *    departamento no tiene quien la atienda en la sede (se avisa a quien
 *    configura las reservas).
 *
 * down(): quita las columnas (las reservas quedan como «para un cliente» o
 * propias, con su título; nada se borra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->unsignedBigInteger('residente_id')->nullable()->after('solicitante_id');
            $table->unsignedBigInteger('vivienda_id')->nullable()->after('residente_id');
            $table->index('residente_id');
            $table->index('vivienda_id');
        });
        Restricciones::agregarForanea('reservas', 'residente_id', 'colaboradores', 'set null');
        Restricciones::agregarForanea('reservas', 'vivienda_id', 'espacios', 'set null');
        Schema::table('reserva_ordenes', function (Blueprint $table) {
            $table->boolean('sin_quien_atienda')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_ordenes', function (Blueprint $table) {
            $table->dropColumn('sin_quien_atienda');
        });
        Restricciones::quitarForanea('reservas', 'vivienda_id');
        Restricciones::quitarForanea('reservas', 'residente_id');
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropIndex(['residente_id']);
            $table->dropIndex(['vivienda_id']);
        });
        Schema::table('reservas', fn (Blueprint $table) => $table->dropColumn(['residente_id', 'vivienda_id']));
    }
};
