<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Altas pendientes de verificar (ADR-0006): lo que la caseta registra desde
 * Operación en un padrón (vehículo, empresa externa, persona) y que no existía
 * se puede usar de inmediato, pero queda "pendiente de verificar" hasta que
 * alguien con permiso de editar el padrón lo acepta, lo rechaza o lo une con
 * el registro correcto.
 *
 *  - verificacion:     pendiente | verificado | rechazado (lo existente = verificado)
 *  - verificado_por / verificado_en: quién y cuándo aceptó, rechazó o unió
 *  - motivo_rechazo:   por qué se rechazó (o con quién se unió)
 *  - fusionado_en_id:  al unir, el registro que lo sustituye
 *  - origen_alta:      pantalla de Operación donde nació (accesos, transporte...)
 *  - sede_alta_id:     sede donde se registró (sin llave foránea: es un dato
 *                      histórico y no debe impedir eliminar una sede)
 */
return new class extends Migration
{
    private const TABLAS = ['vehiculos', 'proveedores', 'personas'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->string('verificacion', 12)->default('verificado')->after('activo');
                $table->unsignedBigInteger('verificado_por')->nullable()->after('verificacion');
                $table->timestamp('verificado_en')->nullable()->after('verificado_por');
                $table->string('motivo_rechazo', 255)->nullable()->after('verificado_en');
                $table->foreignId('fusionado_en_id')->nullable()->after('motivo_rechazo')->constrained($tabla)->nullOnDelete();
                $table->string('origen_alta', 20)->nullable()->after('fusionado_en_id');
                $table->unsignedBigInteger('sede_alta_id')->nullable()->after('origen_alta');
                $table->index(['empresa_id', 'verificacion'], $tabla.'_empresa_verificacion_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropIndex($tabla.'_empresa_verificacion_index');
                $table->dropConstrainedForeignId('fusionado_en_id');
                $table->dropColumn(['verificacion', 'verificado_por', 'verificado_en', 'motivo_rechazo', 'origen_alta', 'sede_alta_id']);
            });
        }
    }
};
