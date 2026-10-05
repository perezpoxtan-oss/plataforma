<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gafete físico (SEGCAT: gafetes). Se genera por lotes por sede y tipo, se
 * imprime en "doble vista" con su QR y se da de baja con voucher si se
 * pierde, se daña o lo roban.
 *
 * Estado "EN SITIO": lo dará la Bitácora de accesos cuando se migre (el
 * gafete prestado a alguien que sigue dentro). Ver disponibleParaAsignar().
 */
class Gafete extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador;

    /** Se encuentra también tecleando o escaneando la nomenclatura impresa. */
    protected string $columnaLegible = 'nomenclatura';

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'sede_id', 'tipo_gafete_id', 'nomenclatura', 'consecutivo', 'etiqueta_nfc', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'consecutivo' => 'integer'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoGafete::class, 'tipo_gafete_id');
    }

    /**
     * ¿Se le puede prestar a alguien en caseta?
     *
     * Gancho para la Bitácora de accesos: hoy solo exige que esté activo. Al
     * migrarse Accesos se agrega aquí "y que no esté EN SITIO" (prestado a
     * una visita o a un acompañante que sigue dentro), como en SEGCAT.
     */
    public function disponibleParaAsignar(): bool
    {
        return (bool) $this->activo;
    }

    public static function tipoLector(): string
    {
        return 'gafete';
    }

    public static function permisoLector(): string
    {
        return 'gafetes.ver';
    }

    public function resumenLector(): array
    {
        $this->loadMissing(['tipo:id,nombre', 'sede:id,nombre']);

        return [
            'titulo' => $this->nomenclatura,
            'detalle' => trim(($this->tipo?->nombre ?? 'Gafete').' · '.($this->sede?->nombre ?? ''), ' ·'),
            'activo' => (bool) $this->activo,
            'sede_id' => $this->sede_id,
        ];
    }

    public function urlLector(): string
    {
        return route('gafetes.index').'#gafete-'.$this->id;
    }
}
