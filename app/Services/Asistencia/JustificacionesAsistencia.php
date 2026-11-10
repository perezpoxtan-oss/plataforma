<?php

namespace App\Services\Asistencia;

use App\Models\AsistenciaJustificacion;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Justificar una falta, un retardo o una salida anticipada (ADR-0024): una
 * por colaborador, día y tipo; guardar otra vez cambia el motivo. Queda en
 * la Bitácora de auditoría (antes y después). Al justificar la falta de hoy
 * se apaga su aviso en la campana del jefe.
 */
class JustificacionesAsistencia
{
    /** Referencia de los avisos «No ha llegado» (reloj FaltasDelDia). */
    public const REFERENCIA_FALTA = 'asistencia_falta';

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $campana,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, Colaborador $colaborador, string $fecha, string $hoy, array $entrada): AsistenciaJustificacion
    {
        $d = Validator::make($entrada, [
            'tipo' => ['required', Rule::in(array_keys(AsistenciaJustificacion::TIPOS))],
            'motivo' => ['required', Rule::in(array_keys(AsistenciaJustificacion::MOTIVOS))],
            'nota' => ['nullable', 'string', 'max:255'],
        ], [
            'tipo.*' => 'Elige qué se justifica: falta, retardo o salida anticipada.',
            'motivo.*' => 'Elige el motivo de la lista.',
            'nota.max' => 'La nota admite hasta 255 caracteres.',
        ])->validate();

        $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? CarbonImmutable::createFromFormat('!Y-m-d', $fecha) : false;
        if ($dia === false || $dia->format('Y-m-d') !== $fecha || $fecha > $hoy) {
            throw ValidationException::withMessages(['fecha' => 'Solo se justifican días que ya llegaron (hoy o antes).']);
        }
        if ($d['motivo'] === 'otro' && trim((string) ($d['nota'] ?? '')) === '') {
            throw ValidationException::withMessages(['nota' => 'Con el motivo «Otro», escribe en la nota qué pasó.']);
        }

        $existente = AsistenciaJustificacion::where('colaborador_id', $colaborador->id)->where('fecha', $fecha)->where('tipo', $d['tipo'])->first();
        $antes = $existente?->only(['tipo', 'motivo', 'nota']);
        $j = $existente ?? new AsistenciaJustificacion(['colaborador_id' => $colaborador->id, 'fecha' => $fecha, 'tipo' => $d['tipo']]);
        $j->fill([
            'sede_id' => $colaborador->sede_id,
            'motivo' => $d['motivo'],
            'nota' => ($nota = trim((string) ($d['nota'] ?? ''))) === '' ? null : mb_substr($nota, 0, 255),
        ])->save();

        $this->auditoria->auditar($actor, $existente ? 'asistencia.justificacion_cambiada' : 'asistencia.justificada', $j, $antes,
            $j->only(['tipo', 'motivo', 'nota']) + ['colaborador' => $colaborador->nombreCompleto(), 'fecha' => $fecha]);
        if ($d['tipo'] === 'falta' && $fecha === $hoy) {
            $this->campana->resolver(self::REFERENCIA_FALTA, (int) $colaborador->id);
        }

        return $j;
    }
}
