<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número de colaborador de la cuenta (como en SEGCAT). Opcional: las cuentas
 * de soporte no tienen colaborador. Único dentro de cada empresa.
 * Cuando se migre Colaboradores se agregará el vínculo colaborador_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('numero_colaborador', 30)->nullable()->after('username');
            $table->unique(['empresa_id', 'numero_colaborador']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // MariaDB usa este índice compuesto para la llave foránea de empresa_id:
            // antes de quitarlo, la llave necesita su propio índice.
            $table->index('empresa_id');
            $table->dropUnique(['empresa_id', 'numero_colaborador']);
            $table->dropColumn('numero_colaborador');
        });
    }
};
