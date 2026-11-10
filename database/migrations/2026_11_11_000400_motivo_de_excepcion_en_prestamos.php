<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-17 (DD-09): al prestar se revisan la caducidad, el
 * horario, el departamento o puesto objetivo y el responsable permanente de
 * la llave. Si no cumple, la caseta puede «Prestar de todos modos» con un
 * motivo, que queda aquí (y en la auditoría con lo que no cumplía).
 * down() quita la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prestamos_llaves', 'excepcion_motivo')) {
            Schema::table('prestamos_llaves', function (Blueprint $t) {
                $t->string('excepcion_motivo', 255)->nullable()->after('folio_garantia');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prestamos_llaves', 'excepcion_motivo')) {
            Schema::table('prestamos_llaves', function (Blueprint $t) {
                $t->dropColumn('excepcion_motivo');
            });
        }
    }
};
