<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lost & Found (archivo, cierre / entrega, etiqueta y auditoría) y Robo —
 * Seguimiento: lo que les faltaba a las tablas que creó la Bitácora de
 * Novedades.
 *
 *  - lost_found_articulos se vuelve "identificable" (ADR-0005): codigo_qr
 *    aleatorio (va en el QR de la etiqueta de la bolsa) y etiqueta_nfc
 *    opcional. Los artículos que ya existían reciben su código aquí.
 *    cerrado_por: quién registró el cierre (cerrado_en ya existía).
 *  - lost_found_entregas: quién recibió, cuando es un colaborador (lector
 *    universal) o una persona del Padrón de personas (registro rápido). El
 *    nombre se sigue guardando como texto (huéspedes, paqueterías…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_found_articulos', function (Blueprint $table) {
            $table->string('codigo_qr', 32)->nullable()->after('estatus');   // aleatorio: va en el QR de la etiqueta
            $table->string('etiqueta_nfc', 64)->nullable()->after('codigo_qr');
            $table->foreignId('cerrado_por')->nullable()->after('cerrado_en')->constrained('users')->nullOnDelete();
        });

        // Los artículos que ya existían reciben su código (no adivinable)
        DB::table('lost_found_articulos')->whereNull('codigo_qr')->orderBy('id')->select('id')->chunkById(500, function ($filas) {
            foreach ($filas as $fila) {
                DB::table('lost_found_articulos')->where('id', $fila->id)->update(['codigo_qr' => Str::lower(Str::random(24))]);
            }
        });

        Schema::table('lost_found_articulos', function (Blueprint $table) {
            $table->unique('codigo_qr');
            $table->unique(['empresa_id', 'etiqueta_nfc']);
        });

        Schema::table('lost_found_entregas', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('tipo_cierre')->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->after('colaborador_id')->constrained('personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lost_found_entregas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('lost_found_articulos', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'etiqueta_nfc']);
            $table->dropUnique(['codigo_qr']);
            $table->dropConstrainedForeignId('cerrado_por');
            $table->dropColumn(['codigo_qr', 'etiqueta_nfc']);
        });
    }
};
