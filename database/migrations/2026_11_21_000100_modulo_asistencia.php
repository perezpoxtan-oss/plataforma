<?php

use Database\Seeders\CatalogoComercialSeeder;
use Database\Seeders\RolesPlantillaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo opcional «Asistencia» (decisión 13 del dueño, 3 nov 2026;
 * docs/tecnico/asistencia.md y ADR-0024).
 *
 *  - Tabla `asistencia_justificaciones`: una falta, retardo o salida
 *    anticipada justificada por colaborador y día, con su motivo y nota.
 *    La asistencia del día NO se guarda: se calcula de las entradas y
 *    salidas de la Bitácora de accesos y del turno del colaborador.
 *  - `turnos.dias_laborables`: días de la semana que se trabaja el turno
 *    (1 = lunes … 7 = domingo, separados por coma; vacío = todos los días,
 *    como hasta hoy). Solo se ve en Turnos con Asistencia encendida.
 *  - Acción nueva «Justificar» y módulo `asistencia` (área Recursos
 *    Humanos, menú Recursos Humanos → Asistencia) con ver, justificar,
 *    exportar y configurar. **No se enciende en ninguna empresa**: apagado
 *    de fábrica, se contrata como complemento.
 *  - Permisos por nombre de rol, igual que RolesPlantillaSeeder::ASISTENCIA.
 *  - Catálogo comercial: producto y paquete «Asistencia» (complemento para
 *    los tres planes). Aquí el paquete nace SIN publicar: el dueño revisa el
 *    precio de ejemplo antes de mostrarlo en la página de planes.
 *
 * En una instalación nueva (catálogo vacío) solo crea las tablas: el resto
 * lo hacen los seeders con las mismas reglas.
 *
 * down(): quita el paquete y el producto (si ningún cliente los tiene), los
 * permisos, el módulo, la acción, la columna y la tabla.
 */
