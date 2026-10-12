<?php

namespace App\Services\Informes;

use App\Models\Acceso;
use App\Models\AccidenteColaborador;
use App\Models\AccidenteGuardavidas;
use App\Models\AccidenteHuesped;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundReportePerdida;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PrestamoLlave;
use App\Models\User;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tendencias (ADR-0017): cada sección compara el periodo elegido contra el
 * periodo anterior de la misma duración, con las sedes elegidas dentro del
 * alcance. Una sección aparece solo si la persona puede ver su módulo de
 * origen. Los datos (sin HTML) se guardan 5 minutos por empresa, sección,
 * alcance, sedes y periodo; «Actualizar cifras» los vuelve a calcular.
 *
 * Cada sección devuelve:
 *  - cifras: [título, actual, anterior (null = sin comparación), unidad, sentido (SENTIDOS)]
 *  - series: [título, etiquetas, actual, anterior]
 *  - desgloses: [título, [concepto => total], unidad]
 */
class Tendencias
{
    public const PERMISO = 'tendencias.ver';

    public const MINUTOS_CACHE = 5;

    /** Para recorrer horas y duraciones sin cargar modelos (tope de seguridad). */
    private const MAX_RECORRIDO = 20000;

    /** clave => [título, ícono, permiso del módulo de origen] */
    public const SECCIONES = [
        'accesos' => ['Accesos', 'bi-door-open-fill', 'accesos.ver'],
        'novedades' => ['Novedades', 'bi-journal-text', 'novedades.ver'],
        'robo' => ['Robo', 'bi-exclamation-octagon-fill', 'robo.ver'],
        'lost_found' => ['Lost & Found', 'bi-box-seam', 'lost_found.ver'],
        'accidentes' => ['Accidentes', 'bi-bandaid', 'novedades.ver'],
        'llaves' => ['Llaves', 'bi-key-fill', 'prestamo_llaves.ver'],
        'pases' => ['Pases de salida', 'bi-box-arrow-right', 'pases_salida.ver'],
        'transporte' => ['Transporte', 'bi-bus-front-fill', 'transporte.ver'],
        'colaboradores' => ['Colaboradores', 'bi-people-fill', 'colaboradores.ver'],
        'usuarios' => ['Usuarios', 'bi-person-gear', 'usuarios.ver'],
        'candidatos' => ['Candidatos', 'bi-person-workspace', 'candidatos.ver'],
    ];

    /**
     * Qué es mejor en cada cifra que se compara (decisión del dueño, mapa de
     * procesos): sección => [título de la cifra => sentido]. Subir es MALO en
     * lo que mide problemas (tickets, robos, accidentes, pérdidas, tiempos de
     * cierre, taxis pagados porque el transporte no llegó) y BUENO en lo que
     * mide resultados (regresos de pases, candidatos y contratados). El volumen
     * de operación (ingresos, préstamos, movimientos, altas) es NEUTRO: se dice
     * si subió o bajó, sin color. Toda cifra comparable debe estar aquí
     * (TendenciasColorTest lo revisa).
     */
    public const SENTIDOS = [
        'accesos' => ['Ingresos registrados' => Indicadores::NEUTRO],
        'novedades' => ['Tickets abiertos en el periodo' => Indicadores::SUBIR_ES_MALO, 'Tiempo promedio de cierre' => Indicadores::SUBIR_ES_MALO],
        'robo' => ['Casos de robo' => Indicadores::SUBIR_ES_MALO, 'Valor estimado' => Indicadores::SUBIR_ES_MALO],
        'lost_found' => ['Objetos encontrados' => Indicadores::NEUTRO, 'Reportes de pérdida' => Indicadores::SUBIR_ES_MALO],
        'accidentes' => ['Accidentes y lesiones' => Indicadores::SUBIR_ES_MALO],
        'llaves' => ['Préstamos de llaves' => Indicadores::NEUTRO],
        'pases' => ['Pases creados' => Indicadores::NEUTRO, 'Regresaron a la propiedad' => Indicadores::SUBIR_ES_BUENO],
        'transporte' => ['Movimientos' => Indicadores::NEUTRO, 'Monto pagado en taxis' => Indicadores::SUBIR_ES_MALO, 'Pasajeros' => Indicadores::NEUTRO],
        'colaboradores' => ['Altas de colaboradores' => Indicadores::NEUTRO],
        'usuarios' => ['Usuarios dados de alta' => Indicadores::NEUTRO],
        'candidatos' => ['Candidatos registrados' => Indicadores::SUBIR_ES_BUENO, 'Contratados' => Indicadores::SUBIR_ES_BUENO],
    ];

