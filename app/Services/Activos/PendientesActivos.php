<?php

namespace App\Services\Activos;

use App\Models\MovimientoTransporte;
use App\Models\PrestamoLlave;
use App\Models\Procedimiento;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\AjustesCaseta;
use App\Support\RelojVoBo;
use Illuminate\Support\Carbon;

/**
 * Renglones de «Mis pendientes» de Transporte, Procedimientos, Préstamo de
 * llaves y Responsivas (ADR-0014; criterio 1). Corre dentro del Tenant de la
 * empresa de trabajo (MisPendientes::calcular). Los de llaves y equipos de
 * turno solo aparecen si la empresa configuró sus horas, y llevan color
 * (ámbar / roja) como los de Candidatos.
 */
class PendientesActivos
{
    /**
     * @return list<array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}>
     */
    public function para(User $actor, int $empresaId): array
    {
        $items = [];

        // Vales de taxi que esperan el Vo.Bo. (dentro del alcance de «Aprobar»): una sola consulta
        if ($actor->can('transporte.aprobar') && $actor->can('transporte.ver')) {
            $fila = app(BitacoraTransporte::class)->limitar(MovimientoTransporte::query(), $actor, 'transporte.aprobar')
                ->where('movimientos_transporte.estatus', 'no_llego')->where('movimientos_transporte.anulado', false)
                ->whereNull('movimientos_transporte.autorizado_en')->toBase()
                ->selectRaw('COUNT(*) as total, MIN(movimientos_transporte.fecha) as desde, MIN(movimientos_transporte.created_at) as esperando_desde')->first();
            $total = (int) ($fila->total ?? 0);
            $desde = $total > 0 && $fila->desde ? substr((string) $fila->desde, 0, 10) : null;
            // Color del renglón por el vale que más lleva esperando (RelojVoBo: amarillo 24–72 h, rojo > 72 h)
            $nivel = $total > 0 && $fila->esperando_desde ? RelojVoBo::de(Carbon::parse((string) $fila->esperando_desde, 'UTC'))['nivel'] : null;
            $items[] = $this->item('vales_taxi', 'Vales de taxi por autorizar', 'bi-taxi-front', $total,
                route('transporte.index', array_filter(['estado' => 'por_autorizar', 'fecha_inicio' => $desde])), $nivel === 'normal' ? null : $nivel);
        }

        // «Procedimientos por aprobar» lo pinta MisPendientes en su lugar (ADR-0016) con procedimientosPorAprobar()

        // La empresa ya viene cargada con el usuario (EstablecerEmpresa): sin consulta de más
        $empresa = ! $actor->es_superadmin && (int) $actor->empresa_id === $empresaId && $actor->relationLoaded('empresa') ? $actor->empresa : $empresaId;
        $ajustes = AjustesCaseta::de($empresa);

        // Llaves fuera de tiempo (semáforo configurado)
        if ($actor->can('prestamo_llaves.editar') && AdministradorPrestamosLlaves::conSemaforo($ajustes)) {
            $servicio = app(AdministradorPrestamosLlaves::class);
            $niveles = $servicio->limitar(PrestamoLlave::query(), $actor, 'prestamo_llaves.editar')->vigentes()->get()
                ->map(fn (PrestamoLlave $p) => $servicio->semaforo($p, $ajustes)['nivel'])->filter();
            $items[] = $this->item('llaves_fuera', 'Llaves fuera de tiempo', 'bi-key', $niveles->count(), route('prestamo_llaves.index'),
                $niveles->contains('rojo') ? 'roja' : ($niveles->isNotEmpty() ? 'ambar' : null));
        }

        // Equipos de turno que no regresaron a su hora
        if ($actor->can('responsivas.editar') && (int) $ajustes['responsivas_horas_turno'] > 0) {
            $servicio = app(AdministradorResponsivas::class);
            $niveles = $servicio->limitar(Responsiva::query(), $actor, 'responsivas.editar')->where('responsivas.estado', Responsiva::EN_CAMPO)
                ->with('equipos:id,responsiva_id,modalidad,devuelto_en')->get()
                ->map(fn (Responsiva $r) => $servicio->regresoTurno($r, $ajustes)['nivel'] ?? null)->filter();
            $items[] = $this->item('equipos_turno', 'Equipos de turno sin regresar', 'bi-broadcast-pin', $niveles->count(), route('responsivas.index'),
                $niveles->contains('rojo') ? 'roja' : ($niveles->isNotEmpty() ? 'ambar' : null));
        }

        return $items;
    }

    /**
     * Procedimientos que esperan la aprobación de quien entra (no los que
     * uno mismo escribió o envió). Como AdministradorProcedimientos::idsPorAprobar()
     * pero con menos consultas: las aplicaciones de la versión solo hacen
     * falta si el permiso «Aprobar» del usuario no es de toda la empresa.
     */
    public function procedimientosPorAprobar(User $actor): int
    {
        $servicio = app(AdministradorProcedimientos::class);
        $deEmpresa = $actor->es_superadmin || app(Autorizador::class)->sedesPermitidas($actor, 'procedimientos.aprobar') === null;

        return $servicio->limitar(Procedimiento::query(), $actor, 'procedimientos.aprobar')
            ->where('procedimientos.estado_trabajo', Procedimiento::EN_REVISION)
            ->with($deEmpresa ? ['trabajo'] : ['trabajo.aplicaciones'])->get()
            ->filter(fn (Procedimiento $p) => $servicio->motivoNoAprueba($actor, $p, $p->trabajo) === null)
            ->count();
    }

    /**
     * @return array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}
     */
    private function item(string $clave, string $titulo, string $icono, int $total, string $url, ?string $nivel = null): array
    {
        return compact('clave', 'titulo', 'icono', 'total', 'url') + ['nivel' => $total > 0 ? $nivel : null];
    }
}
