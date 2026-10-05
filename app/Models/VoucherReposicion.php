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

    protected $attributes = ['aplica_cobro' => false, 'monto' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'folio', 'origen_tipo', 'origen_id', 'origen_descripcion', 'motivo',
        'descripcion', 'aplica_cobro', 'monto', 'colaborador_id',
    ];

    protected function casts(): array
    {
        return ['aplica_cobro' => 'boolean', 'monto' => 'decimal:2'];
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
