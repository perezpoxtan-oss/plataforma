<?php

namespace App\Services\Informes;

use App\Models\Acceso;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\NovedadNota;
use App\Models\PaseSalida;
use App\Models\PrestamoLlave;
use App\Models\RecorridoPc;
use App\Models\User;
use App\Services\Novedades\AjustesNovedades;
use App\Support\Terminos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Bitácora general del día (ADR-0017): una sola línea de tiempo con lo que
 * pasó en la sede ese día (en su hora local): entradas y salidas de Control
 * de accesos, tickets de Novedades (abiertos, notas y cierres), Transporte,
 * Préstamo de llaves y, para el cambio de turno, pases de salida que salieron
 * o regresaron y recorridos de Protección Civil. Cada tipo aparece solo si la
 * persona puede ver su módulo; nada de datos personales sensibles.
 */
class BitacoraDelDia
{
    public const PERMISO = 'bitacora_dia.ver';

    /** Tope por tipo (un día normal tiene muchos menos). */
    public const MAX_POR_TIPO = 500;

    /** clave => [texto, ícono, permiso del módulo de origen] */
    public const TIPOS = [
        'accesos' => ['Control de accesos', 'bi-door-open-fill', 'accesos.ver'],
        'novedades' => ['Novedades', 'bi-journal-text', 'novedades.ver'],
        'transporte' => ['Transporte', 'bi-bus-front-fill', 'transporte.ver'],
        'llaves' => ['Préstamo de llaves', 'bi-key-fill', 'prestamo_llaves.ver'],
        'pases' => ['Pases de salida', 'bi-box-arrow-right', 'pases_salida.ver'],
        'recorridos' => ['Recorridos de Protección Civil', 'bi-fire', 'recorridos_pc.ver'],
    ];

    /** @var array{0: string, 1: string} */
    private array $dia = ['', ''];

    private string $zona = 'UTC';

    public function __construct(private readonly AlcanceInformes $alcance) {}

    /** @return list<string> */
    public function visibles(User $actor): array
    {
        if (! $actor->can(self::PERMISO)) {
            return [];
        }

        return array_keys(array_filter(self::TIPOS, fn ($t) => $actor->can($t[2])));
    }

    /**
     * Eventos del día ordenados por hora y conteo por tipo.
     *
     * @param  list<int>|null  $sedes
     * @param  list<string>  $tipos  los que se quieren ver (dentro de los visibles)
     * @return array{eventos: list<array<string, mixed>>, conteos: array<string, int>}
     */
    public function eventos(User $actor, CarbonImmutable $fecha, string $zona, ?array $sedes, array $tipos): array
    {
        $this->zona = $zona;
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $fecha->format('Y-m-d'), $zona);
        $this->dia = [$inicio->utc()->format('Y-m-d H:i:s'), $inicio->addDay()->utc()->format('Y-m-d H:i:s')];

        $eventos = [];
        $conteos = [];
        foreach (array_intersect($this->visibles($actor), $tipos) as $tipo) {
            try {
                $lista = $this->{$tipo}($actor, $sedes);
            } catch (Throwable $e) {
                report($e); // ese tipo se omite; los demás se muestran

                continue;
            }
            $conteos[$tipo] = count($lista);
            array_push($eventos, ...$lista);
        }
        usort($eventos, fn ($a, $b) => [$a['momento'], $a['orden']] <=> [$b['momento'], $b['orden']]);

        return ['eventos' => $eventos, 'conteos' => $conteos];
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $q
     * @return Builder<T>
     */
    private function limitar(Builder $q, User $actor, string $tipo, ?array $sedes, string $columnaSede, ?string $autor = null): Builder
    {
        return $this->alcance->limitar($q, $actor, self::PERMISO, self::TIPOS[$tipo][2], $sedes, $columnaSede, $autor);
    }

