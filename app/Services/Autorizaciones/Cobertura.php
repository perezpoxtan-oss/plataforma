<?php

namespace App\Services\Autorizaciones;

use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\Destinatarios;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;

/**
 * C-08 · Cobertura real de responsables (A-03, A-04).
 *
 * Un departamento está CUBIERTO en una sede si al menos uno de sus
 * responsables (titular o suplente) cumple las cuatro condiciones:
 *  1. tiene cuenta y está activa;
 *  2. su rol le da el permiso «Responder» de Autorizaciones
 *     (autorizaciones.responder);
 *  3. ese permiso alcanza la sede (puede entrar a esa sede);
 *  4. la fila es de esa sede o de «todas sus sedes».
 *
 * Es la única regla: la usan la caseta (¿espera al responsable o al
 * supervisor?), los avisos y la bandeja de Autorizaciones, la tarjeta de
 * Departamentos, la pantalla Responsables, Inicio y Mis pendientes,
 * Configuración, Configura tu empresa, Pases de salida (paso «jefe del
 * departamento»), Reservas (aprobaciones y órdenes) y Entrevistas.
 *
 * Todo corre con la empresa de trabajo fijada en el Tenant.
 */
class Cobertura
{
    /** El permiso que hace falta para responder. */
    public const PERMISO = 'autorizaciones.responder';

    /** Por qué una fila de responsable no cubre (texto para la pantalla Responsables). */
    public const PROBLEMAS = [
        'baja' => 'cuenta dada de baja',
        'sin_permiso' => 'sin permiso para responder',
        'fuera' => 'no puede entrar a esa sede',
    ];

    /** @var array<string, mixed> cálculos de esta petición (por empresa) */
    private array $memoria = [];

    public function __construct(
        private readonly Destinatarios $destinatarios,
        private readonly Autorizador $autorizador,
        private readonly Tenant $tenant,
    ) {}

    /** Olvida lo calculado (tras guardar responsables, dar de baja una cuenta o cambiar un rol). */
    public function olvidar(): void
    {
        $this->memoria = [];
        $this->autorizador->olvidar();
    }

    // ------------------------------------------------------------- Personas

    /** ¿Esta cuenta puede responder autorizaciones en esa sede? (null = en alguna sede) */
    public function puedeResponderEn(?User $usuario, ?int $sedeId): bool
    {
        if ($usuario === null || ! ($usuario->getAttributes()['activo'] ?? true)) {
            return false;
        }
        if ($usuario->getAttributes()['es_superadmin'] ?? false) {
            return true;
        }
        if ($sedeId === null) {
            return ($this->autorizador->permisosEfectivos($usuario)[self::PERMISO] ?? null) !== null;
        }

        return $this->destinatarios->alcanza($usuario, self::PERMISO, $sedeId);
    }

    /** ¿Esta fila de responsable cubre esa sede? */
    public function cubre(DepartamentoResponsable $fila, ?int $sedeId): bool
    {
        if ($sedeId !== null && $fila->sede_id !== null && (int) $fila->sede_id !== $sedeId) {
            return false;
        }

        return $this->puedeResponderEn($fila->usuario, $sedeId ?? ($fila->sede_id === null ? null : (int) $fila->sede_id));
    }

    /**
     * Sedes (de la lista) a las que la cuenta puede entrar para responder.
     * null = todas (alcance de empresa).
     *
     * @return list<int>|null
     */
    public function sedesDe(User $usuario): ?array
    {
        if ($usuario->getAttributes()['es_superadmin'] ?? false) {
            return null;
        }

        return $this->autorizador->sedesPermitidas($usuario, self::PERMISO);
    }

    /**
     * Qué le falta a una fila de responsable para cubrir lo que dice cubrir:
     * null si cubre todo; si no, [clave, texto] («cuenta dada de baja», «sin
     * permiso para responder», «no puede entrar a Playa», «solo responde en Centro»).
     *
     * @param  array<int, string>  $sedesDepto  id => nombre de las sedes activas del departamento
     * @return array{0: string, 1: string}|null
     */
    public function problema(DepartamentoResponsable $fila, array $sedesDepto): ?array
    {
        $u = $fila->usuario;
        if ($u === null || ! $u->activo) {
            return ['baja', self::PROBLEMAS['baja']];
        }
        if (! $this->puedeResponderEn($u, null)) {
            return ['sin_permiso', self::PROBLEMAS['sin_permiso']];
        }
        if ($fila->sede_id !== null) {
            $sede = (int) $fila->sede_id;

            return $this->puedeResponderEn($u, $sede) ? null : ['fuera', 'no puede entrar a '.($sedesDepto[$sede] ?? $fila->sede?->nombre ?? 'esa sede')];
        }
        $alcanza = array_filter($sedesDepto, fn ($nombre, $id) => $this->puedeResponderEn($u, (int) $id), ARRAY_FILTER_USE_BOTH);
        if (count($alcanza) === count($sedesDepto)) {
            return null;
        }

        return $alcanza === []
            ? ['fuera', 'no puede entrar a '.implode(', ', $sedesDepto)]
            : ['fuera', 'solo responde en '.implode(', ', $alcanza)];
    }

