<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisión del dueño (mapa de procesos): la plantilla «Jefe de seguridad»
 * trae «Bitácora de auditoría: Ver» con alcance de su sede.
 *
 * Solo toca la PLANTILLA (roles sin empresa), que es la que se copia a cada
 * empresa nueva. Los roles «Jefe de seguridad» de las empresas existentes no
 * cambian: cada cliente ya decidió sus permisos en la Matriz.
 *
 * En una instalación nueva no hace nada (el catálogo y las plantillas los
 * crean los seeders, ya con esta regla en RolesPlantillaSeeder).
 *
 * El aviso «movimientos_sensibles» encendido por omisión no necesita
 * migración: es el valor de Empresa::AVISOS y solo aplica a las empresas que
 * nunca guardaron ese ajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permiso = $this->auditoriaVer();
        if ($permiso === null) {
            return;
        }
        $ahora = now();

        foreach ($this->plantillasJefe() as $rolId) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $rolId,
                'modulo_accion_id' => $permiso,
                'alcance' => 'sede',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $permiso = $this->auditoriaVer();
        if ($permiso === null) {
            return;
        }

        DB::table('rol_permisos')->where('modulo_accion_id', $permiso)->where('alcance', 'sede')
            ->whereIn('rol_id', $this->plantillasJefe())->delete();
    }

    /** @return list<int> */
    private function plantillasJefe(): array
    {
        return DB::table('roles')->whereNull('empresa_id')->where('nombre', 'Jefe de seguridad')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function auditoriaVer(): ?int
    {
        $id = DB::table('modulo_acciones as ma')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', 'auditoria')->where('a.clave', 'ver')
            ->value('ma.id');

        return $id === null ? null : (int) $id;
    }
};
