<?php

namespace App\Services\Asistencia;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Facades\Validator;

/**
 * Ajustes de Asistencia por empresa (ADR-0024), en
 * empresas.preferencias['asistencia']. Sin configurar nada se comporta
 * como lo aprobó el dueño:
 *  - tolerancia_minutos: minutos después de la hora de entrada del turno
 *    antes de contar un retardo (y antes de la salida para la salida
 *    anticipada). De fábrica 10.
 *  - aviso_faltas: aviso al jefe del departamento (campana y Mis
 *    pendientes, con reloj y suplente) de quien no llegó. De fábrica apagado.
 */
class AjustesAsistencia
{
    public const CLAVE = 'asistencia';

    public const TOLERANCIA = 10;

    public const TOLERANCIA_MAXIMA = 120;

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /** @return array{tolerancia_minutos: int, aviso_faltas: bool} */
    public function ajustes(?Empresa $empresa): array
    {
        $p = $empresa?->preferencias[self::CLAVE] ?? [];
        $p = is_array($p) ? $p : [];

        return [
            'tolerancia_minutos' => max(0, min(self::TOLERANCIA_MAXIMA, (int) ($p['tolerancia_minutos'] ?? self::TOLERANCIA))),
            'aviso_faltas' => (bool) ($p['aviso_faltas'] ?? false),
        ];
    }

    public function tolerancia(?Empresa $empresa): int
    {
        return $this->ajustes($empresa)['tolerancia_minutos'];
    }

    public function avisaFaltas(?Empresa $empresa): bool
    {
        return $this->ajustes($empresa)['aviso_faltas'];
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{tolerancia_minutos: int, aviso_faltas: bool}
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): array
    {
        $d = Validator::make($entrada, [
            'tolerancia_minutos' => ['required', 'integer', 'min:0', 'max:'.self::TOLERANCIA_MAXIMA],
            'aviso_faltas' => ['nullable', 'boolean'],
        ], [
            'tolerancia_minutos.*' => 'La tolerancia va de 0 a '.self::TOLERANCIA_MAXIMA.' minutos.',
        ])->validate();

        $antes = $this->ajustes($empresa);
        $nuevos = ['tolerancia_minutos' => (int) $d['tolerancia_minutos'], 'aviso_faltas' => (bool) ($d['aviso_faltas'] ?? false)];
        $preferencias = $empresa->preferencias ?? [];
        // Solo se guarda lo distinto de la omisión
        $preferencias[self::CLAVE] = array_filter([
            'tolerancia_minutos' => $nuevos['tolerancia_minutos'] !== self::TOLERANCIA ? $nuevos['tolerancia_minutos'] : null,
            'aviso_faltas' => $nuevos['aviso_faltas'] ?: null,
        ], fn ($v) => $v !== null);
        if ($preferencias[self::CLAVE] === []) {
            unset($preferencias[self::CLAVE]);
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $despues = $this->ajustes($empresa);
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'asistencia.ajustes', $empresa, $antes, $despues);
        }

        return $despues;
    }
}