    // ------------------------------------------------- Avisos (reglas 1 y 3)

    /**
     * Después de una baja de cuenta (directa o en cascada desde
     * Colaboradores) o de un cambio de rol o de sedes: si un departamento
     * quedó sin quien autorice en una sede donde sí tenía a alguien nombrado,
     * avisa por campana a quien nombra responsables («Nombrar responsable»).
     * Un aviso por departamento mientras siga sin resolver; al recuperar la
     * cobertura, el aviso pierde su botón. Mis pendientes lo cuenta siempre.
     */
    public function revisar(int $empresaId): void
    {
        $this->tenant->conEmpresa($empresaId, function () use ($empresaId) {
            $this->olvidar();
            $perdidas = collect($this->sinCobertura(soloPerdidas: true))->groupBy('departamento_id');
            // Lo que ya se recuperó deja de pedir acción
            $abiertas = Notificacion::where('referencia_tipo', self::REFERENCIA)->whereNotNull('acciones')->pluck('referencia_id')->map(fn ($id) => (int) $id)->unique();
            foreach ($abiertas->diff($perdidas->keys()->map(fn ($id) => (int) $id)) as $depto) {
                app(CentroNotificaciones::class)->resolver(self::REFERENCIA, $depto);
            }
            $nuevas = $perdidas->reject(fn ($f, $depto) => $abiertas->contains((int) $depto));
            if ($nuevas->isEmpty()) {
                return;
            }
            $quienes = $this->quienesNombran($empresaId);
            foreach ($nuevas as $depto => $faltantes) {
                $nombre = $faltantes->first()['departamento'];
                $sedes = $faltantes->pluck('sede')->join(', ', ' y ');
                app(CentroNotificaciones::class)->avisar($empresaId, $quienes, 'cobertura_perdida', [
                    'titulo' => "Sin quien autorice en {$nombre} ({$sedes})",
                    'texto' => 'Su responsable ya no puede responder (cuenta dada de baja, sin permiso o sin acceso a esa sede). Mientras no nombres a alguien, sus visitas esperan al supervisor o al jefe de seguridad de la sede.',
                    'url' => route('autorizaciones.responsables').'#depto-'.$depto,
                    'referencia_tipo' => self::REFERENCIA, 'referencia_id' => (int) $depto,
                    'acciones' => [['etiqueta' => 'Nombrar responsable', 'abrir' => true]],
                ]);
            }
        });
    }

