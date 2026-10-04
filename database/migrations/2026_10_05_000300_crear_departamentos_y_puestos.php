<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departamentos y Puestos (SEGCAT: departamentos, hoteles_departamentos,
 * puestos, departamentos_puestos).
 *
 * Cambio de diseño: SEGCAT guardaba las sedes donde un departamento NO aplica
 * (hoteles_departamentos.estatus = 0) y reconstruía la tabla en cada edición.
 * Aquí se guarda en positivo: "todas las sedes" (incluye las futuras) o la
 * lista explícita de sedes donde aplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->boolean('todas_las_sedes')->default(true);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('departamento_sede', function (Blueprint $table) {
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->primary(['departamento_id', 'sede_id']);
            $table->index('sede_id');
        });

        Schema::create('puestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('tipo', 15)->default('operativo'); // operativo | administrativo
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'tipo']);
        });

        Schema::create('departamento_puesto', function (Blueprint $table) {
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            $table->primary(['departamento_id', 'puesto_id']);
            $table->index('puesto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departamento_puesto');
        Schema::dropIfExists('puestos');
        Schema::dropIfExists('departamento_sede');
        Schema::dropIfExists('departamentos');
    }
};
