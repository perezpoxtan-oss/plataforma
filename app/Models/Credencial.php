<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Support\EstadoActivo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C-44 (Etapa 8): la credencial del colaborador (el plástico del empleado;
 * el gafete es el de las visitas) como un activo más, con los estados de
 * C-14:
 *
 *   (Dar salida) → ASIGNADO ─Recibir (OK)→ DISPONIBLE (la guarda RR. HH.) ─Dar salida→ ASIGNADO
 *                  ASIGNADO ─Recibir · Dañado / Dar de baja (voucher)→ DE BAJA
 *                  ASIGNADO ─No regresó→ NO REGRESÓ ─Recibir→ DISPONIBLE | ─Dar de baja (voucher)→ DE BAJA
 *                  cualquiera ─baja del colaborador («Antes de dar de baja»)→ DE BAJA
 *
 * Su QR y su tarjeta NFC/RFID son los del colaborador (el lector lo
 * encuentra a él); al darla de baja por extravío, robo o daño la tarjeta se
 * quita y el QR se cambia, para que el plástico perdido ya no lo identifique.
 */
class Credencial extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'credenciales';

    /** Estados que usa la credencial (los textos son los de EstadoActivo). */
    public const ESTADOS = [EstadoActivo::DISPONIBLE, EstadoActivo::ASIGNADO, EstadoActivo::NO_REGRESO, EstadoActivo::BAJA];

    public const MOTIVOS_BAJA = [
        'reposicion' => 'Reposición (extraviada, dañada o robada)',
        'baja_colaborador' => 'Baja del colaborador',
    ];

    protected $attributes = ['estado' => EstadoActivo::ASIGNADO];

    protected $fillable = ['sede_id', 'colaborador_id'];

    protected function casts(): array
    {
        return ['entregada_en' => 'datetime', 'recibida_en' => 'datetime', 'baja_en' => 'datetime', 'consecutivo' => 'integer'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function entrego(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregada_por');
    }

    public function recibio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibida_por');
    }

    /** Las que no están de baja (a lo más una por colaborador). */
    public function scopeVigentes(Builder $consulta): void
    {
        $consulta->where($this->qualifyColumn('estado'), '!=', EstadoActivo::BAJA);
    }

    /** Para Vouchers (Recuperado): la credencial cuenta como activa si no está de baja. */
    public function getActivoAttribute(): bool
    {
        return ($this->attributes['estado'] ?? EstadoActivo::ASIGNADO) !== EstadoActivo::BAJA;
    }

    public function textoEstado(): string
    {
        return EstadoActivo::texto($this->estado);
    }
}
