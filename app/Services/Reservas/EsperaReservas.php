<?php

namespace App\Services\Reservas;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\RelojVoBo;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que espera tiene reloj, color y suplente (criterio 3): cada aprobación
 * pendiente muestra cuánto lleva esperando; a las horas de «recordar» de la
 * empresa (por omisión 4) se pone ámbar y se reenvía el aviso al
 * responsable y a su suplente; a las de «escalar» (por omisión 24) se pone
 * roja y se avisa al responsable general de reservas y a su suplente (o, si
 * ya estaban avisados, a quien configura las reservas en la sede). Un aviso
 * por nivel, nunca repetido (recordatorio_en, escalado_en).
 *
 * Corre en la tarea programada `plataforma:reservas-pendientes` (en proceso,
 * sin exec) y, por si el cron del hosting no corre, al abrir las pantallas
 * de Reservas (a lo mucho una vez por minuto por empresa).
 */
class EsperaReservas
{
    public function __construct(
        private readonly AjustesReservas $ajustes,
        private readonly ResponsablesReservas $responsables,
        private readonly CentroNotificaciones $centro,
        private readonly TextosReservas $textos,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array{0: int, 1: int} [horas recordar, horas escalar]
     */
    public function umbrales(int $empresaId): array
    {
        $a = $this->ajustes->de($empresaId);

        return [$a['recordar_horas'], $a['escalar_horas']];
    }

    /**
     * Reloj de una aprobación pendiente (RelojVoBo con las horas de la empresa).
     *
     * @return array{minutos: int, hace: string, nivel: string, etiqueta: string, texto: string}|null
     */
    public function reloj(ReservaAprobacion $a, ?array $umbrales = null): ?array
    {
        if (! $a->pendiente()) {
            return null;
        }

        return RelojVoBo::de($a->solicitada_en ?? $a->created_at, null, $umbrales ?? $this->umbrales((int) $a->empresa_id));
    }

    /**
     * @return array{recordatorios: int, escalamientos: int}
     */
    public function escalar(): array
    {
        $total = ['recordatorios' => 0, 'escalamientos' => 0];
        foreach (Empresa::where('activo', true)->pluck('id') as $empresaId) {
            $hecho = $this->escalarEmpresa((int) $empresaId);
            $total['recordatorios'] += $hecho['recordatorios'];
            $total['escalamientos'] += $hecho['escalamientos'];
        }

        return $total;
    }

    /**
     * @return array{recordatorios: int, escalamientos: int}
     */
    public function escalarEmpresa(int $empresaId): array
    {
        [$recordar, $escalar] = $this->umbrales($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($recordar, $escalar) {
            $cuenta = ['recordatorios' => 0, 'escalamientos' => 0];
            $pendientes = ReservaAprobacion::with(['reserva.area.espacio', 'reserva.area.sede', 'departamento:id,nombre'])
                ->where('estado', ReservaAprobacion::PENDIENTE)->where('vigente', true)
                ->where(fn ($q) => $q
                    ->where(fn ($r) => $r->whereNull('recordatorio_en')->where('solicitada_en', '<=', now()->subHours($recordar)))
                    ->orWhere(fn ($e) => $e->whereNull('escalado_en')->where('solicitada_en', '<=', now()->subHours($escalar))))
                ->orderBy('solicitada_en')->limit(500)->get();
            foreach ($pendientes as $a) {
                if ($a->reserva === null || ! $a->reserva->activa()) {
                    continue;
                }
                $minutos = (int) $a->solicitada_en->diffInMinutes(now(), true);
                if ($minutos >= $recordar * 60 && $a->recordatorio_en === null) {
                    $cuenta['recordatorios'] += $this->recordar($a, $minutos) ? 1 : 0;
                }
                if ($minutos >= $escalar * 60 && $a->escalado_en === null) {
                    $cuenta['escalamientos'] += $this->escalarUna($a, $minutos) ? 1 : 0;
                }
            }

            return $cuenta;
        });
    }

    /** Al abrir las pantallas de Reservas: a lo mucho una vez por minuto por empresa, sin tirar la pantalla. */
    public function escalarSiToca(int $empresaId): void
    {
        if (! Cache::add('reservas-pendientes:'.$empresaId, 1, 60)) {
            return;
        }
        try {
            $this->escalarEmpresa($empresaId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function recordar(ReservaAprobacion $a, int $minutos): bool
    {
        $reserva = $a->reserva;
        $usuarios = $this->responsables->aQuienRecordar($a, $reserva);
        $a->forceFill(['recordatorio_en' => now(), 'avisados' => collect((array) $a->avisados)->merge($usuarios->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all()])->saveQuietly();
        if ($usuarios->isEmpty()) {
            return false;
        }
        $titulo = 'Recordatorio: reserva por aprobar ('.$this->textos->parte($a).')';
        $texto = $reserva->titulo.' · '.$this->textos->resumen($reserva).' · lleva '.RelojVoBo::duracion($minutos).' esperando';
        $this->avisar($a, $usuarios, 'reserva_recordatorio', $titulo, $texto, true);
        app(FlujoReservas::class)->evento($reserva, 'recordatorio', 'Recordatorio a '.$usuarios->pluck('name')->join(', ', ' y ').' ('.$this->textos->parte($a).')', null);
        $this->auditar($a, 'reservas.recordatorio', ['parte' => $this->textos->parte($a), 'avisados' => $usuarios->pluck('id')->all()]);

        return true;
    }

    public function escalarUna(ReservaAprobacion $a, int $minutos): bool
    {
        $reserva = $a->reserva;
        $usuarios = $this->responsables->aQuienEscalar($a, $reserva);
        $a->forceFill(['escalado_en' => now(), 'escalado_a' => $usuarios->pluck('id')->map(fn ($id) => (int) $id)->values()->all()])->saveQuietly();
        if ($usuarios->isEmpty()) {
            $this->auditar($a, 'reservas.escalada', ['parte' => $this->textos->parte($a), 'avisados' => [], 'sin_a_quien' => true]);

            return false;
        }
        $titulo = 'Aprobación atrasada: '.$reserva->titulo;
        $texto = 'La aprobación de '.$this->textos->parte($a).' lleva '.RelojVoBo::duracion($minutos).' sin respuesta · '.$this->textos->resumen($reserva)
            .'. Puedes responderla tú o darle seguimiento.';
        $this->avisar($a, $usuarios, 'reserva_escalada', $titulo, $texto, false);
        app(FlujoReservas::class)->evento($reserva, 'escalada', 'Se avisó a '.$usuarios->pluck('name')->join(', ', ' y ').': la aprobación de '.$this->textos->parte($a).' lleva '.RelojVoBo::duracion($minutos), null);
        $this->auditar($a, 'reservas.escalada', ['parte' => $this->textos->parte($a), 'avisados' => $usuarios->pluck('id')->all()]);

        return true;
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function avisar(ReservaAprobacion $a, Collection $usuarios, string $tipo, string $titulo, string $texto, bool $conBoton): void
    {
        $reserva = $a->reserva;
        $this->centro->avisar((int) $reserva->empresa_id, $usuarios->pluck('id'), $tipo, [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('reservas.show', $reserva->id),
            // Se resuelve junto con el aviso original cuando alguien responde
            'referencia_tipo' => 'reserva_aprobacion', 'referencia_id' => (int) $a->id,
            'acciones' => $conBoton
                ? [['etiqueta' => 'Aprobar', 'url' => route('reservas.aprobaciones.responder', $a->id), 'campos' => ['respuesta' => 'aprobar'], 'estilo' => 'exito'], ['etiqueta' => 'Ver y responder', 'abrir' => true]]
                : [['etiqueta' => 'Ver y responder', 'abrir' => true]],
        ]);
        app(AvisosCorreo::class)->recepcion((int) $reserva->empresa_id, 'reserva_aprobacion', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir la reserva', route('reservas.show', $reserva->id)]]));
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(ReservaAprobacion $a, string $evento, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $a->empresa_id, 'user_id' => null, 'evento' => $evento, 'auditable_type' => Reserva::class,
            'auditable_id' => $a->reserva_id, 'antes' => ['estado' => $a->reserva?->estado], 'despues' => $despues, 'ip' => null,
        ]);
    }
}
