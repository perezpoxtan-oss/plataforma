<?php

namespace App\Services\Relojes\Relojes;

use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Candidatos\Entrevistas;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Entrevista por evaluar (renglón 5; C-23): el recordatorio al entrevistador
 * (o su delegado) lo manda PlazosCandidatos; al doble del plazo pasa a quien
 * la canalizó en Recursos Humanos, que puede reprogramarla o canalizarla a otra
 * persona (D-01: los suplentes del departamento solo si pueden atenderla).
 */
class EntrevistasPorEvaluar extends Reloj
{
    public function clave(): string
    {
        return 'entrevistas';
    }

    public function nombre(): string
    {
        return 'Entrevistas por evaluar';
    }

    public function suplenteOmision(): string
    {
        return 'quien la canalizó en Recursos Humanos (puede volver a canalizarla); los suplentes del departamento solo si pueden atenderla';
    }

    public function modulos(): array
    {
        return ['candidatos'];
    }

    public function permiso(): ?string
    {
        return 'candidatos.editar';
    }

    public function recuerda(): bool
    {
        return false;
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Candidatos → Documentos y plazos (al doble del plazo pasa al suplente)';
    }

    public function umbralesPropios($empresa): ?array
    {
        $dias = $empresa instanceof Empresa ? app(PlazosCandidatos::class)->plazo($empresa, 'evaluacion_dias') : PlazosCandidatos::DEFECTO['evaluacion_dias'];

        return [$dias * 1440, $dias * 2880];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Postulacion::with('candidato:id,nombre_completo')->where('etapa', 'canalizado')->whereNotNull('entrevistador_id')->orderBy('id')->limit(500)->get()
            ->filter(fn (Postulacion $p) => $p->candidato !== null && ($p->cita_ahora ? $p->canalizado_en : $p->cita_en)?->lte(now()))
            ->map(fn (Postulacion $p) => new Espera(Entrevistas::REFERENCIA, (int) $p->id, $p->cita_ahora ? $p->canalizado_en : $p->cita_en,
                'Entrevista sin evaluar: '.$p->candidato->nombre_completo, route('entrevistas.show', $p->id), $p->sede_id !== null ? (int) $p->sede_id : null,
                ['entrevistador' => (int) $p->entrevistador_id, 'departamento' => $p->departamento_id, 'canalizo' => $p->canalizado_por, 'candidato' => (int) $p->candidato_id]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return app(Entrevistas::class)->aQuienAvisar($e->datos['entrevistador']);
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId !== null) {
            return parent::suplentes($e, $empresaId, $responsables, $rolId);
        }
        $ya = $responsables->pluck('id')->map(fn ($id) => (int) $id)->push($e->datos['entrevistador'])->all();
        $ids = collect();
        if ($e->datos['departamento'] !== null) {
            $ids = DepartamentoResponsable::where('departamento_id', $e->datos['departamento'])->where('es_suplente', true)
                ->where(fn ($q) => $q->whereNull('sede_id')->orWhere('sede_id', $e->sedeId))->pluck('user_id');
        }
        $ids = $ids->push($e->datos['canalizo'])->filter()->map(fn ($id) => (int) $id)->reject(fn ($id) => in_array($id, $ya, true))->unique();
        $usuarios = $ids->isEmpty() ? collect() : User::where('activo', true)->whereIn('id', $ids)->get();

        return $usuarios->isNotEmpty() ? $usuarios : parent::suplentes($e, $empresaId, $responsables, null);
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'entrevistas';
    }

    /** Le toca a quien entrevista (su renglón «Entrevistas por evaluar»; la delegación la aplica el motor). */
    public function leToca(User $actor, Espera $e): bool
    {
        return (int) $actor->id === $e->datos['entrevistador'] && app(Destinatarios::class)->alcanza($actor, 'candidatos.evaluar', $e->sedeId);
    }

    /**
     * D-01: la atiende quien puede evaluarla (el entrevistador o quien la recibe
     * por delegación, con candidatos.evaluar en la sede) o quien puede volver a
     * canalizarla o marcar «No se presentó» (Recursos Humanos: candidatos.editar
     * en la sede). Un suplente del departamento que no la entrevista no.
     */
    public function puedeAtender(User $actor, Espera $e): bool
    {
        $destinatarios = app(Destinatarios::class);
        if ($destinatarios->alcanza($actor, 'candidatos.editar', $e->sedeId)) {
            return true;
        }

        return $destinatarios->alcanza($actor, 'candidatos.evaluar', $e->sedeId)
            && in_array($e->datos['entrevistador'], app(Entrevistas::class)->titularesDe($actor), true);
    }

    /** Recursos Humanos la atiende desde la ficha del candidato. */
    public function urlSuplente(Espera $e): string
    {
        return isset($e->datos['candidato']) ? route('candidatos.show', $e->datos['candidato']) : $e->url;
    }
}
