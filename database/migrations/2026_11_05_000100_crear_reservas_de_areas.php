<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de áreas (docs/tecnico/reservas.md).
 *
 * Las áreas reservables SALEN de Zonas y edificios: `reserva_areas` no es
 * otro catálogo de lugares, es la configuración de reserva de un espacio que
 * ya existe (1 a 1 con `espacios`): aforo, montajes, horario, montaje y
 * desmontaje, tarifas, foto y responsable de aprobación.
 *
 * Catálogos por empresa (editables): montajes, tipos de uso y servicios (cada
 * servicio con el departamento que lo autoriza y lo atiende).
 *
 * Operación: reservas, sus renglones de servicios, aprobaciones en paralelo
 * (área + cada departamento), órdenes de servicio por departamento e historial.
 *
 * Todas las tablas son nuevas (InnoDB, nombres en español, empresa_id y
 * auditoría creado_por/actualizado_por). down(): las borra de hijas a madres;
 * no altera tablas existentes ni índices que sostengan llaves foráneas de
 * otras tablas (seguro en MariaDB y SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_montajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('reserva_tipos_uso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('reserva_servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 80);
            // alimentos | bebidas | audio_video | computo | red | mobiliario | otro
            $table->string('categoria', 20)->default('otro');
            // pieza | persona | hora | evento
            $table->string('unidad', 10)->default('pieza');
            $table->decimal('costo', 10, 2)->nullable();
            // Departamento que lo autoriza y lo atiende (orden de servicio)
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->boolean('requiere_aprobacion')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('reserva_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->foreignId('espacio_id')->unique()->constrained('espacios')->cascadeOnDelete();
            $table->boolean('reservable')->default(true);
            $table->unsignedInteger('aforo_maximo')->nullable();
            $table->boolean('confirmacion_inmediata')->default(false);
            $table->unsignedSmallInteger('montaje_minutos')->default(0);
            $table->unsignedSmallInteger('desmontaje_minutos')->default(0);
            // {"1": [["08:00","22:00"]], ..., "7": []}; null = cualquier hora
            $table->json('horario')->nullable();
            $table->decimal('tarifa_hora', 10, 2)->nullable();
            $table->decimal('tarifa_medio_dia', 10, 2)->nullable();
            $table->decimal('tarifa_dia', 10, 2)->nullable();
            $table->decimal('tarifa_evento', 10, 2)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('foto', 190)->nullable();
            // Responsable de aprobación del área (null = el responsable general de la empresa)
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('suplente_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'sede_id', 'reservable']);
        });

        Schema::create('reserva_area_montajes', function (Blueprint $table) {
            $table->foreignId('reserva_area_id')->constrained('reserva_areas')->cascadeOnDelete();
            $table->foreignId('reserva_montaje_id')->constrained('reserva_montajes')->cascadeOnDelete();
            $table->unsignedInteger('aforo')->nullable();
            $table->primary(['reserva_area_id', 'reserva_montaje_id']);
        });

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->foreignId('reserva_area_id')->constrained('reserva_areas')->cascadeOnDelete();
            $table->foreignId('espacio_id')->constrained('espacios')->cascadeOnDelete();
            $table->string('folio', 20)->nullable();
            $table->string('titulo', 150);
            $table->foreignId('tipo_uso_id')->nullable()->constrained('reserva_tipos_uso')->nullOnDelete();
            $table->foreignId('montaje_id')->nullable()->constrained('reserva_montajes')->nullOnDelete();
            $table->unsignedInteger('asistentes')->default(1);
            // Hora universal (UTC); el área bloquea de bloqueo_desde a bloqueo_hasta (montaje y desmontaje)
            $table->dateTime('inicio');
            $table->dateTime('fin');
            $table->dateTime('bloqueo_desde');
            $table->dateTime('bloqueo_hasta');
            // por_aprobar | cambio_pedido | confirmada | rechazada | cancelada
            $table->string('estado', 15)->default('por_aprobar');
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            // Para un cliente de la sede (con costo) o uso interno (sin costo)
            $table->boolean('para_cliente')->default(false);
            $table->string('cliente_nombre', 150)->nullable();
            $table->string('cliente_empresa', 150)->nullable();
            $table->string('cliente_correo', 150)->nullable();
            $table->string('cliente_telefono', 30)->nullable();
            // hora | medio_dia | dia | evento
            $table->string('tarifa', 10)->nullable();
            $table->decimal('importe_area', 10, 2)->default(0);
            $table->decimal('importe_area_manual', 10, 2)->nullable();
            $table->decimal('importe_servicios', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->boolean('cortesia')->default(false);
            $table->string('ajuste_motivo', 255)->nullable();
            $table->decimal('iva_porcentaje', 5, 2)->default(0);
            $table->decimal('iva', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('moneda', 3)->default('MXN');
            $table->text('notas')->nullable();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamp('cancelada_en')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'espacio_id', 'bloqueo_desde'], 'reservas_empresa_espacio_bloqueo_idx');
            $table->index(['empresa_id', 'sede_id', 'inicio'], 'reservas_empresa_sede_inicio_idx');
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('reserva_servicio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->foreignId('servicio_id')->nullable()->constrained('reserva_servicios')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->string('nombre', 80);
            $table->string('unidad', 10)->default('pieza');
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('costo_unitario', 10, 2)->default(0);
            $table->decimal('importe', 10, 2)->default(0);
            $table->string('notas', 255)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('reserva_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            // area | departamento
            $table->string('tipo', 12);
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            // pendiente | aprobada | rechazada | cambio | cancelada
            $table->string('estado', 12)->default('pendiente');
            // La aprobación que vale hoy para su parte (área o departamento); las anteriores quedan de historial
            $table->boolean('vigente')->default(true);
            // Huella de lo que se aprobó: si la parte cambia, se vuelve a pedir
            $table->string('huella', 64)->nullable();
            $table->timestamp('solicitada_en')->nullable();
            $table->timestamp('respondida_en')->nullable();
            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario', 1000)->nullable();
            $table->json('avisados')->nullable();
            $table->timestamp('recordatorio_en')->nullable();
            $table->timestamp('escalado_en')->nullable();
            $table->json('escalado_a')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado', 'vigente']);
        });

        Schema::create('reserva_ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            // pendiente | lista | cancelada
            $table->string('estado', 10)->default('pendiente');
            $table->string('huella', 64)->nullable();
            $table->timestamp('lista_en')->nullable();
            $table->foreignId('lista_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario', 500)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->unique(['reserva_id', 'departamento_id']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('reserva_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->string('evento', 30);
            $table->string('titulo', 255);
            $table->json('detalle')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_eventos');
        Schema::dropIfExists('reserva_ordenes');
        Schema::dropIfExists('reserva_aprobaciones');
        Schema::dropIfExists('reserva_servicio_items');
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('reserva_area_montajes');
        Schema::dropIfExists('reserva_areas');
        Schema::dropIfExists('reserva_servicios');
        Schema::dropIfExists('reserva_tipos_uso');
        Schema::dropIfExists('reserva_montajes');
    }
};