    /** Registros con la columna dentro del día. */
    private function delDia(Builder $q, string $columna): Builder
    {
        return (clone $q)->where($columna, '>=', $this->dia[0])->where($columna, '<', $this->dia[1]);
    }

    /** @return array<string, mixed> */
    /** Nombre de cada tipo; el de la bitácora es el que la empresa le dio al módulo. */
    public static function nombreTipo(string $tipo): string
    {
        return $tipo === 'novedades' ? AjustesNovedades::nombreActual() : (self::TIPOS[$tipo][0] ?? $tipo);
    }

    private function evento(string $tipo, mixed $momento, string $etiqueta, string $texto, ?string $sede, ?string $enlace = null, int $orden = 0): array
    {
        $utc = CarbonImmutable::parse($momento instanceof \DateTimeInterface ? $momento->format('Y-m-d H:i:s') : (string) $momento, 'UTC');

        return [
            'tipo' => $tipo, 'momento' => $utc->format('Y-m-d H:i:s'), 'orden' => $orden, 'hora' => $utc->setTimezone($this->zona)->format('H:i'),
            'etiqueta' => $etiqueta, 'texto' => $texto, 'sede' => $sede ?? '—', 'enlace' => $enlace,
        ];
    }

    // ------------------------------------------------------------------- Tipos

    /** @return list<array<string, mixed>> */
    private function accesos(User $actor, ?array $sedes): array
    {
        $base = $this->limitar(Acceso::query()->with('sede:id,nombre'), $actor, 'accesos', $sedes, 'accesos.sede_id', 'accesos.creado_por');
        $columnas = ['accesos.id', 'accesos.sede_id', 'tipo', 'movimiento', 'nombre', 'empresa_procedencia', 'num_acompanantes', 'entrada_at', 'salida_at'];
        $eventos = [];
        $movimientos = ['entrada' => 'Entrada', 'salida_temporal' => 'Salida temporal', 'regreso' => 'Regreso'];
        foreach ($this->delDia($base, 'accesos.entrada_at')->orderBy('accesos.entrada_at')->limit(self::MAX_POR_TIPO)->get($columnas) as $a) {
            $texto = (Terminos::actual()->etiquetasTipo()[$a->tipo] ?? $a->tipo).': '.$a->nombre.($a->empresa_procedencia ? ' ('.$a->empresa_procedencia.')' : '')
                .((int) $a->num_acompanantes > 0 ? ' con '.$a->num_acompanantes.' acompañante'.((int) $a->num_acompanantes === 1 ? '' : 's') : '');
            $eventos[] = $this->evento('accesos', $a->entrada_at, $movimientos[$a->movimiento] ?? 'Entrada', $texto, $a->sede?->nombre);
        }
        foreach ($this->delDia($base->where('accesos.movimiento', 'entrada'), 'accesos.salida_at')->orderBy('accesos.salida_at')->limit(self::MAX_POR_TIPO)->get($columnas) as $a) {
            $eventos[] = $this->evento('accesos', $a->salida_at, 'Salida', (Terminos::actual()->etiquetasTipo()[$a->tipo] ?? $a->tipo).': '.$a->nombre, $a->sede?->nombre, null, 1);
        }

        return $eventos;
    }

