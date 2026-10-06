<?php

namespace Tests\Feature\Administracion;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Usuarios\HomonimosUsuarios;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Homónimos: el responsable creó dos usuarios «Daniela Canul May» (uno
 * vinculado al colaborador #1008 y otro no) y no recibió ningún aviso.
 */
class UsuariosHomonimosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function rol(string $nombre): Rol
    {
        return Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->firstOrFail();
    }

    private function colaborador(string $num, string $nombre, string $paterno, ?string $materno, ?Sede $sede = null): Colaborador
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Colaborador::create([
            'num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno, 'sede_id' => ($sede ?? $this->centro)->id,
        ]));
    }

    /** @return array<string, mixed> */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'name' => 'Daniela Canul May', 'numero_colaborador' => null, 'colaborador_id' => null,
            'username' => 'dcanul', 'email' => 'dcanul@ejemplo.mx', 'password' => 'Clave1234',
            'rol_id' => $this->rol('Agente')->id, 'sede_id' => $this->centro->id,
        ], $extra);
    }

    public function test_la_clave_ignora_mayusculas_acentos_y_espacios(): void
    {
        $this->assertSame('daniela canul may', HomonimosUsuarios::clave('  DANIELA   Canúl  máy '));
        $this->assertSame('jose peña', HomonimosUsuarios::clave('JOSÉ PEÑA'));
        $this->assertNotSame(HomonimosUsuarios::clave('Jose Pena'), HomonimosUsuarios::clave('José Peña'));
    }

    public function test_alta_con_el_mismo_nombre_pide_confirmar_que_es_otra_persona(): void
    {
        $c = $this->colaborador('1008', 'Daniela', 'Canul', 'May');
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['colaborador_id' => $c->id]))->assertSessionHasNoErrors();

        // Mismo nombre con otras mayúsculas y acentos: no se guarda sin confirmar
        $segundo = $this->datos(['name' => 'daniela  CANÚL may', 'username' => 'dcanul2', 'email' => 'dcanul2@ejemplo.mx', '_dialogo' => 'crear']);
        $this->actingAs($this->admin)->post('/usuarios', $segundo)
            ->assertSessionHasErrors(['confirmar_homonimo' => 'Ya existe un usuario con ese nombre: @dcanul (Agente). ¿Es la misma persona? Si es otra persona con el mismo nombre, marca «Sí, es otra persona con el mismo nombre» y guarda de nuevo. Si es la misma persona, cancela y edita su cuenta en la lista.']);
        $this->assertFalse(User::where('username', 'dcanul2')->exists());

        // El diálogo se reabre con el aviso y la casilla a la vista
        $pagina = $this->actingAs($this->admin)->withSession(['_old_input' => $segundo])->get('/usuarios')->getContent();
        $this->assertStringContainsString('Sí, es otra persona con el mismo nombre', $pagina);

        // Confirmado: se guarda (hay homónimos); usuario y correo siguen siendo únicos
        $this->actingAs($this->admin)->post('/usuarios', $segundo + ['confirmar_homonimo' => '1'])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertSame(2, User::where('empresa_id', $this->empresa->id)->whereIn('username', ['dcanul', 'dcanul2'])->count());
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['name' => 'Otra Persona', 'email' => 'nuevo@ejemplo.mx']))
            ->assertSessionHasErrors(['username' => 'Ya existe otro usuario registrado con ese nombre de usuario.']);
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['name' => 'Otra Persona', 'username' => 'nuevo']))
            ->assertSessionHasErrors(['email' => 'Ya existe otro usuario registrado con ese correo.']);
    }

    public function test_editar_pide_confirmar_solo_si_el_nombre_cambia_a_uno_que_ya_existe(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['name' => 'Daniela Canul', 'username' => 'otra', 'email' => 'otra@ejemplo.mx']))->assertSessionHasNoErrors();
        $otra = User::where('username', 'otra')->firstOrFail();
        $base = ['username' => 'otra', 'email' => 'otra@ejemplo.mx', 'rol_id' => $this->rol('Agente')->id, 'sede_id' => $this->centro->id, 'activo' => 1];

        // Cambiar a «Daniela Canul May»: pide confirmar
        $this->actingAs($this->admin)->put("/usuarios/{$otra->id}", $base + ['name' => 'Daniela Canul May'])->assertSessionHasErrors('confirmar_homonimo');
        $this->actingAs($this->admin)->put("/usuarios/{$otra->id}", $base + ['name' => 'Daniela Canul May', 'confirmar_homonimo' => 1])->assertSessionHasNoErrors();
        // Ya es homónimo: editar otra cosa no vuelve a pedirlo
        $this->actingAs($this->admin)->put("/usuarios/{$otra->id}", $base + ['name' => 'DANIELA CANUL MAY', 'email' => 'otra2@ejemplo.mx'])->assertSessionHasNoErrors();
    }

    public function test_aviso_en_vivo_con_usuarios_y_colaborador_por_vincular(): void
    {
        $c = $this->colaborador('1008', 'Daniela', 'Canul', 'May');
        $this->colaborador('2000', 'Daniela', 'Canul', 'Pech'); // otro apellido materno: no es homónimo
        $vacio = $this->actingAs($this->admin)->getJson('/usuarios/homonimos?nombre=daniela canul may')->assertOk();
        $this->assertSame([], $vacio->json('usuarios'));
        $this->assertFalse($vacio->json('requiere_confirmacion'));
        $this->assertSame([[$c->id, '1008', 'Daniela Canul May']], array_map(fn ($x) => [$x['id'], $x['num_empleado'], $x['nombre_completo']], $vacio->json('colaboradores')));

        // Con un usuario «Daniela Canul May» que NO está vinculado
        $this->actingAs($this->admin)->post('/usuarios', $this->datos())->assertSessionHasNoErrors();
        $r = $this->actingAs($this->admin)->getJson('/usuarios/homonimos?nombre='.urlencode('DANIELA CANÚL MAY'))->assertOk();
        $this->assertSame('Ya existe un usuario con ese nombre: @dcanul (Agente). ¿Es la misma persona?', $r->json('mensaje'));
        $this->assertSame([['username' => 'dcanul', 'rol' => 'Agente', 'colaborador' => null, 'activo' => true]], $r->json('usuarios'));
        $this->assertTrue($r->json('requiere_confirmacion'));
        // El colaborador #1008 sigue sin cuenta: se sugiere vincularlo
        $this->assertSame($c->id, $r->json('colaboradores.0.id'));

        // Editando a la misma cuenta: no se cuenta a sí misma
        $dcanul = User::where('username', 'dcanul')->firstOrFail();
        $this->actingAs($this->admin)->getJson("/usuarios/homonimos?nombre=Daniela Canul May&excluir={$dcanul->id}")
            ->assertJsonPath('usuarios', [])->assertJsonPath('requiere_confirmacion', false);

        // Una vez vinculado, el colaborador ya no se sugiere
        $dcanul->forceFill(['colaborador_id' => $c->id])->save();
        $this->actingAs($this->admin)->getJson('/usuarios/homonimos?nombre=Daniela Canul May')->assertJsonPath('colaboradores', [])
            ->assertJsonPath('usuarios.0.colaborador', '1008');

        // Nombre corto o raro: vacío, sin error
        $this->actingAs($this->admin)->getJson('/usuarios/homonimos?nombre=Da')->assertJsonPath('usuarios', []);
        $this->actingAs($this->admin)->getJson('/usuarios/homonimos?nombre[]=x')->assertOk()->assertJsonPath('usuarios', []);
    }

    public function test_el_aviso_respeta_empresa_permiso_y_alcance_de_sede(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['sede_id' => $this->playa->id]))->assertSessionHasNoErrors();
        $this->colaborador('3000', 'Daniela', 'Canul', 'May', $this->playa);

        // Otra empresa: no ve nada de esta
        $intruso = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($intruso)->getJson('/usuarios/homonimos?nombre=Daniela Canul May')
            ->assertOk()->assertJsonPath('usuarios', [])->assertJsonPath('otros', 0)->assertJsonPath('colaboradores', []);

        // Sin permiso de usuarios: 403
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/usuarios/homonimos?nombre=Daniela Canul May')->assertForbidden();

        // Con alcance de sede (Centro): sabe que existe, pero no ve los datos de la otra sede
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Gerente', 'nivel_jerarquia' => 15]);
        foreach (['usuarios.ver', 'usuarios.crear'] as $clave) {
            [$m, $a] = explode('.', $clave);
            $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', $m))->whereHas('accion', fn ($q) => $q->where('clave', $a))->firstOrFail();
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
        }
        $gerente = $this->crearUsuario($this->empresa, 'Gerente', $this->centro);
        $r = $this->actingAs($gerente)->getJson('/usuarios/homonimos?nombre=Daniela Canul May')->assertOk();
        $this->assertSame([], $r->json('usuarios'));
        $this->assertSame(1, $r->json('otros'));
        $this->assertSame([], $r->json('colaboradores'));
        $this->assertSame('Ya existe un usuario con ese nombre: otro usuario de una sede que no tienes a cargo. ¿Es la misma persona?', $r->json('mensaje'));
    }

    public function test_la_pantalla_trae_el_aviso_y_la_confirmacion(): void
    {
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()
            ->assertSee('data-homonimos="'.route('usuarios.homonimos').'"', false)
            ->assertSee('name="confirmar_homonimo"', false)
            ->assertSee('Sí, es otra persona con el mismo nombre');
    }

    // ---------------------------------------------------------- Colaboradores

    public function test_alta_de_colaborador_avisa_si_ya_hay_alguien_con_ese_nombre(): void
    {
        $this->colaborador('1008', 'Daniela', 'Canul', 'May');
        $this->colaborador('1009', 'Daniela', 'Canul', 'May', $this->playa);

        $r = $this->actingAs($this->admin)->getJson('/colaboradores/homonimos?nombre=DANIELA&apellido_paterno=canúl&apellido_materno=may')->assertOk();
        $this->assertSame(['1008', '1009'], collect($r->json('parecidos'))->pluck('num_empleado')->sort()->values()->all());
        $this->assertStringStartsWith('Ya existen colaboradores con ese nombre', $r->json('mensaje'));
        $this->actingAs($this->admin)->getJson('/colaboradores/homonimos?nombre=Daniela&apellido_paterno=Pech')->assertJsonPath('parecidos', [])->assertJsonPath('mensaje', null);
        $this->actingAs($this->admin)->getJson('/colaboradores/homonimos?nombre=Daniela')->assertJsonPath('parecidos', []);

        // Con alcance de sede: los de otra sede solo se cuentan
        $rh = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'RH Centro', 'nivel_jerarquia' => 26]);
        $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'colaboradores'))->whereHas('accion', fn ($q) => $q->where('clave', 'crear'))->firstOrFail();
        RolPermiso::create(['rol_id' => $rh->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
        $rhCentro = $this->crearUsuario($this->empresa, 'RH Centro', $this->centro);
        $r = $this->actingAs($rhCentro)->getJson('/colaboradores/homonimos?nombre=Daniela&apellido_paterno=Canul&apellido_materno=May')->assertOk();
        $this->assertSame(['1008'], collect($r->json('parecidos'))->pluck('num_empleado')->all());
        $this->assertSame(1, $r->json('otros'));
        $this->assertStringContainsString('(1 en sedes que no tienes a cargo)', $r->json('mensaje'));

        // Sin permiso de alta: 403; la pantalla de alta trae el aviso
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/colaboradores/homonimos?nombre=Daniela&apellido_paterno=Canul')->assertForbidden();
        $this->actingAs($this->admin)->get('/colaboradores')->assertOk()->assertSee('data-homonimos="'.route('colaboradores.homonimos').'"', false);
    }
}
