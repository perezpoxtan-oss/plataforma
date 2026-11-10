<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos (SEGCAT: turnos, turnos_hoteles).
 *
 * Cambios de diseño:
 * - SEGCAT no tenía llave primaria en turnos_hoteles (se podían duplicar filas)
 *   y un turno recién creado quedaba en cero sedes. Aquí se guarda en positivo
 *   como en Departamentos: "todas las sedes" (incluye las futuras) o la lista
 *   explícita en sede_turno, con llave primaria compuesta.
 * - Un turno puede cruzar la medianoche (22:00 a 06:00); lo único inválido es
 *   que empiece y termine a la misma hora (se valida en el controlador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 50);
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->boolean('todas_las_sedes')->default(true);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('sede_turno', function (Blueprint $table) {
            $table->foreignId('turno_id')->constrained('turnos')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->primary(['turno_id', 'sede_id']);
            $table->index('sede_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sede_turno');
        Schema::dropIfExists('turnos');
    }
};
