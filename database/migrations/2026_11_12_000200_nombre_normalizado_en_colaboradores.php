<?php

use App\Models\Colaborador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión de la Etapa 3a (C-27): colaboradores.nombre_normalizado («nombre
 * apellido paterno» sin mayúsculas, acentos ni espacios de más), con índice
 * por empresa. Sirve para encontrar homónimos al importar y en el alta
 * rápida sin recortar la lista (antes se leían 200 por inicial del apellido).
 * El modelo la llena al guardar; aquí se llena la de los que ya existen.
 *
 * down(): quita el índice y la columna (no se pierde nada: se calcula del nombre).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('colaboradores', 'nombre_normalizado')) {
            Schema::table('colaboradores', function (Blueprint $t) {
                $t->string('nombre_normalizado', 190)->nullable()->after('apellido_materno');
                $t->index(['empresa_id', 'nombre_normalizado'], 'colaboradores_empresa_nombre_normalizado_index');
            });
        }
        DB::table('colaboradores')->select(['id', 'nombre', 'apellido_paterno'])->orderBy('id')->chunkById(500, function ($filas) {
            foreach ($filas as $f) {
                DB::table('colaboradores')->where('id', $f->id)->update(['nombre_normalizado' => Colaborador::normalizarNombre($f->nombre, $f->apellido_paterno)]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('colaboradores', 'nombre_normalizado')) {
            Schema::table('colaboradores', function (Blueprint $t) {
                $t->dropIndex('colaboradores_empresa_nombre_normalizado_index');
                $t->dropColumn('nombre_normalizado');
            });
        }
    }
};
