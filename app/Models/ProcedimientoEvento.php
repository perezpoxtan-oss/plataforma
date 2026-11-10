<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de un procedimiento (quién, cuándo, comentario e IP). No se edita
 * ni se borra.
 */
class ProcedimientoEvento extends Model
{
    use PerteneceAEmpresa;

    /** evento => [texto, ícono, clase] */
    public const EVENTOS = [
        'creado' => ['Creó la versión', 'bi-plus-circle', 'info'],
        'editado' => ['Editó el borrador', 'bi-pencil', 'neutro'],
        'enviado' => ['Envió a revisión', 'bi-send', 'info'],
        'rechazado' => ['Rechazó y regresó a borrador', 'bi-x-octagon', 'error'],
        'aprobado' => ['Aprobó y publicó', 'bi-patch-check-fill', 'ok'],
        'reemplazada' => ['Quedó reemplazada por la versión nueva', 'bi-archive', 'neutro'],
        'descartado' => ['Descartó el borrador', 'bi-trash3', 'aviso'],
        'retirado' => ['Retiró el procedimiento (obsoleto)', 'bi-slash-circle', 'error'],
        'reactivado' => ['Reactivó el procedimiento', 'bi-arrow-counterclockwise', 'ok'],
    ];

    protected $table = 'procedimiento_eventos';

    protected $fillable = ['empresa_id', 'procedimiento_id', 'version_id', 'evento', 'comentario', 'user_id', 'usuario_nombre', 'ip'];

    /** @return array{0: string, 1: string, 2: string} [texto, ícono, clase] */
    public function datos(): array
    {
        return self::EVENTOS[$this->evento] ?? [$this->evento, 'bi-dot', 'neutro'];
    }
}
