<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Acceso;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use App\Support\AjustesCaseta;
use App\Support\HoraLocal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Permanencia excedida (C-25, B-05): visita 4 h, proveedor y contratista
 * 8 h, huésped con daypass hasta la hora de cierre (Configuración → Caseta
 * y accesos). Al rebasar se avisa al supervisor de la sede; al doble, al jefe
 * de seguridad. La tarjeta lo dice y «En sitio» tiene el filtro «Rebasaron
 * su tiempo» para el cambio de turno.
 */
class Permanencia extends Reloj
{
    public const REFERENCIA = 'acceso_permanencia';

    public function clave(): string
    {
        return 'permanencia';
    }

    public function nombre(): string
    {
        return 'Rebasaron su tiempo en sitio';
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
        return [1, 2];
    }

    public function umbralesDe(Espera $e, array $base): array
    {
        return [$e->datos['limite'], $e->datos['limite'] * 2];
    }

    public function renglonDef(): ?array
    {
        return ['permanencia', 'Rebasaron su tiempo en sitio', 'bi-clock-history', route('accesos.index', ['rebasaron' => 1])];
    }

    public function textosInicio(): ?array
    {
        return ['1 persona rebasó su tiempo en sitio', '%d personas rebasaron su tiempo en sitio', 'Pregunta en caseta si ya salieron: si sí, dales salida; si no, búscalos.', 'Ver en sitio'];
    }

    /**
     * Límite en minutos desde la entrada para ese acceso (null = sin reloj).
     *
     * @param  array<string, mixed>  $ajustes  AjustesCaseta::de()
     */
    public static function limite(Acceso $a, array $ajustes, ?string $zona = null): ?int
    {
        $horas = match (true) {
            $a->tipo === 'visitante' => (int) $ajustes['permanencia_visita_horas'],
            in_array($a->tipo, Acceso::CON_AUTORIZACION, true) => (int) $ajustes['permanencia_proveedor_horas'],
            default => 0,
        };
        if ($horas > 0) {
            return $horas * 60;
        }
        if ($a->tipo === 'huesped' && $a->tipo_pase === 'daypass' && (int) $ajustes['daypass_cierre_hora'] > 0 && $a->entrada_at !== null) {
            $local = $a->entrada_at->copy()->setTimezone($zona ?? app(HoraLocal::class)->zona());
            $cierre = $local->copy()->setTime((int) $ajustes['daypass_cierre_hora'], 0);
            if ($cierre->lte($local)) {
                $cierre->addDay();
            }

            return (int) max(1, intdiv($cierre->getTimestamp() - $local->getTimestamp(), 60));
        }

        return null;
    }

    public function esperas(?int $empresaId): Collection
    {
        $ajustes = AjustesCaseta::de(app(MotorRelojes::class)->empresa($empresaId));

        return Acceso::with('sede:id,empresa_id,zona_horaria', 'sede.empresa:id,zona_horaria')->where('estado', 'en_sitio')->where('movimiento', 'entrada')
            ->whereIn('tipo', ['visitante', 'proveedor', 'contratista', 'huesped'])->orderBy('entrada_at')->limit(500)
            ->get(['id', 'sede_id', 'nombre', 'tipo', 'tipo_pase', 'entrada_at'])
            ->map(function (Acceso $a) use ($ajustes) {
                $limite = self::limite($a, $ajustes, $a->sede?->zonaHoraria());

                return $limite === null ? null : new Espera(self::REFERENCIA, (int) $a->id, $a->entrada_at ?? now(), $a->nombre.' rebasó su tiempo en sitio',
                    route('accesos.index', ['rebasaron' => 1]).'#acceso-'.$a->id, (int) $a->sede_id, ['limite' => $limite, 'nombre' => $a->nombre]);
            })->filter()->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'accesos.aprobar', $e->sedeId);
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        return $rolId === null ? app(Escalamiento::class)->jefesDeSeguridad((int) $empresaId, (int) $e->sedeId) : parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    /** Ids de los accesos que ya rebasaron su tiempo (filtro «Rebasaron su tiempo»). */
    public function idsRebasaron(?int $empresaId): array
    {
        return $this->esperas($empresaId)->filter(fn (Espera $e) => $e->minutos() >= $e->datos['limite'])->pluck('referenciaId')->all();
    }

    /**
     * Reloj de la tarjeta «En sitio»: null si el tipo no tiene límite o aún no lo rebasa.
     *
     * @param  array<string, mixed>  $ajustes
     * @return array{texto: string, nivel: string}|null
     */
    public static function tarjeta(Acceso $a, array $ajustes, ?CarbonInterface $ahora = null): ?array
    {
        $limite = self::limite($a, $ajustes);
        if ($limite === null || $a->entrada_at === null) {
            return null;
        }
        $minutos = (int) max(0, intdiv(($ahora ?? now())->getTimestamp() - $a->entrada_at->getTimestamp(), 60));
        if ($minutos < $limite) {
            return null;
        }

        return ['texto' => 'Rebasó su tiempo: lleva '.MotorRelojes::duracion($minutos).' (límite '.MotorRelojes::duracion($limite).')', 'nivel' => $minutos >= $limite * 2 ? 'roja' : 'ambar'];
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return [$e->titulo, 'Lleva '.MotorRelojes::duracion($minutos).' en sitio (límite '.MotorRelojes::duracion($e->datos['limite']).'). Pregunta en caseta si ya salió: si sí, dale salida.'];
    }

    public function cuentaDesdeElRecordatorio(): bool
    {
        return true;
    }
}
