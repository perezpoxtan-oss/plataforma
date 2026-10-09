<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Sede;
use App\Models\User;
use App\Services\Manual\Manual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Módulo «Manual» (lección 37): ayuda dentro de la plataforma filtrada por
 * permisos, Markdown seguro, imágenes solo de docs/usuario/img y las reglas
 * que mantienen el manual al día (encabezado, cobertura, imágenes y textos prohibidos).
 */
class ManualTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    /**
     * Módulos con pantalla que no necesitan página propia: el propio Manual
     * se explica en las páginas generales (Primeros pasos y Menús).
     */
    private const SIN_PAGINA_PROPIA = ['manual'];

    /** Nada en el manual puede vincular al autor, la infraestructura o el desarrollo. */
    private const PROHIBIDOS = ['vdcp', 'qa.', 'neubox', 'cpanel', 'github', 'perezpoxtan', 'marcelino', '.demo', 'demo1234', 'segcat', 'ronda', 'pr #', 'migración'];

    private Empresa $empresa;

    private Sede $centro;

    private ?string $carpeta = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
    }

    protected function tearDown(): void
    {
        if ($this->carpeta !== null) {
            File::deleteDirectory($this->carpeta);
        }
        parent::tearDown();
    }

    /** PNG real de 1×1. */
    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /**
     * Un manual de prueba en una carpeta temporal.
     *
     * @param  array<string, string>  $paginas  slug => contenido
     */
    private function manualDePrueba(array $paginas): Manual
    {
        $this->carpeta = storage_path('framework/testing/manual-'.uniqid());
        File::ensureDirectoryExists($this->carpeta.'/img/prueba');
        File::put($this->carpeta.'/img/prueba/foto.png', $this->png());
        File::put($this->carpeta.'/img/prueba/falsa.png', 'esto no es una imagen');
        File::put($this->carpeta.'/img/prueba/nota.txt', 'texto');
        File::put($this->carpeta.'/secreto.png', $this->png());
        foreach ($paginas as $slug => $texto) {
            File::put($this->carpeta.'/'.$slug.'.md', $texto);
        }
        $manual = new Manual($this->carpeta);
        $this->app->instance(Manual::class, $manual);

        return $manual;
    }

    private function encabezado(string $titulo, string $modulos, string $seccion = 'Operación', int $orden = 10): string
    {
        return "---\ntitulo: {$titulo}\nmodulos: [{$modulos}]\nseccion: {$seccion}\norden: {$orden}\nresumen: Resumen de {$titulo}.\n---\n";
    }

    private function paginasBasicas(): Manual
    {
        return $this->manualDePrueba([
            'general' => $this->encabezado('Página general', '', 'Primeros pasos')."# Página general\n\n## Primero\n\nVe a [Accesos](accesos.md) o a [Usuarios](usuarios.md#crear).\n\n## Segundo\n\nTexto.",
            'accesos' => $this->encabezado('Bitácora de accesos', 'accesos')."# Bitácora de accesos\n\n## Cómo registrar\n\n1. Toca **Nuevo Ingreso**.",
            'usuarios' => $this->encabezado('Usuarios', 'usuarios', 'Estructura')."# Usuarios\n\n## Crear\n\nTexto.",
            'sin-encabezado' => "# Página vieja\n\nSin encabezado.",
            'GUIA' => "# Guía de redacción\n\nNo es una página.",
        ]);
    }

    // ------------------------------------------------------------------ pantallas

    public function test_sin_sesion_el_manual_pide_entrar(): void
    {
        $this->get('/manual')->assertRedirect('/login');
        $this->get('/manual/primeros-pasos')->assertRedirect('/login');
        $this->get('/manual/img/primeros-pasos/acceso.png')->assertRedirect('/login');
    }

    public function test_cada_usuario_ve_solo_las_paginas_de_lo_que_puede_ver(): void
    {
        $this->paginasBasicas();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $sinRol = $this->crearUsuario($this->empresa);
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        $this->actingAs($agente)->get('/manual')->assertOk()
            ->assertSee('Página general')->assertSee('Bitácora de accesos')->assertDontSee('Resumen de Usuarios')
            ->assertDontSee('Página vieja')->assertDontSee('Guía de redacción');
        $this->actingAs($sinRol)->get('/manual')->assertOk()->assertSee('Página general')->assertDontSee('Bitácora de accesos');
        $this->actingAs($admin)->get('/manual')->assertOk()->assertSee('Bitácora de accesos')->assertSee('Resumen de Usuarios');

        $this->actingAs($agente)->get('/manual/accesos')->assertOk()->assertSee('Cómo registrar');
        $this->actingAs($agente)->get('/manual/usuarios')->assertNotFound();
        $this->actingAs($sinRol)->get('/manual/accesos')->assertNotFound();
        $this->actingAs($agente)->get('/manual/sin-encabezado')->assertNotFound();
        $this->actingAs($agente)->get('/manual/GUIA')->assertNotFound();
        $this->actingAs($agente)->get('/manual/guia')->assertNotFound();
        $this->actingAs($agente)->get('/manual/no-existe')->assertNotFound();
    }

    public function test_otra_empresa_ve_el_manual_segun_sus_propios_permisos(): void
    {
        $this->paginasBasicas();
        $otra = $this->crearEmpresa('Hotel Dos');
        $sede = $this->crearSede($otra, 'DOS');
        $agenteOtra = $this->crearUsuario($otra, 'Agente', $sede);

        $this->actingAs($agenteOtra)->get('/manual/accesos')->assertOk();
        $this->actingAs($agenteOtra)->get('/manual/usuarios')->assertNotFound();

        // Una empresa que no tiene contratado Accesos no ve esa página
        $sinAccesos = $this->crearEmpresa('Hotel Tres', ['usuarios']);
        $adminSinAccesos = $this->crearUsuario($sinAccesos, 'Administrador');
        $this->actingAs($adminSinAccesos)->get('/manual/accesos')->assertNotFound();
    }

    public function test_la_busqueda_filtra_sin_acentos_y_tolera_parametros_raros(): void
    {
        $this->paginasBasicas();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/manual?q=BITACORA')->assertOk()->assertSee('Bitácora de accesos')->assertDontSee('Resumen de Página general');
        $this->actingAs($agente)->get('/manual?q=zzzz')->assertOk()->assertSee('No encontramos páginas con «zzzz»');
        $this->actingAs($agente)->get('/manual?q[]=x')->assertOk()->assertSee('Bitácora de accesos');
        $this->actingAs($agente)->get('/manual?q=%3Cscript%3E')->assertOk()->assertDontSee('<script>', false);
    }

    public function test_indice_de_la_pagina_imprimir_y_vecinas(): void
    {
        $this->paginasBasicas();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $r = $this->actingAs($agente)->get('/manual/general')->assertOk()
            ->assertSee('En esta página')
            ->assertSee('<a href="#primero">Primero</a>', false)
            ->assertSee('<h2 id="primero">', false)
            ->assertSee('data-accion="imprimir"', false);
        // el «# Título» del archivo no se repite: lo muestra el encabezado de la pantalla
        $this->assertSame(1, substr_count((string) $r->getContent(), '<h1'));
    }

    // ------------------------------------------------------------------ Markdown seguro

    public function test_el_markdown_no_deja_pasar_html_ni_enlaces_externos(): void
    {
        $manual = $this->manualDePrueba([
            'peligro' => $this->encabezado('Peligro', '', 'Primeros pasos')
                ."# Peligro\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n"
                ."[malo](javascript:alert(3)) [externo](https://ejemplo.com/x) [servidor](/etc/passwd) [ancla](#arriba)\n\n"
                ."![bien](img/prueba/foto.png) ![falta](img/prueba/no-existe.png) ![fuera](../secreto.png) ![remota](https://ejemplo.com/a.png)\n\n"
                ."| A | B |\n|---|---|\n| 1 | 2 |\n",
        ]);
        $usuario = $this->crearUsuario($this->empresa);
        $html = $manual->contenido($manual->pagina('peligro'), $usuario)['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src="x"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('ejemplo.com', $html);
        $this->assertStringNotContainsString('href="/etc/passwd"', $html);
        $this->assertStringContainsString('externo', $html);
        $this->assertStringContainsString('<a href="#arriba">ancla</a>', $html);
        $this->assertStringContainsString('src="'.route('manual.imagen', ['prueba', 'foto.png']).'"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('no-existe.png', $html);
        $this->assertStringNotContainsString('secreto', $html);
        $this->assertStringContainsString('<table class="manual-tabla">', $html);
    }

    public function test_los_enlaces_a_paginas_que_no_puedes_ver_quedan_como_texto(): void
    {
        $manual = $this->paginasBasicas();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $pagina = $manual->pagina('general');

        $html = $manual->contenido($pagina, $agente)['html'];
        $this->assertStringContainsString('<a href="'.route('manual.ver', 'accesos').'">Accesos</a>', $html);
        $this->assertStringContainsString('<span class="manual-enlace-sin-acceso">Usuarios</span>', $html);
        $this->assertStringNotContainsString(route('manual.ver', 'usuarios'), $html);

        $html = $manual->contenido($pagina, $admin)['html'];
        $this->assertStringContainsString('<a href="'.route('manual.ver', 'usuarios').'#crear">Usuarios</a>', $html);
    }

    public function test_la_cache_se_renueva_cuando_cambia_el_archivo(): void
    {
        $manual = $this->paginasBasicas();
        $usuario = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->assertStringContainsString('Nuevo Ingreso', $manual->contenido($manual->pagina('accesos'), $usuario)['html']);

        $ruta = $this->carpeta.'/accesos.md';
        File::put($ruta, $this->encabezado('Bitácora de accesos', 'accesos')."# Bitácora\n\n## Cambio\n\nTexto **nuevo**.");
        touch($ruta, time() + 10);
        clearstatcache();

        $nuevo = new Manual($this->carpeta);
        $contenido = $nuevo->contenido($nuevo->pagina('accesos'), $usuario);
        $this->assertStringContainsString('<strong>nuevo</strong>', $contenido['html']);
        $this->assertSame([['id' => 'cambio', 'texto' => 'Cambio']], $contenido['apartados']);
    }

    // ------------------------------------------------------------------ imágenes

    public function test_las_imagenes_solo_salen_de_la_carpeta_del_manual(): void
    {
        $this->paginasBasicas();
        $usuario = $this->crearUsuario($this->empresa);

        $r = $this->actingAs($usuario)->get('/manual/img/prueba/foto.png')->assertOk();
        $this->assertSame('image/png', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('max-age=604800', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        $this->assertNotNull($r->headers->get('ETag'));

        foreach (['/manual/img/prueba/falsa.png', '/manual/img/prueba/nota.txt', '/manual/img/prueba/no-hay.png', '/manual/img/prueba/..%2F..%2Fsecreto.png',
            '/manual/img/../secreto.png', '/manual/img/prueba/%2e%2e', '/manual/img/..%2Fsecreto.png/x.png', '/manual/img/PRUEBA/foto.png'] as $url) {
            $this->assertContains($this->actingAs($usuario)->get($url)->getStatusCode(), [404], $url);
        }
    }

    // ------------------------------------------------------------------ menú y botón «?»

    public function test_el_menu_tiene_el_manual_y_la_pantalla_su_boton_de_ayuda(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        // Con el manual real: Accesos tiene su página y el Agente la puede ver
        $this->actingAs($agente)->get('/accesos')->assertOk()
            ->assertSee('href="'.route('manual.index').'"', false)
            ->assertSee('data-ayuda-contextual', false)
            ->assertSee('href="'.route('manual.ver', 'accesos').'"', false);

        // Una pantalla sin página visible no muestra el «?»
        $this->paginasBasicas();
        $this->actingAs($agente)->get('/prestamo-llaves')->assertOk()->assertDontSee('data-ayuda-contextual', false);
    }

    public function test_el_agente_ve_las_paginas_de_operacion_pero_no_las_de_estructura(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $manual = app(Manual::class);

        $visibles = collect($manual->indice($agente))->flatten()->map(fn ($p) => $p->slug)->all();
        foreach (['primeros-pasos', 'inicio-de-sesion', 'menus', 'accesos', 'prestamo-llaves', 'novedades'] as $slug) {
            $this->assertContains($slug, $visibles);
        }
        $this->assertNull($manual->paginaDeModulo('usuarios', $agente));
        $this->assertSame('accesos', $manual->paginaDeModulo('accesos', $agente)?->slug);
    }

    // ------------------------------------------------------------------ reglas del manual real (docs/usuario)

    public function test_cada_pagina_tiene_encabezado_valido_con_modulos_del_catalogo(): void
    {
        $manual = new Manual;
        $claves = Modulo::pluck('clave')->all();
        $fallas = [];

        foreach ($manual->archivos() as $slug => $ruta) {
            [$encabezado, $errores] = $manual->leerEncabezado((string) file_get_contents($ruta));
            foreach ($errores as $error) {
                $fallas[] = "{$slug}.md: {$error}";
            }
            foreach ($encabezado['modulos'] ?? [] as $modulo) {
                if (! in_array($modulo, $claves, true)) {
                    $fallas[] = "{$slug}.md: el módulo «{$modulo}» no existe en el catálogo";
                }
            }
        }

        $this->assertSame([], $fallas, implode("\n", $fallas));
        $this->assertFileExists(base_path('docs/usuario/GUIA.md'));
        $this->assertArrayNotHasKey('GUIA', $manual->archivos());
    }

    public function test_todo_modulo_con_pantalla_tiene_su_pagina_del_manual(): void
    {
        $manual = new Manual;
        $cubiertos = collect($manual->paginas())->flatMap(fn ($p) => $p->modulos)->all();

        $faltan = Modulo::whereNotNull('ruta')->where('activo', true)->pluck('clave')
            ->reject(fn ($clave) => in_array($clave, $cubiertos, true) || in_array($clave, self::SIN_PAGINA_PROPIA, true))
            ->values()->all();

        $this->assertSame([], $faltan, 'Módulos con pantalla sin página en docs/usuario (agrega «modulos: [...]» a su página): '.implode(', ', $faltan));
    }

    public function test_imagenes_y_enlaces_del_manual_existen(): void
    {
        $manual = new Manual;
        $fallas = [];

        foreach ($manual->archivos() as $slug => $ruta) {
            if ($manual->pagina($slug) === null) {
                continue; // páginas pendientes de la otra rama (las demás ya fallan en la prueba del encabezado)
            }
            $texto = (string) file_get_contents($ruta);
            preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $texto, $imagenes);
            foreach ($imagenes[1] as $imagen) {
                if (! preg_match('#^img/([a-z0-9-]+)/([^/]+)$#', $imagen, $m) || $manual->rutaImagen($m[1], $m[2]) === null) {
                    $fallas[] = "{$slug}.md: la imagen «{$imagen}» no existe en docs/usuario/img";
                }
            }
            preg_match_all('/(?<!!)\[[^\]]*\]\(([^)\s]+)\)/', $texto, $enlaces);
            foreach ($enlaces[1] as $enlace) {
                if (preg_match('/^([a-z0-9-]+)\.md(#.*)?$/', $enlace, $m)) {
                    if (! isset($manual->archivos()[$m[1]])) {
                        $fallas[] = "{$slug}.md: el enlace «{$enlace}» apunta a una página que no existe";
                    }
                } elseif (! str_starts_with($enlace, '#')) {
                    $fallas[] = "{$slug}.md: el enlace «{$enlace}» no es a otra página del manual";
                }
            }
        }

        $this->assertSame([], $fallas, implode("\n", $fallas));
    }

    public function test_ninguna_pagina_vincula_al_autor_ni_a_la_infraestructura(): void
    {
        $manual = new Manual;
        $fallas = [];

        foreach ($manual->archivos() as $slug => $ruta) {
            $texto = mb_strtolower((string) file_get_contents($ruta));
            foreach (self::PROHIBIDOS as $prohibido) {
                if (str_contains($texto, $prohibido)) {
                    $fallas[] = "{$slug}.md contiene «{$prohibido}»";
                }
            }
        }

        $this->assertSame([], $fallas, implode("\n", $fallas));
    }

    public function test_la_regla_de_actualizar_el_manual_esta_en_las_convenciones(): void
    {
        $this->assertStringContainsString('docs/usuario/GUIA.md', (string) file_get_contents(base_path('CLAUDE.md')));
        $this->assertStringContainsString('docs/usuario', (string) file_get_contents(base_path('.github/workflows/paquete.yml')));
    }

    public function test_el_superadmin_ve_todas_las_paginas_con_encabezado(): void
    {
        $manual = new Manual;
        $superadmin = $this->crearSuperadmin();
        $visibles = collect($manual->indice($superadmin))->flatten()->count();

        $this->assertSame(count($manual->paginas()), $visibles);
        $this->assertGreaterThanOrEqual(14, $visibles);
    }

    public function test_un_usuario_sin_empresa_de_trabajo_no_rompe_el_manual(): void
    {
        $usuario = User::factory()->create(['empresa_id' => null]);
        $this->actingAs($usuario)->get('/manual')->assertOk()->assertSee('Primeros pasos');
        $this->actingAs($usuario)->get('/manual/primeros-pasos')->assertOk();
        $this->actingAs($usuario)->get('/manual/accesos')->assertNotFound();
    }
}