    /** revisar() desde otro módulo: un fallo aquí nunca detiene la baja o el cambio de rol. */
    public static function revisarDespues(?int $empresaId): void
    {
        if ($empresaId === null) {
            return;
        }
        try {
            app(self::class)->revisar($empresaId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Referencia de los avisos «Sin quien autorice» (departamento). */
    public const REFERENCIA = 'cobertura';

    /**
     * Quien puede nombrar responsables: «Configurar» de Autorizaciones con alcance de empresa.
     *
     * @return list<int>
     */
    public function quienesNombran(int $empresaId): array
    {
        return $this->destinatarios->conPermisoEnAlgunaSede($empresaId, 'autorizaciones.configurar')
            ->filter(fn (User $u) => $this->autorizador->alcanceDeEmpresa($u, 'autorizaciones.configurar'))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    // ------------------------------------------------------- Departamentos

    /**
     * Responsables que cubren el departamento en esa sede (titular primero).
     *
     * @return Collection<int, DepartamentoResponsable>
     */
    public function responsables(int $departamentoId, ?int $sedeId): Collection
    {
        return $this->filas()->where('departamento_id', $departamentoId)
            ->filter(fn (DepartamentoResponsable $r) => $this->cubre($r, $sedeId))->values();
    }

    public function cubierto(int $departamentoId, int $sedeId): bool
    {
        return $this->responsables($departamentoId, $sedeId)->isNotEmpty();
    }

    /**
     * Sedes cubiertas de cada departamento activo (para la caseta: el botón
     * dice antes de guardar si la visita esperará al responsable).
     *
     * @return array<int, list<int>|null> departamento_id => sedes cubiertas (null = todas)
     */
    public function sedesCubiertas(): array
    {
        return $this->memoria[$this->llave('cubiertas')] ??= (function () {
            // Sin cargar departamentos ni sedes (la caseta solo ofrece los que aplican en su sede): null = en todas sus sedes
            $r = [];
            foreach ($this->filas() as $fila) {
                $d = (int) $fila->departamento_id;
                if (array_key_exists($d, $r) && $r[$d] === null) {
                    continue;
                }
                if ($fila->sede_id !== null) {
                    if ($this->puedeResponderEn($fila->usuario, (int) $fila->sede_id)) {
                        $r[$d] = array_values(array_unique([...($r[$d] ?? []), (int) $fila->sede_id]));
                    }

                    continue;
                }
                if (! $this->puedeResponderEn($fila->usuario, null)) {
                    continue;
                }
                $alcance = $this->sedesDe($fila->usuario);
                $r[$d] = $alcance === null ? null : array_values(array_unique([...($r[$d] ?? []), ...array_map('intval', $alcance)]));
            }

            return $r;
        })();
    }

    /**
     * Departamento × sede SIN quien autorice. «perdida» = alguien estaba
     * nombrado para esa sede, pero ya no puede responder (baja de su cuenta,
     * cambio de rol o de sede): es lo que llega a la campana y a Mis pendientes.
     *
     * @return list<array{departamento_id: int, departamento: string, sede_id: int, sede: string, perdida: bool}>
     */
    public function sinCobertura(bool $soloPerdidas = false): array
    {
        if ($soloPerdidas && $this->filas()->isEmpty()) {
            return []; // nadie nombrado: nada se perdió (sin leer departamentos ni sedes)
        }
        $lista = [];
        foreach ($this->mapa() as $depId => $d) {
            foreach ($d['sedes'] as $sedeId => $s) {
                if (! $s['cubierta'] && (! $soloPerdidas || $s['perdida'])) {
                    $lista[] = ['departamento_id' => $depId, 'departamento' => $d['nombre'], 'sede_id' => $sedeId, 'sede' => $s['nombre'], 'perdida' => $s['perdida']];
                }
            }
        }

        return $lista;
    }

    /**
     * Para la tarjeta de un departamento: nombres de las sedes sin quien autorice.
     *
     * @return array<int, list<string>> departamento_id => sedes
     */
    public function sedesSinCobertura(): array
    {
        $r = [];
        foreach ($this->sinCobertura() as $f) {
            $r[$f['departamento_id']][] = $f['sede'];
        }

        return $r;
    }

    /** «Sin quien autorice en Recepción (Playa) y Ama de llaves (Centro)» — máximo $max departamentos. */
    public static function frase(array $faltantes, int $max = 3): string
    {
        $porDepto = [];
        foreach ($faltantes as $f) {
            $porDepto[$f['departamento']][] = $f['sede'];
        }
        $partes = [];
        foreach (array_slice($porDepto, 0, $max, true) as $depto => $sedes) {
            $partes[] = $depto.' ('.implode(', ', $sedes).')';
        }
        $resto = count($porDepto) - count($partes);

        return implode(', ', $partes).($resto > 0 ? ' y '.$resto.' más' : '');
    }

    /**
     * Departamentos activos con sus sedes activas y si cada una está cubierta.
     *
     * @return array<int, array{nombre: string, sedes: array<int, array{nombre: string, cubierta: bool, perdida: bool}>}>
     */
    public function mapa(): array
    {
        return $this->memoria[$this->llave('mapa')] ??= (function () {
            $sedes = Sede::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all();
            $filas = $this->filas()->groupBy('departamento_id');
            $mapa = [];
            foreach (Departamento::with('sedes:sedes.id')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'todas_las_sedes']) as $d) {
                $suyas = $d->todas_las_sedes ? array_keys($sedes) : $d->sedes->pluck('id')->map(fn ($id) => (int) $id)->filter(fn ($id) => isset($sedes[$id]))->values()->all();
                $lista = $filas->get($d->id, collect());
                $porSede = [];
                foreach ($suyas as $sedeId) {
                    $nombradas = $lista->filter(fn (DepartamentoResponsable $r) => $r->sede_id === null || (int) $r->sede_id === (int) $sedeId);
                    $porSede[(int) $sedeId] = [
                        'nombre' => $sedes[$sedeId],
                        'cubierta' => $nombradas->contains(fn (DepartamentoResponsable $r) => $this->cubre($r, (int) $sedeId)),
                        'perdida' => $nombradas->isNotEmpty(),
                    ];
                }
                $mapa[(int) $d->id] = ['nombre' => $d->nombre, 'sedes' => $porSede];
            }

            return $mapa;
        })();
    }

    /**
     * Revisión Etapa 3a: cobertura por función, para que Departamentos y
     * Responsables no digan «cubierto» mientras firma otra persona.
     *  - visitas: la regla de este servicio (responder autorizaciones);
     *  - pases: quién firma el paso «Jefe del departamento del solicitante»
     *    con la regla de Pases de salida (no cambia quién firma): un
     *    responsable activo de esa sede con «Aprobar» de Pases en la sede;
     *    si no hay, quien tenga «Aprobar» y sea colaborador del departamento;
     *    si tampoco, cualquiera con «Aprobar» en la sede. null si la empresa
     *    no tiene Pases de salida.
     *
     * @return array<int, array<int, array{sede: string, visitas: bool, pases: ?array{si: bool, quien: string}}>> depto => sede => …
     */
    public function porFuncion(): array
    {
        return $this->memoria[$this->llave('funciones')] ??= (function () {
            $empresaId = $this->tenant->empresaId();
            $conPases = $empresaId !== null && $this->autorizador->moduloActivo((int) $empresaId, 'pases_salida');
            $aprueban = [];
            $filas = $this->filas()->groupBy('departamento_id');
            $r = [];
            foreach ($this->mapa() as $depto => $d) {
                foreach ($d['sedes'] as $sede => $s) {
                    $pases = null;
                    if ($conPases) {
                        $aprueban[$sede] ??= User::with('colaborador:id,departamento_id')->where('empresa_id', $empresaId)->where('activo', true)->get()
                            ->filter(fn (User $u) => $this->destinatarios->alcanza($u, 'pases_salida.aprobar', (int) $sede))->values();
                        $responsables = $filas->get($depto, collect())->filter(fn ($f) => ($f->sede_id === null || (int) $f->sede_id === (int) $sede) && $f->usuario?->activo)
                            ->pluck('user_id')->map(fn ($id) => (int) $id);
                        $firman = $aprueban[$sede]->filter(fn (User $u) => $responsables->contains((int) $u->id));
                        if ($firman->isNotEmpty()) {
                            $pases = ['si' => true, 'quien' => $firman->pluck('name')->join(', ', ' y ')];
                        } else {
                            $delDepto = $aprueban[$sede]->filter(fn (User $u) => (int) $u->colaborador?->departamento_id === (int) $depto);
                            $pases = ['si' => false, 'quien' => $delDepto->isNotEmpty() ? $delDepto->pluck('name')->join(', ', ' y ') : 'quien tenga «Aprobar» de Pases en la sede'];
                        }
                    }
                    $r[$depto][$sede] = ['sede' => $s['nombre'], 'visitas' => $s['cubierta'], 'pases' => $pases];
                }
            }

            return $r;
        })();
    }

    /** «Autoriza visitas: Sí · Firma pases: No (lo firma …)» de un departamento en una sede. */
    public static function textoFuncion(array $f): string
    {
        $t = 'Autoriza visitas: '.($f['visitas'] ? 'Sí' : 'No');
        if ($f['pases'] !== null) {
            $t .= ' · Firma pases: '.($f['pases']['si'] ? 'Sí' : 'No (lo firma '.$f['pases']['quien'].')');
        }

        return $t;
    }

    /**
     * Todas las filas de responsables de la empresa con su cuenta (una consulta por petición).
     *
     * @return Collection<int, DepartamentoResponsable>
     */
    public function filas(): Collection
    {
        return $this->memoria[$this->llave('filas')] ??= DepartamentoResponsable::with(['usuario:id,name,email,activo,empresa_id,es_superadmin'])
            ->orderBy('es_suplente')->orderBy('id')->get();
    }

    private function llave(string $que): string
    {
        return $que.':'.($this->tenant->empresaId() ?? 'x');
    }
}
