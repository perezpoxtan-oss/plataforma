<?php

namespace App\Services\Autorizaciones;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Accesos\MovimientoNoPermitido;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\Entrada;
use Illuminate\Support\Facades\DB;

/**
 * «Autorizar por excepción»: el proveedor o contratista espera la autorización
 * del departamento, pero la caseta (con accesos.aprobar) lo deja pasar sin
 * ella, siempre con motivo. Se permite solo cuando:
 *  a) el departamento no tiene responsable en esa sede (no hubo solicitud);
 *  b) la solicitud ya se escaló o pasó el tiempo de espera configurado
 *     («Avisar al suplente y al jefe a los … minutos») sin respuesta; o
 *  c) se elige un motivo de la lista corta: «El responsable autorizó por
 *     teléfono», «Urgencia operativa» u «Otro» (con texto obligatorio).
 *
 * El acceso queda EN SITIO con autorizacion = «excepcion» y su motivo
 * (motivo_excepcion), la solicitud pendiente se cierra como autorizada por
 * la caseta (sus avisos pierden los botones), queda en la Bitácora de
 * auditoría y se avisa por campana al responsable del departamento.
 */
class Excepciones
{
    /** Motivo => texto que ven la caseta, la tarjeta y el responsable. */
    public const MOTIVOS = [
        'sin_responsable' => 'El departamento no tiene responsable en esta sede',
        'sin_respuesta' => 'El responsable no contestó a tiempo',
        'telefono' => 'El responsable autorizó por teléfono',
        'urgencia' => 'Urgencia operativa',
        'otro' => 'Otro',
    ];

    /** La lista corta: siempre disponibles (con el responsable avisado y sin escalar). */
    public const LISTA_CORTA = ['telefono', 'urgencia', 'otro'];

    public function __construct(
        private readonly Autorizaciones $autorizaciones,
        private readonly AjustesRecepcion $ajustes,
        private readonly CentroNotificaciones $notificaciones,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * Motivos que aplican a este acceso (vacío si no espera al departamento).
     *
     * @return array<string, string>
     */
    public function motivosPara(Acceso $acceso): array
    {
        if (! $acceso->esperaAlDepartamento()) {
            return [];
        }
        $solicitud = $this->solicitudPendiente($acceso);
        $aplican = [];
        if ($solicitud === null && ($acceso->departamento_id === null
            || ! $this->autorizaciones->tieneResponsables((int) $acceso->departamento_id, (int) $acceso->sede_id))) {
            $aplican[] = 'sin_responsable';
        }
        if ($solicitud !== null && $this->sinRespuestaATiempo($solicitud)) {
            $aplican[] = 'sin_respuesta';
        }

        return array_intersect_key(self::MOTIVOS, array_flip([...$aplican, ...self::LISTA_CORTA]));
    }

    /**
     * Deja pasar al proveedor o contratista por excepción. Devuelve el texto
     * del motivo («Urgencia operativa: …»).
     */
    public function autorizar(User $actor, Acceso $acceso, mixed $motivo, mixed $detalle): string
    {
        $motivos = $this->motivosPara($acceso);
        if ($motivos === []) {
            throw new MovimientoNoPermitido('Este acceso ya no espera la autorización del departamento. Revisa la lista.');
        }
        $motivo = is_string($motivo) ? $motivo : '';
        $detalle = mb_substr(trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($detalle))), 0, 250);
        if (! array_key_exists($motivo, $motivos)) {
            throw new MovimientoNoPermitido(array_key_exists($motivo, self::MOTIVOS)
                ? 'Ese motivo no aplica a este acceso: elige uno de la lista.'
                : 'Elige el motivo de la excepción.');
        }
        if ($motivo === 'otro' && $detalle === '') {
            throw new MovimientoNoPermitido('Con «Otro» escribe el motivo de la excepción.');
        }
        $texto = mb_substr(($motivo === 'otro' ? $detalle : self::MOTIVOS[$motivo].($detalle !== '' ? ': '.$detalle : '')), 0, 300);

