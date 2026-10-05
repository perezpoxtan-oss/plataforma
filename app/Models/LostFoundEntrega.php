<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre de un artículo de Lost & Found (devolución, paquetería, donación,
 * destrucción o beneficencia) con su firma en el disco privado.
 * SEGCAT: lost_found_entregas. La pantalla "Cerrar / Entregar" llega con el
 * archivo de Lost & Found; la tabla queda lista desde ahora.
 */
class LostFoundEntrega extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const TIPOS_CIERRE = [
        'PERSONA' => 'Entrega en persona',
        'PAQUETERIA' => 'Envío por paquetería',
        'DONADO' => 'Donado a colaborador',
        'DESTRUIDO' => 'Destruido',
        'BENEFICENCIA' => 'Entregado a beneficencia',
    ];

    protected $table = 'lost_found_entregas';

    protected $fillable = [
        'empresa_id', 'articulo_id', 'tipo_cierre', 'nombre_recibe', 'tipo_identificacion', 'correo_recibe', 'paqueteria', 'numero_guia',
        'firma_ruta', 'observaciones',
    ];

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(LostFoundArticulo::class, 'articulo_id');
    }
}
