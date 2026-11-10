<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puestos: se quita la clasificación Operativo / Administrativo (decisión del
 * dueño del proyecto, mapa de procesos). Ninguna regla la usaba; solo el
 * formulario, las píldoras de filtro y la tarjeta.
 *
 * El índice (empresa_id, tipo) no sostiene la llave foránea de empresa_id: la
 * única (empresa_id, nombre) también empieza por empresa_id.
 *
 * down(): regresa la columna con «operativo» para todos (el valor por
 * omisión original); la clasificación anterior no se recupera.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('puestos', 'tipo')) {
            return;
        }

        Schema::table('puestos', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'tipo']);
        });
        Schema::table('puestos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('puestos', 'tipo')) {
            return;
        }

        Schema::table('puestos', function (Blueprint $table) {
            $table->string('tipo', 15)->default('operativo')->after('nombre'); // operativo | administrativo
        });
        Schema::table('puestos', function (Blueprint $table) {
            $table->index(['empresa_id', 'tipo']);
        });
    }
};
