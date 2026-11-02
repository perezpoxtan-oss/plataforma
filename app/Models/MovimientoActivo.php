<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C-45 (DD-19): un movimiento de una llave, un gafete, un equipo o una
 * credencial en el historial único «Entregar / Recibir». Solo se agrega;
 * ver App\Services\Activos\HistorialActivos.
 */
class MovimientoActivo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'movimientos_activo';

    public const TIPOS = ['llave' => 'Llave', 'gafete' => 'Gafete', 'equipo' => 'Equipo de seguridad', 'credencial' => 'Credencial'];

    /** Texto de cada acción con los verbos de la plataforma. */
    public const ACCIONES = [
        'salida' => 'Dar salida',
        'recepcion' => 'Recibir',
        'no_regreso' => 'No regresó',
        'baja' => 'Dar de baja',
        'reactivacion' => 'Reactivar',
        'anulado' => 'Anulado (captura equivocada)',
        'mantenimiento' => 'A mantenimiento',
    ];

    /** La recepción común de los tres (y la credencial): OK, dañado o no regresó. */
    public const RESULTADOS = ['ok' => 'OK', 'danado' => 'Dañado', 'no_regreso' => 'No regresó'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'activo_tipo', 'activo_id', 'accion', 'resultado', 'origen_tipo', 'origen_id',
        'receptor_tipo', 'receptor_id', 'detalle',
    ];

    protected function casts(): array
    {
        return ['activo_id' => 'integer', 'origen_id' => 'integer', 'receptor_id' => 'integer'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function textoAccion(): string
    {
        $texto = self::ACCIONES[$this->accion] ?? $this->accion;

        return $this->accion === 'recepcion' && $this->resultado !== null && $this->resultado !== 'ok'
            ? $texto.' · '.(self::RESULTADOS[$this->resultado] ?? $this->resultado)
            : $texto;
    }
}
