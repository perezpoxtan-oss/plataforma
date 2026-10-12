<?php

namespace App\Services\Novedades;

use App\Mail\AvisoRecepcion;
use App\Models\Novedad;
use App\Models\RecorridoPc;
use App\Models\RevisionRecorridoPc;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Recepcion\Destinatarios;
use Illuminate\Support\Collection;

/**
 * Avisos de la Bitácora de novedades y de los Recorridos de Protección Civil
 * (criterio 1 de docs/criterios-ux.md; ADR-0013). Campana siempre; correo
 * solo si la empresa enciende el aviso en Configuración → Avisos por correo
 * (por omisión apagado: nadie recibe correos nuevos sin pedirlos).
 *
 *  - «Te canalizaron el ticket #…» a quien se elige en «¿A quién se
 *    canaliza?» (al despachar o al cambiarlo en el expediente). Se resuelve
 *    al cambiar de persona o al cerrar el caso.
 *  - «Ticket #… resuelto» a quien lo levantó y a quien se le canalizó (si
 *    no fueron quienes lo cerraron).
 *  - «Ticket #… pendiente para tu turno»: al marcarlo, a su dueño (la
 *    persona canalizada); al iniciar el siguiente turno de la sede, al dueño
 *    o, si no tiene, al personal de Seguridad de la sede; y si ese turno
 *    termina sin atenderlo, a quien puede reabrir casos en la sede (jefe de
 *    seguridad). Ver TurnosNovedades.
 *  - «Recorrido #… encontró una falla» al jefe de seguridad de la sede y a
 *    quien administra los equipos de Protección Civil, en la primera falla y
 *    al finalizar con hallazgos.
 *
 * Referencias para resolver: novedad_canalizada, novedad_turno (id del ticket)
 * y recorrido_pc_hallazgo (id del recorrido).
 */
