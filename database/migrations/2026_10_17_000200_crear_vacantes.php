<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vacantes (bolsa de trabajo) — lección 36.
 *
 *  - vacantes:        la vacante (puesto, departamento, plazas, condiciones,
 *                     requisitos, fechas, estado Borrador → Publicada → Pausada → Cerrada)
 *                     con un código aleatorio para su dirección pública.
 *  - sede_vacante:    sedes donde aplica (o todas_las_sedes).
 *  - candidatos:      vacante_id (a qué vacante se postuló o lo ligó RR. HH. / la caseta).
 *  - empresas:        bolsa_slug (dirección pública /empleos/{slug}: nombre + 6 letras
 *                     al azar, para que no se pueda adivinar la de otra empresa).
 *
 * En una instalación nueva el módulo lo crean los seeders. En una base
 * existente (QA, Producción) se crea el módulo `vacantes` (área Recursos
 * Humanos, menú Recursos Humanos → «Recepción y candidatos», primero de la
 * sección), se activa en las empresas que tienen Candidatos y se otorga, con
 * el mismo alcance:
 *  - todo (ver, crear, editar, eliminar, configurar) a los roles con candidatos.crear;
 *  - ver, crear, editar y eliminar a los roles con recepcion_rh.ver (Director);
 *  - ver a los roles con accesos.crear (la caseta imprime el cartel y elige la vacante).
 * Solo agrega.
 */
