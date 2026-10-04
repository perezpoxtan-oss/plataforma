<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colaboradores (SEGCAT: colaboradores, colaboradores_hoteles y
 * usuarios.id_colaborador).
 *
 * Cambios de diseño respecto a SEGCAT:
 *  - num_empleado, curp, rfc y nss eran UNIQUE en toda la base: dos empresas
 *    no podían tener al empleado "100". Ahora son únicos dentro de cada empresa.
 *  - El colaborador siempre pertenece a una empresa (SEGCAT permitía
 *    "Sin Empresa Asignada" y heredarla del puesto).
 *  - Sede física, departamento y puesto son llaves foráneas reales.
 *  - Sedes adicionales en colaborador_sede (sin repetir la sede física).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // Sede física; nula = corporativo (todas las sedes), como en SEGCAT
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->string('num_empleado', 20);
            $table->string('nombre', 60);
            $table->string('apellido_paterno', 60);
            $table->string('apellido_materno', 60)->nullable();
            $table->string('telefono', 15)->nullable();
            // Datos personales (permiso colaboradores.datos_personales)
            $table->date('fecha_nacimiento')->nullable();
            $table->string('lugar_nacimiento', 40)->nullable();
            $table->string('nacionalidad', 40)->nullable()->default('Mexicana');
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable();
            $table->string('correo_personal', 150)->nullable();
            $table->text('direccion_completa')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'num_empleado']);
            $table->unique(['empresa_id', 'curp']);
            $table->unique(['empresa_id', 'rfc']);
            $table->unique(['empresa_id', 'nss']);
            $table->index(['empresa_id', 'sede_id']);
            $table->index(['empresa_id', 'apellido_paterno', 'nombre']);
        });

        Schema::create('colaborador_sede', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->primary(['colaborador_id', 'sede_id']);
            $table->index('sede_id');
        });

        // Vínculo cuenta ↔ colaborador (SEGCAT: usuarios.id_colaborador). Un colaborador, una cuenta.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('numero_colaborador')
                ->constrained('colaboradores')->nullOnDelete();
            $table->unique('colaborador_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['colaborador_id']);
            $table->dropUnique(['colaborador_id']);
            $table->dropColumn('colaborador_id');
        });

        Schema::dropIfExists('colaborador_sede');
        Schema::dropIfExists('colaboradores');
    }
};
