<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importación masiva desde archivo (ver docs/tecnico/importacion.md).
 *
 * Cada archivo subido es una importación de la empresa: el archivo original
 * (en el disco privado, para descargarlo después), la vista previa (estado de
 * cada fila: nueva, ya existe o con error) y, al guardarla, el resultado de
 * cada fila. La vista previa no guarda nada en los módulos.
 *
 * down(): borra la tabla (sus llaves foráneas se van con ella). Los archivos
 * guardados en storage/app/private/importaciones se quedan; no estorban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // colaboradores | personas | proveedores | vehiculos | zonas
            $table->string('tipo', 20);
            $table->string('archivo_nombre', 255);
            $table->string('archivo_ruta', 255);
            $table->string('formato', 4); // csv | xlsx
            $table->unsignedInteger('total_filas')->default(0);
            // vista_previa | aplicada
            $table->string('estado', 12)->default('vista_previa');
            // omitir | actualizar (lo que se eligió para los que ya existen)
            $table->string('existentes', 10)->nullable();
            $table->json('resumen')->nullable();
            $table->longText('filas')->nullable(); // JSON: estado de cada fila en la vista previa
            $table->longText('resultado')->nullable(); // JSON: resultado de cada fila al guardar
            $table->timestamp('aplicada_en')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importaciones');
    }
};
