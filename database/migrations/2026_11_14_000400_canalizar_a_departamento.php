<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 5 · C-28 (DD-15): un incidente se puede
 * canalizar a un departamento (Mantenimiento, RR. HH., Ama de llaves…). Sus
 * responsables ven solo ese ticket, agregan notas y «Atender» su parte.
 *
 *  - novedades.departamento_id: a qué departamento se canalizó.
 *  - novedades.departamento_en: desde cuándo (el reloj de la Etapa 4).
 *  - novedades.departamento_atendido_en / _por: cuándo y quién atendió su parte.
 *
 * down(): quita las columnas (los tickets siguen con su persona canalizada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novedades', function (Blueprint $table) {
            $table->unsignedBigInteger('departamento_id')->nullable()->after('asignado_a');
            $table->timestamp('departamento_en')->nullable()->after('departamento_id');
            $table->timestamp('departamento_atendido_en')->nullable()->after('departamento_en');
            $table->unsignedBigInteger('departamento_atendido_por')->nullable()->after('departamento_atendido_en');
            $table->index(['empresa_id', 'departamento_id']);
            $table->index('departamento_atendido_por');
        });
        Restricciones::agregarForanea('novedades', 'departamento_id', 'departamentos', 'restrict');
        Restricciones::agregarForanea('novedades', 'departamento_atendido_por', 'users', 'set null');
    }

    public function down(): void
    {
        Restricciones::quitarForanea('novedades', 'departamento_atendido_por');
        Restricciones::quitarForanea('novedades', 'departamento_id');
        Schema::table('novedades', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'departamento_id']);
            $table->dropIndex(['departamento_atendido_por']);
        });
        Schema::table('novedades', fn (Blueprint $table) => $table->dropColumn(['departamento_id', 'departamento_en', 'departamento_atendido_en', 'departamento_atendido_por']));
    }
};
