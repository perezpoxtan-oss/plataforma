<?php

namespace App\Services\Informes;

use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\Llave;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PrestamoLlave;
use App\Models\Proveedor;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vacante;
use App\Models\Vehiculo;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\EstadoActivo;
use App\Support\GraficaSvg;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Informe ejecutivo para Dirección (ADR-0017): los bloques del sistema
 * original (Personal y Activos, Seguridad física, Robo 90 días, Pases de
 * salida 90 días, Transporte 30 días) más Candidatos y vacantes y
 * Autorizaciones. Cada ficha sale solo si la persona puede ver el módulo de
 * origen; nunca se muestran CURP, RFC, NSS, teléfonos ni currículos. El
 * resultado se guarda 5 minutos por empresa, alcance y sedes elegidas.
 */
class InformeEjecutivo
{
    public const PERMISO = 'informe_ejecutivo.ver';

    public const MAX_FILAS = 100;

    public const MINUTOS_CACHE = 5;

    public function __construct(
        private readonly AlcanceInformes $alcance,
        private readonly HoraLocal $hora,
        private readonly AdministradorPasesSalida $pases,
        private readonly AdministradorUsuarios $usuarios,
        private readonly AdministradorVacantes $vacantes,
    ) {}

    /** Permisos de origen de las fichas (para la huella de la caché). */
    private const ORIGENES = ['colaboradores.ver', 'usuarios.ver', 'vehiculos.ver', 'proveedores.ver', 'prestamo_llaves.ver', 'equipos.ver',
        'robo.ver', 'pases_salida.ver', 'transporte.ver', 'candidatos.ver', 'vacantes.ver', 'autorizaciones.ver'];

    /**
     * Bloques con sus fichas (solo las que la persona puede ver).
     *
     * @param  list<int>|null  $sedes
     * @return list<array{titulo: string, icono: string, fichas: list<array<string, mixed>>}>
     */
    public function bloques(User $actor, int $empresaId, ?array $sedes, bool $fresco = false): array
    {
        $clave = 'informes:ejecutivo:'.$empresaId.':'.$this->alcance->huella($actor, self::PERMISO, self::ORIGENES, $sedes)
            .':'.$this->hora->zona().':'.CarbonImmutable::now($this->hora->zona())->format('Ymd');
        if ($fresco) {
            Cache::forget($clave);
        }

        return Cache::remember($clave, now()->addMinutes(self::MINUTOS_CACHE), fn () => $this->calcular($actor, $sedes));
    }

