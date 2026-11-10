<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0014: una empresa puede pedir solo la casilla «Leí y entendí» (con
 * nombre, fecha y hora) en lugar de la firma dibujada; el acuse queda sin
 * firma_ruta. Sin configurar, todo acuse sigue llevando firma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedimiento_acuses', function (Blueprint $table) {
            $table->string('firma_ruta')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Los acuses de solo casilla conservan su fecha y hora; la versión anterior verá su firma vacía
        DB::table('procedimiento_acuses')->whereNull('firma_ruta')->update(['firma_ruta' => '']);
        Schema::table('procedimiento_acuses', function (Blueprint $table) {
            $table->string('firma_ruta')->nullable(false)->change();
        });
    }
};
