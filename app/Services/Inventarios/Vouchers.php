<?php

namespace App\Services\Inventarios;

use App\Models\Colaborador;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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
    /** Ronda 5 (LL-04): cómo se firman las copias del voucher. */
    public const FIRMA_MODOS = [
        'fisica' => 'Firma física (imprimir y firmar a mano)',
        'digital' => 'Firma digital (en la pantalla)',
    ];

    public function __construct(
        private readonly AdministradorRoles $roles,
        private readonly Tenant $tenant,
        private readonly Firmas $firmas,
        private readonly AvisosCorreo $avisos,
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
            'firma_modo' => ['nullable', Rule::in(array_keys(self::FIRMA_MODOS))],
            'firma_seguridad' => ['nullable', 'string', 'max:500000'],
            'firma_responsable' => ['nullable', 'string', 'max:500000'],
        ], [
            'firma_modo.in' => 'Elige cómo se firmará el voucher: digital o física.',
            'motivo.required' => 'Indica el motivo de la baja.',
            'monto.required_if_accepted' => 'Indica el monto a cobrar.',
            'colaborador_id.required_if_accepted' => 'Elige al responsable al que se le cobrará.',
        ])->validate();

        $cobro = (bool) ($datos['aplica_cobro'] ?? false);
        $colaboradorId = isset($datos['colaborador_id']) ? (int) $datos['colaborador_id'] : null;
        if ($colaboradorId !== null && ! Colaborador::whereKey($colaboradorId)->exists()) {
            throw ValidationException::withMessages(['colaborador_id' => 'El responsable no pertenece a esta empresa.']);
        }

        // Firma digital: firma quien registra (Seguridad) y, si hay responsable, también él
        $modo = $datos['firma_modo'] ?? null;
        if ($modo === 'digital') {
            if (! $this->firmas->viene($datos['firma_seguridad'] ?? null)) {
                throw ValidationException::withMessages(['firma_seguridad' => 'Falta la firma de Seguridad: firma en el recuadro o elige «Firma física».']);
            }
            if ($colaboradorId !== null && ! $this->firmas->viene($datos['firma_responsable'] ?? null)) {
                throw ValidationException::withMessages(['firma_responsable' => 'Falta la firma del responsable: que firme en el recuadro o elige «Firma física».']);
            }
        }

        return [
            'motivo' => $datos['motivo'],
            'descripcion' => isset($datos['descripcion']) ? trim($datos['descripcion']) : null,
            'aplica_cobro' => $cobro,
            'monto' => $cobro ? number_format((float) $datos['monto'], 2, '.', '') : '0.00',
            'colaborador_id' => $colaboradorId,
            'firma_modo' => $modo,
            'firma_seguridad' => $modo === 'digital' ? $datos['firma_seguridad'] : null,
            'firma_responsable' => $modo === 'digital' && $colaboradorId !== null ? $datos['firma_responsable'] : null,
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
        // Firmas digitales: al disco privado antes de la transacción; si algo falla, se borran
        $guardadas = [];
        foreach (['firma_seguridad' => 'la firma de Seguridad', 'firma_responsable' => 'la firma del responsable'] as $campo => $etiqueta) {
            $valor = $datos[$campo] ?? null;
            $datos[$campo] = null;
            if (($datos['firma_modo'] ?? null) === 'digital' && $valor !== null) {
                $datos[$campo] = $guardadas[] = $this->firmas->guardar($valor, 'vouchers', $campo, $etiqueta);
            }
        }

        try {
            $voucher = $this->emitir($actor, $origen, $origenTipo, $descripcion, $datos, $marcarBaja, $referenciaCosto);
        } catch (Throwable $e) {
            array_map(fn ($ruta) => $this->firmas->borrar($ruta), $guardadas);
            throw $e;
        }

        // Ronda 5 (LL-04): con cobro (CXC) las copias van por correo a Seguridad, Recepción y Administración
        if ($voucher->aplica_cobro) {
            $this->avisos->voucherConCobro($voucher, $actor);
        }

        return $voucher;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function emitir(User $actor, Model $origen, string $origenTipo, string $descripcion, array $datos, callable $marcarBaja, ?string $referenciaCosto): VoucherReposicion
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
                'folio', 'origen_tipo', 'origen_id', 'origen_descripcion', 'motivo', 'aplica_cobro', 'monto', 'colaborador_id', 'firma_modo',
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
