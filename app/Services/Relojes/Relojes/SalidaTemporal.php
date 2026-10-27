<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Acceso;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use App\Support\AjustesCaseta;
use Illuminate\Support\Collection;

/**
 * Salida temporal que no regresa (C-25, B-05): el proveedor o contratista
 * que «fue por material» (2 h de fábrica). Al supervisor; al doble, al jefe.
 * Las salidas a tour de los huéspedes no llevan reloj.
 */
class SalidaTemporal extends Reloj
{
    public const REFERENCIA = 'acceso_salida_temporal';

    public function clave(): string
    {
        return 'salida_temporal';
    }

    public function nombre(): string
    {
        return 'Salidas temporales sin regresar';
    }

    public function suplenteOmision(): string
    {
        return 'el jefe de seguridad de la sede';
    }

    public function modulos(): array
    {
        return ['accesos'];
    }

    public function permiso(): ?string
    {
        return 'accesos.aprobar';
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Configuración → Caseta y accesos: tiempo de permanencia';
    }

    public function umbralesPropios($empresa): ?array
    {
        $horas = max(0, (int) AjustesCaseta::de($empresa)['salida_temporal_horas']);

        return [$horas * 60, $horas * 120];
    }

    public function esperas(?int $empresaId): Collection
    {
        if ((int) AjustesCaseta::de(app(MotorRelojes::class)->empresa($empresaId))['salida_temporal_horas'] === 0) {
            return collect();
        }

        return Acceso::where('estado', 'en_sitio')->where('movimiento', 'salida_temporal')->whereIn('tipo', Acceso::CON_AUTORIZACION)
            ->orderBy('entrada_at')->limit(500)->get(['id', 'sede_id', 'nombre', 'entrada_at', 'acceso_origen_id'])
            ->map(fn (Acceso $a) => new Espera(self::REFERENCIA, (int) $a->id, $a->entrada_at ?? now(), $a->nombre.' salió y no ha regresado',
                route('accesos.index', ['rebasaron' => 1]).'#acceso-'.($a->acceso_origen_id ?? $a->id), (int) $a->sede_id, ['origen' => (int) ($a->acceso_origen_id ?? $a->id)]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'accesos.aprobar', $e->sedeId);
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        return $rolId === null ? app(Escalamiento::class)->jefesDeSeguridad((int) $empresaId, (int) $e->sedeId) : parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    /** Ids de los ingresos cuya salida temporal ya rebasó su tiempo (filtro «Rebasaron su tiempo»). */
    public function idsRebasaron(?int $empresaId): array
    {
        $limite = (int) AjustesCaseta::de(app(MotorRelojes::class)->empresa($empresaId))['salida_temporal_horas'] * 60;

        return $this->esperas($empresaId)->filter(fn (Espera $e) => $e->minutos() >= $limite)->map(fn (Espera $e) => $e->datos['origen'])->values()->all();
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return [$e->titulo, 'Lleva '.MotorRelojes::duracion($minutos).' fuera. Llámale o pregunta a quien lo recibió; si ya no regresa, dale salida.'];
    }

    public function cuentaDesdeElRecordatorio(): bool
    {
        return true;
    }
}
