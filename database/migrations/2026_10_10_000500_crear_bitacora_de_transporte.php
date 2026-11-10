<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de transporte (SEGCAT: bitacora_transporte y
 * bitacora_transporte_colaboradores).
 *
 *  - movimientos_transporte: una llegada o salida de la unidad de una ruta,
 *    o un vale de taxi cuando la unidad no llegó (un registro por taxi, como
 *    en SEGCAT; los taxis capturados juntos comparten "lote").
 *  - movimiento_transporte_pasajeros: colaboradores que viajaron en el taxi.
 *
 * Correcciones: la unidad, el chofer y el destino se ligan a sus padrones
 * (vehiculos, personas, paraderos) en lugar de copiar texto; el horario de la
 * ruta queda ligado; "fecha" es el día en la hora local de la sede (los
 * filtros por día ya no dependen de la zona del servidor); las firmas se
 * guardan como archivo privado (ruta) y no como base64 en la tabla; el Vo.Bo.
 * del vale queda registrado (autorizado_por / autorizado_en).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_transporte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('ruta_id')->constrained('rutas');
            $table->foreignId('ruta_horario_id')->nullable()->constrained('ruta_horarios')->nullOnDelete();
            $table->string('tipo_movimiento', 10);                  // llegada | salida
            $table->string('estatus', 12);                          // a_tiempo | retraso | no_llego
            $table->date('fecha');                                  // día en la hora local de la sede
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();
            $table->foreignId('chofer_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->unsignedSmallInteger('cantidad_pax')->default(0);
            $table->decimal('monto', 10, 2)->nullable();            // solo vales de taxi
            $table->text('justificacion')->nullable();              // si el monto supera el tope de la ruta
            $table->foreignId('paradero_id')->nullable()->constrained('paraderos')->nullOnDelete(); // destino del taxi
            $table->string('firma_guardia', 255)->nullable();       // ruta en el disco privado
            $table->string('firma_taxista', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->uuid('lote')->nullable();                       // taxis registrados juntos
            // Última corrección de datos (anular o autorizar no cuentan como edición)
            $table->unsignedBigInteger('editado_por')->nullable();
            $table->timestamp('editado_en')->nullable();
            $table->boolean('anulado')->default(false);
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->timestamp('anulado_en')->nullable();
            $table->unsignedBigInteger('autorizado_por')->nullable();
            $table->timestamp('autorizado_en')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'sede_id', 'fecha']);
            $table->index(['empresa_id', 'fecha', 'estatus']);
            $table->index('lote');
        });

        Schema::create('movimiento_transporte_pasajeros', function (Blueprint $table) {
            // Con id propio (sin llave única compuesta): la unión de colaboradores
            // duplicados (AdministradorColaboradores::REFERENCIAS) solo cambia colaborador_id
            $table->id();
            $table->foreignId('movimiento_transporte_id')->constrained('movimientos_transporte')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->index(['movimiento_transporte_id', 'colaborador_id'], 'mov_transporte_pasajero_idx');
            $table->index('colaborador_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimiento_transporte_pasajeros');
        Schema::dropIfExists('movimientos_transporte');
    }
};
