<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Plan de correcciones, Etapa 8 · C-44: las personas del Padrón de personas
 * y las empresas externas se pueden leer con el lector universal (QR, NFC o
 * RFID), como colaboradores y vehículos (ADR-0005).
 *
 *  - `personas` y `proveedores` ganan `codigo_qr` (aleatorio, único; va en el
 *    QR impreso o grabado en la etiqueta) y `etiqueta_nfc` (número de serie
 *    de la tarjeta, opcional, único por empresa).
 *  - Los registros que ya existen reciben su código aleatorio.
 *
 * down(): quita los índices y las dos columnas (los códigos se generan de
 * nuevo al volver a migrar; una etiqueta NFC asignada se pierde y se vuelve
 * a asignar con el lector).
 */
return new class extends Migration
{
    private const TABLAS = ['personas', 'proveedores'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'codigo_qr')) {
                    $table->string('codigo_qr', 32)->nullable();
                }
                if (! Schema::hasColumn($tabla, 'etiqueta_nfc')) {
                    $table->string('etiqueta_nfc', 64)->nullable();
                }
            });

            DB::table($tabla)->whereNull('codigo_qr')->orderBy('id')->select('id')->chunkById(500, function ($filas) use ($tabla) {
                foreach ($filas as $fila) {
                    DB::table($tabla)->where('id', $fila->id)->update(['codigo_qr' => Str::lower(Str::random(24))]);
                }
            });

            Schema::table($tabla, function (Blueprint $table) {
                $table->unique('codigo_qr');
                $table->unique(['empresa_id', 'etiqueta_nfc']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'codigo_qr')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropUnique(['codigo_qr']);
                $table->dropUnique(['empresa_id', 'etiqueta_nfc']);
            });
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['codigo_qr', 'etiqueta_nfc']);
            });
        }
    }
};
