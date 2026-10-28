<?php

namespace App\Services\Novedades;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Novedad;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * C-28 (DD-15, D-03): un incidente se canaliza a un departamento
 * (Mantenimiento, RR. HH., Ama de llaves…), no solo a una persona de
 * Seguridad. Quien responde por ese departamento en la sede (la misma
 * cobertura de la Etapa 3: responsable o suplente con cuenta activa, permiso
 * «Responder» y la sede a su alcance, o su delegado) ve SOLO esos tickets:
 * los lee, agrega notas al Minuto a Minuto y «Atender» marca su parte. No
 * necesita permisos de la Bitácora completa.
 *
 * Reloj: el de la Etapa 4 (TicketsDeDepartamento en el motor «Reloj y
 * suplente»), con las horas por categoría de los tickets canalizados.
 *
 * Accidente de un colaborador: avisa al «Departamento que recibe accidentes
 * de personal» (Configuración → Bitácora de novedades) en la sede, si tiene
 * quien lo atienda. El formato de Accidente no cambia.
 */
class CanalizarDepartamento
{
    /** Valor de fábrica de «Departamento que recibe accidentes de personal»: el que se llama como Recursos Humanos. */
    public const PATRON_RRHH = '/recursos\s+humanos|rr\.?\s*hh|capital\s+humano|personal y n[oó]mina/iu';

