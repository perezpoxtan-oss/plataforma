<?php

namespace App\Support;

/**
 * Plan de correcciones, C-14 (máquina de estados de grupo C, opción A): un
 * solo ciclo de vida para llaves, gafetes y equipos de seguridad.
 *
 *   (alta) → DISPONIBLE ─Prestar/Entregar→ EN USO ─Recibir→ DISPONIBLE
 *            DISPONIBLE ─Entregar fijo / responsable permanente→ ASIGNADO
 *            DISPONIBLE ─Dar salida (pase)→ FUERA ─Recibir (regreso)→ DISPONIBLE
 *            DISPONIBLE ─Enviar a mantenimiento→ EN MANTENIMIENTO ─Guardar→ DISPONIBLE
 *            EN USO / ASIGNADO / FUERA ─no regresó→ NO REGRESÓ ─Recibir→ DISPONIBLE
 *                                                   NO REGRESÓ ─Dar de baja (voucher)→ BAJA
 *            sin custodia ─Dar de baja (voucher)→ BAJA ─Reactivar→ DISPONIBLE
 *
 * Qué se guarda y qué se calcula:
 *  - Equipos: columna `estado` con los siete valores (Responsivas pone EN USO
 *    o ASIGNADO; Pases de salida, FUERA).
 *  - Llaves y gafetes: columna `estado` con DISPONIBLE, NO REGRESÓ o BAJA
 *    (`activo` se conserva y siempre coincide: falso solo en BAJA). EN USO y
 *    ASIGNADO se calculan del módulo dueño del movimiento: el préstamo vigente
 *    o el responsable permanente (llave) y el acceso abierto (gafete).
 *
 * Regla de los candados: un objeto EN USO, ASIGNADO o FUERA no se da de baja
 * desde su catálogo: el mensaje manda al módulo que lo tiene.
 */
final class EstadoActivo
{
    public const DISPONIBLE = 'disponible';

    public const EN_USO = 'en_uso';

    public const ASIGNADO = 'asignado';

    public const FUERA = 'fuera';

    public const EN_MANTENIMIENTO = 'en_mantenimiento';

    public const NO_REGRESO = 'no_regreso';

    public const BAJA = 'baja';

    /** clave => texto en pantalla (mayúsculas, como las insignias de siempre). */
    public const TEXTOS = [
        self::DISPONIBLE => 'DISPONIBLE',
        self::EN_USO => 'EN USO',
        self::ASIGNADO => 'ASIGNADO',
        self::FUERA => 'FUERA',
        self::EN_MANTENIMIENTO => 'EN MANTENIMIENTO',
        self::NO_REGRESO => 'NO REGRESÓ',
        self::BAJA => 'DE BAJA',
    ];

    /** Estados que se guardan en llaves y gafetes (los demás se calculan). */
    public const GUARDADOS_LLAVE_GAFETE = [self::DISPONIBLE, self::NO_REGRESO, self::BAJA];

    /** Alguien lo tiene: no se da de baja desde el catálogo. */
    public const EN_CUSTODIA = [self::EN_USO, self::ASIGNADO, self::FUERA];

    /** Equipos dentro de una responsiva sin recibir (Turno = EN USO, Fijo = ASIGNADO). */
    public const EN_RESGUARDO = [self::EN_USO, self::ASIGNADO];

    public static function texto(?string $estado): string
    {
        return self::TEXTOS[$estado] ?? mb_strtoupper((string) $estado);
    }

    /** Clase de color de la insignia (plataforma.css: .estado-activo-<clave>). */
    public static function clase(?string $estado): string
    {
        return 'estado-activo-'.str_replace('_', '-', (string) $estado);
    }
}
