<?php

namespace Tests\Feature\Administracion;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Menu\ConstructorMenu;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class RolesYPermisosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function rol(string $nombre, ?Empresa $empresa = null): Rol
    {
        return Rol::where('empresa_id', ($empresa ?? $this->empresa)->id)->where('nombre', $nombre)->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function permisosDe(Rol $rol): array
    {
        return DB::table('rol_permisos as rp')
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('rp.rol_id', $rol->id)
            ->get(['m.clave as modulo', 'a.clave as accion', 'rp.alcance'])
            ->mapWithKeys(fn ($p) => [$p->modulo.'.'.$p->accion => $p->alcance])
            ->sortKeys()
            ->all();
    }

    // ---------------------------------------------------------------- Roles

    public function test_el_administrador_ve_los_roles_de_su_empresa(): void
    {
        $this->actingAs($this->admin)->get('/roles')
            ->assertOk()
            ->assertSee('Roles y Jerarquía')
            ->assertSee('Supervisor')
            ->assertSee('Nivel 60')
            ->assertSee('Nuevo Rol')
            ->assertDontSee('Empresa de trabajo');
    }

    public function test_un_agente_no_entra_a_roles_ni_permisos(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->actingAs($agente)->get('/roles')->assertForbidden();
        $this->actingAs($agente)->get('/permisos')->assertForbidden();
    }

    public function test_crea_un_rol_por_debajo_de_su_nivel(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Coordinador', 'descripcion' => 'Turno nocturno', 'nivel_jerarquia' => 40])
            ->assertRedirect('/roles')
            ->assertSessionHas('ok');

        $rol = $this->rol('Coordinador');
        $this->assertSame(40, $rol->nivel_jerarquia);
        $this->assertSame($this->admin->id, (int) $rol->creado_por);
        $this->assertDatabaseHas('auditoria', ['evento' => 'roles.creado', 'auditable_id' => $rol->id]);
    }

    public function test_no_crea_roles_de_su_nivel_o_superior(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Jefe máximo', 'nivel_jerarquia' => 5])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('roles', ['nombre' => 'Jefe máximo']);
    }

    public function test_nombre_y_nivel_no_se_repiten_en_la_empresa(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Supervisor', 'nivel_jerarquia' => 45])
            ->assertSessionHasErrors('nombre');

        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Otro', 'nivel_jerarquia' => 50])
            ->assertSessionHasErrors('nivel_jerarquia');
    }

    public function test_edita_un_rol_inferior_pero_no_el_propio(): void
    {
        $supervisor = $this->rol('Supervisor');

        $this->actingAs($this->admin)->put("/roles/{$supervisor->id}", [
            'nombre' => 'Supervisor de turno', 'descripcion' => 'x', 'nivel_jerarquia' => 55, 'activo' => '1',
        ])->assertSessionHas('ok');
        $this->assertSame('Supervisor de turno', $supervisor->fresh()->nombre);

        // No puede subirlo por encima de su propio nivel
        $this->actingAs($this->admin)->put("/roles/{$supervisor->id}", [
            'nombre' => 'Supervisor de turno', 'nivel_jerarquia' => 5, 'activo' => '1',
        ])->assertSessionHas('error');
        $this->assertSame(55, $supervisor->fresh()->nivel_jerarquia);

        $propio = $this->rol('Administrador');
        $this->actingAs($this->admin)->put("/roles/{$propio->id}", [
            'nombre' => 'Admin total', 'nivel_jerarquia' => 10, 'activo' => '1',
        ])->assertSessionHas('error');
        $this->assertSame('Administrador', $propio->fresh()->nombre);
    }

    public function test_no_toca_roles_de_otra_empresa(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->rol('Supervisor', $otra);

        $this->actingAs($this->admin)->put("/roles/{$ajeno->id}", ['nombre' => 'X', 'nivel_jerarquia' => 70])->assertNotFound();
        $this->actingAs($this->admin)->delete("/roles/{$ajeno->id}")->assertNotFound();
        $this->actingAs($this->admin)->put("/permisos/{$ajeno->id}", ['permisos' => []])->assertNotFound();
    }

    public function test_no_elimina_un_rol_con_usuarios(): void
    {
        $this->crearUsuario($this->empresa, 'Agente');
        $agente = $this->rol('Agente');

        $this->actingAs($this->admin)->delete("/roles/{$agente->id}")->assertSessionHas('error');
        $this->assertNotNull($agente->fresh());

        $supervisor = $this->rol('Supervisor');
        $this->actingAs($this->admin)->delete("/roles/{$supervisor->id}")->assertSessionHas('ok');
        $this->assertNull($supervisor->fresh());
    }

    public function test_el_superadmin_trabaja_con_plantillas_o_con_la_empresa_que_elija(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->get('/roles')->assertOk()
            ->assertSee('Empresa de trabajo')
            ->assertSee('Plantillas que recibe cada empresa nueva');

        $this->actingAs($sa)->from('/roles')->post('/empresa-activa', ['empresa_id' => $this->empresa->id])
            ->assertRedirect('/roles');
        $this->assertSame($this->empresa->id, session(EmpresaDeTrabajo::SESION));

        $this->actingAs($sa)->post('/roles', ['nombre' => 'Auditor externo', 'nivel_jerarquia' => 90])->assertSessionHas('ok');
        $this->assertNotNull(Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Auditor externo')->first());
    }

    public function test_cambiar_de_empresa_no_redirige_a_otro_sitio(): void
    {
        $this->actingAs($this->crearSuperadmin())
            ->withHeader('referer', 'https://sitio-malicioso.example/x')
            ->post('/empresa-activa', ['empresa_id' => ''])
            ->assertRedirect(route('panel'));
    }

    public function test_solo_el_superadmin_cambia_de_empresa(): void
    {
        $this->actingAs($this->admin)->post('/empresa-activa', ['empresa_id' => $this->empresa->id])->assertForbidden();
    }

    // ------------------------------------------------------------- Permisos

    public function test_la_matriz_muestra_modulos_y_acciones_del_rol(): void
    {
        $agente = $this->rol('Agente');

        $this->actingAs($this->admin)->get('/permisos?rol='.$agente->id)
            ->assertOk()
            ->assertSee('Permisos por Rol')
            ->assertSee('Bitácora de accesos')
            ->assertSee('Lost &amp; Found', false)
            ->assertSee('Toda la empresa')
            ->assertSee('Guardar Permisos de este Rol');
    }

    public function test_abre_en_el_primer_rol_que_puede_modificar(): void
    {
        $this->actingAs($this->admin)->get('/permisos')
            ->assertOk()
            ->assertSee('Guardar Permisos de este Rol')
            ->assertDontSee('puedes consultarlo, pero no modificarlo');

        $propio = $this->rol('Administrador');
        $this->actingAs($this->admin)->get('/permisos?rol='.$propio->id)
            ->assertSee('puedes consultarlo, pero no modificarlo')
            ->assertDontSee('Guardar Permisos de este Rol');
    }

    public function test_guarda_permisos_y_ver_se_agrega_solo(): void
    {
        $agente = $this->rol('Agente');

        $this->actingAs($this->admin)->put("/permisos/{$agente->id}", [
            'permisos' => [
                'accesos' => ['acciones' => ['crear', 'aprobar'], 'alcance' => 'empresa'],
                'llaves' => ['acciones' => ['ver'], 'alcance' => 'propios'],
                'inventado' => ['acciones' => ['ver'], 'alcance' => 'sede'],
            ],
        ])->assertRedirect('/permisos?rol='.$agente->id)->assertSessionHas('ok');

        $this->assertSame([
            'accesos.aprobar' => 'empresa',
            'accesos.crear' => 'empresa',
            'accesos.ver' => 'empresa',
            'llaves.ver' => 'propios',
        ], collect($this->permisosDe($agente))->sortKeys()->all());

        $this->assertDatabaseHas('auditoria', ['evento' => 'permisos.rol_actualizado', 'auditable_id' => $agente->id]);
    }

    public function test_los_cambios_aplican_de_inmediato(): void
    {
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $agente = $this->rol('Agente');
        $this->assertTrue($usuario->can('accesos.ver'));

        $this->actingAs($this->admin)->put("/permisos/{$agente->id}", ['permisos' => []]);

        $this->app->forgetScopedInstances();
        $this->assertFalse($usuario->fresh()->can('accesos.ver'));
    }

    public function test_no_otorga_lo_que_no_tiene_pero_conserva_lo_existente(): void
    {
        // Coordinador (nivel 40): administra permisos, pero en accesos solo tiene "ver" de su sede
        $coordinador = app(AdministradorRoles::class)->crearRol($this->crearSuperadmin(), $this->empresa->id, ['nombre' => 'Coordinador', 'nivel_jerarquia' => 40]);
        app(AdministradorRoles::class)->sincronizarPermisos($this->crearSuperadmin(), $coordinador, [
            'permisos.ver' => Alcance::Empresa, 'permisos.editar' => Alcance::Empresa, 'accesos.ver' => Alcance::Sede,
        ]);
        $usuario = $this->crearUsuario($this->empresa, 'Coordinador');
        $agente = $this->rol('Agente');
        $antes = $this->permisosDe($agente);

        // Otorgar algo que no tiene
        $this->actingAs($usuario)->put("/permisos/{$agente->id}", [
            'permisos' => ['usuarios' => ['acciones' => ['ver'], 'alcance' => 'sede']],
        ])->assertSessionHas('error');

        // Ampliar el alcance de algo que tiene solo en su sede
        $this->actingAs($usuario)->put("/permisos/{$agente->id}", [
            'permisos' => ['accesos' => ['acciones' => ['ver'], 'alcance' => 'empresa']],
        ])->assertSessionHas('error');

        $this->assertSame($antes, $this->permisosDe($agente));

        // Lo que el Agente ya tenía (y el Coordinador no) se queda al guardar sin cambiarlo
        $payload = collect($antes)->keys()
            ->groupBy(fn ($clave) => explode('.', $clave)[0])
            ->map(fn ($claves, $modulo) => [
                'acciones' => $claves->map(fn ($c) => explode('.', $c)[1])->all(),
                'alcance' => 'sede',
            ])->all();

        $this->actingAs($usuario)->put("/permisos/{$agente->id}", ['permisos' => $payload])->assertSessionHas('ok');
        $this->assertSame($antes, $this->permisosDe($agente));
    }

    public function test_los_modulos_no_contratados_conservan_sus_permisos(): void
    {
        $agente = $this->rol('Agente');
        $llaves = Modulo::where('clave', 'llaves')->value('id');
        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $llaves)->update(['activo' => false]);
        $teniaLlaves = collect($this->permisosDe($agente))->keys()->filter(fn ($c) => str_starts_with($c, 'llaves.'))->values();
        $this->assertNotEmpty($teniaLlaves);

        $this->actingAs($this->admin)->get('/permisos?rol='.$agente->id)->assertDontSee('Catálogo de llaves');
        $this->actingAs($this->admin)->put("/permisos/{$agente->id}", ['permisos' => []])->assertSessionHas('ok');

        $this->assertEquals($teniaLlaves, collect($this->permisosDe($agente))->keys()->values());
    }

    public function test_roles_y_permisos_ya_no_salen_como_en_migracion(): void
    {
        $items = collect(app(ConstructorMenu::class)->para($this->admin))
            ->flatMap(fn ($m) => collect($m['secciones'])->flatten(1))->keyBy('clave');

        $this->assertTrue($items['roles']['disponible']);
        $this->assertSame(route('roles.index'), $items['roles']['url']);
        $this->assertSame(route('permisos.index'), $items['permisos']['url']);
    }
}
