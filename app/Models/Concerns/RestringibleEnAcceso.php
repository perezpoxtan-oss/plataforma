<?php

namespace App\Models\Concerns;

use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * C-13 (DD-07): «Restringir acceso» (personas, vehículos y empresas
 * externas), separado de «Dar de baja». Con motivo y, si se quiere, fecha de
 * fin (restringido_hasta, inclusive; vacía = hasta que se quite). Mientras
 * esté vigente, la caseta no lo puede registrar.
 *
 * Columnas: restringido_en, restringido_hasta, restringido_motivo, restringido_por.
 */
trait RestringibleEnAcceso
{
    public function initializeRestringibleEnAcceso(): void
    {
        $this->mergeCasts(['restringido_en' => 'datetime', 'restringido_hasta' => 'date']);
    }

    /** ¿Tiene el acceso restringido hoy (en la hora local)? */
    public function restringido(): bool
    {
        if (($this->attributes['restringido_en'] ?? null) === null) {
            return false;
        }
        $hasta = $this->attributes['restringido_hasta'] ?? null;

        return $hasta === null || substr((string) $hasta, 0, 10) >= Carbon::now(app(HoraLocal::class)->zona())->format('Y-m-d');
    }

    /** «Acceso restringido hasta el 30/11/2026: motivo» / «… (sin fecha de fin): motivo». */
    public function textoRestriccion(): string
    {
        $hasta = $this->restringido_hasta?->format('d/m/Y');

        return 'Acceso restringido'.($hasta ? ' hasta el '.$hasta : ' (sin fecha de fin)').($this->restringido_motivo ? ': '.$this->restringido_motivo : '');
    }

    /** Con restricción vigente. */
    public function scopeRestringidos(Builder $consulta): void
    {
        $hoy = Carbon::now(app(HoraLocal::class)->zona())->format('Y-m-d');
        $consulta->whereNotNull($this->qualifyColumn('restringido_en'))
            ->where(fn ($q) => $q->whereNull($this->qualifyColumn('restringido_hasta'))->orWhere($this->qualifyColumn('restringido_hasta'), '>=', $hoy));
    }
}
