<?php

namespace App\Services\Relojes\Relojes;

use App\Models\MovimientoTransporte;
use App\Models\Sede;
use App\Models\User;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use App\Services\Transporte\BitacoraTransporte;
use Illuminate\Support\Collection;

/**
 * Ruta programada sin registro (DD-17, D-08): si la unidad de las 06:40 no
 * se registra en la Bitácora de transporte 15 minutos después de su hora en
 * caseta, se avisa a la caseta y a quien da el Vo.Bo. en la sede; una hora
 * después pasa a quien da el Vo.Bo. en toda la empresa. Solo los horarios
 * de hoy que operan hoy, hasta 6 horas después de su hora.
 */
class RutasSinRegistro extends Reloj
{
    public const TOLERANCIA_MINUTOS = 15;

    public const VENTANA_HORAS = 6;

    public const REFERENCIA = 'ruta_sin_registro';

    public function clave(): string
    {
        return 'rutas_sin_registro';
    }

    public function nombre(): string
    {
        return 'Rutas sin registro';
    }

    public function suplenteOmision(): string
    {
        return 'quien da el Vo.Bo. de transporte en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['transporte'];
    }

    public function permiso(): ?string
    {
        return 'transporte.crear';
    }

    public function umbralesPropios($empresa): ?array
    {
        return [1, 60];
    }

    public function renglonDef(): ?array
    {
        return ['rutas_sin_registro', 'Rutas sin registro', 'bi-signpost-split', route('transporte.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 ruta pasó su hora sin registro', '%d rutas pasaron su hora sin registro', 'La unidad no se registró en la Bitácora de transporte. Regístrala (a tiempo, con retraso o «no llegó» con taxis).', 'Registrar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $transporte = app(BitacoraTransporte::class);
        $sedes = Sede::where('activo', true)->get()->keyBy('id');
        $candidatos = [];
        foreach ($transporte->horariosPara($sedes->keys()->all()) as $h) {
            $sede = $sedes[$h->ruta->sede_id] ?? null;
            $hora = $transporte->horaDeCaseta($h);
            if ($sede === null || $hora === '') {
                continue;
            }
            $ahora = $transporte->ahoraEn($sede);
            if (! $h->aplicaEn($ahora->dayOfWeekIso)) {
                continue;
            }
            [$hh, $mm] = array_map('intval', explode(':', $hora));
            $programada = $ahora->setTime($hh, $mm);
            $limite = $programada->addMinutes(self::TOLERANCIA_MINUTOS);
            if ($ahora->lt($limite) || $ahora->gt($programada->addHours(self::VENTANA_HORAS))) {
                continue;
            }
            $candidatos[] = [$h, $sede, $hora, $limite, $ahora->toDateString()];
        }
        if ($candidatos === []) {
            return collect();
        }
        // Una sola consulta: lo ya registrado (no anulado) de esos horarios en su día local
        $registrados = MovimientoTransporte::whereIn('ruta_horario_id', array_map(fn ($c) => $c[0]->id, $candidatos))->where('anulado', false)
            ->whereIn('fecha', array_values(array_unique(array_map(fn ($c) => $c[4], $candidatos))))->get(['ruta_horario_id', 'fecha'])
            ->map(fn ($m) => $m->ruta_horario_id.'|'.$m->fecha->toDateString())->flip();
        $lista = collect();
        foreach ($candidatos as [$h, $sede, $hora, $limite, $dia]) {
            if (! isset($registrados[$h->id.'|'.$dia])) {
                $lista->push(new Espera(self::REFERENCIA, (int) $h->id, $limite->utc(), $h->ruta->nombre.' '.$hora.' sin registro',
                    route('transporte.index'), (int) $sede->id, ['sede' => $sede->nombre]));
            }
        }

        return $lista;
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'transporte.crear', $e->sedeId)->concat($this->conPermiso($empresaId, 'transporte.aprobar', $e->sedeId))->unique('id')->values();
    }

    /** Le toca a la caseta (registra) y a quien da el Vo.Bo. en la sede. */
    public function leToca(User $actor, Espera $e): bool
    {
        $destinatarios = app(Destinatarios::class);

        return $destinatarios->alcanza($actor, 'transporte.crear', $e->sedeId) || $destinatarios->alcanza($actor, 'transporte.aprobar', $e->sedeId);
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can('transporte.crear') || $actor->can('transporte.aprobar');
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId === null) {
            $aprueban = $this->conPermiso($empresaId, 'transporte.aprobar', null)->reject(fn ($u) => $responsables->contains('id', $u->id))->values();
            if ($aprueban->isNotEmpty()) {
                return $aprueban;
            }
        }

        return parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return [$e->titulo, 'Pasaron '.self::TOLERANCIA_MINUTOS.' minutos de su hora y no se ha registrado en '.$e->datos['sede'].'. Regístrala en la Bitácora de transporte.'];
    }
}