return new class extends Migration
{
    private const ACCIONES = ['ver', 'justificar', 'exportar', 'configurar'];

    public function up(): void
    {
        Schema::create('asistencia_justificaciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->date('fecha');
            $table->string('tipo', 20);                 // falta | retardo | salida_anticipada
            $table->string('motivo', 20);               // permiso | vacaciones | descanso | incapacidad | comision | otro
            $table->string('nota', 255)->nullable();
            $table->timestamp('datos_eliminados_en')->nullable(); // conservación: la nota se quita al vencer el plazo
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'colaborador_id', 'fecha', 'tipo'], 'asistencia_just_unica');
            $table->index(['empresa_id', 'fecha']);
        });

        Schema::table('turnos', function (Blueprint $table) {
            $table->string('dias_laborables', 20)->nullable();
        });

        $acciones = DB::table('acciones')->pluck('id', 'clave');
        if ($acciones->isEmpty() || DB::table('modulos')->where('clave', 'asistencia')->exists()) {
            return; // instalación nueva (lo crean los seeders) o ya aplicada
        }
        $ahora = now();

        if (! isset($acciones['justificar'])) {
            $acciones['justificar'] = DB::table('acciones')->insertGetId(['clave' => 'justificar', 'nombre' => 'Justificar',
                'orden' => (int) DB::table('acciones')->max('orden') + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        $area = DB::table('areas')->where('clave', 'recursos_humanos')->value('id');
        if ($area === null) {
            return;
        }
        $menu = DB::table('menus')->where('clave', 'recursos_humanos')->value('id');
        $modulo = DB::table('modulos')->insertGetId([
            'area_id' => $area, 'padre_id' => null, 'clave' => 'asistencia', 'nombre' => 'Asistencia', 'icono' => 'bi-calendar2-check',
            'ruta' => 'asistencia.index', 'orden' => (int) DB::table('modulos')->where('area_id', $area)->whereNull('padre_id')->max('orden') + 1,
            'tipo' => 'sistema', 'activo' => true,
            'menu_id' => $menu, 'seccion_menu' => $menu ? 'Asistencia' : null,
            'orden_menu' => $menu ? (int) DB::table('modulos')->where('menu_id', $menu)->max('orden_menu') + 1 : 0,
            'color_icono' => 'primary', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $ma = [];
        foreach (self::ACCIONES as $accion) {
            DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $acciones[$accion], 'created_at' => $ahora, 'updated_at' => $ahora]);
            $ma[$accion] = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $acciones[$accion])->value('id');
        }

        // Apagado de fábrica: NO se agrega a empresa_modulos. Los permisos se dejan listos para cuando se encienda.
        foreach (RolesPlantillaSeeder::ASISTENCIA as $rol => [$permitidas, $alcance]) {
            foreach (DB::table('roles')->where('nombre', $rol)->pluck('id') as $rolId) {
                foreach ($permitidas as $accion) {
                    DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $rolId, 'modulo_accion_id' => $ma[$accion], 'alcance' => $alcance->value,
                        'created_at' => $ahora, 'updated_at' => $ahora]);
                }
            }
        }

        // Catálogo comercial: complemento «Asistencia» para los tres planes
        if (! Schema::hasTable('productos') || ! DB::table('productos')->exists() || DB::table('productos')->where('clave', 'asistencia')->exists()) {
            return;
        }
        [$nombre, $descripcion, $icono] = CatalogoComercialSeeder::PRODUCTOS['asistencia'];
        $producto = DB::table('productos')->insertGetId(['clave' => 'asistencia', 'nombre' => $nombre, 'descripcion' => $descripcion, 'icono' => $icono,
            'activo' => true, 'orden' => (int) DB::table('productos')->max('orden') + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('modulo_producto')->insert(['producto_id' => $producto, 'modulo_id' => $modulo]);
        if (DB::table('paquetes')->where('slug', 'asistencia')->exists()) {
            return;
        }
        $paquete = DB::table('paquetes')->insertGetId(['nombre' => 'Asistencia', 'slug' => 'asistencia', 'modalidad' => 'suscripcion',
            'descripcion' => CatalogoComercialSeeder::DESCRIPCION_PAQUETE_ASISTENCIA,
            'precio' => CatalogoComercialSeeder::PRECIO_ASISTENCIA, 'por_sede' => true, 'activo' => true, 'publicado' => false,
            'orden' => (int) DB::table('paquetes')->max('orden') + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('paquete_producto')->insert(['paquete_id' => $paquete, 'producto_id' => $producto]);
        foreach (DB::table('planes')->whereIn('slug', ['esencial', 'profesional', 'corporativo'])->pluck('id') as $planId) {
            DB::table('paquete_plan')->insert(['paquete_id' => $paquete, 'plan_id' => $planId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('productos')) {
            $paquete = DB::table('paquetes')->where('slug', 'asistencia')->value('id');
            if ($paquete !== null && ! DB::table('suscripcion_paquete')->where('paquete_id', $paquete)->exists()) {
                DB::table('paquete_plan')->where('paquete_id', $paquete)->delete();
                DB::table('paquete_producto')->where('paquete_id', $paquete)->delete();
                DB::table('paquetes')->where('id', $paquete)->delete();
            }
            $producto = DB::table('productos')->where('clave', 'asistencia')->value('id');
            if ($producto !== null && ! DB::table('paquete_producto')->where('producto_id', $producto)->exists()
                && ! DB::table('plan_producto')->where('producto_id', $producto)->exists()) {
                DB::table('modulo_producto')->where('producto_id', $producto)->delete();
                DB::table('productos')->where('id', $producto)->delete();
            }
        }

        $modulo = DB::table('modulos')->where('clave', 'asistencia')->value('id');
        if ($modulo !== null) {
            // Explícito además de la cascada (SQLite sin llaves foráneas activas)
            DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->where('modulo_id', $modulo)->select('id'))->delete();
            DB::table('modulo_acciones')->where('modulo_id', $modulo)->delete();
            DB::table('empresa_modulos')->where('modulo_id', $modulo)->delete();
            if (Schema::hasTable('modulo_producto')) {
                DB::table('modulo_producto')->where('modulo_id', $modulo)->delete();
            }
            if (Schema::hasTable('suscripcion_excepciones')) {
                DB::table('suscripcion_excepciones')->where('modulo_id', $modulo)->delete();
            }
            DB::table('modulos')->where('id', $modulo)->delete();
        }
        $accion = DB::table('acciones')->where('clave', 'justificar')->value('id');
        if ($accion !== null && ! DB::table('modulo_acciones')->where('accion_id', $accion)->exists()) {
            DB::table('acciones')->where('id', $accion)->delete();
        }

        Schema::table('turnos', function (Blueprint $table) {
            $table->dropColumn('dias_laborables');
        });
        Schema::dropIfExists('asistencia_justificaciones');
    }
};
