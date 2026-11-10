<?php

namespace Tests\Feature\Organizacion;

use App\Models\Empresa;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class TurnosTest extends TestCase
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

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function turno(string $nombre): Turno
    {
        return $this->enEmpresa(fn () => Turno::where('nombre', $nombre)->firstOrFail());
    }

    /** @return list<int> */
    private function sedesDe(Turno $turno): array
    {
        return $this->enEmpresa(fn () => $turno->sedes()->orderBy('sedes.id')->pluck('sedes.id')->all());
    }

    private function usuarioDeSede(Sede $sede, array $permisos): User
    {
        $sa = $this->crearSuperadmin();
        $rol = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente de sede', 'nivel_jerarquia' => 40]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $rol, array_fill_keys($permisos, Alcance::Sede));
        $usuario = $this->crearUsuario($this->empresa, 'Gerente de sede', $sede);
        $this->flushSession();

        return $usuario;
    }

    public function test_alta_en_todas_las_sedes_y_nombre_unico_sin_importar_mayusculas(): void
    {
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => '  Matutino ', 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'todas_las_sedes' => '1'])
            ->assertRedirect('/turnos')->assertSessionHas('ok', 'Turno «Matutino» creado correctamente.');

        $matutino = $this->turno('Matutino');
        $this->assertTrue($matutino->todas_las_sedes);
        $this->assertSame('07:00', $matutino->inicio());
        $this->assertSame('8 h', $matutino->duracion());
        $this->assertFalse($matutino->cruzaMedianoche());
        $this->assertDatabaseHas('auditoria', ['evento' => 'turnos.creado', 'auditable_id' => $matutino->id]);

        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'MATUTINO', 'hora_inicio' => '06:00', 'hora_fin' => '14:00'])->assertSessionHasErrors('nombre');
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Raro', 'hora_inicio' => '25:00', 'hora_fin' => '14:00'])->assertSessionHasErrors('hora_inicio');
        $this->actingAs($this->admin)->get('/turnos')->assertOk()
            ->assertSee('Matutino')->assertSee('07:00 a 15:00 · 8 h')->assertSee('Todas las sedes')->assertSee('Nuevo Turno');
    }

    public function test_turno_que_cruza_la_medianoche_es_valido_y_misma_hora_no(): void
    {
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Nocturno', 'hora_inicio' => '22:00', 'hora_fin' => '06:00'])->assertSessionHasNoErrors();
        $nocturno = $this->turno('Nocturno');
        $this->assertTrue($nocturno->cruzaMedianoche());
        $this->assertSame(480, $nocturno->minutos());
        $this->actingAs($this->admin)->get('/turnos')->assertSee('22:00 a 06:00 · 8 h')->assertSee('Termina al día siguiente');

        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Eterno', 'hora_inicio' => '08:00', 'hora_fin' => '08:00'])
            ->assertSessionHasErrors(['hora_fin' => 'La hora de fin debe ser distinta a la de inicio.']);
        $this->actingAs($this->admin)->put("/turnos/{$nocturno->id}", ['nombre' => 'Nocturno', 'hora_inicio' => '06:00:00', 'hora_fin' => '06:00'])
            ->assertSessionHasErrors('hora_fin');

        $this->actingAs($this->admin)->put("/turnos/{$nocturno->id}", ['nombre' => 'Nocturno largo', 'hora_inicio' => '19:30', 'hora_fin' => '07:00'])->assertSessionHas('ok');
        $this->assertSame('11 h 30 min', $nocturno->fresh()->duracion());
        $this->assertDatabaseHas('auditoria', ['evento' => 'turnos.actualizado', 'auditable_id' => $nocturno->id]);
    }

    public function test_asignar_sedes_con_alcance_de_empresa_y_desactivar_reversible(): void
    {
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Mixto', 'hora_inicio' => '10:00', 'hora_fin' => '18:00', 'todas_las_sedes' => '0'])->assertSessionHasErrors('sedes');
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Mixto', 'hora_inicio' => '10:00', 'hora_fin' => '18:00'])->assertSessionHasNoErrors();
        $mixto = $this->turno('Mixto');
        $ajena = $this->crearSede($this->crearEmpresa('Hotel Dos'), 'OTR');

        $this->actingAs($this->admin)->put("/turnos/{$mixto->id}/sedes", ['todas_las_sedes' => '0', 'sedes' => [$this->playa->id, $ajena->id]])
            ->assertSessionHas('ok', 'Sedes del turno «Mixto» actualizadas.');
        $this->assertFalse($mixto->fresh()->todas_las_sedes);
        $this->assertSame([$this->playa->id], $this->sedesDe($mixto));
        $this->assertDatabaseHas('auditoria', ['evento' => 'turnos.sedes_actualizadas', 'auditable_id' => $mixto->id]);
        $this->actingAs($this->admin)->get('/turnos')->assertSee('1 sede')->assertSee('data-sede="'.$this->playa->id.'"', false);

        // Se puede dejar sin sedes, como en SEGCAT
        $this->actingAs($this->admin)->put("/turnos/{$mixto->id}/sedes", ['todas_las_sedes' => '0'])->assertSessionHas('ok');
        $this->assertSame([], $this->sedesDe($mixto));
        $this->actingAs($this->admin)->get('/turnos')->assertSee('0 sedes');

        $this->actingAs($this->admin)->patch("/turnos/{$mixto->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertFalse($mixto->fresh()->activo);
        $this->actingAs($this->admin)->patch("/turnos/{$mixto->id}/estado", ['activo' => '1'])->assertSessionHas('ok');
        $this->assertDatabaseHas('auditoria', ['evento' => 'turnos.desactivado', 'auditable_id' => $mixto->id]);
    }

    public function test_usuario_de_sede_solo_cambia_su_sede_y_no_modifica_el_catalogo(): void
    {
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00']);
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Playa', 'hora_inicio' => '10:00', 'hora_fin' => '18:00', 'todas_las_sedes' => '0', 'sedes' => [$this->playa->id]]);
        $matutino = $this->turno('Matutino');
        $playa = $this->turno('Playa');
        $gerente = $this->usuarioDeSede($this->centro, ['turnos.ver', 'turnos.crear', 'turnos.editar', 'turnos.eliminar']);

        // Ve también los turnos que su sede aún no usa, para poder activarlos
        $this->actingAs($gerente)->get('/turnos')->assertOk()->assertSee('Playa')->assertSee('Solo puedes activar o quitar el turno en tu sede')
            ->assertDontSee('Nuevo Turno')->assertDontSee('data-accion="editar-registro"', false);

        // Activa "Playa" en su sede: la sede de playa se conserva
        $this->actingAs($gerente)->put("/turnos/{$playa->id}/sedes", ['sedes' => [$this->centro->id]])->assertSessionHas('ok');
        $this->assertSame([$this->centro->id, $this->playa->id], $this->sedesDe($playa));

        // Intenta quitar la de playa (no es suya): se ignora
        $this->actingAs($gerente)->put("/turnos/{$playa->id}/sedes", ['todas_las_sedes' => '0', 'sedes' => [$this->centro->id]])->assertSessionHas('ok');
        $this->assertSame([$this->centro->id, $this->playa->id], $this->sedesDe($playa));

        // Quita su sede de un turno de "todas las sedes": pasa a lista con las demás
        $this->actingAs($gerente)->put("/turnos/{$matutino->id}/sedes", ['sedes' => []])->assertSessionHas('ok');
        $this->assertFalse($matutino->fresh()->todas_las_sedes);
        $this->assertSame([$this->playa->id], $this->sedesDe($matutino));

        // El catálogo es de toda la empresa
        $this->actingAs($gerente)->post('/turnos', ['nombre' => 'Otro', 'hora_inicio' => '08:00', 'hora_fin' => '16:00'])->assertForbidden();
        $this->actingAs($gerente)->put("/turnos/{$matutino->id}", ['nombre' => 'X', 'hora_inicio' => '08:00', 'hora_fin' => '16:00'])->assertForbidden();
        $this->actingAs($gerente)->patch("/turnos/{$matutino->id}/estado", ['activo' => '0'])->assertForbidden();
    }

    public function test_usuario_de_sede_solo_consulta_ve_los_turnos_de_su_sede(): void
    {
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00']);
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Mixto Playa', 'hora_inicio' => '10:00', 'hora_fin' => '18:00', 'todas_las_sedes' => '0', 'sedes' => [$this->playa->id]]);
        $consulta = $this->usuarioDeSede($this->centro, ['turnos.ver']);

        $this->actingAs($consulta)->get('/turnos')->assertOk()->assertSee('Matutino')->assertDontSee('Mixto Playa')->assertDontSee('dialogoSedesTurno');
        $this->actingAs($consulta)->put('/turnos/'.$this->turno('Matutino')->id.'/sedes', ['sedes' => []])->assertForbidden();
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $this->actingAs($this->admin)->post('/turnos', ['nombre' => 'Nocturno', 'hora_inicio' => '23:00', 'hora_fin' => '07:00']);
        $nocturno = $this->turno('Nocturno');
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->flushSession();

        $this->actingAs($ajeno)->get('/turnos')->assertOk()->assertDontSee('Nocturno');
        $this->actingAs($ajeno)->put("/turnos/{$nocturno->id}", ['nombre' => 'Hackeo', 'hora_inicio' => '01:00', 'hora_fin' => '02:00'])->assertNotFound();
        $this->actingAs($ajeno)->put("/turnos/{$nocturno->id}/sedes", ['todas_las_sedes' => '0'])->assertNotFound();
        $this->actingAs($ajeno)->patch("/turnos/{$nocturno->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->assertTrue($nocturno->fresh()->todas_las_sedes);
        // El mismo nombre sí puede existir en otra empresa
        $this->actingAs($ajeno)->post('/turnos', ['nombre' => 'Nocturno', 'hora_inicio' => '23:00', 'hora_fin' => '07:00'])->assertSessionHasNoErrors();
    }

    public function test_sin_permiso_no_entra_y_el_superadmin_elige_empresa(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($agente)->get('/turnos')->assertForbidden();
        $this->actingAs($agente)->post('/turnos', ['nombre' => 'X', 'hora_inicio' => '07:00', 'hora_fin' => '15:00'])->assertForbidden();

        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/turnos')->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/turnos')->assertSee('Nuevo Turno');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])
            ->post('/turnos', ['nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00'])->assertSessionHasNoErrors();
        $this->assertSame($this->empresa->id, $this->turno('Matutino')->empresa_id);
    }
}
