<?php

namespace App\Services\Novedades\FichaHechos;

use App\Models\Espacio;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\User;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Support\HoraLocal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * C-31 (D-09): en un robo, lo primero es saber quién tuvo la llave. La Ficha
 * de Hechos muestra los préstamos de las llaves que abren la habitación en la
 * ventana de fechas, y a quién está asignada de forma permanente:
 *
 *  - la llave de esa habitación (alcance «Área específica / Cuarto»);
 *  - la de su piso o su edificio, la de su sección (grupo) y las maestras
 *    («Global»), que también la abren.
 *
 * Respeta «Préstamo de llaves → Ver» con su alcance (sede o propios).
 */
class PrestamosLlavesEnFicha implements FuenteFichaHechos
{
    public const COLOR = '#7c3aed';

    public function __construct(private readonly AdministradorPrestamosLlaves $prestamos) {}

    public function disponible(User $actor): bool
    {
        return $actor->can('prestamo_llaves.ver');
    }

    public function hechos(User $actor, Espacio $habitacion, ?Espacio $zona, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $llaves = $this->llavesQueAbren($habitacion);
        if ($llaves->isEmpty()) {
            return ['habitacion' => [], 'zona' => []];
        }

        $hechos = [];
        $prestamos = $this->prestamos->limitar(PrestamoLlave::query(), $actor, 'prestamo_llaves.ver')
            ->with(['colaborador:id,nombre,apellido_paterno,apellido_materno'])
            ->whereIn('prestamos_llaves.llave_id', $llaves->keys())
            ->where('prestamos_llaves.anulado', false)
            ->where('prestamos_llaves.prestado_en', '<=', $hasta)
            ->where(fn (Builder $q) => $q->whereNull('prestamos_llaves.devuelto_en')->orWhere('prestamos_llaves.devuelto_en', '>=', $desde))
            ->orderByDesc('prestamos_llaves.prestado_en')->limit(100)->get();
        $hora = app(HoraLocal::class);
        foreach ($prestamos as $p) {
            $llave = $llaves[$p->llave_id];
            $fin = match (true) {
                $p->estado === PrestamoLlave::NO_REGRESO => 'no regresó',
                $p->devuelto_en !== null => 'la devolvió el '.$hora->formatear($p->devuelto_en, 'd/m/Y H:i'),
                default => 'sigue fuera',
            };
            $hechos[] = [
                'tipo' => 'Préstamo de llave · '.$this->alcance($llave, $habitacion), 'icono' => 'bi-key-fill', 'color' => self::COLOR,
                'texto' => 'Llave '.$llave->nomenclatura.($llave->descripcion ? ' ('.$llave->descripcion.')' : '').': la tuvo '.$p->nombreReceptor()
                    .', del '.$hora->formatear($p->prestado_en, 'd/m/Y H:i').'; '.$fin.'.',
                'fecha' => $p->prestado_en, 'enlace' => route('prestamo_llaves.index').'#prestamo-'.$p->id, 'estatus' => $p->etiquetaEstado(),
            ];
        }

        // Responsable permanente (excepcional): también «tuvo la llave» todo el periodo
        foreach ($llaves->filter(fn (Llave $l) => $l->colaborador_id !== null && $l->colaborador !== null) as $llave) {
            $hechos[] = [
                'tipo' => 'Llave asignada · '.$this->alcance($llave, $habitacion), 'icono' => 'bi-key', 'color' => self::COLOR,
                'texto' => 'Llave '.$llave->nomenclatura.': asignada de forma permanente a '.$llave->colaborador->nombreCompleto().'.',
                'fecha' => $desde, 'enlace' => null, 'estatus' => null,
            ];
        }

        return ['habitacion' => $hechos, 'zona' => []];
    }

    /**
     * Llaves activas o no de la sede que abren la habitación (por id).
     *
     * @return Collection<int, Llave>
     */
    public function llavesQueAbren(Espacio $habitacion): Collection
    {
        $ancestros = $habitacion->idsAncestros();

        return Llave::query()->where('sede_id', $habitacion->sede_id)
            ->with('colaborador:id,nombre,apellido_paterno,apellido_materno')
            ->where(function (Builder $q) use ($habitacion, $ancestros) {
                $q->where('alcance', 'global')
                    ->orWhere(fn ($q2) => $q2->whereIn('alcance', ['area', 'piso', 'zona'])
                        ->whereHas('espacios', fn ($e) => $e->whereIn('espacios.id', [$habitacion->id, ...$ancestros])));
                if ($habitacion->grupo_espacio_id !== null) {
                    $q->orWhere(fn ($q2) => $q2->where('alcance', 'seccion')->whereHas('grupos', fn ($g) => $g->whereKey($habitacion->grupo_espacio_id)));
                }
            })
            ->get(['id', 'sede_id', 'nomenclatura', 'descripcion', 'alcance', 'colaborador_id'])->keyBy('id');
    }

    /** «Abre esta habitación», «abre el piso», «llave maestra»… */
    private function alcance(Llave $llave, Espacio $habitacion): string
    {
        return match ($llave->alcance) {
            'global' => 'llave maestra',
            'area' => 'abre esta habitación',
            'seccion' => 'abre su sección',
            'piso' => 'abre su piso',
            'zona' => 'abre su edificio',
            default => $llave->etiquetaAlcance(),
        };
    }
}
