<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas y áreas como un solo árbol (antes 5 tablas en SEGCAT: edificios,
 * secciones, areas_especificas, habitacion_areas, habitacion_area_elementos).
 *
 * Niveles: edificio > area (piso) > area_especifica (habitación, oficina...)
 *          > subarea (recámara, baño...) > elemento (cama, extintor...).
 * Cualquier módulo operativo se liga con un solo espacio_id; "todo lo que hay
 * debajo de X" es ruta LIKE '/12/%'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tipos por nivel: los del sistema (empresa_id nulo) y los propios de cada empresa
        Schema::create('tipos_espacio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->string('nivel', 20);
            $table->string('nombre', 60);
            $table->string('icono', 60)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'nivel', 'nombre']);
        });

        // Agrupaciones de habitaciones dentro de una sede (las "Secciones" de SEGCAT)
        Schema::create('grupos_espacio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->string('nombre', 50);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['sede_id', 'nombre']);
        });

        Schema::create('espacios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('padre_id')->nullable()->constrained('espacios')->restrictOnDelete();
            $table->string('nivel', 20);
            $table->foreignId('tipo_espacio_id')->nullable()->constrained('tipos_espacio')->nullOnDelete();
            $table->foreignId('grupo_espacio_id')->nullable()->constrained('grupos_espacio')->nullOnDelete();
            $table->string('nombre', 100);
            $table->string('codigo', 20)->nullable();
            // Ruta materializada: '/12/45/301/' (ancestros + el propio id)
            $table->string('ruta', 255)->default('/');
            $table->unsignedTinyInteger('profundidad')->default(0);
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->index(['sede_id', 'nivel']);
            $table->index('ruta');
            $table->index(['padre_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espacios');
        Schema::dropIfExists('grupos_espacio');
        Schema::dropIfExists('tipos_espacio');
    }
};
