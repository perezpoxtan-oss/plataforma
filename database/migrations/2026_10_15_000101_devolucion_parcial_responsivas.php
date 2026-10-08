<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 8 (RS-04): devolución parcial de un lote de Responsivas.
 *
 * Cada equipo del lote se recibe por separado con su estado al recibir:
 * ok | danado | faltante (además de "baja", el que ya estaba de baja). Se
 * guarda quién lo recibió, una nota y, si se dio de baja con voucher de
 * reposición, el voucher. El lote sigue EN CAMPO hasta que regresa el último.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos_responsiva', function (Blueprint $table) {
            $table->string('nota_devolucion', 500)->nullable()->after('estado_devolucion');
            $table->foreignId('recibido_por')->nullable()->after('devuelto_en')->constrained('users')->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->after('recibido_por')->constrained('vouchers_reposicion')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipos_responsiva', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropConstrainedForeignId('recibido_por');
            $table->dropColumn('nota_devolucion');
        });
    }
};
