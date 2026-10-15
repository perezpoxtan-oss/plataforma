<?php

namespace Tests\Feature\Administracion;

use App\Mail\SolicitudCompraRecibida;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\SolicitudCompra;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\Calculadora;
use App\Support\CorreoPlataforma;
use Database\Seeders\CatalogoComercialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Catálogo comercial y página pública de planes.
 */
class CatalogoComercialTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->sa = $this->crearSuperadmin();
        RateLimiter::clear('comercial-contratar|127.0.0.1');
        RateLimiter::clear('comercial-contratar-dia|127.0.0.1');
    }

    private function publicar(bool $si = true): void
    {
        app(AjustesComerciales::class)->guardar(['landing_publicada' => $si]);
        $this->app->forgetScopedInstances();
        app()->forgetInstance(AjustesComerciales::class);
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function paquete(string $slug): Paquete
    {
        return Paquete::where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function solicitud(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'Juan Pérez',
            'empresa' => 'Hotel Centro',
            'correo' => 'juan@ejemplo.com.mx',
            'telefono' => '998 123 4567',
            'sedes' => 3,
            'plan_id' => $this->plan('profesional')->id,
            'paquetes' => [$this->paquete('recursos-humanos')->id, $this->paquete('kit-lector')->id],
            'mensaje' => 'Tenemos tres hoteles.',
            'acepta_privacidad' => '1',
        ], $extra);
    }

    // ------------------------------------------------------------------ Datos iniciales

    public function test_el_seeder_crea_el_catalogo_de_ejemplo_una_sola_vez_y_la_pagina_nace_sin_publicar(): void
    {
        $this->assertSame(count(CatalogoComercialSeeder::PRODUCTOS), Producto::count());
        $this->assertSame(['esencial', 'profesional', 'corporativo'], Plan::orderBy('orden')->pluck('slug')->all());
        $this->assertSame(3, Paquete::count());
        $this->assertFalse(app(AjustesComerciales::class)->landingPublicada());
        $this->assertTrue($this->plan('profesional')->destacado);
        $this->assertTrue($this->plan('corporativo')->cotizar);
        // Cada producto habilita módulos reales del catálogo (sin módulos de plataforma)
        $claves = Producto::where('clave', 'caseta')->firstOrFail()->modulos()->pluck('clave')->all();
        $this->assertContains('accesos', $claves);
        $this->assertSame(0, DB::table('modulo_producto')->join('modulos', 'modulos.id', '=', 'modulo_producto.modulo_id')->where('modulos.tipo', Modulo::TIPO_PLATAFORMA)->count());

        // Lo que el dueño borre o cambie no regresa en el siguiente despliegue
        $this->plan('esencial')->delete();
        Producto::where('clave', 'informes')->update(['nombre' => 'Reportes']);
        $this->seed(CatalogoComercialSeeder::class);
        $this->assertSame(2, Plan::count());
        $this->assertSame('Reportes', Producto::where('clave', 'informes')->value('nombre'));
    }

    // ------------------------------------------------------------------ «/» y página pública

    public function test_sin_publicar_el_visitante_va_al_acceso_y_las_paginas_publicas_no_existen(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/planes')->assertNotFound();
        $this->get('/contratar')->assertNotFound();
        $this->post('/contratar', $this->solicitud())->assertNotFound();
        $this->assertSame(0, SolicitudCompra::count());
    }

    public function test_publicada_el_visitante_ve_la_portada_en_la_raiz(): void
    {
        $this->publicar();

        $this->get('/')->assertOk()
            ->assertSee('Planes por sede al mes')->assertSee('Profesional')->assertSee('$1,490')
            ->assertSee('por sede al mes + IVA', false)->assertSee('Recomendado')
            ->assertSee('A tu medida')->assertSee('Pago único')->assertSee('Calcula tu mensualidad')
            ->assertSee('Preguntas frecuentes')->assertSee(route('login'))
            ->assertSee('js/landing.js')->assertDontSee('<script>', false);
        $this->get('/planes')->assertOk()->assertSee('Planes y precios');
        $this->get('/contratar?plan=corporativo&sedes=2')->assertOk()->assertSee('Contratar o solicitar información')
            ->assertSee('Este plan se contrata desde')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_con_sesion_la_raiz_sigue_siendo_el_inicio(): void
    {
        $this->publicar();
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');

        $this->actingAs($admin)->get('/')->assertOk()->assertDontSee('Planes por sede al mes')->assertSee('Inicio');
        $this->actingAs($this->sa)->get('/')->assertOk()->assertDontSee('Calcula tu mensualidad');
    }

    public function test_lo_dado_de_baja_u_oculto_no_se_ve(): void
    {
        $this->publicar();
        $this->plan('esencial')->update(['activo' => false]);
        $this->paquete('kit-lector')->update(['publicado' => false]);

        $this->get('/planes')->assertOk()->assertDontSee('Contratar Esencial')->assertDontSee('Kit de lector para caseta');
    }

    public function test_el_superadmin_ve_la_vista_previa_sin_publicar(): void
    {
        $this->actingAs($this->sa)->get('/catalogo-comercial/vista-previa')->assertOk()->assertSee('Vista previa')->assertSee('Profesional');
        $this->actingAs($this->sa)->get('/planes')->assertOk()->assertSee('Vista previa');
    }

    // ------------------------------------------------------------------ Cálculo

    public function test_el_calculo_por_sede_con_minimo_paquetes_e_iva(): void
    {
        $calc = app(Calculadora::class);
        $r = $calc->calcular($this->plan('profesional'), 3, collect([$this->paquete('recursos-humanos'), $this->paquete('kit-lector'), $this->paquete('puesta-en-marcha')]));
        // (1,490 + 390) × 3 = 5,640 al mes; IVA 16 % = 902.40
        $this->assertSame(['subtotal' => 564000, 'iva' => 90240, 'total' => 654240], $r['mensual']);
        // 2,800 (en total) + 4,500 × 3 = 16,300 de pago único
        $this->assertSame(['subtotal' => 1630000, 'iva' => 260800, 'total' => 1890800], $r['unico']);

        // Sedes mínimas y «Cotizar»
        $r = $calc->calcular($this->plan('corporativo'), 2, collect([$this->paquete('recursos-humanos')]));
        $this->assertSame(5, $r['sedes_cobradas']);
        $this->assertTrue($r['requiere_cotizacion']);
        $this->assertSame(0, $r['mensual']['subtotal']);
        $this->assertSame(['Recursos Humanos y candidatos'], $r['incompatibles']);

        // IVA configurable (redondeo a la mitad hacia arriba)
        app(AjustesComerciales::class)->guardar(['iva_porcentaje' => 8]);
        $this->plan('esencial')->update(['precio_por_sede_mensual' => 0.0625]);
        $r = app(Calculadora::class)->calcular($this->plan('esencial')->fresh(), 1, collect());
        $this->assertSame(['subtotal' => 6, 'iva' => 0, 'total' => 6], $r['mensual']);
        $this->assertSame(['subtotal' => 1, 'iva' => 0, 'total' => 1], Calculadora::totales(1, 1600));
        $this->assertSame(['subtotal' => 25, 'iva' => 4, 'total' => 29], Calculadora::totales(25, 1600)); // 4.0
        $this->assertSame(['subtotal' => 1250, 'iva' => 100, 'total' => 1350], Calculadora::totales(1250, 800));
        $this->assertSame(['subtotal' => 3125, 'iva' => 250, 'total' => 3375], Calculadora::totales(3125, 800));
        $this->assertSame(['subtotal' => 31, 'iva' => 5, 'total' => 36], Calculadora::totales(31, 1600)); // 4.96
    }

    public function test_la_calculadora_del_navegador_da_lo_mismo_que_el_servidor(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node no está instalado: no se puede ejecutar la calculadora del navegador.');
        }
        app(AjustesComerciales::class)->guardar(['iva_porcentaje' => 16]);
        $this->plan('esencial')->update(['precio_por_sede_mensual' => 899.99]);
        $planes = Plan::publicos()->get();
        $paquetes = Paquete::publicos()->with('planes')->get();
        $calc = app(Calculadora::class);
        $catalogo = $calc->datosParaNavegador($planes, $paquetes);

        $casos = [];
        foreach ($planes->pluck('id')->push(null) as $planId) {
            foreach ([1, 2, 3, 7, 40] as $sedes) {
                foreach ([[], $paquetes->pluck('id')->all(), [$paquetes->first()->id], [$paquetes->last()->id, 999]] as $ids) {
                    $casos[] = ['plan' => $planId, 'sedes' => $sedes, 'paquetes' => $ids];
                }
            }
        }

        $script = <<<'JS'
            const fs = require('fs'), vm = require('vm');
            const ctx = { self: {} };
            vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), ctx);
            const datos = JSON.parse(fs.readFileSync(0, 'utf8'));
            const C = ctx.self.CalculoPlanes;
            process.stdout.write(JSON.stringify(datos.casos.map(c => C.calcular(datos.catalogo, c.plan, c.sedes, c.paquetes))));
            JS;
        $proceso = new Process([$node, '-e', $script, public_path('js/landing-calculo.js')]);
        $proceso->setInput(json_encode(['catalogo' => $catalogo, 'casos' => $casos]));
        $proceso->mustRun();
        $navegador = json_decode($proceso->getOutput(), true);

        foreach ($casos as $i => $caso) {
            $plan = $caso['plan'] ? $planes->firstWhere('id', $caso['plan']) : null;
            $servidor = $calc->calcular($plan, $caso['sedes'], $paquetes->whereIn('id', $caso['paquetes'])->values());
            $js = $navegador[$i];
            $this->assertSame($servidor['mensual'], $js['mensual'], "Mensual, caso {$i}");
            $this->assertSame($servidor['unico'], $js['unico'], "Único, caso {$i}");
            $this->assertSame($servidor['sedes_cobradas'], $js['sedes_cobradas'], "Sedes, caso {$i}");
            $this->assertSame($servidor['requiere_cotizacion'], $js['requiere_cotizacion'], "Cotizar, caso {$i}");
            $this->assertSame($servidor['incompatibles'], $js['incompatibles'], "Incompatibles, caso {$i}");
            $this->assertSame(array_column($servidor['renglones'], 'importe'), array_column($js['renglones'], 'importe'), "Renglones, caso {$i}");
        }
    }

    // ------------------------------------------------------------------ Solicitudes

    public function test_una_solicitud_valida_se_guarda_con_el_desglose_del_servidor_y_avisa(): void
    {
        $this->publicar();
        Mail::fake();
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.mx', 'puerto' => 587, 'cifrado' => 'tls', 'remitente_correo' => 'avisos@ejemplo.mx'], null);
        app(AjustesComerciales::class)->guardar(['correos_solicitudes' => ['ventas@ejemplo.mx']]);
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');

        // El navegador manda un total falso: no importa, el servidor calcula
        $this->post('/contratar', $this->solicitud(['mensual_total' => '1.00']))->assertRedirect('/contratar/gracias');
        $s = SolicitudCompra::firstOrFail();
        $this->get('/contratar/gracias')->assertOk()->assertSee($s->folio)->assertSee('juan@ejemplo.com.mx');

        $this->assertSame('nueva', $s->estado);
        $this->assertSame('manual', $s->pasarela);
        $this->assertSame('6542.40', (string) $s->mensual_total);   // (1,490 + 390) × 3 + IVA
        $this->assertSame('3248.00', (string) $s->unico_total);     // 2,800 + IVA
        $this->assertSame('MXN', $s->moneda);
        $this->assertTrue($s->acepta_privacidad);
        $this->assertCount(3, $s->detalle['renglones']);
        $this->assertStringStartsWith('SC-', $s->folio);

        // Congelado: si cambia el precio, la solicitud conserva el suyo
        $this->plan('profesional')->update(['precio_por_sede_mensual' => 9999]);
        $this->assertSame('6542.40', (string) $s->fresh()->mensual_total);

        // Campana del Super Administrador (aviso de la plataforma, sin empresa) y de nadie más
        $aviso = Notificacion::withoutGlobalScopes()->where('tipo', 'solicitud_compra')->get();
        $this->assertCount(1, $aviso);
        $this->assertSame($this->sa->id, $aviso[0]->user_id);
        $this->assertNull($aviso[0]->empresa_id);
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('user_id', $admin->id)->count());
        $this->actingAs($this->sa)->getJson('/notificaciones/resumen')->assertOk()->assertJsonPath('no_leidas', 1)
            ->assertJsonPath('lista.0.titulo', "Nueva solicitud {$s->folio}: Juan Pérez (Hotel Centro)");

        // Correo al Super Administrador y a ventas (con enlace) y acuse al interesado
        Mail::assertSent(SolicitudCompraRecibida::class, fn ($m) => $m->interno && $m->hasTo($this->sa->email) && $m->hasTo('ventas@ejemplo.mx'));
        Mail::assertSent(SolicitudCompraRecibida::class, fn ($m) => ! $m->interno && $m->hasTo('juan@ejemplo.com.mx'));

        // Sin envío previo, «gracias» regresa al formulario
        $this->get('/contratar/gracias')->assertRedirect('/contratar');
    }

    public function test_la_campana_del_superadmin_muestra_el_aviso_aunque_elija_una_empresa(): void
    {
        $this->publicar();
        $empresa = $this->crearEmpresa();
        $this->post('/contratar', $this->solicitud())->assertRedirect();

        $this->actingAs($this->sa)->post('/empresa-activa', ['empresa_id' => $empresa->id]);
        $this->actingAs($this->sa)->getJson('/notificaciones/resumen')->assertOk()->assertJsonPath('no_leidas', 1);
        $id = Notificacion::withoutGlobalScopes()->where('tipo', 'solicitud_compra')->value('id');
        $this->actingAs($this->sa)->post("/notificaciones/{$id}/abrir")->assertRedirect(route('catalogo-comercial.solicitudes.show', SolicitudCompra::value('id')));
        // Leída sin tomar la empresa de trabajo
        $n = Notificacion::withoutGlobalScopes()->find($id);
        $this->assertNotNull($n->leida_en);
        $this->assertNull($n->empresa_id);
    }

    public function test_solicitud_invalida(): void
    {
        $this->publicar();
        $this->post('/contratar', $this->solicitud(['nombre' => '', 'correo' => 'no-es-correo', 'sedes' => 0, 'acepta_privacidad' => null, 'telefono' => 'abc']))
            ->assertSessionHasErrors(['nombre', 'correo', 'sedes', 'acepta_privacidad', 'telefono']);
        // Un paquete que no se suma al plan elegido
        $this->post('/contratar', $this->solicitud(['plan_id' => $this->plan('corporativo')->id]))->assertSessionHasErrors('paquetes');
        // Un plan oculto
        $this->plan('esencial')->update(['publicado' => false]);
        $this->post('/contratar', $this->solicitud(['plan_id' => $this->plan('esencial')->id, 'paquetes' => []]))->assertSessionHasErrors('plan_id');
        // Datos con forma de arreglo no rompen la pantalla
        $this->get('/contratar?plan[]=1&sedes[]=2&paquetes=x')->assertOk();
        $this->assertSame(0, SolicitudCompra::count());
    }

    public function test_el_robot_que_llena_el_campo_trampa_no_guarda_nada(): void
    {
        $this->publicar();
        Mail::fake();
        $this->post('/contratar', $this->solicitud(['sitio_web' => 'https://spam.example']))->assertRedirect('/contratar/gracias');
        $this->assertSame(0, SolicitudCompra::count());
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->count());
        Mail::assertNothingSent();
    }

    public function test_limite_de_envios_por_equipo(): void
    {
        $this->publicar();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/contratar', $this->solicitud(['correo' => "p{$i}@ejemplo.mx"]))->assertRedirect('/contratar/gracias');
        }
        $this->post('/contratar', $this->solicitud())->assertStatus(429);
        $this->assertSame(5, SolicitudCompra::count());
    }

    public function test_la_ruta_de_avisos_de_pago_esta_apagada(): void
    {
        $this->postJson('/pagos/webhook/mercadopago', ['id' => 1])->assertNotFound();
        $this->postJson('/pagos/webhook/manual', ['id' => 1])->assertNotFound();
        config(['comercial.webhooks_habilitados' => true]);
        // Aunque se encienda, una pasarela sin implementar no existe
        $this->postJson('/pagos/webhook/mercadopago', ['id' => 1])->assertNotFound();
        $this->postJson('/pagos/webhook/manual', ['id' => 1])->assertNotFound();
    }

    // ------------------------------------------------------------------ Administración

    public function test_solo_el_superadmin_administra_el_catalogo(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $plan = $this->plan('esencial');

        foreach (['/catalogo-comercial', '/catalogo-comercial/vista-previa', '/catalogo-comercial/planes/nuevo', "/catalogo-comercial/planes/{$plan->id}/editar"] as $url) {
            $this->actingAs($admin)->get($url)->assertForbidden();
        }
        $this->actingAs($admin)->put("/catalogo-comercial/planes/{$plan->id}", ['nombre' => 'X', 'precio_por_sede_mensual' => 1])->assertForbidden();
        $this->actingAs($admin)->put('/catalogo-comercial/ajustes', ['landing_publicada' => 1])->assertForbidden();
        $this->actingAs($admin)->get('/planes')->assertNotFound(); // sin publicar, solo el Super Administrador la revisa
        $this->assertSame('Esencial', $plan->fresh()->nombre);

        // No aparece en el menú ni en la Matriz del administrador de la empresa
        $this->actingAs($admin)->get('/')->assertDontSee('Catálogo comercial');
        $this->actingAs($this->sa)->get('/')->assertSee('Catálogo comercial');
        $this->actingAs($this->sa)->get('/catalogo-comercial')->assertOk()->assertSee('Catálogo comercial')->assertSee('no está publicada');
    }

    public function test_alta_edicion_baja_y_orden_de_planes_paquetes_y_productos(): void
    {
        $this->actingAs($this->sa)->post('/catalogo-comercial/productos', ['clave' => 'kiosco', 'nombre' => 'Kiosco', 'modulos' => ['candidatos']])
            ->assertRedirect()->assertSessionHas('ok');
        $producto = Producto::where('clave', 'kiosco')->firstOrFail();
        $this->assertSame(['candidatos'], $producto->modulos()->pluck('clave')->all());
        // Un módulo de plataforma no se vende
        $this->actingAs($this->sa)->post('/catalogo-comercial/productos', ['clave' => 'x', 'nombre' => 'X', 'modulos' => ['identidad']])->assertSessionHasErrors('modulos.0');

        $this->actingAs($this->sa)->post('/catalogo-comercial/planes', [
            'nombre' => 'Básico Plus', 'precio_por_sede_mensual' => '650.50', 'productos' => [$producto->id], 'publicado' => '1', 'destacado' => '1',
        ])->assertSessionHas('ok');
        $plan = Plan::where('nombre', 'Básico Plus')->firstOrFail();
        $this->assertSame('basico-plus', $plan->slug);
        $this->assertSame('650.50', (string) $plan->precio_por_sede_mensual);
        $this->assertSame(1, Plan::where('destacado', true)->count()); // un solo recomendado
        $this->assertSame(4, $plan->orden);
        $this->assertSame($this->sa->id, $plan->creado_por);
        $this->assertDatabaseHas('auditoria', ['evento' => 'catalogo_comercial.plan_creado']);
        // Sin precio y sin «Cotizar»
        $this->actingAs($this->sa)->post('/catalogo-comercial/planes', ['nombre' => 'Sin precio'])->assertSessionHasErrors('precio_por_sede_mensual');

        $this->actingAs($this->sa)->put("/catalogo-comercial/planes/{$plan->id}", ['nombre' => 'Básico Plus', 'cotizar' => '1', 'publicado' => '0'])->assertSessionHas('ok');
        $this->assertTrue($plan->fresh()->cotizar);
        $this->assertFalse($plan->fresh()->publicado);

        $this->actingAs($this->sa)->post('/catalogo-comercial/paquetes', [
            'nombre' => 'Soporte 24/7', 'modalidad' => 'suscripcion', 'precio' => '200', 'por_sede' => '0', 'planes' => [$plan->id], 'publicado' => '1',
        ])->assertSessionHas('ok');
        $paquete = Paquete::where('nombre', 'Soporte 24/7')->firstOrFail();
        $this->assertFalse($paquete->por_sede);
        $this->assertSame([$plan->id], $paquete->planes()->pluck('planes.id')->all());

        // Dar de baja y reactivar
        $this->actingAs($this->sa)->patch("/catalogo-comercial/planes/{$plan->id}/estado", ['activo' => 0])->assertSessionHas('ok');
        $this->assertFalse($plan->fresh()->activo);
        $this->actingAs($this->sa)->patch("/catalogo-comercial/planes/{$plan->id}/estado", ['activo' => 1]);
        $this->assertTrue($plan->fresh()->activo);

        // Subir un lugar
        $this->actingAs($this->sa)->patch("/catalogo-comercial/planes/{$plan->id}/mover", ['direccion' => 'arriba'])->assertSessionHas('ok');
        $this->assertSame(['esencial', 'profesional', 'basico-plus', 'corporativo'], Plan::orderBy('orden')->pluck('slug')->all());

        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=planes')->assertOk()->assertSee('Básico Plus');
        $this->actingAs($this->sa)->get("/catalogo-comercial/planes/{$plan->id}/editar")->assertOk()->assertSee('Editar plan');
        $this->actingAs($this->sa)->get('/catalogo-comercial/paquetes/nuevo')->assertOk()->assertSee('Nuevo paquete');
        $this->actingAs($this->sa)->get('/catalogo-comercial/otros/nuevo')->assertNotFound();
    }

    public function test_ajustes_publican_la_pagina_y_cambian_moneda_iva_y_textos(): void
    {
        $this->actingAs($this->sa)->put('/catalogo-comercial/ajustes', [
            'landing_publicada' => '1', 'moneda' => 'MXN', 'iva_porcentaje' => '8', 'leyenda_iva' => '+ IVA frontera',
            'portada_titulo' => 'Tu caseta en orden', 'portada_subtitulo' => 'Sin papel.',
            'beneficios' => "Uno\n\nDos", 'preguntas' => "¿Hay contrato?\nSí, mensual.\n\n¿Factura?\nSí.",
            'correos_solicitudes' => 'ventas@ejemplo.mx, Direccion@Ejemplo.mx', 'texto_legal' => 'Borrador legal.', 'aviso_privacidad' => 'Tus datos se usan para contactarte.',
        ])->assertSessionHas('ok', 'Ajustes guardados y página publicada: los visitantes ya la ven en la dirección de la plataforma.');
        $this->app->forgetScopedInstances();
        $a = app(AjustesComerciales::class)->todos();
        $this->assertTrue($a['landing_publicada']);
        $this->assertSame(8.0, $a['iva_porcentaje']);
        $this->assertSame(['Uno', 'Dos'], $a['beneficios']);
        $this->assertSame([['¿Hay contrato?', 'Sí, mensual.'], ['¿Factura?', 'Sí.']], $a['preguntas']);
        $this->assertSame(['ventas@ejemplo.mx', 'direccion@ejemplo.mx'], $a['correos_solicitudes']);
        $this->assertDatabaseHas('auditoria', ['evento' => 'catalogo_comercial.ajustes_actualizados']);

        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Tu caseta en orden')->assertSee('¿Factura?')->assertSee('+ IVA frontera')->assertSee('IVA 8 %')->assertSee('Aviso de privacidad');
        $this->get('/aviso-de-privacidad')->assertOk()->assertSee('Tus datos se usan para contactarte.');

        $this->actingAs($this->sa)->put('/catalogo-comercial/ajustes', ['moneda' => 'MXN', 'iva_porcentaje' => '101', 'portada_titulo' => 'X', 'correos_solicitudes' => 'malo@'])
            ->assertSessionHasErrors('iva_porcentaje');
        $this->actingAs($this->sa)->put('/catalogo-comercial/ajustes', ['moneda' => 'MXN', 'iva_porcentaje' => '16', 'portada_titulo' => 'X', 'correos_solicitudes' => 'malo@'])
            ->assertSessionHasErrors('correos_solicitudes');
    }

    public function test_atender_una_solicitud(): void
    {
        $this->publicar();
        $this->post('/contratar', $this->solicitud());
        $s = SolicitudCompra::firstOrFail();

        $this->actingAs($this->sa)->get('/catalogo-comercial')->assertOk()->assertSee($s->folio)->assertSee('Sin atender');
        $this->actingAs($this->sa)->get("/catalogo-comercial/solicitudes/{$s->id}")->assertOk()->assertSee('Juan Pérez')->assertSee('Tenemos tres hoteles.');
        $this->actingAs($this->sa)->put("/catalogo-comercial/solicitudes/{$s->id}", ['estado' => 'contactada'])
            ->assertSessionHas('ok', "Solicitud {$s->folio} guardada como «Contactada».");
        // «Pagada» activa al cliente (ver SuscripcionesTest) y lleva a su ficha
        $respuesta = $this->actingAs($this->sa)->put("/catalogo-comercial/solicitudes/{$s->id}", ['estado' => 'pagada', 'referencia_pago' => 'TRANSF-123', 'notas_internas' => 'Pagó por transferencia.']);
        $suscripcion = Suscripcion::where('solicitud_compra_id', $s->id)->firstOrFail();
        $respuesta->assertRedirect(route('catalogo-comercial.clientes.show', $suscripcion->id))->assertSessionHas('ok');
        $s->refresh();
        $this->assertSame('pagada', $s->estado);
        $this->assertSame('TRANSF-123', $s->referencia_pago);
        $this->assertNotNull($s->atendida_en);
        // El aviso de la campana queda resuelto
        $this->assertNotNull(Notificacion::withoutGlobalScopes()->where('tipo', 'solicitud_compra')->value('leida_en'));
        $this->actingAs($this->sa)->put("/catalogo-comercial/solicitudes/{$s->id}", ['estado' => 'regalada'])->assertSessionHasErrors('estado');
    }

    public function test_la_migracion_se_revierte_y_se_vuelve_a_aplicar(): void
    {
        $migracion = require database_path('migrations/2026_10_31_000100_crear_catalogo_comercial.php');
        $this->publicar();
        $this->post('/contratar', $this->solicitud());

        $migracion->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('planes'));
        $this->assertFalse(Modulo::where('clave', 'catalogo_comercial')->exists());
        $this->assertSame(0, DB::table('notificaciones')->whereNull('empresa_id')->count());

        $migracion->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('solicitudes_compra'));
        $modulo = Modulo::where('clave', 'catalogo_comercial')->firstOrFail();
        $this->assertSame('Plataforma', $modulo->seccion_menu);
        $this->assertSame(Modulo::TIPO_PLATAFORMA, $modulo->tipo);
    }
}
