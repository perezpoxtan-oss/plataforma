<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Colaborador;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Asistencia\AjustesAsistencia;
use App\Services\Asistencia\AlcanceAsistencia;
use App\Services\Asistencia\CalculoAsistencia;
use App\Services\Asistencia\JustificacionesAsistencia;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Asistencia (ADR-0024): quien no ha llegado a su turno de hoy. OPCIONAL y
 * apagado de fábrica: solo corre si la empresa tiene Asistencia y encendió
 * «Avisar al jefe del departamento cuando alguien no llegue» (Avanzado).
 *
 * Espera desde que pasó la tolerancia de su turno. Le toca al jefe del
 * departamento (titular y suplentes en Autorizaciones, en esa sede o en
 * todas): lo ve en Mis pendientes («Faltas de hoy») y a los 30 minutos le
 * llega el recordatorio a la campana; a las 2 horas pasa a quien justifica
 * asistencias en toda la empresa (Recursos Humanos). Se apaga solo cuando
 * la persona entra (la caseta registra su entrada), cuando se justifica la
 * falta o cuando termina su turno (ya es falta: queda en el reporte).
 */
class FaltasDelDia extends Reloj
{
    public const UMBRALES = [30, 120];

    public function clave(): string
    {
        return 'asistencia_faltas';
    }

    public function nombre(): string
    {
        return 'Faltas de hoy (Asistencia)';
    }

    public function suplenteOmision(): string
    {
        return 'quien justifica asistencias en toda la empresa (Recursos Humanos)';
    }

    public function modulos(): array
    {
        return [AlcanceAsistencia::MODULO];
    }

    public function permiso(): ?string
    {
        return AlcanceAsistencia::JUSTIFICAR;
    }

    public function umbralesPropios(?Empresa $empresa): ?array
    {
        return self::UMBRALES;
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Asistencia → Avanzado (encender o apagar el aviso)';
    }

    public function renglonDef(): ?array
    {
        return ['asistencia_faltas', 'Faltas de hoy', 'bi-calendar-x', route('asistencia.index', ['ver' => 'faltan'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 persona de tu departamento no ha llegado', '%d personas de tu departamento no han llegado',
            'Su turno ya empezó y no tiene entrada en la Bitácora de accesos. Si tiene permiso, justifícalo en Asistencia.', 'Ver asistencia'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $empresa = $empresaId === null ? null : Empresa::find($empresaId);
        if ($empresa === null || ! app(AjustesAsistencia::class)->avisaFaltas($empresa)) {
            return collect();
        }
        $colaboradores = Colaborador::query()->personal()->where('activo', true)->whereNotNull('turno_id')->whereNotNull('departamento_id')
            ->with(['turno', 'sede:id,nombre,zona_horaria'])->orderBy('id')->limit(2000)->get();
        if ($colaboradores->isEmpty()) {
            return collect();
        }
        $hoy = CarbonImmutable::now($empresa->zona_horaria ?: HoraLocal::ZONA_PLATAFORMA)->format('Y-m-d');
        $dias = app(CalculoAsistencia::class)->dias($colaboradores, $hoy, $hoy, $empresa);
        $tolerancia = app(AjustesAsistencia::class)->tolerancia($empresa);
        $lista = collect();
        foreach ($colaboradores as $c) {
            $d = $dias[$c->id][0] ?? null;
            if ($d === null || $d['estado'] !== 'sin_llegar' || isset($d['justificaciones']['falta'])) {
                continue;
            }
            $lista->push(new Espera(JustificacionesAsistencia::REFERENCIA_FALTA, (int) $c->id, $d['inicio']->addMinutes($tolerancia)->utc(),
                $c->nombreCompleto().' no ha llegado ('.$d['turno'].', '.$d['inicio']->format('H:i').')',
                route('asistencia.index', ['ver' => 'faltan', 'departamento' => $c->departamento_id]), $c->sede_id ? (int) $c->sede_id : null,
                ['departamento_id' => (int) $c->departamento_id, 'nombre' => $c->nombreCompleto(), 'turno' => $d['turno']]));
            if ($lista->count() >= 500) {
                break;
            }
        }

        return $lista;
    }

    /** El jefe del departamento: titular y suplentes, en todas las sedes o en la de la persona. */
    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return User::where('activo', true)->whereIn('id', $this->jefes($e))->get();
    }

    public function leToca(User $actor, Espera $e): bool
    {
        return in_array((int) $actor->id, $this->jefes($e), true);
    }

    /** Recursos Humanos (justificar con alcance en esa sede) también puede atenderlo cuando se le pasa. */
    public function puedeAtender(User $delegado, Espera $e): bool
    {
        return $this->leToca($delegado, $e) || app(Destinatarios::class)->alcanza($delegado, AlcanceAsistencia::JUSTIFICAR, $e->sedeId);
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can(AlcanceAsistencia::VER);
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return [$e->titulo, 'Su turno ('.$e->datos['turno'].') ya empezó y no tiene entrada. Si tiene permiso, justifícalo en Asistencia.'];
    }

    /** @return list<int> */
    private function jefes(Espera $e): array
    {
        // Una lectura por empresa (el motor corre varias empresas con la misma instancia)
        $empresa = (int) app(Tenant::class)->empresaId();
        $this->cacheJefes[$empresa] ??= DepartamentoResponsable::query()->get(['departamento_id', 'sede_id', 'user_id']);

        return $this->cacheJefes[$empresa]
            ->filter(fn ($r) => (int) $r->departamento_id === (int) ($e->datos['departamento_id'] ?? 0) && ($r->sede_id === null || (int) $r->sede_id === $e->sedeId))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @var array<int, Collection<int, DepartamentoResponsable>> */
    private array $cacheJefes = [];

    public function olvidar(): void
    {
        $this->cacheJefes = [];
        parent::olvidar();
    }
}
