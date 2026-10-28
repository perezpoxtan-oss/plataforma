<?php

namespace App\Services\Relojes;

use App\Models\Colaborador;
use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Services\Activos\PendientesActivos;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Container\Attributes\Scoped;

/**
 * D-02 (revisión de la Etapa 4): lo que cada persona puede atender en los
 * renglones que ya existían antes del motor (Altas por verificar, Cuentas
 * por revisar y Procedimientos por aprobar), como llaves de asunto
 * («alta_personas:12», «solicitud_cuenta:3», «procedimiento_revision:8»).
 *
 * Es la ÚNICA regla para tres cosas: el número de su renglón en Mis
 * pendientes, a quién le llega el recordatorio y el paso al suplente del
 * motor (Reloj::leToca/puedeAtender) y la delegación en cadena de la Etapa 3
 * (MotorRelojes::conCadenaLlaves). Usa los mismos servicios que las pantallas
 * (alcance, solo propios, jerarquía), así que el número coincide con lo que
 * la persona ve y puede atender. Memorizado por persona en la petición.
 */
#[Scoped]
class PendientesPorPersona
{
    /** @var array<string, mixed> */
    private array $memo = [];

    private ?int $peticion = null;

    /** Lo memorizado vale para una petición (en las pruebas la aplicación vive más que una petición). */
    private function vigente(): void
    {
        if ($this->peticion !== ($id = spl_object_id(app('request')))) {
            [$this->peticion, $this->memo] = [$id, []];
        }
    }

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * @return array{grupos: list<array{padron: string, nombre: string, icono: string, llaves: list<string>}>, llaves: list<string>}
     */
    public function altas(User $u): array
    {
        $this->vigente();

        return $this->memo['altas:'.$this->empresa().':'.$u->id] ??= $this->calcularAltas($u);
    }

    /**
     * @return array{mostrar: bool, total: int, llaves: list<string>}
     */
    public function cuentas(User $u, int $empresaId): array
    {
        $this->vigente();

        return $this->memo['cuentas:'.$empresaId.':'.$u->id] ??= $this->calcularCuentas($u, $empresaId);
    }

    /** @return list<string> */
    public function procedimientosPorAprobar(User $u): array
    {
        $this->vigente();

        return $this->memo['procedimientos:'.$this->empresa().':'.$u->id] ??= array_map(fn (int $id) => 'procedimiento_revision:'.$id,
            app(PendientesActivos::class)->versionesPorAprobar($u));
    }

    private function empresa(): string
    {
        return (string) ($this->tenant->empresaId() ?? 'x');
    }

    /** @return array{grupos: list<array{padron: string, nombre: string, icono: string, llaves: list<string>}>, llaves: list<string>} */
    private function calcularAltas(User $u): array
    {
        $altas = app(AltasPorVerificar::class);
        $grupos = [];
        foreach (AltasPorVerificar::PADRONES as $padron => $d) {
            if (! $altas->puedeVerificar($u, $padron)) {
                continue;
            }
            $tabla = (new $d['modelo'])->getTable();
            $ids = $altas->limitarVerificacion($d['modelo']::query()->pendientesDeVerificar(), $u, $padron)->pluck($tabla.'.id')->all();
            if ($ids !== []) {
                $grupos[] = ['padron' => $padron, 'nombre' => $d['nombre'], 'icono' => $d['icono'],
                    'llaves' => array_map(fn ($id) => 'alta_'.$padron.':'.(int) $id, $ids)];
            }
        }
        if ($u->can('colaboradores.aprobar')) {
            $ids = app(AdministradorColaboradores::class)->limitar(Colaborador::query(), $u, 'colaboradores.aprobar')
                ->where('colaboradores.provisional', true)->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')
                ->pluck('colaboradores.id')->all();
            if ($ids !== []) {
                $grupos[] = ['padron' => 'colaboradores', 'nombre' => 'Colaboradores', 'icono' => 'bi-person-exclamation',
                    'llaves' => array_map(fn ($id) => 'alta_colaboradores:'.(int) $id, $ids)];
            }
        }

        return ['grupos' => $grupos, 'llaves' => array_merge([], ...array_column($grupos, 'llaves'))];
    }

    /**
     * Cuentas por revisar (usuarios.editar, con su alcance) más las peticiones
     * de «Pedir a mi administrador» que le tocan. Una sola consulta si no hay
     * peticiones abiertas (como antes).
     *
     * @return array{mostrar: bool, total: int, llaves: list<string>}
     */
    private function calcularCuentas(User $u, int $empresaId): array
    {
        $editar = $u->can('usuarios.editar');
        if (! $editar && ! $u->can('usuarios.desbloquear')) {
            return ['mostrar' => false, 'total' => 0, 'llaves' => []];
        }
        $abiertas = SolicitudCuenta::query()->withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)
            ->where('estado', SolicitudCuenta::PENDIENTE)->selectRaw('count(*)');
        $llaves = [];
        $cuentas = 0;
        if ($editar) {
            $fila = app(AdministradorUsuarios::class)->consultaPorRevisar($u, $empresaId)->toBase()
                ->selectRaw('count(*) as total')->selectRaw('group_concat(users.id) as ids')
                ->selectRaw('group_concat(case when users.activo = 0 then users.id end) as inactivos')
                ->selectSub($abiertas->toBase(), 'abiertas')->first();
            $inactivos = array_flip(array_filter(explode(',', (string) ($fila->inactivos ?? ''))));
            foreach (array_filter(explode(',', (string) ($fila->ids ?? ''))) as $id) {
                $llaves[] = (isset($inactivos[$id]) ? 'cuenta_desactivada:' : 'cuenta_revisar:').(int) $id;
            }
            $cuentas = (int) ($fila->total ?? 0);
            $hay = (int) ($fila->abiertas ?? 0) > 0;
        } else {
            $hay = $abiertas->toBase()->exists();
        }
        $peticiones = $hay ? app(SolicitudesCuenta::class)->porAtender($u, $empresaId) : collect();
        foreach ($peticiones as $s) {
            $llaves[] = SolicitudesCuenta::REFERENCIA.':'.(int) $s->id;
        }

        // El número sale del conteo (group_concat puede recortarse con muchas cuentas); las llaves, para color y delegación
        return ['mostrar' => $editar || $peticiones->isNotEmpty(), 'total' => $cuentas + $peticiones->count(), 'llaves' => array_values(array_unique($llaves))];
    }
}
