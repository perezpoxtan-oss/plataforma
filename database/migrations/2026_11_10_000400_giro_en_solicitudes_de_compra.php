<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-05 / DD-04: al contratar en línea se pide el giro
 * (hotel, corporativo, condominio o fraccionamiento) y la empresa nace con
 * él (antes nacía siempre como hotel). Anulable: las solicitudes de antes
 * no lo tienen. down() quita la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('solicitudes_compra') || Schema::hasColumn('solicitudes_compra', 'rubro_id')) {
            return;
        }
        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->foreignId('rubro_id')->nullable()->after('plan_id')->constrained('rubros')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('solicitudes_compra', 'rubro_id')) {
            return;
        }
        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->dropForeign(['rubro_id']);
            $table->dropColumn('rubro_id');
        });
    }
};
