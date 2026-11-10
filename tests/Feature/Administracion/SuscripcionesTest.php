<?php

namespace Tests\Feature\Administracion;

use App\Mail\AvisoSuscripcion;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Promocion;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\SolicitudCompra;
use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\Calculadora;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Suscripciones\Aprovisionamiento;
use App\Services\Suscripciones\ModulosEfectivos;
use App\Services\Suscripciones\Promociones;
use App\Support\CorreoPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Suscripciones: prueba gratis, activación al pagar (idempotente), módulos
 * efectivos con excepciones, límites de sedes y usuarios, empresas sin
 * suscripción (legado) intactas, ciclo prueba → vence → gracia → solo
 * consulta → pago, solo consulta, promociones, pago anual y permisos.
 */
class SuscripcionesTest extends TestCase
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
        // Un día fijo (mediodía en México) para que las fechas de vigencia sean exactas
        $this->travelTo(Carbon::parse('2026-11-02 12:00', Suscripcion::ZONA));
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function correoConfigurado(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.mx', 'puerto' => 587, 'cifrado' => 'tls', 'remitente_correo' => 'avisos@ejemplo.mx'], null);
    }

    /**
     * Solicitud registrada como en la página pública (precio calculado y congelado por el servidor).
     *
     * @param  array<string, mixed>  $extra
     */
    private function solicitud(array $extra = []): SolicitudCompra
    {
        [$s] = app(CatalogoPublico::class)->registrar(array_merge([
            'nombre' => 'Laura Méndez', 'empresa' => 'Hotel Laguna', 'correo' => 'laura@ejemplo.com.mx', 'sedes' => 1,
            'plan_id' => $this->plan('esencial')->id, 'paquetes' => [],
        ], $extra), '127.0.0.1');

        return $s;
    }

    /** Cliente activado al pagar (sin pasar por la pantalla). */
    private function clientePagado(array $extra = []): Suscripcion
    {
        return app(Aprovisionamiento::class)->confirmarPago($this->solicitud($extra), $this->sa, 'TRANSF-1');
    }

    private function admin(Suscripcion $s): User
    {
        return User::where('empresa_id', $s->empresa_id)->orderBy('id')->firstOrFail();
    }

    /** @return list<string> */
    private function modulosActivos(int $empresaId): array
    {
        return DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresaId)->where('em.activo', true)->pluck('m.clave')->sort()->values()->all();
    }

    private function avanzar(string $fecha): void
    {
        $this->travelTo(Carbon::parse($fecha.' 12:00', Suscripcion::ZONA));
        // El reloj se movió: sesión nueva para no chocar con el cierre por inactividad
        $this->flushSession();
    }

    // ------------------------------------------------------------------ Activación

    public function test_la_solicitud_pagada_activa_al_cliente_sola_y_queda_por_revisar(): void
    {
        Mail::fake();
        $this->correoConfigurado();
        $s = $this->solicitud(['sedes' => 2]);

        $this->actingAs($this->sa)->put("/catalogo-comercial/solicitudes/{$s->id}", ['estado' => 'pagada', 'referencia_pago' => 'TRANSF-9'])
            ->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, 'quedó activo') && str_contains($m, 'le enviamos el correo de bienvenida'));

        $suscripcion = Suscripcion::firstOrFail();
        $empresa = Empresa::findOrFail($suscripcion->empresa_id);
        $this->assertSame('Hotel Laguna', $empresa->nombre_comercial);
        $this->assertSame(Suscripcion::ACTIVA, $suscripcion->estado);
        $this->assertSame(2, $suscripcion->sedes_contratadas);
        $this->assertSame('2026-11-02', $suscripcion->inicio_en->toDateString());
        $this->assertSame('2026-12-01', $suscripcion->vence_en->toDateString());
        $this->assertSame('2026-12-16', $suscripcion->gracia_hasta->toDateString()); // 15 días de gracia
        $this->assertNull($suscripcion->revisado_en);
        $this->assertSame((string) $s->fresh()->periodo_total, (string) $suscripcion->precio_total); // precio congelado de la solicitud
        $this->assertSame(5, $suscripcion->detalle['usuarios_por_sede']);

        // Sede principal, Administrador con enlace de bienvenida y roles de plantilla
        $this->assertSame(['Sede principal'], Sede::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('nombre')->all());
        $admin = $this->admin($suscripcion);
        $this->assertSame('laura@ejemplo.com.mx', $admin->email);
        $this->assertSame('laura', $admin->username);
        $this->assertTrue((bool) $admin->debe_cambiar_contrasena);
        $this->assertTrue($admin->can('empresas.editar'));
        $this->assertGreaterThan(3, Rol::where('empresa_id', $empresa->id)->count());
        $this->assertTrue($suscripcion->historial()->where('evento', 'activada')->first()->datos['bienvenida_enviada']);

        // Módulos del plan Esencial (más Dirección), sin los de otros productos
        $activos = $this->modulosActivos($empresa->id);
        $this->assertContains('accesos', $activos);
        $this->assertContains('vehiculos', $activos);
        $this->assertContains('empresas', $activos);
        $this->assertNotContains('novedades', $activos);
        $this->assertNotContains('candidatos', $activos);

        // La solicitud queda pagada y ligada; el Super Administrador recibe «Cliente nuevo por revisar»
        $s->refresh();
        $this->assertSame('pagada', $s->estado);
        $this->assertSame($empresa->id, $s->empresa_id);
        $this->assertNotNull($s->activada_en);
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'cliente_por_revisar')->where('user_id', $this->sa->id)->count());
        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=revisar')->assertOk()->assertSee('Hotel Laguna')->assertSee('Sin revisar');

        // Revisar: sale de la lista y el aviso se resuelve
        $this->actingAs($this->sa)->put("/catalogo-comercial/clientes/{$suscripcion->id}/revision", ['notas' => 'Datos correctos.'])
            ->assertSessionHas('ok', 'Cliente guardado como revisado.');
        $this->assertNotNull($suscripcion->fresh()->revisado_en);
        $this->assertSame('Datos correctos.', $suscripcion->fresh()->notas);
        $this->assertNotNull(Notificacion::withoutGlobalScopes()->where('tipo', 'cliente_por_revisar')->value('leida_en'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'suscripciones.activada']);
        $this->assertDatabaseHas('auditoria', ['evento' => 'suscripciones.revisada']);
    }

    public function test_activar_es_idempotente(): void
    {
        $promo = Promocion::create(['nombre' => 'Bienvenida', 'codigo' => 'HOLA10', 'tipo' => 'porcentaje', 'valor' => 10]);
        $s = $this->solicitud(['codigo_promocion' => 'hola10']);
        $servicio = app(Aprovisionamiento::class);

        $a = $servicio->confirmarPago($s, $this->sa);
        $b = $servicio->confirmarPago($s->fresh(), $this->sa);   // doble clic
        $c = $servicio->confirmarPago($s->fresh(), null);        // la pasarela reintenta
        $this->actingAs($this->sa)->put("/catalogo-comercial/solicitudes/{$s->id}", ['estado' => 'pagada'])->assertRedirect();

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $c->id);
        $this->assertSame(1, Suscripcion::count());
        $this->assertSame(1, Empresa::where('nombre_comercial', 'Hotel Laguna')->count());
        $this->assertSame(1, User::where('email', 'laura@ejemplo.com.mx')->count());
        $this->assertSame(1, $promo->fresh()->usos);
        $this->assertSame(1, $a->historial()->where('evento', 'activada')->count());
        // El vencimiento no se movió con los reintentos
        $this->assertSame('2026-12-01', $a->fresh()->vence_en->toDateString());
    }

    public function test_la_prueba_gratis_no_se_activa_sola_y_el_superadmin_la_aprueba(): void
    {
        Mail::fake();
        app(AjustesComerciales::class)->guardar(['landing_publicada' => true]);
        $this->get('/contratar?prueba=1&plan=profesional')->assertOk()->assertSee('Probar gratis 14 días')->assertSee('Solicitar mi prueba gratis');
        $this->post('/contratar', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'),
            'tipo' => 'prueba', 'nombre' => 'Mario Ruiz', 'empresa' => 'Plaza Norte', 'correo' => 'mario@ejemplo.com.mx', 'sedes' => 1,
            'plan_id' => $this->plan('profesional')->id, 'periodicidad' => 'mensual', 'acepta_privacidad' => '1',
        ])->assertRedirect('/contratar/gracias');
        $this->get('/contratar/gracias')->assertSee('Recibimos tu solicitud de prueba gratis');

        $s = SolicitudCompra::firstOrFail();
        $this->assertSame('prueba', $s->tipo);
        $this->assertSame(0, Suscripcion::count());
        $this->assertNull(Empresa::where('nombre_comercial', 'Plaza Norte')->first());
        $this->assertStringStartsWith('Prueba gratis por aprobar', Notificacion::withoutGlobalScopes()->where('tipo', 'solicitud_compra')->value('titulo'));
        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=solicitudes&estado=pruebas')->assertOk()->assertSee($s->folio);
        $this->actingAs($this->sa)->get("/catalogo-comercial/solicitudes/{$s->id}")->assertOk()->assertSee('Aprobar prueba gratis');

        $this->actingAs($this->sa)->post("/catalogo-comercial/solicitudes/{$s->id}/aprobar-prueba")->assertRedirect()
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Prueba aprobada'));
        $suscripcion = Suscripcion::firstOrFail();
        $this->assertSame(Suscripcion::PRUEBA, $suscripcion->estado);
        $this->assertTrue($suscripcion->es_prueba);
        $this->assertSame('2026-11-15', $suscripcion->vence_en->toDateString()); // 14 días: del 2 al 15
        $this->assertNotNull($suscripcion->revisado_en); // la aprobó el Super Administrador
        $this->assertSame('en_prueba', $s->fresh()->estado);
        $this->assertContains('novedades', $this->modulosActivos($suscripcion->empresa_id)); // Profesional

        // Aprobarla otra vez no duplica nada
        $this->actingAs($this->sa)->post("/catalogo-comercial/solicitudes/{$s->id}/aprobar-prueba")->assertRedirect();
        $this->assertSame(1, Suscripcion::count());

        // Con la prueba apagada en Ajustes, el formulario no la acepta
        app(AjustesComerciales::class)->guardar(['prueba_habilitada' => false]);
        // (el controlador quedó guardado en la ruta con los ajustes de antes: en producción cada petición es nueva)
        app('router')->getRoutes()->getByName('planes.guardar')->flushController();
        $this->post('/contratar', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'tipo' => 'prueba', 'nombre' => 'Otra Persona', 'correo' => 'otra@ejemplo.com.mx', 'sedes' => 1,
            'plan_id' => $this->plan('profesional')->id, 'acepta_privacidad' => '1'])->assertSessionHasErrors('tipo');
    }

    // ------------------------------------------------------------------ Módulos y excepciones

    public function test_modulos_efectivos_con_paquetes_y_excepciones_sin_borrar_datos(): void
    {
        $rh = Paquete::where('slug', 'recursos-humanos')->firstOrFail();
        $s = $this->clientePagado(['paquetes' => [$rh->id]]);
        $empresaId = (int) $s->empresa_id;
        $this->assertContains('candidatos', $this->modulosActivos($empresaId)); // por el paquete
        $this->assertNotContains('novedades', $this->modulosActivos($empresaId));

        $novedades = Modulo::where('clave', 'novedades')->firstOrFail();
        $vehiculos = Modulo::where('clave', 'vehiculos')->firstOrFail();
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/excepciones", ['tipo' => 'modulo_agregado', 'modulo_id' => $novedades->id, 'motivo' => 'Cortesía del primer año'])
            ->assertSessionHas('ok');
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/excepciones", ['tipo' => 'modulo_quitado', 'modulo_id' => $vehiculos->id, 'motivo' => 'No lo usan'])
            ->assertSessionHas('ok');
        $activos = $this->modulosActivos($empresaId);
        $this->assertContains('novedades', $activos);
        $this->assertNotContains('vehiculos', $activos);
        // Lo quitado solo se oculta: la fila sigue en empresa_modulos
        $this->assertDatabaseHas('empresa_modulos', ['empresa_id' => $empresaId, 'modulo_id' => $vehiculos->id, 'activo' => false]);
        // Dirección no se puede quitar; el motivo es obligatorio
        $empresas = Modulo::where('clave', 'empresas')->firstOrFail();
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/excepciones", ['tipo' => 'modulo_quitado', 'modulo_id' => $empresas->id, 'motivo' => 'x'])
            ->assertSessionHas('error');
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/excepciones", ['tipo' => 'usuarios', 'cantidad' => 3])->assertSessionHasErrors('motivo');

        // Cambiar a Profesional sin el paquete: novedades ya viene en el plan; candidatos se apaga
        $this->actingAs($this->sa)->put("/catalogo-comercial/clientes/{$s->id}/plan", ['plan_id' => $this->plan('profesional')->id, 'sedes' => 2, 'periodicidad' => 'mensual', 'motivo' => 'Amplió'])
            ->assertSessionHas('ok');
        $s->refresh();
        $activos = $this->modulosActivos($empresaId);
        $this->assertContains('dashboard', $activos);
        $this->assertNotContains('candidatos', $activos);
        $this->assertNotContains('vehiculos', $activos); // la excepción sigue
        $this->assertSame(2, $s->sedes_contratadas);
        $this->assertSame('3456.80', (string) $s->precio_total); // 1,490 × 2 + IVA
        $this->assertSame('2026-12-01', $s->vence_en->toDateString()); // las fechas no cambian

        // Dar de baja la excepción de vehículos: regresa
        $e = SuscripcionExcepcion::where('modulo_id', $vehiculos->id)->firstOrFail();
        $this->actingAs($this->sa)->patch("/catalogo-comercial/clientes/{$s->id}/excepciones/{$e->id}/baja")->assertSessionHas('ok');
        $this->assertContains('vehiculos', $this->modulosActivos($empresaId));
        $this->assertFalse($e->fresh()->activo);
        $this->assertSame($this->sa->id, $e->fresh()->dado_de_baja_por);
        $this->assertDatabaseHas('auditoria', ['evento' => 'suscripciones.excepcion_agregada']);
        $this->assertDatabaseHas('auditoria', ['evento' => 'suscripciones.plan_cambiado']);
        // El cálculo del servicio coincide con lo escrito
        $this->assertEqualsCanonicalizing(app(ModulosEfectivos::class)->calcular($s->fresh()),
            DB::table('empresa_modulos')->where('empresa_id', $empresaId)->where('activo', true)->pluck('modulo_id')->map(fn ($id) => (int) $id)->all());
    }

    // ------------------------------------------------------------------ Límites

    public function test_limites_de_sedes_y_usuarios_con_excepciones(): void
    {
        $s = $this->clientePagado(); // Esencial, 1 sede, 5 usuarios por sede
        $admin = $this->admin($s);
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();
        $sede = ['nombre' => 'Hotel Laguna Playa', 'codigo' => 'PLA', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'];

        $this->actingAs($admin)->post('/sedes', $sede)->assertSessionHasErrors(['limite' => 'Tu plan incluye 1 sede y ya tienes 1 activa. Para agregar otra, amplía tu plan en Empresas → Tu suscripción, o da de baja una sede que ya no uses.']);
        $this->assertSame(1, Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->count());

        // Excepción: una sede de más
        app(Aprovisionamiento::class)->agregarExcepcion($s, $this->sa, 'sedes', 1, null, 'Sede de prueba acordada');
        $this->actingAs($admin)->post('/sedes', $sede)->assertSessionHas('ok');
        $this->assertSame(2, Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->count());
        // Reactivar una sede dada de baja también cuenta
        $playa = Sede::withoutGlobalScopes()->where('codigo', 'PLA')->firstOrFail();
        $this->actingAs($admin)->patch("/sedes/{$playa->id}/estado", ['activo' => 0])->assertSessionHas('aviso');
        $this->actingAs($admin)->post('/sedes', ['codigo' => 'NOR', 'nombre' => 'Norte'] + $sede)->assertSessionHas('ok');
        $this->actingAs($admin)->patch("/sedes/{$playa->id}/estado", ['activo' => 1])->assertSessionHasErrors('limite');

        // Usuarios: 5 incluidos (ya hay 1, el Administrador)
        $rol = Rol::where('empresa_id', $s->empresa_id)->where('nombre', 'Agente')->firstOrFail();
        $sedeId = Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->where('codigo', 'S1')->value('id');
        $nombres = [1 => 'Mario López', 2 => 'Ana Torres', 3 => 'Luis Pérez', 4 => 'Sara Díaz'];
        foreach (range(1, 4) as $i) {
            $this->actingAs($admin)->post('/usuarios', ['name' => $nombres[$i], 'username' => "agente{$i}.laguna", 'email' => "agente{$i}@laguna.mx",
                'contrasena_modo' => 'manual', 'password' => 'Caseta2026x', 'rol_id' => $rol->id, 'sede_id' => $sedeId])->assertSessionHas('ok');
        }
        $this->actingAs($admin)->post('/usuarios', ['name' => 'Iván Cruz', 'username' => 'agente5.laguna', 'email' => 'agente5@laguna.mx',
            'contrasena_modo' => 'manual', 'password' => 'Caseta2026x', 'rol_id' => $rol->id, 'sede_id' => $sedeId])
            ->assertSessionHasErrors(['limite' => 'Tu plan incluye 5 usuarios activos y ya tienes 5. Da de baja a quien ya no use la plataforma o amplía tu plan en Empresas → Tu suscripción.']);
        $this->assertSame(5, User::where('empresa_id', $s->empresa_id)->count());

        // Dar de baja libera el lugar; reactivar sin lugar no se puede
        $uno = User::where('username', 'agente1.laguna')->firstOrFail();
        $this->actingAs($admin)->patch("/usuarios/{$uno->id}/estado", ['activo' => 0]);
        $this->actingAs($admin)->post('/usuarios', ['name' => 'Iván Cruz', 'username' => 'agente5.laguna', 'email' => 'agente5@laguna.mx',
            'contrasena_modo' => 'manual', 'password' => 'Caseta2026x', 'rol_id' => $rol->id, 'sede_id' => $sedeId])->assertSessionHas('ok');
        $this->actingAs($admin)->patch("/usuarios/{$uno->id}/estado", ['activo' => 1])->assertSessionHasErrors('limite');
        // Excepción de usuarios
        app(Aprovisionamiento::class)->agregarExcepcion($s, $this->sa, 'usuarios', 2, null, 'Dos guardias extra');
        $this->actingAs($admin)->patch("/usuarios/{$uno->id}/estado", ['activo' => 1])->assertSessionHas('ok');

        // «Tu suscripción» muestra el uso
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertSee('Tu suscripción')->assertSee('2 de 2')->assertSee('6 de 7');
    }

    public function test_una_empresa_sin_suscripcion_no_tiene_limites_ni_vencimiento(): void
    {
        $empresa = $this->crearEmpresa('Hotel Demo');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $this->clientePagado(); // otro cliente con suscripción no afecta

        foreach (['A', 'B', 'C', 'D'] as $codigo) {
            $this->actingAs($admin)->post('/sedes', ['nombre' => "Sede {$codigo}", 'codigo' => $codigo, 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'])->assertSessionHas('ok');
        }
        $this->assertSame(4, Sede::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
        $this->avanzar('2028-01-01');
        $this->artisan('plataforma:suscripciones')->assertSuccessful();
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertDontSee('Tu suscripción')->assertDontSee('solo consulta');
        $this->actingAs($admin)->post('/sedes', ['nombre' => 'Sede E', 'codigo' => 'E', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'])->assertSessionHas('ok');
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('tipo', 'suscripcion_aviso')->count());
        // El Super Administrador lo ve como «Sin suscripción»
        $this->actingAs($this->sa)->get('/empresas')->assertOk()->assertSee('Sin suscripción: sin límites ni vencimiento');
    }

    // ------------------------------------------------------------------ Ciclo de vida

    public function test_ciclo_prueba_vence_gracia_solo_consulta_pago_y_activa(): void
    {
        Mail::fake();
        $this->correoConfigurado();
        $s = app(Aprovisionamiento::class)->activarPrueba($this->solicitud(['tipo' => 'prueba']), $this->sa); // vence el 15/11
        $admin = $this->admin($s);
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();
        $sedeDatos = fn (string $c) => ['nombre' => "Sede {$c}", 'codigo' => $c, 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'];
        $avisos = fn () => Notificacion::withoutGlobalScopes()->where('tipo', 'suscripcion_aviso')->where('user_id', $admin->id)->orderBy('id')->pluck('titulo')->all();

        // 7 días antes: aviso al Administrador y al Super Administrador (una sola vez)
        $this->avanzar('2026-11-08');
        $this->artisan('plataforma:suscripciones')->assertSuccessful();
        $this->artisan('plataforma:suscripciones')->assertSuccessful();
        $this->assertSame(['Tu prueba gratis vence en 7 días'], $avisos());
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'suscripcion_aviso')->where('user_id', $this->sa->id)->count());
        Mail::assertSent(AvisoSuscripcion::class, fn ($m) => $m->hasTo($admin->email));
        $this->assertSame(Suscripcion::PRUEBA, $s->fresh()->estado);

        // 1 día antes
        $this->avanzar('2026-11-14');
        $this->artisan('plataforma:suscripciones');
        $this->assertSame('Tu prueba gratis vence mañana', last($avisos()));

        // Vence: empieza la gracia (sigue operando, con franja ámbar)
        $this->avanzar('2026-11-16');
        $this->artisan('plataforma:suscripciones');
        $this->assertSame(Suscripcion::EN_GRACIA, $s->fresh()->estado);
        $this->assertSame('Terminó tu prueba gratis', last($avisos()));
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertSee('Tienes hasta el 30/11/2026');
        $this->actingAs($admin)->post('/sedes', $sedeDatos('X'))->assertSessionHasErrors('limite'); // aún opera (el límite es lo que lo detiene)

        // Mitad de la gracia
        $this->avanzar('2026-11-24');
        $this->artisan('plataforma:suscripciones');
        $this->assertSame('Tu prueba gratis venció: quedan días de gracia', last($avisos()));

        // Fin de la gracia: solo consulta
        $this->avanzar('2026-12-01');
        $this->artisan('plataforma:suscripciones');
        $this->assertSame(Suscripcion::SOLO_CONSULTA, $s->fresh()->estado);
        $this->assertSame('Tu prueba gratis pasó a solo consulta', last($avisos()));
        $this->actingAs($admin)->get('/sedes')->assertOk()->assertSee('tienes acceso de solo consulta');
        $sede = Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->firstOrFail();
        $this->actingAs($admin)->put("/sedes/{$sede->id}", ['nombre' => 'Otro nombre', 'codigo' => 'S1', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'])
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'Tu prueba gratis terminó el 15/11/2026; tienes acceso de solo consulta'));
        $this->assertSame('Sede principal', $sede->fresh()->nombre);
        $this->assertSame(5, count($avisos()));

        // Pago: se activa y vuelve a registrar
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/pago", ['referencia' => 'DEP-77'])->assertSessionHas('ok', 'Pago recibido: la suscripción vence el 31/12/2026. Le avisamos al cliente.');
        $s->refresh();
        $this->assertSame(Suscripcion::ACTIVA, $s->estado);
        $this->assertFalse($s->es_prueba);
        $this->assertSame('2026-12-31', $s->vence_en->toDateString());
        $this->flushSession();
        $this->actingAs($admin)->put("/sedes/{$sede->id}", ['nombre' => 'Otro nombre', 'codigo' => 'S1', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'])->assertSessionHas('ok');
        $this->assertSame('Recibimos tu pago', last($avisos()));
        $this->assertTrue($s->historial()->where('evento', 'reactivada')->exists());

        // Renovar antes de vencer suma un periodo desde el vencimiento (no desde hoy)
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/pago");
        $this->assertSame('2027-01-31', $s->fresh()->vence_en->toDateString());
    }

    public function test_la_tarea_diaria_con_si_toca_corre_una_vez_al_dia_despues_de_las_ocho(): void
    {
        $s = $this->clientePagado();
        $this->travelTo(Carbon::parse('2026-11-24 07:30', Suscripcion::ZONA)); // 7 días antes del 1/12, de madrugada
        $this->artisan('plataforma:suscripciones --si-toca')->assertSuccessful();
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('tipo', 'suscripcion_aviso')->count());
        $this->travelTo(Carbon::parse('2026-11-24 08:10', Suscripcion::ZONA));
        $this->artisan('plataforma:suscripciones --si-toca')->assertSuccessful();
        $this->assertSame(Suscripcion::POR_VENCER, $s->fresh()->estado);
        $total = Notificacion::withoutGlobalScopes()->where('tipo', 'suscripcion_aviso')->count();
        $this->assertGreaterThan(0, $total);
        // Ya corrió hoy: no repite
        $s->forceFill(['avisos_enviados' => []])->save();
        $this->artisan('plataforma:suscripciones --si-toca')->assertSuccessful();
        $this->assertSame($total, Notificacion::withoutGlobalScopes()->where('tipo', 'suscripcion_aviso')->count());
    }

    public function test_solo_consulta_bloquea_escritura_y_permite_ver_exportar_contrasena_y_renovar(): void
    {
        $s = $this->clientePagado();
        $admin = $this->admin($s);
        $admin->forceFill(['debe_cambiar_contrasena' => false, 'password' => 'Contrasena2026'])->save();
        app(Aprovisionamiento::class)->suspender($s, $this->sa, 'Acuerdo de pago incumplido');

        $this->actingAs($admin)->get('/sedes')->assertOk()->assertSee('Tu suscripción está suspendida; tienes acceso de solo consulta');
        $this->actingAs($admin)->get('/auditoria/exportar')->assertOk(); // exportar es lectura
        $this->actingAs($admin)->post('/sedes', ['nombre' => 'Nueva', 'codigo' => 'N1', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($admin)->postJson('/sedes', ['nombre' => 'Nueva'])->assertStatus(423)->assertJsonPath('ok', false);
        $this->assertSame(1, Sede::withoutGlobalScopes()->where('empresa_id', $s->empresa_id)->count());

        // Su contraseña, sus avisos y pedir la renovación sí
        $this->actingAs($admin)->put('/mi-cuenta/contrasena', ['contrasena_actual' => 'Contrasena2026', 'password' => 'NuevaClave2027x', 'password_confirmation' => 'NuevaClave2027x'])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->actingAs($admin)->post('/mi-suscripcion/solicitar', ['tipo' => 'renovacion', 'sedes' => 1, 'periodicidad' => 'mensual'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'guardada'));
        $renovacion = SolicitudCompra::where('tipo', 'renovacion')->firstOrFail();
        $this->assertSame($s->empresa_id, $renovacion->empresa_id);

        // El Super Administrador no queda bloqueado trabajando en esa empresa
        $this->actingAs($this->sa)->post('/empresa-activa', ['empresa_id' => $s->empresa_id]);
        $this->actingAs($this->sa)->post('/sedes', ['nombre' => 'Desde plataforma', 'codigo' => 'SA1', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'])
            ->assertSessionMissing('error');

        // Las tareas automáticas siguen corriendo
        $this->artisan('plataforma:suscripciones')->assertSuccessful();

        // Pagar la renovación la reactiva (mismo camino que la pasarela)
        $this->actingAs($this->sa)->put("/catalogo-comercial/solicitudes/{$renovacion->id}", ['estado' => 'pagada'])
            ->assertRedirect(route('catalogo-comercial.clientes.show', $s->id));
        $this->assertFalse($s->fresh()->suspendida);
        $this->assertFalse($s->fresh()->soloConsulta());
        $this->flushSession();
        $this->actingAs($admin)->get('/sedes')->assertOk()->assertDontSee('solo consulta');
    }

    public function test_dar_de_baja_y_reactivar_desde_la_ficha(): void
    {
        $s = $this->clientePagado();
        $this->actingAs($this->sa)->patch("/catalogo-comercial/clientes/{$s->id}/estado", ['activo' => 0])->assertSessionHasErrors('motivo');
        $this->actingAs($this->sa)->patch("/catalogo-comercial/clientes/{$s->id}/estado", ['activo' => 0, 'motivo' => 'Sin pago'])->assertSessionHas('ok');
        $this->assertTrue($s->fresh()->soloConsulta());
        $this->actingAs($this->sa)->get("/catalogo-comercial/clientes/{$s->id}")->assertOk()->assertSee('Reactivar')->assertSee('Sin pago');
        $this->actingAs($this->sa)->patch("/catalogo-comercial/clientes/{$s->id}/estado", ['activo' => 1])->assertSessionHas('ok');
        $this->assertSame(Suscripcion::ACTIVA, $s->fresh()->estado);

        // Si su vigencia ya terminó, reactivar pide el pago
        $this->actingAs($this->sa)->patch("/catalogo-comercial/clientes/{$s->id}/estado", ['activo' => 0, 'motivo' => 'Sin pago']);
        $this->avanzar('2027-02-01');
        $this->actingAs($this->sa)->patch("/catalogo-comercial/clientes/{$s->id}/estado", ['activo' => 1])
            ->assertSessionHas('error', 'Su vigencia terminó el 01/12/2026: registra el pago para reactivarla.');
    }

    // ------------------------------------------------------------------ Promociones y pago anual

    public function test_promociones_vigencia_usos_porcentaje_monto_y_primeros_meses(): void
    {
        $esencial = $this->plan('esencial');
        $profesional = $this->plan('profesional');
        $calc = app(Calculadora::class);

        // Porcentaje los primeros 3 meses, mensual: 890 → descuento 133.50
        $p15 = Promocion::create(['nombre' => 'Bienvenida', 'codigo' => 'BIEN15', 'tipo' => 'porcentaje', 'valor' => 15, 'meses' => 3]);
        $r = $calc->calcular($esencial, 1, collect(), 'mensual', $p15->descriptor());
        $this->assertSame(13350, $r['descuento_promocion']);
        $this->assertSame(['subtotal' => 75650, 'iva' => 12104, 'total' => 87754], $r['periodo']);
        $this->assertSame(['subtotal' => 89000, 'iva' => 14240, 'total' => 103240], $r['periodo_regular']);
        // Anual: 10 % por pago anual y la promoción solo 3 de los 12 meses
        $r = $calc->calcular($esencial, 1, collect(), 'anual', $p15->descriptor());
        $this->assertSame(106800, $r['descuento_anual']);              // 89 × 12
        $this->assertSame(3 * 12015, $r['descuento_promocion']);        // 15 % de 801
        $this->assertSame(801 * 1200 - 3 * 12015, $r['periodo']['subtotal']);
        // Monto fijo nunca deja el mes en negativo
        $monto = Promocion::create(['nombre' => 'Mil pesos', 'tipo' => 'monto', 'valor' => 1000]);
        $r = $calc->calcular($esencial, 1, collect(), 'mensual', $monto->descriptor());
        $this->assertSame(0, $r['periodo']['subtotal']);

        // Validaciones por código
        $promos = app(Promociones::class);
        $this->assertNull($promos->porCodigo(' bien15 ', $esencial, 'mensual')[1]);
        $this->assertStringContainsString('no existe', $promos->porCodigo('NADA', $esencial, 'mensual')[1]);
        Promocion::create(['nombre' => 'Vieja', 'codigo' => 'VIEJA', 'tipo' => 'porcentaje', 'valor' => 5, 'vigente_hasta' => '2026-10-31']);
        $this->assertSame('Esa promoción ya venció.', $promos->porCodigo('vieja', $esencial, 'mensual')[1]);
        Promocion::create(['nombre' => 'Futura', 'codigo' => 'FUTURA', 'tipo' => 'porcentaje', 'valor' => 5, 'vigente_desde' => '2026-12-01']);
        $this->assertStringContainsString('todavía no empieza', $promos->porCodigo('futura', $esencial, 'mensual')[1]);
        $anual = Promocion::create(['nombre' => 'Solo anual', 'codigo' => 'ANUAL5', 'tipo' => 'porcentaje', 'valor' => 5, 'periodicidad' => 'anual']);
        $this->assertSame('Esa promoción solo aplica al pago anual.', $promos->porCodigo('anual5', $esencial, 'mensual')[1]);
        $soloPro = Promocion::create(['nombre' => 'Pro', 'codigo' => 'PRO', 'tipo' => 'porcentaje', 'valor' => 5]);
        $soloPro->planes()->sync([$profesional->id]);
        $this->assertSame('Esa promoción solo aplica a Profesional.', $promos->porCodigo('pro', $esencial, 'mensual')[1]);
        $limitada = Promocion::create(['nombre' => 'Dos usos', 'codigo' => 'DOS', 'tipo' => 'porcentaje', 'valor' => 20, 'usos_maximos' => 2]);

        // Página pública: el código en la dirección se aplica y se congela al enviar
        app(AjustesComerciales::class)->guardar(['landing_publicada' => true]);
        $this->get('/contratar?plan=esencial&sedes=1&codigo=bien15')->assertOk()->assertSee('Código aplicado: Bienvenida')->assertSee('$877.54');
        $this->get('/contratar?plan=esencial&sedes=1&codigo=vieja')->assertOk()->assertSee('Esa promoción ya venció.');
        $this->post('/contratar', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'nombre' => 'Ana Ruiz', 'correo' => 'ana@ejemplo.com.mx', 'sedes' => 1, 'plan_id' => $esencial->id,
            'periodicidad' => 'mensual', 'codigo_promocion' => 'vieja', 'acepta_privacidad' => '1'])->assertSessionHasErrors(['codigo_promocion' => 'Esa promoción ya venció.']);
        $this->post('/contratar', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'nombre' => 'Ana Ruiz', 'correo' => 'ana@ejemplo.com.mx', 'sedes' => 1, 'plan_id' => $esencial->id,
            'periodicidad' => 'mensual', 'codigo_promocion' => 'bien15', 'acepta_privacidad' => '1'])->assertRedirect('/contratar/gracias');
        $sol = SolicitudCompra::where('correo', 'ana@ejemplo.com.mx')->firstOrFail();
        $this->assertSame('BIEN15', $sol->codigo_promocion);
        $this->assertSame('877.54', (string) $sol->periodo_total);
        $this->assertSame('133.50', (string) $sol->descuento);
        $this->assertSame(0, $p15->fresh()->usos); // se cuenta al activar

        // Activar: cuenta el uso y congela el precio; los primeros 3 meses terminan el 01/02/2027
        $s = app(Aprovisionamiento::class)->confirmarPago($sol, $this->sa);
        $this->assertSame(1, $p15->fresh()->usos);
        $this->assertSame('877.54', (string) $s->precio_total);
        $this->assertSame('2027-02-01', $s->promocion_hasta->toDateString());
        // Renovaciones: dentro de la promoción conserva el precio; después, el regular
        $servicio = app(Aprovisionamiento::class);
        $s = $servicio->registrarPago($s, $this->sa);   // dic
        $s = $servicio->registrarPago($s, $this->sa);   // ene
        $this->assertSame('877.54', (string) $s->precio_total);
        $s = $servicio->registrarPago($s, $this->sa);   // feb: ya terminó
        $this->assertSame('1032.40', (string) $s->precio_total);

        // Límite de usos
        $this->clientePagado(['correo' => 'a@uno.mx', 'empresa' => 'Uno', 'codigo_promocion' => 'DOS']);
        $this->clientePagado(['correo' => 'b@dos.mx', 'empresa' => 'Dos', 'codigo_promocion' => 'DOS']);
        $this->assertSame(2, $limitada->fresh()->usos);
        $this->assertSame('Esa promoción ya se agotó.', $promos->porCodigo('dos', $esencial, 'mensual')[1]);

        // Aplicar a mano una promoción sin código desde la ficha
        $manual = Promocion::create(['nombre' => 'Lealtad', 'tipo' => 'monto', 'valor' => 100]);
        $cliente = Suscripcion::whereHas('empresa', fn ($q) => $q->where('nombre_comercial', 'Uno'))->firstOrFail();
        $this->actingAs($this->sa)->put("/catalogo-comercial/clientes/{$cliente->id}/promocion", ['promocion_id' => $manual->id])->assertSessionHas('ok');
        $this->assertSame('916.40', (string) $cliente->fresh()->precio_total); // (890 − 100) + IVA
        $this->assertSame($manual->id, $cliente->fresh()->promocion_id);
        // Una que no aplica a ese plan
        $this->actingAs($this->sa)->put("/catalogo-comercial/clientes/{$cliente->id}/promocion", ['promocion_id' => $soloPro->id])
            ->assertSessionHas('error', 'Esa promoción solo aplica a Profesional.');

        // Administración de promociones
        $this->actingAs($this->sa)->post('/catalogo-comercial/promociones', ['nombre' => 'Buen Fin', 'codigo' => 'buen-fin!', 'tipo' => 'porcentaje', 'valor' => 120, 'periodicidad' => 'ambas'])
            ->assertSessionHasErrors(['codigo', 'valor']);
        $this->actingAs($this->sa)->post('/catalogo-comercial/promociones', ['nombre' => 'Buen Fin', 'codigo' => 'buenfin', 'tipo' => 'porcentaje', 'valor' => 12,
            'periodicidad' => 'ambas', 'meses' => 2, 'usos_maximos' => 50, 'vigente_hasta' => '2026-11-30', 'planes' => [$profesional->id]])->assertSessionHas('ok');
        $buenFin = Promocion::where('codigo', 'BUENFIN')->firstOrFail();
        $this->assertSame([$profesional->id], $buenFin->planes()->pluck('planes.id')->all());
        $this->actingAs($this->sa)->patch("/catalogo-comercial/promociones/{$buenFin->id}/estado", ['activo' => 0])->assertSessionHas('ok');
        $this->assertSame('Esa promoción ya no está disponible.', $promos->porCodigo('buenfin', $profesional, 'mensual')[1]);
        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=promociones')->assertOk()->assertSee('BUENFIN')->assertSee('DE BAJA');
        $this->assertDatabaseHas('auditoria', ['evento' => 'catalogo_comercial.promocion_creada']);
    }

    public function test_pago_anual_en_la_pagina_y_la_solicitud_guarda_la_periodicidad(): void
    {
        app(AjustesComerciales::class)->guardar(['landing_publicada' => true, 'descuento_anual_porcentaje' => 15]);
        $this->get('/')->assertOk()->assertSee('¿Cómo quieres pagar?')->assertSee('Ahorras 15 % pagando el año')
            ->assertSee('al mes pagando el año')->assertSee('Probar gratis 14 días');
        // Profesional 3 sedes anual: 1,490 × 3 = 4,470 al mes; −15 % = 3,799.50; × 12 = 45,594 + IVA
        $this->get('/contratar?plan=profesional&sedes=3&periodicidad=anual')->assertOk()->assertSee('Total')->assertSee('$52,889.04');
        $this->post('/contratar', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'nombre' => 'Ana Ruiz', 'correo' => 'ana@ejemplo.com.mx', 'sedes' => 3, 'plan_id' => $this->plan('profesional')->id,
            'periodicidad' => 'anual', 'acepta_privacidad' => '1'])->assertRedirect('/contratar/gracias');
        $s = SolicitudCompra::firstOrFail();
        $this->assertSame('anual', $s->periodicidad);
        $this->assertSame('45594.00', (string) $s->periodo_subtotal);
        $this->assertSame('52889.04', (string) $s->periodo_total);
        $this->assertSame('5185.20', (string) $s->mensual_total); // la lista mensual se conserva
        $suscripcion = app(Aprovisionamiento::class)->confirmarPago($s, $this->sa);
        $this->assertSame('anual', $suscripcion->periodicidad);
        $this->assertSame('2027-11-01', $suscripcion->vence_en->toDateString());
    }

    public function test_la_calculadora_anual_y_con_promocion_del_navegador_da_lo_mismo_que_el_servidor(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node no está instalado: no se puede ejecutar la calculadora del navegador.');
        }
        app(AjustesComerciales::class)->guardar(['descuento_anual_porcentaje' => 12.5]);
        $this->plan('esencial')->update(['precio_por_sede_mensual' => 899.99]);
        $planes = Plan::publicos()->get();
        $paquetes = Paquete::publicos()->with('planes')->get();
        $calc = app(Calculadora::class);
        $catalogo = $calc->datosParaNavegador($planes, $paquetes);
        $promos = [
            null,
            ['nombre' => 'P', 'tipo' => 'porcentaje', 'valor' => 1500, 'meses' => 3],
            ['nombre' => 'Q', 'tipo' => 'porcentaje', 'valor' => 3333, 'meses' => null],
            ['nombre' => 'M', 'tipo' => 'monto', 'valor' => 25050, 'meses' => 14],
            ['nombre' => 'G', 'tipo' => 'monto', 'valor' => 99999999, 'meses' => 1],
        ];

        $casos = [];
        foreach ($planes->pluck('id')->push(null) as $planId) {
            foreach ([1, 3, 7] as $sedes) {
                foreach (['mensual', 'anual'] as $periodicidad) {
                    foreach ($promos as $promo) {
                        foreach ([[], $paquetes->pluck('id')->all()] as $ids) {
                            $casos[] = ['plan' => $planId, 'sedes' => $sedes, 'paquetes' => $ids, 'periodicidad' => $periodicidad, 'promo' => $promo];
                        }
                    }
                }
            }
        }

        $script = <<<'JS'
            const fs = require('fs'), vm = require('vm');
            const ctx = { self: {} };
            vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), ctx);
            const datos = JSON.parse(fs.readFileSync(0, 'utf8'));
            const C = ctx.self.CalculoPlanes;
            process.stdout.write(JSON.stringify(datos.casos.map(c => C.calcular(datos.catalogo, c.plan, c.sedes, c.paquetes, c.periodicidad, c.promo))));
            JS;
        $proceso = new Process([$node, '-e', $script, public_path('js/landing-calculo.js')]);
        $proceso->setInput(json_encode(['catalogo' => $catalogo, 'casos' => $casos]));
        $proceso->mustRun();
        $navegador = json_decode($proceso->getOutput(), true);

        $this->assertCount(count($casos), $navegador);
        foreach ($casos as $i => $caso) {
            $plan = $caso['plan'] ? $planes->firstWhere('id', $caso['plan']) : null;
            $servidor = $calc->calcular($plan, $caso['sedes'], $paquetes->whereIn('id', $caso['paquetes'])->values(), $caso['periodicidad'], $caso['promo']);
            $js = $navegador[$i];
            foreach (['periodo', 'periodo_regular', 'mensual', 'unico'] as $clave) {
                $this->assertSame($servidor[$clave], $js[$clave], "{$clave}, caso {$i}");
            }
            foreach (['descuento_anual', 'descuento_promocion', 'meses', 'periodicidad'] as $clave) {
                $this->assertSame($servidor[$clave], $js[$clave], "{$clave}, caso {$i}");
            }
        }
    }

    // ------------------------------------------------------------------ Permisos y pantallas

    public function test_solo_el_superadmin_administra_suscripciones_y_el_cliente_ve_la_suya(): void
    {
        $s = $this->clientePagado();
        $admin = $this->admin($s);
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();
        $otra = $this->crearEmpresa('Hotel Ajeno');
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        $promo = Promocion::create(['nombre' => 'X', 'codigo' => 'X1', 'tipo' => 'porcentaje', 'valor' => 5]);

        foreach ([$admin, $ajeno] as $u) {
            $this->actingAs($u)->get("/catalogo-comercial/clientes/{$s->id}")->assertForbidden();
            $this->actingAs($u)->get('/catalogo-comercial/promociones/nueva')->assertForbidden();
            $this->actingAs($u)->post("/catalogo-comercial/clientes/{$s->id}/pago")->assertForbidden();
            $this->actingAs($u)->put("/catalogo-comercial/clientes/{$s->id}/plan", ['sedes' => 99, 'periodicidad' => 'mensual'])->assertForbidden();
            $this->actingAs($u)->post("/catalogo-comercial/clientes/{$s->id}/excepciones", ['tipo' => 'sedes', 'cantidad' => 9, 'motivo' => 'x'])->assertForbidden();
            $this->actingAs($u)->patch("/catalogo-comercial/promociones/{$promo->id}/estado", ['activo' => 0])->assertForbidden();
            $this->actingAs($u)->post('/catalogo-comercial/solicitudes/'.$s->solicitud_compra_id.'/aprobar-prueba')->assertForbidden();
        }
        $this->assertSame(1, $s->fresh()->sedes_contratadas);
        $this->assertTrue($promo->fresh()->activo);

        // El Administrador del cliente ve «Tu suscripción»; la empresa sin suscripción no puede pedir cambios
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertSee('Tu suscripción')->assertSee('Ampliar o renovar mi plan')->assertSee('01/12/2026');
        $this->actingAs($ajeno)->post('/mi-suscripcion/solicitar', ['tipo' => 'renovacion', 'sedes' => 1, 'periodicidad' => 'mensual'])->assertNotFound();
        // Un agente del cliente no pide cambios
        $agente = $this->crearUsuario(Empresa::find($s->empresa_id), 'Agente');
        $this->actingAs($agente)->post('/mi-suscripcion/solicitar', ['tipo' => 'renovacion', 'sedes' => 1, 'periodicidad' => 'mensual'])->assertForbidden();

        // El Super Administrador ve la ficha completa y la lista
        $this->actingAs($this->sa)->get("/catalogo-comercial/clientes/{$s->id}")->assertOk()
            ->assertSee('Hotel Laguna')->assertSee('Módulos que tiene')->assertSee('Historial')->assertSee('Recibir pago')->assertSee('Guardar como revisado');
        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=clientes&cliente_estado=activa&q=laguna')->assertOk()->assertSee('Hotel Laguna');
        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=clientes&cliente_estado=solo_consulta')->assertOk()
            ->assertSee('Todavía no hay clientes con suscripción en esta vista');
    }

    public function test_ampliacion_pagada_cambia_el_plan_sin_mover_el_vencimiento(): void
    {
        $s = $this->clientePagado();
        $admin = $this->admin($s);
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();
        $this->actingAs($admin)->post('/mi-suscripcion/solicitar', ['tipo' => 'ampliacion', 'plan_id' => $this->plan('profesional')->id, 'sedes' => 2, 'periodicidad' => 'mensual'])
            ->assertSessionHas('ok');
        $sol = SolicitudCompra::where('tipo', 'ampliacion')->firstOrFail();
        $this->assertSame('3456.80', (string) $sol->periodo_total);
        $this->assertStringStartsWith('Ampliar o cambiar de plan: ', Notificacion::withoutGlobalScopes()->where('tipo', 'solicitud_compra')->latest('id')->value('titulo'));

        app(Aprovisionamiento::class)->confirmarPago($sol, $this->sa, 'TR-2');
        $s->refresh();
        $this->assertSame($this->plan('profesional')->id, $s->plan_id);
        $this->assertSame(2, $s->sedes_contratadas);
        $this->assertSame('3456.80', (string) $s->precio_total);
        $this->assertSame('2026-12-01', $s->vence_en->toDateString());
        $this->assertContains('novedades', $this->modulosActivos((int) $s->empresa_id));
    }

    public function test_los_datos_de_ejemplo_crean_clientes_en_distintos_estados_sin_tocar_hotel_demo(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful(); // repetible

        $this->assertSame(3, Suscripcion::count());
        $estado = fn (string $empresa) => Suscripcion::whereHas('empresa', fn ($q) => $q->where('nombre_comercial', $empresa))->firstOrFail()->estadoEfectivo();
        $this->assertSame(Suscripcion::ACTIVA, $estado('Hotel Laguna'));
        $this->assertSame(Suscripcion::EN_GRACIA, $estado('Plaza Norte'));
        $this->assertSame(Suscripcion::SOLO_CONSULTA, $estado('Torre Centro'));
        $this->assertNull(Suscripcion::whereHas('empresa', fn ($q) => $q->where('nombre_comercial', 'Hotel Demo'))->first());
        $this->assertTrue(SolicitudCompra::where('folio', 'SC-DEMO-0006')->where('tipo', 'prueba')->whereNull('activada_en')->exists());
        $this->assertTrue(Promocion::where('codigo', 'BIENVENIDA15')->exists());
        // Las cuentas de ejemplo entran con la contraseña del demo
        $this->post('/login', ['username' => 'centro.demo', 'password' => 'Demo1234!']);
        $this->assertAuthenticatedAs(User::where('username', 'centro.demo')->firstOrFail());
        $this->get('/empresas')->assertOk()->assertSee('tienes acceso de solo consulta');
    }

    public function test_la_migracion_se_revierte_y_se_vuelve_a_aplicar(): void
    {
        $this->clientePagado(['codigo_promocion' => null]);
        $migracion = require database_path('migrations/2026_10_31_000300_crear_suscripciones.php');
        $migracion->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('suscripciones'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('solicitudes_compra', 'periodicidad'));
        $this->assertSame(1, SolicitudCompra::count()); // las solicitudes se conservan
        $this->assertSame(1, Empresa::where('nombre_comercial', 'Hotel Laguna')->count()); // la empresa creada se queda

        $migracion->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('suscripcion_excepciones'));
        $this->assertSame('mensual', DB::table('solicitudes_compra')->value('periodicidad'));
    }
}
