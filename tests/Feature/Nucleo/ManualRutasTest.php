<?php

namespace Tests\Feature\Nucleo;

use App\Support\TemasConfiguracion;
use Database\Seeders\CatalogoSeeder;
use Database\Seeders\MenuSeeder;
use Tests\TestCase;

/**
 * Etapa 7 · C-42: el manual nombra los menús y la Configuración como se ven.
 * Cada ruta en negritas del manual que empieza con un menú («**Operación →
 * Caseta → Préstamo de llaves**») o con «Configuración →» se compara con los
 * nombres reales: secciones y pantallas del menú (MenuSeeder, el catálogo y
 * las entradas que agrega ConstructorMenu) y las tarjetas (temas) del mosaico
 * de Configuración con sus secciones.
 * Si alguien renombra un menú o mueve un ajuste, esta prueba avisa qué
 * página del manual quedó vieja.
 */
class ManualRutasTest extends TestCase
{
    /** Pantallas que el menú agrega sin ser un módulo con menú propio (ConstructorMenu). */
    private const EXTRAS = [
        'operacion' => ['Bitácora' => ['Tickets de mi departamento']],
        'padrones' => ['Personas y vehículos' => ['Altas por verificar', 'Residentes']],
        'recursos_humanos' => ['Recepción y candidatos' => ['Mis entrevistas']],
        // «Personal» es Colaboradores en condominio y fraccionamiento (el manual lo cambia solo según el giro)
        'estructura' => ['Organización' => ['Autorizaciones', 'Personal']],
    ];

    /** El tema de la bitácora se llama como la empresa la nombre: el manual lo escribe así. */
    private const BITACORA = ['Novedades', 'Bitácora de novedades', '(tu bitácora)', '(bitácora)'];

    /**
     * Configuración en mosaico: «Configuración → X» nombra una tarjeta del
     * mosaico (App\Support\TemasConfiguracion::TEMAS) y «Configuración → X → Y»,
     * si Y es una sección, debe ser de ese tema.
     *
     * @return array<string, list<string>> nombre del tema => nombres de sus secciones
     */
    private static function temasConfiguracion(): array
    {
        $temas = [];
        foreach (TemasConfiguracion::TEMAS as $slug => [$nombre, , , $secciones]) {
            $nombres = array_map(fn ($s) => TemasConfiguracion::SECCIONES[$s][0], $secciones);
            foreach ($slug === 'novedades' ? self::BITACORA : [$nombre] as $alias) {
                $temas[$alias] = $nombres;
            }
        }

        return $temas;
    }

    /** ¿«Configuración → tema → …» es correcta? Devuelve el problema o null. */
    private static function problemaConfiguracion(array $partes): ?string
    {
        $temas = self::temasConfiguracion();
        $tema = $partes[0] ?? null;
        if (! isset($temas[$tema])) {
            return "«{$tema}» no es una tarjeta (tema) de Configuración";
        }
        $siguiente = $partes[1] ?? null;
        $todas = array_merge(...array_values(array_map(fn ($t) => $t, $temas)));
        if ($siguiente !== null && in_array($siguiente, $todas, true) && ! in_array($siguiente, $temas[$tema], true) && $siguiente !== $tema) {
            return "la sección «{$siguiente}» no está en el tema «{$tema}»";
        }

        return null;
    }

    /** @return array<string, array{nombre: string, secciones: array<string, list<string>>}> */
    private function menus(): array
    {
        $nombres = [];
        foreach (CatalogoSeeder::catalogo() as [, , $modulos]) {
            foreach ($modulos as $clave => $def) {
                $nombres[$clave] = $def[0];
                foreach ($def[3] ?? [] as $sub => $subdef) {
                    $nombres[$sub] = $subdef[0];
                }
            }
        }
        $menus = [];
        foreach (MenuSeeder::menus() as $clave => [$nombre, , , $secciones]) {
            $lista = [];
            foreach ($secciones as $seccion => $modulos) {
                foreach ($modulos as $modulo => $def) {
                    $lista[$seccion][] = is_array($def) && isset($def[1]) ? $def[1] : ($nombres[$modulo] ?? $modulo);
                }
            }
            foreach (self::EXTRAS[$clave] ?? [] as $seccion => $extras) {
                $lista[$seccion] = [...($lista[$seccion] ?? []), ...$extras];
            }
            $menus[$nombre] = ['nombre' => $nombre, 'secciones' => $lista];
        }

        return $menus;
    }

