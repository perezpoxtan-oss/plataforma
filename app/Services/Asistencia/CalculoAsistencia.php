<?php

namespace App\Services\Asistencia;

use App\Models\AsistenciaJustificacion;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Turno;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Asistencia (ADR-0024): cruza las entradas y salidas de cada colaborador
 * (MarcajesColaborador, la misma fuente que la Bitácora de accesos) con su
 * turno y da, por día: hora de entrada y de salida, retardo, salida
 * anticipada, falta y horas trabajadas. Nada se guarda: se calcula al
 * consultar (lo único guardado son las justificaciones).
 *
 * Reglas (docs/tecnico/asistencia.md):
 *  - El día de un turno es el día en que EMPIEZA: el nocturno de 22:00 a
 *    06:00 del lunes cuenta el lunes aunque la salida sea el martes.
 *  - Una entrada pertenece al turno si ocurre desde 4 horas antes de su
 *    inicio (o menos, si el turno siguiente empieza antes) hasta su fin.
 *    La primera es la hora de entrada; la salida es la de la última.
 *  - Retardo: la entrada pasó la tolerancia (de fábrica 10 min). Salida
 *    anticipada: salió antes del fin menos la tolerancia.
 *  - Falta: terminó el turno y no hubo entrada. Mientras el turno corre,
 *    «No ha llegado»; antes de la tolerancia, «Por llegar».
 *  - Horas trabajadas: la suma de cada entrada con su salida (si salió a
 *    comer y volvió, no cuenta ese rato). Sin salida registrada no se suman.
 *  - Los días que el turno no trabaja son «Descanso»; antes del alta del
 *    colaborador no se cuenta nada.
 */
class CalculoAsistencia
{
    public const ESTADOS = [
        'asistio' => 'Asistió', 'sin_llegar' => 'No ha llegado', 'por_llegar' => 'Por llegar', 'falta' => 'Falta',
        'descanso' => 'Descanso', 'sin_turno' => 'Sin turno', 'antes_de_alta' => 'Antes de su alta',
    ];

    /** Minutos antes del inicio del turno desde los que una entrada ya cuenta para él. */
    public const ANTES_MINUTOS = 240;

    public function __construct(
        private readonly MarcajesColaborador $marcajes,
        private readonly AjustesAsistencia $ajustes,
    ) {}

    /**
     * @param  Collection<int, Colaborador>  $colaboradores  con turno y sede cargados
     * @return array<int, list<array<string, mixed>>> colaborador_id => días
     */
    public function dias(Collection $colaboradores, string $desde, string $hasta, ?Empresa $empresa, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now();
        $tolerancia = $this->ajustes->tolerancia($empresa);
        $ids = $colaboradores->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inicioRango = CarbonImmutable::parse($desde, 'UTC')->subDay();
        $finRango = CarbonImmutable::parse($hasta, 'UTC')->addDays(3);
        $marcajes = $this->marcajes->marcajes($ids, $inicioRango, $finRango);
        $justificaciones = AsistenciaJustificacion::query()->whereIn('colaborador_id', $ids)
            ->whereBetween('fecha', [$desde, $hasta])->get()
            ->groupBy(fn ($j) => $j->colaborador_id.'|'.$j->fecha->format('Y-m-d'));
        $zonaEmpresa = $empresa?->zona_horaria ?: HoraLocal::ZONA_PLATAFORMA;

        $resultado = [];
        foreach ($colaboradores as $c) {
            $zona = $c->sede?->zona_horaria ?: $zonaEmpresa;
            $alta = $c->created_at ? CarbonImmutable::parse($c->created_at)->setTimezone($zona)->format('Y-m-d') : null;
            $propios = $marcajes->get($c->id, []);
            $dias = [];
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $desde, $zona);
            $ultimo = CarbonImmutable::createFromFormat('!Y-m-d', $hasta, $zona);
            while ($fecha->lessThanOrEqualTo($ultimo)) {
                $clave = $fecha->format('Y-m-d');
                $dias[] = $this->dia($c->turno?->activo ? $c->turno : null, $fecha, $zona, $propios, $tolerancia, $ahora,
                    $alta !== null && $clave < $alta, $justificaciones->get($c->id.'|'.$clave, collect()));
                $fecha = $fecha->addDay();
            }
            $resultado[(int) $c->id] = $dias;
        }

