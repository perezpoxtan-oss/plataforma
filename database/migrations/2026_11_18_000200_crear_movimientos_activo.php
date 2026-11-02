<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 8 · C-45 (DD-19): historial único de lo que
 * se entrega y se recibe. Una fila por movimiento de una llave, un gafete,
 * un equipo de seguridad o una credencial: salida (préstamo, entrega,
 * responsiva, pase), recepción (OK / dañado / no regresó), «No regresó»,
 * baja, reactivación, anulación y mantenimiento.
 *
 * Es un registro de consulta: los módulos dueños (Préstamo de llaves,
 * Bitácora de accesos, Responsivas, Pases de salida, Credenciales) siguen
 * guardando lo suyo; esta tabla no los reemplaza. Lo anterior a esta
 * migración no se reconstruye (cada módulo conserva su propio historial).
 *
 * Sin copias de datos personales: quién lo tiene se guarda como referencia
 * (receptor_tipo/receptor_id del Padrón o el acceso de origen) y el nombre se
 * lee de ahí al mostrarlo; así ARCO y los plazos de conservación de cada
 * módulo siguen alcanzándolo.
 *
 * down(): borra la tabla (los módulos no dependen de ella).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_activo', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('activo_tipo', 20);                 // llave | gafete | equipo | credencial
            $table->unsignedBigInteger('activo_id');
            $table->string('accion', 20);                      // salida | recepcion | no_regreso | baja | reactivacion | anulado | mantenimiento
            $table->string('resultado', 20)->nullable();       // ok | danado | no_regreso (en la recepción)
            $table->string('origen_tipo', 30)->nullable();     // prestamo_llave | acceso | acompanante | responsiva | pase_salida | credencial | catalogo
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->string('receptor_tipo', 20)->nullable();   // colaborador | persona | proveedor (App\Support\Receptor)
            $table->unsignedBigInteger('receptor_id')->nullable();
            $table->string('detalle', 250)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['empresa_id', 'activo_tipo', 'activo_id', 'id'], 'movimientos_activo_activo_index');
            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_activo');
    }
};
