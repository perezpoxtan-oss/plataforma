<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nucleo multi-empresa: rubros, empresas y sedes.
 * Rubro -> Empresa (cliente / tenant) -> Sede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubros', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 100);
            // Diccionario de terminos visibles por rubro (ej. sede => "Hotel", "Torre")
            $table->json('terminologia')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubro_id')->constrained('rubros')->restrictOnDelete();
            $table->string('nombre_comercial', 150);
            $table->string('razon_social', 200)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('logo_ruta')->nullable();
            $table->string('zona_horaria', 64)->default('America/Mexico_City');
            $table->string('idioma', 5)->default('es');
            $table->string('moneda', 3)->default('MXN');
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre', 150);
            $table->string('direccion', 255)->nullable();
            // Nulo = hereda la zona horaria de la empresa
            $table->string('zona_horaria', 64)->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sedes');
        Schema::dropIfExists('empresas');
        Schema::dropIfExists('rubros');
    }
};
