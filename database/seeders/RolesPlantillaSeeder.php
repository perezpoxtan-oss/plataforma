<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\Alcance;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

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

    /**
     * El Asistente de seguridad (SEGCAT: "Apoyo de Gestión Local") captura y
     * corrige tanto en Operación como en Padrones —mantiene los catálogos de
     * su sede—, imprime y exporta. No elimina, no aprueba ni firma.
     */
    public const ACCIONES_ASISTENTE = ['ver', 'crear', 'editar', 'imprimir', 'exportar'];

    /**
     * La caseta (Jefe, Asistente, Supervisor y Agente) consulta a los
     * colaboradores de su sede y da de alta provisionales cuando la persona aún
     * no existe; Recursos Humanos los valida.
     */
    public const COLABORADOR_PROVISIONAL = ['colaboradores.ver', 'colaboradores.provisional'];

    /** Recursos Humanos consulta estos catálogos de Dirección para capturar al personal. */
    public const CONSULTA_RH = ['sedes', 'departamentos', 'puestos', 'turnos'];

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

    /**
     * nombre => [nivel, descripción, regla(ModuloAccion): ?Alcance]
     *
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    public static function definiciones(): array
    {
        $deSeguridad = fn ($ma) => $ma->modulo->area->clave === 'seguridad';
        $provisional = fn ($ma) => in_array($ma->clave(), self::COLABORADOR_PROVISIONAL, true);
        // El Agente trabaja en los menús de caseta: Operación y Padrones (no en reportes)
        $deCaseta = fn ($ma) => $deSeguridad($ma) && in_array(self::menuDe($ma->modulo), ['operacion', 'padrones'], true);

        return [
            'Administrador' => [10, 'Administra toda su empresa', fn ($ma) => Alcance::Empresa],
            'Director' => [20, 'Consulta y aprueba en toda la empresa', fn ($ma) => in_array($ma->accion->clave, ['ver', 'aprobar', 'exportar', 'imprimir'], true) ? Alcance::Empresa : null],
            'Recursos Humanos' => [25, 'Administra el personal y valida las altas provisionales de la caseta', fn ($ma) => $ma->modulo->area->clave === 'recursos_humanos'
                ? Alcance::Empresa
                : (in_array($ma->modulo->clave, self::CONSULTA_RH, true) && $ma->accion->clave === 'ver' ? Alcance::Empresa : null)],
            'Jefe de seguridad' => [30, 'Opera y supervisa seguridad en su sede', fn ($ma) => $deSeguridad($ma) || $provisional($ma)
                || in_array($ma->clave(), self::USUARIOS_JEFE, true) ? Alcance::Sede : null],
            'Asistente' => [40, 'Apoyo de gestión de seguridad en su sede', fn ($ma) => ($deSeguridad($ma)
                && in_array($ma->accion->clave, self::ACCIONES_ASISTENTE, true)) || $provisional($ma) ? Alcance::Sede : null],
            'Supervisor' => [50, 'Da seguimiento a la operación de su sede', fn ($ma) => ($deSeguridad($ma) && $ma->accion->clave !== 'eliminar') || $provisional($ma) ? Alcance::Sede : null],
            'Agente' => [60, 'Registra la operación de caseta', fn ($ma) => ($deCaseta($ma)
                && in_array($ma->accion->clave, self::esPadron($ma->modulo) ? self::ACCIONES_AGENTE_PADRONES : self::ACCIONES_AGENTE_OPERACION, true))
                || $provisional($ma) ? Alcance::Sede : null],
        ];
    }

    /**
     * Clave del menú donde aparece el módulo (los submódulos heredan el de su padre).
     */
    public static function menuDe(Modulo $modulo): ?string
    {
        return ($modulo->menu ?? $modulo->padre?->menu)?->clave;
    }

    public function run(): void
    {
        $todos = self::moduloAcciones();

        foreach (array_keys(self::definiciones()) as $nombre) {
            self::asegurarPlantilla($nombre, $todos);
        }
    }

    /**
     * Crea la plantilla indicada con sus permisos si todavía no existe.
     * Devuelve la plantilla (nueva o la que ya existía), o null si no se puede
     * crear: nombre desconocido, catálogo de módulos aún vacío, o su nivel ya
     * lo ocupa otra plantilla.
     *
     * @param  Collection<int, ModuloAccion>|null  $todos
     */
    public static function asegurarPlantilla(string $nombre, ?Collection $todos = null): ?Rol
    {
        $definicion = self::definiciones()[$nombre] ?? null;
        if ($definicion === null) {
            return null;
        }
        [$nivel, $descripcion, $regla] = $definicion;

        $existente = Rol::plantillas()->where('nombre', $nombre)->first();
        if ($existente !== null) {
            return $existente;
        }

        $todos ??= self::moduloAcciones();
        if ($todos->isEmpty() || Rol::plantillas()->where('nivel_jerarquia', $nivel)->exists()) {
            return null;
        }

        $rol = Rol::create(['empresa_id' => null, 'nombre' => $nombre, 'nivel_jerarquia' => $nivel, 'descripcion' => $descripcion]);

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

        return $rol;
    }

    /**
     * Los módulos de plataforma (Identidad...) son solo del Super Administrador.
     *
     * @return Collection<int, ModuloAccion>
     */
    private static function moduloAcciones(): Collection
    {
        return ModuloAccion::with('modulo.area', 'modulo.menu', 'modulo.padre.menu', 'accion')->get()
            ->reject(fn ($ma) => $ma->modulo->tipo === Modulo::TIPO_PLATAFORMA)
            ->values();
    }
}
