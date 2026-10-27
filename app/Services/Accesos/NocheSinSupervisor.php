<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Relojes\ExcepcionesPorRevisar;
use App\Services\Relojes\Relojes\PedidoAlSupervisor;
use App\Support\AjustesCaseta;
use App\Support\Entrada;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * La caseta de noche (C-25, B-05, DD-13): el agente no tiene «Confirmar
 * Autorización» ni «Autorizar por excepción», y a veces no hay supervisor.
 *
 *  1. «Pedir al supervisor» (un toque en la tarjeta pendiente): el aviso
 *     llega a la campana y a Mis pendientes del supervisor de la sede, con
 *     reloj; si no responde, pasa al jefe de seguridad (PedidoAlSupervisor).
 *  2. Si nadie responde a tiempo y la empresa lo encendió
 *     (Configuración → Caseta y accesos: «Noche sin supervisor»), el agente
 *     puede aprobar por excepción: motivo y foto obligatorios, aviso inmediato
 *     al supervisor y al jefe, y el caso queda marcado para revisarlo
 *     (ExcepcionesPorRevisar; «Atender» lo da por revisado).
 *
 * El flujo normal del agente no cambia: estos botones solo aparecen en una
 * tarjeta que ya está pendiente.
 */
class NocheSinSupervisor
{
    public const TIPO_PEDIDO = 'excepcion_pedida';

