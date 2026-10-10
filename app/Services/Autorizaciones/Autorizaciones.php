<?php

namespace App\Services\Autorizaciones;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Delegacion;
use App\Models\DepartamentoResponsable;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\Postulaciones;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\Destinatarios;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Autorizaciones departamentales:
 *  - visita: la caseta registra a alguien que va a un departamento (o a un
 *    colaborador de ese departamento) y la empresa pide autorización: el
 *    acceso nace PENDIENTE («Esperando autorización») y se avisa al
 *    responsable; su respuesta lo deja EN SITIO o lo cierra como no autorizado;
 *  - candidato: Recursos Humanos aprueba y el departamento responde
 *    «Bajar a entrevistar» o «Rechazar»;
 *  - recepcion: alguien llegó a caseta con Recursos Humanos y la empresa pide
 *    que RR. HH. diga «Que pase» (Que pase / Que espere / No puede pasar). La
 *    responde quien atiende candidatos o Recepción en esa sede
 *    (candidatos.editar o recepcion_rh.ver), no un departamento.
 *
 * Avisos: campana (con botones) y correo (botones con dirección firmada que
 * pide iniciar sesión y confirmar). Si el responsable delegó («No molestar»),
 * el aviso va a su delegado. Responde el responsable (titular o suplente) del
 * departamento en esa sede, o quien tenga su delegación activa, y con el
 * permiso autorizaciones.responder. Cada respuesta guarda quién, cuándo y
 * por qué medio; el primero que responde gana (las demás quedan sin botones).
 */
