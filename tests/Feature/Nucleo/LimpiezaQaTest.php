<?php

namespace Tests\Feature\Nucleo;

use App\Console\Commands\LimpiarQa;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Rubro;
use App\Models\User;
use App\Services\Arranque\AsistenteArranque;
use App\Services\BaseDatos\LimpiezaQa;
use App\Services\Respaldos\Respaldos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * plataforma:limpiar-qa (docs/tecnico/despliegue.md, «QA: limpieza única de
 * la base»): con los datos demo cargados deja solo a los Super
 * Administradores y lo de nivel plataforma; respaldo primero; nunca en
 * Producción.
 */
class LimpiezaQaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private string $almacen;

    protected function setUp(): void
    {
        parent::setUp();
        // Archivos en una carpeta desechable (nunca el storage del repositorio)
        $this->almacen = sys_get_temp_dir().'/plataforma-limpieza-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->almacen.'/app/private');
        File::ensureDirectoryExists($this->almacen.'/app/public');
        $this->app->useStoragePath($this->almacen);
        config(['filesystems.disks.local.root' => $this->almacen.'/app/private', 'filesystems.disks.public.root' => $this->almacen.'/app/public']);
        Storage::forgetDisk(['local', 'public']);
        $this->sembrarCatalogo();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->almacen);
        parent::tearDown();
    }

    /** Super Administrador con dos pasos y códigos de respaldo, más los datos demo completos. */
    private function qaConDemo(): User
    {
        $this->artisan('plataforma:superadmin', ['email' => 'sa@ejemplo.mx', '--usuario' => 'sa', '--password' => 'Clave.Segura.2026'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba.Local.2026'])->assertSuccessful();
        $sa = User::where('username', 'sa')->firstOrFail();
        DB::table('users')->where('id', $sa->id)->update([
            'two_factor_secret' => 'secreto-cifrado', 'two_factor_recovery_codes' => 'codigos-cifrados', 'two_factor_confirmado_en' => now(),
        ]);
        // Archivos de la plataforma que nunca se borran y uno de empresa
        File::ensureDirectoryExists($this->almacen.'/app/private/respaldos');
        File::put($this->almacen.'/app/private/respaldos/respaldo_qa_20261101_030000_diario.sql.gz', 'x');
        File::ensureDirectoryExists($this->almacen.'/app/private/traslados');
        File::put($this->almacen.'/app/private/traslados/paquete.zip', 'x');
        File::ensureDirectoryExists($this->almacen.'/app/public/identidad');
        File::put($this->almacen.'/app/public/identidad/logo.png', 'x');
        File::ensureDirectoryExists($this->almacen.'/app/public/empresas/logos');
        File::put($this->almacen.'/app/public/empresas/logos/hotel.png', 'x');

        return $sa;
    }

    /** @return array<string, int> */
    private function conteos(): array
    {
        return collect(app(LimpiezaQa::class)->tablas())->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_simular_con_el_demo_cargado_no_cambia_nada(): void
    {
        $this->qaConDemo();
        $antes = $this->conteos();
        $archivos = count(File::allFiles($this->almacen, true));

        $this->artisan('plataforma:limpiar-qa', ['--simular' => true])->assertSuccessful()
            ->expectsOutputToContain('Simulación: no se cambió nada')
            ->expectsOutputToContain('Todos menos los Super Administradores')
            ->expectsOutputToContain('public/empresas');

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($archivos, count(File::allFiles($this->almacen, true)));
        $this->assertGreaterThan(1, User::count());
        $this->assertSame([], app(Respaldos::class)->listar()->where('motivo', LimpiarQa::MOTIVO_RESPALDO)->all(), 'Simular no respalda');
    }

    public function test_deja_solo_super_administradores_y_datos_globales(): void
    {
        $sa = $this->qaConDemo();
        $hashAntes = DB::table('users')->where('id', $sa->id)->first();
        $globales = collect(LimpiezaQa::CONSERVAR)->reject(fn ($t) => $t === 'migrations')
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $plantillas = DB::table('roles')->whereNull('empresa_id')->count();
        $permisosPlantilla = DB::table('rol_permisos')->whereIn('rol_id', DB::table('roles')->whereNull('empresa_id')->select('id'))->count();
        $tiposGlobales = DB::table('tipos_espacio')->whereNull('empresa_id')->count();
        $this->assertGreaterThan(0, Empresa::count());

        $this->artisan('plataforma:limpiar-qa', ['--confirmar' => true])->assertSuccessful()
            ->expectsOutputToContain('Base de QA limpiada');

        // Solo el Super Administrador, con su contraseña, dos pasos y códigos de respaldo
        $this->assertSame([$sa->id], User::pluck('id')->all());
        $despues = DB::table('users')->where('id', $sa->id)->first();
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmado_en', 'email', 'username'] as $campo) {
            $this->assertSame($hashAntes->{$campo}, $despues->{$campo}, $campo);
        }
        $this->assertNull($despues->empresa_id);

        // Lo de nivel plataforma, intacto
        foreach ($globales as $tabla => $n) {
            $this->assertSame($n, DB::table($tabla)->count(), $tabla);
        }
        $this->assertSame($plantillas, DB::table('roles')->count());
        $this->assertSame($permisosPlantilla, DB::table('rol_permisos')->count());
        $this->assertSame($tiposGlobales, DB::table('tipos_espacio')->count());
        $this->assertGreaterThan(0, $plantillas);

        // Todas las tablas de empresa, vacías
        $conservadas = array_merge(LimpiezaQa::CONSERVAR, ['users', 'roles', 'rol_permisos', 'tipos_espacio', 'auditoria']);
        foreach ($this->conteos() as $tabla => $n) {
            if (! in_array($tabla, $conservadas, true)) {
                $this->assertSame(0, $n, "La tabla {$tabla} debió quedar vacía");
            }
        }
        foreach (['roles', 'tipos_espacio', 'avisos_privacidad', 'auditoria'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->whereNotNull('empresa_id')->count(), $tabla);
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'), 'Nada de lo que queda apunta a algo borrado');

        // Una línea de auditoría de plataforma con los conteos
        $linea = Auditoria::where('evento', 'plataforma.base_limpiada')->sole();
        $this->assertNull($linea->empresa_id);
        $this->assertGreaterThan(0, $linea->despues['renglones']);
        $this->assertSame(1, $linea->despues['super_administradores']);
        $this->assertArrayHasKey('accesos', $linea->despues['tablas']);

        // Respaldo previo; archivos de empresas borrados; respaldos, traslados e identidad, intactos
        $this->assertNotNull(app(Respaldos::class)->listar()->firstWhere('motivo', LimpiarQa::MOTIVO_RESPALDO));
        $this->assertFileExists($this->almacen.'/app/private/respaldos/respaldo_qa_20261101_030000_diario.sql.gz');
        $this->assertFileExists($this->almacen.'/app/private/traslados/paquete.zip');
        $this->assertFileExists($this->almacen.'/app/public/identidad/logo.png');
        $this->assertDirectoryDoesNotExist($this->almacen.'/app/public/empresas');
        $this->assertDirectoryDoesNotExist($this->almacen.'/app/private/firmas');
        $this->assertSame([], app(LimpiezaQa::class)->archivos());
    }

    public function test_despues_de_limpiar_el_super_administrador_entra_y_crea_una_empresa_completa(): void
    {
        $this->qaConDemo();
        DB::table('users')->where('username', 'sa')->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmado_en' => null]);
        $this->artisan('plataforma:limpiar-qa', ['--confirmar' => true])->assertSuccessful();

        $this->post('/login', ['username' => 'sa', 'password' => 'Clave.Segura.2026'])->assertRedirect();
        $sa = User::where('username', 'sa')->firstOrFail();
        $this->assertAuthenticatedAs($sa);
        $this->post('/logout');

        // Las cuentas demo ya no existen
        $this->post('/login', ['username' => 'admin.demo', 'password' => 'Prueba.Local.2026']);
        $this->assertGuest();

        $this->actingAs($sa)->post('/empresas', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'nombre_comercial' => 'Hotel Nuevo',
            'razon_social' => 'Hotel Nuevo SA de CV', 'rfc' => 'HNU010101AB1', 'zona_horaria' => 'America/Cancun', 'idioma' => 'es', 'moneda' => 'MXN'])
            ->assertSessionHasNoErrors();
        $empresa = Empresa::where('nombre_comercial', 'Hotel Nuevo')->sole();
        $this->assertSame(AsistenteArranque::VISIBLE, $empresa->preferencias['configura_tu_empresa']);
        $this->assertGreaterThan(0, DB::table('roles')->where('empresa_id', $empresa->id)->count(), 'Recibe sus roles de las plantillas');
        $this->assertGreaterThan(0, DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->count(), 'Recibe sus módulos');

        $admin = $this->crearUsuario($empresa, 'Administrador');
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Configura tu empresa')->assertSee('0 de 7 pasos');
        $this->crearSede($empresa, 'S1');
        $this->actingAs($admin)->get('/')->assertSee('1 de 7 pasos');
    }

    public function test_se_niega_en_produccion_y_sin_confirmar(): void
    {
        $this->qaConDemo();
        $antes = $this->conteos();

        $this->artisan('plataforma:limpiar-qa')->assertFailed()->expectsOutputToContain('Falta --confirmar');

        foreach (['production' => 'Bloqueado en Producción', 'prod' => 'solo se hace en', 'staging' => 'solo se hace en'] as $ambiente => $mensaje) {
            $this->app['env'] = $ambiente;
            $this->artisan('plataforma:limpiar-qa', ['--confirmar' => true])->assertFailed()->expectsOutputToContain($mensaje);
            $this->artisan('plataforma:limpiar-qa', ['--simular' => true])->assertFailed();
        }
        $this->app['env'] = 'testing';

        $this->assertSame($antes, $this->conteos());
        $this->assertNull(app(Respaldos::class)->listar()->firstWhere('motivo', LimpiarQa::MOTIVO_RESPALDO), 'Sin respaldo: no se intentó');
    }

    public function test_si_el_respaldo_falla_no_borra_nada(): void
    {
        $this->qaConDemo();
        $antes = $this->conteos();
        $archivos = count(File::allFiles($this->almacen, true));
        $this->mock(Respaldos::class, function ($m) {
            $m->shouldReceive('crear')->andThrow(new RuntimeException('Disco lleno'));
            $m->shouldReceive('listar')->andReturn(collect());
        });

        $this->artisan('plataforma:limpiar-qa', ['--confirmar' => true])->assertFailed()
            ->expectsOutputToContain('No se limpió la base: no se pudo hacer el respaldo previo.');

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($archivos, count(File::allFiles($this->almacen, true)));
        $this->assertSame(0, Auditoria::where('evento', 'plataforma.base_limpiada')->count());
    }

    public function test_solicitud_vigente_para_el_despliegue(): void
    {
        $this->artisan('plataforma:limpiar-qa', ['--solicitud' => true])->assertSuccessful()->expectsOutput(LimpiarQa::SOLICITUD);
    }

    public function test_base_sin_super_administrador_ni_empresas_queda_igual_y_es_repetible(): void
    {
        $this->artisan('plataforma:limpiar-qa', ['--confirmar' => true])->assertSuccessful();
        $this->artisan('plataforma:limpiar-qa', ['--confirmar' => true])->assertSuccessful();
        $this->assertSame(0, User::count());
        $this->assertGreaterThan(0, DB::table('modulos')->count());
        $this->assertSame(2, Auditoria::where('evento', 'plataforma.base_limpiada')->count());
    }
}
