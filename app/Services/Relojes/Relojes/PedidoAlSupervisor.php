<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Acceso;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use App\Support\AjustesCaseta;
use Illuminate\Support\Collection;

/**
 * La caseta pide al supervisor «Autorizar por excepción» (renglón 22; C-25,
 * B-05, DD-11, DD-13). Espera lo que el agente pidió con «Pedir al
 * supervisor» y la visita a un departamento sin quien autorice en la sede.
 * Con los minutos de la caseta (los de «Quién espera autorización»): se
 * recuerda al supervisor de la sede y después pasa al jefe de seguridad. Si
 * la empresa lo encendió, a partir de ahí el agente puede aprobar por
 * excepción con motivo y foto (Accesos\ExcepcionDelAgente).
 */
class PedidoAlSupervisor extends Reloj
{
    public const REFERENCIA = 'acceso';

    public function clave(): string
    {
        return 'caseta_supervisor';
    }

    public function nombre(): string
    {
        return 'La caseta espera al supervisor';
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
        return 'Responsables por departamento: minutos de espera de la caseta';
    }

    public function umbralesPropios($empresa): ?array
    {
        if (! $empresa instanceof Empresa) {
            return null;
        }
        $ajustes = app(AjustesRecepcion::class);

        return [$ajustes->recordarMinutos($empresa), $ajustes->escalarMinutos($empresa)];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Acceso::where('estado', 'pendiente')
            ->where(fn ($q) => $q->whereNotNull('supervisor_pedido_en')->orWhere('autorizacion', Acceso::SIN_RESPONSABLE))
            ->orderBy('entrada_at')->limit(500)->get(['id', 'sede_id', 'nombre', 'tipo', 'entrada_at', 'supervisor_pedido_en', 'creado_por', 'autorizacion'])
            ->map(fn (Acceso $a) => new Espera(self::REFERENCIA, (int) $a->id, $a->supervisor_pedido_en ?? $a->entrada_at ?? now(),
                $a->nombre.' espera tu autorización en caseta', route('accesos.index', ['pestana' => 'pendientes']).'#acceso-'.$a->id, (int) $a->sede_id,
                ['caseta' => $a->creado_por !== null ? (int) $a->creado_por : null, 'nombre' => $a->nombre]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'accesos.aprobar', $e->sedeId)->reject(fn (User $u) => (int) $u->id === $e->datos['caseta'])->values();
    }

    /** El jefe de seguridad de la sede (aunque ya haya recibido el primer aviso: ahora es el que no se silencia). */
    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId === null) {
            $jefes = app(Escalamiento::class)->jefesDeSeguridad((int) $empresaId, (int) $e->sedeId)->reject(fn (User $u) => (int) $u->id === $e->datos['caseta'])->values();
            if ($jefes->isNotEmpty()) {
                return $jefes;
            }
        }

        return parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    /** La caseta se entera de que ya se avisó al jefe y, si la empresa lo permite, de que puede aprobar por excepción. */
    public function alEscalar(Espera $e, ?int $empresaId, Collection $suplentes): void
    {
        if ($e->datos['caseta'] === null || $empresaId === null) {
            return;
        }
        $puede = AjustesCaseta::valor($empresaId, 'agente_excepcion') === 'si';
        app(CentroNotificaciones::class)->avisar($empresaId, [$e->datos['caseta']], 'autorizacion_escalada', [
            'titulo' => 'Nadie ha respondido: '.$e->datos['nombre'],
            'texto' => 'Ya avisamos a '.$suplentes->pluck('name')->join(', ', ' y ').'.'.($puede ? ' Si no puede esperar, puedes aprobar por excepción con motivo y foto.' : ''),
            'url' => route('accesos.index', ['pestana' => 'pendientes']).'#acceso-'.$e->referenciaId,
        ]);
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return ['Recordatorio: '.$e->titulo, 'Lleva '.MotorRelojes::duracion($minutos).' esperando. Confírmalo con quien lo recibe y autorízalo o recházalo en la bitácora.'];
    }

    public function textoEscalado(Espera $e, int $minutos): array
    {
        return ['Nadie ha respondido: '.$e->datos['nombre'].' lleva '.MotorRelojes::duracion($minutos).' en caseta', 'El supervisor no ha contestado. Autorízalo en la bitácora o llama a la caseta.'];
    }
}
