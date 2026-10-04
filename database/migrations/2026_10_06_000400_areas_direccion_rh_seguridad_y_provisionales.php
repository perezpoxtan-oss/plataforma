<?php

use App\Models\Rol;
use App\Services\Permisos\Alcance;
use App\Services\Plataforma\ProvisionarEmpresa;
use Database\Seeders\RolesPlantillaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Las áreas del catálogo pasan a tres (las que se contratan y agrupan la
 *    Matriz de permisos):
 *      - Dirección          (antes Organización + Administración)
 *      - Recursos Humanos   (Colaboradores)
 *      - Seguridad          (antes Seguridad + Reportes)
 * 2. Colaboradores se muestra en su propio menú "Recursos Humanos".
 * 3. Altas provisionales de colaboradores: la caseta registra a una persona
 *    que aún no existe y Recursos Humanos la valida ("aprobar") o la une con
 *    el registro correcto si resultó duplicada.
 * 4. Rol base "Recursos Humanos" (nivel 25) para las empresas existentes.
 *
 * En una base existente (QA, Producción) las migraciones corren ANTES que
 * los seeders: aquí se acomoda lo que ya existe. En una instalación nueva
 * solo se agregan las columnas; el catálogo, los menús y las plantillas los
 * crean los seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->boolean('provisional')->default(false)->after('activo');
            $table->unsignedBigInteger('validado_por')->nullable()->after('provisional');
            $table->timestamp('validado_en')->nullable()->after('validado_por');
            $table->foreignId('fusionado_en_id')->nullable()->after('validado_en')->constrained('colaboradores')->nullOnDelete();
            $table->index(['empresa_id', 'provisional']);
        });
        // Un alta provisional puede no traer número de empleado
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('num_empleado', 20)->nullable()->change();
        });

        if (! DB::table('areas')->exists()) {
            return;
        }

        $ahora = now();
        $this->reorganizarAreas($ahora);
        $this->menuRecursosHumanos($ahora);
        $this->accionesProvisionales($ahora);
        $this->plantillaRecursosHumanos();
    }

    private function reorganizarAreas($ahora): void
    {
        $area = fn (string $clave) => DB::table('areas')->where('clave', $clave)->value('id');
        $crear = fn (string $clave, string $nombre, string $icono) => $area($clave)
            ?? DB::table('areas')->insertGetId(['clave' => $clave, 'nombre' => $nombre, 'icono' => $icono, 'orden' => 0, 'created_at' => $ahora, 'updated_at' => $ahora]);

        if ($area('organizacion') !== null && $area('direccion') === null) {
            DB::table('areas')->where('clave', 'organizacion')->update(['clave' => 'direccion', 'nombre' => 'Dirección', 'icono' => 'bi-building-gear', 'updated_at' => $ahora]);
        }
        $direccion = $crear('direccion', 'Dirección', 'bi-building-gear');
        $rh = $crear('recursos_humanos', 'Recursos Humanos', 'bi-people-fill');
        $seguridad = $crear('seguridad', 'Seguridad', 'bi-shield-lock');

        foreach (['administracion' => $direccion, 'reportes' => $seguridad] as $vieja => $destino) {
            $id = $area($vieja);
            if ($id !== null) {
                DB::table('modulos')->where('area_id', $id)->update(['area_id' => $destino, 'updated_at' => $ahora]);
                DB::table('areas')->where('id', $id)->delete();
            }
        }
        DB::table('modulos')->where('clave', 'colaboradores')->update(['area_id' => $rh, 'updated_at' => $ahora]);

        foreach ([$direccion => 1, $rh => 2, $seguridad => 3] as $id => $orden) {
            DB::table('areas')->where('id', $id)->update(['orden' => $orden]);
        }
    }

    private function menuRecursosHumanos($ahora): void
    {
        if (! DB::table('menus')->exists()) {
            return;
        }

        $menu = DB::table('menus')->where('clave', 'recursos_humanos')->value('id')
            ?? DB::table('menus')->insertGetId([
                'clave' => 'recursos_humanos', 'nombre' => 'Recursos Humanos', 'icono' => 'bi-people-fill',
                'orden' => 2, 'orden_movil' => 4, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

        // Escritorio: Estructura, Recursos Humanos, Padrones, Operación
        foreach (['estructura' => 1, 'recursos_humanos' => 2, 'padrones' => 3, 'operacion' => 4] as $clave => $orden) {
            DB::table('menus')->where('clave', $clave)->update(['orden' => $orden]);
        }

        DB::table('modulos')->where('clave', 'colaboradores')->update([
            'menu_id' => $menu, 'seccion_menu' => 'Personal', 'orden_menu' => 1, 'updated_at' => $ahora,
        ]);
    }

    /**
     * Acción "provisional" y "aprobar" en Colaboradores, y quién las tiene:
     *  - Administrador: ambas, con el alcance de su "colaboradores.editar".
     *  - Director: aprobar (valida), toda la empresa.
     *  - Jefe de seguridad, Asistente, Supervisor y Agente: ver y provisional, su sede
     *    (la Matriz exige "ver" para cualquier otra acción del módulo).
     * Solo agrega; nunca quita ni cambia permisos.
     */
    private function accionesProvisionales($ahora): void
    {
        $modulo = DB::table('modulos')->where('clave', 'colaboradores')->value('id');
        if ($modulo === null) {
            return;
        }

        $accion = function (string $clave, string $nombre) use ($ahora) {
            return DB::table('acciones')->where('clave', $clave)->value('id')
                ?? DB::table('acciones')->insertGetId([
                    'clave' => $clave, 'nombre' => $nombre, 'orden' => (int) DB::table('acciones')->max('orden') + 1,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
        };
        foreach (['provisional' => 'Alta provisional', 'aprobar' => 'Aprobar'] as $clave => $nombre) {
            DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion($clave, $nombre), 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        $permiso = fn (string $clave) => DB::table('modulo_acciones as ma')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('ma.modulo_id', $modulo)->where('a.clave', $clave)->value('ma.id');
        [$ver, $provisional, $aprobar, $editar] = [$permiso('ver'), $permiso('provisional'), $permiso('aprobar'), $permiso('editar')];

        $otorgar = function (iterable $roles, int $permisoId) use ($ahora) {
            foreach ($roles as $rol) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $rol->id, 'modulo_accion_id' => $permisoId, 'alcance' => $rol->alcance,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        };

        $administradores = DB::table('roles as r')->join('rol_permisos as rp', 'rp.rol_id', '=', 'r.id')
            ->where('r.nombre', 'Administrador')->where('rp.modulo_accion_id', $editar)->get(['r.id', 'rp.alcance']);
        $otorgar($administradores, $provisional);
        $otorgar($administradores, $aprobar);

        $otorgar(DB::table('roles')->where('nombre', 'Director')->get(['id'])->map(fn ($r) => (object) ['id' => $r->id, 'alcance' => Alcance::Empresa->value]), $aprobar);

        $caseta = DB::table('roles')->whereIn('nombre', ['Jefe de seguridad', 'Asistente', 'Supervisor', 'Agente'])->get(['id'])
            ->map(fn ($r) => (object) ['id' => $r->id, 'alcance' => Alcance::Sede->value]);
        $otorgar($caseta, $ver);
        $otorgar($caseta, $provisional);
    }

    private function plantillaRecursosHumanos(): void
    {
        $plantilla = RolesPlantillaSeeder::asegurarPlantilla('Recursos Humanos');
        if ($plantilla === null) {
            return;
        }

        $plantilla = Rol::with('permisos')->findOrFail($plantilla->id);
        $provisionar = app(ProvisionarEmpresa::class);
        foreach (DB::table('empresas')->orderBy('id')->pluck('id') as $empresaId) {
            $provisionar->copiarPlantillaSiFalta($plantilla, (int) $empresaId);
        }
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fusionado_en_id');
            $table->dropIndex(['empresa_id', 'provisional']);
            $table->dropColumn(['provisional', 'validado_por', 'validado_en']);
        });
        // El reacomodo de áreas, menús y permisos no se revierte: solo agrupa y agrega.
    }
};
