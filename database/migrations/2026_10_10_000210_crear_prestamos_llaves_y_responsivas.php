<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operación: Préstamo de llaves y Responsivas (resguardos de equipo).
 *
 *  - prestamos_llaves (SEGCAT: bitacora_llaves): cada vez que la caseta
 *    presta una llave del Catálogo a un colaborador, con la identificación
 *    que deja en garantía. Estados: en_uso → devuelta; "anulado" marca una
 *    captura equivocada sin borrarla. La columna generada llave_en_uso
 *    impide, en la propia base, que una llave quede prestada dos veces.
 *
 *  - responsivas (SEGCAT: responsivas_equipos agrupadas por "colaborador +
 *    fecha exacta"): ahora el lote es un registro propio con su folio, la
 *    firma del colaborador (ruta en el disco privado) y quién entregó y
 *    recibió.
 *  - equipos_responsiva: los equipos del lote, cada uno con su modalidad
 *    (prestado = "Turno", asignado = "Fijo"). La columna generada
 *    equipo_en_campo impide que un equipo esté en dos resguardos abiertos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamos_llaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('llave_id')->constrained('llaves');
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->string('tipo_garantia', 20);                         // gafete_interno | ine | licencia | pasaporte | ninguna
            $table->string('folio_garantia', 80)->nullable();            // folio o detalle de la identificación
            $table->string('estado', 12)->default('en_uso');             // en_uso | devuelta
            $table->timestamp('prestado_en');                            // UTC
            $table->foreignId('entregado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('devuelto_en')->nullable();
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('anulado')->default(false);                  // captura equivocada (no cuenta como préstamo)
            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            // Solo un préstamo vigente por llave (NULL en los demás: no choca)
            $table->unsignedBigInteger('llave_en_uso')->nullable()
                ->storedAs("CASE WHEN estado = 'en_uso' AND anulado = 0 THEN llave_id ELSE NULL END");
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('llave_en_uso');
            $table->index(['empresa_id', 'sede_id', 'estado', 'anulado']);
            $table->index(['llave_id', 'prestado_en']);
            $table->index(['colaborador_id']);
            $table->index(['empresa_id', 'prestado_en']);
        });

        Schema::create('responsivas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('colaborador_id')->constrained('colaboradores');   // resguardante
            $table->unsignedInteger('numero');                                   // consecutivo por empresa
            $table->string('folio', 20);                                         // "CENRES-000001"
            $table->string('firma_ruta', 255);                                   // disco privado (Firmas)
            $table->string('estado', 12)->default('en_campo');                   // en_campo | devuelta
            $table->timestamp('entregado_en');                                   // UTC
            $table->foreignId('entregado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('devuelto_en')->nullable();
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'folio']);
            $table->index(['empresa_id', 'sede_id', 'estado']);
            $table->index(['colaborador_id']);
        });

        Schema::create('equipos_responsiva', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('responsiva_id')->constrained('responsivas')->cascadeOnDelete();
            $table->foreignId('equipo_id')->constrained('equipos');
            $table->string('modalidad', 10);                                     // prestado (Turno) | asignado (Fijo)
            $table->string('estado_devolucion', 12)->nullable();                 // ok | baja (el equipo ya estaba de baja)
            $table->timestamp('devuelto_en')->nullable();
            // Un equipo solo puede estar en un resguardo abierto
            $table->unsignedBigInteger('equipo_en_campo')->nullable()
                ->storedAs('CASE WHEN devuelto_en IS NULL THEN equipo_id ELSE NULL END');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('equipo_en_campo');
            $table->index(['equipo_id', 'devuelto_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos_responsiva');
        Schema::dropIfExists('responsivas');
        Schema::dropIfExists('prestamos_llaves');
    }
};
