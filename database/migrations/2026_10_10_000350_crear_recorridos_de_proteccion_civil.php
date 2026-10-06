<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorridos de Protección Civil (SEGCAT: cat_equipos_pc,
 * recorridos_pc_sesiones y recorridos_pc_items).
 *
 *  - equipos_pc: catálogo de equipos de Protección Civil (extintores,
 *    hidrantes, detectores…) por sede. Es "identificable" (codigo_qr +
 *    etiqueta_nfc, ver ADR-0005). Núm. de serie / ID único por sede, como en
 *    SEGCAT. La ubicación sale de Zonas y áreas (antes secciones y
 *    áreas específicas).
 *  - recorridos_pc: la ronda de inspección (SEGCAT: recorridos_pc_sesiones).
 *    Número consecutivo por empresa. Estatus en_proceso → completo |
 *    con_hallazgos. novedad_id: el ticket de Protección Civil que abrió el
 *    primer hallazgo (en la Bitácora de Novedades).
 *  - recorrido_pc_revisiones: cada punto inspeccionado (SEGCAT:
 *    recorridos_pc_items), con el resultado de cada criterio en JSON.
 *    Identificador, categoría y ubicación se copian del equipo al momento de
 *    revisarlo: el reporte de auditoría no cambia si después se edita el
 *    catálogo.
 *
 * Toda tabla lleva empresa_id (scope de tenant) y autoría. Las horas se
 * guardan en UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos_pc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('categoria', 30);                          // EXTINTOR, HIDRANTE… (EquipoPc::CATEGORIAS)
            $table->string('numero_serie', 100);                      // mayúsculas: "EXT-01"
            $table->foreignId('espacio_id')->nullable()->constrained('espacios')->nullOnDelete(); // zona, piso o área específica
            $table->string('referencia', 150)->nullable();            // "Junto al elevador de servicio"
            $table->boolean('activo')->default(true);
            $table->string('codigo_qr', 32)->unique();                // aleatorio: va en el QR y en la etiqueta NFC
            $table->string('etiqueta_nfc', 64)->nullable();           // número de serie del chip o tarjeta asignada
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'sede_id', 'numero_serie']);
            $table->unique(['empresa_id', 'etiqueta_nfc']);
            $table->index(['empresa_id', 'sede_id', 'activo']);
        });

        Schema::create('recorridos_pc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->unsignedInteger('numero');                        // consecutivo por empresa → "#00012"
            $table->foreignId('espacio_id')->nullable()->constrained('espacios')->nullOnDelete(); // edificio / zona (opcional)
            $table->string('estatus', 20)->default('en_proceso');     // en_proceso | completo | con_hallazgos
            $table->text('observaciones_generales')->nullable();
            $table->foreignId('novedad_id')->nullable()->constrained('novedades')->nullOnDelete(); // ticket de Protección Civil
            $table->timestamp('finalizado_en')->nullable();
            $table->foreignId('finalizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete(); // quien lo inició
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'sede_id', 'estatus']);
            $table->index(['empresa_id', 'created_at']);
        });

        Schema::create('recorrido_pc_revisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('recorrido_pc_id')->constrained('recorridos_pc')->cascadeOnDelete();
            $table->foreignId('equipo_pc_id')->nullable()->constrained('equipos_pc')->nullOnDelete(); // null: capturado a mano
            $table->string('identificador', 100);                     // copia del Núm. de serie / ID
            $table->string('categoria', 30);
            $table->string('ubicacion', 255)->nullable();             // copia: "Torre A · Piso 1 · Cocina"
            $table->json('criterios');                                // {"cilindro": true, "manometro": false…}
            $table->string('resultado', 10);                          // ok | falla
            $table->text('observaciones')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['recorrido_pc_id', 'equipo_pc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorrido_pc_revisiones');
        Schema::dropIfExists('recorridos_pc');
        Schema::dropIfExists('equipos_pc');
    }
};
