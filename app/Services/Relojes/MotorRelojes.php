<?php

namespace App\Services\Relojes;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\RelojEspera;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\CadenaDelegaciones;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Novedades\TurnosNovedades;
use App\Services\PasesSalida\EsperaPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\SolicitudesArco;
use App\Services\Reservas\EsperaReservas;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Support\RelojVoBo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Motor «Reloj y suplente» (C-22; reglas 1 y 3 de docs/criterios-ux.md).
 *
 * Un solo lugar decide, para todo lo que espera respuesta:
 *  - cuánto lleva y su color: normal, ámbar desde las horas de recordatorio
 *    y rojo desde las de suplente (de fábrica 24 h y 72 h, DD-10);
 *  - el recordatorio a quien debe responder (una vez; L&F, cada semana);
 *  - el paso al suplente (una sola vez), con aviso que no se puede silenciar.
 *
 * Corre «en proceso» (sin cron nuevo ni exec, hosting sin terminal): al
 * consultar la campana (cada 30 s, con candado de un minuto por empresa) y
 * con la tarea plataforma:relojes --si-toca del despliegue. También encadena
 * los relojes que ya tenían su motor propio (autorizaciones de la caseta,
 * firmas de pases, reservas, pendientes de turno y plazos de candidatos),
 * para que todo arranque desde el mismo punto.
 *
 * La memoria de lo avisado vive en relojes_esperas (una fila por asunto):
 * cada nivel se marca con una actualización condicionada (solo si seguía
 * vacío), así que dos pestañas o el cron y la pantalla nunca lo repiten.
 */
#[Scoped]
class MotorRelojes
{
    /** @var list<class-string<Reloj>> */
    public const RELOJES = [
        // C-22: lo que aplica de inmediato
        Relojes\AltasPorVerificar::class,
        Relojes\ProcedimientosPorAprobar::class,
        Relojes\ProcedimientosPorLeer::class,
        Relojes\TicketsCanalizados::class,
        Relojes\TicketsDeDepartamento::class, // Etapa 5 (C-28)
        Relojes\CuentasPorRevisar::class,
        // C-26: Super Administrador
        Relojes\SolicitudesDeCompra::class,
        Relojes\PruebasPorAprobar::class,
        Relojes\ClientesPorRevisar::class,
        Relojes\SuscripcionEnGracia::class,
        Relojes\SuscripcionDelCliente::class,
        // C-25: caseta
        Relojes\PedidoAlSupervisor::class,
        Relojes\ExcepcionesPorRevisar::class,
        Relojes\ArticulosPorRevisar::class, // Etapa 6 (C-19): el proveedor salió con algo distinto
        Relojes\Permanencia::class,
        Relojes\SalidaTemporal::class,
        // C-23: Recursos Humanos
        Relojes\VacantesPropuestas::class,
        Relojes\VacantesCubiertas::class,
        Relojes\SolicitudesSinRevisar::class,
        Relojes\CandidatosSinAtender::class,
        Relojes\EntrevistasPorEvaluar::class,
        Relojes\ElegidosPorContratar::class,
        // C-24: Operación
        Relojes\ValesDeTaxi::class,
        Relojes\PasesVencidos::class,
        Relojes\LostFoundVencidos::class,
        Relojes\ActivosQueNoRegresaron::class,
        Relojes\EquiposEnMantenimiento::class,
        Relojes\VouchersPendientes::class,
        Relojes\FaltantesPorRecibir::class,
        Relojes\HallazgosDeRecorrido::class,
        // DD-17: operación programada (rutas, órdenes de servicio, recargas y frecuencia de revisión)
        Relojes\RutasSinRegistro::class,
        Relojes\OrdenesDeServicio::class,
        Relojes\RecargasDeEquiposPc::class,
        Relojes\RevisionesDeEquiposPc::class,
    ];

    public const TIPO_RECORDATORIO = 'reloj_recordatorio';

