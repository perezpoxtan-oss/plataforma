<?php

namespace Tests\Feature\Nucleo;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatosDemoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    protected function tearDown(): void
    {
        putenv('PLATAFORMA_CONTRASENA');
        parent::tearDown();
    }

    public function test_crea_empresa_demo_con_un_usuario_por_rol_y_es_repetible(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $this->assertSame(1, Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->count());
        $this->assertSame(count(CrearDatosDemo::USUARIOS), User::where('email', 'like', '%@demo.local')->count());

        $agente = User::where('username', 'agente.demo')->firstOrFail();
        $this->assertTrue(Hash::check('Prueba123!', $agente->password));
        $this->assertTrue($agente->can('accesos.ver'));
        $this->assertFalse($agente->can('usuarios.ver'));
        $this->assertSame(1, $agente->roles()->count());
    }

    public function test_al_completar_crea_las_cuentas_que_faltan_sin_tocar_las_existentes(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        // Quien prueba cambió el correo de admin.demo y alguien borró rh.demo (como una QA anterior a RH)
        User::where('username', 'admin.demo')->update(['email' => 'real@vdcp.com.mx', 'password' => Hash::make('OtraClave99')]);
        User::where('username', 'rh.demo')->delete();

        $this->artisan('plataforma:demo', ['--password' => 'Nueva1234!'])->assertSuccessful()->expectsOutputToContain('Usuario demo creado: rh.demo');

        $admin = User::where('username', 'admin.demo')->firstOrFail();
        $this->assertSame('real@vdcp.com.mx', $admin->email);
        $this->assertTrue(Hash::check('OtraClave99', $admin->password));
        $rh = User::where('username', 'rh.demo')->firstOrFail();
        $this->assertTrue(Hash::check('Nueva1234!', $rh->password));
        $this->assertTrue($rh->can('colaboradores.aprobar'));
    }

    public function test_sin_contrasena_completa_un_demo_existente_con_la_de_admin_y_el_rol_que_faltaba(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        // QA creada antes de Recursos Humanos: no existen ni rh.demo ni el rol en la empresa
        User::where('username', 'rh.demo')->delete();
        $empresa = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        Rol::where('empresa_id', $empresa->id)->where('nombre', 'Recursos Humanos')->get()->each->delete();

        $this->artisan('plataforma:demo')->assertSuccessful()->expectsOutputToContain('Usuario demo creado: rh.demo');

        $rh = User::where('username', 'rh.demo')->firstOrFail();
        $this->assertTrue(Hash::check('Prueba123!', $rh->password));
        $this->assertTrue($rh->can('colaboradores.aprobar'));
    }

    public function test_toma_la_contrasena_de_la_variable_de_entorno(): void
    {
        putenv('PLATAFORMA_CONTRASENA=DesdeEntorno9');

        $this->artisan('plataforma:superadmin', ['email' => 'sa@ejemplo.mx'])->assertSuccessful();
        $this->artisan('plataforma:demo')->assertSuccessful();

        $this->assertTrue(Hash::check('DesdeEntorno9', User::where('email', 'sa@ejemplo.mx')->value('password')));
        $this->assertTrue(Hash::check('DesdeEntorno9', User::where('username', 'admin.demo')->value('password')));
    }

    public function test_no_corre_en_produccion_ni_sin_contrasena(): void
    {
        $this->artisan('plataforma:demo')->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertFailed();
    }
}
