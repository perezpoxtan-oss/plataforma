<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud ARCO (Acceso, Rectificación, Cancelación u Oposición) que llegó
 * del formulario público (docs/tecnico/privacidad.md). empresa_id nulo =
 * llegó desde la página de la plataforma y el Super Administrador la
 * canaliza a la empresa que corresponde.
 */
class SolicitudArco extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const DERECHOS = [
        'acceso' => 'Acceso (saber qué datos tienen de mí)',
        'rectificacion' => 'Rectificación (corregir mis datos)',
        'cancelacion' => 'Cancelación (que borren mis datos)',
        'oposicion' => 'Oposición (que dejen de usar mis datos para algo)',
    ];

    public const DERECHOS_CORTOS = ['acceso' => 'Acceso', 'rectificacion' => 'Rectificación', 'cancelacion' => 'Cancelación', 'oposicion' => 'Oposición'];

    public const RELACIONES = [
        'visitante' => 'Visité una sede (visita, proveedor, contratista, huésped)',
        'candidato' => 'Busqué empleo (candidato)',
        'colaborador' => 'Trabajo o trabajé ahí',
        'cliente' => 'Pedí información de los planes',
        'otro' => 'Otro',
    ];

    public const POR_VERIFICAR = 'por_verificar';

    public const PENDIENTE = 'pendiente';

    public const ATENDIDA = 'atendida';

    public const RECHAZADA = 'rechazada';

    public const ESTADOS = [
        self::POR_VERIFICAR => 'Correo sin confirmar',
        self::PENDIENTE => 'Por atender',
        self::ATENDIDA => 'Atendida',
        self::RECHAZADA => 'Rechazada',
    ];

    protected $table = 'solicitudes_arco';

    protected $fillable = ['empresa_id', 'folio', 'derecho', 'estado', 'nombre', 'correo', 'telefono', 'relacion', 'detalle', 'ip'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'token_vence_en' => 'datetime', 'verificada_en' => 'datetime', 'vence_en' => 'date', 'recordada_en' => 'datetime',
            'escalada_en' => 'datetime', 'respondida_en' => 'datetime', 'acciones' => 'array',
        ];
    }

    public function respondidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondida_por');
    }

    public function abierta(): bool
    {
        return in_array($this->estado, [self::POR_VERIFICAR, self::PENDIENTE], true);
    }

    public function etiquetaDerecho(): string
    {
        return self::DERECHOS_CORTOS[$this->derecho] ?? $this->derecho;
    }
}
