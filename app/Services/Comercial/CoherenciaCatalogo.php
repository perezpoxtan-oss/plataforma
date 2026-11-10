<?php

namespace App\Services\Comercial;

use App\Models\Area;
use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Support\DependenciasModulos;
use Illuminate\Support\Collection;

/**
 * Revisa que lo que se vende funcione (plan de correcciones, C-02 y C-05):
 * cada plan, solo o con cada paquete que se le puede sumar, debe traer los
 * módulos que sus módulos necesitan (App\Support\DependenciasModulos). Los
 * módulos de Dirección (menú Estructura) siempre vienen.
 *
 * La usan el Catálogo comercial (al guardar) y la prueba de CI.
 */
class CoherenciaCatalogo
{
    /** @var list<string>|null */
    private ?array $direccion = null;

    /**
     * Claves de los módulos que trae un plan (con sus paquetes, si se indican).
     *
     * @param  iterable<Paquete>  $paquetes
     * @return list<string>
     */
    public function modulosDe(?Plan $plan, iterable $paquetes = []): array
    {
        $claves = $this->deDireccion();
        foreach ($plan?->productos()->with('modulos:id,clave')->get() ?? [] as $producto) {
            $claves = array_merge($claves, $producto->modulos->pluck('clave')->all());
        }
        foreach ($paquetes as $paquete) {
            foreach ($paquete->productos()->with('modulos:id,clave')->get() as $producto) {
                $claves = array_merge($claves, $producto->modulos->pluck('clave')->all());
            }
        }

        return $this->conSubmodulos(array_values(array_unique($claves)));
    }

    /**
     * Lo que le falta a un plan (solo o con un paquete): módulo => lo que necesita.
     *
     * @param  iterable<Paquete>  $paquetes
     * @return array<string, list<string>>
     */
    public function faltantes(?Plan $plan, iterable $paquetes = []): array
    {
        return DependenciasModulos::faltantes($this->modulosDe($plan, $paquetes));
    }

    /**
     * Todos los problemas del catálogo publicado (o de todo lo activo).
     *
     * @return list<string> renglones en español
     */
    public function problemas(bool $soloPublicados = true): array
    {
        $planes = Plan::query()->where('activo', true)->when($soloPublicados, fn ($q) => $q->where('publicado', true))->orderBy('orden')->get();
        $paquetes = Paquete::query()->where('activo', true)->where('modalidad', Paquete::SUSCRIPCION)
            ->when($soloPublicados, fn ($q) => $q->where('publicado', true))->with('planes:id')->get();
        $renglones = [];
        foreach ($planes as $plan) {
            foreach ($this->renglones($this->faltantes($plan)) as $r) {
                $renglones[] = "Plan {$plan->nombre}: {$r}";
            }
            foreach ($paquetes as $paquete) {
                if ($paquete->planes->isNotEmpty() && ! $paquete->planes->contains('id', $plan->id)) {
                    continue;
                }
                foreach ($this->renglones($this->faltantes($plan, [$paquete])) as $r) {
                    $renglones[] = "Plan {$plan->nombre} con «{$paquete->nombre}»: {$r}";
                }
            }
        }

        return array_values(array_unique($renglones));
    }

    /**
     * Aviso para quien guarda un plan o un paquete (vacío si no falta nada).
     */
    public function avisoAlGuardar(Plan|Paquete $registro): string
    {
        $renglones = [];
        if ($registro instanceof Plan) {
            $renglones = $this->renglones($this->faltantes($registro));
        } else {
            $planes = $registro->planes()->get();
            foreach ($planes->isEmpty() ? [null] : $planes as $plan) {
                foreach ($this->renglones($this->faltantes($plan, [$registro])) as $r) {
                    $renglones[] = ($plan ? "Con {$plan->nombre}: " : '').$r;
                }
            }
        }

        return $renglones === [] ? '' : 'Atención, así no funciona: '.implode(' ', array_unique($renglones)).' Agrega el producto que trae lo que falta.';
    }

    /**
     * Al guardar un producto: le agrega lo que sus módulos necesitan (salvo lo
     * que siempre viene). Devuelve las claves completas y el texto «Se agregó…».
     *
     * @param  list<string>  $claves
     * @return array{claves: list<string>, aviso: string}
     */
    public function completarProducto(array $claves): array
    {
        $completo = DependenciasModulos::completar($claves, $this->deDireccion());
        $existentes = Modulo::query()->whereIn('clave', array_keys($completo['agregados']))->pluck('clave')->all();
        $agregados = array_intersect_key($completo['agregados'], array_flip($existentes));

        return [
            'claves' => array_values(array_unique(array_merge($claves, array_keys($agregados)))),
            'aviso' => DependenciasModulos::texto($agregados, $this->nombres()),
        ];
    }

    /** @param  array<string, list<string>>  $faltantes */
    private function renglones(array $faltantes): array
    {
        $nombres = $this->nombres();

        return collect($faltantes)->map(fn ($faltan, $modulo) => ($nombres[$modulo] ?? $modulo).' necesita '
            .collect($faltan)->map(fn ($f) => $nombres[$f] ?? $f)->join(', ', ' y ').'.')->values()->all();
    }

    /** @return list<string> */
    private function deDireccion(): array
    {
        return $this->direccion ??= Modulo::query()->where('activo', true)
            ->where('area_id', Area::where('clave', 'direccion')->value('id'))->pluck('clave')->all();
    }

    /**
     * Un producto con un módulo trae también sus submódulos (Novedades trae Lost & Found, Robo y Recorridos).
     *
     * @param  list<string>  $claves
     * @return list<string>
     */
    private function conSubmodulos(array $claves): array
    {
        $hijos = Modulo::query()->where('activo', true)->whereIn('padre_id', Modulo::whereIn('clave', $claves)->select('id'))->pluck('clave')->all();

        return array_values(array_unique(array_merge($claves, $hijos)));
    }

    /** @return array<string, string> */
    private function nombres(): array
    {
        return Modulo::query()->pluck('nombre', 'clave')->all();
    }

    /**
     * Módulos que cada producto trae, en palabras (para la página de planes).
     *
     * @param  Collection<int, Producto>  $productos
     * @return array<int, list<string>> producto_id => nombres de sus módulos
     */
    public static function modulosPorProducto(Collection $productos): array
    {
        $resultado = [];
        foreach ($productos as $producto) {
            $resultado[$producto->id] = $producto->modulos()->where('modulos.activo', true)->whereNull('modulos.padre_id')
                ->orderBy('modulos.area_id')->orderBy('modulos.orden')->get(['modulos.id', 'modulos.nombre', 'modulos.nombre_menu'])
                ->map(fn ($m) => $m->nombre_menu ?: $m->nombre)->values()->all();
        }

        return $resultado;
    }
}
