<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Colaborador;
use App\Models\Gafete;
use App\Models\Sede;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Services\Padrones\AdministradorProveedores;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Personas\AdministradorPersonas;
use App\Services\Vehiculos\AdministradorVehiculos;
use App\Support\HoraLocal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lectura de la Bitácora de accesos: alcance por sede, "Gente en Sitio",
 * "Pendientes de Autorización", historial paginado, búsqueda para dar salida,
 * zonas con su ocupación y gafetes libres.
 *
 * Cada acceso es de una sede: con alcance de empresa se ve todo; con alcance
 * de sede, solo lo de sus sedes; con alcance "propios", además, solo lo que
 * él registró. Todo corre con la empresa de trabajo fijada en el Tenant.
 */
class ConsultaAccesos
{
    /** Registros por página en el historial (SEGCAT mostraba solo los últimos 300). */
    public const POR_PAGINA = 24;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly HoraLocal $hora,
        private readonly OcupacionEstacionamientos $ocupacion,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Sedes en las que el usuario usa el permiso: null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    /**
     * @param  Builder<Acceso>  $consulta
     * @return Builder<Acceso>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->whereIn('accesos.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('accesos.creado_por', $actor->id));
    }

    /**
     * Sedes activas donde el usuario puede usar el permiso (para elegir en el formulario).
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permiso): Collection
    {
        $permitidas = $this->sedes($actor, $permiso);

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre']);
    }

    // ------------------------------------------------------------------- Listas

    /**
     * Lo que pinta una tarjeta, ya cargado (sin consultas por tarjeta).
     *
     * @return array<int|string, mixed>
     */
    private function relaciones(bool $abiertos): array
    {
        $colaborador = 'id,num_empleado,nombre,apellido_paterno,apellido_materno';

        return [
            'sede:id,nombre', 'colaborador:'.$colaborador, 'host:'.$colaborador, 'visitaColaborador:'.$colaborador,
            'proveedor:id,nombre', 'departamento:id,nombre', 'zona:id,nombre,tipo',
            'registradoPor:id,name', 'autorizadoPor:id,name', 'salidaPor:id,name',
            ...($abiertos ? [
                'acompanantes' => fn ($q) => $q->whereNull('salida_at'),
                'salidaTemporalAbierta.registradoPor:id,name',
            ] : ['acompanantes']),
        ];
    }

