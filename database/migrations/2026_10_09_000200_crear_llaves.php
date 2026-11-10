<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de llaves (SEGCAT: llaves, llaves_horarios, llaves_edificios,
 * llaves_pisos, llaves_areas_especificas, llaves_catalogo_secciones,
 * cat_tipos_llave y cat_alcances).
 *
 *  - llaves: cada llave, tarjeta o acceso de la sede. El tipo de dispositivo
 *    y el alcance de apertura son claves fijas (antes eran catálogos sin
 *    empresa que se interpretaban buscando palabras en el nombre).
 *  - horarios_llave: horarios de apertura válidos (sin ninguno = 24 horas).
 *  - espacio_llave: zonas (edificios), pisos o áreas específicas que abre,
 *    tomados del árbol de Zonas y áreas (antes cuatro tablas de relación).
 *  - grupo_espacio_llave: secciones (grupos de habitaciones) que abre.
 *
 * La llave es identificable con el lector universal: codigo_qr va en la
 * etiqueta del llavero y etiqueta_nfc guarda el chip o tarjeta asignado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete(); // responsable permanente (excepcional)
            $table->string('nomenclatura', 50);                      // "LL-CAT-SIT-01", en mayúsculas
            $table->string('descripcion', 255);                      // descripción de accesos
            $table->string('tipo_dispositivo', 20);                  // electronica_rfid | metalica | biometrica | clave_pin
            $table->string('alcance', 20);                           // global | zona | piso | area | seccion | otra
            $table->string('alcance_otro', 150)->nullable();         // espacio no catalogado (alcance "otra")
            $table->string('id_externo', 60)->nullable();            // lo graba la plataforma que programó la llave
            $table->string('plataforma_externa', 80)->nullable();    // VingCard, Salto, Onity, ZKTeco…
            $table->date('fecha_caducidad')->nullable();
            $table->string('codigo_qr', 32)->unique();               // aleatorio, va en la etiqueta del llavero
            $table->string('etiqueta_nfc', 64)->nullable();          // número de serie del chip o tarjeta
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'nomenclatura']);
            $table->unique(['empresa_id', 'etiqueta_nfc']);
            $table->index(['empresa_id', 'sede_id', 'activo']);
            $table->index(['sede_id', 'id_externo']);
            $table->index(['empresa_id', 'fecha_caducidad']);
        });

        Schema::create('horarios_llave', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('llave_id')->constrained('llaves')->cascadeOnDelete();
            $table->string('nombre', 60);                            // "Turno Limpieza", "Apertura matutina"
            $table->time('hora_inicio');
            $table->time('hora_fin');                                // menor que el inicio = termina al día siguiente
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['llave_id', 'hora_inicio']);
        });

        Schema::create('espacio_llave', function (Blueprint $table) {
            $table->foreignId('llave_id')->constrained('llaves')->cascadeOnDelete();
            $table->foreignId('espacio_id')->constrained('espacios')->cascadeOnDelete();
            $table->primary(['llave_id', 'espacio_id']);
            $table->index('espacio_id');
        });

        Schema::create('grupo_espacio_llave', function (Blueprint $table) {
            $table->foreignId('llave_id')->constrained('llaves')->cascadeOnDelete();
            $table->foreignId('grupo_espacio_id')->constrained('grupos_espacio')->cascadeOnDelete();
            $table->primary(['llave_id', 'grupo_espacio_id']);
            $table->index('grupo_espacio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_espacio_llave');
        Schema::dropIfExists('espacio_llave');
        Schema::dropIfExists('horarios_llave');
        Schema::dropIfExists('llaves');
    }
};
