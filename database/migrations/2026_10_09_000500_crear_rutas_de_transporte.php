<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas de transporte de personal (SEGCAT: cat_rutas_transporte,
 * ruta_horarios, ruta_paraderos, cat_paraderos).
 *
 *  - paraderos: catálogo de paradas POR SEDE (dos sedes no comparten paraderos).
 *  - rutas: llegada a la sede o salida de la sede, con turno y transportista.
 *    hora_inicio, hora_fin y dias son un resumen calculado de sus horarios
 *    (la fuente de verdad es ruta_horarios).
 *  - ruta_horarios: 1..n por ruta (p. ej. "Lunes a viernes" y "Fin de
 *    semana"), cada uno con sus días, su hora de inicio y de llegada (puede
 *    cruzar la medianoche).
 *  - ruta_paradas: los paraderos de cada horario, en orden y con su hora.
 *
 * Correcciones: el paradero de cada horario se liga al catálogo por id (en
 * SEGCAT se copiaba el nombre, y al renombrar un paradero las rutas seguían
 * con el nombre viejo); los días se guardan siempre explícitos
 * ("LU,MA,MI,JU,VI,SA,DO" en lugar de NULL = todos); sentido como clave de
 * texto validada en la aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paraderos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->string('nombre', 150);                           // en mayúsculas, sin espacios dobles
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['sede_id', 'nombre']);
            $table->index(['empresa_id', 'sede_id']);
        });

        Schema::create('rutas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->string('sentido', 10);                           // llegada | salida
            $table->string('nombre', 150);
            $table->foreignId('turno_id')->constrained('turnos');
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->decimal('costo_maximo_taxi', 10, 2)->nullable(); // vacío = sin tope
            $table->time('hora_inicio')->nullable();                 // resumen: el horario más temprano
            $table->time('hora_fin')->nullable();
            $table->string('dias', 20)->nullable();                  // resumen: unión de días de sus horarios
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['sede_id', 'sentido', 'nombre']);
            $table->index(['empresa_id', 'sede_id', 'activo']);
        });

        Schema::create('ruta_horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->string('dias', 20);                              // LU,MA,MI,JU,VI,SA,DO (siempre explícitos)
            $table->time('hora_inicio');
            $table->time('hora_fin');                                // menor que el inicio = llega al día siguiente
            $table->unsignedSmallInteger('orden')->default(1);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->index(['ruta_id', 'orden']);
        });

        Schema::create('ruta_paradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('ruta_horario_id')->constrained('ruta_horarios')->cascadeOnDelete();
            $table->foreignId('paradero_id')->constrained('paraderos');
            $table->time('hora')->nullable();                        // hora tentativa (opcional)
            $table->unsignedSmallInteger('orden')->default(1);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->index(['ruta_horario_id', 'orden']);
            $table->index('paradero_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_paradas');
        Schema::dropIfExists('ruta_horarios');
        Schema::dropIfExists('rutas');
        Schema::dropIfExists('paraderos');
    }
};
