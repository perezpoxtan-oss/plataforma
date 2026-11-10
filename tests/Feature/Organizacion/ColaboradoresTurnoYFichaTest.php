<?php

namespace Tests\Feature\Organizacion;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Mapa de procesos: turno del colaborador (alta, edición, filtro y ficha) y
 * la ficha del colaborador en pestañas (Datos, Expediente, Código y
 * etiqueta, Historial) con sus permisos.
 */
class ColaboradoresTurnoYFichaTest extends TestCase
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

    /**
     * @param  list<Sede>  $sedes  vacío = todas las sedes
     */
    private function turno(string $nombre, array $sedes = [], bool $activo = true, ?Empresa $empresa = null): Turno
    {
        return $this->enEmpresa(function () use ($nombre, $sedes, $activo) {
            $t = Turno::create(['nombre' => $nombre, 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'todas_las_sedes' => $sedes === [], 'activo' => $activo]);
            $t->sedes()->sync(array_map(fn ($s) => $s->id, $sedes));

            return $t;
        }, $empresa);
    }

    private function datos(array $extra = []): array
    {
        return array_merge(['num_empleado' => 'T-1', 'nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'sede_id' => $this->centro->id], $extra);
    }

    private function colaborador(string $num): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::where('num_empleado', $num)->firstOrFail());
    }

    // ---------------------------------------------------------------- Turno

    public function test_la_columna_turno_existe_con_llave_foranea_que_queda_en_nulo(): void
    {
        $this->assertTrue(Schema::hasColumn('colaboradores', 'turno_id'));
        $turno = $this->turno('Matutino');
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['turno_id' => $turno->id]))->assertSessionHasNoErrors();
        $c = $this->colaborador('T-1');
        $this->assertSame($turno->id, (int) $c->turno_id);

        // «Eliminar definitivamente» cuenta al colaborador como dependencia: no deja borrarlo
        $this->actingAs($this->admin)->getJson("/borrar/turnos/{$turno->id}")->assertOk()->assertJson(['puede_eliminar' => false]);

        // Si el turno se borra, el colaborador queda «Sin turno» (no se borra ni falla)
        $this->enEmpresa(fn () => $turno->delete());
        $this->assertNull($c->fresh()->turno_id);
    }

    public function test_alta_y_edicion_con_turno_de_la_empresa_activo_y_de_su_sede(): void
    {
        $matutino = $this->turno('Matutino');
        $playa = $this->turno('Playa nocturno', [$this->playa]);
        $inactivo = $this->turno('Mixto', [], false);
        $ajeno = $this->turno('Ajeno', [], true, $this->crearEmpresa('Hotel Dos'));

        // El formulario ofrece los turnos con las sedes donde se usan (la lista se acota en la pantalla)
        $this->actingAs($this->admin)->get('/colaboradores')->assertOk()
            ->assertSee('name="turno_id"', false)->assertSee('Matutino (07:00–15:00)')->assertSee('data-colab-turno', false)
            ->assertDontSee('Ajeno');

        // Otra empresa, desactivado o de otra sede: no se aceptan
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['turno_id' => $ajeno->id]))->assertSessionHasErrors('turno_id');
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['turno_id' => $inactivo->id]))->assertSessionHasErrors('turno_id');
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['turno_id' => $playa->id]))
            ->assertSessionHasErrors(['turno_id' => 'El turno «Playa nocturno» no se usa en la sede elegida.']);
        $this->assertSame(0, $this->enEmpresa(fn () => Colaborador::count()));

        // Es opcional
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos())->assertSessionHasNoErrors();
        $c = $this->colaborador('T-1');
        $this->assertNull($c->turno_id);

        // Se asigna al editar, queda en la bitácora y se ve en la lista y en la ficha
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['turno_id' => $matutino->id]))->assertSessionHasNoErrors();
        $this->assertSame($matutino->id, (int) $c->fresh()->turno_id);
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.actualizado', 'auditable_id' => $c->id]);
        $this->actingAs($this->admin)->get('/colaboradores')->assertOk()
            ->assertSee('data-turno="'.$matutino->id.'"', false)->assertSee('Turno:</strong> Matutino', false)
            ->assertSee('data-filtro-colab="turno"', false)->assertSee('Sin turno');
        $this->actingAs($this->admin)->get("/colaboradores/{$c->id}/expediente")->assertOk()->assertSee('<dt>Turno</dt><dd>Matutino (07:00–15:00)</dd>', false);

        // Desactivar el turno no se lo quita: se conserva al guardar sin cambiarlo
        $this->enEmpresa(fn () => $matutino->forceFill(['activo' => false])->save());
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['nombre' => 'Laura Elena', 'turno_id' => $matutino->id]))->assertSessionHasNoErrors();
        $this->assertSame($matutino->id, (int) $c->fresh()->turno_id);

        // Un guardado que no trae el campo (verificación, registro rápido) no lo borra; «Sin turno» sí lo quita
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['nombre' => 'Laura']))->assertSessionHasNoErrors();
        $this->assertSame($matutino->id, (int) $c->fresh()->turno_id);
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['turno_id' => '']))->assertSessionHasNoErrors();
        $this->assertNull($c->fresh()->turno_id);

        // Cambiar a otra sede exige un turno que se use ahí
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['sede_id' => $this->playa->id, 'turno_id' => $playa->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/colaboradores/{$c->id}", $this->datos(['sede_id' => $this->centro->id, 'turno_id' => $playa->id]))->assertSessionHasErrors('turno_id');
    }

    // ---------------------------------------------------------------- Ficha

    public function test_la_ficha_tiene_cuatro_pestanas_accesibles_sin_javascript_en_linea(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['curp' => 'GORL900101MQRMZR05']))->assertSessionHasNoErrors();
        $c = $this->colaborador('T-1');

        $html = $this->actingAs($this->admin)->get("/colaboradores/{$c->id}/expediente")->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSeeInOrder(['id="pestana-datos"', 'id="pestana-expediente"', 'id="pestana-codigo"', 'id="pestana-historial"'], false)
            ->assertSee('aria-controls="panel-expediente"', false)->assertSee('aria-labelledby="pestana-historial"', false)
            ->assertSee('role="tabpanel"', false)->assertSee('js/pestanas.js', false)
            // Expediente con los datos legales (tiene el permiso de datos personales) y documentos
            ->assertSee('GORL900101MQRMZR05')->assertSee('Documentos de contratación')
            // Código y etiqueta: el QR, la dirección y asignar la etiqueta
            ->assertSee(route('identificacion.qr', ['colaborador', $c->id]))->assertSee(route('lector.ir', $c->codigo_qr))
            ->assertSee(route('identificacion.etiqueta', ['colaborador', $c->id]))->assertSee('id="dialogoIdentificacion"', false)
            ->getContent();

        // Sin JavaScript en línea (CSP): solo scripts con archivo
        preg_match_all('/<script\b[^>]*>/i', $html, $etiquetas);
        foreach ($etiquetas[0] as $etiqueta) {
            $this->assertStringContainsString('src=', $etiqueta);
        }
        $this->assertFileExists(public_path('js/pestanas.js'));
    }

    public function test_historial_cuenta_bajas_y_reingresos_y_respeta_permisos(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', $this->datos(['curp' => 'GORL900101MQRMZR05']))->assertSessionHasNoErrors();
        $c = $this->colaborador('T-1');
        $this->actingAs($this->admin)->patch("/colaboradores/{$c->id}/estado", ['activo' => '0']);
        $this->actingAs($this->admin)->patch("/colaboradores/{$c->id}/estado", ['activo' => '1']);

        $this->actingAs($this->admin)->get("/colaboradores/{$c->id}/expediente")->assertOk()
            ->assertSee('Movimientos del registro')->assertSeeInOrder(['Reingreso', 'Baja', 'Alta'])
            ->assertSee('Ver el detalle en la Bitácora de auditoría');

        // Quien solo consulta: sin pestaña Expediente (datos personales), sin asignar etiqueta, sin la bitácora
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->assertFalse($agente->can('colaboradores.datos_personales'));
        $this->actingAs($agente)->get("/colaboradores/{$c->id}/expediente")->assertOk()
            ->assertSee('id="pestana-datos"', false)->assertSee('id="pestana-historial"', false)
            ->assertDontSee('id="pestana-expediente"', false)->assertDontSee('id="panel-expediente"', false)
            ->assertDontSee('GORL900101MQRMZR05')->assertDontSee('Documentos de contratación')
            ->assertDontSee(route('identificacion.etiqueta', ['colaborador', $c->id]))
            ->assertDontSee('Ver el detalle en la Bitácora de auditoría')
            ->assertSee('Reingreso');

        // Otra empresa: 404
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($ajeno)->get("/colaboradores/{$c->id}/expediente")->assertNotFound();
    }
}