        return $resultado;
    }

    /**
     * @param  list<array{entrada: CarbonImmutable, salida: ?CarbonImmutable}>  $marcajes
     * @param  Collection<int, AsistenciaJustificacion>  $justificadas
     * @return array<string, mixed>
     */
    private function dia(?Turno $turno, CarbonImmutable $fecha, string $zona, array $marcajes, int $tolerancia, CarbonImmutable $ahora, bool $antesDeAlta, Collection $justificadas): array
    {
        $d = [
            'fecha' => $fecha->format('Y-m-d'), 'dia' => $fecha->dayOfWeekIso, 'estado' => 'sin_turno',
            'turno' => $turno?->nombre, 'horario' => $turno ? $turno->inicio().' a '.$turno->fin() : null, 'cruza' => (bool) $turno?->cruzaMedianoche(),
            'inicio' => null, 'fin' => null, 'entrada' => null, 'salida' => null, 'sin_salida' => false,
            'retardo' => 0, 'anticipada' => 0, 'trabajados' => 0, 'marcajes' => 0,
            'justificaciones' => $justificadas->mapWithKeys(fn ($j) => [$j->tipo => [
                'motivo' => $j->motivo, 'texto' => $j->textoMotivo(), 'nota' => $j->nota,
            ]])->all(),
            'por_justificar' => [],
        ];

        if ($turno === null) {
            [$desde, $hasta] = [$fecha, $fecha->addDay()];
        } else {
            [$h, $m] = array_map('intval', explode(':', $turno->inicio()));
            $inicio = $fecha->setTime($h, $m);
            $fin = $inicio->addMinutes($turno->minutos());
            $d['inicio'] = $inicio;
            $d['fin'] = $fin;
            // Hasta 4 h antes, sin pisar el turno del día anterior
            [$desde, $hasta] = [$inicio->subMinutes(min(self::ANTES_MINUTOS, 1440 - $turno->minutos())), $fin];
        }

        $delDia = array_values(array_filter($marcajes, fn ($mj) => $mj['entrada']->greaterThanOrEqualTo($desde->utc()) && $mj['entrada']->lessThan($hasta->utc())));
        if ($delDia !== []) {
            $d['marcajes'] = count($delDia);
            $d['entrada'] = $delDia[0]['entrada']->setTimezone($zona);
            $ultimo = $delDia[count($delDia) - 1];
            $d['sin_salida'] = $ultimo['salida'] === null;
            $d['salida'] = $ultimo['salida']?->setTimezone($zona);
            foreach ($delDia as $mj) {
                if ($mj['salida'] !== null) {
                    $d['trabajados'] += (int) max(0, intdiv($mj['salida']->getTimestamp() - $mj['entrada']->getTimestamp(), 60));
                }
            }
        }

        if ($antesDeAlta) {
            $d['estado'] = 'antes_de_alta';

            return $d;
        }
        if ($turno === null) {
            return $d;
        }
        if (! $turno->trabajaEl($fecha->dayOfWeekIso)) {
            $d['estado'] = 'descanso';

            return $d;
        }

        if ($delDia === []) {
            $d['estado'] = match (true) {
                $ahora->lessThan($d['inicio']->addMinutes($tolerancia)) => 'por_llegar',
                $ahora->lessThan($d['fin']) => 'sin_llegar',
                default => 'falta',
            };
        } else {
            $d['estado'] = 'asistio';
            $llegada = intdiv($d['entrada']->getTimestamp() - $d['inicio']->getTimestamp(), 60);
            $d['retardo'] = $llegada > $tolerancia ? $llegada : 0;
            if ($d['salida'] !== null) {
                $antes = intdiv($d['fin']->getTimestamp() - $d['salida']->getTimestamp(), 60);
                $d['anticipada'] = $antes > $tolerancia ? $antes : 0;
            }
        }

        foreach (['falta' => in_array($d['estado'], ['falta', 'sin_llegar'], true), 'retardo' => $d['retardo'] > 0, 'salida_anticipada' => $d['anticipada'] > 0] as $tipo => $hay) {
            if ($hay && ! isset($d['justificaciones'][$tipo])) {
                $d['por_justificar'][] = $tipo;
            }
        }

        return $d;
    }

    /**
     * Totales de un periodo (lo que pide la nómina externa).
     *
     * @param  list<array<string, mixed>>  $dias
     * @return array<string, int>
     */
    public static function resumen(array $dias): array
    {
        $r = ['programados' => 0, 'asistencias' => 0, 'faltas' => 0, 'faltas_justificadas' => 0, 'retardos' => 0, 'retardos_justificados' => 0,
            'minutos_retardo' => 0, 'anticipadas' => 0, 'descansos' => 0, 'trabajados' => 0];
        foreach ($dias as $d) {
            $r['trabajados'] += $d['trabajados'];
            if ($d['estado'] === 'descanso') {
                $r['descansos']++;

                continue;
            }
            if (! in_array($d['estado'], ['asistio', 'falta', 'sin_llegar'], true)) {
                continue;
            }
            $r['programados']++;
            if ($d['estado'] === 'asistio') {
                $r['asistencias']++;
            } elseif ($d['estado'] === 'falta') {
                isset($d['justificaciones']['falta']) ? $r['faltas_justificadas']++ : $r['faltas']++;
            }
            if ($d['retardo'] > 0) {
                isset($d['justificaciones']['retardo']) ? $r['retardos_justificados']++ : $r['retardos']++;
                $r['minutos_retardo'] += $d['retardo'];
            }
            if ($d['anticipada'] > 0) {
                $r['anticipadas']++;
            }
        }

        return $r;
    }

    /** 465 → «7:45». */
    public static function horas(int $minutos): string
    {
        return intdiv($minutos, 60).':'.str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }
}
