<?php

namespace App\Support\BaseDatos;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Equipo;
use App\Models\LostFoundArticulo;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PrestamoLlave;
use App\Models\RecorridoPc;
use App\Models\Reserva;
use App\Models\Responsiva;
use App\Models\SolicitudArco;
use App\Support\EstadoActivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claves de texto críticas (las que filtran y cuentan los informes) con su
 * lista de valores permitidos, tomada SIEMPRE de las constantes de los
 * modelos. La base las protege con un CHECK (Restricciones::agregarChecks).
 *
 * Si se agrega un estado, etapa o categoría nueva a una constante, hace
 * falta una migración que actualice su CHECK:
 *   Restricciones::agregarChecks('tabla', ['columna' => CatalogoChecks::definiciones()['tabla']['columna']]);
 * La prueba IntegridadBaseDatosTest falla mientras no coincidan.
 * Ver docs/tecnico/integridad-bd.md, «CHECK».
 */
final class CatalogoChecks
{
    /** @return array<string, array<string, list<string>>> tabla => columna => valores */
    public static function definiciones(): array
    {
        return [
            'accesos' => ['estado' => array_keys(Acceso::ESTADOS)],
            'autorizaciones' => ['estado' => array_keys(Autorizacion::ESTADOS)],
            'candidatos' => ['etapa' => array_keys(Candidato::ETAPAS)],
            'postulaciones' => ['etapa' => array_keys(Candidato::ETAPAS)],
            'lost_found_articulos' => ['estatus' => array_keys(LostFoundArticulo::ESTATUS)],
            'movimientos_transporte' => [
                'tipo_movimiento' => array_keys(MovimientoTransporte::TIPOS),
                'estatus' => array_keys(MovimientoTransporte::ESTATUS),
            ],
            'novedades' => [
                'categoria' => array_keys(Novedad::CATEGORIAS),
                'estatus' => array_keys(Novedad::ESTATUS),
            ],
            'pases_salida' => ['estado' => array_keys(PaseSalida::ESTADOS)],
            'prestamos_llaves' => ['estado' => [PrestamoLlave::EN_USO, PrestamoLlave::DEVUELTA]],
            'recorridos_pc' => ['estatus' => array_keys(RecorridoPc::ESTATUS)],
            'reservas' => ['estado' => array_keys(Reserva::ESTADOS)],
            'responsivas' => ['estado' => [Responsiva::EN_CAMPO, Responsiva::DEVUELTA]],
            'solicitudes_arco' => ['estado' => array_keys(SolicitudArco::ESTADOS)],
        ];
    }

    /**
     * Todos los CHECK vigentes: los de la Etapa 1 (definiciones(), que usa tal
     * cual la migración 2026_11_09_000600 ya instalada) más los de la Etapa 2
     * (C-14), que pone su propia migración 2026_11_11_000100 porque sus
     * columnas aún no existen en 000600. Revisión Etapa 2, A-8.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function todas(): array
    {
        $todas = self::definiciones();
        $todas['equipos'] = ['estado' => array_keys(Equipo::ESTADOS)];
        $todas['gafetes'] = ['estado' => EstadoActivo::GUARDADOS_LLAVE_GAFETE];
        $todas['llaves'] = ['estado' => EstadoActivo::GUARDADOS_LLAVE_GAFETE];
        $todas['prestamos_llaves'] = ['estado' => [PrestamoLlave::EN_USO, PrestamoLlave::DEVUELTA, PrestamoLlave::NO_REGRESO]];
        ksort($todas);

        return $todas;
    }

    /**
     * Valores viejos conocidos => valor vigente (SEGCAT, etapas de antes de la
     * fase 2 de candidatos).
     *
     * @return array<string, string>
     */
    public static function sinonimos(string $tabla, string $columna): array
    {
        return match ("{$tabla}.{$columna}") {
            'candidatos.etapa', 'postulaciones.etapa' => Candidato::ETAPAS_ANTERIORES,
            'novedades.categoria' => Novedad::CATEGORIAS_SEGCAT,
            'novedades.estatus' => Novedad::ESTATUS_SEGCAT,
            'recorridos_pc.estatus' => RecorridoPc::ESTATUS_SEGCAT,
            default => [],
        };
    }

    /**
     * Pasa a su valor vigente lo que se escribió distinto (sinónimos,
     * mayúsculas, espacios, acentos) y cuenta lo que no se pudo reconocer.
     * No cambia nada que no tenga equivalente claro.
     *
     * @return array{normalizados: array<string, array<string, int>>, sin_reconocer: array<string, array<string, int>>}
     */
    public static function normalizar(bool $aplicar = true): array
    {
        $r = ['normalizados' => [], 'sin_reconocer' => []];
        foreach (self::definiciones() as $tabla => $columnas) {
            foreach ($columnas as $columna => $valores) {
                $raros = Restricciones::consultaFuera($tabla, $columna, $valores)
                    ->selectRaw(Restricciones::exacta($columna).' as valor')->distinct()->pluck('valor')->map(fn ($v) => (string) $v)->all();
                foreach ($raros as $raro) {
                    $nuevo = self::equivalente($tabla, $columna, $raro);
                    $exacto = fn () => DB::table($tabla)->whereRaw(Restricciones::exacta($columna).' = ?', [$raro]);
                    $filas = $exacto()->count();
                    if ($nuevo === null) {
                        $r['sin_reconocer']["{$tabla}.{$columna}"][$raro] = $filas;

                        continue;
                    }
                    if ($aplicar) {
                        $exacto()->update([$columna => $nuevo]);
                    }
                    $r['normalizados']["{$tabla}.{$columna}"]["{$raro} → {$nuevo}"] = $filas;
                }
            }
        }

        return $r;
    }

    /** Valor vigente para uno escrito distinto, o null si no hay uno claro. */
    public static function equivalente(string $tabla, string $columna, string $valor): ?string
    {
        $valores = self::definiciones()[$tabla][$columna] ?? [];
        $sinonimos = self::sinonimos($tabla, $columna);
        $clave = fn (string $v) => trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($v)))), '_');
        $buscado = $clave($valor);
        foreach ($sinonimos as $viejo => $vigente) {
            if ($clave((string) $viejo) === $buscado && in_array($vigente, $valores, true)) {
                return $vigente;
            }
        }
        foreach ($valores as $v) {
            if ($clave($v) === $buscado) {
                return $v;
            }
        }

        return null;
    }
}
