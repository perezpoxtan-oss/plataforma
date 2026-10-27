<?php

namespace App\Services\Novedades\FichaHechos;

use App\Models\Acceso;
use App\Models\Espacio;
use App\Models\User;
use App\Services\Accesos\ConsultaAccesos;
use App\Support\HoraLocal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * C-31 (D-09, con C-30): los accesos ligados a la habitación del catálogo en
 * la ventana de fechas (el huésped de esa habitación, quien vino a verlo, el
 * proveedor que trabajó ahí) y, como contexto, los proveedores, contratistas
 * y visitas que trabajaron en otras áreas de la misma zona. Solo los que se
 * ligaron al catálogo: el texto libre de antes no se adivina.
 *
 * Respeta «Accesos → Ver» con su alcance (sede o propios).
 */
class AccesosEnFicha implements FuenteFichaHechos
{
    public const COLOR = '#0891b2';

    /** Tipos que se muestran como contexto en la zona (los huéspedes de otras habitaciones no). */
    public const TIPOS_EN_ZONA = ['proveedor', 'contratista', 'visitante'];

    public const MAX = 50;

    public function __construct(private readonly ConsultaAccesos $consulta) {}

    public function disponible(User $actor): bool
    {
        return $actor->can('accesos.ver');
    }

    public function hechos(User $actor, Espacio $habitacion, ?Espacio $zona, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $base = fn () => $this->consulta->limitar(Acceso::query(), $actor, 'accesos.ver')
            ->where('accesos.sede_id', $habitacion->sede_id)
            ->where('accesos.movimiento', 'entrada')
            ->whereNotNull('accesos.entrada_at')
            ->where('accesos.entrada_at', '<=', $hasta)
            ->where(fn (Builder $q) => $q->whereNull('accesos.salida_at')->orWhere('accesos.salida_at', '>=', $desde))
            ->with('espacio:id,nombre');

        $enHabitacion = $base()->where('accesos.espacio_id', $habitacion->id)
            ->orderByDesc('accesos.entrada_at')->limit(self::MAX)->get()->map(fn (Acceso $a) => $this->hecho($a))->all();

        $enZona = [];
        if ($zona !== null) {
            $enZona = $base()->whereIn('accesos.tipo', self::TIPOS_EN_ZONA)
                ->where('accesos.espacio_id', '!=', $habitacion->id)
                ->whereIn('accesos.espacio_id', Espacio::query()->where('ruta', 'like', $zona->ruta.'%')->select('id'))
                ->orderByDesc('accesos.entrada_at')->limit(self::MAX)->get()->map(fn (Acceso $a) => $this->hecho($a))->all();
        }

        return ['habitacion' => $enHabitacion, 'zona' => $enZona];
    }

    /** @return array<string, mixed> */
    private function hecho(Acceso $a): array
    {
        $hora = app(HoraLocal::class);
        $salida = match (true) {
            $a->salida_at !== null => 'salió el '.$hora->formatear($a->salida_at, 'd/m/Y H:i'),
            $a->estado === 'pendiente' => 'esperaba autorización',
            default => 'sigue en sitio',
        };
        $detalle = array_filter([
            $a->empresa_procedencia,
            $a->espacio && $a->tipo !== 'huesped' ? 'en '.$a->espacio->nombre : null,
            $a->actividad,
        ]);

        return [
            'tipo' => 'Acceso · '.$a->etiquetaTipo(), 'icono' => 'bi-door-open-fill', 'color' => self::COLOR,
            'texto' => $a->nombre.($detalle ? ' ('.implode(' · ', $detalle).')' : '').': entró el '.$hora->formatear($a->entrada_at, 'd/m/Y H:i').'; '.$salida.'.',
            'fecha' => $a->entrada_at, 'enlace' => route('accesos.index', ['pestana' => $a->estado === 'finalizado' ? 'historial' : null, 'q' => $a->nombre]).'#acceso-'.$a->id,
            'estatus' => Acceso::ESTADOS[$a->estado] ?? null,
        ];
    }
}