    /** @return list<array{0: string, 1: list<string>}> [página, partes] */
    private function rutasDelManual(): array
    {
        $rutas = [];
        foreach (glob(base_path('docs/usuario/*.md')) as $archivo) {
            if (basename($archivo) === 'GUIA.md') {
                continue; // la guía de estilo usa ejemplos
            }
            preg_match_all('/\*\*([^*\n]*?→[^*\n]*?)\*\*/u', (string) file_get_contents($archivo), $m);
            foreach ($m[1] as $ruta) {
                $rutas[] = [basename($archivo), array_map('trim', explode('→', $ruta))];
            }
        }

        return $rutas;
    }

    public function test_cada_ruta_de_menu_del_manual_existe(): void
    {
        $menus = $this->menus();
        $errores = [];
        foreach ($this->rutasDelManual() as [$pagina, $partes]) {
            if (! isset($menus[$partes[0]])) {
                continue;
            }
            $secciones = $menus[$partes[0]]['secciones'];
            $segundo = $partes[1] ?? null;
            $pantallas = array_merge(...array_values($secciones));
            if ($segundo === null) {
                continue;
            }
            if (isset($secciones[$segundo])) {
                // Menú → Sección → Pantalla
                if (isset($partes[2]) && ! in_array($partes[2], $secciones[$segundo], true)) {
                    $errores[] = "{$pagina}: «".implode(' → ', $partes)."»: «{$partes[2]}» no está en la sección «{$segundo}»";
                }
            } elseif (! in_array($segundo, $pantallas, true)) {
                $errores[] = "{$pagina}: «".implode(' → ', $partes)."»: «{$segundo}» no es una sección ni una pantalla del menú «{$partes[0]}»";
            } elseif ($segundo === 'Configuración' && isset($partes[2]) && ($problema = self::problemaConfiguracion(array_slice($partes, 2))) !== null) {
                $errores[] = "{$pagina}: «".implode(' → ', $partes)."»: {$problema}";
            }
        }
        $this->assertSame([], $errores);
    }

    public function test_cada_ruta_de_configuracion_del_manual_existe(): void
    {
        $errores = [];
        foreach ($this->rutasDelManual() as [$pagina, $partes]) {
            if ($partes[0] === 'Configuración' && isset($partes[1]) && ($problema = self::problemaConfiguracion(array_slice($partes, 1))) !== null) {
                $errores[] = "{$pagina}: «".implode(' → ', $partes)."»: {$problema}";
            }
        }
        $this->assertSame([], $errores);
    }

    public function test_los_temas_de_configuracion_son_las_tarjetas_del_mosaico(): void
    {
        // Cada sección de la pantalla pertenece a un solo tema y cada tema tiene nombre propio
        $vista = (string) file_get_contents(resource_path('views/administracion/configuracion/index.blade.php'));
        preg_match_all('/verSeccion\(\'([a-z-]+)\'\)/', $vista, $m);
        $enTemas = array_merge(...array_column(TemasConfiguracion::TEMAS, 3));
        foreach (array_unique($m[1]) as $seccion) {
            $this->assertSame(1, count(array_keys($enTemas, $seccion, true)), "La sección «{$seccion}» debe estar en un solo tema");
        }
        $this->assertGreaterThanOrEqual(12, count(array_unique($m[1])));
        $this->assertSame(count(TemasConfiguracion::TEMAS), count(array_unique(array_column(TemasConfiguracion::TEMAS, 0))));
        // La prueba misma detecta una ruta vieja
        $this->assertNotNull(self::problemaConfiguracion(['Cuentas y seguridad']));
        $this->assertNotNull(self::problemaConfiguracion(['General', 'Transporte']));
        $this->assertNull(self::problemaConfiguracion(['Activos, transporte y pases', 'Transporte']));
    }

    public function test_menus_md_nombra_cada_pantalla_del_menu(): void
    {
        $menusMd = (string) file_get_contents(base_path('docs/usuario/menus.md'));
        foreach ($this->menus() as $menu) {
            foreach ($menu['secciones'] as $seccion => $pantallas) {
                $this->assertStringContainsString($seccion, $menusMd, "menus.md no nombra la sección «{$seccion}»");
                foreach (array_diff($pantallas, ['Personal']) as $pantalla) {
                    $this->assertStringContainsString($pantalla, $menusMd, "menus.md no nombra «{$pantalla}» ({$menu['nombre']} → {$seccion})");
                }
            }
        }
        // Mis pendientes: lo que no regresó y las reservas (C-42)
        foreach (['Llaves, gafetes o equipos que no regresaron', 'Reservas por aprobar', 'Órdenes de servicio por atender', 'Reservas con cambio pedido'] as $renglon) {
            $this->assertStringContainsString($renglon, $menusMd);
        }
    }
}