    public function __construct(
        private readonly AlcanceInformes $alcance,
        private readonly AdministradorUsuarios $usuarios,
        private readonly Tenant $tenant,
    ) {}

    /** @return list<string> */
    public function visibles(User $actor): array
    {
        if (! $actor->can(self::PERMISO)) {
            return [];
        }

        return array_keys(array_filter(self::SECCIONES, fn ($s) => $actor->can($s[2])));
    }

    /**
     * Una sección (null = no la puede ver o falló su consulta).
     *
     * @param  list<int>|null  $sedes
     * @return array<string, mixed>|null
     */
    public function seccion(User $actor, string $clave, Periodo $periodo, ?array $sedes, bool $fresco = false): ?array
    {
        if (! in_array($clave, $this->visibles($actor), true)) {
            return null;
        }
        $llave = 'informes:tendencias:'.$this->tenant->empresaId().':'.$clave.':'
            .$this->alcance->huella($actor, self::PERMISO, [self::SECCIONES[$clave][2]], $sedes).':'.$periodo->clave();
        if ($fresco) {
            Cache::forget($llave);
        }

        try {
            $datos = Cache::remember($llave, now()->addMinutes(self::MINUTOS_CACHE), fn () => $this->{'calcular'.str_replace('_', '', ucwords($clave, '_'))}($actor, $periodo, $sedes));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $seccion = ['clave' => $clave, 'titulo' => $clave === 'novedades' ? AjustesNovedades::nombreActual() : self::SECCIONES[$clave][0], 'icono' => self::SECCIONES[$clave][1]]
            + $datos + ['cifras' => [], 'series' => [], 'desgloses' => [], 'nota' => null];
        // El sentido se agrega fuera de la caché: cambiarlo no espera a que venza
        $seccion['cifras'] = array_map(fn (array $c) => [$c[0], $c[1], $c[2], $c[3], self::SENTIDOS[$clave][$c[0]] ?? Indicadores::NEUTRO], $seccion['cifras']);

        return $seccion;
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $q
     * @return Builder<T>
     */
    private function limitar(Builder $q, User $actor, string $seccion, ?array $sedes, ?string $columnaSede, ?string $autor = null): Builder
    {
        return $this->alcance->limitar($q, $actor, self::PERMISO, self::SECCIONES[$seccion][2], $sedes, $columnaSede, $autor);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $q
     * @return Builder<T>
     */
    private static function en(Builder $q, string $columna, Periodo $p): Builder
    {
        return (clone $q)->where($columna, '>=', $p->inicioUtc())->where($columna, '<', $p->finUtc());
    }

    /**
     * Total del periodo y del anterior, más la serie de ambos.
     *
     * @param  Builder<Model>  $base  sin filtro de fecha
     * @return array{0: array{0: string, 1: float|int, 2: float|int, 3: string}, 1: array{0: string, 1: list<string>, 2: list<float|int>, 3: list<float|int>}}
     */
    private function comparar(Builder $base, string $columna, Periodo $p, string $titulo, string $tituloSerie, ?string $suma = null, string $unidad = ''): array
    {
        $actual = Indicadores::serie(self::en($base, $columna, $p), $columna, $p->cubetas(), $suma);
        $anterior = Indicadores::serie(self::en($base, $columna, $p->anterior()), $columna, $p->cubetasAnteriores(), $suma);

        return [[$titulo, array_sum($actual), array_sum($anterior), $unidad], [$tituloSerie, array_column($p->cubetas(), 0), $actual, $anterior]];
    }

    // --------------------------------------------------------------- Secciones

    private function calcularAccesos(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(Acceso::query()->where('accesos.movimiento', 'entrada'), $actor, 'accesos', $sedes, 'accesos.sede_id', 'accesos.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'accesos.entrada_at', $p, 'Ingresos registrados', 'Ingresos por periodo');
        $periodo = self::en($base, 'accesos.entrada_at', $p);
        $enSitio = (clone $base)->where('accesos.estado', 'en_sitio')->count();

        // Hora del día y duración: se recorren las fechas (sin modelos) en la hora local
        $horas = array_fill(0, 24, 0);
        $minutos = [];
        foreach ((clone $periodo)->toBase()->select(['accesos.tipo', 'accesos.entrada_at', 'accesos.salida_at'])->limit(self::MAX_RECORRIDO)->cursor() as $f) {
            $entrada = CarbonImmutable::parse($f->entrada_at, 'UTC');
            $horas[(int) $entrada->setTimezone($p->zona)->format('G')]++;
            if ($f->salida_at !== null) {
                $minutos[$f->tipo][] = $entrada->diffInMinutes(CarbonImmutable::parse($f->salida_at, 'UTC'), true);
            }
        }
        $duracion = [];
        foreach ($minutos as $tipo => $lista) {
            $duracion[Acceso::TIPOS[$tipo] ?? $tipo] = round(array_sum($lista) / count($lista));
        }
        arsort($duracion);
        $deptos = Indicadores::por((clone $periodo)->join('departamentos', 'departamentos.id', '=', 'accesos.departamento_id'), 'departamentos.nombre', [], 10);

        return [
            'cifras' => [$cifra, ['En sitio ahora', $enSitio, null, ''], ['Departamento más visitado', $deptos === [] ? '—' : (string) array_key_first($deptos), null, 'texto']],
            'series' => [$serie, ['Ingresos por hora del día', array_map(fn ($h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT), range(0, 23)), $horas, null]],
            'desgloses' => [
                ['Por tipo de acceso', Indicadores::por($periodo, 'accesos.tipo', Acceso::TIPOS), ''],
                ['Medio de llegada', Indicadores::por($periodo, 'accesos.modo_arribo', Acceso::MODOS, null, 'No especificado'), ''],
                ['Duración promedio de la visita', $duracion, 'min'],
                ['Departamentos más visitados', $deptos, ''],
            ],
        ];
    }

    private function calcularNovedades(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(Novedad::query(), $actor, 'novedades', $sedes, 'novedades.sede_id', 'novedades.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'novedades.created_at', $p, 'Tickets abiertos en el periodo', 'Tickets por periodo');
        $periodo = self::en($base, 'novedades.created_at', $p);
        $horas = fn (Periodo $x) => $this->promedioHoras(self::en($base, 'novedades.created_at', $x)->whereNotNull('novedades.cerrado_en'), 'novedades.created_at', 'novedades.cerrado_en');
        $abiertas = (clone $base)->where('novedades.estatus', '!=', Novedad::RESUELTO)->count();
        $estatus = array_map(fn ($e) => $e[1], Novedad::ESTATUS);

        return [
            'cifras' => [$cifra, ['Tiempo promedio de cierre', $horas($p), $horas($p->anterior()), 'h'], ['Abiertos ahora', $abiertas, null, '']],
            'series' => [$serie],
            'desgloses' => [
                ['Por estatus', Indicadores::por($periodo, 'novedades.estatus', $estatus), ''],
                ['Por categoría', Indicadores::por($periodo, 'novedades.categoria', Novedad::CATEGORIAS), ''],
                ['Concentración por zona', Indicadores::por((clone $periodo)->leftJoin('espacios', 'espacios.id', '=', 'novedades.area_id'), 'espacios.nombre', [], 10, 'Sin zona'), ''],
                ['Carga por guardia asignado', Indicadores::por((clone $periodo)->leftJoin('users', 'users.id', '=', 'novedades.asignado_a'), 'users.name', [], 10, 'Sin asignar'), ''],
            ],
        ];
    }

    /** Promedio en horas entre dos columnas de fecha (recorre solo esas dos columnas). */
    private function promedioHoras(Builder $q, string $desde, string $hasta): float
    {
        $suma = 0;
        $n = 0;
        foreach ((clone $q)->toBase()->select([$desde.' as d', $hasta.' as h'])->limit(self::MAX_RECORRIDO)->cursor() as $f) {
            $suma += CarbonImmutable::parse($f->d, 'UTC')->diffInMinutes(CarbonImmutable::parse($f->h, 'UTC'), true);
            $n++;
        }

        return $n === 0 ? 0.0 : round($suma / $n / 60, 1);
    }

    private function calcularRobo(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(Novedad::query()->where('novedades.categoria', 'robo')->leftJoin('robo_detalles', 'robo_detalles.novedad_id', '=', 'novedades.id'),
            $actor, 'robo', $sedes, 'novedades.sede_id', 'novedades.creado_por');
        [$casos, $serie] = $this->comparar($base, 'novedades.created_at', $p, 'Casos de robo', 'Casos por periodo');
        [$valor] = $this->comparar($base, 'novedades.created_at', $p, 'Valor estimado', 'Valor', 'COALESCE(robo_detalles.valor_estimado, 0)', '$');
        $c = Indicadores::conteos(self::en($base, 'novedades.created_at', $p), [
            'Con sospechoso identificado' => 'robo_detalles.hay_sospechoso = 1',
            'Con parte a la policía' => 'robo_detalles.parte_policia = 1',
            'Canalizados a gerencia o legal' => '(robo_detalles.canalizado_gerencia = 1 OR robo_detalles.canalizado_legal = 1)',
        ]);

        return ['cifras' => [$casos, $valor], 'series' => [$serie], 'desgloses' => [['Seguimiento de los casos', $c, '']]];
    }

    private function calcularLostFound(User $actor, Periodo $p, ?array $sedes): array
    {
        $articulos = $this->limitar(LostFoundArticulo::query(), $actor, 'lost_found', $sedes, 'lost_found_articulos.sede_id', 'lost_found_articulos.creado_por');
        $reportes = $this->limitar(LostFoundReportePerdida::query(), $actor, 'lost_found', $sedes, 'lost_found_reportes_perdida.sede_id', 'lost_found_reportes_perdida.creado_por');
        [$encontrados, $serie] = $this->comparar($articulos, 'lost_found_articulos.created_at', $p, 'Objetos encontrados', 'Objetos encontrados por periodo');
        [$perdidas] = $this->comparar($reportes, 'lost_found_reportes_perdida.created_at', $p, 'Reportes de pérdida', 'Reportes');
        $rep = Indicadores::conteos(self::en($reportes, 'lost_found_reportes_perdida.created_at', $p), ['total' => '1 = 1', 'vinculados' => "lost_found_reportes_perdida.estatus = 'VINCULADO'"]);
        $periodo = self::en($articulos, 'lost_found_articulos.created_at', $p);

        return [
            'cifras' => [$encontrados, $perdidas, ['Tasa de recuperación', $rep['total'] > 0 ? round($rep['vinculados'] / $rep['total'] * 100) : 0, null, '%']],
            'series' => [$serie],
            'desgloses' => [
                ['Por estatus', Indicadores::por($periodo, 'lost_found_articulos.estatus', LostFoundArticulo::ESTATUS), ''],
                ['Por tipo de valor', Indicadores::por($periodo, 'lost_found_articulos.tipo_valor', LostFoundArticulo::TIPOS_VALOR), ''],
            ],
        ];
    }

    private function calcularAccidentes(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(Novedad::query()->where('novedades.categoria', 'accidente'), $actor, 'accidentes', $sedes, 'novedades.sede_id', 'novedades.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'novedades.created_at', $p, 'Accidentes y lesiones', 'Accidentes por periodo');
        $ids = self::en($base, 'novedades.created_at', $p)->select('novedades.id');
        $colab = Indicadores::conteos(AccidenteColaborador::query()->whereIn('novedad_id', clone $ids), ['total' => '1 = 1', 'acto' => 'causa_acto_inseguro = 1', 'condicion' => 'causa_condicion_insegura = 1']);
        $guarda = Indicadores::conteos(AccidenteGuardavidas::query()->whereIn('novedad_id', clone $ids), ['total' => '1 = 1', 'acto' => 'acto_inseguro = 1', 'condicion' => 'condicion_insegura = 1']);
        $huesped = AccidenteHuesped::query()->whereIn('novedad_id', clone $ids)->count();

        return [
            'cifras' => [$cifra],
            'series' => [$serie],
            'desgloses' => [
                ['Tipo de afectado', array_filter(['Colaborador' => $colab['total'], 'Huésped' => $huesped, 'Guardavidas' => $guarda['total']]), ''],
                ['Causa (colaboradores y guardavidas)', array_filter(['Acto inseguro' => $colab['acto'] + $guarda['acto'], 'Condición insegura' => $colab['condicion'] + $guarda['condicion']]), ''],
            ],
        ];
    }

    private function calcularLlaves(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(PrestamoLlave::query()->where('prestamos_llaves.anulado', false), $actor, 'llaves', $sedes, 'prestamos_llaves.sede_id', 'prestamos_llaves.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'prestamos_llaves.prestado_en', $p, 'Préstamos de llaves', 'Préstamos por periodo');
        $periodo = self::en($base, 'prestamos_llaves.prestado_en', $p);

        return [
            'cifras' => [$cifra, ['En uso ahora', (clone $base)->where('prestamos_llaves.estado', PrestamoLlave::EN_USO)->count(), null, '']],
            'series' => [$serie],
            'desgloses' => [
                ['Llaves más prestadas', Indicadores::por((clone $periodo)->join('llaves', 'llaves.id', '=', 'prestamos_llaves.llave_id'), 'llaves.nomenclatura', [], 10), ''],
                ['Garantía que se dejó', Indicadores::por($periodo, 'prestamos_llaves.tipo_garantia', PrestamoLlave::GARANTIAS_OPCIONES), ''],
            ],
        ];
    }

    private function calcularPases(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(PaseSalida::query(), $actor, 'pases', $sedes, 'pases_salida.sede_id', 'pases_salida.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'pases_salida.created_at', $p, 'Pases creados', 'Pases por periodo');
        [$regresos] = $this->comparar($base->clone()->whereNotNull('pases_salida.regreso_en'), 'pases_salida.regreso_en', $p, 'Regresaron a la propiedad', 'Regresos');
        $periodo = self::en($base, 'pases_salida.created_at', $p);

        return [
            'cifras' => [$cifra, $regresos, ['Fuera de la propiedad ahora', (clone $base)->whereIn('pases_salida.estado', PaseSalida::FUERA)->count(), null, '']],
            'series' => [$serie],
            'desgloses' => [
                ['Por motivo', Indicadores::por($periodo, 'pases_salida.motivo', PaseSalida::MOTIVOS), ''],
                ['Por estatus actual', Indicadores::por($periodo, 'pases_salida.estado', array_map(fn ($e) => $e[0], PaseSalida::ESTADOS)), ''],
            ],
        ];
    }

    private function calcularTransporte(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(MovimientoTransporte::query()->where('movimientos_transporte.anulado', false), $actor, 'transporte', $sedes, 'movimientos_transporte.sede_id', 'movimientos_transporte.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'movimientos_transporte.created_at', $p, 'Movimientos', 'Movimientos por periodo');
        [$monto] = $this->comparar($base, 'movimientos_transporte.created_at', $p, 'Monto pagado en taxis', 'Monto', 'COALESCE(movimientos_transporte.monto, 0)', '$');
        [$pax] = $this->comparar($base, 'movimientos_transporte.created_at', $p, 'Pasajeros', 'PAX', 'COALESCE(movimientos_transporte.cantidad_pax, 0)');
        $c = Indicadores::conteos(self::en($base, 'movimientos_transporte.created_at', $p), [
            'A tiempo' => "movimientos_transporte.estatus = 'a_tiempo'",
            'Retraso' => "movimientos_transporte.estatus = 'retraso'",
            'No llegó (uso de taxis)' => "movimientos_transporte.estatus = 'no_llego'",
        ]);

        return ['cifras' => [$cifra, $monto, $pax], 'series' => [$serie], 'desgloses' => [['Puntualidad del servicio', $c, '']]];
    }

    private function calcularColaboradores(User $actor, Periodo $p, ?array $sedes): array
    {
        $q = Colaborador::query()->whereNull('colaboradores.fusionado_en_id');
        $permitidas = $this->alcance->sedes($actor, self::PERMISO, 'colaboradores.ver', $sedes);
        $base = $this->limitar($permitidas === null || $permitidas === [] ? $q : $q->enSedes($permitidas), $actor, 'colaboradores', $sedes, null, 'colaboradores.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'colaboradores.created_at', $p, 'Altas de colaboradores', 'Altas por periodo');
        $periodo = self::en($base, 'colaboradores.created_at', $p);

        return [
            'cifras' => [$cifra, ['Activos ahora', (clone $base)->where('colaboradores.activo', true)->count(), null, '']],
            'series' => [$serie],
            'desgloses' => [['Altas por departamento', Indicadores::por((clone $periodo)->leftJoin('departamentos', 'departamentos.id', '=', 'colaboradores.departamento_id'), 'departamentos.nombre', [], 10, 'Sin departamento'), '']],
            'nota' => 'Se cuentan las altas; la plataforma no guarda la fecha de baja.',
        ];
    }

    private function calcularUsuarios(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->usuarios->limitarAlcance(User::query()->where('users.empresa_id', $this->tenant->empresaId()), $actor, 'usuarios.ver');
        $permitidas = $this->alcance->sedes($actor, self::PERMISO, 'usuarios.ver', $sedes);
        if ($permitidas !== null) {
            $base->whereHas('roles', fn ($r) => $r->whereIn('usuario_roles.sede_id', $permitidas));
        }
        [$cifra, $serie] = $this->comparar($base, 'users.created_at', $p, 'Usuarios dados de alta', 'Altas por periodo');
        $periodo = self::en($base, 'users.created_at', $p);

        return [
            'cifras' => [$cifra, ['Activos ahora', (clone $base)->where('users.activo', true)->count(), null, '']],
            'series' => [$serie],
            'desgloses' => [['Altas por rol', Indicadores::por((clone $periodo)->join('usuario_roles', 'usuario_roles.user_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'usuario_roles.rol_id'), 'roles.nombre'), '']],
        ];
    }

    private function calcularCandidatos(User $actor, Periodo $p, ?array $sedes): array
    {
        $base = $this->limitar(Candidato::query(), $actor, 'candidatos', $sedes, 'candidatos.sede_id', 'candidatos.creado_por');
        [$cifra, $serie] = $this->comparar($base, 'candidatos.created_at', $p, 'Candidatos registrados', 'Candidatos por periodo');
        [$contratados] = $this->comparar($base->clone()->whereNotNull('candidatos.contratado_en'), 'candidatos.contratado_en', $p, 'Contratados', 'Contratados');
        $periodo = self::en($base, 'candidatos.created_at', $p);

        // Solo cifras: ningún nombre ni dato personal de los candidatos
        return [
            'cifras' => [$cifra, $contratados],
            'series' => [$serie],
            'desgloses' => [
                ['Cómo llegaron', Indicadores::por($periodo, 'candidatos.origen', Candidato::ORIGENES), ''],
                ['Etapa actual de los registrados en el periodo', Indicadores::por($periodo, 'candidatos.etapa', Candidato::ETAPAS), ''],
            ],
        ];
    }
}
