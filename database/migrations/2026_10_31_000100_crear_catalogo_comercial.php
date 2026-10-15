<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo comercial y página pública de planes (ver docs/tecnico/catalogo-comercial.md).
 *
 * Datos GLOBALES de la plataforma (no son de ninguna empresa: sin empresa_id
 * ni filtro de empresa). Solo los administra el Super Administrador.
 *
 *  - productos:            lo que se vende (módulos de la plataforma agrupados).
 *  - modulo_producto:      qué módulos del catálogo habilita cada producto.
 *  - planes:               suscripción con precio por sede al mes.
 *  - plan_producto:        productos incluidos en cada plan.
 *  - paquetes:             complementos o paquetes cerrados (suscripción por sede
 *                          al mes o venta directa de pago único).
 *  - paquete_producto:     productos que incluye cada paquete.
 *  - paquete_plan:         planes con los que se puede sumar (vacío = con todos).
 *  - solicitudes_compra:   lo que llega del formulario «Contratar»; el desglose
 *                          se calcula en el servidor y se congela al guardar.
 *  - notificaciones.empresa_id pasa a aceptar nulo: avisos de la plataforma
 *    (sin empresa) para el Super Administrador.
 *  - Módulo `catalogo_comercial` (tipo plataforma) en Estructura → Plataforma,
 *    después de Identidad. En una instalación nueva lo crean los seeders.
 *
 * Los ajustes comerciales (moneda, IVA, textos, landing publicada) viven en
 * configuracion_plataforma (clave «comercial»), como la identidad.
 *
 * down(): borra el módulo, las tablas nuevas, los ajustes «comercial» y los
 * avisos sin empresa (solo existen por este módulo) y regresa la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->string('icono', 40)->default('bi-box');
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['activo', 'orden']);
        });

        Schema::create('modulo_producto', function (Blueprint $table) {
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->primary(['producto_id', 'modulo_id']);
        });

        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->string('slug', 60)->unique();
            $table->string('frase', 150)->nullable();
            $table->text('descripcion')->nullable();
            $table->decimal('precio_por_sede_mensual', 10, 2)->default(0);
            $table->unsignedSmallInteger('sedes_minimas')->default(1);
            $table->unsignedSmallInteger('usuarios_por_sede')->nullable(); // nulo = ilimitados
            $table->boolean('destacado')->default(false);
            $table->boolean('cotizar')->default(false); // sin precio público: «Cotizar»
            $table->boolean('activo')->default(true);
            $table->boolean('publicado')->default(false);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['activo', 'publicado', 'orden']);
        });

        Schema::create('plan_producto', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained('planes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->primary(['plan_id', 'producto_id']);
        });

        Schema::create('paquetes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            $table->string('slug', 80)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->string('modalidad', 12)->default('suscripcion'); // suscripcion | unico
            $table->decimal('precio', 10, 2)->default(0);
            $table->boolean('por_sede')->default(true); // el precio se multiplica por las sedes
            $table->boolean('activo')->default(true);
            $table->boolean('publicado')->default(false);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['activo', 'publicado', 'orden']);
        });

        Schema::create('paquete_producto', function (Blueprint $table) {
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->primary(['paquete_id', 'producto_id']);
        });

        Schema::create('paquete_plan', function (Blueprint $table) {
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('planes')->cascadeOnDelete();
            $table->primary(['paquete_id', 'plan_id']);
        });

        Schema::create('solicitudes_compra', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->string('nombre', 150);
            $table->string('empresa', 150)->nullable();
            $table->string('correo', 150);
            $table->string('telefono', 20)->nullable();
            $table->unsignedSmallInteger('sedes')->default(1);
            $table->text('mensaje')->nullable();
            // Lo elegido (el plan puede borrarse después: su nombre queda en el detalle)
            $table->foreignId('plan_id')->nullable()->constrained('planes')->nullOnDelete();
            // Desglose calculado en el servidor y congelado al guardar
            $table->string('moneda', 3)->default('MXN');
            $table->decimal('iva_porcentaje', 5, 2)->default(16);
            $table->decimal('mensual_subtotal', 12, 2)->default(0);
            $table->decimal('mensual_iva', 12, 2)->default(0);
            $table->decimal('mensual_total', 12, 2)->default(0);
            $table->decimal('unico_subtotal', 12, 2)->default(0);
            $table->decimal('unico_iva', 12, 2)->default(0);
            $table->decimal('unico_total', 12, 2)->default(0);
            $table->boolean('requiere_cotizacion')->default(false);
            $table->json('detalle')->nullable(); // renglones: plan y paquetes con su precio de ese momento
            // nueva | contactada | cotizada | pagada | cancelada
            $table->string('estado', 12)->default('nueva');
            $table->string('pasarela', 30)->nullable();
            $table->string('referencia_pago', 100)->nullable();
            $table->text('notas_internas')->nullable();
            $table->boolean('acepta_privacidad')->default(false);
            $table->timestamp('privacidad_aceptada_en')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('atendida_en')->nullable(); // primera vez que dejó de estar «nueva»
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['estado', 'created_at']);
        });

        // Avisos de la plataforma (sin empresa) para el Super Administrador
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->change();
        });

        $this->modulo();
    }

    private function modulo(): void
    {
        $area = DB::table('areas')->where('clave', 'direccion')->value('id');
        if ($area === null || DB::table('modulos')->where('clave', 'catalogo_comercial')->exists()) {
            return; // instalación nueva (lo crean los seeders) o ya existe
        }
        $ahora = now();
        $menu = DB::table('menus')->where('clave', 'estructura')->value('id');
        $orden = null;
        if ($menu !== null) {
            $identidad = DB::table('modulos')->where('clave', 'identidad')->where('menu_id', $menu)->value('orden_menu');
            $orden = $identidad !== null ? (int) $identidad + 1 : (int) DB::table('modulos')->where('menu_id', $menu)->max('orden_menu') + 1;
            DB::table('modulos')->where('menu_id', $menu)->where('orden_menu', '>=', $orden)->increment('orden_menu');
        }

        $modulo = DB::table('modulos')->insertGetId([
            'area_id' => $area, 'padre_id' => null, 'clave' => 'catalogo_comercial', 'nombre' => 'Catálogo comercial',
            'icono' => 'bi-shop', 'ruta' => 'catalogo-comercial.index', 'tipo' => 'plataforma', 'activo' => true,
            'orden' => (int) DB::table('modulos')->where('area_id', $area)->max('orden') + 1,
            'menu_id' => $menu, 'seccion_menu' => $menu !== null ? 'Plataforma' : null, 'orden_menu' => $orden,
            'color_icono' => 'success', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        foreach (DB::table('acciones')->whereIn('clave', ['ver', 'editar'])->pluck('id') as $accion) {
            DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'catalogo_comercial')->first(['id', 'menu_id', 'orden_menu']);
        if ($modulo !== null) {
            DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->where('modulo_id', $modulo->id)->select('id'))->delete();
            DB::table('modulo_acciones')->where('modulo_id', $modulo->id)->delete();
            DB::table('empresa_modulos')->where('modulo_id', $modulo->id)->delete();
            DB::table('modulos')->where('id', $modulo->id)->delete();
            if ($modulo->menu_id !== null && $modulo->orden_menu !== null) {
                DB::table('modulos')->where('menu_id', $modulo->menu_id)->where('orden_menu', '>', $modulo->orden_menu)->decrement('orden_menu');
            }
        }
        DB::table('configuracion_plataforma')->where('clave', 'comercial')->delete();

        DB::table('notificaciones')->whereNull('empresa_id')->delete();
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable(false)->change();
        });

        Schema::dropIfExists('solicitudes_compra');
        Schema::dropIfExists('paquete_plan');
        Schema::dropIfExists('paquete_producto');
        Schema::dropIfExists('paquetes');
        Schema::dropIfExists('plan_producto');
        Schema::dropIfExists('planes');
        Schema::dropIfExists('modulo_producto');
        Schema::dropIfExists('productos');
    }
};
