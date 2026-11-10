<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de accesos (SEGCAT: bitacora_accesos y bitacora_acompaniantes).
 *
 *  - accesos: cada ingreso por caseta (colaborador, huésped, personal externo,
 *    proveedor, contratista o emergencia), su estado (pendiente → en sitio →
 *    finalizado) y, para huéspedes, proveedores y contratistas, las salidas
 *    temporales (tour) y sus regresos, ligadas al ingreso original.
 *  - acompanantes_acceso: quienes llegan con el titular, con su propio
 *    gafete y su salida individual (y temporal, para proveedor/contratista).
 *
 * Correcciones respecto a SEGCAT:
 *  - enums de MySQL pasan a claves de texto validadas en la aplicación;
 *  - claves foráneas reales (sede, colaborador, persona, proveedor, gafete,
 *    vehículo, zona, departamento, usuarios); SEGCAT no tenía ninguna;
 *  - el host y "a quién visita" se ligan a un colaborador (antes texto);
 *  - fechas en hora universal (se muestran en la hora local de la sede);
 *  - índices para la lista "en sitio", el historial, los gafetes en uso y
 *    la ocupación de cada zona de estacionamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('tipo', 20);                                   // colaborador | huesped | visitante | proveedor | contratista | emergencia
            $table->string('movimiento', 20)->default('entrada');         // entrada | salida_temporal | regreso
            $table->foreignId('acceso_origen_id')->nullable()->constrained('accesos')->nullOnDelete();
            $table->string('estado', 20)->default('en_sitio');            // pendiente | en_sitio | finalizado
            $table->string('nombre', 150);                                // titular (o la unidad de emergencia)
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->string('empresa_procedencia', 150)->nullable();       // empresa o agencia, como se capturó
            $table->string('motivo_visita', 20)->nullable();              // rh | colaborador (personal externo)
            $table->foreignId('visita_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('persona_visita', 150)->nullable();
            $table->foreignId('host_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('identificacion', 20)->nullable();             // ine | licencia | pasaporte (custodiada en caseta)
            $table->foreignId('gafete_id')->nullable()->constrained('gafetes')->nullOnDelete();
            $table->string('gafete_texto', 50)->nullable();               // nomenclatura al momento del ingreso
            $table->string('modo_arribo', 10)->nullable();                // a_pie | moto | auto
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();
            $table->string('placas', 20)->nullable();
            $table->foreignId('zona_estacionamiento_id')->nullable()->constrained('zonas_estacionamiento')->nullOnDelete();
            $table->string('conductor', 150)->nullable();                 // quien maneja si no es el huésped (taxi, app)
            $table->unsignedTinyInteger('num_acompanantes')->default(0);
            // Huésped
            $table->boolean('tiene_reserva')->nullable();
            $table->string('numero_reserva', 50)->nullable();
            $table->string('tipo_pase', 12)->nullable();                  // estancia | daypass | nightpass
            $table->string('habitacion', 20)->nullable();
            // Proveedor / contratista
            $table->string('tipo_visita', 20)->nullable();                // cortesia | levantamiento | ejecucion
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->string('area_trabajo', 150)->nullable();
            $table->string('actividad', 500)->nullable();
            // Emergencia
            $table->string('tipo_emergencia', 30)->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamp('entrada_at');
            $table->timestamp('autorizado_at')->nullable();
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('salida_at')->nullable();
            $table->foreignId('salida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'sede_id', 'estado']);
            $table->index(['empresa_id', 'estado', 'salida_at']);
            $table->index(['gafete_id', 'estado']);
            $table->index(['zona_estacionamiento_id', 'estado']);
        });

        Schema::create('acompanantes_acceso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('acceso_id')->constrained('accesos')->cascadeOnDelete();
            $table->string('nombre', 150)->nullable();
            $table->string('identificacion', 20)->nullable();
            $table->foreignId('gafete_id')->nullable()->constrained('gafetes')->nullOnDelete();
            $table->string('gafete_texto', 50)->nullable();
            $table->timestamp('salida_at')->nullable();
            $table->foreignId('salida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('salida_temporal_at')->nullable();
            $table->foreignId('salida_temporal_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('regreso_temporal_at')->nullable();
            $table->foreignId('regreso_temporal_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['gafete_id', 'salida_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acompanantes_acceso');
        Schema::dropIfExists('accesos');
    }
};