    public const TIPO_ESCALADO = 'reloj_escalado';

    public const SEGUNDOS_CANDADO = 60;

    /** @var array<string, Reloj>|null */
    private ?array $relojes = null;

    public function __construct(
        private readonly AjustesRelojes $ajustes,
        private readonly CentroNotificaciones $centro,
        private readonly Tenant $tenant,
    ) {}

    /** @return array<string, Reloj> */
    public function relojes(): array
    {
        if ($this->relojes === null) {
            $this->relojes = [];
            foreach (self::RELOJES as $clase) {
                $r = app($clase);
                $this->relojes[$r->clave()] = $r;
            }
        }

        return $this->relojes;
    }

    public function reloj(string $clave): Reloj
    {
        return $this->relojes()[$clave] ?? throw new \InvalidArgumentException("Reloj desconocido: {$clave}");
    }

    /** @return array<string, Reloj> los relojes que aplican a la empresa (por lo contratado) */
    public function relojesDe(?int $empresaId): array
    {
        $autorizador = app(Autorizador::class);

        return array_filter($this->relojes(), fn (Reloj $r) => $empresaId === null
            ? $r->plataforma()
            : ! $r->plataforma() && ($r->modulos() === [] || collect($r->modulos())->contains(fn ($m) => $autorizador->moduloActivo($empresaId, $m))));
    }

    // ----------------------------------------------------------------- Correr

