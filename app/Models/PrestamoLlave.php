<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Support\Receptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Préstamo de una llave del Catálogo a un colaborador (SEGCAT:
 * bitacora_llaves): quién se la llevó, qué identificación dejó en garantía,
 * quién la entregó y quién la recibió de vuelta.
 *
 * Estados: en_uso → devuelta, o en_uso → no_regreso → devuelta (apareció). "anulado" marca una captura equivocada (llave o
 * colaborador equivocado por las prisas): no se borra, pero deja de contar y
 * la llave vuelve a estar disponible. Se puede reactivar si la llave no se
 * volvió a prestar mientras tanto.
 */
class PrestamoLlave extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'prestamos_llaves';

    public const EN_USO = 'en_uso';

    public const DEVUELTA = 'devuelta';

    /** C-14: la llave no regresó (la caseta lo marca; se recibe si aparece o se da de baja con voucher). */
    public const NO_REGRESO = 'no_regreso';

    /** clave => texto en pantalla (SEGCAT: tipo_id_garantia, en mayúsculas) */
    public const GARANTIAS = [
        'gafete_interno' => 'GAFETE INTERNO',
        'ine' => 'INE',
        'licencia' => 'LICENCIA',
        'pasaporte' => 'PASAPORTE',
        'ninguna' => 'NINGUNA',
    ];

    /** Texto de cada opción del formulario (como en SEGCAT; "Sede" en lugar de "Hotel"). */
    public const GARANTIAS_OPCIONES = [
        'gafete_interno' => 'Gafete interno de la empresa',
        'ine' => 'INE / IFE',
        'licencia' => 'Licencia de Conducir',
        'pasaporte' => 'Pasaporte',
        'ninguna' => 'Ninguna (Riesgo)',
    ];

    protected $attributes = ['estado' => self::EN_USO, 'anulado' => false];

    protected $fillable = ['empresa_id', 'sede_id', 'llave_id', 'colaborador_id', 'receptor_tipo', 'receptor_id', 'tipo_garantia', 'folio_garantia', 'excepcion_motivo'];

    protected function casts(): array
    {
        return [
            'anulado' => 'boolean',
            'prestado_en' => 'datetime',
            'devuelto_en' => 'datetime',
            'anulado_en' => 'datetime',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function llave(): BelongsTo
    {
        return $this->belongsTo(Llave::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * C-16: quien recibió (colaborador, persona del padrón o empresa
     * externa). Los registros de antes solo tienen colaborador.
     */
    public function receptor(): ?Model
    {
        // Con consultas que traen solo algunas columnas, se usa el colaborador (como antes)
        $tipo = $this->attributes['receptor_tipo'] ?? Receptor::COLABORADOR;
        $id = $this->attributes['receptor_id'] ?? ($this->attributes['colaborador_id'] ?? null);
        if ($tipo === Receptor::COLABORADOR && $this->relationLoaded('colaborador') && (int) $this->colaborador?->id === (int) $id) {
            return $this->colaborador;
        }

        return Receptor::cargar($tipo, $id === null ? null : (int) $id);
    }

    /** «Juan Pérez», «Juan Pérez (Elevadores del Sur)» o «Elevadores del Sur». */
    public function nombreReceptor(): string
    {
        return Receptor::nombreDe($this->receptor());
    }

    public function entrego(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    public function recibio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    public function anulo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /** Préstamos vigentes: la llave está fuera de la caseta. */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where('prestamos_llaves.estado', self::EN_USO)->where('prestamos_llaves.anulado', false);
    }

    public function vigente(): bool
    {
        return $this->estado === self::EN_USO && ! $this->anulado;
    }

    /** "ID: #00012", como en SEGCAT. */
    public function folio(): string
    {
        return '#'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function etiquetaGarantia(): string
    {
        return self::GARANTIAS[$this->tipo_garantia] ?? mb_strtoupper((string) $this->tipo_garantia);
    }

    /** "INE (A123)" o "NINGUNA". */
    public function textoGarantia(): string
    {
        return $this->etiquetaGarantia().($this->folio_garantia ? ' ('.$this->folio_garantia.')' : '');
    }

    /** Texto del estado para la lista y el Excel. */
    public function etiquetaEstado(): string
    {
        return match (true) {
            $this->anulado => 'ANULADO',
            $this->estado === self::EN_USO => 'EN USO',
            $this->estado === self::NO_REGRESO => 'NO REGRESÓ',
            default => 'DEVUELTA',
        };
    }
}
