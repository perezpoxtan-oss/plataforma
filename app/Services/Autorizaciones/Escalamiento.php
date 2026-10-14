<?php

namespace App\Services\Autorizaciones;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Delegacion;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Recepcion\Destinatarios;
use App\Services\Recepcion\PanelRecepcion;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Lo que espera tiene reloj, color y suplente (regla 3 de la experiencia):
 *
 *  - «Espera a»: para cada acceso pendiente, quién debe responder (el
 *    responsable del departamento o su delegado; Recursos Humanos; o el host
 *    del proveedor/contratista), cuántos minutos lleva esperando y el color
 *    (ámbar desde «recordar_minutos», rojo desde «escalar_minutos»; sin
 *    ajustes, los umbrales del panel de Recepción). La caseta lo ve en la
 *    tarjeta y lo refresca con el mismo sondeo que cambia las tarjetas.
 *  - Escalamiento (tarea programada plataforma:escalar-autorizaciones, en
 *    proceso): a los X minutos se reenvía el aviso a quien debe responder
 *    (campana y correo); a los Y se avisa a los suplentes del departamento (o
 *    sus delegados) y al Jefe de seguridad de la sede; para Recursos Humanos,
 *    al resto de RR. HH. de la empresa y al Jefe de seguridad. Un aviso por
 *    nivel, nunca repetido; cada uno queda en la auditoría y la caseta recibe
 *    «Se escaló a…».
 */
class Escalamiento
{
    public function __construct(
        private readonly Autorizaciones $autorizaciones,
        private readonly AjustesRecepcion $ajustes,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
        private readonly Tenant $tenant,
    ) {}

    // -------------------------------------------------------------- Espera a

    /**
     * «Espera a» de varios accesos pendientes, sin consultas por tarjeta:
     * acceso_id => [texto, nombres, minutos, nivel, desde (timestamp)].
     *
     * @param  Collection<int, Acceso>  $accesos  con autorizacionDepartamento.departamento, departamento y host cargados
     * @return array<int, array{texto: string, nombres: string, minutos: int, nivel: string, desde: int, escalado: ?string}>
     */
    public function esperasPara(Collection $accesos, ?Empresa $empresa = null): array
    {
        $pendientes = $accesos->filter(fn (Acceso $a) => $a->estado === 'pendiente');
        if ($pendientes->isEmpty()) {
            return [];
        }
        $empresa ??= Empresa::find($this->tenant->empresaId());
        [$ambar, $roja] = $this->umbrales($empresa);

        // Responsables (o delegados) por departamento y sede, una consulta por par
        $avisar = [];
        $escaladosIds = [];
        foreach ($pendientes as $a) {
            $aut = $this->autorizacionDe($a);
            if ($aut !== null && $aut->tipo === 'visita' && $aut->departamento_id !== null) {
                $avisar[$aut->departamento_id.'-'.$aut->sede_id] ??= $this->autorizaciones->aQuienAvisar((int) $aut->departamento_id, (int) $aut->sede_id);
            }
            foreach ((array) ($aut?->escalado_a ?? []) as $id) {
                $escaladosIds[] = (int) $id;
            }
        }
        $escalados = $escaladosIds === [] ? collect() : User::whereIn('id', array_unique($escaladosIds))->get(['id', 'name'])->keyBy('id');

        $resultado = [];
        foreach ($pendientes as $a) {
            $aut = $this->autorizacionDe($a);
            $minutos = (int) max(0, $a->entrada_at?->diffInMinutes(now()) ?? 0);
            $nombres = '';
            $escaladoA = $aut !== null ? collect((array) ($aut->escalado_a ?? []))->map(fn ($id) => $escalados[(int) $id]->name ?? null)->filter()->join(', ', ' y ') : '';
            if ($aut !== null && $aut->tipo === 'recepcion') {
                $texto = 'Espera a: Recursos Humanos';
            } elseif ($aut !== null && $aut->tipo === 'visita') {
                $nombres = ($avisar[$aut->departamento_id.'-'.$aut->sede_id] ?? collect())->pluck('name')->join(', ', ' y ');
                $depto = $aut->departamento?->nombre ?? $a->departamento?->nombre;
                $texto = 'Espera a: '.($nombres !== '' ? $nombres : 'el responsable').($depto ? ' ('.$depto.')' : '');
            } elseif (in_array($a->tipo, Acceso::CON_AUTORIZACION, true) && $a->autorizacion === Acceso::SIN_RESPONSABLE) {
                $texto = 'Sin responsable en '.($a->departamento?->nombre ?? 'el departamento').': se autoriza por excepción';
            } elseif (in_array($a->tipo, Acceso::CON_AUTORIZACION, true)) {
                $nombres = $a->host?->nombreCompleto() ?? '';
                $texto = 'Confirma con el host: '.($nombres !== '' ? $nombres : '—');
            } else {
                $texto = 'Espera autorización';
            }
            $resultado[(int) $a->id] = [
                'texto' => $texto,
                'nombres' => $nombres,
                'minutos' => $minutos,
                'nivel' => $minutos >= $roja ? 'roja' : ($minutos >= $ambar ? 'ambar' : 'normal'),
                'desde' => $a->entrada_at?->getTimestamp() ?? 0,
                'escalado' => $escaladoA !== '' ? 'Se escaló a: '.$escaladoA : null,
            ];
        }

        return $resultado;
    }