    public const TIPO_AGENTE = 'excepcion_agente';

    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly AdministradorRoles $auditoria,
        private readonly Destinatarios $destinatarios,
    ) {}

    /** ¿Espera a alguien que solo el supervisor puede destrabar? */
    public function puedePedir(Acceso $a): bool
    {
        return $a->estado === 'pendiente' && $a->supervisor_pedido_en === null;
    }

    public function pedir(User $actor, Acceso $a): void
    {
        if ($a->estado !== 'pendiente') {
            throw new MovimientoNoPermitido('Este acceso ya no está pendiente (alguien más lo atendió). Revisa la lista.');
        }
        if (Acceso::whereKey($a->id)->where('estado', 'pendiente')->whereNull('supervisor_pedido_en')
            ->update(['supervisor_pedido_en' => now(), 'actualizado_por' => $actor->id, 'updated_at' => now()]) === 0) {
            throw new MovimientoNoPermitido('Ya se le pidió al supervisor. Su reloj sigue corriendo en la tarjeta.');
        }
        $a->loadMissing('sede:id,nombre');
        $supervisores = $this->destinatarios->conPermiso((int) $a->empresa_id, 'accesos.aprobar', (int) $a->sede_id)
            ->reject(fn (User $u) => $u->id === $actor->id);
        $this->centro->avisar((int) $a->empresa_id, $supervisores->pluck('id'), self::TIPO_PEDIDO, [
            'titulo' => 'La caseta te pide autorizar: '.$a->nombre,
            'texto' => $a->etiquetaTipo().($a->empresa_procedencia ? ' de '.$a->empresa_procedencia : '').' espera en '.($a->sede?->nombre ?? 'la caseta')
                .'. Lo pidió '.$actor->name.'. Confírmalo y autorízalo (o recházalo) en la bitácora.',
            'url' => route('accesos.index', ['pestana' => 'pendientes']).'#acceso-'.$a->id,
            'referencia_tipo' => PedidoAlSupervisor::REFERENCIA, 'referencia_id' => (int) $a->id,
            'acciones' => [['etiqueta' => 'Atender', 'abrir' => true]],
        ]);
        $this->auditoria->auditar($actor, 'accesos.supervisor_pedido', $a, ['estado' => 'pendiente'], ['avisados' => $supervisores->pluck('id')->all()]);
    }

    /**
     * ¿El agente ya puede aprobar por excepción? Solo si la empresa lo
     * encendió y nadie respondió en los minutos de escalamiento de la caseta.
     */
    public function agentePuedeAprobar(Acceso $a, ?Empresa $empresa = null): bool
    {
        $empresa ??= Empresa::find($a->empresa_id);
        if ($empresa === null || $a->estado !== 'pendiente' || AjustesCaseta::valor($empresa, 'agente_excepcion') !== 'si') {
            return false;
        }
        $desde = $a->supervisor_pedido_en ?? ($a->autorizacion === Acceso::SIN_RESPONSABLE || $a->esperaAlDepartamento() ? $a->entrada_at : null);
        if ($desde === null) {
            return false;
        }

        // Primero el tiempo (sin consultar); solo si no ha pasado, si el motor ya lo escaló
        return $desde->lte(now()->subMinutes(app(AjustesRecepcion::class)->escalarMinutos($empresa)))
            || app(MotorRelojes::class)->escalado('caseta_supervisor', PedidoAlSupervisor::REFERENCIA, (int) $a->id);
    }

    /** Minutos que faltan para que el agente pueda aprobar (para el texto de la tarjeta). */
    public function minutosParaAprobar(Acceso $a, Empresa $empresa): ?int
    {
        $desde = $a->supervisor_pedido_en ?? ($a->autorizacion === Acceso::SIN_RESPONSABLE || $a->esperaAlDepartamento() ? $a->entrada_at : null);
        if ($desde === null) {
            return null;
        }

        return (int) max(0, app(AjustesRecepcion::class)->escalarMinutos($empresa) - intdiv(now()->getTimestamp() - $desde->getTimestamp(), 60));
    }

    /**
     * El agente aprueba por excepción: motivo y foto obligatorios (si la
     * caseta ya tomó la foto de la persona al registrarla, esa sirve).
     */
    public function aprobarAgente(User $actor, Acceso $a, mixed $motivo, mixed $foto): string
    {
        if (! $this->agentePuedeAprobar($a)) {
            throw new MovimientoNoPermitido('Todavía no puedes aprobar por excepción: espera la respuesta del supervisor o del jefe de seguridad.');
        }
        $motivo = mb_substr(trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($motivo))), 0, 250);
        if (mb_strlen($motivo) < 5) {
            throw new MovimientoNoPermitido('Escribe el motivo de la excepción (por ejemplo: «fuga de gas, la pipa no puede esperar»).');
        }
        $tieneFoto = $foto instanceof UploadedFile;
        if (! $tieneFoto && ! $a->foto_persona) {
            throw new MovimientoNoPermitido('Toma la foto de la persona: sin foto no se puede aprobar por excepción.');
        }
        $ruta = $tieneFoto ? app(DocumentosCandidato::class)->guardarFoto((int) $a->empresa_id, $foto, 'foto_persona') : null;
        $texto = 'Agente sin supervisor: '.$motivo;

        $solicitud = DB::transaction(function () use ($actor, $a, $texto, $ruta) {
            $ahora = now();
            $hecho = Acceso::whereKey($a->id)->where('estado', 'pendiente')->update(array_filter([
                'estado' => 'en_sitio', 'autorizacion' => Acceso::POR_EXCEPCION, 'motivo_excepcion' => mb_substr($texto, 0, 300),
                'autorizado_at' => $ahora, 'autorizado_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => $ahora,
                'excepcion_por_revisar' => true, 'foto_persona' => $ruta,
            ], fn ($v) => $v !== null));
            if ($hecho === 0) {
                throw new MovimientoNoPermitido('Este acceso ya no está pendiente (alguien más lo atendió). Revisa la lista.');
            }
            $solicitud = Autorizacion::where('acceso_id', $a->id)->where('estado', 'pendiente')->lockForUpdate()->first();
            $solicitud?->forceFill(['estado' => 'autorizada', 'respondida_en' => $ahora, 'respondida_por' => $actor->id, 'respuesta_medio' => 'caseta',
                'comentario' => mb_substr('Por excepción: '.$texto, 0, 500)])->save();

            return $solicitud;
        });

        $this->centro->resolver(PedidoAlSupervisor::REFERENCIA, (int) $a->id);
        if ($solicitud !== null) {
            $this->centro->resolver('autorizacion', (int) $solicitud->id);
        }
        $this->auditoria->auditar($actor, 'accesos.autorizado_excepcion_agente', $a, ['estado' => 'pendiente'],
            ['estado' => 'en_sitio', 'por' => 'excepcion_agente', 'motivo' => $texto, 'foto' => $tieneFoto ? 'nueva' : 'del registro']);

        // Aviso inmediato al supervisor y al jefe de seguridad de la sede (no se puede silenciar)
        $a->loadMissing('sede:id,nombre');
        $avisar = $this->destinatarios->conPermiso((int) $a->empresa_id, 'accesos.aprobar', (int) $a->sede_id)
            ->concat(app(Escalamiento::class)->jefesDeSeguridad((int) $a->empresa_id, (int) $a->sede_id))
            ->unique('id')->reject(fn (User $u) => $u->id === $actor->id);
        $this->centro->avisar((int) $a->empresa_id, $avisar->pluck('id'), self::TIPO_AGENTE, [
            'titulo' => 'El agente aprobó por excepción: '.$a->nombre,
            'texto' => 'Nadie respondió a tiempo. Motivo: '.$motivo.'. Aprobó: '.$actor->name.' en '.($a->sede?->nombre ?? 'la caseta').'. Revisa el caso y toca «Atender».',
            'url' => route('accesos.index', ['excepciones' => 1]).'#acceso-'.$a->id,
            'referencia_tipo' => ExcepcionesPorRevisar::REFERENCIA, 'referencia_id' => (int) $a->id,
            'acciones' => [['etiqueta' => 'Atender', 'url' => route('accesos.revisar-excepcion', $a->id), 'campos' => ['_method' => 'PATCH'], 'estilo' => 'aceptar']],
        ]);

        return $motivo;
    }

    /** «Atender»: el supervisor revisó la excepción del agente. */
    public function revisar(User $actor, Acceso $a): void
    {
        if (Acceso::whereKey($a->id)->where('excepcion_por_revisar', true)->whereNull('excepcion_revisada_en')
            ->update(['excepcion_revisada_en' => now(), 'excepcion_revisada_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => now()]) === 0) {
            throw new MovimientoNoPermitido('Esta excepción ya se había revisado.');
        }
        $this->centro->resolver(ExcepcionesPorRevisar::REFERENCIA, (int) $a->id);
        $this->auditoria->auditar($actor, 'accesos.excepcion_revisada', $a, ['excepcion_por_revisar' => true], ['revisada' => true]);
    }
}