        $solicitud = DB::transaction(function () use ($actor, $acceso, $texto) {
            $ahora = now();
            $hecho = Acceso::whereKey($acceso->id)->where('estado', 'pendiente')->update([
                'estado' => 'en_sitio', 'autorizacion' => Acceso::POR_EXCEPCION, 'motivo_excepcion' => $texto,
                'autorizado_at' => $ahora, 'autorizado_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => $ahora,
            ]);
            if ($hecho === 0) {
                throw new MovimientoNoPermitido('Este acceso ya no está pendiente de autorización (alguien más lo atendió). Revisa la lista.');
            }
            $solicitud = Autorizacion::where('acceso_id', $acceso->id)->where('estado', 'pendiente')->lockForUpdate()->first();
            $solicitud?->forceFill(['estado' => 'autorizada', 'respondida_en' => $ahora, 'respondida_por' => $actor->id, 'respuesta_medio' => 'caseta',
                'comentario' => mb_substr('Por excepción: '.$texto, 0, 500)])->save();

            return $solicitud;
        });

        $this->auditoria->auditar($actor, 'accesos.autorizado_excepcion', $acceso, ['estado' => 'pendiente'],
            ['estado' => 'en_sitio', 'por' => 'excepcion', 'motivo' => $texto, 'departamento_id' => $acceso->departamento_id]);
        if ($solicitud !== null) {
            $this->notificaciones->resolver('autorizacion', $solicitud->id);
            $this->auditoria->auditar($actor, 'autorizaciones.respondida', $solicitud, ['estado' => 'pendiente'], ['estado' => 'autorizada', 'medio' => 'caseta', 'excepcion' => $texto]);
        }
        $this->avisarResponsables($actor, $acceso, $solicitud, $texto);

        return $texto;
    }

    /** ¿La última autorización por excepción avisó a algún responsable? (para no decir «Avisamos…» si no hay a quién) */
    public bool $seAviso = false;

    /** Aviso por campana al responsable (o su delegado) de que pasó por excepción. */
    private function avisarResponsables(User $actor, Acceso $acceso, ?Autorizacion $solicitud, string $texto): void
    {
        $this->seAviso = false;
        if ($acceso->departamento_id === null) {
            return;
        }
        $avisar = $this->autorizaciones->aQuienAvisar((int) $acceso->departamento_id, (int) $acceso->sede_id)
            ->reject(fn (User $u) => $u->id === $actor->id)->pluck('id');
        if ($avisar->isEmpty()) {
            return;
        }
        $acceso->loadMissing('sede:id,nombre');
        $this->notificaciones->avisar((int) $acceso->empresa_id, $avisar, 'autorizacion_excepcion', [
            'titulo' => 'Pasó por excepción: '.$acceso->nombre,
            'texto' => $acceso->etiquetaTipo().($acceso->empresa_procedencia ? ' de '.$acceso->empresa_procedencia : '').'. Motivo: '.$texto
                .'. Autorizó en caseta: '.$actor->name.'. Sede: '.$acceso->sede?->nombre.'.',
            'url' => $solicitud !== null ? route('autorizaciones.show', $solicitud->id) : null,
            'referencia_tipo' => 'acceso', 'referencia_id' => $acceso->id,
        ]);
        $this->seAviso = true;
    }

    private function solicitudPendiente(Acceso $acceso): ?Autorizacion
    {
        return Autorizacion::where('acceso_id', $acceso->id)->where('estado', 'pendiente')->orderByDesc('id')->first();
    }

    /** b) Se escaló, o lleva al menos «Avisar al suplente y al jefe a los … minutos» sin respuesta. */
    private function sinRespuestaATiempo(Autorizacion $solicitud): bool
    {
        if ($solicitud->escalado_en !== null) {
            return true;
        }
        $empresa = Empresa::find($solicitud->empresa_id);
        $minutos = $empresa === null ? null : $this->ajustes->escalarMinutos($empresa);

        return $minutos !== null && $solicitud->solicitada_en !== null && $solicitud->solicitada_en->lte(now()->subMinutes($minutos));
    }
}
