<?php

namespace App\Support\Menu;

use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Database\Seeders\MenuSeeder;
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

    /** Sección del menú Recursos Humanos donde va «Mis entrevistas» («Autorizaciones» va en Estructura → Organización, C-01). */
    public const SECCION_PERSONAL = 'Recepción y candidatos';

    /** C-27: donde va «Altas por verificar» en Padrones. */
    public const SECCION_PADRONES = 'Personas y vehículos';

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

            if (in_array($menu->clave, ['recursos_humanos', 'estructura', 'padrones', 'operacion'], true)) {
                $secciones = $this->conPantallasPersonales($usuario, $secciones, $menu->clave);
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
     * Pantallas «personales» que no son un módulo con menú propio
     * (ADR-0010): «Mis entrevistas» en Recursos Humanos para quien entrevista
     * (candidatos.evaluar) y, desde la Etapa 1 (C-01), «Autorizaciones» en
     * Estructura → Organización para quien configura quién autoriza o
     * responde las de algún departamento (responsable o delegado).
     *
     * @param  array<string, list<array<string, mixed>>>  $secciones
     * @return array<string, list<array<string, mixed>>>
     */
    private function conPantallasPersonales(User $usuario, array $secciones, string $menu = 'recursos_humanos'): array
    {
        $extras = [];
        if ($menu === 'recursos_humanos' && Route::has('entrevistas.index') && $this->autorizador->puede($usuario, 'candidatos.evaluar')) {
            $extras[] = ['clave' => 'entrevistas', 'nombre' => 'Mis entrevistas', 'icono' => 'bi-chat-square-text', 'color' => 'indigo', 'ruta' => 'entrevistas.index'];
        }
        // «Autorizaciones» (bandeja, Responsables por departamento y «No molestar») va en Estructura → Organización
        // en todos los planes (C-01): para quien configura quién autoriza y para quien responde las de algún departamento.
        // Responsables y delegaciones son de la empresa activa (o la del usuario); el superadministrador fuera de una empresa no aplica
        $tenant = app(Tenant::class);
        $empresaId = $tenant->activo() ? $tenant->empresaId() : $usuario->empresa_id;
        if ($menu === 'estructura' && Route::has('autorizaciones.index') && $empresaId !== null && $this->autorizador->puede($usuario, 'autorizaciones.ver')
            && ($this->autorizador->puede($usuario, 'autorizaciones.configurar') || ($this->autorizador->puede($usuario, 'autorizaciones.responder')
                && $tenant->conEmpresa((int) $empresaId, fn () => app(Autorizaciones::class)->departamentosQueAtiende($usuario) !== [])))) {
            $extras[] = ['clave' => 'autorizaciones', 'nombre' => 'Autorizaciones', 'icono' => 'bi-patch-check', 'color' => 'warning', 'ruta' => 'autorizaciones.index'];
        }
        // C-27: una sola entrada «Altas por verificar» en Padrones, para quien verifica algún padrón o los colaboradores
        if ($menu === 'padrones' && Route::has('altas_por_verificar.index')
            && collect(['visitantes.editar', 'vehiculos.editar', 'proveedores.editar', 'colaboradores.aprobar'])->contains(fn ($p) => $this->autorizador->puede($usuario, $p))) {
            $extras[] = ['clave' => 'altas_por_verificar', 'nombre' => 'Altas por verificar', 'icono' => 'bi-patch-question', 'color' => 'warning', 'ruta' => 'altas_por_verificar.index'];
        }
        // C-10 (DD-14): «Residentes» en Padrones, solo en condominio y fraccionamiento (permisos del directorio)
        if ($menu === 'padrones' && Route::has('residentes.index') && $empresaId !== null && $this->autorizador->puede($usuario, 'colaboradores.ver')
            && Terminos::deEmpresaId((int) $empresaId)->esResidencial()) {
            array_unshift($extras, ['clave' => 'residentes', 'nombre' => 'Residentes', 'icono' => 'bi-house-heart', 'color' => 'success', 'ruta' => 'residentes.index']);
        }
        // C-28 (DD-15): «Tickets de mi departamento» para quien responde por un departamento (Mantenimiento, RR. HH.…)
        if ($menu === 'operacion' && Route::has('novedades.departamento.index') && $empresaId !== null && $this->autorizador->puede($usuario, 'autorizaciones.responder')
            && $this->autorizador->moduloActivo((int) $empresaId, 'novedades')
            && $tenant->conEmpresa((int) $empresaId, fn () => app(Autorizaciones::class)->departamentosQueAtiende($usuario) !== [])) {
            $extras[] = ['clave' => 'novedades_departamento', 'nombre' => 'Tickets de mi departamento', 'icono' => 'bi-diagram-2', 'color' => 'danger', 'ruta' => 'novedades.departamento.index'];
        }
        $seccion = match ($menu) {
            'estructura' => MenuSeeder::SECCION_ORGANIZACION,
            'padrones' => self::SECCION_PADRONES,
            'operacion' => 'Incidentes',
            default => self::SECCION_PERSONAL,
        };
        foreach ($extras as $item) {
            $secciones[$seccion][] = $item + [
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