    /**
     * "Gente en Sitio": ingresos abiertos (las salidas temporales se ven
     * dentro de la tarjeta de su ingreso, no como otra persona).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Acceso>
     */
    public function enSitio(User $actor, array $filtros = []): Collection
    {
        return $this->filtrar($this->limitar(Acceso::query(), $actor, 'accesos.ver'), $filtros)
            ->where('accesos.estado', 'en_sitio')->where('accesos.movimiento', 'entrada')
            ->with($this->relaciones(true))
            ->orderByDesc('accesos.entrada_at')->orderByDesc('accesos.id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Acceso>
     */
    public function pendientes(User $actor, array $filtros = []): Collection
    {
        return $this->filtrar($this->limitar(Acceso::query(), $actor, 'accesos.ver'), $filtros)
            ->where('accesos.estado', 'pendiente')
            ->with($this->relaciones(true))
            ->orderBy('accesos.entrada_at')->orderBy('accesos.id')
            ->get();
    }

    /**
     * Totales de las pestañas (sin filtros).
     *
     * @return array{en_sitio: int, pendientes: int}
     */
    public function conteos(User $actor): array
    {
        $filas = $this->limitar(Acceso::query(), $actor, 'accesos.ver')
            ->where(fn ($q) => $q->where('accesos.estado', 'pendiente')
                ->orWhere(fn ($e) => $e->where('accesos.estado', 'en_sitio')->where('accesos.movimiento', 'entrada')))
            ->groupBy('accesos.estado')
            ->selectRaw('accesos.estado, COUNT(*) as total')
            ->pluck('total', 'estado');

        return ['en_sitio' => (int) ($filas['en_sitio'] ?? 0), 'pendientes' => (int) ($filas['pendiente'] ?? 0)];
    }

    /**
     * "Historial Finalizados", paginado y con filtros (texto, tipo, sede y fechas).
     *
     * @param  array<string, mixed>  $filtros
     */
    public function historial(User $actor, array $filtros = [], string $permiso = 'accesos.ver'): LengthAwarePaginator
    {
        return $this->consultaHistorial($actor, $filtros, $permiso)
            ->with($this->relaciones(false))
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Acceso>
     */
    public function consultaHistorial(User $actor, array $filtros, string $permiso): Builder
    {
        $consulta = $this->filtrar($this->limitar(Acceso::query(), $actor, $permiso), $filtros)
            ->where('accesos.estado', 'finalizado');

        // Fechas en la hora local de quien consulta (se guardan en hora universal)
        $zona = $this->hora->zona();
        if (! empty($filtros['desde'])) {
            $consulta->where('accesos.entrada_at', '>=', Carbon::parse($filtros['desde'], $zona)->startOfDay()->utc());
        }
        if (! empty($filtros['hasta'])) {
            $consulta->where('accesos.entrada_at', '<=', Carbon::parse($filtros['hasta'], $zona)->endOfDay()->utc());
        }

        return $consulta->orderByDesc('accesos.entrada_at')->orderByDesc('accesos.id');
    }

    /**
     * Texto (nombre, placas, gafete, empresa, habitación, a quién visita), tipo y sede.
     *
     * @param  Builder<Acceso>  $consulta
     * @param  array<string, mixed>  $filtros
     * @return Builder<Acceso>
     */
    private function filtrar(Builder $consulta, array $filtros): Builder
    {
        if (! empty($filtros['tipo'])) {
            $consulta->where('accesos.tipo', $filtros['tipo']);
        }
        if (! empty($filtros['sede'])) {
            $consulta->where('accesos.sede_id', (int) $filtros['sede']);
        }
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) ($filtros['q'] ?? '')));
        if ($texto !== '') {
            // Todas las palabras en algún dato… o el texto completo como placas ("abc 123" = ABC123)
            $normalizadas = Vehiculo::normalizarPlacas($texto);
            $placas = $normalizadas === '' ? null : '%'.addcslashes($normalizadas, '%_\\').'%';
            $consulta->where(fn ($todo) => $todo
                ->where(function ($palabras) use ($texto) {
                    foreach (explode(' ', mb_strtolower($texto)) as $palabra) {
                        $comodin = '%'.addcslashes($palabra, '%_\\').'%';
                        $palabras->where(fn ($q) => $q->whereRaw('LOWER(accesos.nombre) LIKE ?', [$comodin])
                            ->orWhereRaw('LOWER(accesos.gafete_texto) LIKE ?', [$comodin])
                            ->orWhereRaw('LOWER(accesos.empresa_procedencia) LIKE ?', [$comodin])
                            ->orWhereRaw('LOWER(accesos.habitacion) LIKE ?', [$comodin])
                            ->orWhereRaw('LOWER(accesos.persona_visita) LIKE ?', [$comodin])
                            ->orWhereRaw('LOWER(accesos.conductor) LIKE ?', [$comodin])
                            // Ronda 8 (AC-05): también por el nombre o el gafete de un acompañante
                            ->orWhereHas('acompanantes', fn ($ac) => $ac->where(fn ($x) => $x->whereRaw('LOWER(nombre) LIKE ?', [$comodin])
                                ->orWhereRaw('LOWER(gafete_texto) LIKE ?', [$comodin]))));
                    }
                })
                ->when($placas !== null, fn ($q) => $q->orWhere('accesos.placas', 'like', $placas)));
        }

        return $consulta;
    }

