<?php

namespace App\Services\Autorizaciones;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DepartamentoResponsable;
use App\Models\User;
use App\Services\Accesos\AQuienViene;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\CambioNoPermitido;
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
 *    colaborador de ese departamento), o a un proveedor o contratista que
 *    viene a un departamento, y la empresa pide autorización para ese tipo: el
 *    acceso nace PENDIENTE («Esperando autorización») y se avisa al
 *    responsable; su respuesta lo deja EN SITIO o lo cierra como no autorizado;
 *  - candidato: ya no se crean (candidatos, fase 2): Recursos Humanos
 *    canaliza la entrevista al departamento con App\Services\Candidatos\Entrevistas;
 *    las que existían quedan como historial;
 *  - recepcion: alguien llegó a caseta con Recursos Humanos y la empresa pide
 *    que RR. HH. diga «Que pase» (Que pase / Que espere / No puede pasar). La
 *    responde quien atiende candidatos o Recepción en esa sede
 *    (candidatos.editar: Recursos Humanos), no un departamento. «Que pase»
 *    también atiende al candidato (pasa a «Con RR. HH.») en el mismo clic.
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
     * Responsables (titular y suplentes) que CUBREN el departamento en una
     * sede (C-08): cuenta activa, permiso «Responder» y alcance en esa sede.
     *
     * @return Collection<int, DepartamentoResponsable>
     */
    public function responsablesDe(int $departamentoId, ?int $sedeId): Collection
    {
        return $this->cobertura()->responsables($departamentoId, $sedeId);
    }

    public function tieneResponsables(int $departamentoId, ?int $sedeId): bool
    {
        return $this->responsablesDe($departamentoId, $sedeId)->isNotEmpty();
    }

    /**
     * Sedes en las que cada departamento tiene quien autorice (C-08): para
     * que el formulario de la caseta anticipe si la visita esperará al
     * responsable. departamento_id => lista de sedes (null = todas).
     *
     * @return array<int, list<int>|null>
     */
    public function departamentosConResponsable(): array
    {
        return $this->cobertura()->sedesCubiertas();
    }

    /** La regla única de cobertura (se recuerda durante la petición). */
    public function cobertura(): Cobertura
    {
        return $this->cobertura ??= app(Cobertura::class);
    }

    private ?Cobertura $cobertura = null;

    /** C-12: delegaciones en cadena (una lectura por instancia). */
    public function cadena(): CadenaDelegaciones
    {
        return $this->cadena ??= app(CadenaDelegaciones::class); // una sola cadena por petición (revisión Etapa 4, D-04)
    }

    private ?CadenaDelegaciones $cadena = null;

    /**
     * A quién avisar: cada responsable, o su delegado si tiene una delegación
     * activa Y el delegado puede responder en esa sede (C-12: si no puede, el
     * aviso se queda con el responsable).
     *
     * @return Collection<int, User>
     */
    public function aQuienAvisar(int $departamentoId, int $sedeId): Collection
    {
        $usuarios = $this->responsablesDe($departamentoId, $sedeId)->pluck('usuario');
        $cadena = $this->cadena();

        // En cadena (A→B→C): al último que pueda responder en esa sede; si nadie, al responsable
        return $usuarios->map(function (User $u) use ($cadena, $sedeId) {
            $destino = $cadena->destino((int) $u->id, fn (User $d) => $this->cobertura()->puedeResponderEn($d, $sedeId));

            return $destino === (int) $u->id ? $u : $cadena->usuario($destino);
        })->unique('id')->values();
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
        // C-09 (DD-12): la persona citada responde su propia visita (es suya, como su cuenta)
        if ($this->esLaPersonaCitada($usuario, $a)) {
            return true;
        }
        // Revisión Etapa 5 (C-12): la persona citada que delegó: su delegado (directo o en cadena) responde, como en los departamentos
        if ($this->esDelegadoDeLaPersonaCitada($usuario, $a)) {
            return true;
        }
        if (! $usuario->can('autorizaciones.responder') || $a->departamento_id === null) {
            return false;
        }
        $responsables = $this->responsablesDe((int) $a->departamento_id, (int) $a->sede_id)->pluck('user_id');
        if ($responsables->contains($usuario->id)) {
            return true;
        }

        // Delegado activo de un responsable, si él mismo puede responder en esa sede (A-04: nada de avisos que dan 404)
        return $this->cobertura()->puedeResponderEn($usuario, (int) $a->sede_id)
            && $responsables->map(fn ($id) => (int) $id)->intersect($this->cadena()->titularesDe((int) $usuario->id))->isNotEmpty();
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
        $propio = $usuario->activo ? ($usuario->getAttributes()['colaborador_id'] ?? null) : null; // C-09: sus propias visitas
        $delegadas = $this->personasQueLeDelegan($usuario); // revisión Etapa 5: las de quien le delegó (en las sedes donde responde)
        if (! $verDepartamentos && $sedesRecepcion === [] && $propio === null && $delegadas['personas'] === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($usuario, $verDepartamentos, $sedesRecepcion, $propio, $delegadas) {
            $w->whereRaw('1 = 0');
            if ($propio !== null) {
                $w->orWhere('autorizaciones.colaborador_id', (int) $propio);
            }
            if ($delegadas['personas'] !== []) {
                $w->orWhere(fn ($x) => $x->where('autorizaciones.tipo', 'visita')->whereIn('autorizaciones.colaborador_id', $delegadas['personas'])
                    ->when($delegadas['sedes'] !== null, fn ($y) => $y->whereIn('autorizaciones.sede_id', $delegadas['sedes'] === [] ? [0] : $delegadas['sedes'])));
            }
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
     * Sedes donde el usuario atiende Recepción de RR. HH. (candidatos.editar:
     * Recursos Humanos; Dirección solo consulta el panel y no dice «Que pase»):
     * null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedesRecepcion(User $usuario): ?array
    {
        $todas = [];
        foreach (['candidatos.editar'] as $permiso) {
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
     * con candidatos.editar (Recursos Humanos) que alcancen esa sede.
     *
     * @return Collection<int, User>
     */
    public function destinatariosRecepcion(int $empresaId, int $sedeId): Collection
    {
        return $this->destinatarios->conPermiso($empresaId, 'candidatos.editar', $sedeId)->values();
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
        $delegan = collect($this->cadena()->titularesDe((int) $usuario->id)); // directa o en cadena
        // C-08 / A-04: solo las sedes a las que el usuario puede entrar para responder
        $alcance = $this->cobertura()->puedeResponderEn($usuario, null) ? $this->cobertura()->sedesDe($usuario) : [];
        $pares = [];
        foreach (DepartamentoResponsable::with('usuario:id,activo')->whereIn('user_id', $delegan->push($usuario->id)->unique())->get(['departamento_id', 'sede_id', 'user_id']) as $r) {
            if (! $r->usuario?->activo) {
                continue;
            }
            $departamento = (int) $r->departamento_id;
            if ($r->sede_id === null) {
                foreach ($alcance === null ? [null] : $alcance as $sede) {
                    $pares[] = [$departamento, $sede === null ? null : (int) $sede];
                }
            } elseif ($alcance === null || in_array((int) $r->sede_id, array_map('intval', $alcance), true)) {
                $pares[] = [$departamento, (int) $r->sede_id];
            }
        }

        return collect($pares)->unique(fn ($p) => $p[0].'-'.($p[1] ?? 'x'))->values()->all();
    }

    /**
     * Pendientes que el usuario puede responder (bandeja y aviso en Inicio).
     *
     * @return Collection<int, Autorizacion>
     */
    public function pendientesPara(User $usuario): Collection
    {
        $propio = $usuario->activo ? ($usuario->getAttributes()['colaborador_id'] ?? null) : null; // C-09: las visitas que vienen con él
        $pares = $usuario->can('autorizaciones.responder') ? $this->departamentosQueAtiende($usuario) : [];
        $delegadas = $this->personasQueLeDelegan($usuario); // revisión Etapa 5: y las de quien le delegó
        if ($pares === [] && $propio === null && $delegadas['personas'] === []) {
            return collect();
        }

        return Autorizacion::with($this->relaciones())->where('estado', 'pendiente')
            ->where(function ($w) use ($pares, $propio, $delegadas) {
                $w->whereRaw('1 = 0');
                if ($propio !== null) {
                    $w->orWhere(fn ($x) => $x->where('tipo', 'visita')->where('colaborador_id', (int) $propio));
                }
                if ($delegadas['personas'] !== []) {
                    $w->orWhere(fn ($x) => $x->where('tipo', 'visita')->whereIn('colaborador_id', $delegadas['personas'])
                        ->when($delegadas['sedes'] !== null, fn ($y) => $y->whereIn('sede_id', $delegadas['sedes'] === [] ? [0] : $delegadas['sedes'])));
                }
                foreach ($pares as [$departamento, $sede]) {
                    $w->orWhere(fn ($x) => $x->where('departamento_id', $departamento)->when($sede !== null, fn ($y) => $y->where('sede_id', $sede)));
                }
            })
            ->orderBy('solicitada_en')->get();
    }

    /** C-09: ¿es la persona citada (con su cuenta activa) de esta visita? */
    public function esLaPersonaCitada(User $usuario, Autorizacion $a): bool
    {
        $propio = $usuario->getAttributes()['colaborador_id'] ?? null;

        return $a->tipo === 'visita' && $a->colaborador_id !== null && $propio !== null && (int) $propio === (int) $a->colaborador_id && (bool) $usuario->activo;
    }

    /**
     * Revisión Etapa 5 (C-12): «Delegar mis aprobaciones» es por cuenta. Si la
     * persona citada delegó, su visita la responde el último de su cadena que
     * pueda responder en esa sede (la misma regla que aQuienAvisar()); si
     * nadie de la cadena puede, se queda con ella.
     */
    public function destinoDeLaPersonaCitada(User $cuenta, int $sedeId): User
    {
        $cadena = $this->cadena();
        $destino = $cadena->destino((int) $cuenta->id, fn (User $d) => $this->cobertura()->puedeResponderEn($d, $sedeId));

        return $destino === (int) $cuenta->id ? $cuenta : $cadena->usuario($destino);
    }

    /** ¿Responde por delegación (directa o en cadena) la visita de la persona citada? */
    public function esDelegadoDeLaPersonaCitada(User $usuario, Autorizacion $a): bool
    {
        if ($a->tipo !== 'visita' || $a->colaborador_id === null || ! $usuario->activo) {
            return false;
        }
        $titulares = $this->cadena()->titularesDe((int) $usuario->id);
        if ($titulares === [] || ! $this->cobertura()->puedeResponderEn($usuario, (int) $a->sede_id)) {
            return false;
        }

        return User::whereIn('id', $titulares)->where('activo', true)->where('empresa_id', $a->empresa_id)
            ->where('colaborador_id', $a->colaborador_id)->exists();
    }

    /**
     * Personas citadas (colaborador_id) que le delegaron sus aprobaciones, y
     * las sedes donde él puede responder (null = todas).
     *
     * @return array{personas: list<int>, sedes: list<int>|null}
     */
    public function personasQueLeDelegan(User $usuario): array
    {
        // Primero lo que no consulta: sin el permiso de responder no puede ser delegado (C-12)
        if (! $usuario->activo || ! $this->cobertura()->puedeResponderEn($usuario, null)) {
            return ['personas' => [], 'sedes' => []];
        }
        $titulares = $this->cadena()->titularesDe((int) $usuario->id);
        if ($titulares === []) {
            return ['personas' => [], 'sedes' => []];
        }
        $personas = User::whereIn('id', $titulares)->where('activo', true)->where('empresa_id', $usuario->empresa_id)->whereNotNull('colaborador_id')
            ->pluck('colaborador_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $sedes = $this->cobertura()->sedesDe($usuario);

        return ['personas' => $personas, 'sedes' => $sedes === null ? null : array_values(array_map('intval', $sedes))];
    }

    /** @return array<int|string, mixed> */
    public function relaciones(): array
    {
        return ['sede:id,nombre', 'departamento:id,nombre', 'colaborador:id,nombre,apellido_paterno,apellido_materno', 'respondidaPor:id,name', 'registradoPor:id,name',
            'acceso:id,sede_id,nombre,tipo,motivo_visita,viene_a,persona_visita,empresa_procedencia,aviso_padron,entrada_at,estado,autorizacion,foto_persona,creado_por',
            'candidato:id,sede_id,nombre_completo,etapa,puesto_id,vacante,escolaridad,experiencia,habilidades,idiomas,disponibilidad,llegada_en,departamento_id',
            'candidato.puesto:id,nombre'];
    }

    // -------------------------------------------------------------- Solicitudes

    /**
     * Visita que espera la autorización del departamento (el acceso ya nació pendiente).
     */
    public function solicitarVisita(User $actor, Acceso $acceso, ?int $departamentoId, ?Colaborador $persona = null): ?Autorizacion
    {
        // C-09 (DD-12): la persona citada con cuenta recibe el aviso; su departamento es el suplente (escalamiento)
        $cuenta = AQuienViene::cuentaDe($persona);
        if ($cuenta !== null) {
            // Es su visita: le llega a ella o, si delegó, al último de su cadena que pueda responder (C-12);
            // si no contesta, el reloj la pasa a su departamento
            $avisar = collect([$this->destinoDeLaPersonaCitada($cuenta, (int) $acceso->sede_id)]);
        } else {
            $avisar = $departamentoId === null ? collect() : $this->aQuienAvisar($departamentoId, (int) $acceso->sede_id);
        }
        if ($avisar->isEmpty()) {
            return null;
        }
        $a = $this->nueva('visita', (int) $acceso->sede_id, $departamentoId, $acceso->id, null);
        if ($cuenta !== null) {
            $a->forceFill(['colaborador_id' => $persona->id])->save();
        }
        $a->loadMissing('departamento:id,nombre', 'sede:id,nombre');
        $detalle = trim(match (true) {
            $cuenta !== null => $acceso->etiquetaTipo().((int) $avisar->first()->id === (int) $cuenta->id ? ' que viene contigo. ' : ' que viene con '.$persona->nombreCompleto().' (te delegó sus aprobaciones). ').($acceso->actividad ? 'Actividad: '.$acceso->actividad.'. ' : ''),
            // Proveedor y contratista: de qué empresa, quién lo citó y a qué viene
            in_array($acceso->tipo, Acceso::CON_AUTORIZACION, true) => $acceso->etiquetaTipo().' para '.$a->departamento?->nombre.'. '
                .($acceso->host?->nombreCompleto() ? 'Lo citó: '.$acceso->host->nombreCompleto().'. ' : '')
                .($acceso->actividad ? 'Actividad: '.$acceso->actividad.'. ' : ''),
            (bool) $acceso->persona_visita => 'Visita a: '.$acceso->persona_visita.'. ',
            default => 'Visita al departamento '.$a->departamento?->nombre.'. ',
        }.($acceso->empresa_procedencia ? 'Viene de: '.$acceso->empresa_procedencia.'. ' : '').'Sede: '.$a->sede?->nombre.'.');
        $this->avisarResponsables($a, $avisar, 'autorizacion_visita', ($cuenta !== null ? 'Te busca en caseta: ' : 'Espera tu autorización: ').$acceso->nombre, $detalle, $actor);

        return $a;
    }

    /**
     * C-09 (DD-12): «se le avisa al visitado». La visita entró sin esperar
     * (la empresa no pide autorización), pero la persona con cuenta se entera.
     */
    public function avisarVisitado(User $actor, Acceso $acceso, User $cuenta): void
    {
        if ((int) $cuenta->id === (int) $actor->id) {
            return;
        }
        $acceso->loadMissing('sede:id,nombre');
        $this->notificaciones->avisar((int) $acceso->empresa_id, [(int) $cuenta->id], 'visita_llego', [
            'titulo' => 'Llegó tu visita: '.mb_convert_case(mb_strtolower((string) $acceso->nombre), MB_CASE_TITLE),
            'texto' => $acceso->etiquetaTipo().' en '.($acceso->sede?->nombre ?? 'la sede').'. Ya está en sitio. Registró: '.$actor->name.'.',
            'url' => route('panel'),
        ]);
    }

    /**
     * C-08 / DD-11: la visita (o el proveedor o contratista) va a un
     * departamento sin quien autorice en esa sede. El acceso ya nació
     * pendiente («sin responsable»); se avisa al supervisor y al jefe de
     * seguridad de la sede (campana y Mis pendientes), que lo confirman en la
     * bitácora. La tarjeta muestra el reloj y el color de la espera.
     *
     * @return list<int> avisados
     */
    public function avisarSinCobertura(User $actor, Acceso $acceso): array
    {
        $avisar = $this->supervisoresDeSede((int) $acceso->empresa_id, (int) $acceso->sede_id)->reject(fn (User $u) => $u->id === $actor->id);
        if ($avisar->isEmpty()) {
            return [];
        }
        $acceso->loadMissing('departamento:id,nombre', 'sede:id,nombre');
        $depto = $acceso->departamento?->nombre ?? 'su departamento';

        return $this->notificaciones->avisar((int) $acceso->empresa_id, $avisar->pluck('id'), 'acceso_sin_cobertura', [
            'titulo' => 'Espera tu autorización: '.$acceso->nombre,
            'texto' => $acceso->etiquetaTipo().' para '.$depto.', que no tiene quien autorice en '.($acceso->sede?->nombre ?? 'la sede')
                .'. Confírmalo con quien lo recibe y autorízalo en la bitácora. Registró: '.$actor->name.'.',
            'url' => route('accesos.index', ['pestana' => 'pendientes']),
            'referencia_tipo' => 'acceso', 'referencia_id' => $acceso->id,
            'acciones' => [['etiqueta' => 'Ver en la bitácora', 'abrir' => true]],
        ]);
    }

    /**
     * Supervisor y jefe de seguridad de la sede: quienes pueden autorizar
     * accesos en ella con el rol «Supervisor» o «Jefe de seguridad»; si la
     * empresa no usa esos roles, todos los que pueden autorizar accesos ahí.
     *
     * @return Collection<int, User>
     */
    public function supervisoresDeSede(int $empresaId, int $sedeId): Collection
    {
        $aprueban = $this->destinatarios->conPermiso($empresaId, 'accesos.aprobar', $sedeId);
        $suyos = $aprueban->filter(fn (User $u) => $u->roles()->where('roles.activo', true)
            ->where(fn ($q) => $q->whereRaw('LOWER(roles.nombre) LIKE ?', ['%supervisor%'])->orWhereRaw('LOWER(roles.nombre) LIKE ?', ['%jefe de seguridad%']))->exists())->values();

        return $suyos->isNotEmpty() ? $suyos : $aprueban->values();
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

    private function nueva(string $tipo, int $sedeId, ?int $departamentoId, ?int $accesoId, ?int $candidatoId): Autorizacion
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
     * Responde (el primero gana). $medio: plataforma | correo. $atender: en
     * Recepción, «Que pase y lo atiendo» (el candidato queda «Con RR. HH.» en
     * el mismo clic); desde la campana y el correo es solo «Que pase».
     */
    public function responder(User $actor, Autorizacion $a, string $respuesta, ?string $comentario, string $medio = 'plataforma', bool $atender = false): Autorizacion
    {
        $estado = Autorizacion::RESPUESTAS[$a->tipo][$respuesta] ?? null;
        if ($estado === null) {
            throw new CambioNoPermitido('Esa respuesta no aplica a esta solicitud.');
        }
        if (! $this->puedeResponder($actor, $a)) {
            throw new CambioNoPermitido($a->tipo === 'recepcion'
                ? 'Solo quien atiende Recursos Humanos en '.($a->sede?->nombre ?? 'esa sede').' puede responder.'
                : ($a->colaborador_id !== null
                    ? 'Solo la persona a quien viene la visita o el responsable de su departamento pueden responder.'
                    : 'Solo el responsable del departamento '.($a->departamento?->nombre ?? '').' (o su delegado) puede responder.'));
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
                if ($estado === 'autorizada' && $atender) {
                    $this->atenderConElPaso($actor, $ficha);
                }
            }
        }
        $this->notificaciones->resolver('autorizacion', $a->id);
        $this->auditoria->auditar($actor, 'autorizaciones.respondida', $a, ['estado' => 'pendiente'], ['estado' => $estado, 'medio' => $medio]);

        return $a;
    }

    /**
     * «Que pase y lo atiendo»: si el candidato seguía «Por atender», su
     * postulación pasa a «Con RR. HH.» con el mismo clic (quien da el paso es
     * quien lo atiende). Si otra persona lo cambió antes, no pasa nada.
     */
    private function atenderConElPaso(User $actor, Candidato $ficha): void
    {
        if ($ficha->etapa !== 'registrado') {
            return;
        }
        try {
            app(AdministradorCandidatos::class)->cambiarEtapa($actor, $ficha, 'con_rh');
        } catch (CambioNoPermitido) {
            // Alguien lo atendió (o lo cerró) un instante antes: el paso ya quedó dado
        }
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
}
