<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre de un artículo de Lost & Found (devolución, paquetería, donación,
 * destrucción o beneficencia) con su firma en el disco privado.
 * SEGCAT: lost_found_entregas. Se registra desde "Cerrar / Entregar" del
 * archivo de Lost & Found (App\Services\Novedades\ArchivoLostFound).
 * Quien recibe puede ser un colaborador (lector universal) o una persona del
 * Padrón de personas; el nombre siempre queda como texto.
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

    /** Texto de las opciones de "¿Cómo se cierra el artículo?" (los de SEGCAT). */
    public const OPCIONES_CIERRE = [
        'PERSONA' => 'Devuelto en persona',
        'PAQUETERIA' => 'Enviado por paquetería',
        'DONADO' => 'Donado a colaborador',
        'DESTRUIDO' => 'Destruido',
        'BENEFICENCIA' => 'Entregado a beneficencia',
    ];

    /** Quién firma en cada tipo de cierre (SEGCAT: ETIQUETAS_FIRMA_LF). */
    public const ETIQUETAS_FIRMA = [
        'PERSONA' => 'Firma de quien recibe',
        'PAQUETERIA' => 'Firma de quien procesa el envío',
        'DONADO' => 'Firma de quien autoriza la donación',
        'DESTRUIDO' => 'Firma de quien autoriza la destrucción',
        'BENEFICENCIA' => 'Firma de quien autoriza la entrega',
    ];

    public const PAQUETERIAS = ['FedEx', 'DHL', 'Estafeta', 'Otro'];

    protected $table = 'lost_found_entregas';

    protected $fillable = [
        'empresa_id', 'articulo_id', 'tipo_cierre', 'colaborador_id', 'persona_id', 'nombre_recibe', 'tipo_identificacion', 'correo_recibe', 'paqueteria', 'numero_guia',
        'firma_ruta', 'observaciones',
    ];

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(LostFoundArticulo::class, 'articulo_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function etiquetaCierre(): string
    {
        return self::TIPOS_CIERRE[$this->tipo_cierre] ?? $this->tipo_cierre;
    }
}
