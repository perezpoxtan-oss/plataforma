<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneEstadoActivo;
use App\Models\Concerns\TieneIdentificador;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gafete físico (SEGCAT: gafetes). Se genera por lotes por sede y tipo, se
 * imprime en "doble vista" con su QR y se da de baja con voucher si se
 * pierde, se daña o lo roban.
 *
 * Estado "EN SITIO" (EN USO en la máquina única, C-14): lo da la Bitácora
 * de accesos (el gafete prestado a alguien que sigue dentro o espera
 * autorización). NO REGRESÓ (C-15): la persona salió sin devolverlo; no se
 * presta hasta que se reciba o se dé de baja. Ver disponibleParaAsignar().
 */
class Gafete extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneEstadoActivo, TieneIdentificador;

    /** Se encuentra también tecleando o escaneando la nomenclatura impresa. */
    protected string $columnaLegible = 'nomenclatura';

    protected $attributes = ['activo' => true, 'estado' => 'disponible'];

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
     * Debe estar activo y no estar "EN SITIO": prestado a un acceso que sigue
     * dentro o pendiente de autorización, o a un acompañante que no ha salido
     * (aunque haya salido un rato: su gafete sigue reservado), como en SEGCAT.
     */
    public function disponibleParaAsignar(): bool
    {
        return (bool) $this->activo && ! $this->noRegreso() && ! static::query()->whereKey($this->getKey())->prestados()->exists();
    }

    /**
     * Gafetes que hoy tiene alguien (Bitácora de accesos). Ver disponibleParaAsignar().
     *
     * @param  Builder<Gafete>  $consulta
     */
    public function scopePrestados(Builder $consulta): void
    {
        $abiertos = Acceso::ESTADOS_ABIERTOS;
        $consulta->where(fn ($q) => $q
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('accesos')
                ->whereColumn('accesos.gafete_id', 'gafetes.id')->whereIn('accesos.estado', $abiertos))
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('acompanantes_acceso')
                ->join('accesos as acceso_acompanante', 'acceso_acompanante.id', '=', 'acompanantes_acceso.acceso_id')
                ->whereColumn('acompanantes_acceso.gafete_id', 'gafetes.id')->whereNull('acompanantes_acceso.salida_at')
                ->whereIn('acceso_acompanante.estado', $abiertos)));
    }

    /**
     * Activos y sin prestar: los que se pueden dar en caseta.
     *
     * @param  Builder<Gafete>  $consulta
     */
    public function scopeDisponibles(Builder $consulta): void
    {
        $consulta->where('gafetes.activo', true)->where('gafetes.estado', '!=', 'no_regreso')->whereNot(fn ($q) => $q->prestados());
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

    /**
     * A dónde lleva escanear el gafete fuera de un formulario (/e/{código}):
     * si alguien lo tiene ahora (EN SITIO) y quien escanea atiende la
     * bitácora, a «Dar salida» de esa persona; si no, a su ficha en Gafetes.
     */
    public function urlLector(): string
    {
        $actor = auth()->user();
        if ($actor !== null && $actor->can('accesos.editar') && static::query()->whereKey($this->getKey())->prestados()->exists()) {
            return route('accesos.index', ['salida' => $this->nomenclatura]);
        }

        return route('gafetes.index').'#gafete-'.$this->id;
    }
}
