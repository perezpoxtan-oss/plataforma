<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una impresión de etiquetas QR (Ronda 7, historial): quién, cuándo, con qué
 * plantilla, cuántas y cuáles (items). «Reimprimir» crea otra impresión que
 * apunta a esta (reimpresion_de_id).
 */
class ImpresionEtiquetas extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'impresiones_etiquetas';

    protected $fillable = ['empresa_id', 'sede_id', 'plantilla_id', 'plantilla_nombre', 'cantidad', 'reimpresion_de_id'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImpresionEtiquetasItem::class, 'impresion_id')->orderBy('orden');
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(EtiquetaPlantilla::class, 'plantilla_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reimpresion_de_id');
    }
}
