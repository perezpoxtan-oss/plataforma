<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-02 / DD-05: cuando un cliente baja de plan, los
 * módulos que su plan ya no trae quedan en SOLO LECTURA (empresa_modulos:
 * activo = 0 y solo_lectura = 1): sus datos se siguen consultando,
 * exportando e imprimiendo, pero no se captura nada nuevo. Un módulo
 * quitado a propósito (excepción «módulo quitado») queda apagado del todo.
 *
 * Idempotente. down() quita la columna (los módulos en solo lectura
 * vuelven a quedar apagados, como antes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('empresa_modulos', 'solo_lectura')) {
            return;
        }
        Schema::table('empresa_modulos', function (Blueprint $table) {
            $table->boolean('solo_lectura')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('empresa_modulos', 'solo_lectura')) {
            return;
        }
        Schema::table('empresa_modulos', function (Blueprint $table) {
            $table->dropColumn('solo_lectura');
        });
    }
};
