<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-15 (DD-08): al dar salida, dos casillas ya
 * marcadas: «Devolvió el gafete» y «Le devolví su identificación».
 *
 *  - accesos.gafete_no_regreso y acompanantes_acceso.gafete_no_regreso: se
 *    desmarcó la casilla (el gafete quedó NO REGRESÓ).
 *  - accesos.identificacion_devuelta: null = salida de antes (o sin
 *    identificación en custodia); verdadero/falso = lo que confirmó la caseta.
 *
 * down() quita las columnas (los gafetes que quedaron NO REGRESÓ siguen así:
 * los regresa a DISPONIBLE la migración de estados al revertirse).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accesos', 'gafete_no_regreso')) {
            Schema::table('accesos', function (Blueprint $t) {
                $t->boolean('gafete_no_regreso')->default(false)->after('gafete_texto');
                $t->boolean('identificacion_devuelta')->nullable()->after('identificacion');
            });
        }
        if (! Schema::hasColumn('acompanantes_acceso', 'gafete_no_regreso')) {
            Schema::table('acompanantes_acceso', function (Blueprint $t) {
                $t->boolean('gafete_no_regreso')->default(false)->after('gafete_texto');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accesos', 'gafete_no_regreso')) {
            Schema::table('accesos', function (Blueprint $t) {
                $t->dropColumn(['gafete_no_regreso', 'identificacion_devuelta']);
            });
        }
        if (Schema::hasColumn('acompanantes_acceso', 'gafete_no_regreso')) {
            Schema::table('acompanantes_acceso', function (Blueprint $t) {
                $t->dropColumn('gafete_no_regreso');
            });
        }
    }
};