    /**
     * Texto con el que la pantalla filtra las tarjetas sin recargar.
     */
    public static function textoBusqueda(Acceso $a): string
    {
        return mb_strtolower(implode(' ', array_filter([
            $a->nombre, $a->empresa_procedencia, $a->placas, $a->gafete_texto, $a->habitacion, $a->persona_visita, $a->conductor,
            $a->host?->nombreCompleto(), $a->colaborador?->num_empleado, $a->zona?->nombre, $a->sede?->nombre,
            ...$a->acompanantes->map(fn ($ac) => trim($ac->nombre.' '.$ac->gafete_texto))->all(),
        ])));
    }

    /** Minúsculas y sin acentos, para comparar lo que se escribe ("sofia mendez" = "SOFÍA MÉNDEZ"). */
    public static function normalizar(?string $texto): string
    {
        return Str::lower(Str::ascii(trim((string) preg_replace('/\s+/u', ' ', (string) $texto))));
    }

    /**
     * Ronda 8 (AC-05): el acompañante del acceso que coincide con lo buscado
     * (todas las palabras en su nombre o su gafete), para avisar en la tarjeta
     * «Coincide con X, acompañante de Y». Null si el titular es quien coincide.
     */
    public static function acompananteQueCoincide(Acceso $a, ?string $texto): ?AcompananteAcceso
    {
        $palabras = array_filter(explode(' ', self::normalizar($texto)));
        if ($palabras === [] || ! $a->relationLoaded('acompanantes')) {
            return null;
        }
        $titular = self::normalizar(implode(' ', [$a->nombre, $a->gafete_texto, $a->empresa_procedencia, $a->habitacion, $a->persona_visita, $a->conductor]));
        if (array_filter($palabras, fn ($p) => ! str_contains($titular, $p)) === []) {
            return null;
        }

        return $a->acompanantes->first(function (AcompananteAcceso $ac) use ($palabras) {
            $suyo = self::normalizar($ac->nombre.' '.$ac->gafete_texto);

            return array_filter($palabras, fn ($p) => ! str_contains($suyo, $p)) === [];
        });
    }

    // ------------------------------------------------- Buscar y dar salida rápido

    /**
     * Personas en sitio (o fuera en tour) por nombre, placas, gafete (del titular
     * o de un acompañante) o habitación. Para "Dar Salida" desde el formulario.
     *
     * @return list<array<string, mixed>>
     */
    public function buscarEnSitio(User $actor, string $texto, ?int $gafeteId = null): array
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if ($gafeteId === null && mb_strlen($texto) < 2) {
            return [];
        }

        $consulta = $this->limitar(Acceso::query(), $actor, 'accesos.ver')
            ->where('accesos.estado', 'en_sitio')->where('accesos.movimiento', 'entrada');

        if ($gafeteId !== null) {
            $consulta->where(fn ($q) => $q->where('accesos.gafete_id', $gafeteId)
                ->orWhereHas('acompanantes', fn ($ac) => $ac->where('gafete_id', $gafeteId)->whereNull('salida_at')));
        } else {
            $comodin = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
            $normalizadas = Vehiculo::normalizarPlacas($texto);
            $consulta->where(fn ($q) => $q->whereRaw('LOWER(accesos.nombre) LIKE ?', [$comodin])
                ->orWhereRaw('LOWER(accesos.gafete_texto) LIKE ?', [$comodin])
                ->orWhereRaw('LOWER(accesos.habitacion) LIKE ?', [$comodin])
                ->when($normalizadas !== '', fn ($p) => $p->orWhere('accesos.placas', 'like', '%'.addcslashes($normalizadas, '%_\\').'%'))
                ->orWhereHas('acompanantes', fn ($ac) => $ac->whereNull('salida_at')
                    ->where(fn ($x) => $x->whereRaw('LOWER(gafete_texto) LIKE ?', [$comodin])->orWhereRaw('LOWER(nombre) LIKE ?', [$comodin]))));
        }

