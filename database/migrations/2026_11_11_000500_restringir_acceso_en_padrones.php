<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-13 (DD-07): «Dar de baja» (ya no se usa; si vuelve,
 * la caseta ofrece reactivarlo) se separa de «Restringir acceso» (con motivo y
 * vigencia; la caseta no puede registrarlo).
 *
 *  - personas, vehiculos y proveedores: restringido_en, restringido_hasta
 *    (vacío = sin fecha de fin), restringido_motivo, restringido_por.
 *  - accesos.aviso_padron: lo que ve quien autoriza («Empresa nueva, pendiente
 *    de verificar», «Parecida a una restringida», «No opera en esta sede»).
 *  - Datos: las empresas externas que hoy están dadas de baja eran el «veto»:
 *    pasan a restringidas (activas) para conservarlo.
 *
 * down(): las restringidas vuelven a quedar dadas de baja (el veto de antes) y
 * se quitan las columnas.
 */
return new class extends Migration
{
    private const TABLAS = ['personas', 'vehiculos', 'proveedores'];

    private const MOTIVO_CONVERSION = 'Estaba dada de baja (vetada) antes de separar «Dar de baja» de «Restringir acceso».';

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasColumn($tabla, 'restringido_en')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $t) {
                $t->timestamp('restringido_en')->nullable();
                $t->date('restringido_hasta')->nullable();
                $t->string('restringido_motivo', 255)->nullable();
                $t->foreignId('restringido_por')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('accesos', 'aviso_padron')) {
            Schema::table('accesos', function (Blueprint $t) {
                $t->string('aviso_padron', 255)->nullable();
            });
        }

        DB::table('proveedores')->where('activo', false)->whereNull('fusionado_en_id')->where('verificacion', '!=', 'rechazado')
            ->update(['activo' => true, 'restringido_en' => now(), 'restringido_motivo' => self::MOTIVO_CONVERSION, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('proveedores', 'restringido_en')) {
            DB::table('proveedores')->whereNotNull('restringido_en')->update(['activo' => false, 'updated_at' => now()]);
        }
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'restringido_en')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $t) {
                $t->dropForeign(['restringido_por']);
                $t->dropColumn(['restringido_en', 'restringido_hasta', 'restringido_motivo', 'restringido_por']);
            });
        }
        if (Schema::hasColumn('accesos', 'aviso_padron')) {
            Schema::table('accesos', function (Blueprint $t) {
                $t->dropColumn('aviso_padron');
            });
        }
    }
};
