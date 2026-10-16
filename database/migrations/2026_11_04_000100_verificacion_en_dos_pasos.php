<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación en dos pasos (TOTP, RFC 6238; docs/tecnico/dos-pasos.md).
 *
 *  - two_factor_secret: clave secreta en base32, CIFRADA con la llave de la
 *    aplicación (cast «encrypted»). Nunca se muestra después de activarla.
 *  - two_factor_recovery_codes: huellas (HMAC-SHA256) de los 10 códigos de
 *    respaldo de un solo uso; los códigos en claro se muestran una sola vez.
 *  - two_factor_confirmado_en: cuándo se activó (nulo = no la tiene).
 *  - two_factor_ultimo_paso: último paso de 30 s aceptado, para que el mismo
 *    código no sirva dos veces.
 *
 * Los nombres terminan en «secret» y «recovery_codes»: la exportación de una
 * empresa ya los excluye (ExportacionEmpresa::PATRON_COLUMNA_SENSIBLE).
 *
 * down(): quita las cuatro columnas (sin índices ni llaves foráneas; seguro
 * en MariaDB y SQLite). Quien tenía la verificación activa la pierde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmado_en')->nullable();
            $table->unsignedBigInteger('two_factor_ultimo_paso')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmado_en', 'two_factor_ultimo_paso']);
        });
    }
};
