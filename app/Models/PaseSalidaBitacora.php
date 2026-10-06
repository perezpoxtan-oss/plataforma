<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bitácora de un pase de salida: cada paso con quién, cuándo, comentario,
 * firmas e IP. Inmutable: no se edita (el historial no se reescribe).
 */
class PaseSalidaBitacora extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida_bitacora';

    /** evento => [icono, clase de color] */
    public const EVENTOS = [
        'creado' => ['bi-file-earmark-plus', 'info'],
        'reenviado' => ['bi-arrow-repeat', 'info'],
        'aprobado' => ['bi-check-circle-fill', 'ok'],
        'omitido' => ['bi-skip-forward-circle', 'neutro'],
        'rechazado' => ['bi-x-circle-fill', 'error'],
        'cancelado' => ['bi-slash-circle', 'neutro'],
        'salida' => ['bi-box-arrow-right', 'morado'],
        'recepcion' => ['bi-box-arrow-in-down', 'info'],
        'salida_regreso' => ['bi-signpost-split', 'morado'],
        'regreso_parcial' => ['bi-arrow-return-left', 'aviso'],
        'regreso' => ['bi-arrow-return-left', 'ok'],
        'firma' => ['bi-pen', 'neutro'],
        'recordatorio' => ['bi-bell', 'aviso'],
    ];

    protected $fillable = ['empresa_id', 'pase_salida_id', 'evento', 'titulo', 'comentario', 'detalle', 'user_id', 'usuario_nombre', 'ip'];

    protected function casts(): array
    {
        return ['detalle' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
    }

    public function pase(): BelongsTo
    {
        return $this->belongsTo(PaseSalida::class, 'pase_salida_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function firmas(): HasMany
    {
        return $this->hasMany(PaseSalidaFirma::class, 'bitacora_id')->orderBy('id');
    }
}
