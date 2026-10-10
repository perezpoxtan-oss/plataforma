<?php

namespace App\Services\Recepcion;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Postulacion;
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
     * con Recursos Humanos, más candidatos de hoy que aún no se atienden y que
     * no pasaron por caseta (RR. HH., kiosco o internet).
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

        $accesos = Acceso::with(['sede:id,nombre', 'postulacion.candidato.departamento:id,nombre', 'postulacion.candidato.puesto:id,nombre',
            'candidatoRecepcion.departamento:id,nombre', 'candidatoRecepcion.puesto:id,nombre'])
            ->where('tipo', 'visitante')->where('motivo_visita', 'rh')->where('movimiento', 'entrada')
            ->whereIn('estado', Acceso::ESTADOS_ABIERTOS)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
            ->orderBy('entrada_at')->limit(200)->get();
        // Solicitudes de «Que pase» pendientes de esos accesos
        $pendientes = Autorizacion::where('tipo', 'recepcion')->where('estado', 'pendiente')->whereIn('acceso_id', $accesos->pluck('id'))
            ->pluck('id', 'acceso_id');
        // ¿Ya había venido antes? (otra visita ligada a su ficha)
        $fichas = $accesos->map(fn (Acceso $a) => $a->postulacion?->candidato_id)->filter()->unique()->values();
        $primerAcceso = $fichas->isEmpty() ? collect() : Acceso::join('postulaciones', 'postulaciones.id', '=', 'accesos.postulacion_id')
            ->whereIn('postulaciones.candidato_id', $fichas)->groupBy('postulaciones.candidato_id')
            ->selectRaw('postulaciones.candidato_id as ficha, MIN(accesos.id) as primero')->pluck('primero', 'ficha');

        $filas = $accesos->map(function (Acceso $a) use ($verCv, $pendientes, $primerAcceso) {
            $c = $a->postulacion?->candidato ?? $a->candidatoRecepcion;
            $antes = $c !== null && ((int) ($primerAcceso[$c->id] ?? $a->id) < $a->id || $c->origen !== 'caseta'
                || ($c->created_at !== null && $a->entrada_at !== null && $c->created_at->lt($a->entrada_at->copy()->subMinutes(5))));

            return $this->fila($c, $a, $verCv, $pendientes[$a->id] ?? null, $antes);
        });

        // Candidatos de hoy que no pasaron por caseta (RR. HH., kiosco o internet) y siguen sin atender
        $desde = now()->setTimezone($this->hora->zona())->startOfDay()->utc();
        $enLista = $filas->pluck('candidato_id')->filter()->all();
        $sinAcceso = Candidato::with(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'postulacionActiva'])
            ->whereIn('etapa', ['registrado', 'revision'])
            ->where(fn ($q) => $q->where('llegada_en', '>=', $desde)->orWhere(fn ($x) => $x->whereNull('llegada_en')->where('created_at', '>=', $desde)))
            ->whereNotIn('id', $enLista ?: [0])
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->orderBy('id')->limit(100)->get()
            // Solo si su postulación de hoy no llegó por caseta (esas ya se ven con su acceso)
            ->filter(fn (Candidato $c) => $c->acceso_id === null || ($c->postulacionActiva !== null && ! Acceso::where('postulacion_id', $c->postulacionActiva->id)->exists()));
        foreach ($sinAcceso as $c) {
            $filas->push($this->fila($c, null, $verCv, null, $c->postulaciones()->count() > 1));
        }

        return $filas->sortBy('llegada_ts')->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(?Candidato $c, ?Acceso $a, bool $verCv, ?int $autorizacionId, bool $vinoAntes): array
    {
        $llegada = $a?->entrada_at ?? $c?->llegada_en ?? $c?->created_at;
        $minutos = (int) max(0, $llegada ? $llegada->diffInMinutes(now()) : 0);
        $esperandoRh = $a !== null && $a->estado === 'pendiente' && in_array($a->autorizacion, ['esperando', 'espera'], true);
        $atendido = ! $esperandoRh && $c !== null && ! in_array($c->etapa, ['registrado'], true);
        $vieneA = $a?->viene_a ?? ($c ? 'busca_empleo' : 'tramite');
        $textoVieneA = Acceso::VIENE_A_CORTO[$vieneA] ?? 'Trámite';
        if ($vieneA === 'busca_empleo' && $c !== null) {
            // Sin acceso: no pasó por caseta (internet, kiosco o la capturó RR. HH.)
            $textoVieneA .= $a === null
                ? match ($c->postulacionActiva?->origen ?? $c->origen) {
                    'web' => ' — por internet', 'rh' => ' — registrado por RR. HH.', default => ' — en el kiosco'
                }
            : ($vinoAntes ? ' — ya vino antes' : ' — primera vez');
        }

        return [
            'clave' => ($a ? 'a'.$a->id : 'c'.$c?->id),
            'nombre' => $c?->nombre_completo ?? mb_convert_case(mb_strtolower((string) $a?->nombre), MB_CASE_TITLE),
            'tipo' => $c ? 'Candidato' : 'Trámite con RR. HH.',
            'es_candidato' => $c !== null,
            'viene_a' => $textoVieneA,
            'puesto' => $c?->puestoVisible(),
            'departamento' => $c?->departamento?->nombre,
            'sede' => $a?->sede?->nombre ?? $c?->sede?->nombre,
            'llegada' => $this->hora->formatear($llegada, 'H:i'),
            'llegada_ts' => $llegada?->getTimestamp() ?? 0,
            'minutos' => $minutos,
            'espera' => $atendido ? 'atendido' : ($minutos >= self::ESPERA_ROJA ? 'roja' : ($minutos >= self::ESPERA_AMBAR ? 'ambar' : 'normal')),
            'estado' => match (true) {
                $esperandoRh && $a->autorizacion === 'espera' => 'En caseta: le pidieron que espere',
                $esperandoRh => 'En caseta: espera que digas «Que pase»',
                $c !== null => $c->etiquetaEtapa(),
                $a?->estado === 'pendiente' => 'Pendiente en caseta',
                default => 'En sitio',
            },
            'etapa' => $c?->etapa,
            'autocaptura' => (bool) $c?->autocaptura_pendiente,
            'candidato_id' => $c?->id,
            'autorizacion_id' => $esperandoRh ? $autorizacionId : null,
            'ficha' => $c && $verCv ? route('candidatos.show', $c->id) : null,
            'tiene_foto' => (bool) ($a?->foto_persona),
            'foto' => $a?->foto_persona ? route('accesos.foto-persona', $a->id) : null,
        ];
    }

    /**
     * Contadores del panel: esperando, con RR. HH. (en revisión o entrevista de
     * RR. HH.), con el departamento (canalizados) y evaluados por el departamento.
     *
     * @return array{esperando: int, revision: int, departamento: int, evaluado: int}
     */
    public function contadores(User $actor, array $filas): array
    {
        $sedes = $this->sedes($actor, 'recepcion_rh.ver');
        $base = Candidato::query()->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes ?? []));

        return [
            'esperando' => count(array_filter($filas, fn ($f) => $f['espera'] !== 'atendido')),
            'revision' => (clone $base)->whereIn('etapa', Candidato::POR_ENTREVISTAR)->count(),
            'departamento' => (clone $base)->where('etapa', 'canalizado')->count(),
            'evaluado' => (clone $base)->where('etapa', 'evaluado')->count(),
        ];
    }

    /**
     * Mis pendientes de RR. HH. («Esperando en Recepción»): accesos de RR. HH.
     * que esperan su «Que pase» más candidatos de hoy sin atender, en las
     * sedes donde puede editar candidatos.
     */
    public function porAtender(User $actor): int
    {
        $sedes = $actor->can('candidatos.editar') ? $this->autorizador->sedesPermitidas($actor, 'candidatos.editar') : [];
        if ($sedes === []) {
            return 0;
        }
        $esperando = Acceso::query()->where('tipo', 'visitante')->where('motivo_visita', 'rh')->where('estado', 'pendiente')
            ->whereIn('autorizacion', ['esperando', 'espera'])->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
            ->pluck('id');
        $desde = now()->setTimezone($this->hora->zona())->startOfDay()->utc();
        $sinAtender = Candidato::query()->where('etapa', 'registrado')
            ->where(fn ($q) => $q->where('llegada_en', '>=', $desde)->orWhere(fn ($x) => $x->whereNull('llegada_en')->where('created_at', '>=', $desde)))
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
            // Quien espera el «Que pase» ya se contó por su acceso
            ->where(fn ($q) => $q->whereNull('acceso_id')->orWhereNotIn('acceso_id', $esperando->all() ?: [0]))
            ->count();

        return $esperando->count() + $sinAtender;
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
        $porDepto = $auts->groupBy(fn ($a) => $a->tipo === 'recepcion' ? 'Recursos Humanos (caseta)' : ($a->departamento?->nombre ?? '—'))->map(function (Collection $g, $nombre) {
            $respondidas = $g->whereNotNull('respondida_en')->where('respuesta_medio', '!=', 'rh');

            return [
                'departamento' => $nombre,
                'total' => $g->count(),
                'visitas' => $g->where('tipo', 'visita')->count(),
                'candidatos' => $g->where('tipo', 'candidato')->count(),
                'pendientes' => $g->where('estado', 'pendiente')->count(),
                'autorizadas' => $g->whereIn('estado', ['autorizada', 'entrevista'])->count(), // «entrevista»: proceso anterior
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
            ->get(['id', 'etapa', 'origen', 'llegada_en', 'avisado_rh_en', 'revision_en', 'created_at']);
        // Fase 2: las fechas de entrevista, canalización y evaluación viven en la postulación
        $posts = $limitar(Postulacion::query())->whereBetween('created_at', [$inicio, $fin])->limit(5000)
            ->get(['id', 'candidato_id', 'created_at', 'entrevista_rh_en', 'canalizado_en', 'evaluado_en']);
        $llegadas = $cands->pluck('llegada_en', 'id');
        foreach ($posts as $p) {
            $p->setAttribute('llegada', $llegadas[$p->candidato_id] ?? $p->created_at);
        }
        $promedio = function (Collection $filas, string $de, string $a): ?int {
            $tiempos = $filas->filter(fn ($c) => $c->{$de} && $c->{$a} && $c->{$a}->greaterThanOrEqualTo($c->{$de}))->map(fn ($c) => $c->{$de}->diffInMinutes($c->{$a}));

            return $tiempos->isEmpty() ? null : (int) round($tiempos->avg());
        };

        return [
            'departamentos' => $porDepto,
            'dias' => $porDia,
            'candidatos' => [
                'total' => $cands->count(),
                'por_etapa' => collect(Candidato::ETAPAS)->map(fn ($nombre, $clave) => ['etapa' => $nombre, 'total' => $cands->where('etapa', $clave)->count()])->values()->all(),
                'llegada_a_aviso' => $promedio($cands, 'llegada_en', 'avisado_rh_en'),
                'llegada_a_atencion' => $promedio($cands, 'llegada_en', 'revision_en'),
                'canalizado_a_evaluacion' => $promedio($posts, 'canalizado_en', 'evaluado_en'),
                'llegada_a_entrevista' => $promedio($posts, 'llegada', 'entrevista_rh_en'),
            ],
            'visitas' => [
                'total' => $auts->where('tipo', 'visita')->count(),
                'promedio' => ($r = $auts->where('tipo', 'visita')->whereNotNull('respondida_en'))->isEmpty() ? null : (int) round($r->avg(fn ($a) => $a->minutosEspera())),
            ],
        ];
    }
}
