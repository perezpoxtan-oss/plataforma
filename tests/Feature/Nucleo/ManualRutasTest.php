<?php

namespace Tests\Feature\Nucleo;

use Database\Seeders\CatalogoSeeder;
use Database\Seeders\MenuSeeder;
use Tests\TestCase;

/**
 * Etapa 7 · C-42: el manual nombra los menús y la Configuración como se ven.
 * Cada ruta en negritas del manual que empieza con un menú («**Operación →
 * Caseta → Préstamo de llaves**») o con «Configuración →» se compara con los
 * nombres reales: secciones y pantallas del menú (MenuSeeder, el catálogo y
 * las entradas que agrega ConstructorMenu) y secciones de Configuración.
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

    /** Secciones de Configuración (vista administracion/configuracion/index). */
    private const CONFIGURACION = ['General', 'Caseta y accesos', 'Estructura', 'Recepción y RR. HH.', 'Novedades', 'Bitácora de novedades', '(tu bitácora)',
        '(bitácora)', 'Activos', 'Transporte', 'Pases de salida', 'Avisos por correo', 'Cuentas y seguridad', 'Privacidad', 'Plataforma', 'Reservas de áreas'];

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
            } elseif ($segundo === 'Configuración' && isset($partes[2]) && ! in_array($partes[2], self::CONFIGURACION, true)) {
                $errores[] = "{$pagina}: «".implode(' → ', $partes)."»: «{$partes[2]}» no es una sección de Configuración";
            }
        }
        $this->assertSame([], $errores);
    }

    public function test_cada_ruta_de_configuracion_del_manual_existe(): void
    {
        $errores = [];
        foreach ($this->rutasDelManual() as [$pagina, $partes]) {
            if ($partes[0] === 'Configuración' && isset($partes[1]) && ! in_array($partes[1], self::CONFIGURACION, true)) {
                $errores[] = "{$pagina}: «".implode(' → ', $partes)."»: «{$partes[1]}» no es una sección de Configuración";
            }
        }
        $this->assertSame([], $errores);
    }

    public function test_las_secciones_de_configuracion_son_las_de_la_pantalla(): void
    {
        $vista = (string) file_get_contents(resource_path('views/administracion/configuracion/index.blade.php'));
        preg_match_all("/\\['(sec-[a-z]+|caseta)', '([^']+)'/u", $vista, $m);
        foreach ($m[2] as $seccion) {
            $this->assertContains($seccion, self::CONFIGURACION, "La sección «{$seccion}» de Configuración falta en la lista de la prueba");
        }
        $this->assertGreaterThanOrEqual(12, count($m[2]));
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
