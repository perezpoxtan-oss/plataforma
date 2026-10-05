<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pases de salida (SEGCAT: pases_salida, pases_salida_articulos y
 * pases_salida_firmas).
 *
 *  - pases_salida: cada pase. Sale de una sede (sede_id) hacia otra sede,
 *    un proveedor o un colaborador que se lo lleva. El folio PS-000123 es
 *    consecutivo POR EMPRESA (antes era el id global de la tabla: una
 *    empresa veía cuántos pases llevaban las demás). Estado en claves de
 *    texto: pendiente_aprobacion, rechazado, aprobado, salio, en_destino,
 *    en_transito_regreso, regresado (las de SEGCAT en minúsculas).
 *  - pases_salida_articulos: lo que sale (cantidad, equipo, marca, modelo,
 *    serie, descripción). equipo_id liga el renglón con el padrón de Equipos
 *    de seguridad cuando se escaneó uno.
 *  - pases_salida_firmas: una firma por rol y pase (rol único por pase). La
 *    imagen va al disco privado (Firmas); antes se guardaba en base64
 *    dentro de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pases_salida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');                       // sede de origen, de donde sale
            $table->unsignedInteger('folio_numero');                                  // consecutivo por empresa
            $table->string('folio', 20);                                              // "PS-000123"
            $table->string('motivo', 30);                                             // prestamo | venta | consignacion | devolucion | reparacion | traspaso_definitivo
            $table->boolean('requiere_regreso')->default(true);                       // false en venta y traspaso definitivo
            $table->foreignId('colaborador_id')->constrained('colaboradores');        // solicitante
            $table->string('destino_tipo', 20);                                       // sede | proveedor | colaborador
            $table->foreignId('sede_destino_id')->nullable()->constrained('sedes');
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->foreignId('colaborador_destino_id')->nullable()->constrained('colaboradores');
            $table->string('destino_direccion', 255)->nullable();
            $table->string('destino_telefono', 20)->nullable();
            $table->date('fecha_salida_programada')->nullable();
            $table->date('fecha_tentativa_regreso')->nullable();
            $table->string('estado', 30)->default('pendiente_aprobacion');
            $table->text('motivo_rechazo')->nullable();
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamp('rechazado_en')->nullable();
            $table->foreignId('rechazado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('salio_en')->nullable();
            $table->timestamp('recibido_destino_en')->nullable();
            $table->timestamp('salio_regreso_en')->nullable();
            $table->timestamp('regreso_en')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'folio_numero']);
            $table->unique(['empresa_id', 'folio']);
            $table->index(['empresa_id', 'sede_id', 'estado']);
            $table->index(['empresa_id', 'sede_destino_id', 'estado']);
            $table->index(['empresa_id', 'estado', 'fecha_tentativa_regreso']);
            $table->index('colaborador_id');
            $table->index('colaborador_destino_id');
        });

        Schema::create('pases_salida_articulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('pase_salida_id')->constrained('pases_salida')->cascadeOnDelete();
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete(); // si se escaneó del padrón de equipos
            $table->unsignedInteger('cantidad')->default(1);
            $table->string('equipo', 150);                                             // "Laptop", "Radio"…
            $table->string('marca', 100)->nullable();
            $table->string('modelo', 100)->nullable();
            $table->string('serie', 100)->nullable();
            $table->text('descripcion')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['empresa_id', 'serie']);
        });

        Schema::create('pases_salida_firmas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('pase_salida_id')->constrained('pases_salida')->cascadeOnDelete();
            $table->string('grupo', 30);                                               // aprobacion | salida_fisica | recepcion_destino | salida_regreso | regreso
            $table->string('rol', 40);                                                 // jefe_depto, seguridad_salida…
            $table->string('nombre_firma', 150);                                       // quien firma, en mayúsculas
            $table->string('firma_ruta', 255);                                         // disco privado (App\Services\Firmas\Firmas)
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete(); // usuario que capturó la firma
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['pase_salida_id', 'rol']);
            $table->index(['empresa_id', 'grupo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pases_salida_firmas');
        Schema::dropIfExists('pases_salida_articulos');
        Schema::dropIfExists('pases_salida');
    }
};
