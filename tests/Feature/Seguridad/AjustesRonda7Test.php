<?php

namespace Tests\Feature\Seguridad;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Llave;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Ronda 7 (parte A): los últimos padrones pasan al aviso único de duplicados
 * (data-duplicado + AvisoDuplicado): Llaves (nombre por sede y etiqueta NFC),
 * Departamentos, Puestos, Turnos y Usuarios (usuario y correo; los homónimos
 * están en Administracion/UsuariosHomonimosTest). Sin avisos propios.
 */
class AjustesRonda7Test extends TestCase
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

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function llave(string $nomenclatura, ?Sede $sede = null, array $extra = []): Llave
    {
        return $this->enEmpresa(fn () => Llave::create(array_merge(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nomenclatura,
            'descripcion' => 'Acceso de prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global'], $extra)));
    }

    private function llaves(User $quien, string $valor, ?Sede $sede = null, array $extra = []): TestResponse
    {
        return $this->actingAs($quien)->getJson('/llaves/duplicado?'.http_build_query(['campo' => 'nomenclatura', 'valor' => $valor, 'sede_id' => ($sede ?? $this->centro)->id] + $extra));
    }

    // ================================================================= Llaves

    public function test_llaves_nombre_por_sede_existe_parecido_libre_y_reactivar(): void
    {
        $sit = $this->llave('LL-CAT-SIT-01');
        $this->llave('HDP-201', $this->playa);

        // Igual en la misma sede (sin importar mayúsculas ni espacios dobles): existe
        $this->llaves($this->admin, 'll-cat-sit-01')->assertOk()->assertJsonPath('estado', 'existe')
            ->assertJsonPath('mensaje', 'Ya existe una llave «LL-CAT-SIT-01» en Sede CEN. No se puede repetir en la misma sede.')
            ->assertJsonPath('coincidencias.0.titulo', 'LL-CAT-SIT-01')->assertJsonPath('coincidencias.0.reactivar', null);
        // Sin guiones ni espacios: parecido
        $this->llaves($this->admin, 'LL CAT SIT 01')->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'LL-CAT-SIT-01');
        // Otra sede puede usar el mismo nombre
        $this->llaves($this->admin, 'LL-CAT-SIT-01', $this->playa)->assertJsonPath('estado', 'libre')->assertJsonPath('mensaje', 'Nombre disponible en Sede PLA.');
        $this->llaves($this->admin, 'HDC-999')->assertJsonPath('estado', 'libre');
        // Edición: la propia no cuenta
        $this->llaves($this->admin, 'LL-CAT-SIT-01', null, ['excluir' => $sit->id])->assertJsonPath('estado', 'libre');
        // Sin sede todavía, o con muy poco texto: no se revisa
        $this->actingAs($this->admin)->getJson('/llaves/duplicado?campo=nomenclatura&valor=LL-CAT-SIT-01')->assertJsonPath('estado', 'nada');
        $this->llaves($this->admin, 'L')->assertJsonPath('estado', 'nada');

        // Dada de baja: «Reactivar» (PATCH /llaves/{id}/reactivar)
        $sit->forceFill(['activo' => false])->save();
        $this->llaves($this->admin, 'LL-CAT-SIT-01')->assertJsonPath('estado', 'existe')
            ->assertJsonPath('coincidencias.0.inactivo', true)->assertJsonPath('coincidencias.0.reactivar', route('llaves.reactivar', $sit->id));
        $this->actingAs($this->admin)->patch(route('llaves.reactivar', $sit->id), ['activo' => 1])->assertRedirect();
        $this->assertTrue($sit->fresh()->activo);
    }

    public function test_llaves_aviso_respeta_sede_empresa_y_permiso(): void
    {
        $this->llave('HDP-201', $this->playa);
        $this->enEmpresa(function () {
            $sede = Sede::create(['codigo' => 'OTR', 'nombre' => 'Sede OTR']);
            Llave::create(['sede_id' => $sede->id, 'nomenclatura' => 'AJENA-1', 'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
        }, $otra = $this->crearEmpresa('Hotel Dos'));
        $this->assertNotNull($otra);

        // Jefe de Centro: la sede de Playa no es suya (no se revela nada)
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->llaves($jefe, 'HDP-201', $this->playa)->assertJsonPath('estado', 'nada');
        $this->llaves($jefe, 'HDP-201')->assertJsonPath('estado', 'libre');
        // Otra empresa no existe aquí
        $this->llaves($this->admin, 'AJENA-1')->assertJsonPath('estado', 'libre');
        // El Agente solo consulta llaves: no pregunta
        $this->llaves($this->crearUsuario($this->empresa, 'Agente', $this->centro), 'HDP-201')->assertForbidden();

        // Un solo mecanismo en la pantalla (nombre y etiqueta NFC)
        $this->actingAs($this->admin)->get('/llaves')->assertOk()
            ->assertSee('data-duplicado="'.route('llaves.duplicado').'"', false)
            ->assertSee('data-duplicado-con="sede_id"', false)
            ->assertSee('data-duplicado="'.route('identificacion.etiqueta-duplicado', ['llave', 0]).'"', false)
            ->assertDontSee('data-nombres-existentes', false)->assertDontSee('data-nombres-por-sede', false)->assertDontSee('data-aviso-nombre', false);
    }

    public function test_llaves_etiqueta_nfc_con_el_aviso_unico(): void
    {
        $this->llave('HDC-101', null, ['etiqueta_nfc' => '04A1B2C3D4']);
        $this->actingAs($this->admin)->getJson('/identificacion/llave/0/etiqueta-duplicado?valor=04:a1:b2:c3:d4')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', 'HDC-101');
        $this->actingAs($this->admin)->getJson('/identificacion/llave/0/etiqueta-duplicado?valor=0499887766')->assertJsonPath('estado', 'libre');
    }

    // ============================================ Departamentos, Puestos y Turnos

    public function test_departamentos_existe_parecido_desactivado_y_reactivar(): void
    {
        $sistemas = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Sistemas', 'todas_las_sedes' => true]));
        $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Sistemas']), $this->crearEmpresa('Hotel Dos'));

        $this->actingAs($this->admin)->getJson('/departamentos/duplicado?campo=nombre&valor=SISTEMAS')->assertOk()
            ->assertJsonPath('estado', 'existe')->assertJsonPath('mensaje', 'Ya existe un departamento «Sistemas» en esta empresa. No se puede repetir.')
            ->assertJsonPath('coincidencias.0.detalle', 'Todas las sedes');
        // Acentos o una letra de diferencia: parecido
        $this->actingAs($this->admin)->getJson('/departamentos/duplicado?campo=nombre&valor=Sistémas')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->admin)->getJson('/departamentos/duplicado?campo=nombre&valor=Sistema')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->admin)->getJson('/departamentos/duplicado?campo=nombre&valor=Ama de Llaves')->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson("/departamentos/duplicado?campo=nombre&valor=Sistemas&excluir={$sistemas->id}")->assertJsonPath('estado', 'libre');

        $sistemas->forceFill(['activo' => false])->save();
        $this->actingAs($this->admin)->getJson('/departamentos/duplicado?campo=nombre&valor=Sistemas')->assertJsonPath('estado', 'existe')
            ->assertJsonPath('mensaje', 'Ya existe el departamento «Sistemas», pero está desactivado. ¿Lo reactivas en lugar de crearlo otra vez?')
            ->assertJsonPath('coincidencias.0.reactivar', route('departamentos.estado', $sistemas->id));

        // El servidor también lo rechaza al guardar
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'sistemas', 'todas_las_sedes' => 1])
            ->assertSessionHasErrors(['nombre' => 'Ya existe un departamento con ese nombre en esta empresa.']);
    }

    public function test_puestos_y_turnos_con_el_aviso_unico(): void
    {
        $guardia = $this->enEmpresa(fn () => Puesto::create(['nombre' => 'Guardia de Seguridad']));
        $matutino = $this->enEmpresa(fn () => Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'todas_las_sedes' => true]));

        $this->actingAs($this->admin)->getJson('/puestos/duplicado?campo=nombre&valor=guardia de seguridad')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.detalle', 'Sin departamento');
        $this->actingAs($this->admin)->getJson('/puestos/duplicado?campo=nombre&valor=Seguridad Guardia')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->admin)->getJson("/puestos/duplicado?campo=nombre&valor=Guardia de Seguridad&excluir={$guardia->id}")->assertJsonPath('estado', 'libre');

        $this->actingAs($this->admin)->getJson('/turnos/duplicado?campo=nombre&valor=MATUTINO')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.detalle', '07:00 a 15:00');
        $matutino->forceFill(['activo' => false])->save();
        $this->actingAs($this->admin)->getJson('/turnos/duplicado?campo=nombre&valor=Matutino')
            ->assertJsonPath('coincidencias.0.reactivar', route('turnos.estado', $matutino->id));
        $this->actingAs($this->admin)->patch(route('turnos.estado', $matutino->id), ['activo' => 1])->assertRedirect();
        $this->assertTrue($matutino->fresh()->activo);

        // Catálogos de toda la empresa: con alcance de sede no se modifican, así que no se pregunta
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->getJson('/turnos/duplicado?campo=nombre&valor=Matutino')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/puestos/duplicado?campo=nombre&valor=Guardia')->assertForbidden();
        // Otra empresa
        $intruso = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($intruso)->getJson('/turnos/duplicado?campo=nombre&valor=Matutino')->assertJsonPath('estado', 'libre');

        // Un solo mecanismo en las pantallas
        foreach (['departamentos', 'puestos', 'turnos'] as $modulo) {
            $this->actingAs($this->admin)->get('/'.$modulo)->assertOk()
                ->assertSee('data-duplicado="'.route($modulo.'.duplicado').'"', false)
                ->assertDontSee('data-nombres-existentes', false)->assertDontSee('data-aviso-nombre', false);
        }
    }

    // =============================================================== Usuarios

    public function test_usuarios_usuario_y_correo_unicos_con_aviso_en_vivo(): void
    {
        $dcanul = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $dcanul->forceFill(['username' => 'd.canul', 'email' => 'dcanul@hotel.mx', 'name' => 'Daniela Canul'])->save();
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Agente');
        $ajeno->forceFill(['username' => 'jperez', 'email' => 'jperez@otro.mx'])->save();

        $u = fn (string $campo, string $valor, array $extra = []) => $this->actingAs($this->admin)
            ->getJson('/usuarios/duplicado?'.http_build_query(['campo' => $campo, 'valor' => $valor] + $extra));

        $u('username', 'D.Canul')->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', '@d.canul')
            ->assertJsonPath('mensaje', 'Ese nombre de usuario ya lo usa la cuenta @d.canul. No se puede repetir.');
        // «dcanul» se confunde con «d.canul»: solo aviso
        $u('username', 'dcanul')->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', '@d.canul');
        $u('username', 'mrios')->assertJsonPath('estado', 'libre')->assertJsonPath('mensaje', 'Nombre de usuario disponible.');
        $u('username', 'd.canul', ['excluir' => $dcanul->id])->assertJsonPath('estado', 'libre');
        // De otra empresa: existe (es único en toda la plataforma) pero sin datos
        $u('username', 'jperez')->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias', [])
            ->assertJsonPath('mensaje', 'Ese nombre de usuario ya lo usa otra cuenta. Elige otro.');

        $u('email', 'DCANUL@hotel.mx')->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', '@d.canul');
        $u('email', 'jperez@otro.mx')->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias', []);
        $u('email', 'nuevo@hotel.mx')->assertJsonPath('estado', 'libre')->assertJsonPath('mensaje', 'Correo disponible.');
        $u('email', 'sinarroba')->assertJsonPath('estado', 'nada');

        // Inactiva: «Reactivar» (PATCH /usuarios/{id}/estado con activo=1)
        $dcanul->forceFill(['activo' => false])->save();
        $u('username', 'd.canul')->assertJsonPath('coincidencias.0.reactivar', route('usuarios.estado', $dcanul->id));

        // Sin permiso de usuarios: 403
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/usuarios/duplicado?campo=username&valor=d.canul')->assertForbidden();

        // La pantalla: usuario, correo y nombre con el aviso único
        $html = $this->actingAs($this->admin)->get('/usuarios')->assertOk()->getContent();
        $this->assertSame(6, substr_count($html, 'data-duplicado="'.route('usuarios.duplicado').'"'));
        $this->assertStringNotContainsString('data-homonimos', $html);
        // Al guardar el servidor sigue rechazando lo repetido
        $this->actingAs($this->admin)->post('/usuarios', ['name' => 'Otra Persona', 'username' => 'd.canul', 'email' => 'nueva@hotel.mx', 'password' => 'Segura1234',
            'rol_id' => Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->value('id')])
            ->assertSessionHasErrors(['username' => 'Ya existe otro usuario registrado con ese nombre de usuario.']);
    }
}
