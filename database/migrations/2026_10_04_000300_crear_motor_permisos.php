<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de areas, modulos, acciones, roles y permisos.
 * Todo se administra desde la interfaz; el codigo solo declara
 * que accion necesita cada pantalla (modulo.accion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 100);
            $table->string('icono', 60)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('padre_id')->nullable()->constrained('modulos')->restrictOnDelete();
            $table->string('clave', 60)->unique();
            $table->string('nombre', 120);
            $table->string('descripcion', 255)->nullable();
            $table->string('icono', 60)->nullable();
            $table->string('ruta', 120)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            // sistema = logica programada; configurable = creado con el constructor de formularios
            $table->string('tipo', 20)->default('sistema');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('acciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 80);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('modulo_acciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->foreignId('accion_id')->constrained('acciones')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['modulo_id', 'accion_id']);
        });

        // Modulos contratados / activos por empresa
        Schema::create('empresa_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('modulo_id')->constrained('modulos')->restrictOnDelete();
            $table->string('nombre_visible', 120)->nullable();
            $table->unsignedSmallInteger('orden')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'modulo_id']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Nulo = plantilla de la plataforma, que se copia a cada empresa
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->string('descripcion', 255)->nullable();
            // Menor numero = mayor jerarquia
            $table->unsignedSmallInteger('nivel_jerarquia')->default(100);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('rol_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('modulo_accion_id')->constrained('modulo_acciones')->cascadeOnDelete();
            // propios | sede | empresa
            $table->string('alcance', 10)->default('sede');
            $table->timestamps();

            $table->unique(['rol_id', 'modulo_accion_id']);
        });

        Schema::create('usuario_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            // Nulo = el rol aplica en todas las sedes de la empresa
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'rol_id', 'sede_id']);
        });

        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('evento', 60);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
        });

        // Identidad y parametros generales de la plataforma (clave/valor)
        Schema::create('configuracion_plataforma', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 80)->unique();
            $table->json('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_plataforma');
        Schema::dropIfExists('auditoria');
        Schema::dropIfExists('usuario_roles');
        Schema::dropIfExists('rol_permisos');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('empresa_modulos');
        Schema::dropIfExists('modulo_acciones');
        Schema::dropIfExists('acciones');
        Schema::dropIfExists('modulos');
        Schema::dropIfExists('areas');
    }
};
