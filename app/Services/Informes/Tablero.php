<?php

namespace App\Services\Informes;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\Llave;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundUmbral;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PrestamoLlave;
use App\Models\User;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Novedades\ArchivoLostFound;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Support\GraficaSvg;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Tablero — «Consola de Monitoreo» (ADR-0017): las fichas de cifras del
 * momento del sistema original. Cada ficha se ve solo si la persona tiene
 * «ver» del módulo de donde sale el dato, se calcula con su alcance (sede o
 * empresa) y, si su consulta falla, se oculta sin tirar la pantalla. Al
 * tocarla se abre su detalle: gráfica, tabla (máximo 200 renglones) y
 * enlace al módulo ya filtrado.
 */
class Tablero
{
    public const PERMISO = 'dashboard.ver';

    public const MAX_FILAS = 200;

    /** clave => [título, ícono, permiso del módulo de origen] (en el orden del sistema original) */
    public const FICHAS = [
        'colaboradores' => ['Colaboradores activos', 'bi-people-fill', 'colaboradores.ver'],
        'en_sitio' => ['Personas en sitio ahora', 'bi-door-open-fill', 'accesos.ver'],
        'llaves' => ['Llaves en uso ahora', 'bi-key-fill', 'prestamo_llaves.ver'],
        'transporte' => ['Movimientos de transporte (7 días)', 'bi-bus-front-fill', 'transporte.ver'],
        'novedades' => ['Novedades abiertas', 'bi-journal-text', 'novedades.ver'],
        'lost_found' => ['Lost & Found por vencer / vencidos', 'bi-box-seam', 'lost_found.ver'],
        'pases' => ['Pases de salida vencidos sin regresar', 'bi-box-arrow-right', 'pases_salida.ver'],
        'equipos' => ['Equipos asignados', 'bi-tools', 'equipos.ver'],
    ];

    public function __construct(
        private readonly AlcanceInformes $alcance,
        private readonly HoraLocal $hora,
        private readonly ArchivoLostFound $archivo,
        private readonly AdministradorPasesSalida $pases,
    ) {}

    /** Claves de las fichas que la persona puede ver. @return list<string> */
    public function visibles(User $actor): array
    {
        if (! $actor->can(self::PERMISO)) {
            return [];
        }

        return array_keys(array_filter(self::FICHAS, fn ($f) => $actor->can($f[2])));
    }

