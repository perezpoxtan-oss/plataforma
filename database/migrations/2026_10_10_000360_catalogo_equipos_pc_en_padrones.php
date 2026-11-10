<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El Catálogo de Equipos de Protección Civil pasa a ser un módulo propio,
 * «equipos_pc», en Padrones → Inventarios de Seguridad (antes era una
 * pantalla dentro de Recorridos de Protección Civil).
 *
 * En una instalación nueva no hace nada: el catálogo, el menú y las
 * plantillas de rol los crean los seeders. En una base existente (QA,
 * Producción) el despliegue corre las migraciones ANTES que los seeders, así
 * que aquí se crea el módulo con sus acciones, se acomoda en el menú, se
 * activa en las empresas que tenían Recorridos o Equipos de seguridad y se
 * otorgan los permisos respetando lo que cada rol ya tenía:
 *  - «equipos_pc.ver» a quien tenía «recorridos_pc.ver» (mismo alcance);
 *  - «equipos_pc.crear | editar | eliminar | imprimir» a quien tenía el
 *    mismo permiso de «equipos.*» (mismo alcance).
 * Solo agrega; nunca quita ni cambia permisos que ya tenga un rol.
 */
return new class extends Migration
{
    private const CLAVE = 'equipos_pc';

    private const ACCIONES = ['ver', 'crear', 'editar', 'eliminar', 'imprimir'];

    /** acción de equipos_pc => permiso del que se copia (módulo, acción) */
    private const ORIGEN = [
        'ver' => ['recorridos_pc', 'ver'],
        'crear' => ['equipos', 'crear'],
        'editar' => ['equipos', 'editar'],
        'eliminar' => ['equipos', 'eliminar'],
        'imprimir' => ['equipos', 'imprimir'],
    ];

    public function up(): void
    {
        $area = DB::table('areas')->where('clave', 'seguridad')->value('id');
        if ($area === null || DB::table('modulos')->where('clave', self::CLAVE)->exists()) {
            return; // instalación nueva (lo crean los seeders) o ya migrada
        }
        $ahora = now();

        // Padrones → Inventarios de Seguridad, justo después de Equipos de seguridad
        $equipos = DB::table('modulos')->where('clave', 'equipos')->first(['menu_id', 'seccion_menu', 'orden_menu', 'orden']);
        $menu = $equipos->menu_id ?? DB::table('menus')->where('clave', 'padrones')->value('id');
        $ordenMenu = $equipos?->orden_menu !== null ? (int) $equipos->orden_menu + 1 : null;
        if ($menu && $ordenMenu !== null) {
            // Se hace lugar: los que seguían a Equipos de seguridad bajan un lugar
            DB::table('modulos')->where('menu_id', $menu)->where('orden_menu', '>=', $ordenMenu)->increment('orden_menu');
        }

        $modulo = DB::table('modulos')->insertGetId([
            'area_id' => $area,
            'padre_id' => null,
            'clave' => self::CLAVE,
            'nombre' => 'Equipos de Protección Civil',
            'icono' => 'bi-fire',
            'ruta' => 'equipos_pc.index',
            'orden' => (int) ($equipos->orden ?? 0),
            'tipo' => 'sistema',
            'activo' => true,
            'menu_id' => $menu,
            'seccion_menu' => $menu ? ($equipos->seccion_menu ?? 'Inventarios de Seguridad') : null,
            'orden_menu' => $menu ? ($ordenMenu ?? 0) : null,
            'color_icono' => 'danger',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        // Acciones del módulo (la acción "imprimir" ya existe en el catálogo)
        $acciones = DB::table('acciones')->whereIn('clave', self::ACCIONES)->pluck('id', 'clave');
        foreach (self::ACCIONES as $accion) {
            if (isset($acciones[$accion])) {
                DB::table('modulo_acciones')->insertOrIgnore([
                    'modulo_id' => $modulo, 'accion_id' => $acciones[$accion], 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }

        // Se activa en cada empresa que tenía Recorridos PC o Equipos de seguridad (como estaba ese módulo)
        $contratos = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->whereIn('m.clave', ['recorridos_pc', 'equipos'])
            ->get(['em.empresa_id', 'em.activo']);
        foreach ($contratos->groupBy('empresa_id') as $empresaId => $filas) {
            DB::table('empresa_modulos')->insertOrIgnore([
                'empresa_id' => $empresaId, 'modulo_id' => $modulo, 'activo' => $filas->contains(fn ($f) => (bool) $f->activo),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        // Permisos: copia de los que cada rol ya tenía (plantillas y roles de cada empresa)
        foreach (self::ORIGEN as $accion => [$moduloOrigen, $accionOrigen]) {
            $destino = $this->permiso(self::CLAVE, $accion);
            $origen = $this->permiso($moduloOrigen, $accionOrigen);
            if ($destino === null || $origen === null) {
                continue;
            }
            foreach (DB::table('rol_permisos')->where('modulo_accion_id', $origen)->get(['rol_id', 'alcance']) as $permiso) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $permiso->rol_id, 'modulo_accion_id' => $destino, 'alcance' => $permiso->alcance,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    private function permiso(string $modulo, string $accion): ?int
    {
        $id = DB::table('modulo_acciones as ma')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', $modulo)->where('a.clave', $accion)
            ->value('ma.id');

        return $id === null ? null : (int) $id;
    }

    public function down(): void
    {
        $modulo = DB::table('modulos')->where('clave', self::CLAVE)->value('id');
        if ($modulo === null) {
            return;
        }
        // Al borrar la relación módulo-acción se borran en cascada los permisos otorgados
        DB::table('modulo_acciones')->where('modulo_id', $modulo)->delete();
        DB::table('empresa_modulos')->where('modulo_id', $modulo)->delete();
        DB::table('modulos')->where('id', $modulo)->delete();
    }
};
