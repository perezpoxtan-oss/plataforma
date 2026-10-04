<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Sede;
use App\Models\UsuarioRol;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Las fechas se guardan en hora universal (UTC) y se muestran en la hora
 * local de quien las ve:
 *  1. la zona de su sede, si trabaja en una sola sede y esa sede tiene zona propia;
 *  2. si no, la zona de la empresa con la que trabaja;
 *  3. si no hay empresa (Super Administrador en plantillas), la de la plataforma.
 *
 * En las vistas: @fecha($registro->created_at) o @fecha($valor, 'H:i').
 */
class HoraLocal
{
    public const ZONA_PLATAFORMA = 'America/Mexico_City';

    private ?string $zona = null;

    public function __construct(private readonly EmpresaDeTrabajo $empresa) {}

    public function zona(): string
    {
        return $this->zona ??= $this->resolver();
    }

    public function formatear(DateTimeInterface|string|null $fecha, string $formato = 'd/m/Y H:i'): string
    {
        if ($fecha === null || $fecha === '') {
            return '';
        }

        $carbon = $fecha instanceof CarbonInterface ? $fecha->copy() : Carbon::parse($fecha, 'UTC');

        return $carbon->setTimezone($this->zona())->format($formato);
    }

    /**
     * Nombre corto de la zona para mostrar junto a una hora (ej. "Cancún").
     */
    public function etiqueta(): string
    {
        return ZonasHorarias::etiqueta($this->zona());
    }

    private function resolver(): string
    {
        $usuario = auth()->user();
        if ($usuario === null) {
            return self::ZONA_PLATAFORMA;
        }

        // Sus sedes asignadas (sin el filtro de empresa: es su propia asignación)
        $sedes = UsuarioRol::where('user_id', $usuario->id)->whereNotNull('sede_id')->distinct()->pluck('sede_id');
        if ($sedes->count() === 1) {
            $zonaSede = Sede::withoutGlobalScopes()->whereKey($sedes->first())->value('zona_horaria');
            if ($zonaSede) {
                return $zonaSede;
            }
        }

        $empresaId = $this->empresa->id($usuario);
        $zonaEmpresa = $empresaId === null ? null : Empresa::whereKey($empresaId)->value('zona_horaria');

        return $zonaEmpresa ?: self::ZONA_PLATAFORMA;
    }
}
