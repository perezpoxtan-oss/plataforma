<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 5 de ajustes (QA del dueño):
 *  - LL-03: el nombre de la llave es único POR SEDE (dos sedes pueden tener
 *    una "HDC-101"). Se crea el índice nuevo antes de quitar el anterior
 *    (MariaDB necesita un índice que empiece por empresa_id para su llave
 *    foránea).
 *  - LL-05: costo de reposición de la llave, fijo o variable.
 *  - LL-04: firmas del voucher de reposición en dos modalidades: digital
 *    (firmas guardadas en el disco privado) o física (impresa, firmada a
 *    mano y marcada como "firmado en papel", con la hoja escaneada opcional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llaves', function (Blueprint $table) {
            $table->unique(['empresa_id', 'sede_id', 'nomenclatura'], 'llaves_empresa_sede_nomenclatura_unique');
        });
        Schema::table('llaves', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'nomenclatura']);
            $table->decimal('costo_reposicion', 10, 2)->nullable()->after('fecha_caducidad'); // fijo, si se conoce
            $table->boolean('costo_variable')->default(false)->after('costo_reposicion');   // se captura en cada baja
        });

        Schema::table('vouchers_reposicion', function (Blueprint $table) {
            $table->string('firma_modo', 10)->nullable()->after('colaborador_id');   // digital | fisica
            $table->string('firma_seguridad')->nullable()->after('firma_modo');      // ruta en el disco privado
            $table->string('firma_responsable')->nullable()->after('firma_seguridad');
            $table->timestamp('firmado_papel_en')->nullable()->after('firma_responsable');
            $table->foreignId('firmado_papel_por')->nullable()->after('firmado_papel_en')->constrained('users')->nullOnDelete();
            $table->string('hoja_firmada')->nullable()->after('firmado_papel_por');  // hoja escaneada (privada)
        });
    }

    public function down(): void
    {
        Schema::table('vouchers_reposicion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('firmado_papel_por');
            $table->dropColumn(['firma_modo', 'firma_seguridad', 'firma_responsable', 'firmado_papel_en', 'hoja_firmada']);
        });

        Schema::table('llaves', function (Blueprint $table) {
            $table->unique(['empresa_id', 'nomenclatura']);
        });
        Schema::table('llaves', function (Blueprint $table) {
            $table->dropUnique('llaves_empresa_sede_nomenclatura_unique');
            $table->dropColumn(['costo_reposicion', 'costo_variable']);
        });
    }
};
