<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Procedimiento;
use App\Models\ProcedimientoAcuse;
use App\Models\ProcedimientoVersion;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Procedimiento por leer (renglón 15; C-22). El recordatorio a cada persona
 * ya lo manda Procedimientos cada N días (Configuración); el motor solo pasa
 * al suplente la versión que lleva tiempo publicada y aún tiene quien no
 * firma «Leí y entendí»: le llega a quien aprueba procedimientos.
 */
class ProcedimientosPorLeer extends Reloj
{
    public function clave(): string
    {
        return 'procedimientos_leer';
    }

    public function nombre(): string
    {
        return 'Procedimientos por leer';
    }

    public function suplenteOmision(): string
    {
        return 'quien aprueba procedimientos en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['procedimientos'];
    }

    public function permiso(): ?string
    {
        return 'procedimientos.aprobar';
    }

    public function recuerda(): bool
    {
        return false;
    }

    public function esperas(?int $empresaId): Collection
    {
        $servicio = app(AdministradorProcedimientos::class);
        $versiones = ProcedimientoVersion::with(['procedimiento:id,empresa_id,clave,estado', 'aplicaciones'])->where('estado', ProcedimientoVersion::PUBLICADA)
            ->whereHas('procedimiento', fn ($q) => $q->where('estado', Procedimiento::PUBLICADO))->orderBy('id')->limit(200)->get();
        $lista = collect();
        foreach ($versiones as $v) {
            $deben = $servicio->obligados($v)->pluck('id')->map(fn ($id) => (int) $id);
            $firmaron = ProcedimientoAcuse::where('version_id', $v->id)->pluck('user_id')->map(fn ($id) => (int) $id);
            $faltan = $deben->diff($firmaron)->count();
            if ($faltan > 0) {
                $lista->push(new Espera('procedimiento_lectura', (int) $v->id, $v->aprobado_en ?? $v->updated_at ?? now(),
                    $v->procedimiento->clave.': '.$faltan.' '.($faltan === 1 ? 'persona no ha' : 'personas no han').' firmado «Leí y entendí»',
                    route('procedimientos.show', $v->procedimiento_id), null, ['faltan' => $faltan]));
            }
        }

        return $lista;
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return collect();
    }

    public function textoEscalado(Espera $e, int $minutos): array
    {
        return [$e->titulo, 'Se publicó hace '.MotorRelojes::duracion($minutos).' y se les recordó. Revisa en «Acuses» quién falta y habla con ellos o con su jefe.'];
    }
}
