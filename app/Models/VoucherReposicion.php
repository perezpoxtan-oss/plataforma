<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Voucher de reposición (SEGCAT: vouchers_reposicion). Se genera al dar de
 * baja una llave, un gafete o un equipo; no se edita ni se borra (es el
 * comprobante de lo que pasó y, si aplica, de lo que se cobró).
 */
class VoucherReposicion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'vouchers_reposicion';

    /** origen_tipo => [etiqueta, módulo (permiso), clase] */
    public const ORIGENES = [
        'llave' => ['Llave', 'llaves', 'App\Models\Llave'],
        'gafete' => ['Gafete', 'gafetes', 'App\Models\Gafete'],
        'equipo' => ['Equipo de seguridad', 'equipos', 'App\Models\Equipo'],
    ];

    /** Igual que SEGCAT: Extraviado | Dañado | Robado */
    public const MOTIVOS = [
        'extraviado' => 'Extraviado',
        'danado' => 'Dañado',
        'robado' => 'Robado',
    ];

    /**
     * Ronda 6 (GV-04): estado del voucher. Nace «vigente»; si el artículo
     * aparece se marca «Recuperado»: «cancelado_recuperacion» (sin cobro, o el
     * cobro no se había pagado y se cancela) o «reembolso_pendiente» (ya se
     * había cobrado), que pasa a «reembolsado» al devolver el dinero.
     */
    public const ESTADOS = [
        'vigente' => 'Vigente',
        'cancelado_recuperacion' => 'Cancelado por recuperación',
        'reembolso_pendiente' => 'Reembolso pendiente',
        'reembolsado' => 'Reembolsado',
    ];

    protected $attributes = ['aplica_cobro' => false, 'monto' => 0, 'estado' => 'vigente'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'folio', 'origen_tipo', 'origen_id', 'origen_descripcion', 'motivo',
        'descripcion', 'aplica_cobro', 'monto', 'colaborador_id',
        'firma_modo', 'firma_seguridad', 'firma_responsable', 'firmado_papel_en', 'firmado_papel_por', 'hoja_firmada',
    ];

    /** Ronda 5 (LL-04): copias impresas y por correo. El colaborador firma, pero no recibe copia. */
    public const COPIAS = ['seguridad' => 'Copia Seguridad', 'recepcion' => 'Copia Recepción', 'administracion' => 'Copia Administración'];

    protected function casts(): array
    {
        return [
            'aplica_cobro' => 'boolean', 'monto' => 'decimal:2', 'firmado_papel_en' => 'datetime',
            'recuperado_en' => 'datetime', 'reembolsado_en' => 'datetime',
        ];
    }

    public function etiquetaEstado(): string
    {
        return self::ESTADOS[$this->estado ?? 'vigente'] ?? (string) $this->estado;
    }

    /** ¿Se puede marcar «Recuperado»? Solo un voucher vigente. */
    public function esVigente(): bool
    {
        return ($this->estado ?? 'vigente') === 'vigente';
    }

    public function recuperadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recuperado_por');
    }

    public function reembolsadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reembolsado_por');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /** Lo que se dio de baja (llave, gafete o equipo), si su módulo ya existe. */
    public function origen(): ?Model
    {
        $clase = self::ORIGENES[$this->origen_tipo][2] ?? null;

        return $clase !== null && class_exists($clase) ? $clase::find($this->origen_id) : null;
    }

    public function firmadoPapelPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firmado_papel_por');
    }

    /**
     * Estado de las firmas: "digital" (firmado en pantalla), "papel" (marcado
     * como firmado a mano), "pendiente" (firma física sin marcar) o null
     * (vouchers anteriores a la Ronda 5).
     */
    public function estadoFirma(): ?string
    {
        return match (true) {
            $this->firma_modo === 'digital' => 'digital',
            $this->firmado_papel_en !== null => 'papel',
            $this->firma_modo === 'fisica' => 'pendiente',
            default => null,
        };
    }

    public function etiquetaOrigen(): string
    {
        return self::ORIGENES[$this->origen_tipo][0] ?? ucfirst($this->origen_tipo);
    }

    /** Módulo de permisos del que depende (llaves, gafetes o equipos). */
    public function moduloOrigen(): string
    {
        return self::ORIGENES[$this->origen_tipo][1] ?? $this->origen_tipo;
    }
}
