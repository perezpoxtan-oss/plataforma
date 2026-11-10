<?php

namespace Tests\Feature\Direccion;

use App\Mail\AltaProvisionalRegistrada;
use App\Mail\CorreoDePrueba;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Services\Respaldos\Respaldos;
use App\Support\CorreoPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Configuración: correo de la plataforma, avisos por correo y respaldos.
 */
class ConfiguracionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->sa = $this->crearSuperadmin();
        foreach (glob(storage_path('app/private/respaldos/respaldo_testing_*')) ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/private/respaldos/respaldo_testing_*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function datosCorreo(array $extra = []): array
    {
        return array_merge(['host' => 'mail.vdcp.com.mx', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos@vdcp.com.mx',
            'contrasena' => 'SecretoDePrueba123', 'remitente_correo' => 'avisos@vdcp.com.mx', 'remitente_nombre' => 'Avisos'], $extra);
    }

    public function test_el_superadmin_configura_el_correo_y_la_contrasena_queda_cifrada(): void
    {
        $this->actingAs($this->sa)->put('/configuracion/correo', $this->datosCorreo())->assertSessionHas('ok');

        $guardado = ConfiguracionPlataforma::where('clave', 'correo')->value('valor');
        $this->assertNotSame('SecretoDePrueba123', $guardado['contrasena']);
        $this->assertStringNotContainsString('SecretoDePrueba123', json_encode(DB::table('configuracion_plataforma')->get()));
        $this->assertStringNotContainsString('SecretoDePrueba123', json_encode(DB::table('auditoria')->get()));
        $this->assertTrue(app(CorreoPlataforma::class)->configurado());

        // La pantalla nunca devuelve la contraseña; guardar sin escribirla la conserva
        $this->actingAs($this->sa)->get('/configuracion')->assertOk()->assertSee('CONFIGURADO')->assertDontSee('SecretoDePrueba123');
        $this->actingAs($this->sa)->put('/configuracion/correo', $this->datosCorreo(['contrasena' => '']));
        app(CorreoPlataforma::class)->aplicar();
        $this->assertSame('SecretoDePrueba123', config('mail.mailers.plataforma.password'));

        $this->actingAs($this->sa)->put('/configuracion/correo', $this->datosCorreo(['host' => 'https://mal host']))->assertSessionHasErrors('host');
    }

    public function test_correo_de_prueba(): void
    {
        Mail::fake();
        $this->actingAs($this->sa)->post('/configuracion/correo/prueba', ['para' => 'yo@vdcp.com.mx'])->assertSessionHas('error');

        $this->actingAs($this->sa)->put('/configuracion/correo', $this->datosCorreo());
        $this->actingAs($this->sa)->post('/configuracion/correo/prueba', ['para' => 'yo@vdcp.com.mx'])->assertSessionHas('ok');
        Mail::assertSent(CorreoDePrueba::class, fn ($m) => $m->hasTo('yo@vdcp.com.mx'));
    }

    public function test_solo_el_superadmin_toca_correo_y_respaldos(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->get('/configuracion')->assertOk()->assertSee('Avisos por correo')->assertDontSee('Correo de la plataforma')->assertDontSee('Respaldar ahora');
        $this->actingAs($admin)->put('/configuracion/correo', $this->datosCorreo())->assertForbidden();
        $this->actingAs($admin)->post('/configuracion/respaldos')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get('/configuracion')->assertForbidden();
    }

    public function test_aviso_de_alta_provisional_a_quien_valida_y_se_puede_apagar(): void
    {
        Mail::fake();
        $this->actingAs($this->sa)->put('/configuracion/correo', $this->datosCorreo());
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->postJson('/colaboradores/rapido', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id])->assertCreated();
        Mail::assertSent(AltaProvisionalRegistrada::class, fn ($m) => $m->hasTo($rh->email) && ! $m->hasTo($agente->email));

        // La empresa apaga el aviso
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->put('/configuracion/avisos', [])->assertSessionHas('ok');
        $this->assertFalse($this->empresa->fresh()->aviso('alta_provisional'));
        Mail::fake();
        $this->actingAs($agente)->postJson('/colaboradores/rapido', ['nombre' => 'Otro', 'apellido_paterno' => 'Más', 'sede_id' => $this->centro->id])->assertCreated();
        Mail::assertNothingSent();
    }

    public function test_respaldos_crear_descargar_y_conservar_catorce_dias(): void
    {
        $respaldos = app(Respaldos::class);
        $r = $respaldos->crear('manual');
        $contenido = gzdecode(file_get_contents($r['ruta']));
        $this->assertStringContainsString('CREATE TABLE', $contenido);
        $this->assertMatchesRegularExpression('/INSERT INTO .users./', $contenido);
        // Cómo restaurar, sin nombrar herramientas del hospedaje
        $this->assertStringContainsString(Respaldos::COMO_RESTAURAR, $contenido);
        $this->assertDoesNotMatchRegularExpression('/cpanel|phpmyadmin|neubox/i', $contenido);

        // En SQLite (desarrollo) el respaldo se puede restaurar completo
        if (DB::connection()->getDriverName() === 'sqlite') {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec($contenido);
            $this->assertSame(User::count(), (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        }

        $this->actingAs($this->sa)->get('/configuracion')->assertSee('Respaldar ahora')->assertSee('Manual');
        $this->actingAs($this->sa)->get('/configuracion/respaldos/'.$r['archivo'])->assertOk()->assertHeader('Content-Type', 'application/gzip');
        $this->assertDatabaseHas('auditoria', ['evento' => 'configuracion.respaldo_descargado']);
        $this->actingAs($this->sa)->get('/configuracion/respaldos/..%2F.env')->assertNotFound();
        $this->actingAs($this->sa)->get('/configuracion/respaldos/respaldo_testing_20200101_000000_manual.sql.gz')->assertNotFound();

        // El diario: solo después de las 3:00 y uno por día
        $this->assertFalse($respaldos->tocaDiario(Carbon::parse('2026-10-05 02:00', 'America/Cancun')));
        $this->assertTrue($respaldos->tocaDiario(Carbon::parse('2099-10-05 04:00', 'America/Cancun')));
        $this->artisan('plataforma:respaldar', ['--si-toca' => true])->assertSuccessful();

        // Poda: los viejos se borran, pero siempre quedan los 3 más recientes
        $viejo = $respaldos->carpeta().'/respaldo_testing_20200101_000000_diario.sql.gz';
        copy($r['ruta'], $viejo);
        $respaldos->crear('manual');
        $respaldos->crear('manual');
        $respaldos->podar();
        $this->assertFileDoesNotExist($viejo);
        $this->assertGreaterThanOrEqual(3, $respaldos->listar()->count());
    }

    public function test_textos_de_correo_y_respaldos_sin_nombrar_el_hospedaje(): void
    {
        $this->actingAs($this->sa)->get('/configuracion')->assertOk()
            ->assertSee('Los datos te los da tu proveedor de correo')
            ->assertSee('pide a tu proveedor de hospedaje o a tu administrador de base de datos que lo importe')
            ->assertDontSee('cPanel')->assertDontSee('phpMyAdmin');
    }
}
