<?php

namespace App\Services\Reservas;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de Reservas de áreas por empresa (empresas.preferencias.reservas).
 * Se editan en Configuración → Reservas de áreas y en Reservas →
 * Configuración (el mismo formulario).
 *
 *  - responsable_id / suplente_id: responsable GENERAL de reservas (por
 *    omisión de cada área: «regularmente el asistente de gerencia») y su
 *    suplente. Cada área puede tener los suyos.
 *  - iva_aplica / iva_porcentaje: IVA de las reservas para clientes (la
 *    empresa no tiene un IVA propio en otro lado; la moneda es la de la
 *    empresa, empresas.moneda).
 *  - recordar_horas / escalar_horas: reloj y color de las aprobaciones que
 *    esperan (ámbar y recordatorio; rojo y aviso al responsable general).
 *  - departamento_montaje_id: departamento que hace el montaje (opcional; en
 *    un hotel, Steward). Si se elige, el «Tipo de montaje» se muestra dentro
 *    de su tarjeta en el formulario y en la orden de evento, y recibe la orden
 *    de servicio del montaje aunque no se le pida otro servicio.
 */
class AjustesReservas
{
    public const DEFECTO = [
        'responsable_id' => null,
        'suplente_id' => null,
        'iva_aplica' => true,
        'iva_porcentaje' => 16.0,
        'recordar_horas' => 4,
        'escalar_horas' => 24,
        'departamento_montaje_id' => null,
    ];

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /**
     * @return array{responsable_id: ?int, suplente_id: ?int, iva_aplica: bool, iva_porcentaje: float, recordar_horas: int, escalar_horas: int, departamento_montaje_id: ?int, moneda: string}
     */
    public function de(Empresa|int|null $empresa): array
    {
        $empresa = $empresa instanceof Empresa ? $empresa : ($empresa === null ? null : Empresa::find($empresa));
        $guardado = (array) ($empresa?->preferencias['reservas'] ?? []);
        $v = array_merge(self::DEFECTO, array_intersect_key($guardado, self::DEFECTO));

        return [
            'responsable_id' => $v['responsable_id'] === null ? null : (int) $v['responsable_id'],
            'suplente_id' => $v['suplente_id'] === null ? null : (int) $v['suplente_id'],
            'iva_aplica' => (bool) $v['iva_aplica'],
            'iva_porcentaje' => (float) $v['iva_porcentaje'],
            'recordar_horas' => max(1, (int) $v['recordar_horas']),
            'escalar_horas' => max(1, (int) $v['escalar_horas']),
            'departamento_montaje_id' => $v['departamento_montaje_id'] === null || $v['departamento_montaje_id'] === '' ? null : (int) $v['departamento_montaje_id'],
            'moneda' => (string) ($empresa?->moneda ?: 'MXN'),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): void
    {
        $usuarios = User::where('empresa_id', $empresa->id)->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $id = fn ($valor) => $valor === null || $valor === '' ? null : (int) $valor;
        $responsable = $id($entrada['responsable_id'] ?? null);
        $suplente = $id($entrada['suplente_id'] ?? null);
        foreach (['responsable_id' => $responsable, 'suplente_id' => $suplente] as $campo => $valor) {
            if ($valor !== null && ! in_array($valor, $usuarios, true)) {
                throw ValidationException::withMessages([$campo => 'Elige a un usuario activo de la empresa.']);
            }
        }
        if ($responsable !== null && $responsable === $suplente) {
            throw ValidationException::withMessages(['suplente_id' => 'El suplente debe ser otra persona.']);
        }
        $recordar = (int) ($entrada['recordar_horas'] ?? self::DEFECTO['recordar_horas']);
        $escalar = (int) ($entrada['escalar_horas'] ?? self::DEFECTO['escalar_horas']);
        if ($recordar < 1 || $recordar > 720 || $escalar < 1 || $escalar > 720) {
            throw ValidationException::withMessages(['recordar_horas' => 'Las horas van de 1 a 720.']);
        }
        if ($escalar <= $recordar) {
            throw ValidationException::withMessages(['escalar_horas' => 'Avisar al responsable general debe ser después del recordatorio.']);
        }
        // Si el formulario no lo trae, se conserva el que ya tenía
        $montaje = array_key_exists('departamento_montaje_id', $entrada) ? $id($entrada['departamento_montaje_id']) : $this->de($empresa)['departamento_montaje_id'];
        if ($montaje !== null && ! Departamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereKey($montaje)->exists()) {
            throw ValidationException::withMessages(['departamento_montaje_id' => 'Elige un departamento de la empresa.']);
        }
        $iva = (float) ($entrada['iva_porcentaje'] ?? self::DEFECTO['iva_porcentaje']);
        if ($iva < 0 || $iva > 30) {
            throw ValidationException::withMessages(['iva_porcentaje' => 'El IVA va de 0 a 30 %.']);
        }

        $antes = $this->de($empresa);
        $nuevo = [
            'responsable_id' => $responsable, 'suplente_id' => $suplente,
            'iva_aplica' => filter_var($entrada['iva_aplica'] ?? false, FILTER_VALIDATE_BOOLEAN), 'iva_porcentaje' => round($iva, 2),
            'recordar_horas' => $recordar, 'escalar_horas' => $escalar, 'departamento_montaje_id' => $montaje,
        ];
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['reservas' => $nuevo])])->save();
        $this->auditoria->auditar($actor, 'reservas.configurado', $empresa, $antes, $nuevo);
    }
}
