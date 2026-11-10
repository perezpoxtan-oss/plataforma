<?php

namespace App\Services\Asistencia;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quién ve la asistencia de quién (ADR-0024). Con el permiso de Asistencia:
 *  - Toda la empresa: todo el personal (también el corporativo, sin sede).
 *  - Su sede: el personal cuya sede es una de las suyas.
 *  - Solo los propios: la gente de los departamentos donde es responsable
 *    (titular o suplente, en todas sus sedes o en la que se le asignó) y él
 *    mismo. Así el Jefe de departamento ve solo a su departamento.
 * Solo el personal: en condominios y fraccionamientos, los residentes no
 * tienen asistencia.
 */
class AlcanceAsistencia
{
    public const MODULO = 'asistencia';

    public const VER = 'asistencia.ver';

    public const JUSTIFICAR = 'asistencia.justificar';

    public const EXPORTAR = 'asistencia.exportar';

    public const CONFIGURAR = 'asistencia.configurar';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly EmpresaDeTrabajo $empresa,
    ) {}

    /** ¿La empresa tiene Asistencia (encendida o en solo lectura)? Apagada, nada del módulo se ve. */
    public function encendido(?int $empresaId): bool
    {
        return $empresaId !== null
            && ($this->autorizador->moduloActivo($empresaId, self::MODULO) || $this->autorizador->moduloEnSoloLectura($empresaId, self::MODULO));
    }

    /** ¿Puede abrir Asistencia ahora (módulo encendido en su empresa de trabajo y permiso)? Para los enlaces de otras pantallas. */
    public function disponible(?User $actor): bool
    {
        return $actor !== null && $this->encendido($this->empresa->id($actor)) && $actor->can(self::VER);
    }

    /**
     * Personal que la persona puede ver con ese permiso (en la empresa activa).
     *
     * @return Builder<Colaborador>
     */
    public function consulta(User $actor, string $permiso = self::VER): Builder
    {
        $q = Colaborador::query()->personal();
        if (! $actor->can($permiso)) {
            return $q->whereRaw('1 = 0');
        }
        if ($this->autorizador->soloPropios($actor, $permiso)) {
            $pares = DepartamentoResponsable::where('user_id', $actor->id)->get(['departamento_id', 'sede_id']);
            $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

            return $q->where(function (Builder $w) use ($pares, $actor, $sedes) {
                $w->whereRaw('1 = 0');
                foreach ($pares as $p) {
                    $w->orWhere(function (Builder $d) use ($p, $sedes) {
                        $d->where('colaboradores.departamento_id', $p->departamento_id);
                        if ($p->sede_id !== null) {
                            $d->where('colaboradores.sede_id', $p->sede_id);
                        } elseif ($sedes !== null) {
                            $d->whereIn('colaboradores.sede_id', $sedes);
                        }
                    });
                }
                $propio = $actor->getAttributes()['colaborador_id'] ?? null;
                if ($propio !== null) {
                    $w->orWhere('colaboradores.id', (int) $propio);
                }
            });
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
        if ($sedes !== null || ! $this->autorizador->alcanceDeEmpresa($actor, $permiso)) {
            $q->whereIn('colaboradores.sede_id', $sedes ?? []);
        }

        return $q;
    }

    public function puedeVerColaborador(User $actor, int $colaboradorId, string $permiso = self::VER): bool
    {
        return $this->consulta($actor, $permiso)->whereKey($colaboradorId)->exists();
    }

    /**
     * Sedes y departamentos para los filtros (solo los que tienen gente visible).
     *
     * @return array{sedes: Collection<int, Sede>, departamentos: Collection<int, Departamento>}
     */
    public function filtros(User $actor): array
    {
        $base = $this->consulta($actor)->where('colaboradores.activo', true);
        $sedes = Sede::where('activo', true)->whereIn('id', (clone $base)->whereNotNull('sede_id')->select('sede_id'))
            ->orderBy('nombre')->get(['id', 'nombre', 'zona_horaria']);
        $departamentos = Departamento::whereIn('id', (clone $base)->whereNotNull('departamento_id')->select('departamento_id'))
            ->orderBy('nombre')->get(['id', 'nombre']);

        return ['sedes' => $sedes, 'departamentos' => $departamentos];
    }
}
