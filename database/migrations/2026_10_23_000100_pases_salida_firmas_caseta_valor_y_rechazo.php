<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pases de salida: firmas de caseta configurables, circuito por valor y
 * rechazo configurable (ADR-0012).
 *
 *  - pases_salida.firmas_caseta (JSON): las firmas que pide cada movimiento de
 *    caseta en ESE pase (salida, recepcion, salida_regreso, regreso =>
 *    dos | seguridad | ninguna), copiadas de la configuración al enviarlo. Los
 *    pases anteriores quedan en null = dos firmas en cada movimiento (como
 *    siempre).
 *  - pases_salida_articulos.valor_unitario: valor de un artículo (del padrón de
 *    Equipos o capturado por el solicitante). Opcional.
 *  - pases_salida_pasos.valor_minimo / valor_maximo: condición de valor del
 *    paso (null = sin condición, como hoy).
 *  - pases_salida_aprobaciones: la misma condición copiada al pase y
 *    conservada_de_ronda (la firma viene de una ronda anterior al retomar).
 *
 * La configuración de la empresa (firmas en caseta y rechazo) vive en
 * empresas.preferencias['pases_salida']: sin tabla nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pases_salida', function (Blueprint $table) {
            if (! Schema::hasColumn('pases_salida', 'firmas_caseta')) {
                $table->json('firmas_caseta')->nullable()->after('ronda');
            }
        });

        Schema::table('pases_salida_articulos', function (Blueprint $table) {
            if (! Schema::hasColumn('pases_salida_articulos', 'valor_unitario')) {
                $table->decimal('valor_unitario', 12, 2)->nullable()->after('descripcion');
            }
        });

        Schema::table('pases_salida_pasos', function (Blueprint $table) {
            if (! Schema::hasColumn('pases_salida_pasos', 'valor_minimo')) {
                $table->decimal('valor_minimo', 14, 2)->nullable()->after('motivos');
                $table->decimal('valor_maximo', 14, 2)->nullable()->after('valor_minimo');
            }
        });

        Schema::table('pases_salida_aprobaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('pases_salida_aprobaciones', 'valor_minimo')) {
                $table->decimal('valor_minimo', 14, 2)->nullable()->after('obligatorio');
                $table->decimal('valor_maximo', 14, 2)->nullable()->after('valor_minimo');
            }
            if (! Schema::hasColumn('pases_salida_aprobaciones', 'conservada_de_ronda')) {
                $table->unsignedSmallInteger('conservada_de_ronda')->nullable()->after('firma_id');
            }
        });
    }

    public function down(): void
    {
        // Columnas sin índices ni llaves foráneas: se quitan sin tocar los índices que sostienen llaves
        Schema::table('pases_salida_aprobaciones', function (Blueprint $table) {
            foreach (['valor_minimo', 'valor_maximo', 'conservada_de_ronda'] as $columna) {
                if (Schema::hasColumn('pases_salida_aprobaciones', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('pases_salida_pasos', function (Blueprint $table) {
            foreach (['valor_minimo', 'valor_maximo'] as $columna) {
                if (Schema::hasColumn('pases_salida_pasos', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('pases_salida_articulos', function (Blueprint $table) {
            if (Schema::hasColumn('pases_salida_articulos', 'valor_unitario')) {
                $table->dropColumn('valor_unitario');
            }
        });

        Schema::table('pases_salida', function (Blueprint $table) {
            if (Schema::hasColumn('pases_salida', 'firmas_caseta')) {
                $table->dropColumn('firmas_caseta');
            }
        });
    }
};