return new class extends Migration
{
    private const ACCIONES = ['ver', 'crear', 'editar', 'eliminar', 'configurar'];

    public function up(): void
    {
        Schema::create('vacantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 12)->unique(); // dirección pública (aleatorio)
            $table->string('titulo', 150);
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->unsignedSmallInteger('plazas')->default(1);
            $table->boolean('todas_las_sedes')->default(false);
            $table->string('tipo_contrato', 20)->nullable(); // indeterminado | determinado | temporada | practicas
            $table->string('jornada', 20)->nullable();       // completa | medio_tiempo | por_horas | fines_semana
            $table->foreignId('turno_id')->nullable()->constrained('turnos')->nullOnDelete();
            $table->string('horario', 150)->nullable();
            $table->decimal('sueldo_min', 10, 2)->nullable();
            $table->decimal('sueldo_max', 10, 2)->nullable();
            $table->string('sueldo_periodo', 12)->default('mensual'); // mensual | quincenal | semanal
            $table->boolean('sueldo_a_tratar')->default(false);
            $table->text('descripcion')->nullable();
            $table->json('requisitos')->nullable();
            $table->json('prestaciones')->nullable();
            $table->string('escolaridad_minima', 20)->nullable();
            $table->string('experiencia', 150)->nullable();
            $table->date('fecha_publicacion')->nullable();
            $table->date('fecha_cierre')->nullable();
            $table->string('contacto_nombre', 150)->nullable();
            $table->string('contacto_telefono', 20)->nullable();
            $table->string('contacto_correo', 150)->nullable();
            // borrador | publicada | pausada | cerrada
            $table->string('estado', 12)->default('borrador');
            $table->string('cierre_motivo', 12)->nullable(); // cubierta | cancelada
            $table->timestamp('publicada_en')->nullable();
            $table->timestamp('cerrada_en')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('sede_vacante', function (Blueprint $table) {
            $table->foreignId('vacante_id')->constrained('vacantes')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->primary(['vacante_id', 'sede_id']);
        });

        Schema::table('candidatos', function (Blueprint $table) {
            $table->foreignId('vacante_id')->nullable()->after('vacante')->constrained('vacantes')->nullOnDelete();
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->string('bolsa_slug', 90)->nullable()->unique()->after('moneda');
        });

        $this->modulo();
    }

    private function modulo(): void
    {
        $area = DB::table('areas')->where('clave', 'recursos_humanos')->value('id');
        if ($area === null) {
            return; // instalación nueva: lo crean los seeders
        }
        $existente = DB::table('modulos')->where('clave', 'vacantes')->first();
        if ($existente !== null) {
            // Ya migrada: solo se acomoda en el menú si quedó fuera
            if ($existente->menu_id === null) {
                [$menu, $orden] = $this->lugarEnMenu();
                DB::table('modulos')->where('id', $existente->id)->update(['menu_id' => $menu, 'seccion_menu' => $menu ? 'Recepción y candidatos' : null,
                    'orden_menu' => $orden, 'color_icono' => 'warning', 'updated_at' => now()]);
            }

            return;
        }
        $ahora = now();
        $acciones = DB::table('acciones')->pluck('id', 'clave');
        [$menu, $orden] = $this->lugarEnMenu();

        $modulo = DB::table('modulos')->insertGetId([
            'area_id' => $area, 'padre_id' => null, 'clave' => 'vacantes', 'nombre' => 'Vacantes', 'icono' => 'bi-megaphone', 'ruta' => 'vacantes.index',
            'orden' => (int) DB::table('modulos')->where('area_id', $area)->max('orden') + 1, 'tipo' => 'sistema', 'activo' => true,
            'menu_id' => $menu, 'seccion_menu' => $menu ? 'Recepción y candidatos' : null, 'orden_menu' => $orden,
            'color_icono' => 'warning', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        foreach (self::ACCIONES as $accion) {
            if (isset($acciones[$accion])) {
                DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $acciones[$accion], 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
        $origen = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('m.clave', 'candidatos')->get(['em.empresa_id', 'em.activo']);
        foreach ($origen as $fila) {
            DB::table('empresa_modulos')->insertOrIgnore(['empresa_id' => $fila->empresa_id, 'modulo_id' => $modulo, 'activo' => (bool) $fila->activo,
                'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        // Permisos: [origen modulo.accion] => destinos (mismo alcance)
        $otorgar = [
            'candidatos.crear' => ['vacantes.ver', 'vacantes.crear', 'vacantes.editar', 'vacantes.eliminar', 'vacantes.configurar'],
            'recepcion_rh.ver' => ['vacantes.ver', 'vacantes.crear', 'vacantes.editar', 'vacantes.eliminar'],
            'accesos.crear' => ['vacantes.ver'],
        ];
        $idDe = fn (string $permiso) => DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', explode('.', $permiso)[0])->where('a.clave', explode('.', $permiso)[1])->value('ma.id');
        foreach ($otorgar as $desde => $destinos) {
            $origenId = $idDe($desde);
            if ($origenId === null) {
                continue;
            }
            $permisos = DB::table('rol_permisos')->where('modulo_accion_id', $origenId)->get(['rol_id', 'alcance']);
            foreach ($destinos as $destino) {
                $destinoId = $idDe($destino);
                foreach ($destinoId === null ? [] : $permisos as $p) {
                    DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $p->rol_id, 'modulo_accion_id' => $destinoId, 'alcance' => $p->alcance,
                        'created_at' => $ahora, 'updated_at' => $ahora]);
                }
            }
        }
    }

    /**
     * Menú Recursos Humanos → «Recepción y candidatos»: Vacantes va primero
     * (recorre a los que siguen).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function lugarEnMenu(): array
    {
        $menu = DB::table('menus')->where('clave', 'recursos_humanos')->value('id');
        if ($menu === null) {
            return [null, null];
        }
        $orden = DB::table('modulos')->where('menu_id', $menu)->where('seccion_menu', 'Recepción y candidatos')->min('orden_menu')
            ?? ((int) DB::table('modulos')->where('menu_id', $menu)->max('orden_menu') + 1);
        DB::table('modulos')->where('menu_id', $menu)->where('orden_menu', '>=', $orden)->increment('orden_menu');

        return [(int) $menu, (int) $orden];
    }

    public function down(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'vacantes')->value('id');
        if ($modulo !== null) {
            DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->where('modulo_id', $modulo)->select('id'))->delete();
            DB::table('modulo_acciones')->where('modulo_id', $modulo)->delete();
            DB::table('empresa_modulos')->where('modulo_id', $modulo)->delete();
            DB::table('modulos')->where('id', $modulo)->delete();
        }
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique(['bolsa_slug']);
            $table->dropColumn('bolsa_slug');
        });
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vacante_id');
        });
        Schema::dropIfExists('sede_vacante');
        Schema::dropIfExists('vacantes');
    }
};