        return $consulta->with($this->relaciones(true))
            ->orderByDesc('accesos.entrada_at')->limit(10)->get()
            ->map(fn (Acceso $a) => $this->resumen($a, $gafeteId === null ? $texto : null))
            ->all();
    }

    /**
     * @param  string|null  $buscado  lo que se escribió (Ronda 8: avisa si coincidió un acompañante)
     * @return array<string, mixed>
     */
    public function resumen(Acceso $a, ?string $buscado = null): array
    {
        $fuera = $a->relationLoaded('salidaTemporalAbierta') ? $a->salidaTemporalAbierta !== null : false;

        return [
            'id' => $a->id,
            'nombre' => $a->nombre,
            'coincide_acompanante' => self::acompananteQueCoincide($a, $buscado)?->nombreVisible(),
            'tipo' => $a->tipo,
            'tipo_etiqueta' => $a->etiquetaTipo(),
            'sede' => $a->sede?->nombre,
            'habitacion' => $a->habitacion,
            'placas' => $a->placas,
            'zona' => $a->zona?->nombre,
            'zona_descarga' => $a->zona?->esDescarga() ?? false,
            'gafete' => $a->gafete_texto,
            'identificacion' => Acceso::IDENTIFICACIONES[$a->identificacion] ?? null,
            'fuera_temporal' => $fuera,
            'salida_temporal' => $a->admiteSalidaTemporal() && ! $fuera,
            'texto_salida_temporal' => $a->textoSalidaTemporal(),
            'texto_salida' => $a->tipo === 'huesped' ? 'Salida Final' : 'Salida',
            'acompanantes' => $a->acompanantes->map(fn ($ac) => [
                'id' => $ac->id,
                'nombre' => $ac->nombreVisible(),
                'gafete' => $ac->gafete_texto,
                'fuera_temporal' => $ac->estaFueraTemporal(),
                'url_salida' => route('accesos.acompanantes.salida', $ac->id),
            ])->values()->all(),
            'url_salida' => route('accesos.salida', $a->id),
            'url_salida_temporal' => route('accesos.salida-temporal', $a->id),
            'url_regreso' => route('accesos.regreso', $a->id),
        ];
    }

    // ------------------------------------------------------- Zonas y gafetes

    /**
     * Zonas activas de las sedes, con cuántos vehículos las ocupan ahora.
     *
     * @param  list<int>  $sedeIds
     * @return list<array{id: int, sede_id: int, nombre: string, descarga: bool, cupo: ?int, ocupados: int, lleno: bool, texto: string}>
     */
    public function zonas(array $sedeIds): array
    {
        if ($sedeIds === []) {
            return [];
        }
        $zonas = ZonaEstacionamiento::where('activo', true)->whereIn('sede_id', $sedeIds)
            ->orderBy('tipo')->orderBy('nombre')->get(['id', 'sede_id', 'nombre', 'tipo', 'cupo_total']);
        $conteo = $zonas->isEmpty() ? [] : $this->ocupacion->ocupados($zonas->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $zonas->map(function (ZonaEstacionamiento $z) use ($conteo) {
            $ocupados = (int) ($conteo[$z->id] ?? 0);
            // Ronda 6 (ES-02): una zona de descarga con capacidad también se llena
            $lleno = $z->estaLlena($ocupados);

            return [
                'id' => $z->id, 'sede_id' => $z->sede_id, 'nombre' => $z->nombre, 'descarga' => $z->esDescarga(),
                'cupo' => $z->cupo_total, 'ocupados' => $ocupados, 'lleno' => $lleno,
                // Textos de SEGCAT: "Sótano 1A (3/8)", "Lobby (zona de descarga)", "… — LLENO"
                'texto' => $z->nombre.' '.($z->esDescarga() ? '(zona de descarga'.($z->tieneCupo() ? ' '.$ocupados.'/'.$z->cupo_total : '').')' : '('.$ocupados.'/'.$z->cupo_total.')').($lleno ? ' — LLENO' : ''),
            ];
        })->values()->all();
    }

    /**
     * Gafetes que se pueden prestar ahora en la sede (activos y sin prestar).
     *
     * @return list<array{id: int, titulo: string, detalle: string, tipo_id: int, tipo: string, activo: bool}>
     */
    public function gafetesDisponibles(int $sedeId): array
    {
        return Gafete::query()->where('sede_id', $sedeId)->disponibles()
            ->with('tipo:id,nombre')->orderBy('nomenclatura')->limit(500)
            ->get(['id', 'nomenclatura', 'tipo_gafete_id', 'activo'])
            ->map(fn (Gafete $g) => [
                'id' => $g->id, 'titulo' => $g->nomenclatura, 'detalle' => $g->tipo?->nombre ?? 'Gafete',
                'tipo_id' => (int) $g->tipo_gafete_id, 'tipo' => mb_strtolower($g->tipo?->nombre ?? ''), 'activo' => true,
            ])->all();
    }

    // ------------------------------------------------- Autocompletar del formulario

    /**
     * Sugerencias al escribir: colaboradores de la sede, personas del padrón,
     * vehículos por placas (con tipo, marca, modelo y color para llenar el
     * formulario) y empresas externas. Reutiliza la búsqueda de cada padrón.
     *
     * @return list<array<string, mixed>>
     */
    public function sugerencias(User $actor, string $que, string $texto, ?int $sede, ?string $tipoPersona): array
    {
        $sedes = $this->sedes($actor, 'accesos.crear');
        $enSede = $sede !== null && ($sedes === null || in_array($sede, $sedes, true)) ? [$sede] : $sedes;

        return match ($que) {
            'colaborador' => app(AdministradorColaboradores::class)->buscar($texto, $enSede, false)['resultados'],
            // Primero las personas del tipo que se registra
            'persona' => collect(app(AdministradorPersonas::class)->buscar($texto))
                ->sortBy(fn ($p) => $p['tipo'] === $tipoPersona ? 0 : 1)->values()->all(),
            'vehiculo' => $this->vehiculosConDatos($texto),
            'proveedor' => app(AdministradorProveedores::class)->buscar($texto, $enSede),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vehiculosConDatos(string $texto): array
    {
        $resultados = app(AdministradorVehiculos::class)->buscar($texto);
        $datos = Vehiculo::whereIn('id', array_column($resultados, 'id'))->get(['id', 'tipo', 'marca', 'modelo', 'color'])->keyBy('id');

        return array_map(function (array $v) use ($datos) {
            $modelo = $datos[$v['id']] ?? null;

            return $v + ($modelo ? $modelo->only(['tipo', 'marca', 'modelo', 'color']) : []);
        }, $resultados);
    }

    /**
     * Títulos de lo que ya se había elegido, para volver a pintar el formulario
     * tal cual tras un error de captura.
     *
     * @param  array<string, mixed>  $anterior  lo enviado (old())
     * @return array<string, string>
     */
    public function previos(array $anterior): array
    {
        $previos = [];
        foreach (['colaborador_id', 'host_colaborador_id', 'visita_colaborador_id'] as $campo) {
            if (! empty($anterior[$campo]) && ($c = Colaborador::find((int) $anterior[$campo])) !== null) {
                $previos[$campo] = $c->nombreCompleto().($c->num_empleado ? ' · Núm. '.$c->num_empleado : '');
            }
        }
        if (! empty($anterior['gafete_id']) && ($g = Gafete::find((int) $anterior['gafete_id'])) !== null) {
            $previos['gafete_id'] = $g->nomenclatura;
        }
        if (! empty($anterior['vehiculo_id']) && ($v = Vehiculo::find((int) $anterior['vehiculo_id'])) !== null) {
            $previos['vehiculo_id'] = $v->placas;
        }

        return $previos;
    }

    /**
     * Tipos de gafete activos de la empresa (para el filtro "Tipo de Gafete").
     *
     * @return Collection<int, TipoGafete>
     */
    public function tiposGafete(): Collection
    {
        return TipoGafete::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
    }
}