    /** @return list<array{titulo: string, icono: string, fichas: list<array<string, mixed>>}> */
    private function calcular(User $actor, ?array $sedes): array
    {
        $definicion = [
            ['Personal y activos', 'bi-people-fill', ['colaboradores' => 'colaboradores.ver', 'usuarios' => 'usuarios.ver', 'vehiculos' => 'vehiculos.ver', 'proveedores' => 'proveedores.ver']],
            ['Seguridad física', 'bi-shield-lock-fill', ['llaves' => 'prestamo_llaves.ver', 'equipos' => 'equipos.ver']],
            ['Robo — últimos 90 días', 'bi-exclamation-octagon-fill', ['robo' => 'robo.ver']],
            ['Pases de salida — últimos 90 días', 'bi-box-arrow-right', ['pases' => 'pases_salida.ver']],
            ['Transporte — últimos 30 días', 'bi-bus-front-fill', ['transporte' => 'transporte.ver']],
            ['Candidatos y vacantes', 'bi-person-workspace', ['candidatos' => 'candidatos.ver', 'vacantes' => 'vacantes.ver']],
            ['Autorizaciones — últimos 30 días', 'bi-patch-check-fill', ['autorizaciones' => 'autorizaciones.ver']],
        ];

        $bloques = [];
        foreach ($definicion as [$titulo, $icono, $fichas]) {
            $calculadas = [];
            foreach ($fichas as $clave => $permiso) {
                if (! $actor->can($permiso)) {
                    continue;
                }
                try {
                    $calculadas = array_merge($calculadas, $this->{$clave}($actor, $sedes, $permiso));
                } catch (Throwable $e) {
                    report($e); // la ficha se omite; el resto del informe sigue
                }
            }
            if ($calculadas !== []) {
                $bloques[] = ['titulo' => $titulo, 'icono' => $icono, 'fichas' => $calculadas];
            }
        }

        return $bloques;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $q
     * @return Builder<T>
     */
    private function limitar(Builder $q, User $actor, string $permiso, ?array $sedes, ?string $columnaSede, ?string $autor = null): Builder
    {
        return $this->alcance->limitar($q, $actor, self::PERMISO, $permiso, $sedes, $columnaSede, $autor);
    }

    /** Hace N días (hora local) en hora universal. */
    private function hace(int $dias): string
    {
        return CarbonImmutable::now($this->hora->zona())->startOfDay()->subDays($dias - 1)->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function ficha(string $titulo, string $valor, array $extra = []): array
    {
        return $extra + ['titulo' => $titulo, 'valor' => $valor, 'nota' => null, 'nivel' => null, 'grafica' => null, 'columnas' => [], 'filas' => [], 'enlace' => null];
    }

    // ------------------------------------------------------- Personal y activos

    private function colaboradores(User $actor, ?array $sedes, string $permiso): array
    {
        $q = Colaborador::query()->personal()->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id'); // C-10: sin residentes
        $permitidas = $this->alcance->sedes($actor, self::PERMISO, $permiso, $sedes);
        $q = $permitidas === null ? $q : $q->enSedes($permitidas);
        $q = $this->limitar($q, $actor, $permiso, $sedes, null, 'colaboradores.creado_por');
        $porDepto = Indicadores::por((clone $q)->leftJoin('departamentos', 'departamentos.id', '=', 'colaboradores.departamento_id'), 'departamentos.nombre', [], null, 'Sin departamento');
        $filas = (clone $q)->with(['departamento:id,nombre', 'puesto:id,nombre'])->orderBy('colaboradores.nombre')->limit(self::MAX_FILAS)
            ->get(['colaboradores.id', 'num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno', 'departamento_id', 'puesto_id'])
            ->map(fn (Colaborador $c) => [$c->num_empleado ?? '—', $c->nombreCompleto(), $c->departamento?->nombre ?? '—', $c->puesto?->nombre ?? '—'])->all();

        return [$this->ficha('Colaboradores activos', number_format(array_sum($porDepto)), [
            'grafica' => GraficaSvg::barras($porDepto, 'Colaboradores por departamento', '', 8),
            'columnas' => ['Núm.', 'Nombre', 'Departamento', 'Puesto'], 'filas' => $filas, 'enlace' => route('colaboradores.index'),
        ])];
    }

    private function usuarios(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->usuarios->limitarAlcance(User::query()->where('users.empresa_id', app(Tenant::class)->empresaId())->where('users.activo', true), $actor, $permiso);
        $permitidas = $this->alcance->sedes($actor, self::PERMISO, $permiso, $sedes);
        if ($permitidas !== null) {
            $q->whereHas('roles', fn ($r) => $r->whereIn('usuario_roles.sede_id', $permitidas));
        }
        $total = (clone $q)->count();
        $porRol = Indicadores::por((clone $q)->join('usuario_roles', 'usuario_roles.user_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'usuario_roles.rol_id'), 'roles.nombre', [], null, 'Sin rol');
        $filas = (clone $q)->with('roles:id,nombre')->orderBy('users.name')->limit(self::MAX_FILAS)->get(['users.id', 'users.name'])
            ->map(fn (User $u) => [$u->name, $u->roles->pluck('nombre')->unique()->implode(', ') ?: '—'])->all();

        return [$this->ficha('Usuarios de la plataforma', number_format($total), [
            'grafica' => GraficaSvg::barras($porRol, 'Usuarios por rol'),
            'columnas' => ['Nombre', 'Rol'], 'filas' => $filas, 'enlace' => route('usuarios.index'),
        ])];
    }

    private function vehiculos(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(Vehiculo::query()->where('vehiculos.activo', true)->whereNull('vehiculos.fusionado_en_id'), $actor, $permiso, null, null, 'vehiculos.creado_por');
        $porTipo = Indicadores::por($q, 'vehiculos.tipo', Vehiculo::TIPOS);
        $filas = (clone $q)->orderBy('vehiculos.placas')->limit(self::MAX_FILAS)->get(['placas', 'marca', 'modelo', 'color', 'tipo'])
            ->map(fn (Vehiculo $v) => [$v->placas, $v->marca ?: '—', $v->modelo ?: '—', $v->color ?: '—', Vehiculo::TIPOS[$v->tipo] ?? $v->tipo])->all();

        return [$this->ficha('Vehículos registrados', number_format(array_sum($porTipo)), [
            'nota' => 'De toda la empresa (el padrón no es por sede)',
            'grafica' => GraficaSvg::barras($porTipo, 'Vehículos por tipo'),
            'columnas' => ['Placas', 'Marca', 'Modelo', 'Color', 'Tipo'], 'filas' => $filas, 'enlace' => route('vehiculos.index'),
        ])];
    }

    private function proveedores(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(Proveedor::query()->where('proveedores.activo', true)->whereNull('proveedores.fusionado_en_id'), $actor, $permiso, null, null);
        $porCategoria = Indicadores::por($q, 'proveedores.categoria', Proveedor::CATEGORIAS, null, 'Sin categoría');
        // Sin RFC ni teléfono: el informe no muestra datos de contacto
        $filas = (clone $q)->orderBy('proveedores.nombre')->limit(self::MAX_FILAS)->get(['nombre', 'categoria'])
            ->map(fn (Proveedor $p) => [$p->nombre, Proveedor::CATEGORIAS[$p->categoria] ?? ($p->categoria ?: '—')])->all();

        return [$this->ficha('Proveedores y contratistas', number_format(array_sum($porCategoria)), [
            'nota' => 'De toda la empresa',
            'grafica' => GraficaSvg::barras($porCategoria, 'Proveedores por categoría'),
            'columnas' => ['Empresa', 'Categoría'], 'filas' => $filas, 'enlace' => route('proveedores.index'),
        ])];
    }

    // --------------------------------------------------------- Seguridad física

    private function llaves(User $actor, ?array $sedes, string $permiso): array
    {
        $prestamos = $this->limitar(PrestamoLlave::query()->vigentes(), $actor, $permiso, $sedes, 'prestamos_llaves.sede_id', 'prestamos_llaves.creado_por');
        $enUso = (clone $prestamos)->count();
        $total = $this->limitar(Llave::query()->where('llaves.activo', true), $actor, $permiso, $sedes, 'llaves.sede_id')->count();
        // Revisión Etapa 2 (R2-03): las que no regresaron no están disponibles
        $noRegreso = $this->limitar(Llave::query()->where('llaves.activo', true)->where('llaves.estado', EstadoActivo::NO_REGRESO), $actor, $permiso, $sedes, 'llaves.sede_id')->count();
        $filas = (clone $prestamos)->with(['llave:id,nomenclatura,descripcion', 'colaborador:id,nombre,apellido_paterno,apellido_materno'])
            ->orderBy('prestamos_llaves.prestado_en')->limit(self::MAX_FILAS)->get(['prestamos_llaves.id', 'llave_id', 'colaborador_id', 'prestado_en'])
            ->map(fn (PrestamoLlave $p) => [$p->llave?->nomenclatura ?? '—', $p->llave?->descripcion ?? '—', $p->nombreReceptor(), $this->hora->formatear($p->prestado_en, 'd/m/Y H:i')])->all();

        return [$this->ficha('Llaves en uso (de '.number_format($total).')', number_format($enUso), [
            'nivel' => 'destacada',
            'grafica' => GraficaSvg::barras(['En uso' => $enUso] + ($noRegreso > 0 ? ['No regresaron' => $noRegreso] : []) + ['Disponibles' => max(0, $total - $enUso - $noRegreso)], 'Llaves en uso y disponibles'),
            'columnas' => ['Llave', 'Descripción', 'La tiene', 'Desde'], 'filas' => $filas, 'enlace' => route('prestamo_llaves.index'),
        ])];
    }

    private function equipos(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(Equipo::query()->where('equipos.estado', '!=', 'baja'), $actor, $permiso, $sedes, 'equipos.sede_id', 'equipos.creado_por');
        $porEstado = Indicadores::por($q, 'equipos.estado', Equipo::ESTADOS);
        $asignados = ($porEstado[Equipo::ESTADOS['asignado']] ?? 0) + ($porEstado[Equipo::ESTADOS['en_uso']] ?? 0);
        $filas = (clone $q)->with('tipo:id,nombre')->orderBy('equipos.estado')->limit(self::MAX_FILAS)->get(['equipos.id', 'tipo_equipo_id', 'marca', 'modelo', 'numero_serie', 'estado'])
            ->map(fn (Equipo $e) => [$e->tipo?->nombre ?? '—', trim(($e->marca ?? '').' '.($e->modelo ?? '')) ?: '—', $e->numero_serie ?: '—', Equipo::ESTADOS[$e->estado] ?? $e->estado])->all();

        return [$this->ficha('Equipos asignados (de '.number_format(array_sum($porEstado)).')', number_format($asignados), [
            'grafica' => GraficaSvg::barras($porEstado, 'Equipos por estado (sin bajas)'),
            'columnas' => ['Tipo', 'Marca y modelo', 'Serie', 'Estado'], 'filas' => $filas, 'enlace' => route('equipos.index'),
        ])];
    }

    // -------------------------------------------------------------------- Robo

    private function robo(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(Novedad::query()->where('novedades.categoria', 'robo')->where('novedades.created_at', '>=', $this->hace(90))
            ->leftJoin('robo_detalles', 'robo_detalles.novedad_id', '=', 'novedades.id'), $actor, $permiso, $sedes, 'novedades.sede_id', 'novedades.creado_por');
        $c = Indicadores::conteos($q, ['total' => '1 = 1', 'con_parte' => 'robo_detalles.parte_policia = 1']);
        $valor = (float) (clone $q)->toBase()->sum('robo_detalles.valor_estimado');
        $sinParte = $c['total'] - $c['con_parte'];
        $filas = (clone $q)->orderByDesc('novedades.created_at')->limit(self::MAX_FILAS)
            ->get(['novedades.id', 'novedades.numero', 'novedades.created_at', 'novedades.ubicacion', 'novedades.estatus', 'robo_detalles.lugar_exacto', 'robo_detalles.objetos_descripcion', 'robo_detalles.valor_estimado', 'robo_detalles.parte_policia'])
            ->map(fn (Novedad $n) => [$n->folio(), $this->hora->formatear($n->created_at, 'd/m/Y H:i'), $n->getAttribute('lugar_exacto') ?: ($n->ubicacion ?: '—'),
                Indicadores::corto($n->getAttribute('objetos_descripcion'), 60) ?: '—', (float) $n->getAttribute('valor_estimado') > 0 ? GraficaSvg::numero((float) $n->getAttribute('valor_estimado'), '$') : '—',
                $n->getAttribute('parte_policia') ? 'Sí' : 'No', $n->etiquetaEstatus()])->all();

        return [
            $this->ficha('Casos registrados', number_format($c['total']), [
                'nivel' => 'alerta',
                'grafica' => GraficaSvg::barras(['Con parte a la policía' => $c['con_parte'], 'Sin parte a la policía' => $sinParte], 'Robo: con y sin parte a la policía'),
                'columnas' => ['Folio', 'Fecha', 'Lugar', 'Qué se llevaron', 'Valor estimado', 'Parte policial', 'Estatus'], 'filas' => $filas, 'enlace' => route('robo.index'),
            ]),
            $this->ficha('Sin parte a la policía', number_format($sinParte), ['nivel' => $sinParte > 0 ? 'alerta' : 'positiva']),
            $this->ficha('Valor estimado total', GraficaSvg::numero($valor, '$'), ['nivel' => 'alerta']),
        ];
    }

    // ---------------------------------------------------------- Pases de salida

    private function pases(User $actor, ?array $sedes, string $permiso): array
    {
        $base = fn () => $this->limitar(PaseSalida::query(), $actor, $permiso, $sedes, 'pases_salida.sede_id', 'pases_salida.creado_por');
        $periodo = $base()->where('pases_salida.created_at', '>=', $this->hace(90));
        $fuera = $base()->whereIn('pases_salida.estado', PaseSalida::FUERA)->count();
        $vencidos = $this->pases->filtrar($base(), 'vencidos')->count();
        $porMotivo = Indicadores::por($periodo, 'pases_salida.motivo', PaseSalida::MOTIVOS);
        // Revisión Etapa 2: un pase cerrado con faltantes dice cuántos faltan o que ya se recibió todo después del cierre
        $filas = (clone $periodo)->with('solicitante:id,nombre,apellido_paterno,apellido_materno')
            ->withSum('articulos as total_articulos', 'cantidad')->withSum('articulos as total_regresados', 'cantidad_regresada')
            ->orderByDesc('pases_salida.created_at')->limit(self::MAX_FILAS)
            ->get(['pases_salida.id', 'pases_salida.sede_id', 'folio', 'motivo', 'estado', 'colaborador_id', 'pases_salida.created_at', 'fecha_tentativa_regreso', 'requiere_regreso', 'cerrado_con_faltantes'])
            ->map(fn (PaseSalida $p) => [$p->folio, $p->etiquetaMotivo(), $this->estatusPase($p), $p->solicitante?->nombreCompleto() ?? '—',
                $this->hora->formatear($p->created_at, 'd/m/Y'), $p->fecha_tentativa_regreso ? $p->fecha_tentativa_regreso->format('d/m/Y').($this->pases->vencido($p) ? ' (VENCIDO)' : '') : '—'])->all();

        return [
            $this->ficha('Pases creados', number_format(array_sum($porMotivo)), [
                'nivel' => 'destacada',
                'grafica' => GraficaSvg::barras($porMotivo, 'Pases de salida por motivo'),
                'columnas' => ['Folio', 'Motivo', 'Estatus', 'Solicitante', 'Creado', 'Regreso programado'], 'filas' => $filas, 'enlace' => route('pases-salida.index'),
            ]),
            $this->ficha('Fuera de la propiedad ahora', number_format($fuera), ['enlace' => route('pases-salida.index', ['filtro' => 'fuera'])]),
            $this->ficha('Vencidos sin regresar', number_format($vencidos), ['nivel' => $vencidos > 0 ? 'alerta' : 'positiva', 'enlace' => route('pases-salida.index', ['filtro' => 'vencidos'])]),
        ];
    }

    private function estatusPase(PaseSalida $p): string
    {
        if ($p->estado !== PaseSalida::REGRESADO || ! $p->cerrado_con_faltantes) {
            return PaseSalida::ESTADOS[$p->estado][0] ?? $p->estado;
        }
        $faltan = (int) $p->getAttribute('total_articulos') - (int) $p->getAttribute('total_regresados');

        return $faltan > 0 ? "Cerrado con faltantes (faltan {$faltan})" : 'Cerrado con faltantes · lo que faltaba se recibió después';
    }

    // --------------------------------------------------------------- Transporte

    private function transporte(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(MovimientoTransporte::query()->where('movimientos_transporte.anulado', false)->where('movimientos_transporte.created_at', '>=', $this->hace(30)),
            $actor, $permiso, $sedes, 'movimientos_transporte.sede_id', 'movimientos_transporte.creado_por');
        $c = Indicadores::conteos($q, ['total' => '1 = 1', 'a_tiempo' => "movimientos_transporte.estatus = 'a_tiempo'", 'retraso' => "movimientos_transporte.estatus = 'retraso'", 'taxis' => "movimientos_transporte.estatus = 'no_llego'"]);
        $gasto = (float) (clone $q)->toBase()->sum('movimientos_transporte.monto');
        $puntualidad = $c['total'] > 0 ? (int) round($c['a_tiempo'] / $c['total'] * 100) : null;
        $rutas = $this->limitar(Ruta::query()->where('rutas.activo', true), $actor, $permiso, $sedes, 'rutas.sede_id')->count();
        $filas = (clone $q)->with('ruta:id,nombre')->orderByDesc('movimientos_transporte.created_at')->limit(self::MAX_FILAS)
            ->get(['movimientos_transporte.id', 'ruta_id', 'tipo_movimiento', 'estatus', 'cantidad_pax', 'monto', 'movimientos_transporte.created_at'])
            ->map(fn (MovimientoTransporte $m) => [$this->hora->formatear($m->created_at, 'd/m/Y H:i'), $m->ruta?->nombre ?? '—', $m->etiquetaEstatus(), (int) $m->cantidad_pax, (float) $m->monto > 0 ? GraficaSvg::numero((float) $m->monto, '$') : '—'])->all();

        return [
            $this->ficha('Movimientos', number_format($c['total']), [
                'grafica' => GraficaSvg::barras(['A tiempo' => $c['a_tiempo'], 'Retraso' => $c['retraso'], 'Uso de taxis' => $c['taxis']], 'Movimientos por estatus'),
                'columnas' => ['Fecha', 'Ruta', 'Estatus', 'PAX', 'Monto taxi'], 'filas' => $filas, 'enlace' => route('transporte.reportes'),
            ]),
            $this->ficha('Puntualidad', $puntualidad === null ? '—' : $puntualidad.' %', ['nivel' => $puntualidad !== null && $puntualidad >= 90 ? 'positiva' : 'alerta']),
            $this->ficha('Gasto en taxis', GraficaSvg::numero($gasto, '$'), ['nota' => number_format($c['taxis']).' incidencias', 'nivel' => 'alerta']),
            $this->ficha('Rutas activas', number_format($rutas)),
        ];
    }

    // ---------------------------------------------------- Candidatos y vacantes

    private function candidatos(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(Candidato::query()->whereNull('candidatos.archivada_en'), $actor, $permiso, $sedes, 'candidatos.sede_id', 'candidatos.creado_por');
        $porEtapa = Indicadores::por((clone $q)->whereIn('candidatos.etapa', Candidato::ABIERTAS), 'candidatos.etapa', Candidato::ETAPAS);
        $contratados = (clone $q)->where('candidatos.contratado_en', '>=', $this->hace(90))->count();

        // Sin nombres ni datos personales: solo cifras por etapa
        return [
            $this->ficha('Candidatos en proceso', number_format(array_sum($porEtapa)), [
                'grafica' => GraficaSvg::barras($porEtapa, 'Candidatos en proceso por etapa'),
                'columnas' => ['Etapa', 'Candidatos'], 'filas' => array_map(fn ($k, $v) => [$k, $v], array_keys($porEtapa), $porEtapa), 'enlace' => route('candidatos.index'),
            ]),
            $this->ficha('Contratados en 90 días', number_format($contratados), ['nivel' => 'positiva']),
        ];
    }

    private function vacantes(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->vacantes->limitar(Vacante::query(), $actor, $permiso);
        $permitidas = $this->alcance->sedes($actor, self::PERMISO, $permiso, $sedes);
        if ($permitidas !== null) {
            $q->aplicanEn($permitidas);
        }
        $publicadas = (clone $q)->where('vacantes.estado', 'publicada')->get(['vacantes.id', 'titulo', 'plazas', 'publicada_en']);
        // Tiempo para cubrir: vacantes cerradas como «cubierta» en los últimos 180 días (son pocas: se promedia aquí)
        $cubiertas = (clone $q)->where('vacantes.estado', 'cerrada')->where('vacantes.cierre_motivo', 'cubierta')
            ->where('vacantes.cerrada_en', '>=', $this->hace(180))->whereNotNull('vacantes.publicada_en')->limit(500)->get(['publicada_en', 'cerrada_en']);
        $dias = $cubiertas->map(fn ($v) => CarbonImmutable::parse($v->publicada_en)->diffInDays(CarbonImmutable::parse($v->cerrada_en), true));
        $enProceso = $actor->can('candidatos.ver') ? Candidato::query()->whereIn('candidatos.vacante_id', $publicadas->pluck('id'))->whereIn('candidatos.etapa', Candidato::ABIERTAS)
            ->whereNull('candidatos.archivada_en')->toBase()->selectRaw('vacante_id, COUNT(*) as total')->groupBy('vacante_id')->pluck('total', 'vacante_id') : collect();
        $hoy = CarbonImmutable::now();
        $filas = $publicadas->sortBy('titulo')->take(self::MAX_FILAS)->map(fn (Vacante $v) => [$v->titulo, (int) $v->plazas,
            $actor->can('candidatos.ver') ? (int) ($enProceso[$v->id] ?? 0) : '—', $v->publicada_en ? (int) floor(CarbonImmutable::parse($v->publicada_en)->diffInDays($hoy, true)) : '—'])->values()->all();

        return [
            $this->ficha('Vacantes publicadas', number_format($publicadas->count()), [
                'columnas' => ['Vacante', 'Plazas', 'Candidatos en proceso', 'Días publicada'], 'filas' => $filas, 'enlace' => route('vacantes.index'),
            ]),
            $this->ficha('Tiempo para cubrir', $dias->isEmpty() ? '—' : number_format($dias->avg(), 0).' días', ['nota' => 'Promedio de '.number_format($dias->count()).' vacantes cubiertas en 180 días']),
        ];
    }

    // ------------------------------------------------------------ Autorizaciones

    private function autorizaciones(User $actor, ?array $sedes, string $permiso): array
    {
        $q = $this->limitar(Autorizacion::query()->where('autorizaciones.solicitada_en', '>=', $this->hace(30)), $actor, $permiso, $sedes, 'autorizaciones.sede_id');
        $porEstado = Indicadores::por($q, 'autorizaciones.estado', Autorizacion::ESTADOS);
        $c = Indicadores::conteos($q, ['escaladas' => 'autorizaciones.escalado_en IS NOT NULL', 'pendientes' => "autorizaciones.estado = 'pendiente'"]);
        $minutos = [];
        foreach ((clone $q)->whereNotNull('autorizaciones.respondida_en')->toBase()->select(['autorizaciones.solicitada_en', 'autorizaciones.respondida_en'])->limit(5000)->cursor() as $f) {
            $minutos[] = CarbonImmutable::parse($f->solicitada_en, 'UTC')->diffInMinutes(CarbonImmutable::parse($f->respondida_en, 'UTC'), true);
        }
        $promedio = $minutos === [] ? null : array_sum($minutos) / count($minutos);
        $filas = (clone $q)->with('departamento:id,nombre')->orderByDesc('autorizaciones.solicitada_en')->limit(self::MAX_FILAS)
            ->get(['autorizaciones.id', 'tipo', 'departamento_id', 'estado', 'solicitada_en', 'respondida_en', 'escalado_en'])
            ->map(fn (Autorizacion $a) => [$this->hora->formatear($a->solicitada_en, 'd/m/Y H:i'), Autorizacion::TIPOS[$a->tipo] ?? $a->tipo, $a->departamento?->nombre ?? '—',
                Autorizacion::ESTADOS[$a->estado] ?? $a->estado, $a->respondida_en ? (int) round($a->solicitada_en->diffInMinutes($a->respondida_en, true)).' min' : '—', $a->escalado_en ? 'Sí' : 'No'])->all();

        return [
            $this->ficha('Solicitudes', number_format(array_sum($porEstado)), [
                'grafica' => GraficaSvg::barras($porEstado, 'Autorizaciones por estado'),
                'columnas' => ['Solicitada', 'Tipo', 'Departamento', 'Estado', 'Respuesta', 'Escalada'], 'filas' => $filas, 'enlace' => route('autorizaciones.index'),
            ]),
            $this->ficha('Tiempo de respuesta', $promedio === null ? '—' : number_format($promedio, 0).' min', ['nota' => 'Promedio de las respondidas']),
            $this->ficha('Escaladas', number_format($c['escaladas']), ['nota' => 'Pasaron al suplente o al jefe', 'nivel' => $c['escaladas'] > 0 ? 'alerta' : 'positiva']),
            $this->ficha('Esperando respuesta', number_format($c['pendientes'])),
        ];
    }
}