    /** @return list<array<string, mixed>> */
    private function novedades(User $actor, ?array $sedes): array
    {
        $base = $this->limitar(Novedad::query()->with('sede:id,nombre'), $actor, 'novedades', $sedes, 'novedades.sede_id', 'novedades.creado_por');
        $columnas = ['novedades.id', 'numero', 'novedades.sede_id', 'categoria', 'estatus', 'ubicacion', 'descripcion', 'resolucion', 'novedades.created_at', 'cerrado_en'];
        $eventos = [];
        foreach ($this->delDia($base, 'novedades.created_at')->orderBy('novedades.created_at')->limit(self::MAX_POR_TIPO)->get($columnas) as $n) {
            $eventos[] = $this->evento('novedades', $n->created_at, 'Ticket abierto', $n->folio().' · '.$n->etiquetaCategoria().($n->ubicacion ? ' · '.$n->ubicacion : '').' — '.Indicadores::corto($n->descripcion, 110),
                $n->sede?->nombre, route('novedades.mostrar', $n->id));
        }
        foreach ($this->delDia($base, 'novedades.cerrado_en')->where('novedades.estatus', Novedad::RESUELTO)->orderBy('novedades.cerrado_en')->limit(self::MAX_POR_TIPO)->get($columnas) as $n) {
            $eventos[] = $this->evento('novedades', $n->cerrado_en, 'Ticket cerrado', $n->folio().' · '.$n->etiquetaCategoria().($n->resolucion ? ' — '.Indicadores::corto($n->resolucion, 110) : ''),
                $n->sede?->nombre, route('novedades.mostrar', $n->id), 2);
        }
        $notas = $this->delDia(NovedadNota::query()->where('novedad_notas.tipo', 'nota')->whereIn('novedad_notas.novedad_id', (clone $base)->select('novedades.id')), 'novedad_notas.created_at')
            ->with(['novedad:id,numero,sede_id', 'novedad.sede:id,nombre'])->orderBy('novedad_notas.created_at')->limit(self::MAX_POR_TIPO)
            ->get(['novedad_notas.id', 'novedad_id', 'autor_nombre', 'texto', 'novedad_notas.created_at']);
        foreach ($notas as $nota) {
            $eventos[] = $this->evento('novedades', $nota->created_at, 'Nota', ($nota->novedad?->folio() ?? '').' · '.($nota->autor_nombre ? $nota->autor_nombre.': ' : '').Indicadores::corto($nota->texto, 110),
                $nota->novedad?->sede?->nombre, $nota->novedad ? route('novedades.mostrar', $nota->novedad_id) : null, 1);
        }

        return $eventos;
    }

    /** @return list<array<string, mixed>> */
    private function transporte(User $actor, ?array $sedes): array
    {
        $q = $this->delDia($this->limitar(MovimientoTransporte::query()->where('movimientos_transporte.anulado', false)->with(['sede:id,nombre', 'ruta:id,nombre']),
            $actor, 'transporte', $sedes, 'movimientos_transporte.sede_id', 'movimientos_transporte.creado_por'), 'movimientos_transporte.created_at');

        return $q->orderBy('movimientos_transporte.created_at')->limit(self::MAX_POR_TIPO)
            ->get(['movimientos_transporte.id', 'movimientos_transporte.sede_id', 'ruta_id', 'tipo_movimiento', 'estatus', 'cantidad_pax', 'monto', 'movimientos_transporte.created_at'])
            ->map(fn (MovimientoTransporte $m) => $this->evento('transporte', $m->created_at, ucfirst(mb_strtolower($m->etiquetaTipo())),
                ($m->ruta?->nombre ?? 'Ruta').' · '.$m->etiquetaEstatus().' · '.(int) $m->cantidad_pax.' PAX'.((float) $m->monto > 0 ? ' · taxis $'.number_format((float) $m->monto, 2) : ''),
                $m->sede?->nombre, route('transporte.show', $m->id)))->all();
    }

