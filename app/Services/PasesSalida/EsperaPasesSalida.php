<?php

namespace App\Services\PasesSalida;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaBitacora;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Recepcion\Destinatarios;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Firmas de pases de salida que tardan: reloj, color y escalamiento
 * (criterio 3 de docs/criterios-ux.md; ADR-0013), como las autorizaciones de
 * la caseta (App\Services\Autorizaciones\Escalamiento) pero en horas o días.
 *
 *  - Reloj: cada pase que espera firma dice desde cuándo espera el paso
 *    actual (desde que el paso anterior se firmó o desde que se envió). Se
 *    muestra siempre.
 *  - Color y avisos: solo si la empresa pone horas en el Circuito
 *    (Configuración → Pases de salida → Circuito, «Firmas que tardan»). Ámbar
 *    desde «recordar», rojo desde «escalar». A las horas de «recordar» se
 *    reenvía el aviso a quien puede firmar ese paso (campana y correo); a las
 *    de «escalar» se avisa a quien firma el siguiente paso o, si es el último
 *    (o nadie firma el siguiente), a quien configura los pases en la sede de
 *    origen. Un aviso por nivel y por paso, nunca repetido (recordatorio_en,
 *    escalado_en); queda en la bitácora del pase y en la auditoría.
 *  - Sin horas configuradas (omisión) no cambia nada de lo que se ve hoy,
 *    salvo el reloj.
 */
class EsperaPasesSalida
{
    public function __construct(
        private readonly CircuitoPasesSalida $circuito,
        private readonly CentroNotificaciones $centro,
        private readonly Destinatarios $destinatarios,
        private readonly Tenant $tenant,
    ) {}

    /**
     * Desde cuándo espera el paso: el último paso anterior firmado de la ronda o el envío de la ronda.
     */
    public function desde(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): CarbonInterface
    {
        $anteriores = $this->circuito->rondaActual($pase)
            ->filter(fn (PaseSalidaAprobacion $a) => $a->orden < $aprobacion->orden && $a->resuelto_en !== null)
            ->map(fn (PaseSalidaAprobacion $a) => $a->resuelto_en);
        $desde = $aprobacion->created_at ?? now();
        foreach ($anteriores as $fecha) {
            if ($fecha->greaterThan($desde)) {
                $desde = $fecha;
            }
        }

        return $desde;
    }

    /**
     * Reloj del paso que espera firma (null si el pase no espera firma).
     *
     * @param  array{recordar: ?int, escalar: ?int}|null  $espera  los ajustes (para no leerlos por ficha)
     * @return array{texto: string, horas: int, nivel: ?string, escalado: ?string}|null
     */
    public function relojDe(PaseSalida $pase, ?array $espera = null): ?array
    {
        $aprobacion = $this->circuito->actual($pase);
        if ($aprobacion === null) {
            return null;
        }
        $espera ??= $this->circuito->espera();
        $desde = $this->desde($pase, $aprobacion);
        $minutos = (int) max(0, $desde->diffInMinutes(now(), true));
        $horas = intdiv($minutos, 60);
        $nivel = null;
        if ($espera['escalar'] !== null && $horas >= $espera['escalar']) {
            $nivel = 'roja';
        } elseif ($espera['recordar'] !== null && $horas >= $espera['recordar']) {
            $nivel = 'ambar';
        } elseif ($espera['recordar'] !== null || $espera['escalar'] !== null) {
            $nivel = 'normal';
        }
        $escaladoA = collect((array) ($aprobacion->escalado_a ?? []))->map(fn ($id) => (int) $id)->filter();

        return [
            'texto' => 'Esperando firma desde hace '.$this->duracion($minutos),
            'horas' => $horas,
            'nivel' => $nivel,
            'escalado' => $escaladoA->isNotEmpty() ? 'Se avisó a: '.User::whereIn('id', $escaladoA)->pluck('name')->join(', ', ' y ') : null,
        ];
    }

    /** «25 min», «5 h», «2 días 3 h». */
    public function duracion(int $minutos): string
    {
        if ($minutos < 60) {
            return max(1, $minutos).' min';
        }
        $horas = intdiv($minutos, 60);
        if ($horas < 24) {
            return $horas.' h';
        }
        $dias = intdiv($horas, 24);
        $resto = $horas % 24;

        return $dias.' '.($dias === 1 ? 'día' : 'días').($resto > 0 ? ' '.$resto.' h' : '');
    }