    /**
     * Al consultar la campana (hosting sin cron confiable): a lo mucho una vez
     * por minuto por empresa y sin que un error tire la pantalla. Primero los
     * relojes que ya tenían su propio motor, después los de aquí.
     */
    public function correrSiToca(?int $empresaId): bool
    {
        if (! Cache::add(self::candado($empresaId), 1, self::SEGUNDOS_CANDADO)) {
            return false;
        }
        try {
            if ($empresaId !== null) {
                $this->encadenarPropios($empresaId);
            } else {
                app(SolicitudesArco::class)->revisarPlazos(null, ConservacionDatos::RECORDAR_ARCO);
            }
            $hecho = $this->correrEmpresa($empresaId);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return $hecho['recordatorios'] + $hecho['escalamientos'] > 0;
    }

    /** ¿Corrió hace menos de un minuto en esa empresa (o en la plataforma)? Una lectura de caché, sin consultas. */
    public static function yaCorrio(?int $empresaId): bool
    {
        return Cache::has(self::candado($empresaId));
    }

    private static function candado(?int $empresaId): string
    {
        return 'relojes:'.($empresaId ?? 'plataforma');
    }

    /**
     * Para la tarea programada: todas las empresas activas y la plataforma.
     *
     * @return array{recordatorios: int, escalamientos: int}
     */
    public function correr(): array
    {
        $total = ['recordatorios' => 0, 'escalamientos' => 0];
        foreach ([null, ...Empresa::where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all()] as $empresaId) {
            $hecho = $this->correrEmpresa($empresaId);
            $total['recordatorios'] += $hecho['recordatorios'];
            $total['escalamientos'] += $hecho['escalamientos'];
        }

        return $total;
    }

    /**
     * Recuerda y pasa al suplente lo que ya tardó en una empresa (null = la
     * plataforma). Nunca repite un aviso; lo que ya no espera se limpia.
     *
     * @return array{recordatorios: int, escalamientos: int}
     */
    public function correrEmpresa(?int $empresaId): array
    {
        $empresa = $empresaId === null ? null : Empresa::find($empresaId);
        if ($empresaId !== null && ($empresa === null || ! $empresa->activo)) {
            return ['recordatorios' => 0, 'escalamientos' => 0];
        }

        $arranque = $this->arranque($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($empresa, $empresaId, $arranque) {
            $cuenta = ['recordatorios' => 0, 'escalamientos' => 0];
            foreach ($this->relojesDe($empresaId) as $reloj) {
                $hecho = $this->correrReloj($reloj, $empresa, $empresaId, $arranque);
                $cuenta['recordatorios'] += $hecho[0];
                $cuenta['escalamientos'] += $hecho[1];
            }
            // D-01: a quién pasó cada asunto, para el renglón de Mis pendientes del suplente
            $this->guardarEscalados($empresaId);

            return $cuenta;
        });
    }

    /**
     * Cuándo empezó a correr el motor en la empresa (o en la plataforma). Lo
     * que ya esperaba antes cuenta sus avisos desde aquí, para no mandar de
     * golpe los recordatorios y suplentes de todo lo viejo el día que se
     * instala (la tarjeta sí muestra cuánto lleva de verdad).
     */
    public function arranque(?int $empresaId): CarbonInterface
    {
        $fila = ['reloj' => self::ARRANQUE, 'referencia_tipo' => 'empresa', 'referencia_id' => $empresaId ?? 0];
        RelojEspera::query()->insertOrIgnore([$fila + ['empresa_id' => $empresaId, 'desde' => now(), 'created_at' => now(), 'updated_at' => now()]]);

        return RelojEspera::where($fila)->first()?->desde ?? now();
    }

    /** Fila de relojes_esperas que guarda el arranque del motor. */
    public const ARRANQUE = '_arranque';

    /** @return array{0: int, 1: int} */
    private function correrReloj(Reloj $reloj, ?Empresa $empresa, ?int $empresaId, CarbonInterface $arranque): array
    {
        $ajuste = $this->ajustes->de($empresa, $reloj);
        $reloj->olvidar();
        $esperas = $reloj->esperas($empresaId)->keyBy(fn (Espera $e) => $e->llave());
        $memoria = RelojEspera::where('reloj', $reloj->clave())
            ->where(fn ($q) => $empresaId === null ? $q->whereNull('empresa_id') : $q->where('empresa_id', $empresaId))->get()
            ->keyBy(fn (RelojEspera $m) => $m->referencia_tipo.':'.$m->referencia_id);

        // Lo que ya no espera (se resolvió, se borró o se reasignó por completo): se olvida
        $ya = $memoria->keys()->diff($esperas->keys());
        if ($ya->isNotEmpty()) {
            $resueltas = $memoria->filter(fn ($m, $llave) => $ya->contains($llave));
            RelojEspera::whereIn('id', $resueltas->pluck('id'))->delete();
            // Sus recordatorios y avisos al suplente pierden el botón (el módulo ya resolvió los suyos)
            foreach ($resueltas as $r) {
                Notificacion::query()->withoutGlobalScopes()->whereIn('tipo', [self::TIPO_RECORDATORIO, self::TIPO_ESCALADO])
                    ->where('referencia_tipo', $r->referencia_tipo)->where('referencia_id', $r->referencia_id)
                    ->where(fn ($q) => $empresaId === null ? $q->whereNull('empresa_id') : $q->where('empresa_id', $empresaId))
                    ->whereNull('leida_en')->update(['acciones' => null, 'leida_en' => now(), 'updated_at' => now()]);
            }
        }
        if (! $ajuste['encendido'] || $esperas->isEmpty()) {
            return [0, 0];
        }

        $hechos = [0, 0];
        foreach ($esperas as $llave => $e) {
            [$ambar, $suplente] = $reloj->umbralesDe($e, [$ajuste['ambar'], $ajuste['suplente']]);
            // Lo que ya esperaba cuando arrancó el motor avisa contando desde el arranque
            $desde = $e->desde->lt($arranque) ? $arranque : $e->desde;
            $minutos = (int) max(0, intdiv(now()->getTimestamp() - $desde->getTimestamp(), 60));
            if ($minutos < $ambar) {
                continue;
            }
            $fila = $memoria[$llave] ?? null;
            if ($fila !== null && abs($fila->desde->getTimestamp() - $desde->getTimestamp()) > 60) {
                // Empezó a esperar de nuevo (otra persona, otro turno): el reloj vuelve a cero
                $fila->forceFill(['desde' => $desde, 'recordado_en' => null, 'escalado_en' => null, 'escalado_a' => null])->save();
            }
            if ($fila === null) {
                RelojEspera::query()->insertOrIgnore([[
                    'empresa_id' => $empresaId, 'reloj' => $reloj->clave(), 'referencia_tipo' => $e->referenciaTipo, 'referencia_id' => $e->referenciaId,
                    'desde' => $desde, 'created_at' => now(), 'updated_at' => now(),
                ]]);
                $fila = RelojEspera::where('reloj', $reloj->clave())->where('referencia_tipo', $e->referenciaTipo)->where('referencia_id', $e->referenciaId)->first();
                if ($fila === null) {
                    continue;
                }
            }
            // Los textos dicen cuánto lleva de verdad
            if ($reloj->recuerda() && $this->tomar($fila, 'recordado_en', $reloj->repetirRecordatorioHoras())) {
                $hechos[0] += $this->recordar($reloj, $e, $empresaId, $e->minutos()) ? 1 : 0;
            }
            if ($minutos >= $suplente && $this->tomar($fila, 'escalado_en', null)) {
                $hechos[1] += $this->escalar($reloj, $e, $empresaId, $e->minutos(), $ajuste['rol_id'], $fila) ? 1 : 0;
            }
        }

        return $hechos;
    }

    /**
     * Marca un nivel como avisado solo si nadie más lo hizo (actualización
     * condicionada): así cada aviso sale una sola vez.
     */
    private function tomar(RelojEspera $fila, string $columna, ?int $repetirHoras): bool
    {
        $consulta = RelojEspera::whereKey($fila->id)->where(fn ($q) => $q->whereNull($columna)
            ->when($repetirHoras !== null, fn ($r) => $r->orWhere($columna, '<=', now()->subHours($repetirHoras))));
        $ok = $consulta->update([$columna => now(), 'updated_at' => now()]) > 0;
        if ($ok) {
            $fila->{$columna} = now();
        }

        return $ok;
    }

    private function recordar(Reloj $reloj, Espera $e, ?int $empresaId, int $minutos): bool
    {
        // D-01/D-02: solo a quien puede atenderlo (la misma regla que su renglón de Mis pendientes)
        $usuarios = $this->conDelegacion($reloj, $e, $empresaId,
            $reloj->responsables($e, $empresaId)->filter(fn (User $u) => $reloj->puedeAtender($u, $e)));
        if ($usuarios->isEmpty()) {
            return false;
        }
        [$titulo, $texto] = $reloj->textoRecordatorio($e, $minutos);
        $this->avisar($empresaId, $usuarios, self::TIPO_RECORDATORIO, $titulo, $texto, $e);
        $this->auditar($empresaId, 'relojes.recordatorio', $reloj, $e, $minutos, $usuarios);

        return true;
    }

    private function escalar(Reloj $reloj, Espera $e, ?int $empresaId, int $minutos, ?int $rolId, RelojEspera $fila): bool
    {
        $responsables = $reloj->responsables($e, $empresaId);
        // D-01: solo quien puede atenderlo (si los suplentes del reloj no pueden, los de fábrica que sí)
        $suplentes = $this->conDelegacion($reloj, $e, $empresaId, $reloj->suplentesQuePuedenAtender($e, $empresaId, $responsables, $rolId));
        $fila->forceFill(['escalado_a' => $suplentes->pluck('id')->map(fn ($id) => (int) $id)->values()->all()])->save();
        $this->auditar($empresaId, 'relojes.suplente', $reloj, $e, $minutos, $suplentes);
        if ($suplentes->isEmpty()) {
            return false;
        }
        [$titulo, $texto] = $reloj->textoEscalado($e, $minutos);
        $this->avisar($empresaId, $suplentes, self::TIPO_ESCALADO, $titulo, $texto, $e);
        $reloj->alEscalar($e, $empresaId, $suplentes);

        return true;
    }

    /**
     * C-12 (Etapa 3): quien delegó sus aprobaciones no recibe el aviso; le
     * llega al último de su cadena (A→B→C) que pueda atenderlo
     * (Reloj::puedeAtender). Misma regla que la caseta, los pases, las
     * reservas y Mis pendientes: CadenaDelegaciones::destino(). Si nadie de
     * la cadena puede, se queda con el titular. Los de la plataforma no se delegan.
     *
     * @param  Collection<int, User>  $usuarios
     * @return Collection<int, User>
     */
    private function conDelegacion(Reloj $reloj, Espera $e, ?int $empresaId, Collection $usuarios): Collection
    {
        $usuarios = $usuarios->unique('id')->values();
        if ($empresaId === null || $usuarios->isEmpty()) {
            return $usuarios;
        }
        $cadena = $this->cadena();
        if ($cadena->mapa() === []) {
            return $usuarios;
        }

        return $usuarios->map(function (User $u) use ($cadena, $reloj, $e) {
            $destino = $cadena->destino((int) $u->id, fn (User $d) => $reloj->puedeAtender($d, $e));

            return $destino === (int) $u->id ? $u : $cadena->usuario($destino);
        })->unique('id')->values();
    }

    private ?CadenaDelegaciones $cadena = null;

    /** Una sola cadena de delegaciones para el motor y todos sus relojes (una lectura por petición). */
    public function cadena(): CadenaDelegaciones
    {
        return $this->cadena ??= app(Autorizaciones::class)->cadena();
    }

    /** @var array<int, ?User> */
    private array $titulares = [];

    public const CACHE_ESCALADOS = 'relojes:escalados:';

    /** @var array<int, array<string, array<string, list<int>>>> */
    private array $escalados = [];

    private ?int $peticionEscalados = null;

    /**
     * D-01: a quién pasó cada asunto que sigue en la memoria del motor
     * (relojes_esperas.escalado_a), por reloj y llave. Lo guarda el motor al
     * terminar cada corrida; si no está en caché, una consulta.
     *
     * @return array<string, array<string, list<int>>>
     */
    public function escaladosDe(?int $empresaId): array
    {
        $k = $empresaId ?? 0;
        if ($this->peticionEscalados !== ($peticion = spl_object_id(app('request')))) {
            [$this->peticionEscalados, $this->escalados] = [$peticion, []];
        }
        if (isset($this->escalados[$k])) {
            return $this->escalados[$k];
        }
        $guardado = Cache::get(self::CACHE_ESCALADOS.($empresaId ?? 'plataforma'));

        return $this->escalados[$k] = is_array($guardado) ? $guardado : $this->guardarEscalados($empresaId);
    }

    /** @return array<string, array<string, list<int>>> */
    private function guardarEscalados(?int $empresaId): array
    {
        $mapa = [];
        RelojEspera::query()->whereNotNull('escalado_a')->where('reloj', '!=', self::ARRANQUE)
            ->where(fn ($q) => $empresaId === null ? $q->whereNull('empresa_id') : $q->where('empresa_id', $empresaId))
            ->get(['reloj', 'referencia_tipo', 'referencia_id', 'escalado_a'])
            ->each(function (RelojEspera $r) use (&$mapa) {
                $ids = array_values(array_map('intval', (array) $r->escalado_a));
                if ($ids !== []) {
                    $mapa[$r->reloj][$r->referencia_tipo.':'.$r->referencia_id] = $ids;
                }
            });
        Cache::put(self::CACHE_ESCALADOS.($empresaId ?? 'plataforma'), $mapa, now()->addDay());

        return $this->escalados[$empresaId ?? 0] = $mapa;
    }

    /**
     * D-02: las llaves que le tocan a esta persona con la regla de delegación
     * de la Etapa 3 (CadenaDelegaciones::destino), a partir del conjunto que
     * cada quien puede atender ($llavesDe). Sin delegaciones de por medio,
     * su propio conjunto tal cual (sin consultas extra).
     *
     * @param  callable(User): list<string>  $llavesDe
     * @return list<string>
     */
    public function conCadenaLlaves(User $actor, callable $llavesDe): array
    {
        $cadena = $this->cadena();
        $id = (int) $actor->id;
        $mapa = $cadena->mapa();
        $titulares = $mapa === [] ? [] : $cadena->titularesDe($id);
        $propias = $llavesDe($actor);
        if (! isset($mapa[$id]) && $titulares === []) {
            return $propias;
        }
        $memo = [$id => array_flip($propias)];
        $de = function (User $u) use (&$memo, $llavesDe): array {
            return $memo[(int) $u->id] ??= array_flip($llavesDe($u));
        };
        $resultado = [];
        foreach ($propias as $llave) {
            if ($cadena->destino($id, fn (User $d) => isset($de($d)[$llave])) === $id) {
                $resultado[$llave] = true;
            }
        }
        foreach ($titulares as $t) {
            if (($titular = $this->titular($t)) === null) {
                continue;
            }
            foreach (array_keys($de($titular)) as $llave) {
                if ($cadena->destino($t, fn (User $d) => isset($de($d)[$llave])) === $id) {
                    $resultado[$llave] = true;
                }
            }
        }

        return array_keys($resultado);
    }

    /** Un titular que delega (activo), leído una sola vez por petición. */
    public function titular(int $id): ?User
    {
        return array_key_exists($id, $this->titulares) ? $this->titulares[$id] : ($this->titulares[$id] = User::where('activo', true)->find($id));
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function avisar(?int $empresaId, Collection $usuarios, string $tipo, string $titulo, string $texto, Espera $e): void
    {
        $datos = ['titulo' => $titulo, 'texto' => $texto, 'url' => $e->url, 'referencia_tipo' => $e->referenciaTipo, 'referencia_id' => $e->referenciaId,
            'acciones' => [['etiqueta' => 'Atender', 'abrir' => true]]];
        if ($empresaId === null) {
            $this->centro->avisarPlataforma($usuarios->pluck('id'), $tipo, $datos);

            return;
        }
        $this->centro->avisar($empresaId, $usuarios->pluck('id'), $tipo, $datos);
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function auditar(?int $empresaId, string $evento, Reloj $reloj, Espera $e, int $minutos, Collection $usuarios): void
    {
        Auditoria::create([
            'empresa_id' => $empresaId, 'sede_id' => $e->sedeId, 'user_id' => null, 'evento' => $evento,
            'auditable_type' => RelojEspera::class, 'auditable_id' => $e->referenciaId,
            'antes' => ['reloj' => $reloj->clave(), 'referencia' => $e->llave()],
            'despues' => ['minutos' => $minutos, 'avisados' => $usuarios->pluck('id')->map(fn ($id) => (int) $id)->all()] + ($usuarios->isEmpty() ? ['sin_a_quien' => true] : []),
            'ip' => null,
        ]);
    }

    /**
     * Los relojes que ya tenían su motor (se conservan tal cual): se corren
     * desde aquí para que la campana los dispare a todos.
     */
    private function encadenarPropios(int $empresaId): void
    {
        $propios = [
            fn () => app(Escalamiento::class)->escalarSiToca($empresaId),
            fn () => app(EsperaReservas::class)->escalarSiToca($empresaId),
            fn () => app(TurnosNovedades::class)->revisarSiToca($empresaId),
            fn () => app(PlazosCandidatos::class)->correrSiToca($empresaId),
            fn () => app(EsperaPasesSalida::class)->escalarEmpresa($empresaId),
            fn () => app(AdministradorPrestamosLlaves::class)->recordatorios($empresaId),
            fn () => app(AdministradorResponsivas::class)->recordatoriosTurno($empresaId),
            // ARCO: el plazo legal (recordatorio y aviso al Super Administrador al vencer), una vez cada uno
            function () use ($empresaId) {
                $empresa = $this->empresa($empresaId);
                if ($empresa !== null) {
                    app(SolicitudesArco::class)->revisarPlazos($empresa, app(ConservacionDatos::class)->ajustes($empresa)['arco_recordar_dias']);
                }
            },
        ];
        foreach ($propios as $correr) {
            try {
                $correr();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    // ------------------------------------------------------- Reloj y color

    /**
     * Color de lo que espera desde $desde: null (a tiempo), 'ambar' o 'roja'.
     */
    public function nivel(string $clave, ?CarbonInterface $desde, ?int $empresaId, ?Espera $espera = null): ?string
    {
        if ($desde === null) {
            return null;
        }
        $reloj = $this->reloj($clave);
        $ajuste = $this->ajusteMemorizado($empresaId, $reloj);
        [$ambar, $suplente] = $espera !== null ? $reloj->umbralesDe($espera, [$ajuste['ambar'], $ajuste['suplente']]) : [$ajuste['ambar'], $ajuste['suplente']];
        $minutos = (int) max(0, intdiv(now()->getTimestamp() - $desde->getTimestamp(), 60));

        return match (true) {
            $minutos >= $suplente => 'roja',
            $minutos >= $ambar => 'ambar',
            default => null,
        };
    }

    /**
     * Reloj para una tarjeta: «lleva 2 días» con su color.
     *
     * @return array{minutos: int, texto: string, nivel: ?string}|null
     */
    public function relojDe(string $clave, ?CarbonInterface $desde, ?int $empresaId, ?Espera $espera = null): ?array
    {
        if ($desde === null) {
            return null;
        }
        $minutos = (int) max(0, intdiv(now()->getTimestamp() - $desde->getTimestamp(), 60));

        return ['minutos' => $minutos, 'texto' => 'Lleva '.self::duracion($minutos), 'nivel' => $this->nivel($clave, $desde, $empresaId, $espera)];
    }

    /** @var array<string, array{encendido: bool, ambar: int, suplente: int, rol_id: ?int, propio: bool}> */
    private array $memoria = [];

    /** @return array{encendido: bool, ambar: int, suplente: int, rol_id: ?int, propio: bool} */
    public function ajusteMemorizado(?int $empresaId, Reloj $reloj): array
    {
        return $this->memoria[($empresaId ?? 0).':'.$reloj->clave()] ??= $this->ajustes->de($this->empresa($empresaId), $reloj);
    }

    /** @var array<int, ?Empresa> */
    private array $empresas = [];

    /** La empresa, leída una sola vez por petición (para umbrales y ajustes). */
    public function empresa(?int $empresaId): ?Empresa
    {
        if ($empresaId === null) {
            return null;
        }

        return array_key_exists($empresaId, $this->empresas) ? $this->empresas[$empresaId] : ($this->empresas[$empresaId] = Empresa::find($empresaId));
    }

    /** La empresa que ya trae la petición (sin volver a leerla). */
    public function recordarEmpresa(Empresa $empresa): void
    {
        $this->empresas[(int) $empresa->id] ??= $empresa;
    }

    /** «25 min», «5 h», «2 días 3 h». */
    public static function duracion(int $minutos): string
    {
        return RelojVoBo::duracion($minutos);
    }

    /** ¿Ya pasó al suplente este asunto? (para que la caseta sepa si puede aprobar por excepción). */
    public function escalado(string $clave, string $referenciaTipo, int $referenciaId, ?int $empresaId): bool
    {
        return DB::table('relojes_esperas')->where(fn ($q) => $empresaId === null ? $q->whereNull('empresa_id') : $q->where('empresa_id', $empresaId))
            ->where('reloj', $clave)->where('referencia_tipo', $referenciaTipo)
            ->where('referencia_id', $referenciaId)->whereNotNull('escalado_en')->exists();
    }
}
