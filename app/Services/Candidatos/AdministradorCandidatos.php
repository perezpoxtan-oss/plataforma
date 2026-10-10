<?php

namespace App\Services\Candidatos;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Candidato;
use App\Models\CandidatoEvento;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Firmas\Firmas;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Recepcion\Destinatarios;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ficha del candidato: alta (caseta, RR. HH. o kiosco), CV, etapas, contratar.
 *
 * Reglas:
 *  - cada candidato es de una sede; con alcance de sede solo se ve lo de sus sedes;
 *  - el CV no se guarda sin aceptar el aviso de privacidad (se guarda cuándo,
 *    desde qué IP y la huella del texto aceptado);
 *  - las etapas siguen Candidato::TRANSICIONES y cada cambio se condiciona a la
 *    etapa esperada (doble clic o dos personas a la vez: el segundo recibe aviso);
 *  - «Contratar» crea el colaborador con las reglas de Colaboradores;
 *  - la auditoría guarda etapa, sede, departamento y puesto: nunca el CV ni el contacto.
 */
class AdministradorCandidatos
{
    public const MAX_FILAS = 6;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
        private readonly AjustesRecepcion $ajustes,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * @param  Builder<Candidato>  $consulta
     * @return Builder<Candidato>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->whereIn('candidatos.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('candidatos.creado_por', $actor->id));
    }

    /**
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permiso): Collection
    {
        $permitidas = $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];

        return Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))->orderBy('nombre')->get(['id', 'nombre']);
    }

    // ------------------------------------------------------------------- Altas

    /**
     * La caseta registró a alguien que viene a Recursos Humanos por empleo
     * (Personal externo → Recursos Humanos → ¿A qué viene?: Busca empleo,
     * Entrevista, Entrega de documentos o Firma de contrato).
     *
     * Una ficha por persona: si ya tiene ficha (por su persona del padrón, su
     * teléfono o, si viene a una cita, su nombre exacto) no se crea otra; la
     * visita se liga a su postulación abierta o, si no tiene, a una nueva. Si
     * dijo que venía a una cita y no se encuentra su ficha, se registra como
     * «Busca empleo» y queda anotado.
     *
     * Avisa a RR. HH. en ese momento, salvo cuando la visita espera su «Que
     * pase» (ese aviso lo manda Autorizaciones, con los botones).
     *
     * @param  array<string, mixed>  $d  viene_a, vacante_id, departamento_id, puesto_id, vacante, esperar_rh (ya validados)
     */
    public function desdeAcceso(User $actor, Acceso $acceso, array $d): Candidato
    {
        $vieneA = in_array($d['viene_a'] ?? null, ['busca_empleo', ...Acceso::VIENE_CON_FICHA], true) ? $d['viene_a'] : 'busca_empleo';
        $conCita = in_array($vieneA, Acceso::VIENE_CON_FICHA, true);
        $ficha = $this->fichaDeLaVisita($acceso, $conCita);
        $nota = null;
        if ($ficha === null && $conCita) {
            $nota = 'Dijo que venía a «'.Acceso::VIENE_A[$vieneA].'», pero no se encontró su ficha: se registró como «Busca empleo».';
            $vieneA = 'busca_empleo';
            $conCita = false;
        }
        $postulaciones = app(Postulaciones::class);
        $vacante = ['vacante_id' => $d['vacante_id'] ?? null, 'departamento_id' => $d['departamento_id'] ?? null, 'puesto_id' => $d['puesto_id'] ?? null,
            'vacante' => $d['vacante'] ?? null];
        $primeraVez = $ficha === null;

        if ($ficha === null) {
            $ficha = new Candidato([
                'sede_id' => $acceso->sede_id, 'persona_id' => $acceso->persona_id, 'acceso_id' => $acceso->id,
                'nombre_completo' => mb_convert_case(mb_strtolower($acceso->nombre), MB_CASE_TITLE), 'origen' => 'caseta', 'llegada_en' => $acceso->entrada_at ?? now(),
            ]);
            $ficha->save();
            $postulacion = $postulaciones->crear($ficha, $vacante + ['sede_id' => $acceso->sede_id], 'caseta', null);
            $ficha->refresh();
            $this->evento($ficha, 'registrado', null, 'registrado', trim('Registrado en caseta por '.$actor->name.'. '.($nota ?? '')), $actor);
            $this->auditoria->auditar($actor, 'candidatos.creado', $ficha, null, $this->foto($ficha));
        } else {
            $postulacion = $postulaciones->abierta($ficha) ?? ($conCita ? $postulaciones->activa($ficha) : null);
            $nueva = $postulacion === null;
            if ($nueva) {
                $postulacion = $postulaciones->crear($ficha, $vacante + ['sede_id' => $acceso->sede_id], 'caseta', $actor);
            } elseif ($postulacion->vacante_id === null && $vacante['vacante_id'] !== null) {
                // Ya estaba en proceso «a lo que haya» y hoy dice a qué vacante viene
                $postulacion->forceFill(array_filter($vacante, fn ($v) => $v !== null))->save();
                $postulaciones->reflejar($postulacion);
            }
            $ficha->forceFill(['acceso_id' => $acceso->id, 'llegada_en' => $acceso->entrada_at ?? now(), 'persona_id' => $ficha->persona_id ?? $acceso->persona_id])->save();
            $ficha->refresh();
            $this->evento($ficha, 'visita', null, null, 'Volvió a la caseta: '.Acceso::VIENE_A[$vieneA].($nueva ? ' (nueva postulación'.($postulacion->vacante ? ' a «'.$postulacion->vacante.'»' : '').')' : '').'. Registró '.$actor->name.'.'.($nota ? ' '.$nota : ''), $actor);
            $this->auditoria->auditar($actor, 'candidatos.visita_ligada', $ficha, null, ['acceso_id' => $acceso->id, 'postulacion_id' => $postulacion->id, 'viene_a' => $vieneA]);
        }

        $acceso->forceFill(['postulacion_id' => $postulacion->id, 'viene_a' => $vieneA])->saveQuietly();
        if ($acceso->persona_id !== null) {
            Persona::whereKey($acceso->persona_id)->where('categoria', 'general')->update(['categoria' => 'prospecto_rrhh']);
        }
        if (empty($d['esperar_rh'])) {
            $this->avisarLlegada($ficha, $actor, $vieneA, $primeraVez, $nota);
        }

        return $ficha;
    }

