<?php

namespace App\Services\Suscripciones;

use App\Models\Area;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Suscripcion;
use App\Services\Permisos\Autorizador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Módulos que tiene un cliente con suscripción:
 *
 *   módulos de los productos del plan + de los productos de sus paquetes
 *   + excepciones «módulo agregado» − excepciones «módulo quitado»
 *
 * Además, siempre los de Dirección (empresa, sedes, usuarios, roles,
 * permisos, configuración, auditoría…) y los submódulos de cada módulo
 * incluido. Se escriben en empresa_modulos, que ya respetan el motor de
 * permisos y el menú: lo que se quita solo se oculta (activo = 0); sus datos
 * y permisos se conservan.
 */
class ModulosEfectivos
{
    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * @return list<int> ids de módulo
     */
    public function calcular(Suscripcion $suscripcion): array
    {
        $suscripcion->loadMissing(['plan.productos.modulos:id', 'paquetes.productos.modulos:id', 'excepciones']);
        $ids = collect();
        foreach ($suscripcion->plan?->productos ?? [] as $producto) {
            $ids = $ids->merge($producto->modulos->pluck('id'));
        }
        foreach ($suscripcion->paquetes as $paquete) {
            foreach ($paquete->productos as $producto) {
                $ids = $ids->merge($producto->modulos->pluck('id'));
            }
        }
        $activas = $suscripcion->excepciones->where('activo', true);
        $ids = $ids->merge($activas->where('tipo', 'modulo_agregado')->pluck('modulo_id'));
        $quitados = $activas->where('tipo', 'modulo_quitado')->pluck('modulo_id')->map(fn ($id) => (int) $id)->all();

        $catalogo = $this->catalogo();
        $direccion = (int) Area::where('clave', 'direccion')->value('id');
        $elegidos = $ids->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => in_array($id, $quitados, true))->values()->all();

        return $catalogo->filter(function (Modulo $m) use ($elegidos, $quitados, $direccion, $catalogo) {
            if ((int) $m->area_id === $direccion) {
                return true;
            }
            if (in_array((int) $m->id, $quitados, true)) {
                return false;
            }
            if (in_array((int) $m->id, $elegidos, true)) {
                return true;
            }
            // Submódulo de un módulo incluido (y que no se quitó)
            $padre = $m->padre_id === null ? null : $catalogo->firstWhere('id', $m->padre_id);

            return $padre !== null && in_array((int) $padre->id, $elegidos, true);
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Escribe los módulos efectivos en empresa_modulos (sin borrar filas).
     *
     * @return array{antes: list<string>, despues: list<string>}
     */
    public function sincronizar(Suscripcion $suscripcion): array
    {
        $empresaId = (int) $suscripcion->empresa_id;
        $efectivos = $this->calcular($suscripcion);
        $catalogo = $this->catalogo();
        $antes = DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('activo', true)->pluck('modulo_id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($catalogo, $efectivos, $empresaId) {
            foreach ($catalogo as $modulo) {
                $activo = in_array((int) $modulo->id, $efectivos, true);
                $existe = DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $modulo->id);
                if ($existe->exists()) {
                    $existe->update(['activo' => $activo, 'updated_at' => now(), 'actualizado_por' => Auth::id()]);
                } elseif ($activo) {
                    DB::table('empresa_modulos')->insert(['empresa_id' => $empresaId, 'modulo_id' => $modulo->id, 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                        'creado_por' => Auth::id(), 'actualizado_por' => Auth::id()]);
                }
            }
        });
        $this->autorizador->olvidar();

        $claves = fn (array $ids) => $catalogo->whereIn('id', $ids)->pluck('clave')->sort()->values()->all();

        return ['antes' => $claves($antes), 'despues' => $claves($efectivos)];
    }

    /**
     * Nombres de los módulos activos de la empresa (para la ficha).
     *
     * @return Collection<int, string>
     */
    public function nombresActivos(Empresa $empresa): Collection
    {
        return DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresa->id)->where('em.activo', true)->where('m.activo', true)->whereNull('m.padre_id')
            ->where('m.tipo', '!=', Modulo::TIPO_PLATAFORMA)->orderBy('m.area_id')->orderBy('m.orden')->pluck('m.nombre');
    }

    /** @return Collection<int, Modulo> */
    private function catalogo(): Collection
    {
        return Modulo::query()->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->get(['id', 'clave', 'padre_id', 'area_id', 'nombre']);
    }
}
