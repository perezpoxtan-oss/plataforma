<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento del candidato (CV, INE, comprobante de domicilio...). El archivo
 * vive en el disco PRIVADO y solo se entrega con permiso (candidatos.ver).
 */
class CandidatoDocumento extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const TIPOS = ['cv' => 'Currículum (CV)', 'ine' => 'Identificación (INE)', 'comprobante' => 'Comprobante de domicilio', 'otro' => 'Otro documento'];

    protected $table = 'candidato_documentos';

    protected $fillable = ['empresa_id', 'candidato_id', 'tipo', 'nombre_original', 'ruta', 'mime', 'bytes', 'origen'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function esImagen(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function tamano(): string
    {
        return $this->bytes >= 1048576 ? number_format($this->bytes / 1048576, 1).' MB' : max(1, (int) round($this->bytes / 1024)).' KB';
    }
}