    /**
     * «Espera a» de un acceso.
     *
     * @return array{texto: string, nombres: string, minutos: int, nivel: string, desde: int, escalado: ?string}
     */
    public function esperaDe(Acceso $acceso): array
    {
        $acceso->loadMissing(['autorizacionDepartamento.departamento:id,nombre', 'departamento:id,nombre', 'host:id,nombre,apellido_paterno,apellido_materno']);

        return $this->esperasPara(collect([$acceso]))[(int) $acceso->id]
            ?? ['texto' => '', 'nombres' => '', 'minutos' => 0, 'nivel' => 'normal', 'desde' => 0, 'escalado' => null];
    }

    /** @return array{0: int, 1: int} [ámbar, rojo] en minutos */
    public function umbrales(?Empresa $empresa): array
    {
        if ($empresa === null) {
            return [PanelRecepcion::ESPERA_AMBAR, PanelRecepcion::ESPERA_ROJA];
        }

        return [$this->ajustes->recordarMinutos($empresa), $this->ajustes->escalarMinutos($empresa)];
    }

    private function autorizacionDe(Acceso $a): ?Autorizacion
    {
        $aut = $a->relationLoaded('autorizacionDepartamento') ? $a->autorizacionDepartamento : $a->autorizacionDepartamento()->with('departamento:id,nombre')->first();

        return $aut !== null && $aut->pendiente() ? $aut : null;
    }

    // ----------------------------------------------------------- Escalamiento

    /**
     * Recorre las empresas activas y recuerda o escala lo que ya tardó. Para
     * la tarea programada (corre en proceso, sin exec ni proc_open).
     *
     * @return array{recordatorios: int, escalamientos: int}
     */
    public function escalar(): array
    {
        $total = ['recordatorios' => 0, 'escalamientos' => 0];
        foreach (Empresa::where('activo', true)->get() as $empresa) {
            $hecho = $this->escalarEmpresa($empresa);
            $total['recordatorios'] += $hecho['recordatorios'];
            $total['escalamientos'] += $hecho['escalamientos'];
        }

        return $total;
    }

    /**
     * Lo mismo para una empresa. Solo toca lo que ya pasó del umbral y no se
     * avisó (recordatorio_en / escalado_en vacíos): nunca repite un aviso.
     *
     * @return array{recordatorios: int, escalamientos: int}
     */
    public function escalarEmpresa(Empresa $empresa): array
    {
        [$x, $y] = $this->umbrales($empresa);

        return $this->tenant->conEmpresa($empresa->id, function () use ($empresa, $x, $y) {
            $cuenta = ['recordatorios' => 0, 'escalamientos' => 0];
            $pendientes = $this->queTardan($x, $y)
                ->with(['acceso', 'departamento:id,nombre', 'sede:id,nombre', 'candidato:id,nombre_completo'])
                ->orderBy('solicitada_en')->limit(500)->get();
            foreach ($pendientes as $a) {
                $minutos = $a->minutosEspera();
                if ($minutos >= $x && $a->recordatorio_en === null) {
                    $cuenta['recordatorios'] += $this->recordar($empresa, $a) ? 1 : 0;
                }
                if ($minutos >= $y && $a->escalado_en === null) {
                    $cuenta['escalamientos'] += $this->escalarUna($empresa, $a) ? 1 : 0;
                }
            }

            return $cuenta;
        });
    }