    /**
     * Ficha de quien llega a la caseta: por su persona del padrón, por el
     * teléfono que tenga en el padrón y, si viene a una cita (entrevista,
     * documentos o firma), por su nombre exacto.
     */
    public function fichaDeLaVisita(Acceso $acceso, bool $porNombre): ?Candidato
    {
        $telefono = $acceso->persona_id !== null ? Persona::whereKey($acceso->persona_id)->value('telefono') : null;
        $ficha = $this->buscarFicha($acceso->persona_id, $telefono, null);
        if ($ficha === null && $porNombre) {
            $ficha = Candidato::whereRaw('LOWER(nombre_completo) = ?', [mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $acceso->nombre)))])
                ->orderByDesc('id')->first();
        }

        return $ficha;
    }

    /**
     * Ficha existente de la misma empresa (empresa activa) por persona del
     * padrón, teléfono normalizado o CURP. La más reciente si hubiera varias.
     */
    public function buscarFicha(?int $personaId, ?string $telefono, ?string $curp): ?Candidato
    {
        $telefono = $telefono === null ? '' : (string) preg_replace('/\D+/', '', $telefono);
        $curp = $curp === null ? '' : mb_strtoupper(str_replace([' ', '-'], '', $curp));
        if ($personaId === null && strlen($telefono) < 10 && strlen($curp) !== 18) {
            return null;
        }

        return Candidato::where(function ($q) use ($personaId, $telefono, $curp) {
            $q->whereRaw('1 = 0')
                ->when($personaId !== null, fn ($x) => $x->orWhere('persona_id', $personaId))
                ->when(strlen($telefono) >= 10, fn ($x) => $x->orWhere('telefono', $telefono))
                ->when(strlen($curp) === 18, fn ($x) => $x->orWhere('curp', $curp));
        })->orderByDesc('id')->first();
    }

    /**
     * Recursos Humanos captura un candidato (sin pasar por la caseta).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada, string $ip): Candidato
    {
        return $this->crearOLigar($actor, $entrada, $ip)[0];
    }

    /**
     * «Nuevo candidato» de Recursos Humanos. Si la persona ya tiene ficha (mismo
     * teléfono o CURP) no se crea otra: se usa la suya (solo se completan los
     * datos que le faltaban) y, si no tiene una postulación abierta, se le
     * abre una nueva. Devuelve [ficha, ¿ya existía?].
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: Candidato, 1: bool}
     */
    public function crearOLigar(User $actor, array $entrada, string $ip): array
    {
        $d = $this->validarCv($entrada, true);
        $sedeId = $this->sedeValida($actor, $entrada['sede_id'] ?? null, 'candidatos.crear');
        $existente = $this->buscarFicha(null, $d['telefono'] ?? null, $d['curp'] ?? null);
        if ($existente !== null) {
            return [$this->ligarDesdeRh($actor, $existente, $d, $sedeId, $entrada, $ip), true];
        }

        $candidato = DB::transaction(function () use ($actor, $d, $sedeId, $ip, $entrada) {
            $persona = $this->personaDelPadron($actor, $d['nombre_completo'], $d['telefono'] ?? null);
            $candidato = new Candidato($this->soloCv($d) + ['sede_id' => $sedeId, 'persona_id' => $persona->id, 'origen' => 'rh', 'llegada_en' => now()]);
            $this->aceptarPrivacidad($candidato, $ip, 'rh');
            $this->firmarSiViene($candidato, $entrada, 'rh', $actor);
            $this->ligarVacante($candidato, $entrada);
            $candidato->save();
            app(Postulaciones::class)->crear($candidato, $candidato->only(['sede_id', 'vacante_id', 'departamento_id', 'puesto_id', 'vacante']), 'rh', null);

            return $candidato->refresh();
        });

        $this->evento($candidato, 'registrado', null, 'registrado', 'Capturado por Recursos Humanos.', $actor);
        $this->auditoria->auditar($actor, 'candidatos.creado', $candidato, null, $this->foto($candidato));

        return [$candidato, false];
    }

    /**
     * «Nuevo candidato» de alguien que ya tiene ficha: no se crea otra.
     *
     * @param  array<string, mixed>  $d  datos validados
     * @param  array<string, mixed>  $entrada
     */
    private function ligarDesdeRh(User $actor, Candidato $ficha, array $d, int $sedeId, array $entrada, string $ip): Candidato
    {
        $postulaciones = app(Postulaciones::class);
        $abierta = $postulaciones->abierta($ficha);
        $visible = $this->limitar(Candidato::query(), $actor, 'candidatos.ver')->whereKey($ficha->id)->exists();
        if ($abierta !== null && ! $visible) {
            throw ValidationException::withMessages(['telefono' => 'Esta persona ya tiene una solicitud en proceso en una sede que no tienes a cargo. Pide a Recursos Humanos de esa sede que la atienda.']);
        }

        DB::transaction(function () use ($ficha, $d, $abierta, $sedeId, $entrada, $actor, $postulaciones, $ip) {
            // Solo se completa lo que la ficha no tenía (lo capturado antes no se pierde)
            $faltan = array_filter($this->soloCv($d), fn ($v, $campo) => $v !== null && $v !== [] && ! in_array($campo, Postulaciones::DESDE_FICHA, true)
                && ($ficha->getAttributes()[$campo] ?? null) === null, ARRAY_FILTER_USE_BOTH);
            $ficha->fill($faltan);
            if ($ficha->privacidad_aceptada_en === null) {
                $this->aceptarPrivacidad($ficha, $ip, 'rh');
            }
            $ficha->save();
            if ($abierta === null) {
                $vacanteId = is_numeric($entrada['vacante_id'] ?? null) && Vacante::whereKey((int) $entrada['vacante_id'])->whereIn('estado', ['publicada', 'pausada'])->exists()
                    ? (int) $entrada['vacante_id'] : null;
                $postulaciones->crear($ficha, ['sede_id' => $sedeId, 'vacante_id' => $vacanteId, 'departamento_id' => $d['departamento_id'] ?? null,
                    'puesto_id' => $d['puesto_id'] ?? null, 'vacante' => $d['vacante'] ?? ($vacanteId ? Vacante::whereKey($vacanteId)->value('titulo') : null)], 'rh', $actor);
            }
        });
        $ficha->refresh();
        $this->evento($ficha, 'postulacion', null, $abierta === null ? 'registrado' : null, $abierta === null
            ? 'Recursos Humanos la volvió a registrar: se abrió una nueva postulación en su misma ficha.'
            : 'Recursos Humanos la volvió a registrar: sigue con su postulación en proceso.', $actor);

        return $ficha;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Candidato $candidato, array $entrada, string $ip): Candidato
    {
        $d = $this->validarCv($entrada, $candidato->privacidad_aceptada_en === null);
        $antes = $this->foto($candidato);
        $candidato->fill($this->soloCv($d));
        if (array_key_exists('notas_rh', $entrada)) {
            $notas = trim((string) ($entrada['notas_rh'] ?? ''));
            $candidato->notas_rh = $notas === '' ? null : mb_substr($notas, 0, 3000);
        }
        if ($candidato->privacidad_aceptada_en === null) {
            $this->aceptarPrivacidad($candidato, $ip, 'rh');
        }
        $this->firmarSiViene($candidato, $entrada, 'rh', $actor);
        $this->ligarVacante($candidato, $entrada);
        $candidato->autocaptura_pendiente = false;
        $candidato->save();
        app(Postulaciones::class)->desdeFicha($candidato); // A qué aplica: lo manda la postulación activa
        $this->auditoria->auditar($actor, 'candidatos.actualizado', $candidato, $antes, $this->foto($candidato));

        return $candidato;
    }

    /**
     * Recursos Humanos revisó lo que el candidato capturó en el kiosco.
     */
    public function autocapturaRevisada(User $actor, Candidato $candidato): void
    {
        if ($candidato->autocaptura_pendiente) {
            $candidato->forceFill(['autocaptura_pendiente' => false])->save();
            $this->evento($candidato, 'autocaptura_revisada', null, null, 'Recursos Humanos revisó lo que capturó el candidato.', $actor);
        }
    }

    // ------------------------------------------------------------------- Etapas

    /**
     * Cambia de etapa. «Aprobado por RR. HH.» pide el departamento y avisa a su
     * responsable; «Descartado» pide el motivo.
     */
    public function cambiarEtapa(User $actor, Candidato $candidato, string $etapa, ?string $comentario = null): Candidato
    {
        $comentario = $comentario === null ? null : (trim($comentario) === '' ? null : mb_substr(trim($comentario), 0, 500));
        if (! array_key_exists($etapa, Candidato::ETAPAS) || $etapa === 'contratado' || $etapa === 'registrado') {
            throw new CambioNoPermitido('Elige una etapa válida. Para contratar usa el botón «Contratar».');
        }
        // La que manda es la postulación activa (la ficha es su espejo)
        $postulaciones = app(Postulaciones::class);
        $p = $postulaciones->asegurar($candidato);
        if (! $p->puedePasarA($etapa)) {
            throw new CambioNoPermitido('Un candidato «'.$p->etiquetaEtapa().'» no puede pasar a «'.Candidato::ETAPAS[$etapa].'».');
        }
        if ($etapa === 'descartado' && $comentario === null) {
            throw ValidationException::withMessages(['comentario' => 'Escribe por qué se descarta (lo verá solo Recursos Humanos).']);
        }
        if ($etapa === 'aprobado_rh' && $p->departamento_id === null) {
            throw ValidationException::withMessages(['comentario' => 'Antes de aprobar, indica en la ficha a qué departamento aplica.']);
        }

        $anterior = $p->etapa;
        $cambios = ['etapa' => $etapa];
        $cambios += match ($etapa) {
            'revision' => $p->revision_en === null ? ['revision_en' => now()] : [],
            'aprobado_rh' => ['aprobado_rh_en' => now()],
            'entrevista' => ['entrevista_en' => now()],
            'seleccionado', 'cartera', 'descartado' => ['decision_en' => now(), 'decision_por' => $actor->id],
        };
        if ($etapa === 'descartado') {
            $cambios['motivo_descarte'] = $comentario;
        }
        if (! $postulaciones->cambiar($p, $anterior, $cambios, $actor)) {
            throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
        }
        Candidato::whereKey($candidato->id)->update(['actualizado_por' => $actor->id, 'updated_at' => now()]);
        $candidato->refresh();

        $this->evento($candidato, 'etapa', $anterior, $etapa, $comentario, $actor);
        $this->auditoria->auditar($actor, 'candidatos.etapa', $candidato, ['etapa' => $anterior], ['etapa' => $etapa]);

        // Lo que estaba pendiente con el departamento deja de esperar si RR. HH. decide otra cosa
        if ($anterior === 'aprobado_rh' && $etapa !== 'entrevista') {
            app(Autorizaciones::class)->cancelarDeCandidato($actor, $candidato);
        }
        if ($etapa === 'aprobado_rh') {
            app(Autorizaciones::class)->solicitarCandidato($actor, $candidato);
        }

        return $candidato;
    }

    /**
     * La respuesta del departamento mueve al candidato (lo llama Autorizaciones).
     */
    public function respuestaDepartamento(User $actor, Candidato $candidato, string $estado, ?string $comentario): void
    {
        $nueva = $estado === 'entrevista' ? 'entrevista' : 'cartera';
        $cambios = ['etapa' => $nueva, 'respuesta_departamento_en' => now()]
            + ($nueva === 'entrevista' ? ['entrevista_en' => now()] : ['decision_en' => now(), 'decision_por' => $actor->id]);
        $postulaciones = app(Postulaciones::class);
        if (! $postulaciones->cambiar($postulaciones->asegurar($candidato), 'aprobado_rh', $cambios, $actor)) {
            return;
        }
        Candidato::whereKey($candidato->id)->update(['actualizado_por' => $actor->id, 'updated_at' => now()]);
        $candidato->refresh();
        $texto = $nueva === 'entrevista' ? 'El departamento pidió bajarlo a entrevista.' : 'El departamento no lo aceptó: queda en cartera.';
        $this->evento($candidato, 'etapa', 'aprobado_rh', $nueva, trim($texto.' '.($comentario ?? '')), $actor);
        $this->auditoria->auditar($actor, 'candidatos.etapa', $candidato, ['etapa' => 'aprobado_rh'], ['etapa' => $nueva]);
    }

    /**
     * «Contratar»: crea el colaborador con los datos capturados (y lo que RR. HH.
     * confirma en el diálogo: número de empleado, apellidos, sede, puesto).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function contratar(User $actor, Candidato $candidato, array $entrada): Colaborador
    {
        $postulaciones = app(Postulaciones::class);
        $postulacion = $postulaciones->asegurar($candidato);
        if ($postulacion->etapa !== 'seleccionado') {
            throw new CambioNoPermitido('Solo se contrata a un candidato «Seleccionado».');
        }
        // Los datos oficiales de la solicitud pasan al colaborador (RR. HH. no los vuelve a escribir)
        $datos = array_merge([
            'telefono' => $candidato->telefono, 'sede_id' => $candidato->sede_id, 'departamento_id' => $candidato->departamento_id,
            'puesto_id' => $candidato->puesto_id, 'correo_personal' => $candidato->correo,
            'fecha_nacimiento' => $candidato->fecha_nacimiento?->format('Y-m-d'),
            'nombre' => $candidato->nombre, 'apellido_paterno' => $candidato->apellido_paterno, 'apellido_materno' => $candidato->apellido_materno,
            'curp' => $candidato->curp, 'rfc' => $candidato->rfc, 'nss' => $candidato->nss, 'lugar_nacimiento' => $candidato->lugar_nacimiento,
            'nacionalidad' => $candidato->nacionalidad, 'direccion_completa' => $candidato->domicilioCompleto(),
        ], array_intersect_key($entrada, array_flip(['num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno', 'sede_id', 'departamento_id', 'puesto_id', 'telefono'])));
        $datos = array_filter($datos, fn ($v) => $v !== null && $v !== '');

        $colaborador = DB::transaction(function () use ($actor, $candidato, $datos, $postulaciones, $postulacion) {
            $colaborador = app(AdministradorColaboradores::class)->crear($actor, (int) $candidato->empresa_id, Request::create('/', 'POST', $datos));
            $hecho = $postulaciones->cambiar($postulacion, 'seleccionado', [
                'etapa' => 'contratado', 'contratado_en' => now(), 'colaborador_id' => $colaborador->id, 'decision_en' => $postulacion->decision_en ?? now(),
            ], $actor);
            if (! $hecho) {
                throw new CambioNoPermitido('Otra persona acaba de cambiar a este candidato. Revisa su etapa actual.');
            }
            Candidato::whereKey($candidato->id)->update(['actualizado_por' => $actor->id, 'updated_at' => now()]);

            return $colaborador;
        });
        $candidato->refresh();
        $this->evento($candidato, 'contratado', 'seleccionado', 'contratado', 'Alta como colaborador (núm. '.$colaborador->num_empleado.').', $actor);
        $this->auditoria->auditar($actor, 'candidatos.contratado', $candidato, ['etapa' => 'seleccionado'], ['etapa' => 'contratado', 'colaborador_id' => $colaborador->id]);

        return $colaborador;
    }

    public function eliminar(User $actor, Candidato $candidato): void
    {
        $antes = $this->foto($candidato);
        $documentos = app(DocumentosCandidato::class);
        foreach ($candidato->documentos as $doc) {
            $documentos->borrar($doc->ruta);
        }
        app(Firmas::class)->borrar($candidato->firma_ruta);
        $candidato->delete();
        $this->auditoria->auditar($actor, 'candidatos.eliminado', $candidato, $antes, null);
    }

    // ------------------------------------------------------------ Validación CV

    /**
     * Valida y normaliza la solicitud de empleo (mismas reglas en RR. HH., el
     * kiosco y la bolsa de trabajo).
     *
     * $completa: lo que envía el propio candidato (kiosco o internet) debe
     * traer nombre y apellido paterno, teléfono, al menos 2 referencias
     * personales y la declaración «la información es verdadera». RR. HH.
     * puede capturar por partes.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function validarCv(array $entrada, bool $pidePrivacidad, bool $completa = false): array
    {
        $entrada = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $entrada);
        foreach (['escolaridad', 'experiencia', 'referencias', 'referencias_laborales'] as $lista) {
            // Filas sin ningún dato escrito (las que el formulario deja en blanco) se omiten
            $entrada[$lista] = array_values(array_filter(is_array($entrada[$lista] ?? null) ? $entrada[$lista] : [],
                fn ($f) => is_array($f) && collect($f)->except(['concluido', 'pedir_referencias'])->contains(fn ($v) => is_scalar($v) && trim((string) $v) !== '')));
        }
        foreach (['telefono', 'telefono_fijo', 'emergencia_telefono', 'nss', 'codigo_postal'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = preg_replace('/[\s\-().]+/', '', $entrada[$campo]);
            }
        }
        foreach (['curp', 'rfc'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = mb_strtoupper(str_replace([' ', '-'], '', $entrada[$campo]));
            }
        }
        foreach (['pretension', 'dependientes'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = str_replace([',', '$', ' '], '', $entrada[$campo]);
            }
        }
        foreach ($entrada['experiencia'] as $i => $f) {
            if (isset($f['sueldo_final']) && is_string($f['sueldo_final'])) {
                $entrada['experiencia'][$i]['sueldo_final'] = str_replace([',', '$', ' '], '', $f['sueldo_final']);
            }
        }
        $siNo = ['nullable', 'boolean'];
        $mes = ['nullable', 'regex:/^(19|20)\d{2}-(0[1-9]|1[0-2])$/'];

        $d = Validator::make($entrada, [
            'nombre_completo' => [isset($entrada['nombre']) ? 'nullable' : 'required', 'string', 'min:5', 'max:150'],
            'nombre' => [$completa ? 'required' : 'nullable', 'string', 'max:60'],
            'apellido_paterno' => [$completa || isset($entrada['nombre']) ? 'required' : 'nullable', 'string', 'max:60'],
            'apellido_materno' => ['nullable', 'string', 'max:60'],
            'telefono' => [$completa ? 'required' : 'nullable', 'regex:/^\d{10,15}$/'],
            'correo' => ['nullable', 'email:rfc', 'max:150'],
            'fecha_nacimiento' => ['nullable', 'date_format:Y-m-d', 'before:-15 years', 'after:1930-01-01'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'sexo' => ['nullable', Rule::in(array_keys(Candidato::SEXOS))],
            'lugar_nacimiento' => ['nullable', Rule::in(Colaborador::ESTADOS_NACIMIENTO)],
            'nacionalidad' => ['nullable', 'string', 'max:40'],
            'estado_civil' => ['nullable', Rule::in(array_keys(Candidato::ESTADOS_CIVILES))],
            'dependientes' => ['nullable', 'integer', 'min:0', 'max:20'],
            'curp' => ['nullable', 'regex:'.Colaborador::CURP],
            'rfc' => ['nullable', 'regex:'.Colaborador::RFC],
            'nss' => ['nullable', 'regex:'.Colaborador::NSS],
            'licencia_tipo' => ['nullable', Rule::in(array_keys(Candidato::LICENCIAS))],
            'licencia_vigencia' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'calle_numero' => ['nullable', 'string', 'max:150'],
            'colonia' => ['nullable', 'string', 'max:100'],
            'codigo_postal' => ['nullable', 'regex:/^\d{5}$/'],
            'municipio' => ['nullable', 'string', 'max:100'],
            'estado_domicilio' => ['nullable', Rule::in(array_values(array_diff(Colaborador::ESTADOS_NACIMIENTO, ['Extranjero'])))],
            'tiempo_residencia' => ['nullable', 'string', 'max:40'],
            'telefono_fijo' => ['nullable', 'regex:/^\d{10}$/'],
            'emergencia_nombre' => ['nullable', 'string', 'max:150'],
            'emergencia_parentesco' => ['nullable', 'string', 'max:40'],
            'emergencia_telefono' => ['nullable', 'regex:/^\d{10,15}$/'],
            'departamento_id' => ['nullable', 'integer'],
            'puesto_id' => ['nullable', 'integer'],
            'vacante' => ['nullable', 'string', 'max:150'],
            'escolaridad' => ['array', 'max:'.self::MAX_FILAS],
            'escolaridad.*.nivel' => ['required', Rule::in(array_keys(Candidato::ESCOLARIDAD))],
            'escolaridad.*.institucion' => ['nullable', 'string', 'max:150'],
            'escolaridad.*.titulo' => ['nullable', 'string', 'max:150'],
            'escolaridad.*.periodo' => ['nullable', 'string', 'max:40'],
            'escolaridad.*.documento' => ['nullable', Rule::in(array_keys(Candidato::DOCUMENTOS_ESTUDIO))],
            'escolaridad.*.concluido' => ['nullable', 'boolean'],
            'experiencia' => ['array', 'max:'.self::MAX_FILAS],
            'experiencia.*.empresa' => ['required', 'string', 'max:150'],
            'experiencia.*.puesto' => ['nullable', 'string', 'max:150'],
            'experiencia.*.anos' => ['nullable', 'integer', 'min:0', 'max:60'],
            'experiencia.*.ingreso' => $mes,
            'experiencia.*.salida' => $mes,
            'experiencia.*.sueldo_final' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'experiencia.*.jefe' => ['nullable', 'string', 'max:150'],
            'experiencia.*.jefe_telefono' => ['nullable', 'string', 'max:20'],
            'experiencia.*.motivo_salida' => ['nullable', 'string', 'max:200'],
            'experiencia.*.pedir_referencias' => ['nullable', Rule::in(['si', 'no'])],
            'habilidades' => ['nullable', 'string', 'max:1000'],
            'idiomas' => ['nullable', 'string', 'max:255'],
            'disponibilidad' => ['nullable', Rule::in(array_keys(Candidato::DISPONIBILIDAD))],
            'disponibilidad_notas' => ['nullable', 'string', 'max:255'],
            'pretension' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'referencias' => ['array', $completa ? 'min:2' : 'min:0', 'max:'.self::MAX_FILAS],
            'referencias.*.nombre' => ['required', 'string', 'max:150'],
            'referencias.*.telefono' => [$completa ? 'required' : 'nullable', 'string', 'max:20'],
            'referencias.*.relacion' => ['nullable', 'string', 'max:80'],
            'referencias.*.anos_conocerlo' => ['nullable', 'integer', 'min:0', 'max:90'],
            'referencias_laborales' => ['array', 'max:'.self::MAX_FILAS],
            'referencias_laborales.*.nombre' => ['required', 'string', 'max:150'],
            'referencias_laborales.*.telefono' => ['nullable', 'string', 'max:20'],
            'referencias_laborales.*.relacion' => ['nullable', 'string', 'max:80'],
            'referencias_laborales.*.anos_conocerlo' => ['nullable', 'integer', 'min:0', 'max:90'],
            'medio_vacante' => ['nullable', Rule::in(array_keys(Candidato::MEDIOS_VACANTE))],
            'tiene_familiares' => $siNo,
            'familiares_nombre' => ['nullable', 'string', 'max:150'],
            'trabajo_antes_aqui' => $siNo,
            'rolar_turnos' => $siNo,
            'puede_viajar' => $siNo,
            'cambiar_residencia' => $siNo,
            'fecha_inicio_posible' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'acepta_privacidad' => $pidePrivacidad ? ['accepted'] : ['nullable'],
            'declaracion' => $completa ? ['accepted'] : ['nullable'],
            // La firma la guarda firmar(); aquí solo se pide junto con los demás errores
            'firma' => $completa ? ['required', function (string $atributo, mixed $valor, \Closure $falla) {
                if (! app(Firmas::class)->viene(is_string($valor) ? $valor : null)) {
                    $falla('Falta tu firma: firma en el recuadro con el dedo antes de enviar.');
                }
            }] : ['nullable'],
        ], [
            'nombre_completo.required' => 'Escribe el nombre completo.',
            'nombre_completo.min' => 'Escribe el nombre completo (nombre y apellidos).',
            'nombre.required' => 'Escribe tu nombre (o nombres).',
            'apellido_paterno.required' => 'Escribe el apellido paterno.',
            'telefono.required' => 'Escribe un teléfono (celular) para poder llamarte.',
            'telefono.regex' => 'El teléfono lleva de 10 a 15 números.',
            'correo.email' => 'Revisa el correo: parece incompleto.',
            'fecha_nacimiento.*' => 'Revisa la fecha de nacimiento.',
            'sexo.*' => 'Elige una opción de la lista en «Sexo».',
            'lugar_nacimiento.*' => 'Elige el estado de nacimiento de la lista.',
            'estado_civil.*' => 'Elige el estado civil de la lista.',
            'dependientes.*' => 'Dependientes económicos: escribe un número de 0 a 20.',
            'curp.regex' => 'El CURP no tiene el formato correcto: son 18 letras y números (ej. GOMA850101HQRRRN09).',
            'rfc.regex' => 'El RFC no tiene el formato correcto: son 12 o 13 letras y números (ej. GOMA850101AB1).',
            'nss.regex' => 'El NSS (número de seguro social) lleva exactamente 11 números.',
            'licencia_tipo.*' => 'Elige el tipo de licencia de la lista.',
            'licencia_vigencia.*' => 'Revisa la fecha de vigencia de la licencia.',
            'codigo_postal.regex' => 'El código postal lleva exactamente 5 números.',
            'estado_domicilio.*' => 'Elige el estado de tu domicilio de la lista.',
            'telefono_fijo.regex' => 'El teléfono fijo lleva 10 números (con lada).',
            'emergencia_telefono.regex' => 'El teléfono del contacto de emergencia lleva de 10 a 15 números.',
            'escolaridad.*.nivel.*' => 'En escolaridad, elige el nivel de estudios.',
            'escolaridad.*.documento.*' => 'En escolaridad, elige el documento obtenido de la lista.',
            'experiencia.*.empresa.required' => 'En empleos anteriores, escribe el nombre de la empresa.',
            'experiencia.*.anos.*' => 'En empleos anteriores, los años van de 0 a 60.',
            'experiencia.*.ingreso.*' => 'En empleos anteriores, revisa el mes de ingreso.',
            'experiencia.*.salida.*' => 'En empleos anteriores, revisa el mes de salida.',
            'experiencia.*.sueldo_final.*' => 'En empleos anteriores, el sueldo es un número (sin letras).',
            'experiencia.*.pedir_referencias.*' => 'En empleos anteriores, indica si podemos pedir referencias (sí o no).',
            'referencias.min' => 'Escribe al menos 2 referencias personales (nombre y teléfono).',
            'referencias.*.nombre.required' => 'En referencias personales, escribe el nombre de la persona.',
            'referencias.*.telefono.required' => 'En referencias personales, escribe el teléfono de cada persona.',
            'referencias.*.anos_conocerlo.*' => 'En referencias, los años de conocerlo son un número.',
            'referencias_laborales.*.nombre.required' => 'En referencias laborales, escribe el nombre de la persona.',
            'referencias_laborales.*.anos_conocerlo.*' => 'En referencias, los años de conocerlo son un número.',
            'medio_vacante.*' => 'Elige de la lista cómo te enteraste de la vacante.',
            'fecha_inicio_posible.*' => 'Revisa la fecha en que puedes empezar.',
            'pretension.*' => 'El sueldo que esperas es un número (sin letras).',
            'acepta_privacidad.accepted' => 'Para guardar, marca «Acepto el aviso de privacidad».',
            'declaracion.accepted' => 'Para enviar, marca «Declaro que la información es verdadera».',
            'firma.required' => 'Falta tu firma: firma en el recuadro con el dedo antes de enviar.',
            '*.boolean' => 'Elige «Sí» o «No».',
            '*.max' => 'Ese dato es demasiado largo.',
            '*.array' => 'Revisa la lista.',
        ])->validate();

        // Departamento y puesto: de la empresa y activos
        if (! empty($d['departamento_id']) && ! Departamento::where('activo', true)->whereKey((int) $d['departamento_id'])->exists()) {
            throw ValidationException::withMessages(['departamento_id' => 'Elige un departamento activo de la lista.']);
        }
        if (! empty($d['puesto_id']) && ! Puesto::where('activo', true)->whereKey((int) $d['puesto_id'])->exists()) {
            throw ValidationException::withMessages(['puesto_id' => 'Elige un puesto activo de la lista.']);
        }

        $titulo = fn (?string $v) => $v === null ? null : mb_convert_case(mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($v))), MB_CASE_TITLE);
        foreach (['nombre', 'apellido_paterno', 'apellido_materno'] as $campo) {
            $d[$campo] = $titulo($d[$campo] ?? null);
        }
        $d['nombre_completo'] = ! empty($d['nombre'])
            ? implode(' ', array_filter([$d['nombre'], $d['apellido_paterno'] ?? null, $d['apellido_materno'] ?? null]))
            : $titulo($d['nombre_completo']);
        if (mb_strlen($d['nombre_completo']) > 150) {
            throw ValidationException::withMessages(['nombre' => 'El nombre completo es demasiado largo.']);
        }

        $d['escolaridad'] = array_map(fn ($f) => [
            'nivel' => $f['nivel'], 'institucion' => $this->linea($f['institucion'] ?? null), 'titulo' => $this->linea($f['titulo'] ?? null),
            'periodo' => $this->linea($f['periodo'] ?? null), 'documento' => $f['documento'] ?? null,
            'concluido' => isset($f['documento']) ? $f['documento'] !== 'trunco' : filter_var($f['concluido'] ?? false, FILTER_VALIDATE_BOOL),
        ], $d['escolaridad'] ?? []);
        $d['experiencia'] = array_map(function ($f) {
            $ingreso = $f['ingreso'] ?? null;
            $salida = $f['salida'] ?? null;
            if ($ingreso !== null && $salida !== null && $salida < $ingreso) {
                throw ValidationException::withMessages(['experiencia' => 'En empleos anteriores, el mes de salida no puede ser antes del de ingreso.']);
            }

            return [
                'empresa' => $this->linea($f['empresa'] ?? null), 'puesto' => $this->linea($f['puesto'] ?? null),
                'ingreso' => $ingreso, 'salida' => $salida,
                // Años: se calculan con las fechas (o se conserva el dato anterior)
                'anos' => $ingreso !== null ? $this->anosEntre($ingreso, $salida) : (isset($f['anos']) ? (int) $f['anos'] : null),
                'sueldo_final' => isset($f['sueldo_final']) ? round((float) $f['sueldo_final'], 2) : null,
                'jefe' => $this->linea($f['jefe'] ?? null), 'jefe_telefono' => $this->linea($f['jefe_telefono'] ?? null),
                'motivo_salida' => $this->linea($f['motivo_salida'] ?? null), 'pedir_referencias' => $f['pedir_referencias'] ?? null,
            ];
        }, $d['experiencia'] ?? []);
        foreach (['referencias', 'referencias_laborales'] as $lista) {
            $d[$lista] = array_map(fn ($f) => [
                'nombre' => $this->linea($f['nombre'] ?? null), 'telefono' => $this->linea($f['telefono'] ?? null), 'relacion' => $this->linea($f['relacion'] ?? null),
                'anos_conocerlo' => isset($f['anos_conocerlo']) ? (int) $f['anos_conocerlo'] : null,
            ], $d[$lista] ?? []);
        }
        if (! filter_var($d['tiene_familiares'] ?? false, FILTER_VALIDATE_BOOL)) {
            $d['familiares_nombre'] = null;
        }
        // «Ciudad» (lista y resumen) = municipio del domicilio cuando se captura
        if (! empty($d['municipio'])) {
            $d['ciudad'] = $d['municipio'];
        }

        return $d;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function soloCv(array $d): array
    {
        $campos = ['nombre_completo', 'telefono', 'correo', 'fecha_nacimiento', 'ciudad', 'departamento_id', 'puesto_id', 'vacante',
            'escolaridad', 'experiencia', 'habilidades', 'idiomas', 'disponibilidad', 'disponibilidad_notas', 'pretension', 'referencias',
            // Solicitud de empleo formal
            'nombre', 'apellido_paterno', 'apellido_materno', 'sexo', 'lugar_nacimiento', 'nacionalidad', 'estado_civil', 'dependientes',
            'curp', 'rfc', 'nss', 'licencia_tipo', 'licencia_vigencia',
            'calle_numero', 'colonia', 'codigo_postal', 'municipio', 'estado_domicilio', 'tiempo_residencia', 'telefono_fijo',
            'emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono', 'referencias_laborales',
            'medio_vacante', 'tiene_familiares', 'familiares_nombre', 'trabajo_antes_aqui', 'rolar_turnos', 'puede_viajar', 'cambiar_residencia',
            'fecha_inicio_posible'];
        $r = [];
        foreach ($campos as $c) {
            $v = $d[$c] ?? null;
            $r[$c] = is_string($v) ? trim($v) : $v;
        }
        foreach (['tiene_familiares', 'trabajo_antes_aqui', 'rolar_turnos', 'puede_viajar', 'cambiar_residencia'] as $c) {
            $r[$c] = $r[$c] === null ? null : filter_var($r[$c], FILTER_VALIDATE_BOOL);
        }
        foreach (['escolaridad', 'experiencia', 'referencias', 'referencias_laborales'] as $lista) {
            $r[$lista] = $r[$lista] ?: null;
        }

        return $r;
    }

    /**
     * Declaración «la información es verdadera» y firma autógrafa (disco
     * privado). En el kiosco y en internet es obligatoria; RR. HH. la puede
     * capturar por el candidato (queda quién la capturó).
     */
    public function firmar(Candidato $candidato, ?string $firma, string $medio, ?User $capturo, bool $obligatoria): void
    {
        $firmas = app(Firmas::class);
        if (! $firmas->viene($firma)) {
            if ($obligatoria) {
                throw ValidationException::withMessages(['firma' => 'Falta tu firma: firma en el recuadro con el dedo antes de enviar.']);
            }

            return;
        }
        $anterior = $candidato->firma_ruta;
        $ruta = $firmas->guardar($firma, 'candidatos', 'firma', 'la firma de la solicitud');
        $candidato->forceFill([
            'firma_ruta' => $ruta, 'firma_en' => now(), 'firma_medio' => $medio, 'firma_capturada_por' => $capturo?->id,
            'declaracion_aceptada_en' => now(),
        ]);
        if ($anterior !== null && $anterior !== $ruta) {
            $firmas->borrar($anterior);
        }
    }

    /**
     * RR. HH. captura la firma por el candidato (opcional): si trae firma, pide
     * también la declaración.
     *
     * @param  array<string, mixed>  $entrada
     */
    private function firmarSiViene(Candidato $candidato, array $entrada, string $medio, User $actor): void
    {
        if (! app(Firmas::class)->viene(is_string($entrada['firma'] ?? null) ? $entrada['firma'] : null)) {
            return;
        }
        if (! filter_var($entrada['declaracion'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw ValidationException::withMessages(['declaracion' => 'Para guardar la firma, marca «Declaro que la información es verdadera».']);
        }
        $this->firmar($candidato, $entrada['firma'], $medio, $actor, false);
    }

    /**
     * Vacantes (lección 36): RR. HH. liga al candidato con una vacante de la
     * bolsa (no borradores; la que ya tenía se conserva aunque esté cerrada).
     *
     * @param  array<string, mixed>  $entrada
     */
    private function ligarVacante(Candidato $candidato, array $entrada): void
    {
        if (! array_key_exists('vacante_id', $entrada)) {
            return;
        }
        $id = is_numeric($entrada['vacante_id']) ? (int) $entrada['vacante_id'] : null;
        if ($id !== null && $id !== $candidato->vacante_id && ! Vacante::whereKey($id)->whereIn('estado', ['publicada', 'pausada'])->exists()) {
            throw ValidationException::withMessages(['vacante_id' => 'Elige una vacante publicada (o en pausa) de la lista.']);
        }
        $candidato->forceFill(['vacante_id' => $id]);
        if ($id !== null && ($candidato->vacante === null || $candidato->vacante === '') && $candidato->puesto_id === null) {
            $candidato->vacante = mb_substr((string) Vacante::whereKey($id)->value('titulo'), 0, 150);
        }
    }

    /** Años completos entre dos meses «AAAA-MM» (sin salida = hasta hoy). */
    private function anosEntre(string $ingreso, ?string $salida): int
    {
        $fin = $salida ?? now()->format('Y-m');
        $meses = ((int) substr($fin, 0, 4) - (int) substr($ingreso, 0, 4)) * 12 + ((int) substr($fin, 5, 2) - (int) substr($ingreso, 5, 2));

        return max(0, intdiv($meses, 12));
    }

    public function aceptarPrivacidad(Candidato $candidato, string $ip, string $medio): void
    {
        $empresa = Empresa::find($candidato->empresa_id ?? app(Tenant::class)->empresaId());
        $candidato->forceFill([
            'privacidad_aceptada_en' => now(), 'privacidad_ip' => mb_substr($ip, 0, 45),
            'privacidad_version' => $empresa ? $this->ajustes->versionPrivacidad($empresa) : null, 'privacidad_medio' => $medio,
        ]);
    }

    // ------------------------------------------------------------------ Ayudas

    public function evento(Candidato $candidato, string $evento, ?string $de, ?string $a, ?string $comentario, ?User $actor): void
    {
        $e = new CandidatoEvento(['candidato_id' => $candidato->id, 'evento' => $evento, 'etapa_anterior' => $de, 'etapa_nueva' => $a,
            'comentario' => $comentario === null ? null : mb_substr($comentario, 0, 500), 'user_id' => $actor?->id]);
        $e->forceFill(['empresa_id' => $candidato->empresa_id])->save();
    }

    /**
     * Aviso a Recursos Humanos (campana + correo) en el momento en que llega un
     * candidato (o vuelve: entrevista, documentos, firma, otra postulación).
     */
    public function avisarLlegada(Candidato $candidato, ?User $registro, string $vieneA = 'busca_empleo', bool $primeraVez = true, ?string $nota = null): void
    {
        $candidato->loadMissing(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);
        $usuarios = $this->destinatarios->conPermiso((int) $candidato->empresa_id, 'candidatos.editar', (int) $candidato->sede_id)
            ->reject(fn (User $u) => $registro !== null && $u->id === $registro->id);
        $puesto = $candidato->puestoVisible();
        $texto = trim(($vieneA !== 'busca_empleo' ? 'Viene a: '.(Acceso::VIENE_A[$vieneA] ?? $vieneA).'. ' : '')
            .($puesto ? 'Aplica a: '.$puesto.'. ' : '').($candidato->departamento ? 'Departamento: '.$candidato->departamento->nombre.'. ' : '')
            .'Sede: '.$candidato->sede?->nombre.'.'.($nota ? ' '.$nota : ''));
        $titulo = ($primeraVez ? 'Llegó un candidato: ' : 'Volvió a la caseta: ').$candidato->nombre_completo;
        $this->notificaciones->avisar((int) $candidato->empresa_id, $usuarios->pluck('id'), 'candidato_llegada', [
            'titulo' => $titulo,
            'texto' => $texto,
            'url' => route('candidatos.show', $candidato->id),
            'referencia_tipo' => 'candidato', 'referencia_id' => $candidato->id,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $candidato->empresa_id, 'candidato_llegada', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto, 'Registró: '.($registro?->name ?? 'Kiosco').'.'],
                [['Abrir su ficha', route('candidatos.show', $candidato->id)]]));
        $candidato->forceFill(['avisado_rh_en' => now()])->save();
    }

    private function sedeValida(User $actor, mixed $sedeId, string $permiso): int
    {
        if (! is_numeric($sedeId) || ! $this->sedesParaElegir($actor, $permiso)->contains('id', (int) $sedeId)) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista.']);
        }

        return (int) $sedeId;
    }

    /**
     * El candidato queda en el Padrón de personas (visitante, «Prospecto de RR. HH.»):
     * si ya hay una persona activa con ese nombre, se usa.
     */
    public function personaDelPadron(User $actor, string $nombre, ?string $telefono): Persona
    {
        $existente = Persona::where('activo', true)->where('tipo', 'visitante')
            ->whereRaw('LOWER(nombre_completo) = ?', [mb_strtolower($nombre)])->orderBy('id')->first();
        if ($existente !== null) {
            if ($existente->categoria === 'general') {
                $existente->forceFill(['categoria' => 'prospecto_rrhh'])->save();
            }

            return $existente;
        }
        $persona = Persona::create(['tipo' => 'visitante', 'categoria' => 'prospecto_rrhh', 'nombre_completo' => $nombre, 'telefono' => $telefono,
            'motivo_visita' => 'Candidato de Recursos Humanos']);
        $this->auditoria->auditar($actor, 'visitantes.creado', $persona, null, $persona->only(['tipo', 'categoria', 'nombre_completo']));

        return $persona;
    }

    private function linea(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', $v));

        return $t === '' ? null : $t;
    }

    /**
     * Foto para la auditoría: sin datos de contacto ni CV (dato personal).
     *
     * @return array<string, mixed>
     */
    public function foto(Candidato $c): array
    {
        // CURP, RFC y NSS solo enmascarados (se sabe que cambiaron, no su valor); domicilio y emergencia nunca
        return $c->only(['sede_id', 'nombre_completo', 'etapa', 'departamento_id', 'puesto_id', 'vacante', 'vacante_id', 'origen', 'acceso_id', 'colaborador_id'])
            + array_filter(['curp' => Colaborador::enmascarar($c->curp), 'rfc' => Colaborador::enmascarar($c->rfc), 'nss' => Colaborador::enmascarar($c->nss),
                'firmada' => $c->firma_ruta !== null ? true : null], fn ($v) => $v !== null);
    }
}
