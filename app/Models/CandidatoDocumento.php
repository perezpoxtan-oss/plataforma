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

    /**
     * Tipos fijos (siempre existen). Los demás vienen de la lista «Documentos
     * para contratar» de cada empresa (fase 3): la clave del renglón es el tipo.
     */
    public const TIPOS = ['cv' => 'Currículum (CV)', 'ine' => 'Identificación (INE)', 'comprobante' => 'Comprobante de domicilio', 'otro' => 'Otro documento'];

    // Fase 3: tipos que no se eligen en la lista de la empresa
    /** El contrato firmado (se guarda con «Contrato firmado», no con «Agregar documento»). */
    public const TIPO_CONTRATO = 'contrato';

    public const FIJOS = ['cv' => 'Currículum (CV)', 'otro' => 'Otro documento', self::TIPO_CONTRATO => 'Contrato firmado'];
    // Fin Fase 3

    protected $table = 'candidato_documentos';

    /** Fase 3: origen de un documento que se recibió en papel (sin archivo). */
    public const ORIGEN_PAPEL = 'papel';

    protected $fillable = ['empresa_id', 'candidato_id', 'colaborador_id', 'tipo', 'nombre_original', 'ruta', 'mime', 'bytes', 'origen', 'recibido_en'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'recibido_en' => 'date'];
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** Fase 3: el colaborador a cuyo expediente pasó el documento al contratar. */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function esImagen(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** Fase 3: ¿se recibió en papel (no hay archivo que abrir)? */
    public function enPapel(): bool
    {
        return $this->origen === self::ORIGEN_PAPEL || $this->ruta === null;
    }

    public function tamano(): string
    {
        return $this->bytes >= 1048576 ? number_format($this->bytes / 1048576, 1).' MB' : max(1, (int) round($this->bytes / 1024)).' KB';
    }
}
