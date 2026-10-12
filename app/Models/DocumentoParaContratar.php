<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Un renglón de la lista «Documentos para contratar» de la empresa
 * (candidatos, fase 3): INE, CURP, NSS, RFC, acta de nacimiento… con su clave
 * (la que lleva cada documento subido en candidato_documentos.tipo), si es
 * obligatorio y su orden. Un renglón quitado de la lista queda inactivo para
 * que los documentos ya subidos conserven su nombre.
 *
 * Sin renglones, la empresa usa la lista estándar
 * (App\Services\Candidatos\DocumentosParaContratar::DEFECTO).
 */
class DocumentoParaContratar extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'documentos_para_contratar';

    protected $fillable = ['empresa_id', 'clave', 'nombre', 'obligatorio', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean', 'activo' => 'boolean', 'orden' => 'integer'];
    }
}
