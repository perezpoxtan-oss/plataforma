<?php

namespace App\Services\Novedades;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Novedad;
use App\Models\Sede;
use App\Models\Turno;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * «Pendiente de turno» con dueño, hora y aviso (criterios 1 y 3; ADR-0013).
 *
 * El ticket que se deja para el siguiente turno guarda cuándo se marcó
 * (pendiente_turno_en). Con los turnos de la empresa (Recursos Humanos →
 * Turnos) se sabe qué turno lo recibe en esa sede:
 *
 *  - Al empezar ese turno se avisa a su dueño (la persona canalizada) o, si
 *    no tiene, al personal de Seguridad de la sede (turno_avisado_en).
 *  - Si ese turno termina y el ticket sigue pendiente, se avisa a quien puede
 *    reabrir casos en la sede (jefe de seguridad) (turno_escalado_en).
 *
 * Un aviso por nivel, nunca repetido. Sin turnos configurados en la sede se
 * avisa al marcarlo y no hay hora límite (no hay de dónde sacarla). Lo corre
 * la tarea programada plataforma:novedades-pendientes (en proceso, sin exec)
 * y, por si el cron no corre, la lista de la bitácora al pintarse (a lo mucho
 * una vez por minuto por empresa).
 */
class TurnosNovedades
{
    public function __construct(
        private readonly AvisosNovedades $avisos,
        private readonly Tenant $tenant,
    ) {}

    /**
     * Turno que recibe un ticket marcado en ese momento: el primero de la sede
     * que empieza después. null si la sede no tiene turnos.
     *
     * @return array{nombre: string, inicio: CarbonImmutable, fin: CarbonImmutable}|null
     */
    public function siguienteTurno(Sede $sede, \DateTimeInterface $desde): ?array
    {
        $zona = $sede->zonaHoraria();
        $momento = CarbonImmutable::instance($desde)->setTimezone($zona);
        $mejor = null;
        foreach (Turno::where('activo', true)->aplicanEn([(int) $sede->id])->get() as $turno) {
            [$h, $m] = array_map('intval', explode(':', $turno->inicio()));
            $inicio = $momento->setTime($h, $m);
            if ($inicio->lessThanOrEqualTo($momento)) {
                $inicio = $inicio->addDay();
            }
            if ($mejor === null || $inicio->lessThan($mejor['inicio'])) {
                $mejor = ['nombre' => $turno->nombre, 'inicio' => $inicio, 'fin' => $inicio->addMinutes($turno->minutos())];
            }
        }

        return $mejor;
    }

    /** Texto para la ficha: «Lo recibe el turno Nocturno (23:00)». */
    public function textoTurno(Novedad $novedad): ?string
    {
        if ($novedad->estatus !== Novedad::PENDIENTE_TURNO || $novedad->pendiente_turno_en === null || $novedad->sede === null) {
            return null;
        }
        $turno = $this->siguienteTurno($novedad->sede, $novedad->pendiente_turno_en);

        return $turno === null ? null : 'Lo recibe el turno '.$turno['nombre'].' ('.$turno['inicio']->format('H:i').')';
    }

    /**
     * Recorre las empresas activas y avisa lo que ya toca.
     *
     * @return array{avisos: int, escalamientos: int}
     */
    public function revisar(): array
    {
        $total = ['avisos' => 0, 'escalamientos' => 0];
        foreach (Empresa::where('activo', true)->pluck('id') as $empresaId) {
            $hecho = $this->revisarEmpresa((int) $empresaId);
            $total['avisos'] += $hecho['avisos'];
            $total['escalamientos'] += $hecho['escalamientos'];
        }

        return $total;
    }

    /**
     * @return array{avisos: int, escalamientos: int}
     */
    public function revisarEmpresa(int $empresaId): array
    {
        return $this->tenant->conEmpresa($empresaId, function () {
            $cuenta = ['avisos' => 0, 'escalamientos' => 0];
            $pendientes = Novedad::with(['sede', 'asignado:id,name'])
                ->where('estatus', Novedad::PENDIENTE_TURNO)->whereNotNull('pendiente_turno_en')->whereNull('turno_escalado_en')
                ->orderBy('pendiente_turno_en')->limit(500)->get();
            foreach ($pendientes as $novedad) {
                if ($novedad->sede === null) {
                    continue;
                }
                $turno = $this->siguienteTurno($novedad->sede, $novedad->pendiente_turno_en);
                $ahora = CarbonImmutable::now();
                if ($novedad->turno_avisado_en === null && ($turno === null || $ahora->greaterThanOrEqualTo($turno['inicio']))) {
                    $avisados = $this->avisos->inicioDeTurno($novedad, $turno['nombre'] ?? null);
                    $novedad->forceFill(['turno_avisado_en' => now()])->saveQuietly();
                    $this->auditar($novedad, 'novedades.turno_avisado', ['turno' => $turno['nombre'] ?? null, 'avisados' => $avisados]);
                    $cuenta['avisos']++;
                }
                if ($turno !== null && $novedad->turno_avisado_en !== null && $ahora->greaterThanOrEqualTo($turno['fin'])) {
                    $avisados = $this->avisos->finDeTurno($novedad, $turno['nombre']);
                    $novedad->forceFill(['turno_escalado_en' => now()])->saveQuietly();
                    $this->auditar($novedad, 'novedades.turno_escalado', ['turno' => $turno['nombre'], 'avisados' => $avisados]);
                    $cuenta['escalamientos']++;
                }
            }

            return $cuenta;
        });
    }

    /**
     * Desde la pantalla (hosting sin cron confiable): a lo mucho una vez por
     * minuto por empresa y sin que un correo fallido tire la pantalla.
     */
    public function revisarSiToca(int $empresaId): void
    {
        if (! Cache::add('novedades-turno:'.$empresaId, 1, 60)) {
            return;
        }
        try {
            $this->revisarEmpresa($empresaId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(Novedad $novedad, string $evento, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $novedad->empresa_id, 'user_id' => null, 'evento' => $evento,
            'auditable_type' => Novedad::class, 'auditable_id' => $novedad->id,
            'antes' => ['estatus' => $novedad->estatus], 'despues' => $despues, 'ip' => null,
        ]);
    }
}
