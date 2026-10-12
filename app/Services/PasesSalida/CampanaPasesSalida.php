<?php

namespace App\Services\PasesSalida;

use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use Illuminate\Support\Collection;

/**
 * Avisos de los pases de salida en la campana (criterio 1 de
 * docs/criterios-ux.md), además del correo:
 *
 *  - «Te toca firmar el pase PS-…» a quien puede firmar el paso que se abrió
 *    (botón «Abrir pase»: aprobar pide firma, se hace en la ficha). Se resuelve
 *    cuando alguien firma, omite o rechaza ese paso, o se cancela el pase.
 *  - «Tu pase PS-… fue aprobado» / «… fue rechazado» a quien lo registró y al
 *    solicitante (si tiene usuario).
 *  - «Pase PS-… vencido — debió regresar el …» cada día que sigue vencido, a
 *    sus dueños y a quien aprueba en la sede de origen; el del día anterior
 *    queda leído y se resuelven todos cuando regresa.
 *  - «Un pase viene a tu sede» a la caseta destino (permiso «Firmar» en la
 *    sede destino) cuando sale hacia ella; se resuelve al confirmar la llegada.
 *
 * Referencias (para resolver): pase_salida_aprobacion (id de la aprobación),
 * pase_salida_vencido y pase_salida_llegada (id del pase).
 */
class CampanaPasesSalida
{
    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly Autorizador $autorizador,
        private readonly HoraLocal $hora,
    ) {}

    /**
     * @param  Collection<int, User>  $usuarios
     */
    public function porFirmar(PaseSalida $pase, PaseSalidaAprobacion $aprobacion, Collection $usuarios, int $total): void
    {
        $this->centro->avisar((int) $pase->empresa_id, $usuarios->pluck('id'), 'pase_por_firmar', [
            'titulo' => "Te toca firmar el pase {$pase->folio}",
            'texto' => "{$aprobacion->nombre} (paso {$aprobacion->orden} de {$total}) · {$pase->etiquetaMotivo()} · "
                .($pase->solicitante?->nombreCompleto() ?? 'sin solicitante').' → '.$pase->nombreDestino(),
            'url' => route('pases-salida.show', $pase->id),
            'referencia_tipo' => 'pase_salida_aprobacion',
            'referencia_id' => (int) $aprobacion->id,
            'acciones' => [['etiqueta' => 'Abrir pase', 'abrir' => true]],
        ]);
    }

    /** El paso ya se firmó, se omitió, se rechazó o el pase se canceló: el aviso pierde su botón. */
    public function resolverAprobacion(int $aprobacionId): void
    {
        $this->centro->resolver('pase_salida_aprobacion', $aprobacionId);
    }

    /**
     * Aprobado o rechazado (con su motivo), a los dueños del pase menos quien resolvió.
     */
    public function resultado(PaseSalida $pase, string $tipo, ?string $motivo, ?User $quien): void
    {
        $usuarios = $this->duenos($pase)->reject(fn (int $id) => $quien !== null && $id === (int) $quien->id);
        $rechazado = $tipo === 'rechazado';
        $this->centro->avisar((int) $pase->empresa_id, $usuarios, $rechazado ? 'pase_rechazado' : 'pase_aprobado', [
            'titulo' => "Tu pase {$pase->folio} fue ".($rechazado ? 'rechazado' : 'aprobado'),
            'texto' => $rechazado
                ? 'Motivo: '.($motivo ?? '—').' · Corrígelo y reenvíalo, o cancélalo.'
                : 'Quedó «Aprobado, listo para salir»: la caseta de '.($pase->sede?->nombre ?? 'origen').' ya puede registrar la salida.',
            'url' => route('pases-salida.show', $pase->id),
            'referencia_tipo' => 'pase_salida_resultado',
            'referencia_id' => (int) $pase->id,
        ]);
    }

    /**
     * Recordatorio de vencido (una vez al día por pase): el del día anterior queda leído.
     *
     * @return int usuarios avisados
     */
    public function vencido(PaseSalida $pase): int
    {
        $this->centro->resolver('pase_salida_vencido', (int) $pase->id);
        $usuarios = $this->duenos($pase)->merge($this->conPermiso((int) $pase->empresa_id, 'pases_salida.aprobar', (int) $pase->sede_id))->unique()->values();
        $fecha = PaseSalida::dia($pase->fecha_tentativa_regreso);

        return count($this->centro->avisar((int) $pase->empresa_id, $usuarios, 'pase_vencido', [
            'titulo' => "Pase {$pase->folio} vencido — debió regresar el ".($fecha ? $this->hora->formatear($fecha, 'd/m/Y') : '—'),
            'texto' => ($pase->solicitante?->nombreCompleto() ?? 'Sin solicitante').' · '.$pase->etiquetaMotivo().' · está en '.$pase->nombreDestino().'. Dale seguimiento.',
            'url' => route('pases-salida.show', $pase->id),
            'referencia_tipo' => 'pase_salida_vencido',
            'referencia_id' => (int) $pase->id,
        ]));
    }

    /** Ya regresó (o se cerró con faltantes): no hay vencido que recordar. */
    public function resolverVencido(PaseSalida $pase): void
    {
        $this->centro->resolver('pase_salida_vencido', (int) $pase->id);
    }

    /**
     * El pase salió hacia otra sede: la caseta destino sabe que viene y qué hará al llegar.
     */
    public function llega(PaseSalida $pase, ?User $quien): void
    {
        if ($pase->destino_tipo !== 'sede' || $pase->sede_destino_id === null) {
            return;
        }
        $usuarios = $this->conPermiso((int) $pase->empresa_id, 'pases_salida.firmar', (int) $pase->sede_destino_id)
            ->reject(fn (int $id) => $quien !== null && $id === (int) $quien->id);
        $articulos = (int) ($pase->articulos_count ?? $pase->articulos()->count());
        $this->centro->avisar((int) $pase->empresa_id, $usuarios, 'pase_llega', [
            'titulo' => "Un pase viene a tu sede: {$pase->folio}",
            'texto' => 'Sale de '.($pase->sede?->nombre ?? 'origen').' hacia '.($pase->sedeDestino?->nombre ?? 'tu sede')." con {$articulos} "
                .($articulos === 1 ? 'artículo' : 'artículos').'. Cuando llegue, toca «Confirmar llegada a esta sede».',
            'url' => route('pases-salida.show', $pase->id),
            'referencia_tipo' => 'pase_salida_llegada',
            'referencia_id' => (int) $pase->id,
        ]);
    }

    public function resolverLlegada(PaseSalida $pase): void
    {
        $this->centro->resolver('pase_salida_llegada', (int) $pase->id);
    }

    /**
     * Quien registró el pase y los usuarios ligados al solicitante.
     *
     * @return Collection<int, int>
     */
    public function duenos(PaseSalida $pase): Collection
    {
        return User::where('empresa_id', $pase->empresa_id)->where('activo', true)
            ->where(fn ($q) => $q->where('id', $pase->creado_por)->orWhere('colaborador_id', $pase->colaborador_id))
            ->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Usuarios activos de la empresa con un permiso que alcance esa sede.
     *
     * @return Collection<int, int>
     */
    public function conPermiso(int $empresaId, string $permiso, ?int $sedeId): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->get()
            ->filter(function (User $u) use ($permiso, $sedeId) {
                $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

                return $efectivo !== null && ($efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
                    || ($sedeId !== null && in_array($sedeId, $efectivo->sedes, true)));
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}
