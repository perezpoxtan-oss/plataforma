<?php

namespace App\Services\Suscripciones;

use App\Models\ConfiguracionPlataforma;
use App\Models\Suscripcion;
use App\Services\Comercial\AjustesComerciales;

/**
 * Tarea diaria de las suscripciones (plataforma:suscripciones --si-toca,
 * lanzada por despliegue/desplegar.sh en cada corrida del cron, en proceso,
 * sin exec ni proc_open):
 *
 *  - guarda el estado que dicen las fechas (por vencer, en gracia, solo consulta);
 *  - avisa al Administrador del cliente y al Super Administrador N días antes
 *    de vencer (Ajustes → «Avisar antes de vencer», por omisión 7 y 1), al
 *    vencer (empieza la gracia), a la mitad de la gracia y al pasar a solo
 *    consulta. La prueba gratis avisa igual, con sus textos.
 *
 * Cada aviso se manda una sola vez por vencimiento (suscripciones.avisos_enviados).
 * Con --si-toca corre una vez al día, después de las 8:00 (hora de la plataforma).
 */
class RevisionDiaria
{
    public const CLAVE = 'suscripciones_revision';

    public function __construct(
        private readonly Aprovisionamiento $aprovisionamiento,
        private readonly AvisosSuscripcion $avisos,
        private readonly AjustesComerciales $ajustes,
    ) {}

    public function toca(): bool
    {
        $ahora = now()->setTimezone(Suscripcion::ZONA);
        if ((int) $ahora->format('G') < 8) {
            return false;
        }
        $ultima = ConfiguracionPlataforma::where('clave', self::CLAVE)->value('valor');

        return ($ultima['fecha'] ?? null) !== $ahora->toDateString();
    }

    /**
     * @return array{estados: int, avisos: int}
     */
    public function ejecutar(bool $siToca = false): array
    {
        if ($siToca && ! $this->toca()) {
            return ['estados' => 0, 'avisos' => 0];
        }
        $estados = 0;
        $avisos = 0;
        Suscripcion::query()->with('empresa')->where('estado', '!=', Suscripcion::CANCELADA)->orderBy('id')
            ->chunkById(100, function ($lote) use (&$estados, &$avisos) {
                foreach ($lote as $s) {
                    if ($s->empresa === null || $s->empresa->trashed()) {
                        continue;
                    }
                    if ($this->aprovisionamiento->sincronizarEstado($s) !== null) {
                        $estados++;
                    }
                    $avisos += $this->avisar($s);
                }
            });

        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => self::CLAVE]);
        $registro->valor = ['fecha' => now()->setTimezone(Suscripcion::ZONA)->toDateString(), 'estados' => $estados, 'avisos' => $avisos];
        $registro->save();

        return ['estados' => $estados, 'avisos' => $avisos];
    }

    /** Manda el aviso que toca hoy (máximo uno por suscripción y por día). */
    public function avisar(Suscripcion $s): int
    {
        if ($s->suspendida) {
            return 0;
        }
        $enviados = (array) ($s->avisos_enviados ?? []);
        $vence = $s->vence_en->toDateString();
        $dias = $s->diasParaVencer();
        $empresa = $s->empresa?->nombre_comercial ?? 'El cliente';
        $prueba = $s->es_prueba;
        $quien = $prueba ? 'Tu prueba gratis' : 'Tu suscripción';
        $contacto = $this->ajustes->contacto();
        $fecha = $s->vence_en->format('d/m/Y');
        $gracia = $s->gracia_hasta?->format('d/m/Y') ?? $fecha;
        $aviso = null;
        $marcar = [];

        if ($dias >= 0) {
            // Antes de vencer: del más cercano que toque; los anteriores que no salieron ya no se mandan
            $pendientes = array_values(array_filter($this->ajustes->get('avisos_dias_antes'),
                fn (int $d) => $dias <= $d && ! in_array("antes-{$d}-{$vence}", $enviados, true)));
            if ($pendientes !== []) {
                $d = min($pendientes);
                $marcar = array_map(fn ($x) => "antes-{$x}-{$vence}", $pendientes);
                $cuando = $dias === 0 ? 'hoy' : ($dias === 1 ? 'mañana' : "en {$dias} días");
                $aviso = [
                    "{$quien} vence {$cuando}",
                    "{$quien} vence el {$fecha}. ".($prueba ? 'Para seguir usando la plataforma, contrata tu plan' : 'Para no interrumpir el servicio, renueva tu plan')
                        ." desde Empresas → Tu suscripción o contacta a {$contacto}.",
                    "{$empresa}: vence ".($dias === 0 ? 'hoy' : "en {$dias} ".($dias === 1 ? 'día' : 'días')),
                ];
            }
        } else {
            $estado = $s->estadoEfectivo();
            $mitad = $s->vence_en->copy()->addDays((int) max(1, ceil(($s->vence_en->diffInDays($s->gracia_hasta ?? $s->vence_en)) / 2)));
            if ($estado === Suscripcion::SOLO_CONSULTA && ! in_array("solo-{$vence}", $enviados, true)) {
                $marcar = ["solo-{$vence}", "vencio-{$vence}", "mitad-{$vence}"];
                $aviso = [
                    "{$quien} pasó a solo consulta",
                    "Terminó el periodo de gracia. Tu equipo puede ver y exportar la información, pero no registrar nada nuevo. Se reactiva en cuanto registremos tu pago: contacta a {$contacto}.",
                    "{$empresa} pasó a solo consulta",
                ];
            } elseif ($estado === Suscripcion::EN_GRACIA && Suscripcion::hoy()->gte($mitad) && ! in_array("mitad-{$vence}", $enviados, true)) {
                $marcar = ["mitad-{$vence}", "vencio-{$vence}"];
                $aviso = [
                    "{$quien} venció: quedan días de gracia",
                    "{$quien} venció el {$fecha}. Tienes hasta el {$gracia} para normalizar el pago; después la plataforma queda en solo consulta. Contacta a {$contacto}.",
                    "{$empresa}: a mitad del periodo de gracia (hasta el {$gracia})",
                ];
            } elseif ($estado === Suscripcion::EN_GRACIA && ! in_array("vencio-{$vence}", $enviados, true)) {
                $marcar = ["vencio-{$vence}"];
                $aviso = [
                    $prueba ? 'Terminó tu prueba gratis' : 'Tu suscripción venció',
                    ($prueba ? 'Tu prueba gratis terminó' : 'Tu suscripción venció')." el {$fecha}. Tienes hasta el {$gracia} para ".($prueba ? 'contratar tu plan' : 'normalizar el pago')
                        ."; después la plataforma queda en solo consulta (puedes ver y exportar, no registrar). Contacta a {$contacto}.",
                    "{$empresa}: ".($prueba ? 'terminó la prueba' : 'venció').", gracia hasta el {$gracia}",
                ];
            }
        }

        if ($aviso === null) {
            return 0;
        }
        [$titulo, $texto, $tituloPlataforma] = $aviso;
        $this->avisos->alCliente($s, $titulo, $texto);
        $this->avisos->alSuperadmin($s, 'suscripcion_aviso', $tituloPlataforma, $texto);
        $s->forceFill(['avisos_enviados' => array_slice(array_values(array_unique([...$enviados, ...$marcar])), -30)])->save();
        $this->aprovisionamiento->historial($s, 'aviso_enviado', $s->estado, $s->estado, 'Aviso: '.$titulo, ['claves' => $marcar], null);

        return 1;
    }
}
