<?php

namespace App\Services\Suscripciones;

use App\Models\Area;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Suscripcion;
use App\Services\Permisos\Autorizador;
use App\Support\DependenciasModulos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    /** false = como antes de la lista de dependencias (solo la migración que ordena el catálogo, para comparar y revertir). */
    public bool $conDependencias = true;

    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * @return list<int> ids de módulo
     */
    public function calcular(Suscripcion $suscripcion): array
    {
        return $this->detalle($suscripcion)['ids'];
    }

    /**
     * Módulos efectivos y lo que se agregó porque otro módulo lo necesita
     * (DependenciasModulos, C-02). Lo indispensable gana aunque haya una
     * excepción «módulo quitado»: sin ello el módulo que lo pide no guarda.
     *
     * @return array{ids: list<int>, agregados: array<string, string>, quitados: list<int>}
     */
    public function detalle(Suscripcion $suscripcion): array
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

        $incluidos = $catalogo->filter(function (Modulo $m) use ($elegidos, $quitados, $direccion, $catalogo) {
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
        });

        // Lo indispensable (C-02): lo que necesitan los módulos incluidos
        $porClave = $catalogo->keyBy('clave');
        $deDireccion = $catalogo->where('area_id', $direccion)->pluck('clave')->all();
        $completo = $this->conDependencias ? DependenciasModulos::completar($incluidos->pluck('clave')->all(), $deDireccion) : ['agregados' => []];
        $agregados = array_filter($completo['agregados'], fn ($_, $clave) => $porClave->has($clave), ARRAY_FILTER_USE_BOTH);
        $resultado = $incluidos->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (array_keys($agregados) as $clave) {
            $modulo = $porClave[$clave];
            $resultado[] = (int) $modulo->id;
            // Un submódulo agregado trae a su módulo padre
            if ($modulo->padre_id !== null) {
                $resultado[] = (int) $modulo->padre_id;
            }
        }

        return ['ids' => array_values(array_unique($resultado)), 'agregados' => $agregados, 'quitados' => $quitados];
    }

    /**
     * Escribe los módulos efectivos en empresa_modulos (sin borrar filas).
     *
     * Al bajar de plan (DD-05): un módulo que estaba activo y el plan ya no
     * trae queda en SOLO LECTURA (se consulta, exporta e imprime, no se
     * captura); el que se quitó con una excepción queda apagado del todo. Los
     * administradores de la empresa reciben un aviso con la lista.
     *
     * @return array{antes: list<string>, despues: list<string>, agregados: array<string, string>, solo_lectura: list<string>}
     */
    public function sincronizar(Suscripcion $suscripcion, bool $avisar = true): array
    {
        $empresaId = (int) $suscripcion->empresa_id;
        $detalle = $this->detalle($suscripcion);
        $efectivos = $detalle['ids'];
        $quitados = $detalle['quitados'];
        $catalogo = $this->catalogo();
        $filas = DB::table('empresa_modulos')->where('empresa_id', $empresaId)->get()->keyBy('modulo_id');
        $antes = $filas->where('activo', true)->keys()->map(fn ($id) => (int) $id)->all();
        $soloLectura = [];
        $nuevosSoloLectura = [];

        $conLectura = Schema::hasColumn('empresa_modulos', 'solo_lectura');
        DB::transaction(function () use ($catalogo, $efectivos, $empresaId, $filas, $quitados, $conLectura, &$soloLectura, &$nuevosSoloLectura) {
            foreach ($catalogo as $modulo) {
                $activo = in_array((int) $modulo->id, $efectivos, true);
                $fila = $filas->get($modulo->id);
                $lectura = $conLectura && ! $activo && $fila !== null && ($fila->activo || ($fila->solo_lectura ?? false))
                    && ! in_array((int) $modulo->id, $quitados, true);
                if ($lectura) {
                    $soloLectura[] = (int) $modulo->id;
                    if ($fila->activo) {
                        $nuevosSoloLectura[] = (int) $modulo->id;
                    }
                }
                $cambios = ['activo' => $activo] + ($conLectura ? ['solo_lectura' => $lectura] : []);
                if ($fila !== null) {
                    if ((bool) $fila->activo !== $activo || (bool) ($fila->solo_lectura ?? false) !== $lectura) {
                        DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('modulo_id', $modulo->id)
                            ->update($cambios + ['updated_at' => now(), 'actualizado_por' => Auth::id()]);
                    }
                } elseif ($activo) {
                    DB::table('empresa_modulos')->insert(['empresa_id' => $empresaId, 'modulo_id' => $modulo->id, 'created_at' => now(), 'updated_at' => now(),
                        'creado_por' => Auth::id(), 'actualizado_por' => Auth::id()] + $cambios);
                }
            }
        });
        $this->autorizador->olvidar();

        $claves = fn (array $ids) => $catalogo->whereIn('id', $ids)->pluck('clave')->sort()->values()->all();
        // Aviso al administrador (DD-05): solo los módulos principales (los submódulos van con su módulo)
        $nombres = $catalogo->whereIn('id', $nuevosSoloLectura)->whereNull('padre_id')->pluck('nombre')->values()->all();
        if ($avisar && $nombres !== []) {
            app(AvisosSuscripcion::class)->alCliente($suscripcion, 'Algunos módulos quedaron en solo consulta',
                'Tu plan ya no incluye: '.implode(', ', $nombres).'. Sus datos se conservan y puedes consultarlos, exportarlos e imprimirlos, '
                .'pero ya no registrar nada nuevo en ellos. Si los necesitas, cambia de plan o agrega el complemento desde «Tu suscripción».');
        }

        return ['antes' => $claves($antes), 'despues' => $claves($efectivos), 'agregados' => $detalle['agregados'], 'solo_lectura' => $claves($soloLectura)];
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
