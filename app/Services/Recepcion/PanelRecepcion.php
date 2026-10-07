<?php

namespace App\Services\Recepcion;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Panel «Recepción» de Recursos Humanos: quién llegó a caseta y va con
 * RR. HH. (candidatos y trámites), a qué viene, puesto, departamento, hora de
 * llegada, minutos esperando y estado. Se refresca cada 15 s con un JSON
 * pequeño (sin websockets: hosting compartido).
 *
 * Y las métricas (SLA): registro en caseta → aviso → respuesta → entrevista
 * o ingreso; promedio de espera por departamento y por día.
 */
class PanelRecepcion
{
    /** Minutos a partir de los cuales la espera se pinta en ámbar y en rojo. */
    public const ESPERA_AMBAR = 10;

    public const ESPERA_ROJA = 20;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly HoraLocal $hora,
    ) {}

    /** @return list<int>|null */
    private function sedes(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    /**
     * Personas en espera ahora: accesos abiertos de Personal externo que van
     * con Recursos Humanos, más candidatos de hoy que aún no se atienden.
     *
     * @return list<array<string, mixed>>
     */
    public function enEspera(User $actor): array
    {
        $sedes = $this->sedes($actor, 'recepcion_rh.ver');
        if ($sedes === []) {
            return [];
        }
        $verCv = $actor->can('candidatos.ver');

        $accesos = Acceso::with(['sede:id,nombre', 'candidatoRecepcion.departamento:id,nombre', 'candidatoRecepcion.puesto:id,nombre'])
            ->where('tipo', 'visitante')->where('motivo_visita', 'rh')->where('movimiento', 'entrada')
            ->whereIn('estado', Acceso::ESTADOS_ABIERTOS)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
            ->orderBy('entrada_at')->limit(200)->get();

        $filas = $accesos->map(function (Acceso $a) use ($verCv) {
            $c = $a->candidatoRecepcion;

            return $this->fila($c, $a, $verCv);
        });

        // Candidatos de hoy que no pasaron por caseta (RR. HH. o kiosco) y siguen sin atender
        $desde = now()->setTimezone($this->hora->zona())->startOfDay()->utc();
        $sinAcceso = Candidato::with(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->whereNull('acceso_id')->whereIn('etapa', ['registrado', 'revision'])->where('created_at', '>=', $desde)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->orderBy('id')->limit(100)->get();
        foreach ($sinAcceso as $c) {
            $filas->push($this->fila($c, null, $verCv));
        }

        return $filas->sortBy('llegada_ts')->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(?Candidato $c, ?Acceso $a, bool $verCv): array
    {
        $llegada = $a?->entrada_at ?? $c?->llegada_en ?? $c?->created_at;
        $minutos = (int) max(0, $llegada ? $llegada->diffInMinutes(now()) : 0);
        $atendido = $c !== null && ! in_array($c->etapa, ['registrado'], true);

        return [
            'clave' => ($a ? 'a'.$a->id : 'c'.$c?->id),
            'nombre' => $c?->nombre_completo ?? mb_convert_case(mb_strtolower((string) $a?->nombre), MB_CASE_TITLE),
            'tipo' => $c ? 'Candidato' : 'Trámite con RR. HH.',
            'es_candidato' => $c !== null,
            'puesto' => $c?->puestoVisible(),
            'departamento' => $c?->departamento?->nombre,
            'sede' => $a?->sede?->nombre ?? $c?->sede?->nombre,
            'llegada' => $this->hora->formatear($llegada, 'H:i'),
            'llegada_ts' => $llegada?->getTimestamp() ?? 0,
            'minutos' => $minutos,
            'espera' => $atendido ? 'atendido' : ($minutos >= self::ESPERA_ROJA ? 'roja' : ($minutos >= self::ESPERA_AMBAR ? 'ambar' : 'normal')),
            'estado' => $c ? $c->etiquetaEtapa() : ($a?->estado === 'pendiente' ? 'Pendiente en caseta' : 'En sitio'),
            'etapa' => $c?->etapa,
            'autocaptura' => (bool) $c?->autocaptura_pendiente,
            'candidato_id' => $c?->id,
            'ficha' => $c && $verCv ? route('candidatos.show', $c->id) : null,
            'tiene_foto' => (bool) ($a?->foto_persona),
            'foto' => $a?->foto_persona ? route('accesos.foto-persona', $a->id) : null,
        ];
    }

    /**
     * @return array{esperando: int, revision: int, departamento: int, entrevista: int}
     */
    public function contadores(User $actor, array $filas): array
    {
        $sedes = $this->sedes($actor, 'recepcion_rh.ver');
        $base = Candidato::query()->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes ?? []));

        return [
            'esperando' => count(array_filter($filas, fn ($f) => $f['espera'] !== 'atendido')),
            'revision' => (clone $base)->where('etapa', 'revision')->count(),
            'departamento' => (clone $base)->where('etapa', 'aprobado_rh')->count(),
            'entrevista' => (clone $base)->where('etapa', 'entrevista')->count(),
        ];
    }

    // ------------------------------------------------------------------ Métricas

    /**
     * Métricas de espera en un periodo (fechas locales Y-m-d, inclusive).
     *
     * @return array<string, mixed>
     */
    public function metricas(User $actor, string $desde, string $hasta, ?int $sede): array
    {
        $sedes = $this->sedes($actor, 'recepcion_rh.ver');
        $zona = $this->hora->zona();
        $inicio = Carbon::createFromFormat('Y-m-d', $desde, $zona)->startOfDay()->utc();
        $fin = Carbon::createFromFormat('Y-m-d', $hasta, $zona)->endOfDay()->utc();
        $limitar = fn ($q, string $col = 'sede_id') => $q->when($sedes !== null, fn ($x) => $x->whereIn($col, $sedes ?? []))->when($sede, fn ($x) => $x->where($col, $sede));

        /** @var Collection<int, Autorizacion> $auts */
        $auts = $limitar(Autorizacion::with('departamento:id,nombre'))->whereBetween('solicitada_en', [$inicio, $fin])->limit(5000)->get();
        $porDepto = $auts->groupBy(fn ($a) => $a->departamento?->nombre ?? '—')->map(function (Collection $g, $nombre) {
            $respondidas = $g->whereNotNull('respondida_en')->where('respuesta_medio', '!=', 'rh');

            return [
                'departamento' => $nombre,
                'total' => $g->count(),
                'visitas' => $g->where('tipo', 'visita')->count(),
                'candidatos' => $g->where('tipo', 'candidato')->count(),
                'pendientes' => $g->where('estado', 'pendiente')->count(),
                'autorizadas' => $g->whereIn('estado', ['autorizada', 'entrevista'])->count(),
                'rechazadas' => $g->where('estado', 'rechazada')->count(),
                'promedio' => $respondidas->isEmpty() ? null : (int) round($respondidas->avg(fn ($a) => $a->minutosEspera())),
                'maximo' => $respondidas->isEmpty() ? null : (int) $respondidas->max(fn ($a) => $a->minutosEspera()),
            ];
        })->sortByDesc('total')->values()->all();

        $porDia = $auts->groupBy(fn ($a) => $a->solicitada_en->copy()->setTimezone($zona)->format('Y-m-d'))->map(function (Collection $g, $dia) {
            $respondidas = $g->whereNotNull('respondida_en');

            return ['dia' => $dia, 'total' => $g->count(), 'promedio' => $respondidas->isEmpty() ? null : (int) round($respondidas->avg(fn ($a) => $a->minutosEspera()))];
        })->sortKeys()->values()->all();

        $cands = $limitar(Candidato::query())->whereBetween('created_at', [$inicio, $fin])->limit(5000)
            ->get(['id', 'etapa', 'origen', 'llegada_en', 'avisado_rh_en', 'revision_en', 'aprobado_rh_en', 'respuesta_departamento_en', 'entrevista_en', 'created_at']);
        $promedio = function (string $de, string $a) use ($cands): ?int {
            $tiempos = $cands->filter(fn ($c) => $c->{$de} && $c->{$a} && $c->{$a}->greaterThanOrEqualTo($c->{$de}))->map(fn ($c) => $c->{$de}->diffInMinutes($c->{$a}));

            return $tiempos->isEmpty() ? null : (int) round($tiempos->avg());
        };

        return [
            'departamentos' => $porDepto,
            'dias' => $porDia,
            'candidatos' => [
                'total' => $cands->count(),
                'por_etapa' => collect(Candidato::ETAPAS)->map(fn ($nombre, $clave) => ['etapa' => $nombre, 'total' => $cands->where('etapa', $clave)->count()])->values()->all(),
                'llegada_a_aviso' => $promedio('llegada_en', 'avisado_rh_en'),
                'llegada_a_atencion' => $promedio('llegada_en', 'revision_en'),
                'aprobado_a_respuesta' => $promedio('aprobado_rh_en', 'respuesta_departamento_en'),
                'llegada_a_entrevista' => $promedio('llegada_en', 'entrevista_en'),
            ],
            'visitas' => [
                'total' => $auts->where('tipo', 'visita')->count(),
                'promedio' => ($r = $auts->where('tipo', 'visita')->whereNotNull('respondida_en'))->isEmpty() ? null : (int) round($r->avg(fn ($a) => $a->minutosEspera())),
            ],
        ];
    }
}
