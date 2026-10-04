<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\Alcance;
use Illuminate\Database\Seeder;

/**
 * Plantillas de rol que se copian a cada empresa nueva. Son un punto de
 * partida: cada cliente las ajusta desde la matriz de permisos.
 * Solo se crean si no existen; nunca se sobrescriben los cambios.
 */
class RolesPlantillaSeeder extends Seeder
{
    /** Lo que el Agente hace en los módulos de Operación (bitácoras, pases...). */
    public const ACCIONES_AGENTE_OPERACION = ['ver', 'crear', 'editar', 'imprimir', 'firmar'];

    /** En los Padrones (catálogos: llaves, gafetes, vehículos...) el Agente solo consulta. */
    public const ACCIONES_AGENTE_PADRONES = ['ver'];

    /** El Jefe de seguridad ve los usuarios de su sede y los desbloquea. */
    public const USUARIOS_JEFE = ['usuarios.ver', 'usuarios.desbloquear'];

    /**
     * Un módulo es de Padrones si está en ese menú (los submódulos heredan el
     * menú de su padre). Se decide por el menú, no por nombres de módulo.
     */
    public static function esPadron(Modulo $modulo): bool
    {
        $menu = $modulo->menu ?? $modulo->padre?->menu;

        return $menu?->clave === 'padrones';
    }

    public function run(): void
    {
        // Los modulos de plataforma (Identidad...) son solo del Super Administrador
        $todos = ModuloAccion::with('modulo.area', 'modulo.menu', 'modulo.padre.menu', 'accion')->get()
            ->reject(fn ($ma) => $ma->modulo->tipo === Modulo::TIPO_PLATAFORMA);

        $plantillas = [
            'Administrador' => [10, 'Administra toda su empresa', fn ($ma) => Alcance::Empresa],
            'Director' => [20, 'Consulta y aprueba en toda la empresa', fn ($ma) => in_array($ma->accion->clave, ['ver', 'aprobar', 'exportar', 'imprimir'], true) ? Alcance::Empresa : null],
            'Jefe de seguridad' => [30, 'Opera y supervisa seguridad en su sede', fn ($ma) => in_array($ma->modulo->area->clave, ['seguridad', 'reportes'], true)
                || in_array($ma->clave(), self::USUARIOS_JEFE, true) ? Alcance::Sede : null],
            'Supervisor' => [50, 'Da seguimiento a la operación de su sede', fn ($ma) => in_array($ma->modulo->area->clave, ['seguridad', 'reportes'], true) && $ma->accion->clave !== 'eliminar' ? Alcance::Sede : null],
            'Agente' => [60, 'Registra la operación de caseta', fn ($ma) => $ma->modulo->area->clave === 'seguridad'
                && in_array($ma->accion->clave, self::esPadron($ma->modulo) ? self::ACCIONES_AGENTE_PADRONES : self::ACCIONES_AGENTE_OPERACION, true) ? Alcance::Sede : null],
        ];

        foreach ($plantillas as $nombre => [$nivel, $descripcion, $regla]) {
            $rol = Rol::firstOrCreate(
                ['empresa_id' => null, 'nombre' => $nombre],
                ['nivel_jerarquia' => $nivel, 'descripcion' => $descripcion],
            );

            if (! $rol->wasRecentlyCreated) {
                continue;
            }

            foreach ($todos as $moduloAccion) {
                $alcance = $regla($moduloAccion);
                if ($alcance !== null) {
                    RolPermiso::create([
                        'rol_id' => $rol->id,
                        'modulo_accion_id' => $moduloAccion->id,
                        'alcance' => $alcance,
                    ]);
                }
            }
        }
    }
}
