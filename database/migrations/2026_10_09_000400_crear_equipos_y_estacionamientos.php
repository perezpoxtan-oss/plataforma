<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipos de seguridad y estacionamientos (SEGCAT: cat_tipos_equipo,
 * cat_equipos_seguridad y estacionamientos_zonas).
 *
 *  - tipos_equipo: catálogo por empresa (Radio, Lámpara táctica…); se crea
 *    al vuelo desde el alta del equipo, como en SEGCAT.
 *  - equipos: el inventario prestable de la guardia, por sede. Es
 *    "identificable" (codigo_qr + etiqueta_nfc, ver ADR-0005). El número de
 *    serie es único por empresa (en SEGCAT era único en toda la base).
 *  - zonas_estacionamiento: estacionamientos (cuentan espacios) y zonas de
 *    descarga (sin cupo) de cada sede. La ocupación no se guarda: se cuenta
 *    en vivo en la Bitácora de accesos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_equipo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('tipo_equipo_id')->constrained('tipos_equipo')->restrictOnDelete();
            $table->string('marca', 80)->nullable();
            $table->string('modelo', 80)->nullable();
            $table->string('numero_serie', 100);                      // mayúsculas, sin espacios dobles
            $table->decimal('costo', 10, 2)->nullable();              // para el voucher si algún día se da de baja
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('disponible');      // disponible | asignado | en_mantenimiento | baja
            $table->string('codigo_qr', 32)->unique();                // aleatorio: va en el QR y en la etiqueta NFC
            $table->string('etiqueta_nfc', 64)->nullable();           // número de serie del chip o tarjeta asignada
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero_serie']);
            $table->unique(['empresa_id', 'etiqueta_nfc']);
            $table->index(['empresa_id', 'sede_id', 'estado']);
        });

        Schema::create('zonas_estacionamiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('nombre', 100);
            $table->string('tipo', 20)->default('estacionamiento');   // estacionamiento | zona_descarga
            $table->unsignedSmallInteger('cupo_total')->nullable();   // null en zonas de descarga
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'sede_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zonas_estacionamiento');
        Schema::dropIfExists('equipos');
        Schema::dropIfExists('tipos_equipo');
    }
};
