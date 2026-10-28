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
use App\Services\Espacios\Lugares;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Services\Padrones\AdministradorProveedores;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Personas\AdministradorPersonas;
use App\Services\Relojes\Relojes\Permanencia;
use App\Services\Relojes\Relojes\SalidaTemporal;
use App\Services\Vehiculos\AdministradorVehiculos;
use App\Support\HoraLocal;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
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
        $colaborador = 'id,tipo,espacio_id,num_empleado,nombre,apellido_paterno,apellido_materno';

        return [
            'sede:id,nombre', 'colaborador:'.$colaborador, 'host:'.$colaborador, 'visitaColaborador:'.$colaborador,
            'proveedor:id,nombre', 'departamento:id,nombre', 'zona:id,nombre,tipo',
            'articulos:id,acceso_id,articulo,cantidad,serie', // C-19: lo que trae el proveedor
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
            // C-25: «Rebasaron su tiempo» (permanencia o salida temporal), para el cambio de turno
            ->when(! empty($filtros['rebasaron']), fn ($q) => $q->whereIn('accesos.id', $this->idsRebasaron() ?: [0]))
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
            // La solicitud pendiente (a quién espera y si ya se escaló) viene cargada: la tarjeta no consulta
            ->with([...$this->relaciones(true), 'autorizacionDepartamento.departamento:id,nombre', 'autorizacionDepartamento.colaborador:id,nombre,apellido_paterno,apellido_materno'])
            ->orderBy('accesos.entrada_at')->orderBy('accesos.id')
            ->get();
    }

    /**
     * C-25: ingresos que rebasaron su tiempo en sitio o cuya salida temporal no regresó.
     *
     * @return list<int>
     */
    public function idsRebasaron(): array
    {
        $empresaId = app(Tenant::class)->empresaId();

        return array_values(array_unique([
            ...app(Permanencia::class)->idsRebasaron($empresaId),
            ...app(SalidaTemporal::class)->idsRebasaron($empresaId),
        ]));
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
        // C-25: las aprobaciones por excepción del agente que esperan revisión
        if (! empty($filtros['excepciones'])) {
            $consulta->where('accesos.excepcion_por_revisar', true)->whereNull('accesos.excepcion_revisada_en');
        }
        // C-19: las salidas con algo distinto que esperan la revisión del supervisor
        if (! empty($filtros['articulos'])) {
            $consulta->where('accesos.articulos_por_revisar', true)->whereNull('accesos.articulos_revisado_en');
        }
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
     * o de un acompañante) o habitación. Para «Dar salida»: el campo a la vista
     * de la bitácora y la vista «Dar Salida» del formulario.
     *
     * Lo que entrega un lector (el QR del gafete, su etiqueta NFC/RFID o la
     * nomenclatura impresa) también se reconoce en el texto: se buscan los
     * gafetes que coinciden con la lectura y se incluye a quien los tiene.
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

        $gafetes = $gafeteId !== null ? [$gafeteId] : $this->gafetesLeidos($texto);
        $porGafete = fn ($q) => $q->whereIn('accesos.gafete_id', $gafetes)
            ->orWhereHas('acompanantes', fn ($ac) => $ac->whereIn('gafete_id', $gafetes)->whereNull('salida_at'));

        if ($gafeteId !== null) {
            $consulta->where($porGafete);
        } else {
            $comodin = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
            $normalizadas = Vehiculo::normalizarPlacas($texto);
            $consulta->where(fn ($q) => $q->whereRaw('LOWER(accesos.nombre) LIKE ?', [$comodin])
                ->orWhereRaw('LOWER(accesos.gafete_texto) LIKE ?', [$comodin])
                ->orWhereRaw('LOWER(accesos.habitacion) LIKE ?', [$comodin])
                ->when($normalizadas !== '', fn ($p) => $p->orWhere('accesos.placas', 'like', '%'.addcslashes($normalizadas, '%_\\').'%'))
                ->when($gafetes !== [], fn ($p) => $p->orWhere($porGafete))
                ->orWhereHas('acompanantes', fn ($ac) => $ac->whereNull('salida_at')
                    ->where(fn ($x) => $x->whereRaw('LOWER(gafete_texto) LIKE ?', [$comodin])->orWhereRaw('LOWER(nombre) LIKE ?', [$comodin]))));
        }

        return $consulta->with($this->relaciones(true))
            ->orderByDesc('accesos.entrada_at')->limit(10)->get()
            ->map(fn (Acceso $a) => $this->resumen($a, $gafeteId === null ? $texto : null))
            ->all();
    }

    /**
     * Gafetes de la empresa que corresponden a una lectura (QR con dirección,
     * etiqueta NFC/RFID en cualquiera de sus formas o la nomenclatura).
     *
     * @return list<int>
     */
    private function gafetesLeidos(string $texto): array
    {
        if ($texto === '' || mb_strlen($texto) > Etiqueta::MAXIMO) {
            return [];
        }
        $codigo = Etiqueta::codigoDeUrl($texto);
        $candidatos = $codigo !== null ? [] : Etiqueta::candidatos($texto);
        if ($codigo === null && $candidatos === []) {
            return [];
        }

        return Gafete::query()->coincideConLectura($codigo, $candidatos)->limit(5)->pluck('id')->map(fn ($id) => (int) $id)->all();
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
            // Giros: «Hab. 204» en hoteles; «Depto. 101» o «Casa 12» en condominios y fraccionamientos
            'etiqueta_espacio' => $a->terminos()->abreviaturaEspacio($a->tipo),
            'nombre_espacio' => $a->tipo === 'huesped' ? 'Habitación' : $a->terminos()->espacio(),
            'placas' => $a->placas,
            'zona' => $a->zona?->nombre,
            'zona_descarga' => $a->zona?->esDescarga() ?? false,
            'gafete' => $a->gafete_texto,
            'gafete_id' => $a->gafete_id,
            'identificacion' => Acceso::IDENTIFICACIONES[$a->identificacion] ?? null,
            'fuera_temporal' => $fuera,
            'salida_temporal' => $a->admiteSalidaTemporal() && ! $fuera,
            'texto_salida_temporal' => $a->textoSalidaTemporal(),
            'texto_salida' => $a->tipo === 'huesped' ? 'Salida Final' : 'Salida',
            'acompanantes' => $a->acompanantes->map(fn ($ac) => [
                'id' => $ac->id,
                'nombre' => $ac->nombreVisible(),
                'gafete' => $ac->gafete_texto,
                'gafete_id' => $ac->gafete_id,
                'fuera_temporal' => $ac->estaFueraTemporal(),
                'url_salida' => route('accesos.acompanantes.salida', $ac->id),
            ])->values()->all(),
            // C-19 (DD-18): lo que trajo, para compararlo al salir, y el pase aprobado de su empresa
            'articulos' => ($a->relationLoaded('articulos') ? $a->articulos : $a->articulos()->get())->map(fn ($art) => $art->texto())->values()->all(),
            'pase' => ($pase = app(ArticulosDelProveedor::class)->paseAbierto($a)) === null ? null : [
                'id' => $pase->id, 'folio' => $pase->folio, 'motivo' => $pase->etiquetaMotivo(),
                'url' => auth()->user()?->can('pases_salida.ver') ? route('pases-salida.show', $pase->id) : null,
            ],
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
    public function sugerencias(User $actor, string $que, string $texto, ?int $sede, ?string $tipoPersona, string $contexto = 'visita', string $niveles = 'todos'): array
    {
        $sedes = $this->sedes($actor, 'accesos.crear');
        $enSede = $sede !== null && ($sedes === null || in_array($sede, $sedes, true)) ? [$sede] : $sedes;

        return match ($que) {
            'destino' => $this->conCuentaYTelefono(app(AQuienViene::class)->sugerencias($texto, $enSede, $contexto, Terminos::actual())),
            'lugar' => app(Lugares::class)->buscar($texto, $enSede, $niveles === 'especificos' ? Lugares::ESPECIFICOS : Lugares::TODOS, 8)->all(),
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
     * C-09: si la persona tiene cuenta (autoriza ella) y el teléfono del residente
     * (la caseta le llama). Una consulta por cada dato, no por renglón.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function conCuentaYTelefono(array $items): array
    {
        $ids = collect($items)->filter(fn ($i) => str_starts_with((string) $i['clave'], 'c:'))->map(fn ($i) => (int) substr($i['clave'], 2))->values();
        $conCuenta = $ids->isEmpty() ? collect() : User::whereIn('colaborador_id', $ids)->where('activo', true)->pluck('colaborador_id')->map(fn ($id) => (int) $id);
        $telefonos = $ids->isEmpty() ? collect() : Colaborador::whereIn('id', $ids)->where('tipo', Colaborador::RESIDENTE)->pluck('telefono', 'id');
        $viviendas = collect($items)->filter(fn ($i) => str_starts_with((string) $i['clave'], 'e:'))->map(fn ($i) => (int) substr($i['clave'], 2))->values();
        $telVivienda = $viviendas->isEmpty() ? collect() : Colaborador::residentes()->where('activo', true)->whereIn('espacio_id', $viviendas)
            ->whereNotNull('telefono')->orderBy('id')->get(['espacio_id', 'telefono'])->groupBy('espacio_id')->map(fn ($g) => $g->pluck('telefono')->join(' · '));

        return array_map(function (array $i) use ($conCuenta, $telefonos, $telVivienda) {
            $id = (int) substr((string) $i['clave'], 2);

            return $i + [
                'cuenta' => str_starts_with((string) $i['clave'], 'c:') && ! ($i['llamar'] ?? false) && $conCuenta->contains($id),
                'telefono' => str_starts_with((string) $i['clave'], 'c:') ? ($telefonos[$id] ?? null) : (str_starts_with((string) $i['clave'], 'e:') ? ($telVivienda[$id] ?? null) : null),
            ];
        }, $items);
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
        // C-09: lo elegido en «¿A quién viene?»
        if (! empty($anterior['a_quien']) && ($titulo = app(AQuienViene::class)->titulo((string) $anterior['a_quien'], Terminos::actual())) !== null) {
            $previos['a_quien'] = mb_strtoupper($titulo);
        }
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