    /**
     * Al refrescar la bitácora (hosting sin cron confiable): si en esta empresa
     * ya tocaba recordar o escalar algo, se hace ahora, a lo mucho una vez por
     * minuto y sin que un correo fallido tire la pantalla. Devuelve si hizo algo.
     */
    public function escalarSiToca(int $empresaId): bool
    {
        if (! Cache::add('escalamiento-autorizaciones:'.$empresaId, 1, 60)) {
            return false;
        }
        $empresa = Empresa::find($empresaId);
        if ($empresa === null || ! $this->toca($empresa)) {
            return false;
        }
        try {
            $hecho = $this->escalarEmpresa($empresa);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return $hecho['recordatorios'] + $hecho['escalamientos'] > 0;
    }

    /** ¿Hay alguna autorización de la empresa que ya pasó del umbral y no se avisó? (una consulta) */
    public function toca(Empresa $empresa): bool
    {
        [$x, $y] = $this->umbrales($empresa);

        return $this->tenant->conEmpresa($empresa->id, fn () => $this->queTardan($x, $y)->exists());
    }

    /** @return Builder<Autorizacion> */
    private function queTardan(int $x, int $y): Builder
    {
        return Autorizacion::where('estado', 'pendiente')->whereIn('tipo', ['visita', 'recepcion'])
            ->whereHas('acceso', fn ($q) => $q->where('estado', 'pendiente'))
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->whereNull('recordatorio_en')->where('solicitada_en', '<=', now()->subMinutes($x)))
                ->orWhere(fn ($e) => $e->whereNull('escalado_en')->where('solicitada_en', '<=', now()->subMinutes($y))));
    }

    /**
     * Nivel 1: se reenvía el aviso (campana con botones y correo) a quienes ya
     * lo recibieron. Si nadie lo recibió, se calcula de nuevo a quién.
     */
    public function recordar(Empresa $empresa, Autorizacion $a): bool
    {
        $usuarios = $this->avisadosDe($a);
        $a->forceFill(['recordatorio_en' => now()])->saveQuietly();
        if ($usuarios->isEmpty()) {
            return false;
        }
        $nombre = $this->nombreDe($a);
        $minutos = $a->minutosEspera();
        $titulo = "Recordatorio: {$nombre} lleva {$minutos} min esperando tu respuesta";
        $texto = $a->tipo === 'recepcion'
            ? 'Sigue en caseta esperando que digas «Que pase». Sede: '.($a->sede?->nombre ?? '').'.'
            : 'Sigue en caseta esperando la autorización de '.($a->departamento?->nombre ?? 'tu departamento').'. Sede: '.($a->sede?->nombre ?? '').'.';
        $this->avisar($a, $usuarios, $titulo, $texto, true);
        $this->auditar($a, 'autorizaciones.recordatorio', ['minutos' => $minutos, 'avisados' => $usuarios->pluck('id')->all()]);

        return true;
    }

    /**
     * Nivel 2: suplentes del departamento y Jefe de seguridad de la sede; para
     * Recursos Humanos, el resto de RR. HH. y el Jefe de seguridad. La caseta se entera.
     */
    public function escalarUna(Empresa $empresa, Autorizacion $a): bool
    {
        $usuarios = $this->aQuienEscalar($a);
        $a->forceFill(['escalado_en' => now(), 'escalado_a' => $usuarios->pluck('id')->map(fn ($id) => (int) $id)->values()->all()])->saveQuietly();
        if ($usuarios->isEmpty()) {
            $this->auditar($a, 'autorizaciones.escalada', ['minutos' => $a->minutosEspera(), 'avisados' => [], 'sin_a_quien' => true]);

            return false;
        }
        $nombre = $this->nombreDe($a);
        $minutos = $a->minutosEspera();
        $titulo = "Nadie ha respondido: {$nombre} lleva {$minutos} min en caseta";
        $texto = $a->tipo === 'recepcion'
            ? 'Espera que Recursos Humanos diga «Que pase» y nadie ha contestado. Sede: '.($a->sede?->nombre ?? '').'.'
            : 'Espera la autorización de '.($a->departamento?->nombre ?? 'su departamento').' y su responsable no ha contestado. Sede: '.($a->sede?->nombre ?? '').'.';
        // Quien puede responder recibe los botones; el Jefe de seguridad, el enlace a la bitácora para confirmar en caseta
        $responden = $usuarios->filter(fn (User $u) => $this->autorizaciones->puedeResponder($u, $a))->values();
        $soloVen = $usuarios->reject(fn (User $u) => $responden->contains('id', $u->id))->values();
        if ($responden->isNotEmpty()) {
            $this->avisar($a, $responden, $titulo, $texto, true);
        }
        if ($soloVen->isNotEmpty()) {
            $this->notificaciones->avisar((int) $a->empresa_id, $soloVen->pluck('id'), 'autorizacion_escalada', [
                'titulo' => $titulo, 'texto' => $texto.' Si te lo confirman por teléfono o en persona, usa Confirmar Autorización en la bitácora.',
                'url' => route('accesos.index', ['pestana' => 'pendientes']), 'referencia_tipo' => 'autorizacion', 'referencia_id' => $a->id,
            ]);
            app(AvisosCorreo::class)->recepcion((int) $a->empresa_id, $a->tipo === 'recepcion' ? 'candidato_llegada' : 'autorizacion_departamento',
                $soloVen->pluck('email')->filter()->values()->all(), new AvisoRecepcion($titulo, [$texto, 'Confirma en la bitácora de accesos si te lo autorizan por otro medio.'], []));
        }
        // La caseta se entera de a quién se escaló
        if ($a->acceso?->creado_por !== null) {
            $this->notificaciones->avisar((int) $a->empresa_id, [(int) $a->acceso->creado_por], 'autorizacion_escalada', [
                'titulo' => 'Se escaló a '.$usuarios->pluck('name')->join(', ', ' y ').': '.$a->acceso->nombre,
                'texto' => "Lleva {$minutos} min esperando y nadie ha respondido; ya se avisó a ".($a->tipo === 'recepcion' ? 'el resto de Recursos Humanos y al Jefe de seguridad' : 'los suplentes y al Jefe de seguridad').'.',
                'url' => route('accesos.index', ['pestana' => 'pendientes']),
            ]);
        }
        $this->auditar($a, 'autorizaciones.escalada', ['minutos' => $minutos, 'avisados' => $usuarios->pluck('id')->all()]);

        return true;
    }

    /**
     * A quién se escala: los suplentes del departamento en la sede (o sus
     * delegados) y el Jefe de seguridad de la sede. Para Recepción, el resto
     * de Recursos Humanos de la empresa (los de la sede ya recibieron el aviso
     * y el recordatorio) y el Jefe de seguridad de la sede.
     *
     * @return Collection<int, User>
     */
    public function aQuienEscalar(Autorizacion $a): Collection
    {
        $jefes = $this->jefesDeSeguridad((int) $a->empresa_id, (int) $a->sede_id);
        if ($a->tipo === 'recepcion') {
            $yaAvisados = collect((array) ($a->avisados ?? []))->map(fn ($id) => (int) $id);
            $rh = $this->destinatarios->conPermisoEnAlgunaSede((int) $a->empresa_id, 'candidatos.editar')
                ->reject(fn (User $u) => $yaAvisados->contains($u->id));

            return $rh->concat($jefes)->unique('id')->values();
        }
        $suplentes = $this->autorizaciones->responsablesDe((int) $a->departamento_id, (int) $a->sede_id)
            ->filter(fn ($r) => (bool) $r->es_suplente)->pluck('usuario');
        $delegaciones = Delegacion::activas()->with('delegado:id,name,email,activo')
            ->whereIn('user_id', $suplentes->pluck('id'))->orderByDesc('id')->get()->unique('user_id')->keyBy('user_id');
        $usuarios = $suplentes->map(fn (User $u) => ($d = $delegaciones[$u->id] ?? null) && $d->delegado?->activo ? $d->delegado : $u);
        // Completos (los responsables vienen con pocas columnas) para revisar sus permisos
        $ids = $usuarios->concat($jefes)->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->where('activo', true)->get()->sortBy(fn (User $u) => $ids->search($u->id))->values();
    }

    /**
     * Jefe de seguridad de la sede: usuarios activos con el rol «Jefe de
     * seguridad» que alcancen la sede; si la empresa no usa ese rol, quienes
     * pueden aprobar accesos en ella.
     *
     * @return Collection<int, User>
     */
    public function jefesDeSeguridad(int $empresaId, int $sedeId): Collection
    {
        $aprueban = $this->destinatarios->conPermiso($empresaId, 'accesos.aprobar', $sedeId);
        $jefes = $aprueban->filter(fn (User $u) => $u->roles()->where('roles.activo', true)->whereRaw('LOWER(roles.nombre) LIKE ?', ['%jefe de seguridad%'])->exists())->values();

        return $jefes->isNotEmpty() ? $jefes : $aprueban->values();
    }

    // ------------------------------------------------------------------ Ayudas

    /** Nombre de quien espera, en mayúsculas y minúsculas (la caseta lo guarda en mayúsculas). */
    private function nombreDe(Autorizacion $a): string
    {
        return mb_convert_case(mb_strtolower($a->titulo()), MB_CASE_TITLE);
    }

    /** @return Collection<int, User> */
    private function avisadosDe(Autorizacion $a): Collection
    {
        $ids = collect((array) ($a->avisados ?? []))->map(fn ($id) => (int) $id)->filter()->unique();
        if ($ids->isNotEmpty()) {
            return User::whereIn('id', $ids)->where('activo', true)->get();
        }
        if ($a->tipo === 'recepcion') {
            return $this->autorizaciones->destinatariosRecepcion((int) $a->empresa_id, (int) $a->sede_id);
        }

        return $this->autorizaciones->aQuienAvisar((int) $a->departamento_id, (int) $a->sede_id);
    }

    /**
     * Campana con los botones de respuesta y correo con botones firmados (como el aviso original).
     *
     * @param  Collection<int, User>  $usuarios
     */
    private function avisar(Autorizacion $a, Collection $usuarios, string $titulo, string $texto, bool $conBotones): void
    {
        $acciones = [];
        $botones = [];
        if ($conBotones) {
            foreach (array_keys(Autorizacion::RESPUESTAS[$a->tipo]) as $respuesta) {
                $acciones[] = ['etiqueta' => Autorizacion::BOTONES[$respuesta], 'url' => route('autorizaciones.responder', $a->id),
                    'campos' => ['respuesta' => $respuesta],
                    'estilo' => in_array($respuesta, Autorizacion::NEGATIVAS, true) ? 'rechazar' : ($respuesta === 'espere' ? 'esperar' : 'aceptar')];
                $botones[] = [Autorizacion::BOTONES[$respuesta], URL::temporarySignedRoute('autorizaciones.confirmar',
                    now()->addHours(Autorizaciones::HORAS_ENLACE_CORREO), ['autorizacion' => $a->id, 'respuesta' => $respuesta])];
            }
        }
        $this->notificaciones->avisar((int) $a->empresa_id, $usuarios->pluck('id'), $a->tipo === 'recepcion' ? 'autorizacion_recepcion' : 'autorizacion_visita', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('autorizaciones.show', $a->id),
            'referencia_tipo' => 'autorizacion', 'referencia_id' => $a->id, 'acciones' => $acciones,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $a->empresa_id, $a->tipo === 'recepcion' ? 'candidato_llegada' : 'autorizacion_departamento',
            $usuarios->pluck('email')->filter()->values()->all(), new AvisoRecepcion($titulo, [$texto], $botones));
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditar(Autorizacion $a, string $evento, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $a->empresa_id, 'user_id' => null, 'evento' => $evento,
            'auditable_type' => Autorizacion::class, 'auditable_id' => $a->id, 'antes' => ['estado' => 'pendiente'], 'despues' => $despues, 'ip' => null,
        ]);
    }
}
