<?php

namespace App\Support;

use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;

/**
 * Lista única de «este módulo necesita este otro» (plan de correcciones,
 * C-02). Vive en el código y no en la base: se versiona con el sistema y la
 * prueba de CI la revisa (tests/Feature/Nucleo/DependenciasModulosTest).
 *
 * Solo se anotan las dependencias DURAS: sin ellas la pantalla no puede
 * guardar o su único uso desaparece. Las opcionales (un campo que se oculta
 * si no hay datos) no van aquí.
 *
 * Se usa en:
 *  1. Catálogo comercial: al guardar un producto se agregan sus requisitos
 *     («Se agregó X porque Y lo necesita»); al guardar un plan o paquete se
 *     avisa lo que le falta.
 *  2. Excepciones de un cliente: no se puede quitar un módulo que otro
 *     módulo activo necesita.
 *  3. ModulosEfectivos::calcular() y Empresas → Módulos: agregan lo indispensable.
 *  4. La página 403: «No viene en tu plan».
 *  5. La prueba de CI: ningún plan publicado deja una dependencia sin cubrir.
 */
final class DependenciasModulos
{
    /**
     * módulo => módulos que necesita (claves del catálogo de módulos).
     *
     * Colaboradores, Departamentos, Puestos, Turnos y Autorizaciones son de
     * Dirección (Estructura, C-01): vienen en todos los planes.
     */
    public const REQUIERE = [
        // Personas y organización
        'colaboradores' => ['departamentos'],
        'autorizaciones' => ['departamentos', 'usuarios'],
        // Caseta
        'accesos' => ['colaboradores', 'departamentos', 'autorizaciones'],
        'prestamo_llaves' => ['llaves', 'colaboradores'],
        'responsivas' => ['equipos', 'colaboradores'],
        'vouchers' => ['colaboradores'],
        'pases_salida' => ['colaboradores', 'departamentos'],
        // Transporte: el turno de la ruta es opcional (C-06)
        'transporte' => ['rutas'],
        // Incidentes: el «pendiente de turno» usa Turnos; los recorridos revisan los equipos de PC
        'novedades' => ['turnos'],
        'recorridos_pc' => ['novedades', 'equipos_pc'],
        'equipos_pc' => ['recorridos_pc'],
        'lost_found' => ['novedades'],
        'robo' => ['novedades'],
        // Procedimientos: la audiencia es el usuario (C-07); no necesita nada más
        'procedimientos' => [],
        // Reservas: las órdenes de servicio y montaje van a un departamento
        'reservas' => ['departamentos'],
        // Recursos Humanos
        'recepcion_rh' => ['candidatos', 'accesos'],
        'candidatos' => ['colaboradores', 'departamentos', 'puestos', 'vacantes'],
        'vacantes' => ['departamentos', 'puestos', 'candidatos'],
    ];

    /**
     * Acciones que se venden con OTRO módulo: el expediente y los datos
     * personales del colaborador (CURP, RFC, NSS, documentos) son del
     * producto Recursos Humanos aunque el directorio venga en todos los
     * planes (C-01). «modulo.accion» => módulo que debe estar activo.
     */
    public const ACCIONES_CON_MODULO = [
        'colaboradores.datos_personales' => 'candidatos',
        // C-33: los accidentes del colaborador viven en Novedades (Incidentes)
        'colaboradores.accidentes' => 'novedades',
    ];

    /**
     * Revisión Etapa 1 (A-3): las altas provisionales de colaboradores que hace
     * la caseta las verifica Recursos Humanos solo si la empresa lo contrató
     * (Candidatos, como el expediente); si no, el administrador.
     */
    public const VERIFICA_ALTAS_CON = 'candidatos';

    /** «Recursos Humanos» o «el administrador», según lo contratado por la empresa de trabajo. */
    public static function quienVerificaAltas(?int $empresaId = null): string
    {
        $empresaId ??= app(Tenant::class)->empresaId();

        return $empresaId !== null && app(Autorizador::class)->moduloActivo((int) $empresaId, self::VERIFICA_ALTAS_CON)
            ? 'Recursos Humanos' : 'el administrador';
    }

    /**
     * Lo que necesita un módulo (directo).
     *
     * @return list<string>
     */
    public static function de(string $modulo): array
    {
        return self::REQUIERE[$modulo] ?? [];
    }

    /**
     * Agrega a la lista todo lo que sus módulos necesitan (también lo que
     * necesitan los agregados). Devuelve la lista completa y, para cada
     * agregado, quién lo pidió.
     *
     * @param  list<string>  $claves
     * @param  list<string>  $siempre  módulos que ya están (p. ej. los de Dirección): no se reportan como agregados
     * @return array{claves: list<string>, agregados: array<string, string>}
     */
    public static function completar(array $claves, array $siempre = []): array
    {
        $tiene = array_fill_keys(array_merge($claves, $siempre), true);
        $agregados = [];
        $pendientes = array_values(array_unique($claves));
        while ($pendientes !== []) {
            $modulo = array_shift($pendientes);
            foreach (self::de($modulo) as $necesita) {
                if (! isset($tiene[$necesita])) {
                    $tiene[$necesita] = true;
                    $agregados[$necesita] = $modulo;
                    $pendientes[] = $necesita;
                }
            }
        }

        return ['claves' => array_values(array_unique(array_merge($claves, array_keys($agregados)))), 'agregados' => $agregados];
    }

    /**
     * Dependencias sin cubrir: módulo => lo que le falta.
     *
     * @param  list<string>  $claves
     * @return array<string, list<string>>
     */
    public static function faltantes(array $claves): array
    {
        $tiene = array_fill_keys($claves, true);
        $faltan = [];
        foreach ($claves as $modulo) {
            $sinCubrir = array_values(array_filter(self::de($modulo), fn ($n) => ! isset($tiene[$n])));
            if ($sinCubrir !== []) {
                $faltan[$modulo] = $sinCubrir;
            }
        }

        return $faltan;
    }

    /**
     * Módulos de la lista que necesitan a $modulo (para no dejar quitarlo).
     *
     * @param  list<string>  $claves
     * @return list<string>
     */
    public static function quienesLoNecesitan(string $modulo, array $claves): array
    {
        return array_values(array_filter($claves, fn ($c) => $c !== $modulo && in_array($modulo, self::de($c), true)));
    }

    /**
     * «Se agregó Rutas porque Bitácora de transporte lo necesita.»
     *
     * @param  array<string, string>  $agregados  agregado => quien lo pidió
     * @param  array<string, string>  $nombres  clave => nombre visible
     */
    public static function texto(array $agregados, array $nombres): string
    {
        return collect($agregados)
            ->map(fn ($quien, $agregado) => 'Se agregó '.($nombres[$agregado] ?? $agregado).' porque '.($nombres[$quien] ?? $quien).' lo necesita.')
            ->implode(' ');
    }
}