class Autorizaciones
{
    /** Horas que vale el botón del correo. */
    public const HORAS_ENLACE_CORREO = 24;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
    ) {}

    // ------------------------------------------------------------ Responsables

    /**
     * Responsables (titular y suplentes) de un departamento en una sede.
     *
     * @return Collection<int, DepartamentoResponsable>
     */
    public function responsablesDe(int $departamentoId, ?int $sedeId): Collection
    {
        return DepartamentoResponsable::with('usuario:id,name,email,activo,empresa_id')
            ->where('departamento_id', $departamentoId)
            ->where(fn ($q) => $q->whereNull('sede_id')->when($sedeId !== null, fn ($s) => $s->orWhere('sede_id', $sedeId)))
            ->orderBy('es_suplente')->orderBy('id')->get()
            ->filter(fn ($r) => $r->usuario?->activo)->values();
    }

    public function tieneResponsables(int $departamentoId, ?int $sedeId): bool
    {
        return $this->responsablesDe($departamentoId, $sedeId)->isNotEmpty();
    }

    /**
     * A quién avisar: cada responsable, o su delegado si tiene una delegación activa.
     *
     * @return Collection<int, User>
     */
    public function aQuienAvisar(int $departamentoId, int $sedeId): Collection
    {
        $usuarios = $this->responsablesDe($departamentoId, $sedeId)->pluck('usuario');
        $delegaciones = Delegacion::activas()->with('delegado:id,name,email,activo')->whereIn('user_id', $usuarios->pluck('id'))->orderByDesc('id')->get()->unique('user_id')->keyBy('user_id');

        return $usuarios->map(fn (User $u) => ($d = $delegaciones[$u->id] ?? null) && $d->delegado?->activo ? $d->delegado : $u)
            ->unique('id')->values();
    }

    /**
     * ¿Puede responder esta autorización? Responsable del departamento en esa
     * sede (o delegado activo de uno) y con el permiso autorizaciones.responder.
     */
    public function puedeResponder(User $usuario, Autorizacion $a): bool
    {
        if ($a->tipo === 'recepcion') {
            return $this->atiendeRecepcion($usuario, (int) $a->sede_id);
        }
        if (! $usuario->can('autorizaciones.responder')) {
            return false;
        }
        $responsables = $this->responsablesDe((int) $a->departamento_id, (int) $a->sede_id)->pluck('user_id');
        if ($responsables->contains($usuario->id)) {
            return true;
        }

        return Delegacion::activas()->where('delegado_id', $usuario->id)->whereIn('user_id', $responsables)->exists();
    }

    /**
     * Autorizaciones que ve el usuario: quien configura (Recursos Humanos) ve
     * las de sus sedes; los demás, las de los departamentos que atienden
     * (como responsables o delegados) dentro de sus sedes.
     *
     * @param  Builder<Autorizacion>  $q
     * @return Builder<Autorizacion>
     */
    public function limitar(Builder $q, User $usuario): Builder
    {
        if ($usuario->es_superadmin) {
            return $q;
        }
        $verDepartamentos = $usuario->can('autorizaciones.ver');
        $sedesRecepcion = $this->sedesRecepcion($usuario);
        if (! $verDepartamentos && $sedesRecepcion === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($usuario, $verDepartamentos, $sedesRecepcion) {
            $w->whereRaw('1 = 0');
            // Recepción de RR. HH.: las de sus sedes
            if ($sedesRecepcion !== []) {
                $w->orWhere(fn ($r) => $r->where('autorizaciones.tipo', 'recepcion')
                    ->when($sedesRecepcion !== null, fn ($x) => $x->whereIn('autorizaciones.sede_id', $sedesRecepcion)));
            }
            if (! $verDepartamentos) {
                return;
            }
            $w->orWhere(function ($d) use ($usuario) {
                $d->where('autorizaciones.tipo', '!=', 'recepcion');
                $sedes = $this->autorizador->sedesPermitidas($usuario, 'autorizaciones.ver');
                $d->when($sedes !== null, fn ($s) => $s->whereIn('autorizaciones.sede_id', $sedes));
                if ($usuario->can('autorizaciones.configurar')) {
                    return;
                }
                $pares = $this->departamentosQueAtiende($usuario);
                if ($pares === []) {
                    $d->whereRaw('1 = 0');

                    return;
                }
                $d->where(function ($x) use ($pares) {
                    foreach ($pares as [$departamento, $sede]) {
                        $x->orWhere(fn ($y) => $y->where('autorizaciones.departamento_id', $departamento)
                            ->when($sede !== null, fn ($z) => $z->where('autorizaciones.sede_id', $sede)));
                    }
                });
            });
        });
    }

    // ------------------------------------------------------------ Recepción de RR. HH.

    /**
     * Sedes donde el usuario atiende Recepción de RR. HH. (candidatos.editar o
     * recepcion_rh.ver): null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedesRecepcion(User $usuario): ?array
    {
        $todas = [];
        foreach (['candidatos.editar', 'recepcion_rh.ver'] as $permiso) {
            if (! $usuario->can($permiso)) {
                continue;
            }
            $sedes = $this->autorizador->sedesPermitidas($usuario, $permiso);
            if ($sedes === null) {
                return null;
            }
            $todas = [...$todas, ...$sedes];
        }

        return array_values(array_unique(array_map('intval', $todas)));
    }

    /** ¿Atiende Recepción de RR. HH. en esa sede? (puede decir «Que pase») */
    public function atiendeRecepcion(User $usuario, int $sedeId): bool
    {
        $sedes = $this->sedesRecepcion($usuario);

        return $sedes === null || in_array($sedeId, $sedes, true);
    }

    /**
     * A quién avisar que alguien espera a RR. HH. en caseta: usuarios activos
     * con candidatos.editar o recepcion_rh.ver que alcancen esa sede.
     *
     * @return Collection<int, User>
     */
    public function destinatariosRecepcion(int $empresaId, int $sedeId): Collection
    {
        return $this->destinatarios->conPermiso($empresaId, 'candidatos.editar', $sedeId)
            ->merge($this->destinatarios->conPermiso($empresaId, 'recepcion_rh.ver', $sedeId))
            ->unique('id')->values();
    }

    /**
     * Accesos que esperan el «Que pase» de RR. HH. en las sedes del usuario
     * (Recepción y Mis pendientes).
     *
     * @return Builder<Acceso>
     */
    public function accesosEsperandoRecepcion(User $usuario): Builder
    {
        $sedes = $usuario->es_superadmin ? null : $this->sedesRecepcion($usuario);

        return Acceso::query()->where('tipo', 'visitante')->where('motivo_visita', 'rh')->where('estado', 'pendiente')
            ->whereIn('autorizacion', ['esperando', 'espera'])
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes === [] ? [0] : $sedes));
    }

    /**
     * [departamento, sede|null] que atiende (propios y por delegación activa).
     *
     * @return list<array{0: int, 1: ?int}>
     */
    public function departamentosQueAtiende(User $usuario): array
    {
        $delegan = Delegacion::activas()->where('delegado_id', $usuario->id)->pluck('user_id');

        return DepartamentoResponsable::whereIn('user_id', $delegan->push($usuario->id)->unique())
            ->get(['departamento_id', 'sede_id'])
            ->map(fn ($r) => [(int) $r->departamento_id, $r->sede_id === null ? null : (int) $r->sede_id])
            ->unique(fn ($p) => $p[0].'-'.($p[1] ?? 'x'))->values()->all();
    }

    /**
     * Pendientes que el usuario puede responder (bandeja y aviso en Inicio).
     *
     * @return Collection<int, Autorizacion>
     */
    public function pendientesPara(User $usuario): Collection
    {
        if (! $usuario->can('autorizaciones.responder')) {
            return collect();
        }
        $pares = $this->departamentosQueAtiende($usuario);
        if ($pares === []) {
            return collect();
        }

        return Autorizacion::with($this->relaciones())->where('estado', 'pendiente')
            ->where(function ($w) use ($pares) {
                foreach ($pares as [$departamento, $sede]) {
                    $w->orWhere(fn ($x) => $x->where('departamento_id', $departamento)->when($sede !== null, fn ($y) => $y->where('sede_id', $sede)));
                }
            })
            ->orderBy('solicitada_en')->get();
    }

    /** @return array<int|string, mixed> */
    public function relaciones(): array
    {
        return ['sede:id,nombre', 'departamento:id,nombre', 'respondidaPor:id,name', 'registradoPor:id,name',
            'acceso:id,sede_id,nombre,tipo,motivo_visita,viene_a,persona_visita,empresa_procedencia,entrada_at,estado,autorizacion,foto_persona,creado_por',
            'candidato:id,sede_id,nombre_completo,etapa,puesto_id,vacante,escolaridad,experiencia,habilidades,idiomas,disponibilidad,llegada_en,departamento_id',
            'candidato.puesto:id,nombre'];
    }

    // -------------------------------------------------------------- Solicitudes

    /**
     * Visita que espera la autorización del departamento (el acceso ya nació pendiente).
     */
    public function solicitarVisita(User $actor, Acceso $acceso, int $departamentoId): ?Autorizacion
    {
        $avisar = $this->aQuienAvisar($departamentoId, (int) $acceso->sede_id);
        if ($avisar->isEmpty()) {
            return null;
        }
        $a = $this->nueva('visita', (int) $acceso->sede_id, $departamentoId, $acceso->id, null);
        $a->loadMissing('departamento:id,nombre', 'sede:id,nombre');
        $detalle = trim(($acceso->persona_visita ? 'Visita a: '.$acceso->persona_visita.'. ' : 'Visita al departamento '.$a->departamento?->nombre.'. ')
            .($acceso->empresa_procedencia ? 'Viene de: '.$acceso->empresa_procedencia.'. ' : '').'Sede: '.$a->sede?->nombre.'.');
        $this->avisarResponsables($a, $avisar, 'autorizacion_visita', 'Espera tu autorización: '.$acceso->nombre, $detalle, $actor);

        return $a;
    }

    /**
     * Alguien llegó a caseta con Recursos Humanos y espera su «Que pase» (el
     * acceso ya nació pendiente). El aviso es el de su llegada, con los
     * botones Que pase / Que espere / No puede pasar.
     */
    public function solicitarRecepcion(User $actor, Acceso $acceso, ?Candidato $candidato): ?Autorizacion
    {
        $avisar = $this->destinatariosRecepcion((int) $acceso->empresa_id, (int) $acceso->sede_id)->reject(fn (User $u) => $u->id === $actor->id)->values();
        $a = Autorizacion::create([
            'sede_id' => $acceso->sede_id, 'departamento_id' => null, 'tipo' => 'recepcion', 'acceso_id' => $acceso->id,
            'candidato_id' => $candidato?->id, 'solicitada_en' => now(),
        ]);
        $a->loadMissing('sede:id,nombre');
        $vieneA = Acceso::VIENE_A[$acceso->viene_a] ?? 'Recursos Humanos';
        $nombre = $candidato?->nombre_completo ?? mb_convert_case(mb_strtolower((string) $acceso->nombre), MB_CASE_TITLE);
        $primeraVez = $candidato === null || $candidato->eventos()->where('evento', 'visita')->doesntExist();
        $titulo = $candidato === null ? 'En caseta para RR. HH.: '.$nombre : ($primeraVez ? 'Llegó un candidato: ' : 'Volvió a la caseta: ').$nombre;
        $detalle = 'Viene a: '.$vieneA.'. '.($candidato?->puestoVisible() ? 'Aplica a: '.$candidato->puestoVisible().'. ' : '').'Sede: '.$a->sede?->nombre
            .'. Está en caseta esperando tu respuesta.';
        $this->avisarResponsables($a, $avisar, 'autorizacion_recepcion', $titulo, $detalle, $actor);
        $candidato?->forceFill(['avisado_rh_en' => now()])->save();

        return $a;
    }

    /**
     * Candidato aprobado por Recursos Humanos: el departamento recibe un resumen y responde.
     */
    public function solicitarCandidato(User $actor, Candidato $candidato): ?Autorizacion
    {
        $avisar = $this->aQuienAvisar((int) $candidato->departamento_id, (int) $candidato->sede_id);
        $postulaciones = app(Postulaciones::class);
        $postulaciones->cambiar($postulaciones->asegurar($candidato), null, ['enviado_departamento_en' => now()], $actor);
        $candidato->refresh();
        if ($avisar->isEmpty()) {
            app(AdministradorCandidatos::class)->evento($candidato, 'sin_responsable', null, null,
                'El departamento no tiene responsable registrado: avísale por otro medio o pásalo tú a entrevista.', $actor);

            return null;
        }
        $a = $this->nueva('candidato', (int) $candidato->sede_id, (int) $candidato->departamento_id, null, $candidato->id);
        $candidato->loadMissing('puesto:id,nombre');
        $partes = array_filter([
            $candidato->puestoVisible() ? 'Aplica a: '.$candidato->puestoVisible() : null,
            $candidato->escolaridadMaxima() ? 'Escolaridad: '.$candidato->escolaridadMaxima() : null,
            $candidato->anosExperiencia() > 0 ? 'Experiencia: '.$candidato->anosExperiencia().' año(s)' : null,
        ]);
        $this->avisarResponsables($a, $avisar, 'autorizacion_candidato', 'Recursos Humanos aprobó a '.$candidato->nombre_completo, implode('. ', $partes).'.', $actor);
        app(AdministradorCandidatos::class)->evento($candidato, 'enviado_departamento', null, null,
            'Aviso enviado a: '.$avisar->pluck('name')->join(', ', ' y ').'.', $actor);

        return $a;
    }

    private function nueva(string $tipo, int $sedeId, int $departamentoId, ?int $accesoId, ?int $candidatoId): Autorizacion
    {
        return Autorizacion::create([
            'sede_id' => $sedeId, 'departamento_id' => $departamentoId, 'tipo' => $tipo, 'acceso_id' => $accesoId, 'candidato_id' => $candidatoId,
            'solicitada_en' => now(),
        ]);
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function avisarResponsables(Autorizacion $a, Collection $usuarios, string $tipo, string $titulo, string $texto, User $actor): void
    {
        $acciones = [];
        foreach (array_keys(Autorizacion::RESPUESTAS[$a->tipo]) as $respuesta) {
            $acciones[] = ['etiqueta' => Autorizacion::BOTONES[$respuesta], 'url' => route('autorizaciones.responder', $a->id),
                'campos' => ['respuesta' => $respuesta],
                'estilo' => in_array($respuesta, Autorizacion::NEGATIVAS, true) ? 'rechazar' : ($respuesta === 'espere' ? 'esperar' : 'aceptar')];
        }
        $avisados = $this->notificaciones->avisar((int) $a->empresa_id, $usuarios->pluck('id'), $tipo, [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('autorizaciones.show', $a->id),
            'referencia_tipo' => 'autorizacion', 'referencia_id' => $a->id, 'acciones' => $acciones,
        ]);
        $a->forceFill(['avisados' => $avisados])->save();

        $botones = [];
        foreach (array_keys(Autorizacion::RESPUESTAS[$a->tipo]) as $respuesta) {
            $botones[] = [Autorizacion::BOTONES[$respuesta], URL::temporarySignedRoute('autorizaciones.confirmar',
                now()->addHours(self::HORAS_ENLACE_CORREO), ['autorizacion' => $a->id, 'respuesta' => $respuesta])];
        }
        app(AvisosCorreo::class)->recepcion((int) $a->empresa_id, $a->tipo === 'recepcion' ? 'candidato_llegada' : 'autorizacion_departamento',
            $usuarios->pluck('email')->filter()->values()->all(), new AvisoRecepcion($titulo, [$texto, 'Registró: '.$actor->name.'.'], $botones));
    }

    // --------------------------------------------------------------- Respuestas

    /**
     * Responde (el primero gana). $medio: plataforma | correo.
     */
    public function responder(User $actor, Autorizacion $a, string $respuesta, ?string $comentario, string $medio = 'plataforma'): Autorizacion
    {
        $estado = Autorizacion::RESPUESTAS[$a->tipo][$respuesta] ?? null;
        if ($estado === null) {
            throw new CambioNoPermitido('Esa respuesta no aplica a esta solicitud.');
        }
        if (! $this->puedeResponder($actor, $a)) {
            throw new CambioNoPermitido($a->tipo === 'recepcion'
                ? 'Solo quien atiende Recursos Humanos en '.($a->sede?->nombre ?? 'esa sede').' puede responder.'
                : 'Solo el responsable del departamento '.($a->departamento?->nombre ?? '').' (o su delegado) puede responder.');
        }
        $comentario = $comentario === null || trim($comentario) === '' ? null : mb_substr(trim($comentario), 0, 500);
        if ($a->tipo === 'recepcion' && $respuesta === 'espere') {
            return $this->pedirQueEspere($actor, $a, $comentario, $medio);
        }

        DB::transaction(function () use ($actor, $a, $estado, $comentario, $medio) {
            $hecho = Autorizacion::whereKey($a->id)->where('estado', 'pendiente')->update([
                'estado' => $estado, 'respondida_en' => now(), 'respondida_por' => $actor->id, 'respuesta_medio' => $medio,
                'comentario' => $comentario, 'actualizado_por' => $actor->id, 'updated_at' => now(),
            ]);
            if ($hecho === 0) {
                throw new CambioNoPermitido('Esta solicitud ya fue respondida o cancelada. Revisa la lista.');
            }
            $a->refresh();

            if (in_array($a->tipo, ['visita', 'recepcion'], true) && $a->acceso_id !== null) {
                $this->aplicarAVisita($actor, $a);
            }
        });

        if ($a->tipo === 'candidato' && $a->candidato !== null) {
            app(AdministradorCandidatos::class)->respuestaDepartamento($actor, $a->candidato, $estado, $comentario);
            $this->avisarRespuestaCandidato($a, $actor);
        }
        if ($a->tipo === 'visita' && $a->acceso?->creado_por !== null) {
            $this->notificaciones->avisar((int) $a->empresa_id, [(int) $a->acceso->creado_por], 'autorizacion_respuesta', [
                'titulo' => ($estado === 'autorizada' ? 'Ingreso autorizado: ' : 'Ingreso NO autorizado: ').$a->acceso->nombre,
                'texto' => 'Respondió '.$actor->name.($comentario ? ': «'.$comentario.'»' : '.'),
                'url' => route('accesos.index', $estado === 'autorizada' ? [] : ['pestana' => 'historial']),
            ]);
        }
        if ($a->tipo === 'recepcion') {
            $this->avisarCaseta($a, $actor, $estado === 'autorizada' ? 'RR. HH. dice que pase: ' : 'RR. HH.: NO puede pasar ', $comentario,
                $estado === 'autorizada' ? [] : ['pestana' => 'historial']);
            if (($ficha = $a->candidato_id ? Candidato::find($a->candidato_id) : null) !== null) {
                app(AdministradorCandidatos::class)->evento($ficha, 'recepcion', null, null,
                    ($estado === 'autorizada' ? 'Recursos Humanos le dio el paso' : 'Recursos Humanos no le dio el paso').($comentario ? ': «'.$comentario.'».' : '.'), $actor);
            }
        }
        $this->notificaciones->resolver('autorizacion', $a->id);
        $this->auditoria->auditar($actor, 'autorizaciones.respondida', $a, ['estado' => 'pendiente'], ['estado' => $estado, 'medio' => $medio]);

        return $a;
    }

    /**
     * Recepción: «Que espere». La solicitud sigue pendiente (y su aviso con
     * botones también); la caseta ve «RR. HH. pide que espere».
     */
    private function pedirQueEspere(User $actor, Autorizacion $a, ?string $comentario, string $medio): Autorizacion
    {
        if (! $a->pendiente()) {
            throw new CambioNoPermitido('Esta solicitud ya fue respondida o cancelada. Revisa la lista.');
        }
        $hecho = Acceso::whereKey($a->acceso_id)->where('estado', 'pendiente')->whereIn('autorizacion', ['esperando', 'espera'])
            ->update(['autorizacion' => 'espera', 'actualizado_por' => $actor->id, 'updated_at' => now()]);
        if ($hecho === 0) {
            throw new CambioNoPermitido('Esta persona ya no está esperando en caseta. Revisa la lista.');
        }
        $a->forceFill(['comentario' => $comentario, 'actualizado_por' => $actor->id])->save();
        $this->avisarCaseta($a, $actor, 'RR. HH. pide que espere: ', $comentario, ['pestana' => 'pendientes']);
        if (($ficha = $a->candidato_id ? Candidato::find($a->candidato_id) : null) !== null) {
            app(AdministradorCandidatos::class)->evento($ficha, 'recepcion', null, null,
                'Recursos Humanos pidió que espere en caseta'.($comentario ? ': «'.$comentario.'».' : '.'), $actor);
        }
        $this->auditoria->auditar($actor, 'autorizaciones.espera', $a, ['estado' => 'pendiente'], ['estado' => 'pendiente', 'acceso' => 'espera', 'medio' => $medio]);

        return $a;
    }

    /**
     * Aviso a quien registró en caseta (sin datos del CV).
     *
     * @param  array<string, string>  $consulta
     */
    private function avisarCaseta(Autorizacion $a, User $actor, string $titulo, ?string $comentario, array $consulta): void
    {
        if ($a->acceso?->creado_por === null) {
            return;
        }
        $this->notificaciones->avisar((int) $a->empresa_id, [(int) $a->acceso->creado_por], 'autorizacion_respuesta', [
            'titulo' => $titulo.$a->acceso->nombre,
            'texto' => 'Respondió '.$actor->name.($comentario ? ': «'.$comentario.'»' : '.'),
            'url' => route('accesos.index', $consulta),
        ]);
    }

    /**
     * Visita: autorizada → EN SITIO; rechazada → se cierra sin ingresar (su gafete queda libre).
     */
    private function aplicarAVisita(User $actor, Autorizacion $a): void
    {
        $ahora = now();
        if ($a->estado === 'autorizada') {
            Acceso::whereKey($a->acceso_id)->where('estado', 'pendiente')->update([
                'estado' => 'en_sitio', 'autorizacion' => 'autorizada', 'autorizado_at' => $ahora, 'autorizado_por' => $actor->id,
                'actualizado_por' => $actor->id, 'updated_at' => $ahora,
            ]);
            $this->auditoria->auditar($actor, 'accesos.autorizado', $a->acceso, ['estado' => 'pendiente'], ['estado' => 'en_sitio', 'por' => $a->tipo === 'recepcion' ? 'rh' : 'departamento']);
        } else {
            Acceso::whereKey($a->acceso_id)->where('estado', 'pendiente')->update([
                'estado' => 'finalizado', 'autorizacion' => 'rechazada', 'salida_at' => $ahora, 'salida_por' => $actor->id,
                'actualizado_por' => $actor->id, 'updated_at' => $ahora,
            ]);
            $this->auditoria->auditar($actor, 'accesos.no_autorizado', $a->acceso, ['estado' => 'pendiente'], ['estado' => 'finalizado']);
        }
    }

    private function avisarRespuestaCandidato(Autorizacion $a, User $actor): void
    {
        $c = $a->candidato;
        $rh = $this->destinatarios->conPermiso((int) $a->empresa_id, 'candidatos.editar', (int) $a->sede_id)->reject(fn ($u) => $u->id === $actor->id);
        $titulo = ($a->estado === 'entrevista' ? 'Bajar a entrevistar: ' : 'El departamento rechazó a ').$c->nombre_completo;
        $texto = $a->departamento?->nombre.' respondió por medio de '.$actor->name.($a->comentario ? ': «'.$a->comentario.'»' : '.');
        $this->notificaciones->avisar((int) $a->empresa_id, $rh->pluck('id'), 'autorizacion_respuesta', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('candidatos.show', $c->id), 'referencia_tipo' => 'candidato', 'referencia_id' => $c->id,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $a->empresa_id, 'autorizacion_respuesta', $rh->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir su ficha', route('candidatos.show', $c->id)]]));
    }

    /**
     * La caseta autorizó la visita por su cuenta (botón Autorizar de Accesos):
     * la solicitud queda respondida «por caseta» y los avisos pierden sus botones.
     */
    public function resueltaEnCaseta(User $actor, Acceso $acceso): void
    {
        Acceso::whereKey($acceso->id)->whereIn('autorizacion', ['esperando', 'espera'])->update(['autorizacion' => 'autorizada']);
        $pendiente = Autorizacion::where('acceso_id', $acceso->id)->where('estado', 'pendiente')->first();
        if ($pendiente === null) {
            return;
        }
        $pendiente->forceFill(['estado' => 'autorizada', 'respondida_en' => now(), 'respondida_por' => $actor->id, 'respuesta_medio' => 'caseta'])->save();
        $this->notificaciones->resolver('autorizacion', $pendiente->id);
        $this->auditoria->auditar($actor, 'autorizaciones.respondida', $pendiente, ['estado' => 'pendiente'], ['estado' => 'autorizada', 'medio' => 'caseta']);
    }

    /** Recursos Humanos decidió otra cosa: lo pendiente con el departamento se cancela. */
    public function cancelarDeCandidato(User $actor, Candidato $candidato): void
    {
        foreach (Autorizacion::where('candidato_id', $candidato->id)->where('estado', 'pendiente')->get() as $a) {
            $a->forceFill(['estado' => 'cancelada', 'respondida_en' => now(), 'respondida_por' => $actor->id, 'respuesta_medio' => 'rh'])->save();
            $this->notificaciones->resolver('autorizacion', $a->id);
            $this->auditoria->auditar($actor, 'autorizaciones.cancelada', $a, ['estado' => 'pendiente'], ['estado' => 'cancelada']);
        }
    }
}
