<?php

namespace App\Services\Autorizaciones;

use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Sede;
use App\Models\User;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use App\Support\HoraLocal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Responsables por departamento y delegaciones («No molestar»).
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
     * Guarda los responsables de un departamento (reemplaza la lista).
     *
     * @param  array<int, mixed>  $filas  [{user_id, sede_id, es_suplente}]
     */
    public function guardarResponsables(User $actor, int $empresaId, Departamento $departamento, array $filas): void
    {
        $validos = $this->candidatosAResponsable($empresaId)->pluck('id')->all();
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

        $validos = $this->candidatosAResponsable($empresaId)->pluck('id')->all();
        $titular = $paraOtros && ! empty($d['user_id']) ? (int) $d['user_id'] : $actor->id;
        if ($titular !== $actor->id && ! in_array($titular, $validos, true)) {
            throw ValidationException::withMessages(['user_id' => 'Elige un responsable activo de la lista.']);
        }
        $delegado = (int) $d['delegado_id'];
        if (! in_array($delegado, $validos, true)) {
            throw ValidationException::withMessages(['delegado_id' => 'Elige a un usuario activo que pueda responder autorizaciones.']);
        }
        if ($delegado === $titular) {
            throw ValidationException::withMessages(['delegado_id' => 'No puedes delegar en la misma persona.']);
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

        $delegacion = Delegacion::create(['user_id' => $titular, 'delegado_id' => $delegado, 'desde' => $desde, 'hasta' => $hasta,
            'motivo' => isset($d['motivo']) ? trim($d['motivo']) : null]);
        $delegacion->load('usuario:id,name', 'delegado:id,name');
        $this->auditoria->auditar($actor, 'autorizaciones.delegacion_creada', $delegacion, null, $this->foto($delegacion));
        $this->notificaciones->avisar($empresaId, [$delegado], 'delegacion', [
            'titulo' => $delegacion->usuario?->name.' te delegó sus autorizaciones',
            'texto' => 'Del '.$this->hora->formatear($desde).' al '.$this->hora->formatear($hasta).'. Los avisos de su departamento te llegarán a ti.',
            'url' => route('autorizaciones.index'),
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
    }

    /** @return array<string, mixed> */
    public function foto(Delegacion $d): array
    {
        return ['responsable' => $d->usuario?->name ?? $d->user_id, 'delegado' => $d->delegado?->name ?? $d->delegado_id,
            'desde' => $d->desde?->toIso8601String(), 'hasta' => $d->hasta?->toIso8601String(), 'motivo' => $d->motivo,
            'cancelada_en' => $d->cancelada_en?->toIso8601String()];
    }
}
