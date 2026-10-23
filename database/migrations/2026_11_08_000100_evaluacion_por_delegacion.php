<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluación por delegación (docs/tecnico/candidatos.md, «Entrevistar y
 * elegir»). Cuando quien evalúa la entrevista del departamento lo hace por la
 * delegación («No molestar») del entrevistador asignado, se guarda a nombre de
 * quién evaluó: `por_delegacion_de` = el entrevistador titular. Nulo = la
 * evaluó el propio entrevistador (todas las que ya existen).
 *
 * down(): quita la llave foránea y la columna (seguro en MariaDB y SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluaciones_candidato', function (Blueprint $table) {
            $table->foreignId('por_delegacion_de')->nullable()->after('evaluador_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones_candidato', function (Blueprint $table) {
            $table->dropConstrainedForeignId('por_delegacion_de');
        });
    }
};
