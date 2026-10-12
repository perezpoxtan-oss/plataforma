<?php

namespace App\Support\Menu;

use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
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

    /** Sección del menú Recursos Humanos donde van «Mis entrevistas» y «Autorizaciones». */
    public const SECCION_PERSONAL = 'Recepción y candidatos';

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

            if ($menu->clave === 'recursos_humanos') {
                $secciones = $this->conPantallasPersonales($usuario, $secciones);
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
     * Pantallas «personales» de Recursos Humanos que no son un módulo con
     * menú propio (ADR-0010): «Mis entrevistas» para quien entrevista
     * (candidatos.evaluar) y «Autorizaciones» para quien responde las de
     * algún departamento (autorizaciones.responder y es responsable o
     * delegado). Antes solo se llegaba a ellas por la campana o Mis pendientes.
     *
     * @param  array<string, list<array<string, mixed>>>  $secciones
     * @return array<string, list<array<string, mixed>>>
     */
    private function conPantallasPersonales(User $usuario, array $secciones): array
    {
        $extras = [];
        if (Route::has('entrevistas.index') && $this->autorizador->puede($usuario, 'candidatos.evaluar')) {
            $extras[] = ['clave' => 'entrevistas', 'nombre' => 'Mis entrevistas', 'icono' => 'bi-chat-square-text', 'color' => 'indigo', 'ruta' => 'entrevistas.index'];
        }
        // Responsables y delegaciones son de la empresa activa (o la del usuario); el superadministrador fuera de una empresa no aplica
        $tenant = app(Tenant::class);
        $empresaId = $tenant->activo() ? $tenant->empresaId() : $usuario->empresa_id;
        if (Route::has('autorizaciones.index') && $empresaId !== null && $this->autorizador->puede($usuario, 'autorizaciones.responder')
            && $tenant->conEmpresa((int) $empresaId, fn () => app(Autorizaciones::class)->departamentosQueAtiende($usuario) !== [])) {
            $extras[] = ['clave' => 'autorizaciones', 'nombre' => 'Autorizaciones', 'icono' => 'bi-patch-check', 'color' => 'warning', 'ruta' => 'autorizaciones.index'];
        }
        foreach ($extras as $item) {
            $secciones[self::SECCION_PERSONAL][] = $item + [
                'prefijo' => preg_replace('/\.[^.]+$/', '.', $item['ruta']),
                'url' => route($item['ruta']),
                'disponible' => true,
            ];
        }

        return $secciones;
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

        // La empresa ya viene cargada con el usuario (EstablecerEmpresa): solo falta su rubro
        $empresa = $usuario->relationLoaded('empresa') && (int) $usuario->empresa?->id === (int) $usuario->empresa_id
            ? $usuario->empresa : Empresa::find($usuario->empresa_id);
        $empresa?->loadMissing('rubro');

        return $empresa?->rubro?->terminologia ?? [];
    }
}
