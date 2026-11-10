<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario de gafetes (SEGCAT: cat_tipos_gafete y gafetes).
 *
 *  - tipos_gafete: catálogo de tipos por empresa (Visitante, Proveedor,
 *    Contratista y los que cada empresa agregue: RRHH, Capital Humano…).
 *  - gafetes: plásticos físicos que se prestan en caseta. Cada uno con su
 *    nomenclatura (HOT-CEN-VIS-001), código para el QR y, si se le asigna,
 *    la etiqueta NFC / RFID del plástico.
 *
 * Correcciones:
 *  - la nomenclatura es única por empresa (SEGCAT la revisaba contra todas
 *    las empresas y el índice ni siquiera existía en la base);
 *  - codigo_qr aleatorio de 24 caracteres en lugar de "GAF-<id>-xxxxxx",
 *    que exponía el consecutivo;
 *  - claves foráneas reales hacia empresa, sede y tipo;
 *  - el tipo no se repite dentro de la empresa (unique empresa + nombre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_gafete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 50);
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('gafetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('tipo_gafete_id')->constrained('tipos_gafete')->restrictOnDelete();
            $table->string('nomenclatura', 50);                      // HOT-CEN-VIS-001
            $table->unsignedInteger('consecutivo');                  // el 001 de la nomenclatura al generarse
            $table->string('codigo_qr', 32)->unique();               // aleatorio, va en el QR impreso
            $table->string('etiqueta_nfc', 64)->nullable();          // número de serie del chip, normalizado
            $table->boolean('activo')->default(true);                // false = dado de baja (extraviado, dañado, robado)
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'nomenclatura']);
            $table->unique(['empresa_id', 'etiqueta_nfc']);
            $table->index(['empresa_id', 'sede_id', 'tipo_gafete_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gafetes');
        Schema::dropIfExists('tipos_gafete');
    }
};