    /** @return list<array<string, mixed>> */
    private function llaves(User $actor, ?array $sedes): array
    {
        $base = $this->limitar(PrestamoLlave::query()->where('prestamos_llaves.anulado', false)
            ->with(['sede:id,nombre', 'llave:id,nomenclatura,descripcion', 'colaborador:id,nombre,apellido_paterno,apellido_materno']),
            $actor, 'llaves', $sedes, 'prestamos_llaves.sede_id', 'prestamos_llaves.creado_por');
        $columnas = ['prestamos_llaves.id', 'prestamos_llaves.sede_id', 'llave_id', 'colaborador_id', 'prestado_en', 'devuelto_en'];
        $texto = fn (PrestamoLlave $p) => ($p->llave?->nomenclatura ?? 'Llave').($p->llave?->descripcion ? ' ('.$p->llave->descripcion.')' : '').' · '.$p->nombreReceptor();
        $eventos = [];
        foreach ($this->delDia($base, 'prestamos_llaves.prestado_en')->orderBy('prestamos_llaves.prestado_en')->limit(self::MAX_POR_TIPO)->get($columnas) as $p) {
            $eventos[] = $this->evento('llaves', $p->prestado_en, 'Préstamo', $texto($p), $p->sede?->nombre);
        }
        foreach ($this->delDia($base, 'prestamos_llaves.devuelto_en')->orderBy('prestamos_llaves.devuelto_en')->limit(self::MAX_POR_TIPO)->get($columnas) as $p) {
            $eventos[] = $this->evento('llaves', $p->devuelto_en, 'Devolución', $texto($p), $p->sede?->nombre, null, 1);
        }

        return $eventos;
    }

    /** @return list<array<string, mixed>> */
    private function pases(User $actor, ?array $sedes): array
    {
        $base = $this->limitar(PaseSalida::query()->with('sede:id,nombre'), $actor, 'pases', $sedes, 'pases_salida.sede_id', 'pases_salida.creado_por');
        $columnas = ['pases_salida.id', 'pases_salida.sede_id', 'folio', 'motivo', 'salio_en', 'regreso_en'];
        $eventos = [];
        foreach ($this->delDia($base, 'pases_salida.salio_en')->orderBy('pases_salida.salio_en')->limit(self::MAX_POR_TIPO)->get($columnas) as $p) {
            $eventos[] = $this->evento('pases', $p->salio_en, 'Salió de '.($p->sede?->nombre ?? 'origen'), $p->folio.' · '.$p->etiquetaMotivo(), $p->sede?->nombre, route('pases-salida.show', $p->id));
        }
        foreach ($this->delDia($base, 'pases_salida.regreso_en')->orderBy('pases_salida.regreso_en')->limit(self::MAX_POR_TIPO)->get($columnas) as $p) {
            $eventos[] = $this->evento('pases', $p->regreso_en, 'Regresó a '.($p->sede?->nombre ?? 'origen'), $p->folio.' · '.$p->etiquetaMotivo(), $p->sede?->nombre, route('pases-salida.show', $p->id), 1);
        }

        return $eventos;
    }

    /** @return list<array<string, mixed>> */
    private function recorridos(User $actor, ?array $sedes): array
    {
        $base = $this->limitar(RecorridoPc::query()->with(['sede:id,nombre', 'espacio:id,nombre']), $actor, 'recorridos', $sedes, 'recorridos_pc.sede_id', 'recorridos_pc.creado_por');
        $columnas = ['recorridos_pc.id', 'recorridos_pc.sede_id', 'numero', 'espacio_id', 'estatus', 'recorridos_pc.created_at', 'finalizado_en'];
        $eventos = [];
        foreach ($this->delDia($base, 'recorridos_pc.created_at')->orderBy('recorridos_pc.created_at')->limit(self::MAX_POR_TIPO)->get($columnas) as $r) {
            $eventos[] = $this->evento('recorridos', $r->created_at, 'Recorrido iniciado', $r->folio().($r->espacio ? ' · '.$r->espacio->nombre : ''), $r->sede?->nombre, route('recorridos_pc.show', $r->id));
        }
        foreach ($this->delDia($base, 'recorridos_pc.finalizado_en')->orderBy('recorridos_pc.finalizado_en')->limit(self::MAX_POR_TIPO)->get($columnas) as $r) {
            $eventos[] = $this->evento('recorridos', $r->finalizado_en, 'Recorrido terminado', $r->folio().' · '.$r->etiquetaEstatus(), $r->sede?->nombre, route('recorridos_pc.show', $r->id), 1);
        }

        return $eventos;
    }
}
