<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 8 · C-44, fase 1 de la propuesta de credencialización (aprobada el
 * 12 oct): foto, vigencia y el código que lleva impreso cada credencial.
 *
 *  - colaboradores.foto_credencial_ruta: la foto de la credencial (disco
 *    privado, recortada a 3:4). Es dato personal del personal (laboral).
 *  - credenciales.vence_en: fecha de vencimiento según la regla de la empresa
 *    (vacía = sin vencimiento, la regla de fábrica).
 *  - credenciales.codigo_qr: el código del colaborador que lleva impreso esa
 *    credencial; al reponerla el colaborador recibe otro y la anterior deja
 *    de ser vigente al instante.
 *
 * down(): quita las tres columnas. Las fotos guardadas quedan en el disco
 * privado sin referencia (no se borran: un segundo up() no las recupera).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('foto_credencial_ruta', 255)->nullable();
        });
        Schema::table('credenciales', function (Blueprint $table) {
            $table->date('vence_en')->nullable();
            $table->string('codigo_qr', 32)->nullable();
            $table->index(['empresa_id', 'vence_en']);
        });
    }

    public function down(): void
    {
        Schema::table('credenciales', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'vence_en']);
        });
        Schema::table('credenciales', function (Blueprint $table) {
            $table->dropColumn(['vence_en', 'codigo_qr']);
        });
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('foto_credencial_ruta');
        });
    }
};
