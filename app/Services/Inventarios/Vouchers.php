<?php

namespace App\Services\Inventarios;

use App\Models\Colaborador;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bajas con voucher de reposición, compartidas por Llaves, Gafetes y Equipos
 * (en SEGCAT cada módulo repetía su propia versión).
 *
 * Uso típico, dentro de la empresa de trabajo:
 *
 *   $datos = $vouchers->validar($request->all());          // motivo, descripcion, aplica_cobro, monto, colaborador_id
 *   $voucher = $vouchers->darDeBaja($actor, $llave, 'llave', $llave->nomenclatura, $datos,
 *       fn () => $llave->update(['activo' => false]), referenciaCosto: $llave->tipo);
 *
 * Todo pasa en una transacción: si falla la baja, no queda voucher (y al revés).
 */
class Vouchers
{
    public function __construct(
        private readonly AdministradorRoles $roles,
        private readonly Tenant $tenant,
    ) {}

    /**
     * Reglas comunes del formulario de baja (motivo, ¿cómo pasó?, cobro,
     * monto y responsable). El responsable debe ser de la empresa.
     *
     * @return array{motivo: string, descripcion: ?string, aplica_cobro: bool, monto: string, colaborador_id: ?int}
     */
    public function validar(array $entrada): array
    {
        $datos = Validator::make($entrada, [
            'motivo' => ['required', Rule::in(array_keys(VoucherReposicion::MOTIVOS))],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'aplica_cobro' => ['nullable', 'boolean'],
            'monto' => ['nullable', 'required_if_accepted:aplica_cobro', 'numeric', 'min:0', 'max:999999.99'],
            'colaborador_id' => ['nullable', 'integer', 'required_if_accepted:aplica_cobro'],
        ], [
            'motivo.required' => 'Indica el motivo de la baja.',
            'monto.required_if_accepted' => 'Indica el monto a cobrar.',
            'colaborador_id.required_if_accepted' => 'Elige al responsable al que se le cobrará.',
        ])->validate();

        $cobro = (bool) ($datos['aplica_cobro'] ?? false);
        $colaboradorId = isset($datos['colaborador_id']) ? (int) $datos['colaborador_id'] : null;
        if ($colaboradorId !== null && ! Colaborador::whereKey($colaboradorId)->exists()) {
            throw ValidationException::withMessages(['colaborador_id' => 'El responsable no pertenece a esta empresa.']);
        }

        return [
            'motivo' => $datos['motivo'],
            'descripcion' => isset($datos['descripcion']) ? trim($datos['descripcion']) : null,
            'aplica_cobro' => $cobro,
            'monto' => $cobro ? number_format((float) $datos['monto'], 2, '.', '') : '0.00',
            'colaborador_id' => $colaboradorId,
        ];
    }

    /**
     * Da de baja $origen (con $marcarBaja) y emite su voucher.
     *
     * @param  string  $origenTipo  llave | gafete | equipo
     * @param  string  $descripcion  cómo se verá en el voucher ("HDM-CEN-VIS-001", "Radio Motorola DEP450 · S/N 123")
     * @param  callable(): void  $marcarBaja
     * @param  string|null  $referenciaCosto  para recordar el costo (tipo de llave, tipo de gafete, "MARCA|MODELO")
     */
    public function darDeBaja(User $actor, Model $origen, string $origenTipo, string $descripcion, array $datos, callable $marcarBaja, ?string $referenciaCosto = null): VoucherReposicion
    {
        return DB::transaction(function () use ($actor, $origen, $origenTipo, $descripcion, $datos, $marcarBaja, $referenciaCosto) {
            $marcarBaja();

            $voucher = VoucherReposicion::create([
                'sede_id' => $origen->getAttribute('sede_id'),
                'folio' => $this->nuevoFolio(),
                'origen_tipo' => $origenTipo,
                'origen_id' => $origen->getKey(),
                'origen_descripcion' => mb_substr($descripcion, 0, 150),
            ] + $datos);

            if ($voucher->aplica_cobro && $referenciaCosto !== null && (float) $voucher->monto > 0) {
                $this->recordarCosto($origenTipo, $referenciaCosto, (string) $voucher->monto);
            }

            $this->roles->auditar($actor, 'vouchers.creado', $voucher, null, $voucher->only([
                'folio', 'origen_tipo', 'origen_id', 'origen_descripcion', 'motivo', 'aplica_cobro', 'monto', 'colaborador_id',
            ]));

            return $voucher;
        });
    }

    /** Último costo usado para ese tipo (para sugerirlo en el formulario). */
    public function costoSugerido(string $origenTipo, ?string $referencia): ?string
    {
        if ($referencia === null || $referencia === '') {
            return null;
        }

        $monto = DB::table('costos_reposicion')
            ->where('empresa_id', $this->tenant->empresaId())
            ->where('origen_tipo', $origenTipo)->where('referencia', mb_substr($referencia, 0, 150))
            ->value('monto');

        return $monto === null ? null : number_format((float) $monto, 2, '.', '');
    }

    private function recordarCosto(string $origenTipo, string $referencia, string $monto): void
    {
        $empresaId = $this->tenant->empresaId();
        $clave = ['empresa_id' => $empresaId, 'origen_tipo' => $origenTipo, 'referencia' => mb_substr($referencia, 0, 150)];
        $actualizado = DB::table('costos_reposicion')->where($clave)->update(['monto' => $monto, 'actualizado_por' => auth()->id(), 'updated_at' => now()]);
        if ($actualizado === 0) {
            DB::table('costos_reposicion')->insert($clave + ['monto' => $monto, 'creado_por' => auth()->id(), 'actualizado_por' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** VR-aamm-nnnnn, como SEGCAT, sin repetirse. */
    private function nuevoFolio(): string
    {
        do {
            $folio = 'VR-'.now()->format('ym').'-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (VoucherReposicion::withoutGlobalScopes()->where('folio', $folio)->exists());

        return $folio;
    }
}
