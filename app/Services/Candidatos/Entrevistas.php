<?php

namespace App\Services\Candidatos;

use App\Mail\AvisoEntrevistaCandidato;
use App\Mail\AvisoRecepcion;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\EvaluacionCandidato;
use App\Models\Postulacion;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\CadenaDelegaciones;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Recepcion\Destinatarios;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Entrevistas de la postulación (candidatos, fase 2; ADR-0009):
 *
 *  1. Recursos Humanos entrevista y evalúa (evaluarRh, con el candidato «Con
 *     RR. HH.»): Canalizar al departamento, Considerar o Rechazar. En los dos
 *     últimos el jefe no se entera.
 *  2. Canalizar al departamento (canalizar): vacante, departamento,
 *     entrevistador (por omisión, los responsables del departamento) y cita
 *     («Ahora, está en sala» o fecha y hora). La postulación queda
 *     «Entrevista con el departamento» y se avisa al entrevistador (o a su
 *     delegado, «No molestar») por campana, Mis pendientes y correo con botón
 *     firmado. El mismo formulario sirve para Reprogramar (cambia la cita o el
 *     entrevistador: se avisa al anterior y al nuevo) y para la Segunda entrevista.
 *  3. El entrevistador asignado (o su delegado activo) evalúa y decide
 *     (evaluarDepartamento): Elegir → «Elegido»; Segunda entrevista → sigue
 *     con el departamento (2.ª) sin cita y RR. HH. le pone fecha; Considerar o
 *     Rechazar → «Evaluado» (Recursos Humanos cierra el contacto). Puede
 *     corregir su evaluación durante MINUTOS_CORRECCION (corregirEvaluacion).
 *     Al cubrirse las plazas de la vacante, las demás postulaciones abiertas
 *     de esa vacante con el departamento pasan a «Considerar».
 *  4. No se presentó: cuando ya pasó la hora de la cita; «Reprogramar» lo
 *     regresa. Con la cita «Ahora, está en sala» lo marca también el entrevistador.
 *
 * No se usa la tabla de autorizaciones (ver ADR-0009): la respuesta del jefe
 * es una evaluación completa, no un botón, y la decide una persona concreta.
 * Se reutilizan las delegaciones («No molestar»), la campana y los correos.
 */
class Entrevistas
{
    /** Horas que vale el botón del correo al entrevistador. */
    public const HORAS_ENLACE_CORREO = 72;