class AvisosNovedades
{
    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly Destinatarios $destinatarios,
    ) {}

    // ------------------------------------------------------- Canalizar y cerrar

    /** Se canalizó (o se cambió a quién): el anterior pierde el aviso, el nuevo lo recibe. */
    public function canalizado(Novedad $novedad, User $actor): void
    {
        $this->centro->resolver('novedad_canalizada', (int) $novedad->id);
        if ($novedad->asignado_a === null || (int) $novedad->asignado_a === (int) $actor->id || $novedad->resuelto()) {
            return;
        }
        $usuario = User::where('activo', true)->find($novedad->asignado_a);
        if ($usuario === null) {
            return;
        }
        $titulo = "Te canalizaron el ticket {$novedad->folio()}".$this->modulo($novedad);
        $texto = $novedad->etiquetaCategoria().' · '.($novedad->sede?->nombre ?? '').' · '.$novedad->ubicacion.' — lo canalizó '.$actor->name.'.';
        $this->centro->avisar((int) $novedad->empresa_id, [$usuario->id], 'novedad_canalizada', [
            'titulo' => $titulo, 'texto' => $texto,
            'url' => route('novedades.index', ['abrir' => $novedad->id]),
            'referencia_tipo' => 'novedad_canalizada', 'referencia_id' => (int) $novedad->id,
            'acciones' => [['etiqueta' => 'Atender', 'abrir' => true]],
        ]);
        $this->correo($novedad, 'novedad_canalizada', [$usuario], $titulo, [$texto, 'Ábrelo en '.app(AjustesNovedades::class)->bitacoraConArticulo((int) $novedad->empresa_id).' para darle seguimiento.']);
    }

    /** Caso cerrado: quien lo levantó y quien lo tenía canalizado se enteran del resultado. */
    public function resuelto(Novedad $novedad, User $actor): void
    {
        $this->centro->resolver('novedad_canalizada', (int) $novedad->id);
        $this->centro->resolver('novedad_turno', (int) $novedad->id);
        $ids = collect([$novedad->creado_por, $novedad->asignado_a])->filter()->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) $actor->id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $this->centro->avisar((int) $novedad->empresa_id, User::whereIn('id', $ids)->where('activo', true)->pluck('id'), 'novedad_resuelta', [
            'titulo' => "Ticket {$novedad->folio()} resuelto".$this->modulo($novedad),
            'texto' => 'Lo cerró '.$actor->name.'. Resolución: '.($novedad->resolucion ?? '—'),
            'url' => route('novedades.index', ['abrir' => $novedad->id]),
            'referencia_tipo' => 'novedad_resuelta', 'referencia_id' => (int) $novedad->id,
        ]);
    }

    // ------------------------------------------------------- Pendiente de turno

    /** Al marcarlo «Pendiente de turno»: su dueño (si lo tiene y no es quien lo marcó) lo sabe de inmediato. */
    public function pendienteTurno(Novedad $novedad, User $actor, ?string $turno): void
    {
        $this->centro->resolver('novedad_turno', (int) $novedad->id);
        if ($novedad->asignado_a === null || (int) $novedad->asignado_a === (int) $actor->id) {
            return;
        }
        $this->centro->avisar((int) $novedad->empresa_id, [(int) $novedad->asignado_a], 'novedad_turno', [
            'titulo' => "Ticket {$novedad->folio()} pendiente para tu turno".$this->modulo($novedad),
            'texto' => $actor->name.' lo dejó para '.($turno !== null ? "el turno {$turno}" : 'el siguiente turno').' · '.$novedad->etiquetaCategoria().' · '.$novedad->ubicacion,
            'url' => route('novedades.index', ['abrir' => $novedad->id]),
            'referencia_tipo' => 'novedad_turno', 'referencia_id' => (int) $novedad->id,
            'acciones' => [['etiqueta' => 'Atender', 'abrir' => true]],
        ]);
    }

    /**
     * Empezó el turno que lo recibe: al dueño o, sin dueño, al personal de Seguridad de la sede.
     *
     * @return int usuarios avisados
     */
    public function inicioDeTurno(Novedad $novedad, ?string $turno, ?User $quienLoDejo = null): int
    {
        $usuarios = $this->duenoOPersonal($novedad)->reject(fn (User $u) => $quienLoDejo !== null && (int) $u->id === (int) $quienLoDejo->id)->values();
        $titulo = "Pendiente de turno: ticket {$novedad->folio()}".$this->modulo($novedad);
        $texto = ($turno !== null ? "Empezó el turno {$turno}" : 'Empezó el siguiente turno').' y este ticket sigue esperando: '
            .$novedad->etiquetaCategoria().' · '.$novedad->ubicacion.($novedad->asignado_a === null ? ' (sin dueño: lo puede atender cualquiera del turno).' : '.');
        $this->centro->resolver('novedad_turno', (int) $novedad->id);
        $avisados = $this->centro->avisar((int) $novedad->empresa_id, $usuarios->pluck('id'), 'novedad_turno', [
            'titulo' => $titulo, 'texto' => $texto,
            'url' => route('novedades.index', ['abrir' => $novedad->id]),
            'referencia_tipo' => 'novedad_turno', 'referencia_id' => (int) $novedad->id,
            'acciones' => [['etiqueta' => 'Atender', 'abrir' => true]],
        ]);
        $this->correo($novedad, 'novedad_canalizada', $usuarios, $titulo, [$texto]);

        return count($avisados);
    }

    /**
     * El turno que lo recibió terminó y nadie lo atendió: al jefe de seguridad de la sede.
     *
     * @return int usuarios avisados
     */
    public function finDeTurno(Novedad $novedad, ?string $turno): int
    {
        $jefes = $this->destinatarios->conPermiso((int) $novedad->empresa_id, 'novedades.reabrir', (int) $novedad->sede_id);
        $titulo = "Nadie atendió el ticket {$novedad->folio()}".$this->modulo($novedad);
        $texto = ($turno !== null ? "Terminó el turno {$turno}" : 'Terminó el turno').' y el ticket sigue «Pendiente de turno»'
            .($novedad->asignado ? ' (dueño: '.$novedad->asignado->name.')' : ' sin dueño').'. '.$novedad->etiquetaCategoria().' · '.$novedad->ubicacion;
        $avisados = $this->centro->avisar((int) $novedad->empresa_id, $jefes->pluck('id'), 'novedad_turno_escalada', [
            'titulo' => $titulo, 'texto' => $texto,
            'url' => route('novedades.index', ['abrir' => $novedad->id]),
            'referencia_tipo' => 'novedad_turno', 'referencia_id' => (int) $novedad->id,
        ]);
        $this->correo($novedad, 'novedad_canalizada', $jefes, $titulo, [$texto]);

        return count($avisados);
    }

    /** Ya no está pendiente de turno (se atendió o se cerró): los avisos pierden su botón. */
    public function turnoAtendido(Novedad $novedad): void
    {
        $this->centro->resolver('novedad_turno', (int) $novedad->id);
    }

    // ------------------------------------------------------ Recorridos de PC

    /** Primera falla del recorrido: el jefe y quien administra los equipos se enteran sin revisar la bitácora. */
    public function hallazgo(RecorridoPc $recorrido, RevisionRecorridoPc $revision, Novedad $ticket, User $actor): int
    {
        $usuarios = $this->interesadosRecorrido($recorrido, $actor);
        $titulo = "Recorrido {$recorrido->folio()} encontró una falla";
        $texto = $revision->resumenHallazgo().' · Ticket '.$ticket->folio().' ('.$ticket->etiquetaCategoria().').';

        return $this->avisarRecorrido($recorrido, $ticket, $usuarios, $titulo, $texto);
    }

    /** Al finalizar con hallazgos: el resumen (el aviso de la primera falla queda leído). */
    public function recorridoConHallazgos(RecorridoPc $recorrido, int $fallas, User $actor): int
    {
        $ticket = $recorrido->novedad_id !== null ? Novedad::find($recorrido->novedad_id) : null;
        if ($ticket === null) {
            return 0;
        }
        $this->centro->resolver('recorrido_pc_hallazgo', (int) $recorrido->id);
        $usuarios = $this->interesadosRecorrido($recorrido, $actor);
        $titulo = "Recorrido {$recorrido->folio()} terminó con {$fallas} ".($fallas === 1 ? 'hallazgo' : 'hallazgos');
        $texto = ($recorrido->sede?->nombre ?? '').' · Los equipos con falla están en el ticket '.$ticket->folio().' ('.$ticket->etiquetaCategoria().').';

        return $this->avisarRecorrido($recorrido, $ticket, $usuarios, $titulo, $texto);
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Si la empresa le dio otro nombre al módulo («Incidentes»), los avisos
     * lo dicen: «Te canalizaron el ticket #00012 · Incidentes». Con el
     * nombre de siempre, el título queda igual que antes.
     */
    private function modulo(Novedad $novedad): string
    {
        $ajustes = app(AjustesNovedades::class);

        return $ajustes->nombrePropio((int) $novedad->empresa_id) ? ' · '.$ajustes->nombreMenu((int) $novedad->empresa_id) : '';
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function avisarRecorrido(RecorridoPc $recorrido, Novedad $ticket, Collection $usuarios, string $titulo, string $texto): int
    {
        $avisados = $this->centro->avisar((int) $recorrido->empresa_id, $usuarios->pluck('id'), 'recorrido_pc_hallazgo', [
            'titulo' => $titulo, 'texto' => $texto,
            'url' => route('novedades.index', ['abrir' => $ticket->id]),
            'referencia_tipo' => 'recorrido_pc_hallazgo', 'referencia_id' => (int) $recorrido->id,
        ]);
        $this->correo($ticket, 'hallazgo_recorrido_pc', $usuarios, $titulo, [$texto]);

        return count($avisados);
    }

    /**
     * Jefe de seguridad (puede reabrir casos) y quien administra los equipos de PC en la sede, menos quien recorre.
     *
     * @return Collection<int, User>
     */
    private function interesadosRecorrido(RecorridoPc $recorrido, User $actor): Collection
    {
        $empresa = (int) $recorrido->empresa_id;
        $sede = (int) $recorrido->sede_id;

        return $this->destinatarios->conPermiso($empresa, 'novedades.reabrir', $sede)
            ->concat($this->destinatarios->conPermiso($empresa, 'equipos_pc.editar', $sede))
            ->reject(fn (User $u) => (int) $u->id === (int) $actor->id)
            ->unique('id')->values();
    }

    /**
     * Dueño del ticket o, si no tiene (o ya no está activo), el personal de Seguridad de la sede.
     *
     * @return Collection<int, User>
     */
    private function duenoOPersonal(Novedad $novedad): Collection
    {
        if ($novedad->asignado_a !== null) {
            $dueno = User::where('activo', true)->whereKey($novedad->asignado_a)->get();
            if ($dueno->isNotEmpty()) {
                return $dueno;
            }
        }
        $ids = app(AdministradorNovedades::class)->personalCanalizable([(int) $novedad->sede_id])->pluck('id');

        return User::whereIn('id', $ids)->where('activo', true)->get();
    }

    /**
     * @param  iterable<User>  $usuarios
     * @param  list<string>  $lineas
     */
    private function correo(Novedad $novedad, string $clave, iterable $usuarios, string $titulo, array $lineas): void
    {
        $correos = collect($usuarios)->pluck('email')->filter()->values()->all();
        if ($correos === []) {
            return;
        }
        app(AvisosCorreo::class)->recepcion((int) $novedad->empresa_id, $clave, $correos,
            new AvisoRecepcion($titulo, $lineas, [['Abrir el ticket', route('novedades.index', ['abrir' => $novedad->id])]]));
    }
}
