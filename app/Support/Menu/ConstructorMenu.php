<?php

namespace App\Support\Menu;

use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Arma el menu principal de un usuario a partir de la configuracion
 * (menus y modulos) y de sus permisos. Un modulo aparece solo si el usuario
 * puede "modulo.ver" y si ya tiene pantalla (ruta registrada): los modulos
 * que aun no se migran no se muestran en ningun menu (PC ni celular). Un menu
 * o una seccion sin modulos visibles se oculta.
 */
class ConstructorMenu
{
    /**
     * Modulos cuyo nombre cambia segun el rubro de la empresa
     * (p. ej. "Habitación" u "Oficina" según el rubro; las sedes siempre se llaman "Sedes").
     */
    private const TERMINOLOGIA = ['sedes' => 'sedes'];

    /** @var array<int, list<array<string, mixed>>> */
    private array $cache = [];

    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * @return list<array{clave: string, nombre: string, icono: ?string, orden_movil: int, activo: bool, secciones: array<string, list<array<string, mixed>>>}>
     */
    public function para(User $usuario, ?string $rutaActual = null, ?string $moduloPendiente = null): array
    {
        $menus = $this->cache[$usuario->id] ??= $this->construir($usuario);

        // $moduloPendiente se conserva por compatibilidad: los modulos sin pantalla ya no salen en el menu
        return array_map(function (array $menu) use ($rutaActual) {
            $activo = false;
            foreach ($menu['secciones'] as $seccion => $items) {
                foreach ($items as $i => $item) {
                    $item['activo'] = $rutaActual !== null && ($rutaActual === $item['ruta'] || str_starts_with($rutaActual, $item['prefijo']));
                    $menu['secciones'][$seccion][$i] = $item;
                    $activo = $activo || $item['activo'];
                }
            }
            $menu['activo'] = $activo;

            return $menu;
        }, $menus);
    }

    public function puedeVer(User $usuario, string $claveModulo): bool
    {
        return $this->autorizador->puede($usuario, $claveModulo.'.ver');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function construir(User $usuario): array
    {
        $nombresEmpresa = $this->nombresDeEmpresa($usuario);
        $terminologia = $this->terminologia($usuario);

        $modulos = Modulo::query()
            ->whereNotNull('menu_id')
            ->where('activo', true)
            ->orderBy('orden_menu')
            ->get(['id', 'menu_id', 'seccion_menu', 'nombre_menu', 'clave', 'nombre', 'icono', 'color_icono', 'ruta'])
            ->groupBy('menu_id');

        $resultado = [];

        foreach (Menu::where('activo', true)->orderBy('orden')->get() as $menu) {
            $secciones = [];

            foreach ($modulos->get($menu->id, collect()) as $modulo) {
                // Sin pantalla todavia (sin ruta): no se muestra en ningun menu
                if ($modulo->ruta === null || ! Route::has($modulo->ruta) || ! $this->puedeVer($usuario, $modulo->clave)) {
                    continue;
                }

                $nombre = $nombresEmpresa[$modulo->id]
                    ?? (isset(self::TERMINOLOGIA[$modulo->clave]) ? ($terminologia[self::TERMINOLOGIA[$modulo->clave]] ?? null) : null)
                    ?? $modulo->nombre_menu
                    ?? $modulo->nombre;

                $secciones[$modulo->seccion_menu ?? ''][] = [
                    'clave' => $modulo->clave,
                    'nombre' => $nombre,
                    'icono' => $modulo->icono ?? 'bi-circle',
                    'color' => $modulo->color_icono ?? 'primary',
                    'ruta' => $modulo->ruta,
                    'prefijo' => preg_replace('/\.[^.]+$/', '.', $modulo->ruta),
                    'url' => route($modulo->ruta),
                    'disponible' => true,
                ];
            }

            if ($secciones === []) {
                continue;
            }

            $resultado[] = [
                'clave' => $menu->clave,
                'nombre' => $menu->nombre,
                'icono' => $menu->icono,
                'orden_movil' => $menu->orden_movil,
                'activo' => false,
                'secciones' => $secciones,
            ];
        }

        return $resultado;
    }

    /**
     * Nombre que la empresa le dio a cada modulo contratado.
     *
     * @return array<int, string>
     */
    private function nombresDeEmpresa(User $usuario): array
    {
        if ($usuario->empresa_id === null) {
            return [];
        }

        return DB::table('empresa_modulos')
            ->where('empresa_id', $usuario->empresa_id)
            ->whereNotNull('nombre_visible')
            ->pluck('nombre_visible', 'modulo_id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function terminologia(User $usuario): array
    {
        if ($usuario->empresa_id === null) {
            return [];
        }

        $empresa = Empresa::with('rubro')->find($usuario->empresa_id);

        return $empresa?->rubro?->terminologia ?? [];
    }
}
