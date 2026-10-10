<?php

namespace App\Services\Candidatos;

use App\Models\Candidato;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Collection;

/**
 * Postulaciones (fase 1 de candidatos): una ficha por persona y una
 * postulación por cada vez que aplica.
 *
 * QUÉ COLUMNA MANDA: la postulación. candidatos.etapa y las demás columnas de
 * self::ESPEJO son un espejo de la postulación ACTIVA (la más reciente de la
 * ficha) para que las listas, filtros, contadores, el panel de Recepción y la
 * exportación sigan funcionando igual. Todo cambio de etapa o de fechas se
 * escribe primero en la postulación y luego se refleja en la ficha con
 * reflejar(); nunca al revés, salvo cuando Recursos Humanos edita en la
 * ficha a qué aplica (desdeFicha()). La fase 2 quitará el espejo.
 */
class Postulaciones
{
    /** Columnas de la postulación activa que se copian a la ficha. */
    public const ESPEJO = ['sede_id', 'vacante_id', 'departamento_id', 'puesto_id', 'vacante', 'etapa', 'motivo_descarte',
        'revision_en', 'aprobado_rh_en', 'entrevista_en', 'decision_en', 'decision_por', 'enviado_departamento_en',
        'respuesta_departamento_en', 'contratado_en', 'colaborador_id'];

    /** Lo que se edita desde la ficha y pasa a la postulación activa. */
    public const DESDE_FICHA = ['departamento_id', 'puesto_id', 'vacante', 'vacante_id'];

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /** La postulación activa (la más reciente), abierta o no. */
    public function activa(Candidato $c): ?Postulacion
    {
        return Postulacion::where('candidato_id', $c->id)->orderByDesc('id')->first();
    }

    /** La postulación que sigue en proceso (si hay). */
    public function abierta(Candidato $c): ?Postulacion
    {
        return Postulacion::where('candidato_id', $c->id)->whereIn('etapa', Candidato::ABIERTAS)->orderByDesc('id')->first();
    }

    /**
     * La activa, o una nueva con lo que tenga la ficha (fichas capturadas antes
     * de existir las postulaciones y que la migración no alcanzó).
     */
    public function asegurar(Candidato $c): Postulacion
    {
        return $this->activa($c) ?? $this->nuevaDesdeFicha($c);
    }

    /**
     * Nueva postulación para una ficha: queda como la activa y la ficha la refleja.
     *
     * @param  array{sede_id?: int, vacante_id?: ?int, departamento_id?: ?int, puesto_id?: ?int, vacante?: ?string}  $datos
     */
    public function crear(Candidato $c, array $datos, string $origen, ?User $actor): Postulacion
    {
        $p = new Postulacion([
            'candidato_id' => $c->id, 'sede_id' => $datos['sede_id'] ?? $c->sede_id, 'vacante_id' => $datos['vacante_id'] ?? null,
            'departamento_id' => $datos['departamento_id'] ?? null, 'puesto_id' => $datos['puesto_id'] ?? null,
            'vacante' => isset($datos['vacante']) ? mb_substr((string) $datos['vacante'], 0, 150) : null, 'origen' => $origen,
        ]);
        $p->forceFill(['empresa_id' => $c->empresa_id, 'creado_por' => $actor?->id, 'actualizado_por' => $actor?->id])->save();
        $this->reflejar($p);
        if ($actor !== null) {
            $this->auditoria->auditar($actor, 'candidatos.postulacion_creada', $p, null, $this->foto($p));
        }

        return $p;
    }

    private function nuevaDesdeFicha(Candidato $c): Postulacion
    {
        $p = new Postulacion(['candidato_id' => $c->id, 'sede_id' => $c->sede_id, 'origen' => $c->origen ?: 'caseta']);
        $p->forceFill(array_merge(['empresa_id' => $c->empresa_id, 'creado_por' => $c->creado_por, 'actualizado_por' => $c->actualizado_por],
            $c->only(array_diff(self::ESPEJO, ['sede_id']))));
        $p->etapa ??= 'registrado';
        $p->save();

        return $p;
    }

    /**
     * Cambio condicionado a la etapa esperada (doble clic o dos personas a la
     * vez: el segundo no cambia nada) y reflejo en la ficha. false = otra
     * persona lo cambió primero.
     *
     * @param  array<string, mixed>  $cambios
     */
    public function cambiar(Postulacion $p, ?string $etapaEsperada, array $cambios, ?User $actor): bool
    {
        $hecho = Postulacion::whereKey($p->id)->when($etapaEsperada !== null, fn ($q) => $q->where('etapa', $etapaEsperada))
            ->update($cambios + ['actualizado_por' => $actor?->id ?? $p->actualizado_por, 'updated_at' => now()]);
        if ($hecho === 0) {
            return false;
        }
        $p->refresh();
        $this->reflejar($p);

        return true;
    }

    /** Copia la postulación a la ficha, solo si es la activa. */
    public function reflejar(Postulacion $p): void
    {
        $activa = Postulacion::where('candidato_id', $p->candidato_id)->max('id');
        if ((int) $activa !== (int) $p->id) {
            return;
        }
        $valores = [];
        foreach (self::ESPEJO as $columna) {
            $valores[$columna] = $p->getAttributes()[$columna] ?? null;
        }
        Candidato::whereKey($p->candidato_id)->update($valores);
    }

    /** Recursos Humanos cambió en la ficha a qué aplica: pasa a la postulación activa. */
    public function desdeFicha(Candidato $c): void
    {
        $p = $this->asegurar($c);
        $cambios = [];
        foreach (self::DESDE_FICHA as $columna) {
            $valor = $c->getAttributes()[$columna] ?? null;
            if (($p->getAttributes()[$columna] ?? null) != $valor) {
                $cambios[$columna] = $valor;
            }
        }
        if ($cambios !== []) {
            $p->forceFill($cambios)->save();
        }
    }

    /**
     * Postulaciones anteriores a la activa (para la ficha).
     *
     * @return Collection<int, Postulacion>
     */
    public function anteriores(Candidato $c, ?Postulacion $activa)
    {
        return Postulacion::with(['vacantePublicada:id,titulo', 'puesto:id,nombre', 'departamento:id,nombre', 'sede:id,nombre'])
            ->where('candidato_id', $c->id)->when($activa !== null, fn ($q) => $q->whereKeyNot($activa->id))
            ->orderByDesc('id')->limit(20)->get();
    }

    /**
     * Foto para la auditoría (sin datos personales).
     *
     * @return array<string, mixed>
     */
    public function foto(Postulacion $p): array
    {
        return $p->only(['candidato_id', 'sede_id', 'vacante_id', 'departamento_id', 'puesto_id', 'vacante', 'etapa', 'origen']);
    }
}
