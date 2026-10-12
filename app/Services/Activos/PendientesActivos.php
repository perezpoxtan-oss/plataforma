<?php

namespace App\Services\Activos;

use App\Models\MovimientoTransporte;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\AjustesCaseta;

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

        // Vales de taxi que esperan el Vo.Bo. (dentro del alcance de «Aprobar»)
        if ($actor->can('transporte.aprobar') && $actor->can('transporte.ver')) {
            $vales = app(BitacoraTransporte::class)->limitar(MovimientoTransporte::query(), $actor, 'transporte.aprobar')
                ->where('movimientos_transporte.estatus', 'no_llego')->where('movimientos_transporte.anulado', false)
                ->whereNull('movimientos_transporte.autorizado_en');
            $total = (clone $vales)->count();
            $desde = $total > 0 ? (string) (clone $vales)->min('movimientos_transporte.fecha') : null;
            $items[] = $this->item('vales_taxi', 'Vales de taxi por autorizar', 'bi-taxi-front', $total,
                route('transporte.index', array_filter(['estado' => 'por_autorizar', 'fecha_inicio' => $desde ? substr($desde, 0, 10) : null])));
        }

        // Procedimientos que esperan su aprobación (no los que uno mismo escribió o envió)
        if ($actor->can('procedimientos.aprobar')) {
            $items[] = $this->item('procedimientos_aprobar', 'Procedimientos por aprobar', 'bi-journal-check',
                count(app(AdministradorProcedimientos::class)->idsPorAprobar($actor)), route('procedimientos.index', ['filtro' => 'por_aprobar']));
        }

        $ajustes = AjustesCaseta::de($empresaId);

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
     * @return array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}
     */
    private function item(string $clave, string $titulo, string $icono, int $total, string $url, ?string $nivel = null): array
    {
        return compact('clave', 'titulo', 'icono', 'total', 'url') + ['nivel' => $total > 0 ? $nivel : null];
    }
}
