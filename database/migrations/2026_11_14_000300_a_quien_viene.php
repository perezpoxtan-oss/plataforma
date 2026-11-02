<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 5 · C-09 (DD-12): un solo «¿A quién viene?».
 *
 *  - accesos.visita_colaborador_id pasa a ser «a quién viene» para TODOS los
 *    tipos (antes solo la visita; el proveedor y el contratista usaban
 *    host_colaborador_id). Los registros de proveedor y contratista copian su
 *    host aquí, sin perder nada: host_colaborador_id se conserva y se sigue
 *    llenando con el mismo valor, para que una versión anterior lo lea igual.
 *  - autorizaciones.colaborador_id: la persona citada que autoriza (si tiene
 *    cuenta); su departamento queda como suplente.
 *
 * down(): las filas de proveedor y contratista cuyo «a quién viene» es su
 * host vuelven a tenerlo solo en host_colaborador_id; se quita la columna de
 * autorizaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('colaborador_id')->nullable()->after('departamento_id');
            $table->index('colaborador_id');
        });
        Restricciones::agregarForanea('autorizaciones', 'colaborador_id', 'colaboradores', 'set null');

        DB::table('accesos')->whereIn('tipo', ['proveedor', 'contratista'])->whereNotNull('host_colaborador_id')->whereNull('visita_colaborador_id')
            ->update(['visita_colaborador_id' => DB::raw('host_colaborador_id')]);
    }

    public function down(): void
    {
        DB::table('accesos')->whereIn('tipo', ['proveedor', 'contratista'])->whereNotNull('host_colaborador_id')
            ->whereColumn('visita_colaborador_id', 'host_colaborador_id')->update(['visita_colaborador_id' => null]);

        Restricciones::quitarForanea('autorizaciones', 'colaborador_id');
        Schema::table('autorizaciones', fn (Blueprint $table) => $table->dropIndex(['colaborador_id']));
        Schema::table('autorizaciones', fn (Blueprint $table) => $table->dropColumn('colaborador_id'));
    }
};
