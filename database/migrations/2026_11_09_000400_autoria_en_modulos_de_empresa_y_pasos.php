<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md, «Autoría»):
 * creado_por / actualizado_por donde la regla lo exige y faltaban:
 * - empresa_modulos: qué módulos tiene la empresa y su nombre en el menú
 *   (lo cambian el Super Administrador y el administrador de la empresa);
 * - procedimiento_pasos: el texto de cada paso de un procedimiento (se
 *   reescribe al editar la versión).
 * Las filas existentes quedan vacías (no se sabe quién las capturó; la
 * Bitácora de auditoría sí lo tiene). Las demás tablas revisadas ya las
 * tenían o son bitácoras de solo agregar con su propio autor (user_id).
 *
 * Idempotente. down() quita las columnas.
 */
return new class extends Migration
{
    public const TABLAS = ['empresa_modulos', 'procedimiento_pasos'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasColumn($tabla, 'creado_por')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'creado_por')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['creado_por']);
                $table->dropForeign(['actualizado_por']);
            });
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['creado_por', 'actualizado_por']);
            });
        }
    }
};