    /** Referencia de las notificaciones (una por postulación). */
    public const REFERENCIA = 'entrevista';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
        private readonly Postulaciones $postulaciones,
        private readonly AjustesRecepcion $ajustes,
        private readonly HoraLocal $hora,
    ) {}

    // ---------------------------------------------------------------- Quién evalúa

    /** C-12: delegaciones directas o en cadena (una lectura por instancia). */
    private function cadena(): CadenaDelegaciones
    {
        return $this->cadena ??= new CadenaDelegaciones;
    }

    private ?CadenaDelegaciones $cadena = null;

    /**
     * Usuarios cuyas entrevistas atiende: él y quienes le delegaron (activo).
     *
     * @return list<int>
     */
    public function titularesDe(User $usuario): array
    {
        return array_values(array_unique(array_map('intval', [$usuario->id,
            ...$this->cadena()->titularesDe((int) $usuario->id)]))); // C-12: directa o en cadena
    }

    /**
     * ¿Puede evaluar esta postulación? Solo el entrevistador asignado (o su
     * delegado activo), con candidatos.evaluar en la sede de la postulación.
     */
    public function puedeEvaluar(User $usuario, Postulacion $p): bool
    {
        if ($p->entrevistador_id === null || ! $usuario->can('candidatos.evaluar')) {
            return false;
        }
        $sedes = $this->autorizador->sedesPermitidas($usuario, 'candidatos.evaluar');
        if ($sedes !== null && ! in_array((int) $p->sede_id, array_map('intval', $sedes), true)) {
            return false;
        }

        return in_array((int) $p->entrevistador_id, $this->titularesDe($usuario), true);
    }

    /**
     * Postulaciones que el usuario entrevista o ya evaluó (pantalla «Entrevistar»).
     *
     * @param  Builder<Postulacion>  $q
     * @return Builder<Postulacion>
     */
    public function limitar(Builder $q, User $usuario): Builder
    {
        if (! $usuario->can('candidatos.evaluar')) {
            return $q->whereRaw('1 = 0');
        }
        $sedes = $this->autorizador->sedesPermitidas($usuario, 'candidatos.evaluar');
        $titulares = $this->titularesDe($usuario);

        return $q->when($sedes !== null, fn ($x) => $x->whereIn('postulaciones.sede_id', $sedes === [] ? [0] : $sedes))
            ->where(fn ($w) => $w->whereIn('postulaciones.entrevistador_id', $titulares)
                ->orWhereIn('postulaciones.id', EvaluacionCandidato::where('tipo', 'departamento')->where('evaluador_id', $usuario->id)->select('postulacion_id')));
    }

    /**
     * Entrevistas que esperan su evaluación (Mis pendientes), la cita más próxima primero.
     *
     * @return Builder<Postulacion>
     */
    public function pendientes(User $usuario): Builder
    {
        return $this->limitar(Postulacion::query(), $usuario)->where('postulaciones.etapa', 'canalizado')
            ->whereIn('postulaciones.entrevistador_id', $this->titularesDe($usuario))
            ->orderByRaw('CASE WHEN postulaciones.cita_ahora = 1 THEN 0 ELSE 1 END')
            // Las que no tienen cita, al final (en MySQL los nulos van primero)
            ->orderByRaw('CASE WHEN postulaciones.cita_en IS NULL THEN 1 ELSE 0 END')->orderBy('postulaciones.cita_en')->orderBy('postulaciones.id');
    }

    /**
     * Quién puede entrevistar en esa sede: los responsables del departamento
     * (Responsables por departamento) y las demás personas activas con
     * candidatos.evaluar en esa sede. Quien atiende candidatos (Recursos
     * Humanos) no se ofrece, salvo que sea responsable del departamento: la
     * decisión de elegir es del departamento.
     *
     * @return array{responsables: Collection<int, User>, otros: Collection<int, User>}
     */
    public function elegibles(int $empresaId, int $sedeId, ?int $departamentoId): array
    {
        $conPermiso = $this->destinatarios->conPermiso($empresaId, 'candidatos.evaluar', $sedeId);
        $responsables = $departamentoId === null ? collect()
            : app(Autorizaciones::class)->responsablesDe($departamentoId, $sedeId)->pluck('usuario')->filter()
                ->filter(fn (User $u) => $conPermiso->contains('id', $u->id))->unique('id')->values();
        $otros = $conPermiso->reject(fn (User $u) => $responsables->contains('id', $u->id) || $this->destinatarios->alcanza($u, 'candidatos.editar', $sedeId))
            ->sortBy('name')->values();

        return ['responsables' => $responsables, 'otros' => $otros];
    }

    /**
     * A quién avisar: al entrevistador o, si delegó («No molestar»), a su delegado.
     *
     * @return Collection<int, User>
     */
    public function aQuienAvisar(?int $entrevistadorId): Collection
    {
        if ($entrevistadorId === null) {
            return collect();
        }
        $titular = User::where('activo', true)->find($entrevistadorId);
        if ($titular === null) {
            return collect();
        }
        // C-12: al último de su cadena que pueda entrevistar; si nadie, se queda con el titular
        $cadena = $this->cadena();
        $destino = $cadena->destino((int) $titular->id, fn (User $d) => $d->can('candidatos.evaluar'));

        return collect([$destino === (int) $titular->id ? $titular : $cadena->usuario($destino)]);
    }

    // --------------------------------------------------------------- Evaluaciones

    /** @return list<string> */
    public function criterios(int $empresaId): array
    {
        return $this->ajustes->criterios(Empresa::findOrFail($empresaId));
    }

    /**
     * Valida una evaluación: cada criterio de 1 a 5 estrellas, el resultado y
     * el comentario (obligatorio para Considerar y Rechazar).
     *
     * @param  array<string, mixed>  $entrada
     * @return array{criterios: array<string, int>, promedio: float, resultado: string, comentario: ?string, entrevista_en: Carbon}
     */
    public function validarEvaluacion(int $empresaId, string $tipo, array $entrada): array
    {
        $errores = [];
        $calificaciones = is_array($entrada['criterios'] ?? null) ? $entrada['criterios'] : [];
        $valores = [];
        foreach ($this->criterios($empresaId) as $nombre) {
            $clave = AjustesRecepcion::claveCriterio($nombre);
            $v = Entrada::texto($calificaciones[$clave] ?? null);
            if (! ctype_digit($v) || (int) $v < EvaluacionCandidato::MINIMO || (int) $v > EvaluacionCandidato::MAXIMO) {
                $errores['criterios.'.$clave] = "Califica «{$nombre}» de 1 a 5 estrellas.";

                continue;
            }
            $valores[$nombre] = (int) $v;
        }
        $resultado = Entrada::texto($entrada['resultado'] ?? null);
        if (! array_key_exists($resultado, EvaluacionCandidato::RESULTADOS[$tipo])) {
            $errores['resultado'] = 'Elige el resultado de la entrevista.';
        }
        $comentario = trim(Entrada::texto($entrada['comentario'] ?? null));
        if (mb_strlen($comentario) > 1000) {
            $errores['comentario'] = 'El comentario admite máximo 1000 caracteres.';
        } elseif ($comentario === '' && in_array($resultado, EvaluacionCandidato::CON_COMENTARIO, true)) {
            $errores['comentario'] = 'Escribe un comentario: es obligatorio para «Considerar» y «Rechazar».';
        }
        $cuando = now();
        // Fecha y hora por separado (la hora en 24 h) o juntas «AAAA-MM-DDTHH:MM»
        $fecha = Entrada::texto($entrada['entrevista_fecha'] ?? null) !== ''
            ? Entrada::texto($entrada['entrevista_fecha'] ?? null).'T'.(Entrada::texto($entrada['entrevista_hora'] ?? null) ?: '00:00')
            : Entrada::texto($entrada['entrevista_en'] ?? null);
        if ($fecha !== '') {
            try {
                $cuando = Carbon::createFromFormat('Y-m-d\TH:i', $fecha, $this->hora->zona())->utc();
            } catch (\Throwable) {
                $errores['entrevista_en'] = 'Revisa la fecha y hora de la entrevista.';
            }
            if (! isset($errores['entrevista_en']) && ($cuando->gt(now()->addMinutes(10)) || $cuando->lt(now()->subDays(60)))) {
                $errores['entrevista_en'] = 'La fecha de la entrevista no puede ser futura (ni de hace más de 60 días).';
            }
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return ['criterios' => $valores, 'promedio' => round(array_sum($valores) / max(1, count($valores)), 2), 'resultado' => $resultado,
            'comentario' => $comentario === '' ? null : $comentario, 'entrevista_en' => $cuando];
    }

    /** @param array{criterios: array<string, int>, promedio: float, resultado: string, comentario: ?string, entrevista_en: Carbon} $d */
    private function guardarEvaluacion(Postulacion $p, string $tipo, array $d, User $actor): EvaluacionCandidato
    {
        $e = new EvaluacionCandidato([
            'sede_id' => $p->sede_id, 'postulacion_id' => $p->id, 'candidato_id' => $p->candidato_id, 'tipo' => $tipo, 'evaluador_id' => $actor->id,
            'entrevista_en' => $d['entrevista_en'], 'criterios' => $d['criterios'], 'promedio' => $d['promedio'], 'comentario' => $d['comentario'],
            'resultado' => $d['resultado'], 'numero' => max(1, (int) $p->numero_entrevista),
        ]);
        // QA ENT-08: si evalúa por la delegación del entrevistador asignado, queda anotado a nombre de quién
        $titular = $tipo === 'departamento' && $p->entrevistador_id !== null && (int) $p->entrevistador_id !== (int) $actor->id ? (int) $p->entrevistador_id : null;
        $e->forceFill(['empresa_id' => $p->empresa_id, 'por_delegacion_de' => $titular, 'creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();

        return $e;
    }

    /**
     * Recursos Humanos registra su entrevista de filtro con el candidato «Con
     * RR. HH.» (no cambia de etapa: solo se anota cuándo fue la entrevista).
     * Considerar y Rechazar cierran ahí (sin avisar al departamento);
     * Canalizar deja lista la canalización.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function evaluarRh(User $actor, Candidato $c, array $entrada): EvaluacionCandidato
    {
        $p = $this->postulaciones->asegurar($c);
        if ($p->etapa !== 'con_rh') {
            throw new CambioNoPermitido('La evaluación de RR. HH. se registra con el candidato «Con RR. HH.» (hoy está «'.$p->etiquetaEtapa().'»). Primero toca «Atender».');
        }
        $d = $this->validarEvaluacion((int) $c->empresa_id, 'rh', $entrada);
        $anterior = $p->etapa;

        $e = DB::transaction(function () use ($actor, $p, $d) {
            if (! $this->postulaciones->cambiar($p, 'con_rh', $p->entrevista_rh_en === null ? ['entrevista_rh_en' => now()] : [], $actor)) {
                throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
            }
            $e = $this->guardarEvaluacion($p->refresh(), 'rh', $d, $actor);
            $cierre = ['considerar' => 'considerar', 'rechazar' => 'rechazado'][$d['resultado']] ?? null;
            if ($cierre !== null && ! $this->postulaciones->cambiar($p, 'con_rh', ['etapa' => $cierre, 'decision_en' => now(), 'decision_por' => $actor->id,
                'motivo_descarte' => mb_substr((string) $d['comentario'], 0, 500)], $actor)) {
                throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
            }

            return $e;
        });
        $p->refresh();
        Candidato::whereKey($c->id)->update(['actualizado_por' => $actor->id, 'updated_at' => now()]);
        app(AdministradorCandidatos::class)->evento($c, 'evaluacion_rh', $anterior, $p->etapa !== $anterior ? $p->etapa : null,
            'Evaluación de RR. HH. ('.$e->numero.'.ª entrevista): promedio '.$e->promedioTexto().' · '.$e->etiquetaResultado().'.'.($e->comentario ? ' «'.$e->comentario.'»' : ''), $actor);
        $this->auditoria->auditar($actor, 'candidatos.evaluado_rh', $e, null, $this->foto($e));

        return $e;
    }

    /** Minutos durante los que el entrevistador puede corregir la evaluación que acaba de guardar. */
    public const MINUTOS_CORRECCION = 15;

    /**
     * Evalúa el entrevistador asignado (o su delegado). Elegir → «Elegido»;
     * Segunda entrevista → sigue «Entrevista con el departamento» con el
     * mismo entrevistador, número + 1 y sin cita (RR. HH. le pone fecha con
     * «Reprogramar»); Considerar y Rechazar → «Evaluado» (Recursos Humanos
     * decide con quién sigue).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function evaluarDepartamento(User $actor, Postulacion $p, array $entrada): EvaluacionCandidato
    {
        if (! $this->puedeEvaluar($actor, $p)) {
            throw new CambioNoPermitido('Solo la persona que va a entrevistar (o quien tiene su delegación) puede evaluar esta entrevista.');
        }
        if ($p->etapa !== 'canalizado') {
            throw new CambioNoPermitido('Esta entrevista ya se evaluó o Recursos Humanos la cambió («'.$p->etiquetaEtapa().'»).');
        }
        $d = $this->validarEvaluacion((int) $p->empresa_id, 'departamento', $entrada);
        $elegir = $d['resultado'] === 'elegir';
        $segunda = $d['resultado'] === 'segunda_entrevista';
        $numero = max(1, (int) $p->numero_entrevista);

        $e = DB::transaction(function () use ($actor, $p, $d, $elegir, $segunda, $numero) {
            $cambios = match (true) {
                $elegir => ['etapa' => 'elegido', 'elegido_en' => now(), 'evaluado_en' => now(), 'decision_en' => now(), 'decision_por' => $actor->id],
                $segunda => ['etapa' => 'canalizado', 'evaluado_en' => now(), 'numero_entrevista' => $numero + 1, 'cita_en' => null, 'cita_ahora' => false],
                default => ['etapa' => 'evaluado', 'evaluado_en' => now()],
            };
            // La evaluación se guarda con el número de la entrevista que acaba de hacer
            $e = $this->guardarEvaluacion($p, 'departamento', $d, $actor);
            if (! $this->postulaciones->cambiar($p, 'canalizado', $cambios, $actor)) {
                throw new CambioNoPermitido('Esta entrevista ya se evaluó o Recursos Humanos la cambió. Revisa la lista.');
            }

            return $e;
        });
        $p->refresh()->loadMissing(['candidato', 'departamento:id,nombre', 'vacantePublicada:id,titulo,plazas']);
        Candidato::whereKey($p->candidato_id)->update(['updated_at' => now()]);
        $this->notificaciones->resolver(self::REFERENCIA, $p->id);
        $ficha = $p->candidato;
        app(AdministradorCandidatos::class)->evento($ficha, 'evaluacion_departamento', 'canalizado', $segunda ? null : $p->etapa,
            'Evaluación del departamento ('.$e->numero.'.ª entrevista) por '.$e->quienEvaluo().': promedio '.$e->promedioTexto().' · '.$e->etiquetaResultado().'.'
            .($e->comentario ? ' «'.$e->comentario.'»' : '').($segunda ? ' Sigue con el departamento ('.$p->numeroTexto().' entrevista): falta ponerle fecha.' : ''), $actor);
        $this->auditoria->auditar($actor, 'candidatos.evaluado_departamento', $e, ['etapa' => 'canalizado'], $this->foto($e) + ['etapa' => $p->etapa]);

        // Aviso a Recursos Humanos: siempre (RR. HH. cierra el contacto con el candidato o le pone fecha a la segunda entrevista)
        $this->avisarRhDeLaEvaluacion($p, $actor, $e, $elegir, $segunda);

        if ($elegir) {
            $this->cubrirPlazas($actor, $p);
        }

        return $e;
    }

    private function avisarRhDeLaEvaluacion(Postulacion $p, User $actor, EvaluacionCandidato $e, bool $elegir, bool $segunda, bool $correccion = false): void
    {
        $ficha = $p->candidato;
        $titulo = match (true) {
            $correccion => 'Corrigió su evaluación de '.$ficha->nombre_completo,
            $segunda => 'Pide segunda entrevista: ponle fecha a '.$ficha->nombre_completo,
            $elegir => 'Elegido por el departamento: '.$ficha->nombre_completo,
            default => 'El departamento evaluó a '.$ficha->nombre_completo,
        };
        $texto = trim(($p->departamento?->nombre ? $p->departamento->nombre.' · ' : '').$e->quienEvaluo().': '.$e->etiquetaResultado()
            .' (promedio '.$e->promedioTexto().').'.($e->comentario ? ' «'.$e->comentario.'»' : '')
            .($segunda ? ' Sigue con '.($e->titular?->name ?? $actor->name).' ('.$p->numeroTexto().' entrevista): usa «Reprogramar» para ponerle fecha.' : ''));
        $this->avisarRh($p, $actor, $titulo, $texto);
    }

    /**
     * ¿Puede corregir esta evaluación? La hizo él, hace menos de
     * MINUTOS_CORRECCION, y la postulación sigue como la dejó (si Recursos
     * Humanos ya la movió, no). Una evaluación «Elegir» solo corrige estrellas
     * y comentario: deshacer la elección es decisión de RR. HH.
     */
    public function puedeCorregir(User $usuario, Postulacion $p, ?EvaluacionCandidato $e): bool
    {
        if ($e === null || $e->tipo !== 'departamento' || (int) $e->evaluador_id !== $usuario->id || $e->created_at === null
            || $e->created_at->lt(now()->subMinutes(self::MINUTOS_CORRECCION)) || ! $usuario->can('candidatos.evaluar')) {
            return false;
        }
        if ($p->evaluaciones()->where('id', '>', $e->id)->exists()) {
            return false;
        }

        return match ($e->resultado) {
            'elegir' => $p->etapa === 'elegido',
            'segunda_entrevista' => $p->etapa === 'canalizado' && (int) $p->numero_entrevista === (int) $e->numero + 1 && $p->cita_en === null && ! $p->cita_ahora,
            default => $p->etapa === 'evaluado',
        };
    }

    /**
     * Corrige la evaluación que acaba de guardar (estrellas, comentario y,
     * salvo que haya elegido, el resultado). Si el resultado cambia, la
     * postulación se acomoda como si se hubiera evaluado así desde el inicio.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function corregirEvaluacion(User $actor, Postulacion $p, EvaluacionCandidato $e, array $entrada): EvaluacionCandidato
    {
        if (! $this->puedeCorregir($actor, $p, $e)) {
            throw new CambioNoPermitido('Ya no se puede corregir esta evaluación: pasaron más de '.self::MINUTOS_CORRECCION.' minutos o Recursos Humanos ya la atendió.');
        }
        $d = $this->validarEvaluacion((int) $p->empresa_id, 'departamento', $entrada);
        if ($e->resultado === 'elegir' && $d['resultado'] !== 'elegir') {
            throw ValidationException::withMessages(['resultado' => 'Ya elegiste a esta persona: solo puedes corregir las estrellas y el comentario. Si cambiaste de opinión, avisa a Recursos Humanos.']);
        }
        $antes = $this->foto($e);
        $anterior = $e->resultado;
        $elegir = $d['resultado'] === 'elegir';
        $segunda = $d['resultado'] === 'segunda_entrevista';

        DB::transaction(function () use ($actor, $p, $e, $d, $anterior, $elegir, $segunda) {
            $e->forceFill(['criterios' => $d['criterios'], 'promedio' => $d['promedio'], 'comentario' => $d['comentario'], 'resultado' => $d['resultado'],
                'entrevista_en' => $d['entrevista_en'], 'actualizado_por' => $actor->id])->save();
            if ($anterior === $d['resultado']) {
                return;
            }
            // Se deshace lo que dejó el resultado anterior y se aplica el nuevo
            $cambios = match (true) {
                $elegir => ['etapa' => 'elegido', 'elegido_en' => now(), 'decision_en' => now(), 'decision_por' => $actor->id, 'numero_entrevista' => $e->numero],
                $segunda => ['etapa' => 'canalizado', 'numero_entrevista' => $e->numero + 1, 'cita_en' => null, 'cita_ahora' => false],
                default => ['etapa' => 'evaluado', 'numero_entrevista' => $e->numero],
            };
            if (! $this->postulaciones->cambiar($p, $p->etapa, $cambios, $actor)) {
                throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa la lista.');
            }
        });
        $p->refresh()->loadMissing(['candidato', 'departamento:id,nombre', 'vacantePublicada:id,titulo,plazas']);
        Candidato::whereKey($p->candidato_id)->update(['updated_at' => now()]);
        app(AdministradorCandidatos::class)->evento($p->candidato, 'evaluacion_corregida', null, null,
            $actor->name.' corrigió su evaluación ('.$e->numero.'.ª entrevista): promedio '.$e->promedioTexto().' · '.$e->etiquetaResultado().'.'
            .($e->comentario ? ' «'.$e->comentario.'»' : ''), $actor);
        $this->auditoria->auditar($actor, 'candidatos.evaluacion_corregida', $e, $antes, $this->foto($e) + ['etapa' => $p->etapa]);
        $this->avisarRhDeLaEvaluacion($p, $actor, $e, $elegir, $segunda, true);
        if ($elegir && $anterior !== 'elegir') {
            $this->cubrirPlazas($actor, $p);
        }

        return $e;
    }

    /**
     * Con las plazas de la vacante cubiertas (elegidos + contratados), las
     * demás postulaciones de esa vacante que siguen con el departamento
     * (canalizado o evaluado) pasan a «Considerar» y se avisa a RR. HH.
     */
    private function cubrirPlazas(User $actor, Postulacion $elegida): void
    {
        if ($elegida->vacante_id === null || ($vacante = Vacante::find($elegida->vacante_id)) === null) {
            return;
        }
        $cubiertas = Postulacion::where('vacante_id', $vacante->id)->whereIn('etapa', ['elegido', 'contratado'])->count();
        if ($cubiertas < max(1, (int) $vacante->plazas)) {
            return;
        }
        $motivo = 'Se eligió a otra persona para esta vacante';
        $movidas = [];
        foreach (Postulacion::with('candidato')->where('vacante_id', $vacante->id)->whereKeyNot($elegida->id)->whereIn('etapa', ['canalizado', 'evaluado'])->get() as $o) {
            $antes = $o->etapa;
            if (! $this->postulaciones->cambiar($o, $antes, ['etapa' => 'considerar', 'decision_en' => now(), 'decision_por' => $actor->id, 'motivo_descarte' => $motivo], $actor)) {
                continue;
            }
            if ($o->candidato !== null) {
                app(AdministradorCandidatos::class)->evento($o->candidato, 'etapa', $antes, 'considerar', $motivo.' («'.$vacante->titulo.'»).', $actor);
                $this->auditoria->auditar($actor, 'candidatos.etapa', $o->candidato, ['etapa' => $antes], ['etapa' => 'considerar', 'motivo' => 'vacante_cubierta']);
                $movidas[] = $o->candidato->nombre_completo;
            }
            if ($antes === 'canalizado') {
                $this->citaCancelada($actor, $o, $motivo.'.', (int) $o->entrevistador_id === $actor->id);
            }
        }
        // Aviso «Vacante cubierta» a RR. HH. con el botón «Cerrar como cubierta» (ADR-0010); se resuelve al cerrar o publicar la vacante
        $rh = $this->destinatarios->conPermiso((int) $elegida->empresa_id, 'candidatos.editar', (int) $elegida->sede_id)->reject(fn (User $u) => $u->id === $actor->id);
        $this->notificaciones->resolver(AdministradorVacantes::REFERENCIA, $vacante->id);
        Vacante::whereKey($vacante->id)->whereNull('cubierta_en')->update(['cubierta_en' => now()]); // C-23: reloj de «Vacante cubierta por cerrar»
        $this->notificaciones->avisar((int) $elegida->empresa_id, $rh->pluck('id'), 'vacante_cubierta', [
            'titulo' => 'Vacante cubierta: «'.$vacante->titulo.'»',
            'texto' => 'Se eligió a '.$elegida->candidato?->nombre_completo.'.'.($movidas !== [] ? ' Pasaron a «Considerar»: '.implode(', ', $movidas).'.' : '')
                .' Si ya no buscas a nadie más, ciérrala.',
            'url' => route('vacantes.candidatos', $vacante->id),
            'referencia_tipo' => AdministradorVacantes::REFERENCIA, 'referencia_id' => $vacante->id,
            'acciones' => [['etiqueta' => 'Cerrar como cubierta', 'url' => route('vacantes.estado', $vacante->id),
                'campos' => ['_method' => 'PATCH', 'estado' => 'cerrada', 'cierre_motivo' => 'cubierta']]],
        ]);
        app(AvisosCorreo::class)->recepcion((int) $elegida->empresa_id, 'evaluacion_departamento', $rh->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion('Vacante cubierta: «'.$vacante->titulo.'»', ['Se eligió a '.$elegida->candidato?->nombre_completo.'. Si ya no buscas a nadie más, ciérrala como cubierta desde Vacantes.'],
                [['Ver sus candidatos', route('vacantes.candidatos', $vacante->id)]]));
    }

    // -------------------------------------------------------- Canalizar y citas

    /**
     * Canalizar al departamento (después de la evaluación de RR. HH. con
     * «Canalizar»), Segunda entrevista (desde «Evaluado») o Reprogramar
     * (desde «Entrevista con el departamento» o «No se presentó»).
     *
     * @param  array<string, mixed>  $entrada  vacante_id, departamento_id, entrevistador_id, cuando (ahora|cita), fecha, hora, lugar, avisar_candidato
     */
    public function canalizar(User $actor, Candidato $c, array $entrada): Postulacion
    {
        $p = $this->postulaciones->asegurar($c);
        $modo = match ($p->etapa) {
            'con_rh' => 'canalizar',
            'evaluado' => 'segunda',
            'canalizado', 'no_se_presento' => 'reprogramar',
            default => throw new CambioNoPermitido('Un candidato «'.$p->etiquetaEtapa().'» no se puede canalizar al departamento.'),
        };
        if ($modo === 'canalizar') {
            $rh = EvaluacionCandidato::where('postulacion_id', $p->id)->where('tipo', 'rh')->orderByDesc('id')->first();
            if ($rh === null || $rh->resultado !== 'canalizar') {
                throw new CambioNoPermitido('Primero registra la evaluación de RR. HH. con el resultado «Canalizar al departamento».');
            }
        }
        $d = $this->validarCanalizar($p, $entrada);
        $anterior = $p->etapa;
        $entrevistadorAntes = $p->entrevistador_id === null ? null : (int) $p->entrevistador_id;
        $citaAntes = $this->textoCita($p);

        $cambios = ['etapa' => 'canalizado', 'vacante_id' => $d['vacante']?->id, 'departamento_id' => $d['departamento']->id,
            'entrevistador_id' => $d['entrevistador']->id, 'cita_en' => $d['cita_en'], 'cita_ahora' => $d['ahora'], 'cita_lugar' => $d['lugar']];
        if ($d['vacante'] !== null && $d['vacante']->id !== $p->vacante_id) {
            $cambios += ['vacante' => mb_substr($d['vacante']->titulo, 0, 150)] + ($d['vacante']->puesto_id ? ['puesto_id' => $d['vacante']->puesto_id] : []);
        }
        if ($modo !== 'reprogramar') {
            $cambios += ['canalizado_en' => now(), 'canalizado_por' => $actor->id];
        }
        if ($modo === 'segunda') {
            $cambios['numero_entrevista'] = max(1, (int) $p->numero_entrevista) + 1;
        }
        if (! $this->postulaciones->cambiar($p, $anterior, $cambios, $actor)) {
            throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
        }
        $p->refresh()->loadMissing(['departamento:id,nombre', 'vacantePublicada:id,titulo', 'entrevistador:id,name']);
        Candidato::whereKey($c->id)->update(['actualizado_por' => $actor->id, 'updated_at' => now()]);
        $c->refresh();

        // Avisos: los anteriores de esta entrevista pierden vigencia
        $this->notificaciones->resolver(self::REFERENCIA, $p->id);
        $cambioEntrevistador = $modo === 'reprogramar' && $entrevistadorAntes !== null && $entrevistadorAntes !== (int) $p->entrevistador_id;
        if ($cambioEntrevistador) {
            $this->avisarEntrevistador($entrevistadorAntes, $p, $c, 'entrevista_cambio', 'Ya no entrevistas a '.$c->nombre_completo,
                ['Recursos Humanos asignó la entrevista a otra persona. No tienes que evaluarla.'], false);
        }
        $titulo = match (true) {
            $modo === 'reprogramar' && ! $cambioEntrevistador && $anterior === 'canalizado' => 'Se reprogramó la entrevista: '.$c->nombre_completo,
            $modo === 'segunda' => 'Segunda entrevista: '.$c->nombre_completo,
            default => 'Entrevista: '.$c->nombre_completo,
        };
        $this->avisarEntrevistador((int) $p->entrevistador_id, $p, $c, 'entrevista_asignada', $titulo.' · '.$this->textoCita($p), $this->resumenParaEntrevistador($p, $c), true);

        $texto = match ($modo) {
            'segunda' => 'Segunda entrevista con el departamento',
            'reprogramar' => $anterior === 'no_se_presento' ? 'Se reprogramó su entrevista (no se había presentado)' : 'Se reprogramó la entrevista',
            default => 'Canalizado al departamento',
        };
        app(AdministradorCandidatos::class)->evento($c, $modo === 'reprogramar' ? 'reprogramado' : 'canalizado', $anterior, 'canalizado',
            $texto.': '.$p->departamento?->nombre.' con '.$p->entrevistador?->name.' · '.$this->textoCita($p).($p->cita_lugar ? ' · '.$p->cita_lugar : '')
            .($modo === 'reprogramar' && $citaAntes !== '' ? ' (antes: '.$citaAntes.')' : '').'.', $actor);
        $this->auditoria->auditar($actor, $modo === 'reprogramar' ? 'candidatos.reprogramado' : 'candidatos.canalizado', $c,
            ['etapa' => $anterior, 'entrevistador_id' => $entrevistadorAntes],
            ['etapa' => 'canalizado', 'entrevistador_id' => $p->entrevistador_id, 'departamento_id' => $p->departamento_id, 'vacante_id' => $p->vacante_id,
                'cita' => $p->cita_ahora ? 'ahora' : $p->cita_en?->toIso8601String(), 'numero' => $p->numero_entrevista]);

        if ($d['avisar_candidato']) {
            $this->correoAlCandidato($actor, $p, $c);
        }

        return $p;
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{vacante: ?Vacante, departamento: Departamento, entrevistador: User, ahora: bool, cita_en: Carbon, lugar: ?string, avisar_candidato: bool}
     */
    private function validarCanalizar(Postulacion $p, array $entrada): array
    {
        $errores = [];
        $vacante = null;
        $vacanteId = Entrada::texto($entrada['vacante_id'] ?? null);
        if ($vacanteId !== '') {
            $vacante = ctype_digit($vacanteId) ? Vacante::whereKey((int) $vacanteId)
                ->where(fn ($q) => $q->whereIn('estado', ['publicada', 'pausada'])->when($p->vacante_id !== null, fn ($x) => $x->orWhere('id', $p->vacante_id)))->first() : null;
            if ($vacante === null) {
                $errores['vacante_id'] = 'Elige una vacante publicada (o en pausa) de la lista.';
            }
        }
        $departamentoId = Entrada::texto($entrada['departamento_id'] ?? null);
        $departamento = ctype_digit($departamentoId) ? Departamento::where('activo', true)->aplicanEn([(int) $p->sede_id])->find((int) $departamentoId) : null;
        if ($departamento === null) {
            $errores['departamento_id'] = $departamentoId === '' ? 'Indica a qué departamento lo canalizas.' : 'Elige un departamento activo de esta sede.';
        }
        $entrevistador = null;
        $entrevistadorId = Entrada::texto($entrada['entrevistador_id'] ?? null);
        if ($departamento !== null) {
            $elegibles = $this->elegibles((int) $p->empresa_id, (int) $p->sede_id, $departamento->id);
            $entrevistador = ctype_digit($entrevistadorId) ? $elegibles['responsables']->concat($elegibles['otros'])->firstWhere('id', (int) $entrevistadorId) : null;
            if ($entrevistador === null) {
                $errores['entrevistador_id'] = $entrevistadorId === '' ? 'Elige quién lo va a entrevistar.'
                    : 'Esa persona no puede entrevistar en esta sede (necesita el permiso «Evaluar» de Candidatos). Elige otra de la lista.';
            }
        }
        $cuando = Entrada::texto($entrada['cuando'] ?? null);
        $ahora = $cuando === 'ahora';
        $citaEn = now();
        if (! in_array($cuando, ['ahora', 'cita'], true)) {
            $errores['cuando'] = 'Indica si la entrevista es ahora (está en sala) o con cita.';
        } elseif (! $ahora) {
            $fecha = Entrada::texto($entrada['fecha'] ?? null);
            $horaCita = Entrada::texto($entrada['hora'] ?? null);
            try {
                $citaEn = Carbon::createFromFormat('Y-m-d H:i', $fecha.' '.$horaCita, $this->hora->zona())->utc();
                if ($citaEn->lt(now()->subMinutes(5))) {
                    $errores['fecha'] = 'Esa fecha y hora ya pasaron: elige una cita futura.';
                } elseif ($citaEn->gt(now()->addDays(90))) {
                    $errores['fecha'] = 'La cita no puede ser en más de 90 días.';
                }
            } catch (\Throwable) {
                $errores['fecha'] = 'Escribe la fecha y la hora de la cita.';
            }
        }
        $lugar = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($entrada['lugar'] ?? null)));
        if (mb_strlen($lugar) > 300) {
            $errores['lugar'] = 'El lugar o las notas admiten máximo 300 caracteres.';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return ['vacante' => $vacante ?? ($p->vacante_id ? Vacante::find($p->vacante_id) : null), 'departamento' => $departamento, 'entrevistador' => $entrevistador,
            'ahora' => $ahora, 'cita_en' => $citaEn, 'lugar' => $lugar === '' ? null : $lugar,
            'avisar_candidato' => ! $ahora && filter_var($entrada['avisar_candidato'] ?? false, FILTER_VALIDATE_BOOL)];
    }

    /**
     * «No se presentó»: ya pasó la hora de la cita. «Reprogramar» lo regresa.
     * Lo marca Recursos Humanos; cuando la cita fue «Ahora, está en sala»,
     * también el propio entrevistador ($porEntrevistador: se avisa a RR. HH.).
     */
    public function noSePresento(User $actor, Candidato $c, bool $porEntrevistador = false): Postulacion
    {
        $p = $this->postulaciones->asegurar($c);
        if ($p->etapa !== 'canalizado') {
            throw new CambioNoPermitido('Solo se marca «No se presentó» cuando el candidato tiene entrevista con el departamento.');
        }
        if ($porEntrevistador && (! $p->cita_ahora || ! $this->puedeEvaluar($actor, $p))) {
            throw new CambioNoPermitido('Solo quien lo iba a entrevistar puede marcar «No se presentó», y solo cuando la cita era «Ahora, está en sala».');
        }
        if (! $p->citaPasada()) {
            throw new CambioNoPermitido('Todavía no es la hora de su cita ('.$this->textoCita($p).').');
        }
        if (! $this->postulaciones->cambiar($p, 'canalizado', ['etapa' => 'no_se_presento', 'no_se_presento_en' => now()], $actor)) {
            throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
        }
        $p->refresh();
        Candidato::whereKey($c->id)->update(['actualizado_por' => $actor->id, 'updated_at' => now()]);
        app(AdministradorCandidatos::class)->evento($c, 'etapa', 'canalizado', 'no_se_presento', 'No se presentó a su entrevista ('.$this->textoCita($p).').'
            .($porEntrevistador ? ' Lo marcó quien lo iba a entrevistar.' : ''), $actor);
        $this->auditoria->auditar($actor, 'candidatos.no_se_presento', $c, ['etapa' => 'canalizado'], ['etapa' => 'no_se_presento', 'por' => $porEntrevistador ? 'entrevistador' : 'rh']);
        if ($porEntrevistador) {
            $this->notificaciones->resolver(self::REFERENCIA, $p->id);
            $this->avisarRh($p, $actor, 'No se presentó: '.$c->nombre_completo, $actor->name.' marcó que no se presentó a su entrevista. Usa «Reprogramar» si vuelve a agendar.');
        } else {
            $this->citaCancelada($actor, $p, 'No se presentó a su entrevista.');
        }

        return $p;
    }

    /**
     * La entrevista ya no va (RR. HH. lo pasó a Considerar o Rechazar, no se
     * presentó o se cubrió la vacante): el aviso pierde vigencia y se avisa al entrevistador.
     */
    public function citaCancelada(User $actor, Postulacion $p, string $motivo, bool $sinAviso = false): void
    {
        $this->notificaciones->resolver(self::REFERENCIA, $p->id);
        if ($sinAviso || $p->entrevistador_id === null) {
            return;
        }
        $c = Candidato::find($p->candidato_id);
        if ($c === null) {
            return;
        }
        $this->avisarEntrevistador((int) $p->entrevistador_id, $p, $c, 'entrevista_cambio', 'Se canceló la entrevista de '.$c->nombre_completo, [$motivo], false);
    }

    /**
     * La caseta registró «Entrevista» para alguien que tiene entrevista con el
     * departamento: «Juan Pérez ya está en recepción para su entrevista de las 11:00».
     */
    public function avisarLlegada(User $registro, Postulacion $p, Candidato $c): void
    {
        if ($p->etapa !== 'canalizado' || $p->entrevistador_id === null) {
            return;
        }
        $de = $p->cita_ahora || $p->cita_en === null ? 'para su entrevista'
            : ($p->cita_en->isSameDay(now()) ? 'para su entrevista de las '.$this->hora->formatear($p->cita_en, 'H:i') : 'para su entrevista del '.$this->textoCita($p));
        $this->avisarEntrevistador((int) $p->entrevistador_id, $p, $c, 'entrevista_llegada', $c->nombre_completo.' ya está en recepción '.$de,
            ['Lo registró la caseta: '.$registro->name.'.'], true);
        app(AdministradorCandidatos::class)->evento($c, 'llegada_entrevista', null, null, 'Llegó a su entrevista con el departamento: se avisó al entrevistador.', $registro);
    }

    // ------------------------------------------------------------------ Avisos

    /**
     * Campana y correo al entrevistador (o a su delegado). $conBoton: lleva el
     * botón «Abrir la entrevista» (dirección firmada).
     *
     * @param  list<string>  $lineas
     */
    private function avisarEntrevistador(int $entrevistadorId, Postulacion $p, Candidato $c, string $tipo, string $titulo, array $lineas, bool $conBoton): void
    {
        $usuarios = $this->aQuienAvisar($entrevistadorId);
        if ($usuarios->isEmpty()) {
            return;
        }
        $this->notificaciones->avisar((int) $p->empresa_id, $usuarios->pluck('id'), $tipo, [
            'titulo' => $titulo, 'texto' => implode(' ', $lineas), 'url' => $conBoton ? route('entrevistas.show', $p->id) : route('entrevistas.index'),
            'referencia_tipo' => self::REFERENCIA, 'referencia_id' => $p->id,
        ]);
        $botones = $conBoton ? [['Abrir la entrevista', URL::temporarySignedRoute('entrevistas.correo', now()->addHours(self::HORAS_ENLACE_CORREO), ['postulacion' => $p->id])]] : [];
        app(AvisosCorreo::class)->recepcion((int) $p->empresa_id, 'entrevista_departamento', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, $lineas, $botones));
    }

    private function avisarRh(Postulacion $p, User $actor, string $titulo, string $texto): void
    {
        $rh = $this->destinatarios->conPermiso((int) $p->empresa_id, 'candidatos.editar', (int) $p->sede_id)->reject(fn (User $u) => $u->id === $actor->id);
        $this->notificaciones->avisar((int) $p->empresa_id, $rh->pluck('id'), 'evaluacion_departamento', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('candidatos.show', $p->candidato_id),
            'referencia_tipo' => 'candidato', 'referencia_id' => $p->candidato_id,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $p->empresa_id, 'evaluacion_departamento', $rh->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir su ficha', route('candidatos.show', $p->candidato_id)]]));
    }

    /**
     * Resumen para el entrevistador: sin CURP, RFC, NSS, domicilio ni contacto,
     * con la evaluación de RR. HH.
     *
     * @return list<string>
     */
    public function resumenParaEntrevistador(Postulacion $p, Candidato $c): array
    {
        $p->loadMissing(['vacantePublicada:id,titulo', 'puesto:id,nombre', 'departamento:id,nombre']);
        $rh = EvaluacionCandidato::where('postulacion_id', $p->id)->where('tipo', 'rh')->orderByDesc('id')->first();
        $lineas = ['Recursos Humanos te canalizó a '.$c->nombre_completo.' para «'.$p->titulo().'»'.($p->departamento?->nombre ? ' ('.$p->departamento->nombre.')' : '').'.',
            'Cita: '.$this->textoCita($p).($p->cita_lugar ? ' · '.$p->cita_lugar : '').'.'];
        $perfil = array_filter([
            $c->escolaridadMaxima() ? 'Escolaridad: '.$c->escolaridadMaxima() : null,
            'Experiencia: '.$c->anosExperiencia().' año(s)',
            $c->disponibilidad ? 'Disponibilidad: '.(Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? $c->disponibilidad) : null,
            $c->pretension !== null ? 'Sueldo que espera: $'.number_format((float) $c->pretension, 0).' al mes' : null,
        ]);
        $lineas[] = implode('. ', $perfil).'.';
        if ($rh !== null) {
            $lineas[] = 'Evaluación de RR. HH.: promedio '.$rh->promedioTexto().' de 5'.($rh->comentario ? ' — «'.$rh->comentario.'»' : '').'.';
        }

        return $lineas;
    }

    /** «Ahora (está en sala)» o «15/10/2026 11:00». */
    public function textoCita(Postulacion $p): string
    {
        if ($p->cita_ahora) {
            return 'Ahora (está en sala)';
        }

        return $p->cita_en === null ? '' : $this->hora->formatear($p->cita_en, 'd/m/Y H:i');
    }

    /**
     * Correo al candidato con su cita (texto neutral, sin resultados). Solo si
     * tiene correo y la plataforma tiene correo configurado; queda en su historial.
     */
    private function correoAlCandidato(User $actor, Postulacion $p, Candidato $c): void
    {
        if ($c->correo === null || $c->correo === '' || $p->cita_ahora || $p->cita_en === null) {
            return;
        }
        $empresa = Empresa::find($p->empresa_id);
        $p->loadMissing(['sede:id,nombre', 'entrevistador:id,name']);
        $enviado = app(AvisosCorreo::class)->alCandidato($c->correo, new AvisoEntrevistaCandidato(
            (string) ($c->partesNombre()['nombre'] ?: $c->nombre_completo),
            (string) ($empresa?->nombre_comercial ?? ''),
            $this->hora->formatear($p->cita_en, 'd/m/Y'),
            $this->hora->formatear($p->cita_en, 'H:i'),
            $p->cita_lugar ?? $p->sede?->nombre,
            $p->entrevistador?->name,
        ));
        app(AdministradorCandidatos::class)->evento($c, 'correo_candidato', null, null, $enviado
            ? 'Se le envió por correo su cita de entrevista ('.$this->textoCita($p).').'
            : 'No se pudo enviar el correo con su cita: la plataforma no tiene correo configurado.', $actor);
        if ($enviado) {
            $this->auditoria->auditar($actor, 'candidatos.correo_candidato', $c, null, ['postulacion_id' => $p->id, 'cita' => $p->cita_en->toIso8601String()]);
        }
    }

    /**
     * Foto para la auditoría (sin comentario: puede traer datos personales).
     *
     * @return array<string, mixed>
     */
    public function foto(EvaluacionCandidato $e): array
    {
        return $e->only(['postulacion_id', 'candidato_id', 'sede_id', 'tipo', 'resultado', 'promedio', 'numero']);
    }
}
