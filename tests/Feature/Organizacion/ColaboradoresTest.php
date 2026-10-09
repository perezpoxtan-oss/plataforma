<?php

namespace Tests\Feature\Organizacion;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class ColaboradoresTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const CURP = 'GORL900101MQRMZR05';

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

    /**
     * @param  list<Sede>  $sedes  vacío = todas las sedes
     */
    private function depto(string $nombre, array $sedes = []): Departamento
    {
        return $this->enEmpresa(function () use ($nombre, $sedes) {
            $d = Departamento::create(['nombre' => $nombre, 'todas_las_sedes' => $sedes === []]);
            $d->sedes()->sync(array_map(fn ($s) => $s->id, $sedes));

            return $d;
        });
    }

    /**
     * @param  list<Departamento>  $departamentos  vacío = cualquiera
     */
    private function puesto(string $nombre, array $departamentos = []): Puesto
    {
        return $this->enEmpresa(function () use ($nombre, $departamentos) {
            $p = Puesto::create(['nombre' => $nombre]);
            $p->departamentos()->sync(array_map(fn ($d) => $d->id, $departamentos));

            return $p;
        });
    }

    private function colaborador(string $num): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::where('num_empleado', $num)->firstOrFail());
    }

    /**
     * Colaborador creado directo (sin pasar por la pantalla).
     *
     * @param  list<Sede>  $adicionales
     */
    private function crearColaborador(string $num, ?Sede $sede, array $adicionales = [], array $extra = [], ?Empresa $empresa = null): Colaborador
    {
        return $this->enEmpresa(function () use ($num, $sede, $adicionales, $extra) {
            $c = Colaborador::create(array_merge(['num_empleado' => $num, 'nombre' => 'Persona', 'apellido_paterno' => $num, 'sede_id' => $sede?->id], $extra));
            $c->sedesAdicionales()->sync(array_map(fn ($s) => $s->id, $adicionales));

            return $c;
        }, $empresa);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'num_empleado' => 'E-100',
            'nombre' => 'Laura',
            'apellido_paterno' => 'Gómez',
            'apellido_materno' => 'Ruiz',
            'telefono' => '(998) 123-4567',
            'sede_id' => $this->centro->id,
            'departamento_id' => null,
            'puesto_id' => null,
            'curp' => 'gorl900101mqrmzr05',
            'rfc' => 'GORL900101AB1',
            'nss' => '1234-5678-901',
            'fecha_nacimiento' => '1990-01-01',
            'lugar_nacimiento' => 'Quintana Roo',
            'nacionalidad' => 'Mexicana',
        ], $extra);
    }

    /**
     * Rol de prueba con los permisos indicados.
     *
     * @param  array<string, Alcance>  $permisos
     */
    private function rolCon(string $nombre, array $permisos, int $nivel = 40): Rol
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => $nombre, 'nivel_jerarquia' => $nivel]);
        $roles->sincronizarPermisos($sa, $rol, $permisos);

        return $rol;
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_normaliza_datos_audita_enmascarado_y_no_expone_datos_personales_en_la_lista(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos())
            ->assertRedirect('/colaboradores')
            ->assertSessionHas('ok', 'Colaborador «Laura Gómez Ruiz» creado correctamente.');

        $c = $this->colaborador('E-100');
        $this->assertSame($this->empresa->id, $c->empresa_id);
        $this->assertSame(self::CURP, $c->curp);
        $this->assertSame('12345678901', $c->nss);
        $this->assertSame('9981234567', $c->telefono);
        $this->assertSame('1990-01-01', $c->fecha_nacimiento->format('Y-m-d'));
        $this->assertSame($this->admin->id, $c->creado_por);

        $auditoria = Auditoria::where('evento', 'colaboradores.creado')->where('auditable_id', $c->id)->firstOrFail();
        $this->assertSame('**************ZR05', $auditoria->despues['curp']);
        $this->assertSame('*******8901', $auditoria->despues['nss']);
        $this->assertArrayNotHasKey('fecha_nacimiento', $auditoria->despues);
        $this->assertStringNotContainsString(self::CURP, json_encode($auditoria->despues));

        $this->actingAs($this->admin)->get('/colaboradores')->assertOk()
            ->assertSee('Laura Gómez Ruiz')->assertSee('#E-100')->assertSee('Nuevo Colaborador')
            ->assertSee('Sede: Sede CEN')
            ->assertDontSee(self::CURP)->assertDontSee('GORL900101AB1')->assertDontSee('12345678901');
    }

    public function test_num_empleado_unico_por_empresa_y_se_permite_en_otra(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['num_empleado' => ' e-100 ', 'curp' => null, 'rfc' => null, 'nss' => null]))
            ->assertSessionHasErrors(['num_empleado' => 'Ya existe otro colaborador registrado con ese número de empleado.']);

        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeOtra = $this->crearSede($otra, 'OTR');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        // Mismo número, CURP, RFC y NSS en otra empresa: permitido (SEGCAT los tenía únicos en toda la base)
        $this->actingAs($adminOtra)->post('/colaboradores', $this->datos(['sede_id' => $sedeOtra->id]))->assertSessionHasNoErrors();
        $this->assertSame(2, Colaborador::withoutGlobalScopes()->where('num_empleado', 'E-100')->count());
    }

    public function test_formatos_y_unicidad_de_curp_rfc_y_nss(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['curp' => 'ABC123']))
            ->assertSessionHasErrors(['curp' => 'El CURP no tiene el formato correcto (18 caracteres, ej. ABCD123456HDFXYZ01).']);
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['rfc' => '12GORL900101']))->assertSessionHasErrors('rfc');
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['nss' => '1234567890']))
            ->assertSessionHasErrors(['nss' => 'El NSS debe tener exactamente 11 dígitos.']);
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['telefono' => '12345']))->assertSessionHasErrors('telefono');
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['lugar_nacimiento' => 'Narnia']))->assertSessionHasErrors('lugar_nacimiento');
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['fecha_nacimiento' => now()->addDay()->format('Y-m-d')]))->assertSessionHasErrors('fecha_nacimiento');
        // RFC de persona moral (12) con Ñ y minúsculas: válido
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['rfc' => 'ñgo900101ab1']))->assertSessionHasNoErrors();
        $this->assertSame('ÑGO900101AB1', $this->colaborador('E-100')->rfc);

        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['num_empleado' => 'E-200', 'rfc' => null]))
            ->assertSessionHasErrors(['curp' => 'Ya existe otro colaborador registrado con ese CURP.', 'nss' => 'Ya existe otro colaborador registrado con ese NSS.']);
        // Opcionales: sin CURP/RFC/NSS no chocan entre sí (en SEGCAT chocaban como '')
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['num_empleado' => 'E-300', 'curp' => '', 'rfc' => '', 'nss' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['num_empleado' => 'E-301', 'curp' => '', 'rfc' => '', 'nss' => '']))->assertSessionHasNoErrors();
    }

    public function test_puesto_y_departamento_deben_ser_consistentes_y_de_la_empresa(): void
    {
        $seguridad = $this->depto('Seguridad');
        $recepcion = $this->depto('Recepción');
        $club = $this->depto('Club de Playa', [$this->playa]);
        $recepcionista = $this->puesto('Recepcionista', [$recepcion]);
        $gerente = $this->puesto('Gerente');
        $base = ['curp' => null, 'rfc' => null, 'nss' => null];

        $this->actingAs($this->admin)->post('/colaboradores', $this->datos($base + ['departamento_id' => $seguridad->id, 'puesto_id' => $recepcionista->id]))
            ->assertSessionHasErrors(['puesto_id' => 'El puesto «Recepcionista» no aplica en el departamento elegido.']);
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos($base + ['departamento_id' => $club->id]))
            ->assertSessionHasErrors(['departamento_id' => 'El departamento «Club de Playa» no aplica en la sede elegida.']);

        $ajeno = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Ajeno']), $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos($base + ['departamento_id' => $ajeno->id]))->assertSessionHasErrors('departamento_id');

        // Un puesto sin departamentos ligados aplica en cualquiera
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos($base + ['departamento_id' => $seguridad->id, 'puesto_id' => $gerente->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos($base + ['num_empleado' => 'E-2', 'sede_id' => $this->playa->id, 'departamento_id' => $club->id]))->assertSessionHasNoErrors();

        $c = $this->colaborador('E-100');
        $this->assertSame([$seguridad->id, $gerente->id], [$c->departamento_id, $c->puesto_id]);
    }

    // ------------------------------------------------------- Alcance de sede

    public function test_con_alcance_de_sede_solo_ve_y_registra_en_sus_sedes(): void
    {
        $this->rolCon('Gerente', [
            'colaboradores.ver' => Alcance::Sede, 'colaboradores.crear' => Alcance::Sede,
            'colaboradores.editar' => Alcance::Sede, 'colaboradores.eliminar' => Alcance::Sede,
        ]);
        $gerente = $this->crearUsuario($this->empresa, 'Gerente', $this->centro);

        $this->crearColaborador('C-1', $this->centro);
        $deplaya = $this->crearColaborador('P-1', $this->playa);
        $this->crearColaborador('P-2', $this->playa, [$this->centro]);
        $this->crearColaborador('X-1', null);
        $this->flushSession();

        $this->actingAs($gerente)->get('/colaboradores')->assertOk()
            ->assertSee('#C-1')->assertSee('#P-2')->assertDontSee('#P-1')->assertDontSee('#X-1')
            ->assertSee('-- Selecciona tu sede --')->assertDontSee('Corporativo (todas las sedes) --');

        $sin = ['curp' => null, 'rfc' => null, 'nss' => null];
        $this->actingAs($gerente)->post('/colaboradores', $this->datos($sin + ['sede_id' => $this->playa->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($gerente)->post('/colaboradores', $this->datos($sin + ['sede_id' => null]))->assertSessionHasErrors('sede_id');
        $this->actingAs($gerente)->post('/colaboradores', $this->datos($sin))->assertSessionHasNoErrors();

        // Fuera de su alcance: 404
        $this->actingAs($gerente)->put("/colaboradores/{$deplaya->id}", ['num_empleado' => 'P-1', 'nombre' => 'X', 'apellido_paterno' => 'Y', 'sede_id' => $this->playa->id])->assertNotFound();
        $this->actingAs($gerente)->patch("/colaboradores/{$deplaya->id}/estado", ['activo' => '0'])->assertNotFound();

        // Visible por sede adicional: puede editarlo sin mover su sede física, pero no moverlo a otra ajena
        $p2 = $this->colaborador('P-2');
        $this->actingAs($gerente)->put("/colaboradores/{$p2->id}", ['num_empleado' => 'P-2', 'nombre' => 'Rocío', 'apellido_paterno' => 'Pech', 'sede_id' => $this->playa->id])->assertSessionHasNoErrors();
        $this->assertSame('Rocío', $p2->fresh()->nombre);
        $this->actingAs($gerente)->put("/colaboradores/{$p2->id}", ['num_empleado' => 'P-2', 'nombre' => 'Rocío', 'apellido_paterno' => 'Pech', 'sede_id' => null])->assertSessionHasErrors('sede_id');
    }

    public function test_sedes_adicionales_sin_repetir_la_fisica_y_respetando_el_alcance(): void
    {
        $tercera = $this->crearSede($this->empresa, 'TER');
        $ajena = $this->crearSede($this->crearEmpresa('Hotel Dos'), 'OTR');
        $c = $this->crearColaborador('C-1', $this->centro);

        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}/sedes", ['sedes' => [$this->centro->id, $this->playa->id, $tercera->id, $ajena->id]])
            ->assertSessionHas('ok', 'Sedes adicionales de «Persona C-1» actualizadas.');
        $ids = fn () => $this->enEmpresa(fn () => $c->sedesAdicionales()->orderBy('sedes.id')->pluck('sedes.id')->all());
        $this->assertSame([$this->playa->id, $tercera->id], $ids());
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.sedes', 'auditable_id' => $c->id]);

        // Quien solo tiene la sede de playa no puede quitar la tercera (fuera de su alcance)
        $this->rolCon('Gerente', ['colaboradores.ver' => Alcance::Sede, 'colaboradores.editar' => Alcance::Sede]);
        $gerente = $this->crearUsuario($this->empresa, 'Gerente', $this->playa);
        $this->flushSession();
        $this->actingAs($gerente)->put("/colaboradores/{$c->id}/sedes", ['sedes' => []])->assertSessionHas('ok');
        $this->assertSame([$tercera->id], $ids());

        // Si la sede física pasa a ser una adicional, deja de repetirse
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}/sedes", ['sedes' => [$this->playa->id, $tercera->id]]);
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", ['num_empleado' => 'C-1', 'nombre' => 'Persona', 'apellido_paterno' => 'C-1', 'sede_id' => $this->playa->id])->assertSessionHasNoErrors();
        $this->assertSame([$tercera->id], $ids());

        $this->actingAs($this->admin)->get('/colaboradores')->assertSee('+1 sede adicional')->assertSee('Gestionar sedes adicionales');
    }

    // ---------------------------------------------------- Datos personales

    public function test_datos_personales_solo_con_su_permiso(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos())->assertSessionHasNoErrors();
        $c = $this->colaborador('E-100');

        // El Administrador los recibe bajo demanda, sin caché
        $this->actingAs($this->admin)->getJson("/colaboradores/{$c->id}/datos-personales")->assertOk()
            ->assertJson(['curp' => self::CURP, 'nss' => '12345678901', 'fecha_nacimiento' => '1990-01-01', 'lugar_nacimiento' => 'Quintana Roo'])
            ->assertHeader('Cache-Control', 'no-store, private');

        // Recursos Humanos sin el permiso: edita, pero no ve ni cambia los datos personales
        $this->rolCon('Capturista', ['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa, 'colaboradores.editar' => Alcance::Empresa]);
        $capturista = $this->crearUsuario($this->empresa, 'Capturista');
        $this->flushSession();

        $this->actingAs($capturista)->get('/colaboradores')->assertOk()
            ->assertDontSee('name="curp"', false)->assertDontSee('data-url-datos', false)
            ->assertSee('solo los ve y captura quien tiene el permiso «Datos personales»');
        $this->actingAs($capturista)->getJson("/colaboradores/{$c->id}/datos-personales")->assertForbidden();
        $this->actingAs($capturista)->put("/colaboradores/{$c->id}", $this->datos(['nombre' => 'Laura Elena', 'curp' => 'XXXX000000HXXXXX00', 'nss' => null]))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame(['Laura Elena', self::CURP, '12345678901'], [$c->nombre, $c->curp, $c->nss]);

        // Si al editar no llegan los datos personales (no se alcanzaron a cargar), no se borran
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", ['num_empleado' => 'E-100', 'nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $this->assertSame(self::CURP, $c->fresh()->curp);

        // Cambiarlos queda en la bitácora solo por nombre de campo
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['curp' => null, 'direccion_completa' => 'Calle 1, Cancún']))->assertSessionHasNoErrors();
        $evento = Auditoria::where('evento', 'colaboradores.actualizado')->latest('id')->firstOrFail();
        $this->assertSame(['curp', 'direccion_completa'], $evento->despues['datos_personales_modificados']);
        $this->assertStringNotContainsString('Calle 1', json_encode($evento->despues));

        // El Director solo consulta: no recibe datos personales
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->getJson("/colaboradores/{$c->id}/datos-personales")->assertForbidden();
        $this->assertTrue($this->admin->can('colaboradores.datos_personales'));
    }

    // ----------------------------------------------------- Registro rápido

    public function test_registro_rapido_responde_json(): void
    {
        $puesto = $this->puesto('Agente de Seguridad');

        $this->actingAs($this->admin)->postJson('/colaboradores/rapido', [
            'num_empleado' => 'r-1', 'nombre' => 'Mario', 'apellido_paterno' => 'Kú', 'sede_id' => $this->playa->id,
            'puesto_id' => $puesto->id, 'curp' => self::CURP,
        ])->assertCreated()->assertJson(['ok' => true, 'colaborador' => [
            'num_empleado' => 'R-1', 'nombre_completo' => 'Mario Kú', 'puesto' => 'Agente de Seguridad', 'departamento' => null, 'sede' => 'Sede PLA',
        ]]);
        // El registro rápido nunca guarda datos personales
        $this->assertNull($this->colaborador('R-1')->curp);

        $this->actingAs($this->admin)->postJson('/colaboradores/rapido', ['num_empleado' => 'R-2', 'nombre' => 'Ana', 'apellido_paterno' => 'Uc'])
            ->assertStatus(422)->assertJson(['ok' => false, 'mensaje' => 'Elige la sede del colaborador.'])->assertJsonValidationErrors('sede_id', 'errores');
        // Aunque el navegador no pida JSON, la respuesta de error es JSON
        $this->actingAs($this->admin)->post('/colaboradores/rapido', ['num_empleado' => 'R-1', 'nombre' => 'Otro', 'apellido_paterno' => 'X', 'sede_id' => $this->playa->id])
            ->assertStatus(422)->assertJsonPath('errores.num_empleado.0', 'Ya existe otro colaborador registrado con ese número de empleado.');

        $sinRol = $this->crearUsuario($this->empresa);
        $this->actingAs($sinRol)->postJson('/colaboradores/rapido', ['num_empleado' => 'R-3'])->assertForbidden();

        // El parcial para otros módulos se dibuja para quien puede crear
        $this->actingAs($this->admin);
        $html = $this->enEmpresa(fn () => view('organizacion.colaboradores._registro-rapido', ['sedeSugerida' => $this->playa->id])->render());
        $this->assertStringContainsString('Registro Rápido de Colaborador', $html);
        $this->assertStringContainsString('data-registro-rapido-colaborador', $html);
    }

    // ------------------------------------------------------------ Búsqueda

    public function test_buscar_por_numero_o_nombre_dentro_de_la_empresa_y_sus_sedes(): void
    {
        $this->crearColaborador('1001', $this->centro, [], ['nombre' => 'Roberto', 'apellido_paterno' => 'Hernández']);
        $this->crearColaborador('1002', $this->playa, [], ['nombre' => 'Rosa', 'apellido_paterno' => 'Poot']);
        $this->crearColaborador('1003', $this->centro, [], ['nombre' => 'Rodrigo', 'apellido_paterno' => 'Baja', 'activo' => false]);
        $this->crearColaborador('2001', null, [], ['nombre' => 'Rogelio', 'apellido_paterno' => 'Ajeno'], $this->crearEmpresa('Hotel Dos'));

        $this->actingAs($this->admin)->getJson('/colaboradores/buscar?q=r')->assertOk()->assertExactJson(['resultados' => [], 'todas_ya_tienen_usuario' => false]);
        $res = $this->actingAs($this->admin)->getJson('/colaboradores/buscar?q=ro')->assertOk()->json('resultados');
        $this->assertSame(['Roberto Hernández', 'Rosa Poot'], array_column($res, 'nombre_completo'));
        $this->assertArrayNotHasKey('curp', $res[0]);
        $this->assertSame('1001', $this->actingAs($this->admin)->getJson('/colaboradores/buscar?q=100')->json('resultados.0.num_empleado'));
        $this->assertCount(1, $this->actingAs($this->admin)->getJson('/colaboradores/buscar?q=rosa%20po')->json('resultados'));

        // Ya vinculado a una cuenta: se excluye y se avisa
        $roberto = $this->colaborador('1001');
        $this->crearUsuario($this->empresa, 'Agente')->forceFill(['colaborador_id' => $roberto->id])->save();
        $this->actingAs($this->admin)->getJson('/colaboradores/buscar?q=roberto&sin_usuario=1')
            ->assertExactJson(['resultados' => [], 'todas_ya_tienen_usuario' => true]);

        // Alcance de sede (Jefe de seguridad: usuarios.ver/desbloquear de su sede; se le da usuarios.crear de sede)
        $this->rolCon('Gerente', ['usuarios.ver' => Alcance::Sede, 'usuarios.crear' => Alcance::Sede]);
        $gerente = $this->crearUsuario($this->empresa, 'Gerente', $this->playa);
        $this->flushSession();
        $this->assertSame(['Rosa Poot'], array_column($this->actingAs($gerente)->getJson('/colaboradores/buscar?q=ro')->json('resultados'), 'nombre_completo'));

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->getJson('/colaboradores/buscar?q=ro')->assertForbidden();
    }

    // ------------------------------------------------- Usuarios ↔ Colaborador

    public function test_vincular_una_cuenta_con_su_colaborador(): void
    {
        $c = $this->crearColaborador('1001', $this->centro, [], ['nombre' => 'Mariana', 'apellido_paterno' => 'López']);
        $agente = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->firstOrFail();
        $cuenta = fn (array $extra) => array_merge([
            'name' => 'Mariana López', 'numero_colaborador' => 'cualquiera', 'username' => 'mariana', 'email' => 'mariana@ejemplo.mx',
            'password' => 'Clave1234', 'rol_id' => $agente->id, 'sede_id' => null, 'colaborador_id' => $c->id,
        ], $extra);

        $this->actingAs($this->admin)->post('/usuarios', $cuenta([]))->assertSessionHasNoErrors();
        $usuario = User::where('username', 'mariana')->firstOrFail();
        $this->assertSame([$c->id, '1001'], [$usuario->colaborador_id, $usuario->numero_colaborador]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.creado', 'auditable_id' => $usuario->id]);
        $this->actingAs($this->admin)->get('/usuarios')->assertSee('Vinculado a Colaborador (activo)');

        // Un colaborador, una cuenta
        $this->actingAs($this->admin)->post('/usuarios', $cuenta(['username' => 'otra', 'email' => 'otra@ejemplo.mx', 'numero_colaborador' => null]))
            ->assertSessionHasErrors(['colaborador_id' => 'Ese colaborador ya tiene una cuenta de usuario: búscala en la lista en vez de crear otra.']);

        // De otra empresa o dado de baja: no
        $ajeno = $this->crearColaborador('9', null, [], [], $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->admin)->post('/usuarios', $cuenta(['username' => 'otra', 'email' => 'otra@ejemplo.mx', 'numero_colaborador' => null, 'colaborador_id' => $ajeno->id]))
            ->assertSessionHasErrors(['colaborador_id' => 'El colaborador no existe en esta empresa.']);
        $baja = $this->crearColaborador('1002', $this->centro, [], ['activo' => false]);
        $this->actingAs($this->admin)->post('/usuarios', $cuenta(['username' => 'otra', 'email' => 'otra@ejemplo.mx', 'numero_colaborador' => null, 'colaborador_id' => $baja->id]))
            ->assertSessionHasErrors('colaborador_id');

        // Editar conserva el vínculo aunque el colaborador ya esté de baja; vaciarlo lo quita
        $this->enEmpresa(fn () => $c->forceFill(['activo' => false])->save());
        $this->actingAs($this->admin)->put("/usuarios/{$usuario->id}", $cuenta(['password' => '', 'activo' => '1']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/usuarios')->assertSee('(¡inactivo! revisar)');
        $this->actingAs($this->admin)->put("/usuarios/{$usuario->id}", $cuenta(['password' => '', 'activo' => '1', 'colaborador_id' => '']))->assertSessionHasNoErrors();
        $this->assertNull($usuario->fresh()->colaborador_id);
    }

    // ------------------------------------------------- Empresa y permisos

    public function test_baja_logica_y_reingreso(): void
    {
        $c = $this->crearColaborador('C-1', $this->centro);

        $this->actingAs($this->admin)->patch("/colaboradores/{$c->id}/estado", ['activo' => '0'])
            ->assertSessionHas('aviso', 'Colaborador «Persona C-1» dado de baja. Puedes reactivarlo con el mismo botón cuando quieras.');
        $this->assertFalse($c->fresh()->activo);
        $this->actingAs($this->admin)->get('/colaboradores')->assertSee('BAJA')->assertSee('¿Reingresar a este colaborador?');
        $this->actingAs($this->admin)->patch("/colaboradores/{$c->id}/estado", ['activo' => '1'])->assertSessionHas('ok', 'Colaborador «Persona C-1» reingresado.');
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.desactivado', 'auditable_id' => $c->id]);

        // Editar nunca cambia el estado (en SEGCAT el formulario de edición daba de baja sin el permiso "eliminar")
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", ['num_empleado' => 'C-1', 'nombre' => 'Persona', 'apellido_paterno' => 'C-1', 'activo' => '0']);
        $this->assertTrue($c->fresh()->activo);
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $c = $this->crearColaborador('C-1', $this->centro, [], ['nombre' => 'Exclusivo']);
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');

        $this->actingAs($ajeno)->get('/colaboradores')->assertOk()->assertDontSee('Exclusivo');
        $this->actingAs($ajeno)->put("/colaboradores/{$c->id}", ['num_empleado' => 'X', 'nombre' => 'X', 'apellido_paterno' => 'X'])->assertNotFound();
        $this->actingAs($ajeno)->patch("/colaboradores/{$c->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->actingAs($ajeno)->put("/colaboradores/{$c->id}/sedes", ['sedes' => []])->assertNotFound();
        $this->actingAs($ajeno)->getJson("/colaboradores/{$c->id}/datos-personales")->assertNotFound();
        $this->actingAs($ajeno)->getJson('/colaboradores/buscar?q=exclu')->assertJsonCount(0, 'resultados');
        // La sede de otra empresa no se acepta
        $this->actingAs($ajeno)->post('/colaboradores', $this->datos())->assertSessionHasErrors('sede_id');
    }

    public function test_datos_demo_con_formatos_validos_y_cuentas_vinculadas(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $todos = $this->enEmpresa(fn () => Colaborador::with('sedesAdicionales')->get(), $demo);

        // Más dos altas provisionales de la caseta (sin datos personales), creadas una sola vez
        $provisionales = $todos->where('provisional', true);
        $this->assertSame(['Beto', 'Jorge'], $provisionales->pluck('nombre')->sort()->values()->all());
        $todos = $todos->where('provisional', false);
        // Recepción (ADR-0007): el candidato contratado del demo no trae datos personales completos
        $todos = $todos->reject(fn ($c) => $c->num_empleado === '2001');

        $this->assertCount(15, $todos);
        $this->assertSame(['1013'], $todos->where('activo', false)->pluck('num_empleado')->values()->all());
        $this->assertSame(2, $todos->pluck('sede_id')->filter()->unique()->count());
        $this->assertCount(1, $todos->filter(fn ($c) => $c->sedesAdicionales->isNotEmpty()));
        foreach ($todos as $c) {
            $this->assertMatchesRegularExpression(Colaborador::CURP, $c->curp);
            $this->assertMatchesRegularExpression(Colaborador::RFC, $c->rfc);
            $this->assertMatchesRegularExpression(Colaborador::NSS, $c->nss);
        }
        $this->assertSame('1003', User::where('username', 'agente.demo')->firstOrFail()->numero_colaborador);
        $this->assertSame($todos->firstWhere('num_empleado', '1001')->id, User::where('username', 'admin.demo')->value('colaborador_id'));
    }

    public function test_agente_sin_permiso_y_superadmin_elige_empresa(): void
    {
        // La caseta consulta su sede y solo hace altas provisionales
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/colaboradores')->assertOk()->assertSee('Alta provisional')->assertDontSee('Nuevo Colaborador');
        $this->actingAs($agente)->post('/colaboradores', $this->datos())->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa))->get('/colaboradores')->assertForbidden();

        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->get('/colaboradores')->assertOk()->assertDontSee('Nuevo Colaborador')->assertDontSee('data-accion="editar-registro"', false);

        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/colaboradores')->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/colaboradores')->assertSee('Nuevo Colaborador');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->post('/colaboradores', $this->datos())->assertSessionHasNoErrors();
        $this->assertSame($this->empresa->id, $this->colaborador('E-100')->empresa_id);
    }

    public function test_boton_codigo_e_identificacion_en_la_lista(): void
    {
        $c = $this->crearColaborador('1001', $this->centro);
        $this->enEmpresa(fn () => $c->forceFill(['etiqueta_nfc' => 'A1B2C3D4'])->save());
        $c = $c->fresh();
        $this->assertNotEmpty($c->codigo_qr);

        $html = $this->actingAs($this->admin)->get('/colaboradores')->assertOk()
            ->assertSee('data-ver-identificacion', false)->assertSee('id="dialogoIdentificacion"', false)
            ->assertSee(route('identificacion.qr', ['colaborador', $c->id]))
            ->assertSee(route('lector.ir', $c->codigo_qr))
            ->assertSee(route('identificacion.etiqueta', ['colaborador', $c->id]))->getContent();
        $this->assertStringContainsString('A1B2C3D4', $html);
        // El QR que abre el diálogo responde
        $this->actingAs($this->admin)->get(route('identificacion.qr', ['colaborador', $c->id]))->assertOk();

        // Quien solo consulta ve el código pero no asigna la etiqueta
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/colaboradores')->assertOk()->assertSee(route('identificacion.qr', ['colaborador', $c->id]))
            ->assertDontSee(route('identificacion.etiqueta', ['colaborador', $c->id]));
    }
}
