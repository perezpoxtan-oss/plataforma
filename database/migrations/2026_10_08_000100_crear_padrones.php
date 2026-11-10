<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Padrones de Seguridad (SEGCAT: cat_empresas_externas, proveedores_hoteles,
 * visitantes_proveedores, vehiculos).
 *
 *  - proveedores: empresas externas (proveedores, contratistas, agencias,
 *    taxis, transportadoras) con las sedes donde operan.
 *  - personas: padrón de personas externas (visitantes, personal de
 *    proveedores y contratistas). Antes "visitantes_proveedores".
 *  - vehiculos: padrón vehicular, con código para la calcomanía QR.
 *
 * Correcciones: enums de MySQL pasan a claves de texto validadas en la
 * aplicación (agregar una categoría ya no exige alterar la tabla); placas y
 * folio de identificación se guardan normalizados (sin espacios ni guiones)
 * para que la unicidad sea real; el vehículo de un colaborador se liga a él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->string('categoria', 40)->default('proveedor');
            $table->string('rfc', 13)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->boolean('todas_las_sedes')->default(true);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'categoria']);
        });

        Schema::create('proveedor_sede', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->primary(['proveedor_id', 'sede_id']);
            $table->index('sede_id');
        });

        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo', 20)->default('visitante');       // visitante | proveedor | contratista
            $table->string('categoria', 30)->default('general');    // general | prospecto_rrhh | familiar
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->string('nombre_completo', 150);
            $table->string('empresa_procedencia', 100)->nullable(); // texto libre si no es un proveedor registrado
            $table->string('tipo_identificacion', 20)->nullable();
            $table->string('folio_identificacion', 50)->nullable(); // normalizado: mayúsculas, sin espacios ni guiones
            $table->string('telefono', 15)->nullable();
            $table->text('motivo_visita')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'folio_identificacion']);
            $table->index(['empresa_id', 'nombre_completo']);
        });

        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('placas', 20);                            // normalizadas: mayúsculas, sin espacios ni guiones
            $table->string('tipo', 30)->nullable();                  // sedan | suv | pickup | autobus | camion_ligero | motocicleta | otro
            $table->string('descripcion_otro', 150)->nullable();
            $table->string('marca', 50)->nullable();
            $table->string('modelo', 60)->nullable();
            $table->string('color', 30)->nullable();
            $table->string('propiedad', 30)->default('propio_visitante');
            $table->unsignedSmallInteger('capacidad')->nullable();
            $table->string('numero_economico', 30)->nullable();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('codigo_qr', 32)->unique();               // aleatorio, no adivinable
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'placas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
        Schema::dropIfExists('personas');
        Schema::dropIfExists('proveedor_sede');
        Schema::dropIfExists('proveedores');
    }
};
