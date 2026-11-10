<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos, fase 2 (ADR-0009): evaluaciones de las entrevistas.
 *
 *  - tipo rh: entrevista de filtro de Recursos Humanos (canalizar | considerar | rechazar);
 *  - tipo departamento: la del entrevistador asignado (elegir | considerar | segunda_entrevista | rechazar);
 *  - criterios: {«criterio» => 1..5} con los criterios configurados por la
 *    empresa (Recepción → Ajustes); promedio de esas calificaciones;
 *  - numero: 1.ª, 2.ª entrevista de la postulación.
 *
 * Los criterios viven en empresas.preferencias['recepcion']['criterios'] (sin el
 * dato = los cinco de siempre): no necesitan columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluaciones_candidato')) {
            return;
        }
        Schema::create('evaluaciones_candidato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('tipo', 15); // rh | departamento
            $table->foreignId('evaluador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entrevista_en')->nullable();
            $table->json('criterios')->nullable();
            $table->decimal('promedio', 3, 2)->nullable();
            $table->text('comentario')->nullable();
            $table->string('resultado', 20);
            $table->unsignedTinyInteger('numero')->default(1);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['postulacion_id', 'tipo']);
            $table->index(['empresa_id', 'sede_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluaciones_candidato');
    }
};
