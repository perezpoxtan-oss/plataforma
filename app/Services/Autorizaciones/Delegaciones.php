<?php

namespace App\Services\Autorizaciones;

use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Responsables por departamento y delegaciones («Delegar mis aprobaciones»,
 * antes «No molestar»; C-12: vive en Mi cuenta).
 *
 *  - Responsables: Recursos Humanos o Dirección (autorizaciones.configurar)
 *    eligen el titular y los suplentes de cada departamento, para todas sus
 *    sedes o para una.
 *  - Delegación: un responsable delega sus autorizaciones a otro usuario de
 *    una fecha y hora a otra (máximo 90 días). Quien configura puede delegar
 *    por otro. Mientras esté activa, los avisos van al delegado. Se audita.
 */
class Delegaciones
{
    public const MAX_DIAS = 90;

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
        private readonly HoraLocal $hora,
    ) {}

    /**
     * Usuarios que pueden ser responsables o delegados (activos, de la empresa,
     * con permiso para responder).
     *
     * @return Collection<int, User>
     */
    public function candidatosAResponsable(int $empresaId): Collection
    {
        return $this->destinatarios->conPermisoEnAlgunaSede($empresaId, 'autorizaciones.responder')->sortBy('name')->values();
    }

    /**
     * C-12 · «Delegar mis aprobaciones»: lo que se delega, permiso => qué es.
     * Delega quien puede aprobar al menos una de estas cosas, y en quien
     * pueda aprobar al menos una. Cada módulo solo le pasa al delegado lo que
     * él puede aprobar; lo demás le sigue llegando al titular.
     */
    public const APROBACIONES = [
        'autorizaciones.responder' => 'visitas',
        'pases_salida.aprobar' => 'pases de salida',
        'reservas.aprobar' => 'reservas',
        'candidatos.evaluar' => 'entrevistas',
    ];

    /** ¿Aprueba algo que se pueda delegar? */
    public function puedeDelegar(User $usuario): bool
    {
        return $this->queAprueba($usuario) !== [];
    }

    /**
     * Lo que este usuario aprueba («visitas», «pases de salida»…).
     *
     * @return list<string>
     */
    public function queAprueba(User $usuario): array
    {
        return array_values(array_filter(self::APROBACIONES, fn ($que, $permiso) => $usuario->can($permiso), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * En quién se puede delegar: usuarios activos de la empresa que aprueban algo.
     *
     * @return Collection<int, User>
     */
    public function candidatosADelegado(int $empresaId): Collection
    {
        $ids = collect();
        foreach (array_keys(self::APROBACIONES) as $permiso) {
            $ids = $ids->merge($this->destinatarios->conPermisoEnAlgunaSede($empresaId, $permiso)->pluck('id'));
        }

        return User::whereIn('id', $ids->unique()->all())->where('activo', true)->orderBy('name')->get();
    }

    /** Su delegación activa hoy (a un delegado activo), si la tiene. */
    public function activaDe(User $usuario): ?Delegacion
    {
        return Delegacion::activas()->with('delegado:id,name,activo')->where('user_id', $usuario->id)->orderByDesc('id')->get()
            ->first(fn (Delegacion $d) => (bool) $d->delegado?->activo);
    }

    /**
     * Guarda los responsables de un departamento (reemplaza la lista).
     *
     * @param  array<int, mixed>  $filas  [{user_id, sede_id, es_suplente}]
     */
    public function guardarResponsables(User $actor, int $empresaId, Departamento $departamento, array $filas): void
    {
        $candidatos = $this->candidatosAResponsable($empresaId)->keyBy('id');
        $validos = $candidatos->keys()->map(fn ($id) => (int) $id)->all();
        $cobertura = app(Cobertura::class);
        $sedesDepto = $departamento->todas_las_sedes ? Sede::where('activo', true)->pluck('id')->all() : $departamento->sedes()->pluck('sedes.id')->all();
        $limpias = [];
        foreach (array_values($filas) as $i => $f) {
            if (! is_array($f) || empty($f['user_id'])) {
                continue;
            }
            $userId = (int) $f['user_id'];
            if (! in_array($userId, $validos, true)) {
                throw ValidationException::withMessages(["responsables.$i.user_id" => 'Elige un usuario activo de la lista (debe poder responder autorizaciones).']);
            }
            $sedeId = empty($f['sede_id']) ? null : (int) $f['sede_id'];
            if ($sedeId !== null && ! in_array($sedeId, $sedesDepto, true)) {
                throw ValidationException::withMessages(["responsables.$i.sede_id" => 'Elige una sede donde exista este departamento.']);
            }
            // A-04: solo las sedes a las que esa cuenta puede entrar para responder; «Todas» solo con alcance de empresa
            $alcance = $cobertura->sedesDe($candidatos[$userId]);
            if ($alcance !== null && $sedeId === null) {
                throw ValidationException::withMessages(["responsables.$i.sede_id" => $candidatos[$userId]->name.' solo puede responder en '
                    .Sede::whereIn('id', $alcance)->orderBy('nombre')->pluck('nombre')->join(', ', ' y ').': elige esa sede en lugar de «Todas sus sedes».']);
            }
            if ($alcance !== null && ! in_array($sedeId, array_map('intval', $alcance), true)) {
                throw ValidationException::withMessages(["responsables.$i.sede_id" => $candidatos[$userId]->name.' no puede entrar a esa sede: elige una de sus sedes o a otra persona.']);
            }
            if (isset($limpias[$userId])) {
                throw ValidationException::withMessages(["responsables.$i.user_id" => 'Ese usuario ya está en la lista.']);
            }
            $limpias[$userId] = ['sede_id' => $sedeId, 'es_suplente' => filter_var($f['es_suplente'] ?? false, FILTER_VALIDATE_BOOL)];
        }
        if (count($limpias) > 10) {
            throw ValidationException::withMessages(['responsables' => 'Máximo 10 responsables por departamento.']);
        }

        $antes = $this->resumen($departamento->id);
        DB::transaction(function () use ($departamento, $limpias) {
            DepartamentoResponsable::where('departamento_id', $departamento->id)->whereNotIn('user_id', array_keys($limpias))->delete();
            foreach ($limpias as $userId => $datos) {
                DepartamentoResponsable::updateOrCreate(['departamento_id' => $departamento->id, 'user_id' => $userId], $datos);
            }
        });
        $this->auditoria->auditar($actor, 'autorizaciones.responsables', $departamento, ['responsables' => $antes], ['responsables' => $this->resumen($departamento->id)]);
        Cobertura::revisarDespues($empresaId); // C-08: el aviso «Sin quien autorice» se resuelve al nombrar
    }

    /** @return list<string> */
    private function resumen(int $departamentoId): array
    {
        return DepartamentoResponsable::with('usuario:id,name', 'sede:id,nombre')->where('departamento_id', $departamentoId)->orderBy('id')->get()
            ->map(fn ($r) => $r->usuario?->name.($r->es_suplente ? ' (suplente)' : ' (titular)').($r->sede ? ' · '.$r->sede->nombre : ''))->all();
    }

    /**
     * @param  array<string, mixed>  $entrada  user_id (solo quien configura), delegado_id, desde, hasta, motivo
     */
    public function crear(User $actor, int $empresaId, array $entrada, bool $paraOtros): Delegacion
    {
        $d = Validator::make($entrada, [
            'user_id' => ['nullable', 'integer'],
            'delegado_id' => ['required', 'integer'],
            'desde' => ['required', 'date_format:Y-m-d\TH:i'],
            'hasta' => ['required', 'date_format:Y-m-d\TH:i'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ], [
            'delegado_id.required' => 'Elige a quién delegas.',
            'desde.*' => 'Indica desde cuándo (fecha y hora).',
            'hasta.*' => 'Indica hasta cuándo (fecha y hora).',
            'motivo.max' => 'El motivo admite máximo 255 caracteres.',
            '*.integer' => 'Elige una opción de la lista.',
        ])->validate();

        // C-12: se delega en quien pueda aprobar al menos una cosa (visitas, pases, reservas o entrevistas)
        $validos = $this->candidatosADelegado($empresaId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $titular = $paraOtros && ! empty($d['user_id']) ? (int) $d['user_id'] : $actor->id;
        if ($titular !== $actor->id && ! in_array($titular, $validos, true)) {
            throw ValidationException::withMessages(['user_id' => 'Elige un responsable activo de la lista.']);
        }
        if ($titular === $actor->id && ! $this->puedeDelegar($actor)) {
            throw ValidationException::withMessages(['delegado_id' => 'No apruebas visitas, pases, reservas ni entrevistas: no tienes nada que delegar.']);
        }
        $delegado = (int) $d['delegado_id'];
        // Ronda 8: nadie delega en sí mismo (se revisa antes que la lista para dar el mensaje claro)
        if ($delegado === $titular) {
            throw ValidationException::withMessages(['delegado_id' => $titular === $actor->id
                ? 'No puedes delegar en ti mismo: elige a otra persona que responda por ti.'
                : 'No puedes delegar en la misma persona que delega: elige a otra.']);
        }
        if (! in_array($delegado, $validos, true)) {
            throw ValidationException::withMessages(['delegado_id' => 'Elige a un usuario activo que pueda aprobar visitas, pases, reservas o entrevistas.']);
        }
        $zona = $this->hora->zona();
        $desde = Carbon::createFromFormat('Y-m-d\TH:i', $d['desde'], $zona)->utc();
        $hasta = Carbon::createFromFormat('Y-m-d\TH:i', $d['hasta'], $zona)->utc();
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw ValidationException::withMessages(['hasta' => '«Hasta» debe ser después de «Desde».']);
        }
        if ($hasta->isPast()) {
            throw ValidationException::withMessages(['hasta' => 'La delegación ya habría terminado: elige una fecha futura.']);
        }
        if ($desde->diffInDays($hasta) > self::MAX_DIAS) {
            throw ValidationException::withMessages(['hasta' => 'Una delegación dura como máximo '.self::MAX_DIAS.' días.']);
        }
        $cruce = Delegacion::where('user_id', $titular)->whereNull('cancelada_en')->where('desde', '<', $hasta)->where('hasta', '>', $desde)->exists();
        if ($cruce) {
            throw ValidationException::withMessages(['desde' => 'Ya hay una delegación en esas fechas: cancélala antes de crear otra.']);
        }
        // C-12 (revisión): sin bucles. Si en esas fechas el delegado ya le delega a quien delega (directa o en cadena), nadie atendería
        $enFechas = Delegacion::whereNull('cancelada_en')->where('desde', '<', $hasta)->where('hasta', '>', $desde)->get(['user_id', 'delegado_id'])
            ->groupBy(fn ($x) => (int) $x->user_id)->map(fn ($g) => $g->pluck('delegado_id')->map(fn ($id) => (int) $id)->all());
        $pendientes = [$delegado];
        $vistos = [$delegado => true];
        while ($pendientes !== []) {
            foreach ($enFechas->get(array_pop($pendientes), []) as $siguiente) {
                if ($siguiente === $titular) {
                    throw ValidationException::withMessages(['delegado_id' => User::find($delegado)?->name.' ya delega sus aprobaciones '
                        .($titular === $actor->id ? 'en ti' : 'en esa persona').' en esas fechas (directamente o a través de otra persona): elige a alguien más.']);
                }
                if (! isset($vistos[$siguiente])) {
                    $vistos[$siguiente] = true;
                    $pendientes[] = $siguiente;
                }
            }
        }

        $delegacion = Delegacion::create(['user_id' => $titular, 'delegado_id' => $delegado, 'desde' => $desde, 'hasta' => $hasta,
            'motivo' => isset($d['motivo']) ? trim($d['motivo']) : null]);
        $delegacion->load('usuario:id,name', 'delegado:id,name');
        $this->auditoria->auditar($actor, 'autorizaciones.delegacion_creada', $delegacion, null, $this->foto($delegacion));
        $this->notificaciones->avisar($empresaId, [$delegado], 'delegacion', [
            'titulo' => $delegacion->usuario?->name.' te delegó sus aprobaciones',
            'texto' => 'Del '.$this->hora->formatear($desde).' al '.$this->hora->formatear($hasta).'. Lo que le toque aprobar y tú también puedas (visitas, pases, reservas o entrevistas) te llegará a ti.',
            'url' => route('panel'),
            'referencia_tipo' => 'delegacion', 'referencia_id' => $delegacion->id,
        ]);

        return $delegacion;
    }

    public function cancelar(User $actor, Delegacion $delegacion): void
    {
        if ($delegacion->cancelada_en !== null || $delegacion->hasta->isPast()) {
            throw new CambioNoPermitido('Esta delegación ya terminó o ya estaba cancelada.');
        }
        $antes = $this->foto($delegacion);
        $delegacion->forceFill(['cancelada_en' => now(), 'cancelada_por' => $actor->id])->save();
        $this->auditoria->auditar($actor, 'autorizaciones.delegacion_cancelada', $delegacion, $antes, $this->foto($delegacion));
        $this->terminarAviso($delegacion);
    }

    /** El aviso «te delegó sus aprobaciones» queda «Terminó · …», leído y sin botones. */
    private function terminarAviso(Delegacion $delegacion): void
    {
        foreach (Notificacion::query()->withoutGlobalScopes()->where('tipo', 'delegacion')->where('referencia_tipo', 'delegacion')->where('referencia_id', $delegacion->id)->get(['id', 'titulo']) as $n) {
            CentroNotificaciones::marcarTerminada($n);
        }
    }

    /**
     * Revisión Etapa 3a: si se da de baja la cuenta de un delegado, sus
     * delegaciones vigentes o programadas terminan y cada titular recibe en
     * la campana «Tu delegación a X terminó porque fue dada de baja; tus
     * aprobaciones regresaron a ti». Lo llaman la baja en Usuarios y la baja
     * en cascada desde Colaboradores.
     */
    public static function delegadoDadoDeBaja(User $cuenta, ?User $actor): void
    {
        if ($cuenta->empresa_id === null) {
            return;
        }
        try {
            app(Tenant::class)->conEmpresa((int) $cuenta->empresa_id, function () use ($cuenta, $actor) {
                $servicio = app(self::class);
                foreach (Delegacion::with('usuario:id,name,activo')->where('delegado_id', $cuenta->id)->whereNull('cancelada_en')->where('hasta', '>=', now())->get() as $d) {
                    $antes = $servicio->foto($d);
                    $d->forceFill(['cancelada_en' => now(), 'cancelada_por' => $actor?->id])->save();
                    if ($actor !== null) {
                        $servicio->auditoria->auditar($actor, 'autorizaciones.delegacion_cancelada', $d, $antes, $servicio->foto($d) + ['motivo' => 'Delegado dado de baja']);
                    }
                    $servicio->terminarAviso($d);
                    if ($d->usuario?->activo) {
                        $servicio->notificaciones->avisar((int) $cuenta->empresa_id, [(int) $d->user_id], 'delegacion_terminada', [
                            'titulo' => 'Tu delegación a '.$cuenta->name.' terminó porque fue dada de baja',
                            'texto' => 'Tus aprobaciones regresaron a ti. Si vas a seguir fuera, elige a otra persona en Mi cuenta.',
                            'url' => route('mi-cuenta.index').'#delegar',
                            'referencia_tipo' => 'delegacion', 'referencia_id' => (int) $d->id,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, mixed> */
    public function foto(Delegacion $d): array
    {
        return ['responsable' => $d->usuario?->name ?? $d->user_id, 'delegado' => $d->delegado?->name ?? $d->delegado_id,
            'desde' => $d->desde?->toIso8601String(), 'hasta' => $d->hasta?->toIso8601String(), 'motivo' => $d->motivo,
            'cancelada_en' => $d->cancelada_en?->toIso8601String()];
    }
}
