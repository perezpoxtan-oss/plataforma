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
    public const CONSULTA_RH = ['sedes'];

    /** El Jefe de seguridad ve los usuarios de su sede y los desbloquea. */
    public const USUARIOS_JEFE = ['usuarios.ver', 'usuarios.desbloquear'];

    // Menús (lección 35): catálogos que se ven en otro menú pero siguen siendo padrón para la plantilla
    public const PADRONES_EN_OTRO_MENU = ['vouchers'];
    // Fin Menús

    /**
     * Un módulo es de Padrones si está en ese menú (los submódulos heredan el
     * menú de su padre). Se decide por el menú, no por nombres de módulo.
     */
    public static function esPadron(Modulo $modulo): bool
    {
        $menu = $modulo->menu ?? $modulo->padre?->menu;

        // Lección 35: Vouchers de reposición pasó al menú Operación, pero sus permisos de plantilla no cambian
        if (in_array($modulo->clave, self::PADRONES_EN_OTRO_MENU, true)) {
            return true;
        }

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

        return self::soloAdministradorBorra(self::reglaVacantes(self::reglaEtiquetasQr(self::reglaProcedimientos(self::reglaRecepcion(self::reglaSolicitudes([
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
            // Solicitante y Jefe de departamento: su regla la arma reglaSolicitudes()
            self::SOLICITANTE => [70, 'Levanta solicitudes (pases de salida) y consulta solo las suyas', fn ($ma) => null],
            self::JEFE_DEPARTAMENTO => [45, 'Aprueba y responde las solicitudes de su departamento en su sede', fn ($ma) => null],
        ]))))));
    }

    // Solicitudes: Solicitante y Jefe de departamento

    public const SOLICITANTE = 'Solicitante';

    public const JEFE_DEPARTAMENTO = 'Jefe de departamento';

    /**
     * Personal de cualquier departamento que no opera la caseta: solo levanta
     * solicitudes y ve las suyas. «Solo los propios» únicamente donde el
     * servicio del módulo filtra por quien lo registró (pases de salida); los
     * procedimientos se ven por sede (lo publicado que le aplica) porque con
     * «propios» solo vería los que él escribió. Mis pendientes y el Manual no
     * piden permiso propio.
     * rol => [módulo => [acciones => alcance]]
     */
    public const SOLICITUDES = [
        self::SOLICITANTE => [
            'pases_salida' => ['ver' => Alcance::Propios, 'crear' => Alcance::Propios],
            'procedimientos' => ['ver' => Alcance::Sede],
        ],
        // Lo del Solicitante, más: ve los pases de su sede para firmar los de su
        // gente (el circuito decide qué paso le toca por el departamento del
        // colaborador vinculado a su usuario), responde las autorizaciones de los
        // departamentos donde es responsable, pide vacantes (quedan en borrador
        // para que Recursos Humanos las revise y publique) y entrevista, evalúa
        // y elige a los candidatos que Recursos Humanos le canaliza (fase 2).
        self::JEFE_DEPARTAMENTO => [
            'pases_salida' => ['ver' => Alcance::Sede, 'crear' => Alcance::Propios, 'aprobar' => Alcance::Sede, 'imprimir' => Alcance::Sede],
            'procedimientos' => ['ver' => Alcance::Sede],
            'autorizaciones' => ['ver' => Alcance::Sede, 'responder' => Alcance::Sede],
            'vacantes' => ['ver' => Alcance::Sede, 'crear' => Alcance::Propios],
            'candidatos' => ['evaluar' => Alcance::Sede],
        ],
    ];

    /**
     * @param  array<string, array{0: int, 1: string, 2: Closure}>  $definiciones
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    private static function reglaSolicitudes(array $definiciones): array
    {
        foreach (self::SOLICITUDES as $nombre => $mapa) {
            $definiciones[$nombre][2] = fn ($ma) => $mapa[$ma->modulo->clave][$ma->accion->clave] ?? null;
        }

        return $definiciones;
    }
    // Fin Solicitudes

    // Recepción de candidatos y autorizaciones departamentales (ADR-0007)

    /** Módulos de Recepción: el CV es dato personal (solo Recursos Humanos y el Administrador lo ven completo). */
    public const MODULOS_RECEPCION = ['recepcion_rh', 'candidatos', 'autorizaciones'];

    /**
     * Excepciones a la regla general en esos módulos: el Director ve la
     * recepción y las métricas y responde autorizaciones, pero no los CV; el
     * Jefe de seguridad y el Supervisor responden las de su sede. Los tres
     * entrevistan y eligen a los candidatos que RR. HH. les canaliza
     * (candidatos.evaluar, fase 2) sin ver los CV completos.
     * Recursos Humanos (todo su menú) y el Administrador siguen la regla general.
     */
    public const RECEPCION = [
        'Director' => ['recepcion_rh' => [['ver'], Alcance::Empresa], 'autorizaciones' => [['ver', 'responder'], Alcance::Empresa],
            'candidatos' => [['evaluar'], Alcance::Empresa]],
        'Jefe de seguridad' => ['autorizaciones' => [['ver', 'responder'], Alcance::Sede], 'candidatos' => [['evaluar'], Alcance::Sede]],
        'Supervisor' => ['autorizaciones' => [['ver', 'responder'], Alcance::Sede], 'candidatos' => [['evaluar'], Alcance::Sede]],
    ];

    /**
     * @param  array<string, array{0: int, 1: string, 2: Closure}>  $definiciones
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    private static function reglaRecepcion(array $definiciones): array
    {
        foreach (self::RECEPCION as $nombre => $mapa) {
            $regla = $definiciones[$nombre][2];
            $definiciones[$nombre][2] = function ($ma) use ($mapa, $regla) {
                if (! in_array($ma->modulo->clave, self::MODULOS_RECEPCION, true)) {
                    return $regla($ma);
                }
                [$acciones, $alcance] = $mapa[$ma->modulo->clave] ?? [[], null];

                return in_array($ma->accion->clave, $acciones, true) ? $alcance : null;
            };
        }

        return $definiciones;
    }
    // Fin Recepción de candidatos

    // Procedimientos

    /**
     * Procedimientos (manual operativo) no sigue la regla general de
     * Seguridad: decisión del dueño del proyecto. rol => [acciones, alcance].
     * "borrar" lo sigue decidiendo soloAdministradorBorra().
     */
    public const PROCEDIMIENTOS = [
        'Administrador' => [['ver', 'crear', 'editar', 'eliminar', 'aprobar', 'borrar'], Alcance::Empresa],
        'Director' => [['ver', 'crear', 'editar', 'eliminar', 'aprobar'], Alcance::Empresa],
        'Jefe de seguridad' => [['ver', 'crear', 'editar', 'eliminar', 'aprobar'], Alcance::Sede],
        'Supervisor' => [['ver', 'crear', 'editar'], Alcance::Sede],
        'Asistente' => [['ver'], Alcance::Sede],
        'Agente' => [['ver'], Alcance::Sede],
        'Recursos Humanos' => [['ver'], Alcance::Empresa],
        // Solicitudes: consultan lo publicado que aplica a su sede y firman su acuse
        self::SOLICITANTE => [['ver'], Alcance::Sede],
        self::JEFE_DEPARTAMENTO => [['ver'], Alcance::Sede],
    ];

    /**
     * @param  array<string, array{0: int, 1: string, 2: Closure}>  $definiciones
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    private static function reglaProcedimientos(array $definiciones): array
    {
        foreach ($definiciones as $nombre => [$nivel, $descripcion, $regla]) {
            $definiciones[$nombre][2] = function ($ma) use ($nombre, $regla) {
                if ($ma->modulo->clave !== 'procedimientos') {
                    return $regla($ma);
                }
                [$acciones, $alcance] = self::PROCEDIMIENTOS[$nombre] ?? [[], null];

                return in_array($ma->accion->clave, $acciones, true) ? $alcance : null;
            };
        }

        return $definiciones;
    }
    // Fin Procedimientos

    // Vacantes (lección 36)

    /**
     * Vacantes (bolsa de trabajo): Recursos Humanos y el Administrador siguen
     * la regla general (todo); el Director las administra en toda la empresa
     * (sin configurar la bolsa pública); la caseta y Seguridad las consultan
     * en su sede (ven las publicadas, imprimen el cartel). rol => [acciones, alcance].
     */
    public const VACANTES = [
        'Director' => [['ver', 'crear', 'editar', 'eliminar'], Alcance::Empresa],
        'Jefe de seguridad' => [['ver'], Alcance::Sede],
        'Asistente' => [['ver'], Alcance::Sede],
        'Supervisor' => [['ver'], Alcance::Sede],
        'Agente' => [['ver'], Alcance::Sede],
    ];

    /**
     * @param  array<string, array{0: int, 1: string, 2: Closure}>  $definiciones
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    private static function reglaVacantes(array $definiciones): array
    {
        foreach ($definiciones as $nombre => [$nivel, $descripcion, $regla]) {
            if (! isset(self::VACANTES[$nombre])) {
                continue;
            }
            [$acciones, $alcance] = self::VACANTES[$nombre];
            $definiciones[$nombre][2] = fn ($ma) => $ma->modulo->clave === 'vacantes'
                ? (in_array($ma->accion->clave, $acciones, true) ? $alcance : null)
                : $regla($ma);
        }

        return $definiciones;
    }
    // Fin Vacantes

    // Etiquetas QR (Ronda 7)

    /** «etiquetas_qr.configurar» (Plantillas del gestor de impresión): rol => alcance; nadie más. */
    public const ETIQUETAS_CONFIGURAR = ['Administrador' => Alcance::Empresa, 'Director' => Alcance::Empresa, 'Jefe de seguridad' => Alcance::Sede];

    /**
     * @param  array<string, array{0: int, 1: string, 2: Closure}>  $definiciones
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    private static function reglaEtiquetasQr(array $definiciones): array
    {
        foreach ($definiciones as $nombre => [$nivel, $descripcion, $regla]) {
            $definiciones[$nombre][2] = fn ($ma) => $ma->modulo->clave === 'etiquetas_qr' && $ma->accion->clave === 'configurar'
                ? (self::ETIQUETAS_CONFIGURAR[$nombre] ?? null)
                : $regla($ma);
        }

        return $definiciones;
    }
    // Fin Etiquetas QR

    /**
     * "Eliminar definitivamente" (acción borrar) solo lo recibe el
     * Administrador, con alcance de empresa; ninguna otra plantilla.
     *
     * @param  array<string, array{0: int, 1: string, 2: Closure}>  $definiciones
     * @return array<string, array{0: int, 1: string, 2: Closure}>
     */
    private static function soloAdministradorBorra(array $definiciones): array
    {
        foreach ($definiciones as $nombre => [$nivel, $descripcion, $regla]) {
            if ($nombre !== 'Administrador') {
                $definiciones[$nombre][2] = fn ($ma) => $ma->accion->clave === 'borrar' ? null : $regla($ma);
            }
        }

        return $definiciones;
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
