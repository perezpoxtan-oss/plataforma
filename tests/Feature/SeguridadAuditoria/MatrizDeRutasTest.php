<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Auditoría de autorización: recorre TODAS las rutas con sesión de la
 * aplicación (php artisan route:list) contra la empresa demo completa.
 *
 *  - Sin rol: ninguna ruta entrega datos ni cambia nada (403/404).
 *  - Otra empresa: todo registro ajeno responde 404, igual que un id que no existe.
 *  - Otra sede: ningún registro de otra sede se ve ni se toca.
 *  - Formularios con ids ajenos: nada queda apuntando a otra empresa u otra sede.
 *  - Los botones y enlaces que se muestran corresponden a permisos que se tienen.
 *
 * Cuando se agrega una ruta nueva, entra sola en estas pruebas.
 */
class MatrizDeRutasTest extends TestCase
{
    use EscenarioAuditoria, RefreshDatabase;

    /** Rutas sin datos de nadie, abiertas a toda sesión. */
    private const LIBRES = ['panel', 'sesion.latido',
        // Centro de notificaciones (ADR-0007): solo las del propio usuario
        'notificaciones.index', 'notificaciones.resumen', 'notificaciones.leer-todas'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
    }

    private function pedir(User $usuario, Route $ruta, string $uri, bool $ajax = false): TestResponse
    {
        $metodo = $ruta->methods()[0];
        $respuesta = $this->actingAs($usuario)
            ->call($metodo, $uri, [], [], [], $ajax ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'] : []);
        $this->app['auth']->forgetGuards();
        session()->flush();

        return $respuesta;
    }

    private function contenido(TestResponse $r): string
    {
        return $r->baseResponse instanceof StreamedResponse ? (string) $r->streamedContent() : (string) $r->getContent();
    }

    public function test_sin_rol_ninguna_ruta_entrega_datos_ni_cambia_nada(): void
    {
        $sinRol = $this->crearUsuario($this->demo);
        $huella = $this->huellaDatos();
        $revisadas = 0;
        $fallas = [];

        foreach ($this->rutasConSesion() as $ruta) {
            $nombre = (string) $ruta->getName();
            if (in_array($nombre, self::LIBRES, true)) {
                continue;
            }
            $uri = $this->uri($ruta);
            $this->assertNotNull($uri, "Sin dato demo para {$nombre}");
            if ($nombre === 'lector.resolver') {
                // El lector no exige permiso propio: solo busca en lo que el usuario puede ver
                $codigo = DB::table('llaves')->where('empresa_id', $this->demo->id)->value('codigo_qr');
                $this->pedir($sinRol, $ruta, $uri.'?entrada='.$codigo, true)->assertOk()->assertJsonCount(0, 'resultados');

                continue;
            }

            $estado = $this->pedir($sinRol, $ruta, $uri)->getStatusCode();
            if (! in_array($estado, [403, 404], true)) {
                $fallas[] = "{$ruta->methods()[0]} {$uri} ({$nombre}) respondió {$estado} a un usuario sin rol";
            }
            $revisadas++;
        }

        $this->assertSame([], $fallas);
        $this->assertGreaterThan(200, $revisadas);
        $this->assertSame($huella, $this->huellaDatos(), 'Un usuario sin rol cambió datos');

        // Sin rol, el menú no ofrece ningún módulo
        $panel = $this->actingAs($sinRol)->get('/')->assertOk();
        foreach (['/llaves', '/accesos', '/novedades', '/usuarios', '/roles', '/colaboradores'] as $enlace) {
            $panel->assertDontSee('href="'.url($enlace).'"', false);
        }
    }

    public function test_otra_empresa_recibe_404_en_todo_registro_ajeno_sin_revelar_que_existe(): void
    {
        $intrusoSinRol = $this->crearUsuario($this->intrusa);
        $huella = $this->huellaDatos();
        $revisadas = 0;
        $fallas = [];

        foreach ($this->rutasConSesion() as $ruta) {
            $nombre = (string) $ruta->getName();
            // Sin registro en la ruta: parámetros que no son de la empresa (módulo, respaldo de la plataforma)
            if ($ruta->parameterNames() === [] || in_array($nombre, ['modulos.pendiente', 'configuracion.respaldos.descargar'], true)) {
                continue;
            }
            $real = $this->uri($ruta);
            $inexistente = $this->uri($ruta, null, true);

            foreach ([false, true] as $ajax) {
                if ($ajax && $ruta->methods()[0] !== 'GET') {
                    continue;
                }
                $estado = $this->pedir($this->adminIntruso, $ruta, $real, $ajax)->getStatusCode();
                if ($estado !== 404) {
                    $fallas[] = "{$nombre}: el administrador de otra empresa recibió {$estado} en {$real}";
                }

                $conDato = $this->pedir($intrusoSinRol, $ruta, $real, $ajax)->getStatusCode();
                $sinDato = $this->pedir($intrusoSinRol, $ruta, $inexistente, $ajax)->getStatusCode();
                if (! in_array($conDato, [403, 404], true) || $sinDato !== $conDato) {
                    $fallas[] = "{$nombre}: sin rol recibió {$conDato} con el registro ajeno y {$sinDato} con uno inexistente (revela si existe)";
                }
            }
            $revisadas++;
        }

        $this->assertSame([], $fallas);
        $this->assertGreaterThan(100, $revisadas);
        $this->assertSame($huella, $this->huellaDatos(), 'Otra empresa cambió datos de la demo');
    }

    public function test_las_listas_busquedas_y_exportaciones_no_muestran_datos_de_otra_empresa(): void
    {
        $marcas = ['Hotel Demo', 'Ana Administradora', 'Andrea Agente', 'Roberto Hern'];

        foreach ($this->rutasConSesion() as $ruta) {
            if (! in_array('GET', $ruta->methods(), true) || $ruta->parameterNames() !== []) {
                continue;
            }
            $uri = route($ruta->getName(), ['q' => 'Roberto', 'entrada' => 'Roberto', 'texto' => 'Roberto'], false);
            foreach ([false, true] as $ajax) {
                $cuerpo = $this->contenido($this->pedir($this->adminIntruso, $ruta, $uri, $ajax));
                foreach ($marcas as $marca) {
                    $this->assertStringNotContainsString($marca, $cuerpo, "{$ruta->getName()} muestra «{$marca}» a otra empresa");
                }
            }
        }
    }

    public function test_con_alcance_de_sede_ningun_registro_de_otra_sede_se_ve_ni_se_toca(): void
    {
        $actores = [
            'agente de Playa' => $this->usuario('agente2.demo'),
            'jefe de seguridad de Playa' => $this->crearUsuario($this->demo, 'Jefe de seguridad', $this->playa),
            'supervisor de Playa' => $this->crearUsuario($this->demo, 'Supervisor', $this->playa),
        ];
        $huella = $this->huellaDatos();
        $revisadas = 0;
        $fallas = [];

        foreach ($this->rutasConSesion() as $ruta) {
            if (! $this->rutaConSede($ruta)) {
                continue;
            }
            $uri = $this->uri($ruta, $this->centro);
            if ($uri === null) {
                continue; // la demo no tiene un registro exclusivo de Centro de este tipo
            }
            foreach ($actores as $quien => $actor) {
                foreach ([false, true] as $ajax) {
                    if ($ajax && $ruta->methods()[0] !== 'GET') {
                        continue;
                    }
                    $r = $this->pedir($actor, $ruta, $uri, $ajax);
                    if ($r->isSuccessful()) {
                        $fallas[] = "{$quien}: {$ruta->methods()[0]} {$uri} ({$ruta->getName()}) respondió {$r->getStatusCode()} con un registro de Centro";
                    }
                }
            }
            $revisadas++;
        }

        $this->assertSame([], $fallas);
        $this->assertGreaterThan(80, $revisadas);
        $this->assertSame($huella, $this->huellaDatos(), 'Un usuario de Playa cambió datos de Centro');
    }

    public function test_formularios_con_ids_ajenos_no_dejan_nada_apuntando_a_otra_empresa_ni_a_otra_sede(): void
    {
        $this->assertSame([], $this->referenciasCruzadas(), 'La demo ya trae referencias cruzadas');

        // Cada formulario que ve el administrador de la otra empresa, con los ids de la demo
        $enviados = $this->enviarFormularios($this->adminIntruso, null, $this->centro);
        $this->assertGreaterThan(25, $enviados);
        $this->assertSame([], $this->referenciasCruzadas(), 'Quedaron registros apuntando a otra empresa');
        $this->assertSame(0, $this->tocadosPor($this->adminIntruso, null), 'Otra empresa modificó registros de la demo');

        // Cada formulario que ve el Jefe de seguridad de Playa, con los ids de Centro
        $jefe = $this->crearUsuario($this->demo, 'Jefe de seguridad', $this->playa);
        $enviados = $this->enviarFormularios($jefe, $this->playa, $this->centro);
        $this->assertGreaterThan(50, $enviados);
        $this->assertSame([], $this->referenciasCruzadas());
        $this->assertSame(0, $this->tocadosPor($jefe, $this->centro), 'El jefe de Playa creó o modificó registros de Centro');
    }

    public function test_los_botones_y_enlaces_visibles_corresponden_a_permisos_que_se_tienen(): void
    {
        $revisados = 0;
        foreach (['agente.demo', 'agente2.demo', 'supervisor.demo', 'rh.demo', 'director.demo', 'jefe.demo'] as $nombre) {
            $usuario = $this->usuario($nombre);
            foreach ($this->paginasVisibles($usuario, $this->centro) as $html) {
                // Formularios con botón para enviar
                preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $formas, PREG_SET_ORDER);
                foreach ($formas as [, $atributos, $cuerpo]) {
                    if (! preg_match('/action="([^"]+)"/i', $atributos, $a)
                        || ! preg_match('/<button(?![^>]*type="button")(?![^>]*disabled)[^>]*>|<input[^>]*type="submit"(?![^>]*disabled)/i', $cuerpo)) {
                        continue;
                    }
                    $ruta = parse_url(html_entity_decode($a[1]), PHP_URL_PATH) ?: '/';
                    if (in_array($ruta, ['/logout', '/empresa-activa'], true)) {
                        continue;
                    }
                    $metodo = preg_match('/name="_method"\s+value="([A-Za-z]+)"/i', $cuerpo, $m) ? strtoupper($m[1])
                        : (preg_match('/method="post"/i', $atributos) ? 'POST' : 'GET');
                    $estado = $this->actingAs($usuario)->call($metodo, $ruta)->getStatusCode();
                    $this->app['auth']->forgetGuards();
                    $this->assertNotSame(403, $estado, "{$nombre} ve un botón para {$metodo} {$ruta} pero no tiene el permiso");
                    $revisados++;
                }
                // Enlaces a impresiones, exportaciones, etiquetas y firmas
                preg_match_all('/<a\b[^>]*href="([^"#]+)"/i', $html, $enlaces);
                foreach (array_unique($enlaces[1]) as $href) {
                    $href = html_entity_decode($href);
                    $host = parse_url($href, PHP_URL_HOST);
                    $ruta = parse_url($href, PHP_URL_PATH) ?: '/';
                    if (($host !== null && $host !== parse_url(url('/'), PHP_URL_HOST))
                        || ! preg_match('/exportar|imprimir|etiqueta|calcomania|hoja|vale|firma|itinerario|\/dia$|\/qr/', $ruta)) {
                        continue;
                    }
                    $estado = $this->actingAs($usuario)->get($href)->getStatusCode();
                    $this->app['auth']->forgetGuards();
                    $this->assertNotContains($estado, [403, 404], "{$nombre} ve el enlace {$href} pero responde {$estado}");
                    $revisados++;
                }
            }
        }
        $this->assertGreaterThan(50, $revisados);
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * HTML de cada pantalla GET (listas y fichas de la sede indicada) que el usuario puede abrir.
     *
     * @return list<string>
     */
    private function paginasVisibles(User $usuario, ?Sede $sede): array
    {
        $paginas = [];
        foreach ($this->rutasConSesion() as $ruta) {
            if (! in_array('GET', $ruta->methods(), true) || str_contains((string) $ruta->getName(), 'exportar')) {
                continue;
            }
            $uri = $ruta->parameterNames() === [] ? route($ruta->getName(), [], false) : ($sede ? $this->uri($ruta, $sede) : null);
            if ($uri === null) {
                continue;
            }
            $r = $this->actingAs($usuario)->get($uri);
            $this->app['auth']->forgetGuards();
            if ($r->getStatusCode() === 200 && str_contains((string) $r->headers->get('Content-Type'), 'html')) {
                $paginas[] = (string) $r->getContent();
            }
        }

        return $paginas;
    }

    private function enviarFormularios(User $usuario, ?Sede $suSede, Sede $ajena): int
    {
        $formas = [];
        foreach ($this->paginasVisibles($usuario, $suSede) as $html) {
            foreach ($this->formularios($html) as $f) {
                $formas[$f[0].' '.$f[1]] = $f;
            }
        }
        foreach ($formas as [$metodo, $ruta, $datos]) {
            $this->actingAs($usuario)->call($metodo, $ruta, $this->conIdsAjenos($datos, $ajena));
            $this->app['auth']->forgetGuards();
            session()->flush();
        }

        return count($formas);
    }

    /**
     * Registros de la demo (o de una sede) que el usuario creó o modificó.
     */
    private function tocadosPor(User $usuario, ?Sede $sede): int
    {
        $total = 0;
        foreach (Schema::getTableListing() as $tabla) {
            $tabla = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
            $columnas = Schema::getColumnListing($tabla);
            if (! in_array('creado_por', $columnas, true) || ! in_array('empresa_id', $columnas, true) || ($sede !== null && ! in_array('sede_id', $columnas, true))) {
                continue;
            }
            $total += DB::table($tabla)->where('empresa_id', $this->demo->id)
                ->when($sede !== null, fn ($q) => $q->where('sede_id', $sede->id))
                ->where(fn ($q) => $q->where('creado_por', $usuario->id)->orWhere('actualizado_por', $usuario->id))
                ->count();
        }

        return $total;
    }
}
