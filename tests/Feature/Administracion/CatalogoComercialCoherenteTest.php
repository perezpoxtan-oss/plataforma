<?php

namespace Tests\Feature\Administracion;

use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Comercial\CoherenciaCatalogo;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\Aprovisionamiento;
use App\Services\Suscripciones\ModulosEfectivos;
use App\Support\DependenciasModulos;
use Database\Seeders\CatalogoComercialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Catálogo comercial coherente con lo construido: credenciales (QR, 125 kHz,
 * MIFARE), lector QR/NFC y equipos de acceso incluidos en todos los planes;
 * Asistencia incluida en Corporativo (apagada hasta que la empresa la
 * enciende) y complemento de Esencial y Profesional; pagos únicos nuevos.
 */
class CatalogoComercialCoherenteTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->sa = $this->crearSuperadmin();
        RateLimiter::clear('comercial-contratar|127.0.0.1');
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_11_22_000100_catalogo_comercial_coherente.php');
    }

    private function cliente(string $plan, array $paquetes = []): Suscripcion
    {
        [$s] = app(CatalogoPublico::class)->registrar(['nombre' => 'Laura Méndez', 'empresa' => 'Cliente '.$plan.Suscripcion::count(), 'correo' => 'c'.Suscripcion::count().$plan.'@ejemplo.com.mx',
            'sedes' => $plan === 'corporativo' ? 5 : 1, 'plan_id' => Plan::where('slug', $plan)->value('id'),
            'paquetes' => array_map(fn ($slug) => Paquete::where('slug', $slug)->value('id'), $paquetes)], '127.0.0.1');

        return app(Aprovisionamiento::class)->confirmarPago($s, $this->sa, 'T-1');
    }

    private function admin(Suscripcion $s): User
    {
        $admin = User::where('empresa_id', $s->empresa_id)->orderBy('id')->firstOrFail();
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();

        return $admin;
    }

    private function asistenciaActiva(Suscripcion $s): bool
    {
        app(Autorizador::class)->olvidar();

        return app(Autorizador::class)->moduloActivo((int) $s->empresa_id, 'asistencia');
    }

    /** @return list<string> */
    private function planesDe(string $paquete): array
    {
        return Paquete::where('slug', $paquete)->firstOrFail()->planes()->orderBy('orden')->pluck('slug')->all();
    }

    /** @return list<string> */
    private function productosDe(string $plan): array
    {
        return Plan::where('slug', $plan)->firstOrFail()->productos()->pluck('clave')->all();
    }

    // ------------------------------------------------------------------ Instalación nueva

    public function test_el_seeder_crea_el_catalogo_nuevo_y_es_coherente(): void
    {
        $this->assertSame(['estructura', 'caseta', 'padrones'], $this->productosDe('esencial'));
        $this->assertSame(['estructura', 'caseta', 'padrones', 'incidentes', 'recursos_humanos', 'informes', 'asistencia'], $this->productosDe('corporativo'));
        $this->assertSame(['esencial', 'profesional'], $this->planesDe('recursos-humanos'));
        $this->assertSame(['esencial', 'profesional', 'corporativo'], $this->planesDe('reservas-y-eventos'));
        $this->assertSame(['esencial', 'profesional'], $this->planesDe('asistencia'));

        $this->assertSame(['recursos-humanos', 'reservas-y-eventos', 'asistencia', 'puesta-en-marcha', 'kit-lector', 'integracion-equipo-acceso', 'credenciales-cr80'],
            Paquete::orderBy('orden')->pluck('slug')->all());
        $unicos = Paquete::where('modalidad', Paquete::UNICO)->get()->keyBy('slug');
        $this->assertSame(['puesta-en-marcha', 'kit-lector', 'integracion-equipo-acceso', 'credenciales-cr80'], $unicos->sortBy('orden')->keys()->values()->all());
        $this->assertSame('4500.00', $unicos['puesta-en-marcha']->precio);
        $this->assertTrue($unicos['puesta-en-marcha']->por_sede);
        $this->assertSame('2800.00', $unicos['kit-lector']->precio);
        $this->assertSame('1500.00', $unicos['integracion-equipo-acceso']->precio);
        $this->assertSame('1200.00', $unicos['credenciales-cr80']->precio);
        $this->assertFalse($unicos['integracion-equipo-acceso']->por_sede);
        $this->assertSame('Integración de equipo de acceso', $unicos['integracion-equipo-acceso']->nombre);
        $this->assertSame('Credenciales CR80 (paquete de 100)', $unicos['credenciales-cr80']->nombre);
        $this->assertStringContainsString('ZKTeco o Hikvision', $unicos['integracion-equipo-acceso']->descripcion);
        $this->assertStringContainsString('125 kHz o MIFARE', $unicos['kit-lector']->descripcion);
        $this->assertStringContainsString('QR', $unicos['kit-lector']->descripcion);
        $this->assertStringContainsString('125 kHz o MIFARE', $unicos['credenciales-cr80']->descripcion);
        // Los pagos únicos se suman a cualquier plan
        $this->assertSame(0, DB::table('paquete_plan')->whereIn('paquete_id', $unicos->pluck('id'))->count());

        // Textos: credenciales, tarjetas y equipos de acceso (sin módulo nuevo)
        $this->assertStringContainsString('125 kHz o MIFARE', Plan::where('slug', 'esencial')->value('descripcion'));
        $this->assertStringContainsString('ZKTeco o Hikvision', Plan::where('slug', 'esencial')->value('descripcion'));
        $this->assertStringContainsString('credenciales', Plan::where('slug', 'esencial')->value('frase'));
        $this->assertStringContainsString('Asistencia', Plan::where('slug', 'corporativo')->value('descripcion'));
        $this->assertStringContainsString('credencial de empleado', Producto::where('clave', 'estructura')->value('descripcion'));
        $this->assertStringContainsString('equipos de acceso', Producto::where('clave', 'caseta')->value('descripcion'));
        $this->assertSame(count(CatalogoComercialSeeder::PRODUCTOS), Producto::count());

        // Ningún plan ni paquete rompe la lista de dependencias (todo lo activo, publicado o no)
        $this->assertSame([], app(CoherenciaCatalogo::class)->problemas(false));
        $this->assertSame([], DependenciasModulos::faltantes(app(CoherenciaCatalogo::class)->modulosDe(Plan::where('slug', 'corporativo')->first())));
    }

    // ------------------------------------------------------------------ Corporativo y Asistencia

    public function test_corporativo_da_acceso_a_asistencia_y_la_empresa_la_enciende(): void
    {
        $s = $this->cliente('corporativo');
        $admin = $this->admin($s);
        // Incluida pero apagada de fábrica (decisión 13)
        $this->assertFalse($this->asistenciaActiva($s));
        $detalle = app(ModulosEfectivos::class)->detalle($s->fresh());
        $asistencia = (int) Modulo::where('clave', 'asistencia')->value('id');
        $this->assertSame([$asistencia], $detalle['encendibles']);
        $this->assertSame([$asistencia], $detalle['por_encender']);

        // La página 403 dice que viene en el plan y dónde se enciende
        $this->actingAs($admin)->get('/asistencia')->assertForbidden()->assertSee('Asistencia viene en tu plan y está apagado')->assertSee('Usar Asistencia');
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertSee('Usar Asistencia (incluido en tu plan)')
            ->assertSee('Apagado: no aparece en el menú hasta que lo enciendas.');

        // La enciende
        $this->actingAs($admin)->post(route('mi-suscripcion.modulos'), ['modulo' => 'asistencia', 'encendido' => '1'])
            ->assertRedirect(route('empresas.index').'#tu-suscripcion')->assertSessionHas('ok', fn ($m) => str_contains($m, 'Asistencia quedó encendido'));
        $this->assertTrue($this->asistenciaActiva($s));
        $this->assertSame(['asistencia'], ModulosEfectivos::encendidosPor((int) $s->empresa_id));
        $this->actingAs($admin)->get('/asistencia')->assertOk();
        $this->assertDatabaseHas('auditoria', ['evento' => 'empresas.modulos_encendidos']);
        // Al volver a sincronizar (renovación, pago) sigue encendida
        app(ModulosEfectivos::class)->sincronizar($s->fresh());
        $this->assertTrue($this->asistenciaActiva($s));

        // La apaga: queda apagada del todo, sin aviso de «tu plan ya no incluye» y sin solo lectura
        $this->actingAs($admin)->post(route('mi-suscripcion.modulos'), ['modulo' => 'asistencia'])->assertSessionHas('ok', fn ($m) => str_contains($m, 'quedó apagado'));
        $this->assertFalse($this->asistenciaActiva($s));
        $this->assertDatabaseHas('empresa_modulos', ['empresa_id' => $s->empresa_id, 'modulo_id' => $asistencia, 'activo' => false, 'solo_lectura' => false]);
        $this->assertFalse(Notificacion::withoutGlobalScopes()->where('user_id', $admin->id)->where('titulo', 'Algunos módulos quedaron en solo consulta')->exists());

        // Bajar a Profesional con Asistencia encendida: el plan ya no la trae (solo lectura, como cualquier módulo)
        $this->actingAs($admin)->post(route('mi-suscripcion.modulos'), ['modulo' => 'asistencia', 'encendido' => '1']);
        app(Aprovisionamiento::class)->cambiarPlan($s->fresh(), Plan::where('slug', 'profesional')->first(), 1, [], 'mensual', $this->sa, 'Bajó de plan');
        $this->assertFalse($this->asistenciaActiva($s));
        $this->assertTrue(app(Autorizador::class)->moduloEnSoloLectura((int) $s->empresa_id, 'asistencia'));
        // …y con el complemento vuelve a estar activa
        app(Aprovisionamiento::class)->cambiarPlan($s->fresh(), Plan::where('slug', 'profesional')->first(), 1, [Paquete::where('slug', 'asistencia')->value('id')], 'mensual', $this->sa, 'Agregó Asistencia');
        $this->assertTrue($this->asistenciaActiva($s));
    }

    public function test_sin_asistencia_en_el_plan_no_se_puede_encender(): void
    {
        $s = $this->cliente('profesional');
        $admin = $this->admin($s);
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertDontSee('Usar Asistencia');
        $this->actingAs($admin)->post(route('mi-suscripcion.modulos'), ['modulo' => 'asistencia', 'encendido' => '1'])->assertSessionHasErrors('modulo');
        $this->assertFalse($this->asistenciaActiva($s));
        $this->actingAs($admin)->get('/asistencia')->assertForbidden()->assertSee('Esto no viene en tu plan');

        // Otro rol (sin editar la empresa) no la enciende aunque sea Corporativo
        $c = $this->cliente('corporativo');
        $agente = $this->crearUsuario(Empresa::findOrFail($c->empresa_id), 'Agente');
        $this->actingAs($agente)->post(route('mi-suscripcion.modulos'), ['modulo' => 'asistencia', 'encendido' => '1'])->assertForbidden();
        $this->assertFalse($this->asistenciaActiva($c));

        // Con el complemento, Profesional la tiene encendida desde que se paga (como siempre)
        $p = $this->cliente('profesional', ['asistencia']);
        $this->assertTrue($this->asistenciaActiva($p));
        $this->assertSame([], app(ModulosEfectivos::class)->detalle($p->fresh())['encendibles']);
    }

    // ------------------------------------------------------------------ Página pública y Contratar

    public function test_la_pagina_de_planes_y_contratar_muestran_el_catalogo_nuevo(): void
    {
        app(AjustesComerciales::class)->guardar(['landing_publicada' => true]);
        $this->app->forgetScopedInstances();
        app()->forgetInstance(AjustesComerciales::class);

        $pagina = $this->get('/planes')->assertOk()
            ->assertSee('Integración de equipo de acceso')->assertSee('Credenciales CR80 (paquete de 100)')
            ->assertSee('Kit de lector para caseta')->assertSee('125 kHz o MIFARE')->assertSee('ZKTeco o Hikvision')
            ->assertSee('La caseta en orden desde el primer día, con credenciales QR y lector.')
            ->assertSee('Para grupos con varias sedes, Recursos Humanos y asistencia.');
        $html = $pagina->getContent();
        // Tarjeta de Corporativo: incluye Asistencia; Asistencia se suma solo a Esencial y Profesional
        $corporativo = substr($html, strpos($html, 'id="plan-'.Plan::where('slug', 'corporativo')->value('id').'"'));
        $corporativo = substr($corporativo, 0, strpos($corporativo, '</article>'));
        $this->assertStringContainsString('Asistencia', $corporativo);
        $esencial = substr($html, strpos($html, 'id="plan-'.Plan::where('slug', 'esencial')->value('id').'"'));
        $this->assertStringNotContainsString('Asistencia', substr($esencial, 0, strpos($esencial, '</article>')));
        $this->assertStringContainsString('Se suma a Esencial y Profesional.', $html);

        $this->get('/contratar?plan=corporativo')->assertOk()
            ->assertSee('Integración de equipo de acceso')->assertSee('Credenciales CR80 (paquete de 100)')
            ->assertSee('Solo se suma a Esencial y Profesional.');

        // El servidor no cobra Asistencia aparte con Corporativo; los pagos únicos sí se suman
        [, , $calculo] = app(CatalogoPublico::class)->cotizar(Plan::where('slug', 'corporativo')->value('id'), 5,
            Paquete::whereIn('slug', ['asistencia', 'integracion-equipo-acceso', 'credenciales-cr80'])->pluck('id')->all());
        $this->assertSame(['Asistencia'], $calculo['incompatibles']);
        $this->assertEqualsCanonicalizing(['Integración de equipo de acceso', 'Credenciales CR80 (paquete de 100)'],
            collect($calculo['renglones'])->where('tipo', 'paquete')->pluck('nombre')->all());

        // Contratar en línea registra la solicitud con los paquetes nuevos
        $this->post('/contratar', [
            'rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'nombre' => 'Juan Pérez', 'empresa' => 'Hotel Centro',
            'correo' => 'juan@ejemplo.com.mx', 'telefono' => '998 123 4567', 'sedes' => 1, 'plan_id' => Plan::where('slug', 'esencial')->value('id'),
            'paquetes' => Paquete::whereIn('slug', ['asistencia', 'kit-lector', 'credenciales-cr80'])->pluck('id')->all(), 'acepta_privacidad' => '1',
        ])->assertRedirect();
        $detalle = collect(json_decode((string) DB::table('solicitudes_compra')->latest('id')->value('detalle'), true)['renglones'] ?? [])->pluck('nombre')->all();
        $this->assertContains('Asistencia', $detalle);
        $this->assertContains('Credenciales CR80 (paquete de 100)', $detalle);
    }

    // ------------------------------------------------------------------ Base existente: la migración

    /** Deja el catálogo como estaba antes de esta entrega, con cambios del Super Administrador. */
    private function catalogoViejo(): void
    {
        $corporativo = Plan::where('slug', 'corporativo')->firstOrFail();
        $corporativo->productos()->detach(Producto::where('clave', 'asistencia')->value('id'));
        Paquete::where('slug', 'asistencia')->firstOrFail()->planes()->syncWithoutDetaching([$corporativo->id]);
        Paquete::whereIn('slug', ['integracion-equipo-acceso', 'credenciales-cr80'])->delete();
        Plan::where('slug', 'esencial')->update(['frase' => 'La caseta en orden desde el primer día.',
            'descripcion' => 'Bitácora de accesos, llaves, pases de salida y padrones para una operación de seguridad ordenada.']);
        Plan::where('slug', 'profesional')->update(['descripcion' => 'Todo lo de Esencial, más la bitácora de incidentes, objetos olvidados, recorridos y los informes para la dirección.']);
        Plan::where('slug', 'corporativo')->update(['descripcion' => 'Seguridad, incidentes, informes y Recursos Humanos, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.']);
        Producto::where('clave', 'estructura')->update(['descripcion' => 'Empresas, sedes, zonas, usuarios, roles y permisos, directorio de colaboradores, departamentos, puestos, turnos, quién autoriza y bitácora de auditoría.']);
        Producto::where('clave', 'caseta')->update(['descripcion' => 'Bitácora de accesos con autorización del departamento, llaves, vouchers, pases de salida y transporte.']);
        Paquete::where('slug', 'kit-lector')->update(['descripcion' => 'Lector de gafetes y códigos QR listo para conectarse a la computadora de la caseta.']);
        // Lo que el Super Administrador cambió o creó
        Paquete::where('slug', 'kit-lector')->update(['precio' => 3100]);
        Paquete::where('slug', 'asistencia')->update(['precio' => 250]);
        Plan::where('slug', 'corporativo')->update(['frase' => 'Frase escrita por el dueño.', 'precio_por_sede_mensual' => 2500]);
        Paquete::create(['nombre' => 'Soporte en sitio', 'slug' => 'soporte-en-sitio', 'modalidad' => Paquete::UNICO, 'precio' => 900, 'por_sede' => false, 'publicado' => true, 'orden' => 9]);
    }

    /** @return array<string, mixed> */
    private function foto(): array
    {
        return [
            'planes' => Plan::orderBy('id')->get(['slug', 'frase', 'descripcion', 'precio_por_sede_mensual', 'publicado'])->toArray(),
            'paquetes' => Paquete::orderBy('id')->get(['slug', 'nombre', 'descripcion', 'precio', 'por_sede', 'publicado'])->toArray(),
            'productos' => Producto::orderBy('id')->get(['clave', 'descripcion'])->toArray(),
            'plan_producto' => DB::table('plan_producto')->orderBy('plan_id')->orderBy('producto_id')->get()->map(fn ($f) => (array) $f)->all(),
            'paquete_plan' => DB::table('paquete_plan')->orderBy('paquete_id')->orderBy('plan_id')->get()->map(fn ($f) => (array) $f)->all(),
            'empresa_modulos' => DB::table('empresa_modulos')->orderBy('empresa_id')->orderBy('modulo_id')->get(['empresa_id', 'modulo_id', 'activo', 'solo_lectura'])->map(fn ($f) => (array) $f)->all(),
            'preferencias' => Empresa::orderBy('id')->pluck('preferencias', 'id')->all(),
        ];
    }

    public function test_en_una_instalacion_nueva_no_hace_nada(): void
    {
        $antes = $this->foto();
        DB::table('paquete_plan')->delete();
        DB::table('plan_producto')->delete();
        DB::table('paquetes')->delete();
        DB::table('planes')->delete();
        $this->migracion()->up();
        $this->assertFalse(ConfiguracionPlataforma::where('clave', 'catalogo_comercial_coherente')->exists());
        $this->assertNotEmpty($antes['planes']);
    }

    public function test_la_migracion_lleva_un_catalogo_viejo_al_nuevo_sin_tocar_precios_y_se_revierte(): void
    {
        $this->catalogoViejo();
        $asistencia = (int) Modulo::where('clave', 'asistencia')->value('id');
        // Clientes de antes: Corporativo con el complemento Asistencia (la usa), Corporativo sin él, Esencial con él
        $corpCon = $this->cliente('corporativo', ['asistencia']);
        $corpSin = $this->cliente('corporativo');
        $esencial = $this->cliente('esencial', ['asistencia']);
        Paquete::where('slug', 'asistencia')->update(['publicado' => false]); // el dueño aún no lo publica
        $this->assertTrue($this->asistenciaActiva($corpCon));
        $this->assertFalse($this->asistenciaActiva($corpSin));
        $antes = $this->foto();

        $this->migracion()->up();

        // Planes y paquetes
        $this->assertContains('asistencia', $this->productosDe('corporativo'));
        $this->assertSame(['esencial', 'profesional'], $this->planesDe('asistencia'));
        $this->assertSame(['esencial', 'profesional', 'corporativo'], $this->planesDe('reservas-y-eventos'));
        $this->assertSame(['esencial', 'profesional'], $this->planesDe('recursos-humanos'));
        // Precios que puso el Super Administrador: intactos
        $this->assertSame('3100.00', Paquete::where('slug', 'kit-lector')->value('precio'));
        $this->assertSame('250.00', Paquete::where('slug', 'asistencia')->value('precio'));
        $this->assertSame('2500.00', Plan::where('slug', 'corporativo')->value('precio_por_sede_mensual'));
        $this->assertSame('890.00', Plan::where('slug', 'esencial')->value('precio_por_sede_mensual'));
        $this->assertFalse((bool) Paquete::where('slug', 'asistencia')->value('publicado'));
        // Lo que creó o escribió: se queda
        $this->assertTrue(Paquete::where('slug', 'soporte-en-sitio')->exists());
        $this->assertSame('Frase escrita por el dueño.', Plan::where('slug', 'corporativo')->value('frase'));
        // Textos de fábrica: actualizados
        $this->assertStringContainsString('125 kHz o MIFARE', Paquete::where('slug', 'kit-lector')->value('descripcion'));
        $this->assertStringContainsString('ZKTeco o Hikvision', Plan::where('slug', 'esencial')->value('descripcion'));
        $this->assertSame('La caseta en orden desde el primer día, con credenciales QR y lector.', Plan::where('slug', 'esencial')->value('frase'));
        $this->assertStringContainsString('Asistencia', Plan::where('slug', 'corporativo')->value('descripcion'));
        $this->assertStringContainsString('credencial de empleado', Producto::where('clave', 'estructura')->value('descripcion'));
        $this->assertStringContainsString('equipos de acceso', Producto::where('clave', 'caseta')->value('descripcion'));
        // Pagos únicos nuevos: sin publicar y con precio de ejemplo
        foreach (['integracion-equipo-acceso' => '1500.00', 'credenciales-cr80' => '1200.00'] as $slug => $precio) {
            $p = Paquete::where('slug', $slug)->firstOrFail();
            $this->assertFalse($p->publicado, "{$slug} nace sin publicar");
            $this->assertSame($precio, $p->precio);
            $this->assertSame(Paquete::UNICO, $p->modalidad);
            $this->assertFalse($p->por_sede);
        }
        $this->assertSame([], app(CoherenciaCatalogo::class)->problemas(false));

        // Clientes: nadie pierde Asistencia y a nadie se le enciende sola
        $this->assertTrue($this->asistenciaActiva($corpCon));
        $this->assertSame(['asistencia'], ModulosEfectivos::encendidosPor((int) $corpCon->empresa_id));
        $this->assertFalse($this->asistenciaActiva($corpSin));
        $this->assertSame([$asistencia], app(ModulosEfectivos::class)->detalle($corpSin->fresh())['por_encender']);
        $this->assertTrue($this->asistenciaActiva($esencial));
        // El Corporativo que la usaba la conserva aunque después quite el complemento
        app(Aprovisionamiento::class)->cambiarPlan($corpCon->fresh(), Plan::where('slug', 'corporativo')->first(), 5, [], 'mensual', $this->sa, 'Quitó el complemento');
        $this->assertTrue($this->asistenciaActiva($corpCon));

        // Idempotente
        $despues = $this->foto();
        $this->migracion()->up();
        $this->assertEquals($despues, $this->foto());

        // Revertir: todo como estaba
        $this->migracion()->down();
        $this->assertFalse(ConfiguracionPlataforma::where('clave', 'catalogo_comercial_coherente')->exists());
        $this->assertEquals($antes, $this->foto());

        // Volver a aplicar: mismo resultado
        $this->migracion()->up();
        $this->assertEquals($despues, $this->foto());
    }
}