    public function __construct(
        private readonly Autorizaciones $autorizaciones,
        private readonly CentroNotificaciones $centro,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * Departamentos activos para «o a un departamento» (con sus sedes para filtrar en el formulario).
     *
     * @return Collection<int, Departamento>
     */
    public function opciones(): Collection
    {
        return Departamento::with('sedes:sedes.id')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'todas_las_sedes']);
    }

    /** Departamento activo que aplica en la sede (el que ya tenía el ticket se conserva). */
    public function valido(mixed $id, int $sedeId, ?int $actual): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = (int) $id;
        if ($id === (int) $actual) {
            return $id;
        }
        if (! Departamento::where('activo', true)->aplicanEn([$sedeId])->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['departamento_id' => 'Elige un departamento activo de esa sede.']);
        }

        return $id;
    }

    /** Se canalizó (o cambió) el departamento: sus responsables reciben el aviso con «Atender». */
    public function avisar(Novedad $novedad, User $actor): void
    {
        $this->centro->resolver('novedad_departamento', (int) $novedad->id);
        if ($novedad->departamento_id === null || $novedad->resuelto()) {
            return;
        }
        $novedad->loadMissing(['departamento:id,nombre', 'sede:id,nombre']);
        $usuarios = $this->autorizaciones->aQuienAvisar((int) $novedad->departamento_id, (int) $novedad->sede_id)
            ->reject(fn (User $u) => (int) $u->id === (int) $actor->id);
        if ($usuarios->isEmpty()) {
            return;
        }
        $this->centro->avisar((int) $novedad->empresa_id, $usuarios->pluck('id'), 'novedad_departamento', [
            'titulo' => "Ticket {$novedad->folio()} para {$novedad->departamento?->nombre}",
            'texto' => $novedad->etiquetaCategoria().' · '.($novedad->sede?->nombre ?? '').' · '.$novedad->ubicacion.' — lo canalizó '.$actor->name.'.',
            'url' => route('novedades.departamento.show', $novedad->id),
            'referencia_tipo' => 'novedad_departamento', 'referencia_id' => (int) $novedad->id,
            'acciones' => [['etiqueta' => 'Atender', 'abrir' => true]],
        ]);
    }

    /**
     * Accidente de un colaborador: si nadie lo canalizó a un departamento, va
     * al «Departamento que recibe accidentes de personal» de Configuración
     * (revisión Etapa 5: ya no se reconoce por el nombre), si aplica en la
     * sede y alguien lo atiende ahí. Un residente (condominio,
     * fraccionamiento) no es personal: su accidente no va a RR. HH.
     */
    public function accidenteDeColaborador(Novedad $novedad, User $actor, ?int $colaboradorId = null): void
    {
        if ($novedad->departamento_id !== null || $novedad->resuelto()) {
            return;
        }
        if ($colaboradorId !== null && Colaborador::whereKey($colaboradorId)->where('tipo', Colaborador::RESIDENTE)->exists()) {
            return;
        }
        $id = app(AjustesNovedades::class)->departamentoAccidentes((int) $novedad->empresa_id);
        $rrhh = $id === null ? null : Departamento::where('activo', true)->aplicanEn([(int) $novedad->sede_id])->whereKey($id)->first(['id', 'nombre']);
        if ($rrhh === null || ! $this->autorizaciones->tieneResponsables((int) $rrhh->id, (int) $novedad->sede_id)) {
            return;
        }
        $novedad->forceFill(['departamento_id' => $rrhh->id, 'departamento_en' => now(), 'departamento_atendido_en' => null, 'departamento_atendido_por' => null])->saveQuietly();
        app(AdministradorNovedades::class)->anotar($novedad, $actor, 'Accidente de un colaborador: se avisó a '.$rrhh->nombre.' para que llene su parte.', 'sistema');
        $this->avisar($novedad, $actor);
    }

    // ------------------------------------------------------- Quién lo atiende

    /**
     * Pares [departamento, sede|null] que atiende el usuario (los de la cobertura de la Etapa 3).
     *
     * @return list<array{0: int, 1: ?int}>
     */
    public function pares(User $usuario): array
    {
        return $usuario->can('autorizaciones.responder') ? $this->autorizaciones->departamentosQueAtiende($usuario) : [];
    }

    /**
     * Tickets canalizados a los departamentos que atiende.
     *
     * @param  Builder<Novedad>  $q
     * @return Builder<Novedad>
     */
    public function limitar(Builder $q, User $usuario): Builder
    {
        $pares = $this->pares($usuario);
        if ($pares === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->whereNotNull('novedades.departamento_id')->where(function ($w) use ($pares) {
            foreach ($pares as [$departamento, $sede]) {
                $w->orWhere(fn ($x) => $x->where('novedades.departamento_id', $departamento)->when($sede !== null, fn ($y) => $y->where('novedades.sede_id', $sede)));
            }
        });
    }

    public function atiende(User $usuario, Novedad $novedad): bool
    {
        return $novedad->departamento_id !== null && collect($this->pares($usuario))
            ->contains(fn ($p) => (int) $p[0] === (int) $novedad->departamento_id && ($p[1] === null || (int) $p[1] === (int) $novedad->sede_id));
    }

    /** Nota del departamento en el Minuto a Minuto. */
    public function anotar(User $actor, Novedad $novedad, string $texto): void
    {
        $texto = trim($texto);
        if ($texto === '') {
            throw ValidationException::withMessages(['nota' => 'Escribe la nota.']);
        }
        if ($novedad->resuelto()) {
            throw ValidationException::withMessages(['nota' => 'El ticket ya está resuelto.']);
        }
        $novedad->loadMissing('departamento:id,nombre');
        app(AdministradorNovedades::class)->anotar($novedad, $actor, '['.mb_strtoupper((string) $novedad->departamento?->nombre).'] '.mb_substr($texto, 0, 2000));
        $this->auditoria->auditar($actor, 'novedades.nota_departamento', $novedad, null, ['departamento_id' => $novedad->departamento_id]);
    }

    /** «Atender»: el departamento hizo su parte. Se avisa a quien lo canalizó y a la persona de Seguridad que lo lleva. */
    public function atender(User $actor, Novedad $novedad, ?string $nota): void
    {
        if ($novedad->departamento_atendido_en !== null) {
            throw ValidationException::withMessages(['nota' => 'Este ticket ya se atendió.']);
        }
        if (($nota = trim((string) $nota)) !== '') {
            $this->anotar($actor, $novedad, $nota);
        }
        $novedad->forceFill(['departamento_atendido_en' => now(), 'departamento_atendido_por' => $actor->id])->saveQuietly();
        $novedad->loadMissing('departamento:id,nombre');
        app(AdministradorNovedades::class)->anotar($novedad, $actor, ($novedad->departamento?->nombre ?? 'El departamento').' atendió su parte.', 'sistema');
        $this->centro->resolver('novedad_departamento', (int) $novedad->id);
        $this->auditoria->auditar($actor, 'novedades.departamento_atendio', $novedad, ['departamento_atendido_en' => null], ['departamento_atendido_en' => now()->toIso8601String()]);
        $avisar = collect([$novedad->creado_por, $novedad->asignado_a])->filter()->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $actor->id)->unique()->values();
        if ($avisar->isNotEmpty()) {
            $this->centro->avisar((int) $novedad->empresa_id, User::whereIn('id', $avisar)->where('activo', true)->pluck('id'), 'novedad_departamento_atendida', [
                'titulo' => ($novedad->departamento?->nombre ?? 'El departamento')." atendió el ticket {$novedad->folio()}",
                'texto' => 'Atendió '.$actor->name.($nota !== '' ? ': «'.mb_substr($nota, 0, 200).'»' : '.'),
                'url' => route('novedades.index', ['abrir' => $novedad->id]),
            ]);
        }
    }
}
