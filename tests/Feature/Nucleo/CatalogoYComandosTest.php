<?php

namespace Tests\Feature\Nucleo;

use App\Models\Accion;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\User;
use App\Support\Identidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogoYComandosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    public function test_el_catalogo_es_idempotente(): void
    {
        $this->artisan('plataforma:instalar')->assertSuccessful();
        $modulos = Modulo::count();
        $acciones = Accion::count();
        $roles = Rol::plantillas()->count();

        $this->artisan('plataforma:instalar')->assertSuccessful();

        $this->assertGreaterThan(30, $modulos);
        $this->assertSame($modulos, Modulo::count());
        $this->assertSame($acciones, Accion::count());
        $this->assertSame($roles, Rol::plantillas()->count());
        $this->assertSame('novedades', Modulo::where('clave', 'lost_found')->first()->padre->clave);
    }

    public function test_crea_superadmin_conservando_un_hash_existente(): void
    {
        $hash = Hash::make('ClaveDeSegcat2026');

        $this->artisan('plataforma:superadmin', [
            'email' => 'Soporte@VDCP.com.mx',
            '--hash' => $hash,
        ])->assertSuccessful();

        $usuario = User::where('email', 'soporte@vdcp.com.mx')->firstOrFail();
        $this->assertTrue($usuario->es_superadmin);
        $this->assertNull($usuario->empresa_id);
        $this->assertTrue(Hash::check('ClaveDeSegcat2026', $usuario->password));
    }

    public function test_rechaza_un_hash_invalido(): void
    {
        $this->artisan('plataforma:superadmin', ['email' => 'a@b.mx', '--hash' => 'texto-plano'])
            ->assertFailed();
    }

    public function test_la_identidad_usa_valores_por_defecto_y_se_puede_cambiar(): void
    {
        $identidad = app(Identidad::class);
        $this->assertSame('Plataforma', $identidad->get('nombre'));

        $identidad->guardar(['nombre' => 'Portiq', 'campo_desconocido' => 'x']);

        $this->assertSame('Portiq', $identidad->get('nombre'));
        $this->assertSame('#2563eb', $identidad->get('color_primario'));
        $this->assertNull($identidad->get('campo_desconocido'));
    }
}
