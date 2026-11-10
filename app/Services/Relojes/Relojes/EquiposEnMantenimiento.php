<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Equipo;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use App\Support\EstadoActivo;
use Illuminate\Support\Collection;

/** Equipo de seguridad EN MANTENIMIENTO (C-24, GC-09): que no se quede ahí para siempre. */
class EquiposEnMantenimiento extends Reloj
{
    public function clave(): string
    {
        return 'equipos_mantenimiento';
    }

    public function nombre(): string
    {
        return 'Equipos en mantenimiento';
    }

    public function suplenteOmision(): string
    {
        return 'quien administra los equipos en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['equipos'];
    }

    public function permiso(): ?string
    {
        return 'equipos.editar';
    }

    public function renglonDef(): ?array
    {
        return ['equipos_mantenimiento', 'Equipos en mantenimiento', 'bi-tools', route('equipos.index', ['estado' => EstadoActivo::EN_MANTENIMIENTO])];
    }

    public function textosInicio(): ?array
    {
        return ['1 equipo lleva tiempo en mantenimiento', '%d equipos llevan tiempo en mantenimiento', 'Cuando regrese de reparación, márcalo disponible; si ya no sirve, dalo de baja.', 'Ver equipos'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Equipo::with('tipo:id,nombre')->where('estado', EstadoActivo::EN_MANTENIMIENTO)->orderBy('updated_at')->limit(500)->get()
            ->map(fn (Equipo $e) => new Espera('equipo_mantenimiento', (int) $e->id, $e->updated_at ?? now(), 'En mantenimiento: '.($e->tipo?->nombre ?? 'equipo').($e->numero_serie ? ' '.$e->numero_serie : ''),
                route('equipos.index', ['estado' => EstadoActivo::EN_MANTENIMIENTO]).'#equipo-'.$e->id, $e->sede_id !== null ? (int) $e->sede_id : null))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'equipos.editar', $e->sedeId);
    }

    public function cuentaDesdeElRecordatorio(): bool
    {
        return true;
    }
}