    // ----------------------------------------------------------- Escalamiento

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
        return $this->tenant->conEmpresa($empresaId, function () {
            $cuenta = ['recordatorios' => 0, 'escalamientos' => 0];
            $espera = $this->circuito->espera();
            if ($espera['recordar'] === null && $espera['escalar'] === null) {
                return $cuenta;
            }
            $pases = PaseSalida::with(['aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre', 'sede:id,nombre', 'solicitante:id,nombre,apellido_paterno,apellido_materno,departamento_id'])
                ->where('estado', PaseSalida::PENDIENTE)->orderBy('id')->limit(500)->get();
            foreach ($pases as $pase) {
                $aprobacion = $this->circuito->actual($pase);
                if ($aprobacion === null) {
                    continue;
                }
                $horas = intdiv((int) $this->desde($pase, $aprobacion)->diffInMinutes(now(), true), 60);
                if ($espera['recordar'] !== null && $horas >= $espera['recordar'] && $aprobacion->recordatorio_en === null) {
                    $cuenta['recordatorios'] += $this->recordar($pase, $aprobacion, $horas) ? 1 : 0;
                }
                if ($espera['escalar'] !== null && $horas >= $espera['escalar'] && $aprobacion->escalado_en === null) {
                    $cuenta['escalamientos'] += $this->escalarUna($pase, $aprobacion, $horas) ? 1 : 0;
                }
            }

            return $cuenta;
        });
    }

    /** Nivel 1: se recuerda a quien puede firmar el paso. */
    public function recordar(PaseSalida $pase, PaseSalidaAprobacion $aprobacion, int $horas): bool
    {
        $aprobacion->forceFill(['recordatorio_en' => now()])->saveQuietly();
        $usuarios = $this->circuito->aprobadores($pase, $aprobacion);
        if ($usuarios->isEmpty()) {
            return false;
        }
        $titulo = "Recordatorio: el pase {$pase->folio} espera tu firma";
        $texto = "{$aprobacion->nombre} · lleva ".$this->duracion($horas * 60).' esperando · '.$pase->etiquetaMotivo().' · '
            .($pase->solicitante?->nombreCompleto() ?? 'sin solicitante').' → '.$pase->nombreDestino();
        $this->avisar($pase, $usuarios, 'pase_firma_recordatorio', $titulo, $texto, (int) $aprobacion->id);
        $this->anotar($pase, 'Recordatorio: el paso «'.$aprobacion->nombre.'» lleva '.$this->duracion($horas * 60).' esperando firma', $usuarios);
        $this->auditar($pase, 'pases_salida.recordatorio', ['paso' => $aprobacion->nombre, 'horas' => $horas, 'avisados' => $usuarios->pluck('id')->all()]);

        return true;
    }

    /** Nivel 2: quien firma el siguiente paso o, si no hay, quien configura los pases en la sede. */
    public function escalarUna(PaseSalida $pase, PaseSalidaAprobacion $aprobacion, int $horas): bool
    {
        $usuarios = $this->aQuienEscalar($pase, $aprobacion);
        $aprobacion->forceFill(['escalado_en' => now(), 'escalado_a' => $usuarios->pluck('id')->map(fn ($id) => (int) $id)->values()->all()])->saveQuietly();
        if ($usuarios->isEmpty()) {
            $this->auditar($pase, 'pases_salida.escalada', ['paso' => $aprobacion->nombre, 'horas' => $horas, 'avisados' => [], 'sin_a_quien' => true]);

            return false;
        }
        $titulo = "Firma atrasada: pase {$pase->folio}";
        $texto = "El paso «{$aprobacion->nombre}» lleva ".$this->duracion($horas * 60).' sin firmar ('.$aprobacion->quienFirma().'). '
            .$pase->etiquetaMotivo().' · '.($pase->solicitante?->nombreCompleto() ?? 'sin solicitante').'. Dale seguimiento con quien debe firmar.';
        $this->avisar($pase, $usuarios, 'pase_firma_escalada', $titulo, $texto, null);
        $this->anotar($pase, 'Se avisó a '.$usuarios->pluck('name')->join(', ', ' y ').': el paso «'.$aprobacion->nombre.'» lleva '.$this->duracion($horas * 60).' sin firma', $usuarios);
        $this->auditar($pase, 'pases_salida.escalada', ['paso' => $aprobacion->nombre, 'horas' => $horas, 'avisados' => $usuarios->pluck('id')->all()]);

        return true;
    }

    /**
     * @return Collection<int, User>
     */
    public function aQuienEscalar(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): Collection
    {
        $actuales = $this->circuito->aprobadores($pase, $aprobacion)->pluck('id')->map(fn ($id) => (int) $id);
        $siguiente = $this->circuito->rondaActual($pase)
            ->first(fn (PaseSalidaAprobacion $a) => $a->orden > $aprobacion->orden && $a->estado === PaseSalidaAprobacion::PENDIENTE);
        $usuarios = $siguiente !== null
            ? $this->circuito->aprobadores($pase, $siguiente)->reject(fn (User $u) => $actuales->contains((int) $u->id))->values()
            : collect();
        if ($usuarios->isEmpty()) {
            $usuarios = $this->destinatarios->conPermiso((int) $pase->empresa_id, 'pases_salida.configurar', (int) $pase->sede_id)
                ->reject(fn (User $u) => $actuales->contains((int) $u->id))->values();
        }

        return $usuarios;
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function avisar(PaseSalida $pase, Collection $usuarios, string $tipo, string $titulo, string $texto, ?int $aprobacionId): void
    {
        $this->centro->avisar((int) $pase->empresa_id, $usuarios->pluck('id'), $tipo, [
            'titulo' => $titulo, 'texto' => $texto,
            'url' => route('pases-salida.show', $pase->id),
            // El recordatorio se resuelve con el aviso «Te toca firmar» del mismo paso
            'referencia_tipo' => $aprobacionId !== null ? 'pase_salida_aprobacion' : 'pase_salida_escalada',
            'referencia_id' => $aprobacionId ?? (int) $pase->id,
            'acciones' => [['etiqueta' => 'Abrir pase', 'abrir' => true]],
        ]);
        app(AvisosCorreo::class)->recepcion((int) $pase->empresa_id, 'pase_salida_espera', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir el pase', route('pases-salida.show', $pase->id)]]));
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function anotar(PaseSalida $pase, string $titulo, Collection $usuarios): void
    {
        PaseSalidaBitacora::create([
            'empresa_id' => $pase->empresa_id, 'pase_salida_id' => $pase->id, 'evento' => 'recordatorio', 'titulo' => mb_substr($titulo, 0, 150),
            'detalle' => ['avisados' => $usuarios->pluck('name')->all()], 'user_id' => null, 'usuario_nombre' => null, 'ip' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(PaseSalida $pase, string $evento, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $pase->empresa_id, 'user_id' => null, 'evento' => $evento,
            'auditable_type' => PaseSalida::class, 'auditable_id' => $pase->id, 'antes' => ['estado' => $pase->estado], 'despues' => $despues, 'ip' => null,
        ]);
    }
}