    /**
     * Resumen de cada ficha visible (las que fallan se omiten).
     *
     * @param  list<int>|null  $sedes
     * @return list<array<string, mixed>>
     */
    public function fichas(User $actor, ?array $sedes): array
    {
        $fichas = [];
        foreach ($this->visibles($actor) as $clave) {
            try {
                $fichas[] = ['clave' => $clave, 'titulo' => self::titulo($clave), 'icono' => self::FICHAS[$clave][1]] + $this->{'resumen'.ucfirst(str_replace('_', '', ucwords($clave, '_')))}($actor, $sedes);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $fichas;
    }

    /**
     * Detalle de una ficha (diálogo o página propia). null = no la puede ver o falló.
     *
     * @param  list<int>|null  $sedes
     * @return array<string, mixed>|null
     */
    public function detalle(User $actor, string $clave, ?array $sedes): ?array
    {
        if (! in_array($clave, $this->visibles($actor), true)) {
            return null;
        }
        try {
            return ['clave' => $clave, 'titulo' => self::titulo($clave), 'icono' => self::FICHAS[$clave][1]]
                + $this->{'detalle'.ucfirst(str_replace('_', '', ucwords($clave, '_')))}($actor, $sedes);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    // ------------------------------------------------------------ Consultas base

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $q
     * @param  list<int>|null  $sedes
     * @return Builder<T>
     */
    private function limitar(Builder $q, User $actor, string $clave, ?array $sedes, ?string $columnaSede, ?string $autor = null): Builder
    {
        return $this->alcance->limitar($q, $actor, self::PERMISO, self::FICHAS[$clave][2], $sedes, $columnaSede, $autor);
    }

    /** @return Builder<Colaborador> */
    private function colaboradores(User $actor, ?array $sedes): Builder
    {
        $q = Colaborador::query()->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id');
        $permitidas = $this->alcance->sedes($actor, self::PERMISO, 'colaboradores.ver', $sedes);
        if ($permitidas === []) {
            return $q->whereRaw('1 = 0');
        }

        return $this->limitar($permitidas === null ? $q : $q->enSedes($permitidas), $actor, 'colaboradores', null, null, 'colaboradores.creado_por');
    }

    /** @return Builder<Acceso> */
    private function enSitio(User $actor, ?array $sedes): Builder
    {
        return $this->limitar(Acceso::query()->where('accesos.estado', 'en_sitio')->where('accesos.movimiento', 'entrada'), $actor, 'en_sitio', $sedes, 'accesos.sede_id', 'accesos.creado_por');
    }

    /** @return Builder<PrestamoLlave> */
    private function prestamos(User $actor, ?array $sedes): Builder
    {
        return $this->limitar(PrestamoLlave::query()->vigentes(), $actor, 'llaves', $sedes, 'prestamos_llaves.sede_id', 'prestamos_llaves.creado_por');
    }

    /** @return array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string}>} */
    private function sieteDias(): array
    {
        $hoy = CarbonImmutable::now($this->hora->zona())->startOfDay();
        $cubetas = [];
        for ($i = 6; $i >= 0; $i--) {
            $dia = $hoy->subDays($i);
            $cubetas[] = [$dia->format('d/m'), $dia->utc()->format('Y-m-d H:i:s'), $dia->addDay()->utc()->format('Y-m-d H:i:s')];
        }

        return [$cubetas[0][1], $cubetas[6][2], $cubetas];
    }

    /** @return Builder<MovimientoTransporte> */
    private function transporte(User $actor, ?array $sedes): Builder
    {
        [$desde, $hasta] = $this->sieteDias();

        return $this->limitar(MovimientoTransporte::query()->where('movimientos_transporte.anulado', false)
            ->where('movimientos_transporte.created_at', '>=', $desde)->where('movimientos_transporte.created_at', '<', $hasta),
            $actor, 'transporte', $sedes, 'movimientos_transporte.sede_id', 'movimientos_transporte.creado_por');
    }

    /** @return Builder<Novedad> */
    private function novedades(User $actor, ?array $sedes): Builder
    {
        return $this->limitar(Novedad::query()->where('novedades.estatus', '!=', Novedad::RESUELTO), $actor, 'novedades', $sedes, 'novedades.sede_id', 'novedades.creado_por');
    }

    /** @return Builder<LostFoundArticulo> */
    private function lostFound(User $actor, ?array $sedes): Builder
    {
        return $this->archivo->soloUrgentes($this->limitar(LostFoundArticulo::query(), $actor, 'lost_found', $sedes, 'lost_found_articulos.sede_id', 'lost_found_articulos.creado_por'), LostFoundUmbral::vigentes());
    }

    /** @return Builder<PaseSalida> */
    private function pasesVencidos(User $actor, ?array $sedes): Builder
    {
        return $this->pases->filtrar($this->limitar(PaseSalida::query(), $actor, 'pases', $sedes, 'pases_salida.sede_id', 'pases_salida.creado_por'), 'vencidos');
    }

    /** @return Builder<Equipo> */
    private function equipos(User $actor, ?array $sedes): Builder
    {
        return $this->limitar(Equipo::query()->where('equipos.estado', '!=', 'baja'), $actor, 'equipos', $sedes, 'equipos.sede_id', 'equipos.creado_por');
    }

    // ------------------------------------------------------------------ Fichas

    private function resumenColaboradores(User $actor, ?array $sedes): array
    {
        return ['valor' => number_format($this->colaboradores($actor, $sedes)->count()), 'nota' => 'Activos en el directorio', 'enlace' => route('colaboradores.index')];
    }

    private function detalleColaboradores(User $actor, ?array $sedes): array
    {
        $base = $this->colaboradores($actor, $sedes);
        $porDepto = Indicadores::por((clone $base)->leftJoin('departamentos', 'departamentos.id', '=', 'colaboradores.departamento_id'), 'departamentos.nombre', [], null, 'Sin departamento');
        $filas = (clone $base)->with(['departamento:id,nombre', 'puesto:id,nombre', 'sede:id,nombre'])
            ->orderBy('colaboradores.nombre')->orderBy('colaboradores.apellido_paterno')->limit(self::MAX_FILAS)
            ->get(['colaboradores.id', 'num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno', 'departamento_id', 'puesto_id', 'colaboradores.sede_id'])
            ->map(fn (Colaborador $c) => [$c->num_empleado ?? '—', $c->nombreCompleto(), $c->departamento?->nombre ?? '—', $c->puesto?->nombre ?? '—', $c->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::barras($porDepto, 'Colaboradores por departamento (los 8 con más)', '', 8),
            'columnas' => ['Núm.', 'Nombre', 'Departamento', 'Puesto', 'Sede'],
            'filas' => $filas, 'total' => array_sum($porDepto),
            'enlace' => route('colaboradores.index'), 'textoEnlace' => 'Ir a Colaboradores',
        ];
    }

    private function resumenEnSitio(User $actor, ?array $sedes): array
    {
        return ['valor' => number_format($this->enSitio($actor, $sedes)->count()), 'nota' => 'Registros abiertos en la Bitácora de accesos', 'enlace' => route('accesos.index')];
    }

    private function detalleEnSitio(User $actor, ?array $sedes): array
    {
        $base = $this->enSitio($actor, $sedes);
        $porTipo = Indicadores::por($base, 'accesos.tipo', Acceso::TIPOS);
        $filas = (clone $base)->with('sede:id,nombre')->orderBy('accesos.entrada_at')->limit(self::MAX_FILAS)
            ->get(['accesos.id', 'accesos.sede_id', 'tipo', 'nombre', 'empresa_procedencia', 'entrada_at'])
            ->map(fn (Acceso $a) => [$this->hora->formatear($a->entrada_at, 'd/m H:i'), Acceso::TIPOS[$a->tipo] ?? $a->tipo, $a->nombre, $a->empresa_procedencia ?: '—', $a->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::barras($porTipo, 'Personas en sitio por tipo de acceso'),
            'columnas' => ['Entrada', 'Tipo', 'Nombre', 'Procedencia', 'Sede'],
            'filas' => $filas, 'total' => array_sum($porTipo),
            'enlace' => route('accesos.index'), 'textoEnlace' => 'Ir a la Bitácora de accesos',
        ];
    }

    private function totalLlaves(User $actor, ?array $sedes): int
    {
        return $this->limitar(Llave::query()->where('llaves.activo', true), $actor, 'llaves', $sedes, 'llaves.sede_id')->count();
    }

    private function resumenLlaves(User $actor, ?array $sedes): array
    {
        $enUso = $this->prestamos($actor, $sedes)->count();

        return ['valor' => number_format($enUso).' / '.number_format($this->totalLlaves($actor, $sedes)), 'nota' => 'En uso de las llaves activas', 'enlace' => route('prestamo_llaves.index')];
    }

    private function detalleLlaves(User $actor, ?array $sedes): array
    {
        $base = $this->prestamos($actor, $sedes);
        $enUso = (clone $base)->count();
        $total = $this->totalLlaves($actor, $sedes);
        $filas = (clone $base)->with(['llave:id,nomenclatura,descripcion', 'colaborador:id,nombre,apellido_paterno,apellido_materno', 'sede:id,nombre'])
            ->orderBy('prestamos_llaves.prestado_en')->limit(self::MAX_FILAS)
            ->get(['prestamos_llaves.id', 'llave_id', 'colaborador_id', 'prestamos_llaves.sede_id', 'prestado_en'])
            ->map(fn (PrestamoLlave $p) => [$p->llave?->nomenclatura ?? '—', $p->llave?->descripcion ?? '—', $p->colaborador?->nombreCompleto() ?? '—', $this->hora->formatear($p->prestado_en, 'd/m/Y H:i'), $p->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::barras(['En uso' => $enUso, 'Disponibles' => max(0, $total - $enUso)], 'Llaves en uso y disponibles'),
            'columnas' => ['Llave', 'Descripción', 'La tiene', 'Desde', 'Sede'],
            'filas' => $filas, 'total' => $enUso,
            'enlace' => route('prestamo_llaves.index'), 'textoEnlace' => 'Ir a Préstamo de llaves',
        ];
    }

    private function resumenTransporte(User $actor, ?array $sedes): array
    {
        return ['valor' => number_format($this->transporte($actor, $sedes)->count()), 'nota' => 'Llegadas y salidas, sin anulados', 'enlace' => $this->enlaceTransporte()];
    }

    private function enlaceTransporte(): string
    {
        $hoy = CarbonImmutable::now($this->hora->zona());

        return route('transporte.reportes', ['fecha_inicio' => $hoy->subDays(6)->toDateString(), 'fecha_fin' => $hoy->toDateString(), 'estado' => 'vigentes']);
    }

    private function detalleTransporte(User $actor, ?array $sedes): array
    {
        $base = $this->transporte($actor, $sedes);
        [, , $cubetas] = $this->sieteDias();
        $serie = Indicadores::serie($base, 'movimientos_transporte.created_at', $cubetas);
        $filas = (clone $base)->with(['ruta:id,nombre', 'sede:id,nombre'])->orderByDesc('movimientos_transporte.created_at')->limit(self::MAX_FILAS)
            ->get(['movimientos_transporte.id', 'ruta_id', 'movimientos_transporte.sede_id', 'tipo_movimiento', 'estatus', 'cantidad_pax', 'movimientos_transporte.created_at'])
            ->map(fn (MovimientoTransporte $m) => [$this->hora->formatear($m->created_at, 'd/m H:i'), $m->etiquetaTipo(), $m->ruta?->nombre ?? '—', $m->etiquetaEstatus(), (int) $m->cantidad_pax, $m->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::tiempo(array_column($cubetas, 0), $serie, null, 'Movimientos por día'),
            'columnas' => ['Fecha', 'Movimiento', 'Ruta', 'Estatus', 'PAX', 'Sede'],
            'filas' => $filas, 'total' => array_sum($serie),
            'enlace' => $this->enlaceTransporte(), 'textoEnlace' => 'Ir a Reportes de transporte',
        ];
    }

    /** Título de la ficha; la de la bitácora lleva el nombre que la empresa le dio al módulo. */
    public static function titulo(string $clave): string
    {
        if ($clave === 'novedades' && ($nombre = AjustesNovedades::nombreActual()) !== AjustesNovedades::NOMBRE_OMISION) {
            return $nombre.': tickets abiertos';
        }

        return self::FICHAS[$clave][0];
    }

    private function resumenNovedades(User $actor, ?array $sedes): array
    {
        return ['valor' => number_format($this->novedades($actor, $sedes)->count()), 'nota' => 'Tickets sin cerrar', 'enlace' => route('novedades.index')];
    }

    private function detalleNovedades(User $actor, ?array $sedes): array
    {
        $base = $this->novedades($actor, $sedes);
        $porCategoria = Indicadores::por($base, 'novedades.categoria', Novedad::CATEGORIAS);
        $filas = (clone $base)->with('sede:id,nombre')->orderByDesc('novedades.created_at')->limit(self::MAX_FILAS)
            ->get(['novedades.id', 'numero', 'novedades.sede_id', 'categoria', 'estatus', 'ubicacion', 'novedades.created_at'])
            ->map(fn (Novedad $n) => [$n->folio(), $this->hora->formatear($n->created_at, 'd/m H:i'), $n->etiquetaCategoria(), Novedad::ESTATUS[$n->estatus][1] ?? $n->estatus, $n->ubicacion ?: '—', $n->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::barras($porCategoria, AjustesNovedades::nombreActual() === AjustesNovedades::NOMBRE_OMISION ? 'Novedades abiertas por categoría' : 'Tickets abiertos por categoría'),
            'columnas' => ['Folio', 'Abierto', 'Categoría', 'Estatus', 'Ubicación', 'Sede'],
            'filas' => $filas, 'total' => array_sum($porCategoria),
            'enlace' => route('novedades.index'), 'textoEnlace' => 'Ir a '.AjustesNovedades::bitacoraActualConArticulo(),
        ];
    }

    /** Días en resguardo y umbral de cada artículo urgente. @return array{0: int, 1: int} */
    private function urgencia(LostFoundArticulo $a, array $umbrales): array
    {
        $dias = (int) floor($a->created_at->diffInDays(now(), true));

        return [$dias, (int) ($umbrales[$a->tipo_valor] ?? LostFoundUmbral::POR_OMISION['OTRO'])];
    }

    private function resumenLostFound(User $actor, ?array $sedes): array
    {
        $umbrales = LostFoundUmbral::vigentes();
        $vencidos = 0;
        $total = 0;
        foreach ((clone $this->lostFound($actor, $sedes))->toBase()->selectRaw('tipo_valor, created_at')->limit(5000)->cursor() as $f) {
            $total++;
            $dias = CarbonImmutable::parse($f->created_at, 'UTC')->diffInDays(now(), true);
            if ($dias >= ($umbrales[$f->tipo_valor] ?? LostFoundUmbral::POR_OMISION['OTRO'])) {
                $vencidos++;
            }
        }

        return ['valor' => number_format($total - $vencidos).' / '.number_format($vencidos), 'nota' => 'Por vencer (≥ 70 % de los días) / vencidos',
            'nivel' => $vencidos > 0 ? 'alerta' : null, 'enlace' => route('lost_found.archivo', ['filtro' => 'urgentes'])];
    }

    private function detalleLostFound(User $actor, ?array $sedes): array
    {
        $umbrales = LostFoundUmbral::vigentes();
        $base = $this->lostFound($actor, $sedes);
        $porTipo = Indicadores::por($base, 'lost_found_articulos.tipo_valor', LostFoundArticulo::TIPOS_VALOR);
        $filas = (clone $base)->with('sede:id,nombre')->orderBy('lost_found_articulos.created_at')->limit(self::MAX_FILAS)
            ->get(['lost_found_articulos.id', 'folio', 'objeto', 'tipo_valor', 'lost_found_articulos.sede_id', 'lost_found_articulos.created_at'])
            ->map(function (LostFoundArticulo $a) use ($umbrales) {
                [$dias, $umbral] = $this->urgencia($a, $umbrales);

                return [$a->folio, $a->objeto, $a->etiquetaTipo(), $dias.' de '.$umbral, $dias >= $umbral ? 'Vencido' : 'Por vencer', $a->sede?->nombre ?? '—'];
            })->all();

        return [
            'grafica' => GraficaSvg::barras($porTipo, 'Artículos por vencer o vencidos por tipo de valor'),
            'columnas' => ['Folio', 'Objeto', 'Tipo de valor', 'Días en resguardo', 'Situación', 'Sede'],
            'filas' => $filas, 'total' => array_sum($porTipo),
            'enlace' => route('lost_found.archivo', ['filtro' => 'urgentes']), 'textoEnlace' => 'Ir a los urgentes de Lost & Found',
        ];
    }

    private function resumenPases(User $actor, ?array $sedes): array
    {
        $n = $this->pasesVencidos($actor, $sedes)->count();

        return ['valor' => number_format($n), 'nota' => 'Debían regresar y siguen fuera', 'nivel' => $n > 0 ? 'alerta' : 'positiva', 'enlace' => route('pases-salida.index', ['filtro' => 'vencidos'])];
    }

    private function detallePases(User $actor, ?array $sedes): array
    {
        $base = $this->pasesVencidos($actor, $sedes);
        $porMotivo = Indicadores::por($base, 'pases_salida.motivo', PaseSalida::MOTIVOS);
        $hoy = CarbonImmutable::now($this->hora->zona())->startOfDay();
        $filas = (clone $base)->with(['solicitante:id,nombre,apellido_paterno,apellido_materno', 'sede:id,nombre'])
            ->orderBy('pases_salida.fecha_tentativa_regreso')->limit(self::MAX_FILAS)
            ->get(['pases_salida.id', 'folio', 'motivo', 'colaborador_id', 'pases_salida.sede_id', 'fecha_tentativa_regreso'])
            ->map(fn (PaseSalida $p) => [$p->folio, $p->etiquetaMotivo(), $p->solicitante?->nombreCompleto() ?? '—', $p->fecha_tentativa_regreso?->format('d/m/Y') ?? '—',
                $p->fecha_tentativa_regreso ? (int) CarbonImmutable::parse($p->fecha_tentativa_regreso->format('Y-m-d'), $this->hora->zona())->diffInDays($hoy) : '—', $p->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::barras($porMotivo, 'Pases vencidos por motivo'),
            'columnas' => ['Folio', 'Motivo', 'Solicitante', 'Debió regresar', 'Días de retraso', 'Sede'],
            'filas' => $filas, 'total' => array_sum($porMotivo),
            'enlace' => route('pases-salida.index', ['filtro' => 'vencidos']), 'textoEnlace' => 'Ir a los pases vencidos',
        ];
    }

    private function resumenEquipos(User $actor, ?array $sedes): array
    {
        $c = Indicadores::conteos($this->equipos($actor, $sedes), ['asignados' => "equipos.estado = 'asignado'", 'total' => '1 = 1']);

        return ['valor' => number_format($c['asignados']).' / '.number_format($c['total']), 'nota' => 'Asignados del total (sin bajas)', 'enlace' => route('equipos.index')];
    }

    private function detalleEquipos(User $actor, ?array $sedes): array
    {
        $base = $this->equipos($actor, $sedes);
        $porEstado = Indicadores::por($base, 'equipos.estado', Equipo::ESTADOS);
        $filas = (clone $base)->with(['tipo:id,nombre', 'sede:id,nombre'])->orderBy('equipos.estado')->orderBy('equipos.id')->limit(self::MAX_FILAS)
            ->get(['equipos.id', 'tipo_equipo_id', 'marca', 'modelo', 'numero_serie', 'estado', 'equipos.sede_id'])
            ->map(fn (Equipo $e) => [$e->tipo?->nombre ?? '—', trim(($e->marca ?? '').' '.($e->modelo ?? '')) ?: '—', $e->numero_serie ?: '—', Equipo::ESTADOS[$e->estado] ?? $e->estado, $e->sede?->nombre ?? '—'])->all();

        return [
            'grafica' => GraficaSvg::barras($porEstado, 'Equipos por estado (sin bajas)'),
            'columnas' => ['Tipo', 'Marca y modelo', 'Serie', 'Estado', 'Sede'],
            'filas' => $filas, 'total' => array_sum($porEstado),
            'enlace' => route('equipos.index'), 'textoEnlace' => 'Ir a Equipos de seguridad',
        ];
    }
}
