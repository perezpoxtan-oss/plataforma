<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoGeneral;
use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Auditoria;
use App\Models\AvisoPrivacidad;
use App\Models\Candidato;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\SolicitudArco;
use App\Models\User;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Privacidad\AvisosPrivacidad;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\SolicitudesArco;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\CorreoPlataforma;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;
use ZipArchive;

/**
 * Privacidad (docs/tecnico/privacidad.md): conservación de datos con viaje
 * en el tiempo, nada se borra con la tarea apagada, empresas existentes
 * apagadas, solicitudes ARCO de punta a punta (con plazos, recordatorio y
 * canalización) y versiones del aviso de privacidad.
 */
class PrivacidadArcoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00', 'UTC')); // martes
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->sa = $this->crearSuperadmin();
        Storage::fake('local');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function conCorreo(): void
    {
        ConfiguracionPlataforma::updateOrCreate(['clave' => CorreoPlataforma::CLAVE], ['valor' => [
            'host' => '10.0.0.5', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos', 'remitente_correo' => 'avisos@ejemplo.mx', 'remitente_nombre' => 'Avisos',
        ]]);
        Mail::fake();
    }

    /** Registro cerrado de la bitácora con foto de la persona y de su identificación. */
    private function acceso(string $nombre, array $extra = [], ?Empresa $empresa = null): Acceso
    {
        $empresa ??= $this->empresa;
        $sede = $empresa->is($this->empresa) ? $this->centro : Sede::withoutGlobalScopes()->where('empresa_id', $empresa->id)->first();

        return $this->enEmpresa(function () use ($nombre, $extra, $empresa, $sede) {
            $foto = "accesos/{$empresa->id}/".uniqid().'.jpg';
            $ine = "accesos/{$empresa->id}/".uniqid().'.jpg';
            Storage::disk('local')->put($foto, 'foto');
            Storage::disk('local')->put($ine, 'ine');
            $a = new Acceso(['sede_id' => $sede->id, 'tipo' => 'visitante', 'nombre' => $nombre, 'identificacion' => 'ine', 'placas' => 'ABC123', 'observaciones' => 'Trae laptop']);
            $a->forceFill($extra + ['estado' => 'finalizado', 'entrada_at' => now()->subHour(), 'salida_at' => now(), 'foto_persona' => $foto, 'foto_identificacion' => $ine])->save();
            AcompananteAcceso::create(['acceso_id' => $a->id, 'nombre' => 'Acompañante de '.$nombre, 'identificacion' => 'licencia']);

            return $a;
        }, $empresa);
    }

    private function fila(Acceso $a): Acceso
    {
        return Acceso::withoutGlobalScopes()->findOrFail($a->id);
    }

    // ------------------------------------------------------------- Conservación

    public function test_conservacion_quita_fotos_y_datos_al_vencer_y_conserva_el_registro(): void
    {
        // Empresa nueva: la tarea nace encendida con los valores prudentes
        $ajustes = app(ConservacionDatos::class)->ajustes($this->empresa->refresh());
        $this->assertTrue($ajustes['activa']);
        $this->assertSame(90, $ajustes['fotos_persona_dias']);
        $this->assertSame(90, $ajustes['fotos_identificacion_dias']);
        $this->assertSame(365, $ajustes['visitantes_dias']);

        $visita = $this->acceso('MARIA LOPEZ');
        $colaborador = $this->acceso('JUAN COLABORADOR', ['tipo' => 'colaborador']);
        $abierto = $this->acceso('PEDRO EN SITIO', ['estado' => 'en_sitio', 'salida_at' => null]);

        // A los 30 días no toca nada
        $this->travel(30)->days();
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $this->assertNotNull($this->fila($visita)->foto_persona);

        // A los 91 días: se quitan las fotos (de la persona y de su identificación) de registros cerrados; el nombre sigue
        $this->travel(61)->days();
        $this->flushSession();
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $v = $this->fila($visita);
        $this->assertNull($v->foto_persona);
        $this->assertNull($v->foto_identificacion);
        $this->assertSame('MARIA LOPEZ', $v->nombre);
        $this->assertSame([], Storage::disk('local')->files('accesos/'.$this->empresa->id) === [] ? [] : array_values(array_filter(Storage::disk('local')->files('accesos/'.$this->empresa->id),
            fn ($f) => in_array($f, [$visita->foto_persona, $visita->foto_identificacion], true))));
        $this->assertNull($this->fila($colaborador)->foto_persona); // las fotos también se quitan en registros de colaboradores
        $this->assertNotNull($this->fila($abierto)->foto_persona);  // abiertos: nunca
        $this->assertTrue(Auditoria::where('evento', 'privacidad.conservacion_aplicada')->where('empresa_id', $this->empresa->id)->exists());
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $this->sa->id)->where('tipo', 'privacidad_conservacion')->exists());

        // Al año: los datos del visitante externo se quitan; el registro se conserva; el colaborador no se toca
        $this->travel(275)->days();
        $this->flushSession();
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $v = $this->fila($visita);
        $this->assertSame(ConservacionDatos::TEXTO_ELIMINADO, $v->nombre);
        $this->assertNotNull($v->datos_eliminados_en);
        $this->assertNull($v->placas);
        $this->assertNull($v->identificacion);
        $this->assertNull($v->observaciones);
        $this->assertSame(ConservacionDatos::TEXTO_ELIMINADO, AcompananteAcceso::withoutGlobalScopes()->where('acceso_id', $visita->id)->value('nombre'));
        $this->assertSame('JUAN COLABORADOR', $this->fila($colaborador)->nombre);
        $this->assertSame('PEDRO EN SITIO', $this->fila($abierto)->nombre);
        // La bitácora sigue mostrando el registro (fecha, sede) con el texto en lugar del nombre
        $this->actingAs($this->admin)->get('/accesos?pestana=historial&desde='.now()->subYear()->subDays(30)->toDateString())->assertOk();

        // --si-toca: una vez al día (después de las 2:00)
        $this->artisan('plataforma:privacidad', ['--si-toca' => true])->assertSuccessful();
        $this->assertFalse(app(ConservacionDatos::class)->toca());
    }

    public function test_nada_se_borra_con_la_tarea_apagada_y_las_empresas_existentes_nacen_apagadas(): void
    {
        // La migración apaga la tarea de las empresas que ya existían
        $migracion = require database_path('migrations/2026_11_04_000200_privacidad_y_solicitudes_arco.php');
        (fn () => $this->apagarConservacionEnEmpresasExistentes())->call($migracion);
        $this->assertFalse(app(ConservacionDatos::class)->ajustes($this->empresa->refresh())['activa']);
        $this->assertFalse($this->empresa->preferencias['privacidad']['conservacion_activa']);
        // Una empresa nueva la trae encendida
        $nueva = $this->crearEmpresa('Hotel Nuevo');
        $this->assertTrue(app(ConservacionDatos::class)->ajustes($nueva)['activa']);

        $visita = $this->acceso('ANA APAGADA');
        $this->travel(800)->days();
        $this->flushSession();
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $v = $this->fila($visita);
        $this->assertSame('ANA APAGADA', $v->nombre);
        $this->assertNotNull($v->foto_persona);
        $this->assertTrue(Storage::disk('local')->exists($visita->foto_persona));
        $this->assertFalse(Auditoria::where('evento', 'privacidad.conservacion_aplicada')->exists());

        // Configuración muestra cuánto se quitaría al encenderla; el administrador la confirma
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Conservación de datos personales')
            ->assertSee('Apagada: no se quita nada hasta que la enciendas')->assertSee('hoy se quitarían');
        $this->actingAs($this->agente)->put('/configuracion/privacidad', ['conservacion_activa' => 1])->assertForbidden();
        $this->actingAs($this->admin)->put('/configuracion/privacidad', ['conservacion_activa' => '1', 'fotos_persona_dias' => 5, 'fotos_identificacion_dias' => 90, 'visitantes_dias' => 365, 'arco_recordar_dias' => 5])
            ->assertSessionHasErrors('fotos_persona_dias');
        $this->actingAs($this->admin)->put('/configuracion/privacidad', ['conservacion_activa' => '1', 'fotos_persona_dias' => 30, 'fotos_identificacion_dias' => 30, 'visitantes_dias' => 400, 'arco_recordar_dias' => 3])
            ->assertRedirect(route('configuracion.index').'#cfg-conservacion')->assertSessionHas('ok');
        $this->assertTrue(Auditoria::where('evento', 'privacidad.conservacion')->exists());
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $this->assertSame(ConservacionDatos::TEXTO_ELIMINADO, $this->fila($visita)->nombre);
        $this->assertFalse(Storage::disk('local')->exists($visita->foto_persona));
    }

    // --------------------------------------------------------------- ARCO

    public function test_solicitud_arco_de_punta_a_punta(): void
    {
        $this->conCorreo();
        $codigo = app(AvisosPrivacidad::class)->codigoPublico($this->empresa);

        // Datos que la plataforma tiene de la persona
        $persona = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Rosa Martínez Uc', 'folio_identificacion' => 'ROMU800101', 'telefono' => '9991234567']));
        $acceso = $this->acceso('Rosa Martínez Uc', ['persona_id' => $persona->id]);
        $candidato = $this->enEmpresa(function () {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Rosa Martínez Uc', 'correo' => 'rosa@ejemplo.mx', 'origen' => 'caseta']);
            $c->forceFill(['etapa' => 'registrado'])->save();

            return $c;
        });
        $otraEmpresa = $this->crearEmpresa('Hotel Ajeno');
        $this->crearSede($otraEmpresa, 'AJ');
        $ajeno = $this->acceso('Rosa Martínez Uc', [], $otraEmpresa);

        // Público: aviso con su versión y formulario (sin sesión)
        $this->get('/privacidad/'.$codigo)->assertOk()->assertSee('Aviso de privacidad')->assertSee('versión 1')->assertSee('Ejercer mis derechos ARCO');
        $this->get('/privacidad/zzzzzzzzzzzz')->assertNotFound();
        $this->get('/privacidad/'.$codigo.'/solicitud')->assertOk()->assertSee('Tus derechos sobre tus datos');
        $this->post('/privacidad/'.$codigo.'/solicitud', ['derecho' => 'acceso'])->assertSessionHasErrors(['nombre', 'correo', 'detalle', 'acepta']);
        $this->post('/privacidad/'.$codigo.'/solicitud', [
            'derecho' => 'cancelacion', 'nombre' => 'Rosa Martínez Uc', 'correo' => 'Rosa@Ejemplo.mx', 'telefono' => '999 123 4567',
            'relacion' => 'visitante', 'detalle' => 'Visité el hotel y quiero que borren mi foto y mis datos.', 'acepta' => '1',
        ])->assertRedirect('/privacidad/'.$codigo.'/solicitud')->assertSessionHas('enviada');

        $s = SolicitudArco::withoutGlobalScopes()->sole();
        $this->assertSame($this->empresa->id, $s->empresa_id);
        $this->assertSame(SolicitudArco::POR_VERIFICAR, $s->estado);
        $this->assertSame('rosa@ejemplo.mx', $s->correo);
        $this->assertSame('9991234567', $s->telefono);
        $this->assertSame('ARCO-2026-0001', $s->folio);
        $enlace = null;
        Mail::assertSent(AvisoGeneral::class, function ($m) use (&$enlace) {
            $enlace = $m->botones[0][1] ?? null;

            return $m->hasTo('rosa@ejemplo.mx');
        });
        $this->assertNotNull($enlace);
        $this->assertNull(SolicitudArco::withoutGlobalScopes()->whereNotNull('vence_en')->first());

        // Confirmar el correo: GET muestra el botón; POST empieza el plazo legal (20 días hábiles)
        $ruta = parse_url($enlace, PHP_URL_PATH);
        $this->get($ruta)->assertOk()->assertSee('Confirmar mi solicitud')->assertSee($s->folio);
        $this->assertSame(SolicitudArco::POR_VERIFICAR, $s->refresh()->estado);
        $this->post($ruta)->assertOk()->assertSee('Listo: confirmaste tu solicitud');
        $s->refresh();
        $this->assertSame(SolicitudArco::PENDIENTE, $s->estado);
        $this->assertSame('2026-12-08', $s->vence_en->toDateString()); // martes 10 nov + 20 días hábiles
        $this->post($ruta)->assertOk()->assertSee('ya se usó o venció');

        // Llega a la campana y a Mis pendientes de quien atiende (Administrador), no al agente
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'arco_solicitud')->exists());
        $this->assertFalse(Notificacion::where('user_id', $this->agente->id)->where('tipo', 'arco_solicitud')->exists());
        app()->forgetScopedInstances();
        $pendientes = app(MisPendientes::class)->para($this->admin);
        $this->assertSame(1, collect($pendientes['items'])->firstWhere('clave', 'arco')['total']);
        $this->actingAs($this->agente)->get('/solicitudes-arco')->assertForbidden();

        // Bandeja con reloj y color
        $this->actingAs($this->admin)->get('/solicitudes-arco')->assertOk()->assertSee($s->folio)->assertSee('Faltan 20 día(s) hábil(es)')->assertSee('reloj-arco verde', false);
        // Otra empresa no la ve
        $adminAjeno = $this->crearUsuario($otraEmpresa, 'Administrador');
        $this->actingAs($adminAjeno)->get('/solicitudes-arco/'.$s->id)->assertNotFound();

        // Buscar: aparecen el padrón, la bitácora y el candidato de ESTA empresa (no el registro ajeno)
        $r = $this->actingAs($this->admin)->get('/solicitudes-arco/'.$s->id)->assertOk()
            ->assertSee('Padrón de personas')->assertSee('Bitácora de accesos')->assertSee('Candidatos')
            ->assertSee('value="'.$persona->id.'"', false)->assertSee('value="'.$acceso->id.'"', false)->assertSee('value="'.$candidato->id.'"', false);
        $this->assertStringNotContainsString('name="seleccion[acceso][]" value="'.$ajeno->id.'"', $r->getContent());

        // Exportar: ZIP con los datos y las fotos
        $seleccion = ['persona' => [$persona->id], 'acceso' => [$acceso->id, $ajeno->id], 'candidato' => [$candidato->id]];
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/exportar', ['seleccion' => $seleccion])->assertRedirect('/solicitudes-arco/'.$s->id);
        $s->refresh();
        $this->assertNotNull($s->archivo);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($s->archivo)) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $this->assertContains('datos/padron_personas.json', $nombres);
        $this->assertContains('datos/bitacora_accesos.csv', $nombres);
        $this->assertContains('datos/candidatos.json', $nombres);
        $this->assertContains('archivos/'.$acceso->foto_persona, $nombres);
        $bitacora = json_decode((string) $zip->getFromName('datos/bitacora_accesos.json'), true);
        $this->assertSame([$acceso->id], array_column($bitacora, 'id')); // el registro de la otra empresa no viaja
        $zip->close();
        $this->actingAs($this->admin)->get('/solicitudes-arco/'.$s->id.'/descargar')->assertOk();
        $this->assertTrue(Auditoria::where('evento', 'privacidad.arco_exportado')->exists());
        $this->assertTrue(Auditoria::where('evento', 'privacidad.arco_descargado')->exists());

        // Eliminar: pide confirmación; anonimiza padrón y bitácora, borra la ficha del candidato
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/eliminar', ['seleccion' => $seleccion])->assertSessionHasErrors('confirmar');
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/eliminar', ['seleccion' => $seleccion, 'confirmar' => '1'])->assertSessionHas('ok');
        $p = Persona::withoutGlobalScopes()->find($persona->id);
        $this->assertSame(SolicitudesArco::TEXTO_ELIMINADO, $p->nombre_completo);
        $this->assertNull($p->folio_identificacion);
        $this->assertNull($p->telefono);
        $this->assertFalse((bool) $p->activo);
        $this->assertSame(SolicitudesArco::TEXTO_ELIMINADO, $this->fila($acceso)->nombre);
        $this->assertNull($this->fila($acceso)->foto_persona);
        $this->assertSame('Rosa Martínez Uc', $this->fila($ajeno)->nombre); // otra empresa: intacto
        $this->assertNull(Candidato::withoutGlobalScopes()->find($candidato->id));
        $this->assertTrue(Auditoria::where('evento', 'privacidad.arco_eliminado')->exists());

        // Responder: le llega a la persona y la solicitud se cierra (resuelve la campana)
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/responder', ['respuesta' => 'corto', 'resultado' => 'atendida'])->assertSessionHasErrors('respuesta');
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/responder', ['respuesta' => 'Eliminamos tu foto, tu identificación y tus datos de nuestras bitácoras.', 'resultado' => 'atendida'])
            ->assertRedirect('/solicitudes-arco');
        $s->refresh();
        $this->assertSame(SolicitudArco::ATENDIDA, $s->estado);
        $this->assertSame($this->admin->id, $s->respondida_por);
        Mail::assertSent(AvisoGeneral::class, fn ($m) => $m->hasTo('rosa@ejemplo.mx') && str_contains($m->titulo, 'Respuesta'));
        $this->assertNotNull(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'arco_solicitud')->value('leida_en'));
        $this->assertTrue(Auditoria::where('evento', 'privacidad.arco_atendida')->exists());
        app()->forgetScopedInstances();
        Cache::flush();
        $this->assertNull(collect(app(MisPendientes::class)->para($this->admin)['items'])->firstWhere('clave', 'arco'));
        // Cerrada: ya no se exporta ni se elimina
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/exportar', ['seleccion' => $seleccion])->assertSessionHasErrors('solicitud');
        // La auditoría no guarda el nombre ni el correo de la persona
        foreach (Auditoria::where('evento', 'like', 'privacidad.arco_%')->get() as $a) {
            $this->assertStringNotContainsString('rosa@ejemplo.mx', json_encode($a->despues));
            $this->assertStringNotContainsString('Martínez', json_encode($a->despues, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_arco_plazos_recordatorio_vencida_y_verificacion_en_persona_sin_correo(): void
    {
        // Sin correo configurado: llega a quien atiende para verificar en persona
        $this->post('/privacidad/'.app(AvisosPrivacidad::class)->codigoPublico($this->empresa).'/solicitud', [
            'derecho' => 'acceso', 'nombre' => 'Luis Pech Can', 'correo' => 'luis@ejemplo.mx', 'detalle' => 'Quiero saber qué datos tienen de mí.', 'acepta' => '1',
        ])->assertSessionHas('sin_correo', true);
        $s = SolicitudArco::withoutGlobalScopes()->sole();
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'arco_solicitud')->exists());
        $this->actingAs($this->admin)->get('/solicitudes-arco/'.$s->id)->assertOk()->assertSee('La persona aún no confirma su correo');
        // No se atiende sin verificar
        $this->actingAs($this->admin)->post('/solicitudes-arco/'.$s->id.'/responder', ['respuesta' => 'Respuesta de prueba larga', 'resultado' => 'atendida'])->assertSessionHasErrors('solicitud');
        $this->actingAs($this->admin)->patch('/solicitudes-arco/'.$s->id.'/verificar')->assertRedirect('/solicitudes-arco/'.$s->id);
        $s->refresh();
        $this->assertSame(SolicitudArco::PENDIENTE, $s->estado);
        $this->assertSame('en_persona', $s->verificada_como);

        // A 5 días hábiles: ámbar y un recordatorio (una sola vez)
        $this->travelTo(Carbon::parse('2026-12-01 15:00:00', 'UTC'));
        $this->flushSession();
        $this->assertSame(5, SolicitudesArco::diasRestantes($s->refresh()));
        $this->assertSame('ambar', SolicitudesArco::nivel($s, 5));
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $this->assertSame(1, Notificacion::where('user_id', $this->admin->id)->where('tipo', 'arco_plazo')->count());
        app()->forgetScopedInstances(); // Mis pendientes recuerda el cálculo de la última petición
        $this->assertSame('ambar', collect(app(MisPendientes::class)->para($this->admin)['items'])->firstWhere('clave', 'arco')['nivel']);

        // Vencida: roja y aviso al Super Administrador (suplente)
        $this->travelTo(Carbon::parse('2026-12-10 15:00:00', 'UTC'));
        $this->flushSession();
        $this->assertSame(-2, SolicitudesArco::diasRestantes($s->refresh()));
        $this->assertSame('roja', SolicitudesArco::nivel($s, 5));
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $this->sa->id)->where('tipo', 'arco_plazo')->exists());
        $this->actingAs($this->admin)->get('/solicitudes-arco')->assertSee('Venció hace 2 día(s) hábil(es)')->assertSee('reloj-arco roja', false);

        // Días hábiles: de lunes a viernes
        $this->assertSame('2026-11-13', SolicitudesArco::venceEn(Carbon::parse('2026-11-12'), 1)->toDateString());
        $this->assertSame('2026-11-16', SolicitudesArco::venceEn(Carbon::parse('2026-11-13'), 1)->toDateString());
    }

    public function test_solicitud_de_la_pagina_de_la_plataforma_se_canaliza_y_campo_trampa(): void
    {
        $this->conCorreo();
        // Robot: llena el campo trampa; se responde igual y no se guarda nada
        $this->post('/privacidad/solicitud', ['sitio_web' => 'http://spam', 'derecho' => 'acceso', 'nombre' => 'Robot Robot', 'correo' => 'r@r.mx', 'detalle' => 'xxxxxxxxxxxxxx', 'acepta' => 1])
            ->assertSessionHas('enviada');
        $this->assertSame(0, SolicitudArco::withoutGlobalScopes()->count());

        $this->flushSession();
        $this->get('/privacidad/solicitud')->assertOk()->assertSee('Pedí información de los planes');
        $this->post('/privacidad/solicitud', ['derecho' => 'oposicion', 'nombre' => 'Carla Dzib May', 'correo' => 'carla@ejemplo.mx', 'detalle' => 'No quiero recibir publicidad.', 'acepta' => '1', 'relacion' => 'cliente'])
            ->assertRedirect('/privacidad/solicitud');
        $s = SolicitudArco::withoutGlobalScopes()->sole();
        $this->assertNull($s->empresa_id);
        app(SolicitudesArco::class)->confirmarCorreo($s);
        // Le llega al Super Administrador (no a la empresa)
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $this->sa->id)->where('tipo', 'arco_solicitud')->exists());
        $this->assertFalse(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'arco_solicitud')->exists());
        $this->actingAs($this->admin)->get('/solicitudes-arco/'.$s->id)->assertNotFound();
        $this->actingAs($this->sa)->get('/solicitudes-arco?plataforma=1')->assertOk()->assertSee($s->folio)->assertSee('canalízala');
        $this->actingAs($this->sa)->get('/solicitudes-arco/'.$s->id)->assertOk()->assertSee('Llegó desde la página de la plataforma');
        // Solo el Super Administrador canaliza (para la empresa, la solicitud ni existe)
        $this->actingAs($this->admin)->patch('/solicitudes-arco/'.$s->id.'/canalizar', ['empresa_id' => $this->empresa->id])->assertNotFound();
        $this->actingAs($this->sa)->patch('/solicitudes-arco/'.$s->id.'/canalizar', ['empresa_id' => $this->empresa->id])->assertRedirect();
        $this->assertSame($this->empresa->id, $s->refresh()->empresa_id);
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'arco_solicitud')->exists());
        $this->actingAs($this->admin)->get('/solicitudes-arco/'.$s->id)->assertOk();
        $this->assertTrue(Auditoria::where('evento', 'privacidad.arco_canalizada')->exists());
        // La landing y su aviso enlazan el formulario
        app(AjustesComerciales::class)->guardar(['aviso_privacidad' => 'Aviso de la plataforma (borrador).']);
        $this->actingAs($this->sa)->get('/aviso-de-privacidad')->assertOk()->assertSee(route('privacidad.solicitud'), false);
    }

    // ------------------------------------------------------- Aviso con versiones

    public function test_versiones_del_aviso_y_que_version_acepto_cada_persona(): void
    {
        $avisos = app(AvisosPrivacidad::class);
        // Versión 1: el texto vigente (el borrador) al primer uso
        $v1 = $avisos->vigente($this->empresa);
        $this->assertSame(1, $v1->numero);
        $this->assertSame(hash('sha256', $v1->texto), $v1->huella);

        // Un candidato acepta: queda con la versión 1
        $c = $this->enEmpresa(function () {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Iván Chi', 'origen' => 'kiosco']);
            app(AdministradorCandidatos::class)->aceptarPrivacidad($c, '127.0.0.1', 'kiosco');
            $c->forceFill(['etapa' => 'registrado'])->save();

            return $c;
        });
        $this->assertSame($v1->id, $c->aviso_privacidad_id);

        // Publicar versión nueva desde Configuración → Privacidad (con autor y auditoría)
        $this->actingAs($this->agente)->post('/configuracion/privacidad/aviso', ['texto' => 'Nuevo aviso'])->assertForbidden();
        $this->actingAs($this->admin)->post('/configuracion/privacidad/aviso', ['texto' => $v1->texto])->assertSessionHasErrors('texto');
        $this->actingAs($this->admin)->post('/configuracion/privacidad/aviso', ['texto' => "Hotel Uno usará tus datos para tu visita.\nDerechos ARCO: privacidad@hoteluno.mx"])
            ->assertRedirect(route('configuracion.index').'#cfg-aviso');
        $v2 = $avisos->vigente($this->empresa->refresh());
        $this->assertSame(2, $v2->numero);
        $this->assertSame($this->admin->id, $v2->publicado_por);
        $this->assertStringContainsString('Hotel Uno usará', app(AjustesRecepcion::class)->textoPrivacidad($this->empresa));
        $this->assertTrue(Auditoria::where('evento', 'privacidad.aviso_publicado')->exists());

        // Recepción → Ajustes (el mismo texto) también crea versión, con su autor
        $this->actingAs($this->admin)->put(route('recepcion.ajustes.guardar'), ['aviso_privacidad' => 'Tercera versión del aviso.', 'kiosco_horas' => 2, 'kiosco_usos' => 3])->assertRedirect();
        $v3 = $avisos->vigente($this->empresa->refresh());
        $this->assertSame(3, $v3->numero);
        $this->assertSame($this->admin->id, $v3->publicado_por);

        // El historial y la página pública muestran la versión; el candidato conserva la que aceptó
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('versión 3')->assertSee('Historial de versiones (3)');
        $this->actingAs($this->admin)->get(route('privacidad.aviso.version', $v1->id))->assertOk()->assertSee('versión 1');
        $otra = $this->crearEmpresa('Hotel Ajeno');
        $vOtra = $avisos->vigente($otra);
        $this->actingAs($this->admin)->get(route('privacidad.aviso.version', $vOtra->id))->assertNotFound();
        $this->get('/privacidad/'.$avisos->codigoPublico($this->empresa))->assertSee('versión 3')->assertSee('Tercera versión del aviso.');
        $this->assertSame(1, Candidato::withoutGlobalScopes()->find($c->id)->avisoPrivacidad()->withoutGlobalScope(EmpresaScope::class)->value('numero'));

        // Plataforma (página pública de planes): versión propia, sin empresa
        $this->assertNull($avisos->vigente(null));
        app(AjustesComerciales::class)->guardar(['aviso_privacidad' => 'Aviso de la plataforma v1']);
        $p1 = $avisos->vigente(null);
        $this->assertNull($p1->empresa_id);
        $this->assertSame(1, $p1->numero);
        app(AjustesComerciales::class)->guardar(['aviso_privacidad' => 'Aviso de la plataforma v2']);
        $this->assertSame(2, $avisos->vigente(null)->numero);
        $this->assertSame(3, AvisoPrivacidad::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
    }
}
