<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Candidatos\Entrevistas;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Importacion\ImportadorColaboradores;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Reservas\ResponsablesReservas;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Revisión independiente de la Etapa 3a (PR #89): delegación en cadena y sin
 * bucles, el delegado solo recibe lo que puede aprobar (escalamiento y
 * Reservas), límite propio de «Delegar mis aprobaciones», la migración de
 * C-11 no toca a un Super Administrador y la importación no une a personas
 * distintas que solo se llaman igual.
 */
class RevisionEtapaTresTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $jefa;

    private User $b;

    private User $c;

    private User $agente;

    private Departamento $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Revisión');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->jefa = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->b = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->c = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->recepcion = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Recepción']));
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->recepcion->id, 'user_id' => $this->jefa->id, 'sede_id' => $this->centro->id]));
        $this->empresa->forceFill(['preferencias' => ['recepcion' => ['visitas_requieren_autorizacion' => true]]])->save();
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function olvidar(): void
    {
        app(Autorizador::class)->olvidar();
        $this->app->forgetScopedInstances();
        Cache::flush();
    }

    private function delegar(User $de, User $en): void
    {
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $de->id, 'delegado_id' => $en->id, 'desde' => now()->subHour(), 'hasta' => now()->addDays(2)]));
    }

    private function visita(string $nombre): Autorizacion
    {
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => $nombre,
            'motivo_visita' => 'departamento', 'departamento_id' => $this->recepcion->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'])->assertSessionHas('ok');

        return $this->enEmpresa(fn () => Autorizacion::where('acceso_id', Acceso::where('nombre', mb_strtoupper($nombre))->value('id'))->firstOrFail());
    }

    private function pendientes(User $u): int
    {
        $this->olvidar();

        return collect(app()->build(MisPendientes::class)->para($u->fresh())['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? 0;
    }

    /** Usuario con solo «Aprobar» pases de salida en Centro (puede recibir delegaciones desde C-12). */
    private function soloPases(): User
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Solo pases', 'nivel_jerarquia' => 45]);
        $roles->sincronizarPermisos($sa, $rol, ['pases_salida.ver' => Alcance::Sede, 'pases_salida.aprobar' => Alcance::Sede]);
        $u = $this->crearUsuario($this->empresa, 'Solo pases', $this->centro);
        $this->olvidar();

        return $u;
    }

    // ------------------------------------------------------- Delegación en cadena

    public function test_delegacion_en_cadena_a_b_c_llega_a_c_sin_perderse(): void
    {
        $this->delegar($this->jefa, $this->b);
        $this->delegar($this->b, $this->c);
        $this->olvidar();
        $a = $this->visita('Ana Cadena');
        $this->assertSame([$this->c->id], array_map('intval', $a->avisados), 'El aviso llega a quien sí está: C');
        $this->assertSame(1, $this->pendientes($this->c));
        $this->assertSame(0, $this->pendientes($this->b));
        $this->assertSame(0, $this->pendientes($this->jefa));
        $this->olvidar();
        $this->assertTrue($this->enEmpresa(fn () => app(Autorizaciones::class)->puedeResponder($this->c->fresh(), $a)));
        $this->actingAs($this->c)->get("/autorizaciones/{$a->id}")->assertOk();
        // Entrevistas siguen la misma cadena
        $this->olvidar();
        $this->assertSame([$this->c->id], $this->enEmpresa(fn () => app(Entrevistas::class)->aQuienAvisar($this->jefa->id)->pluck('id')->all()));
    }

    public function test_no_se_puede_cerrar_un_bucle_y_si_existe_nadie_pierde_sus_pendientes(): void
    {
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->b->id, 'hasta' => now()->addDays(2)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->actingAs($this->b)->post('/mi-cuenta/delegar', ['delegado_id' => $this->jefa->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('delegado_id');
        $this->assertSame(1, $this->enEmpresa(fn () => Delegacion::count()));

        // Un bucle que ya existía (datos anteriores): el aviso no se pierde y Mis pendientes lo muestra
        $this->delegar($this->b, $this->jefa);
        $this->olvidar();
        $a = $this->visita('Beto Bucle');
        $this->assertNotEmpty($a->avisados);
        $this->assertGreaterThan(0, $this->pendientes($this->jefa) + $this->pendientes($this->b), 'Alguien lo ve en Mis pendientes');
    }

    public function test_la_delegacion_vence_sola(): void
    {
        $this->delegar($this->jefa, $this->b);
        $this->travel(3)->days();
        $this->olvidar();
        $a = $this->visita('Carla Vence');
        $this->assertSame([$this->jefa->id], array_map('intval', $a->avisados));
        $this->assertSame(1, $this->pendientes($this->jefa));
    }

    public function test_si_el_delegado_es_dado_de_baja_todo_vuelve_al_titular(): void
    {
        $this->delegar($this->jefa, $this->b);
        $this->delegar($this->b, $this->c);
        $this->c->forceFill(['activo' => false])->save();
        $this->olvidar();
        $a = $this->visita('Dora Baja');
        $this->assertSame([$this->b->id], array_map('intval', $a->avisados), 'Sin C, se queda con B');
        $this->b->forceFill(['activo' => false])->save();
        $this->olvidar();
        $otra = $this->visita('Eva Baja');
        $this->assertSame([$this->jefa->id], array_map('intval', $otra->avisados));
        $this->assertSame(2, $this->pendientes($this->jefa));
    }

    // --------------------------------------- El delegado solo recibe lo que puede

    public function test_escalamiento_no_pasa_al_delegado_que_no_puede_responder(): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->recepcion->id, 'user_id' => $this->b->id, 'sede_id' => $this->centro->id, 'es_suplente' => true]));
        $pases = $this->soloPases();
        $this->delegar($this->b, $pases);
        $this->olvidar();
        $a = $this->visita('Fer Escala');
        $ids = $this->enEmpresa(fn () => app(Escalamiento::class)->aQuienEscalar($a)->pluck('id')->all());
        $this->assertNotContains($pases->id, $ids, 'Quien solo aprueba pases no recibe la visita escalada');
        $this->assertContains($this->b->id, $ids);
    }

    public function test_reservas_el_delegado_sin_capacidad_no_aprueba_ni_recibe_por_el_titular(): void
    {
        $pases = $this->soloPases();
        $this->delegar($this->jefa, $pases);
        $this->olvidar();
        $reservas = fn () => app(ResponsablesReservas::class);
        $this->assertSame([$this->jefa->id], $this->enEmpresa(fn () => $reservas()->conDelegados([$this->jefa->id])->pluck('id')->all()));
        $this->assertFalse($this->enEmpresa(fn () => $reservas()->atiendeDepartamento($pases, $this->recepcion->id, $this->centro->id, $this->empresa->id)));
        $this->assertTrue($this->enEmpresa(fn () => $reservas()->atiendeDepartamento($this->jefa, $this->recepcion->id, $this->centro->id, $this->empresa->id)));
        // Con un delegado que sí puede, la orden le llega a él (en cadena también)
        $this->enEmpresa(fn () => Delegacion::query()->update(['delegado_id' => $this->b->id]));
        $this->delegar($this->b, $this->c);
        $this->olvidar();
        $this->assertSame([$this->c->id], $this->enEmpresa(fn () => $reservas()->conDelegados([$this->jefa->id])->pluck('id')->all()));
        $this->assertTrue($this->enEmpresa(fn () => $reservas()->atiendeDepartamento($this->c, $this->recepcion->id, $this->centro->id, $this->empresa->id)));
    }

    // ------------------------------------------------------------------ Seguridad

    public function test_delegar_tiene_limite_propio_por_cuenta(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->jefa->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])->assertStatus(302);
        }
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->b->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])->assertStatus(429);
        // No comparte contador con otras rutas ni con otras cuentas
        $this->actingAs($this->jefa)->get('/mi-cuenta')->assertOk();
        $this->actingAs($this->b)->post('/mi-cuenta/delegar', ['delegado_id' => $this->c->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
    }

    public function test_no_se_delega_en_otra_empresa(): void
    {
        $otra = $this->crearEmpresa('Otra empresa');
        $ajeno = $this->crearUsuario($otra, 'Jefe de seguridad', $this->crearSede($otra, 'OTR'));
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $ajeno->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('delegado_id');
        $this->actingAs($this->jefa)->patch('/mi-cuenta/delegar/999/cancelar')->assertNotFound();
    }

    public function test_la_migracion_de_c11_no_renombra_a_un_super_administrador(): void
    {
        $col = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '900', 'nombre' => 'Laura', 'apellido_paterno' => 'Chan', 'sede_id' => $this->centro->id]));
        $sa = $this->crearSuperadmin();
        $sa->forceFill(['name' => 'Soporte Plataforma', 'colaborador_id' => $col->id])->save();
        (require database_path('migrations/2026_11_12_000100_cuentas_siguen_al_colaborador.php'))->up();
        $this->assertSame('Soporte Plataforma', $sa->fresh()->name);
        // Ni la edición del colaborador desde la empresa
        $this->actingAs($this->admin)->get('/colaboradores')->assertOk();
        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->sincronizarCuenta($this->admin, $col, null));
        $this->assertSame('Soporte Plataforma', $sa->fresh()->name);
    }

    // --------------------------------------------- Importar: no unir a distintos

    public function test_importar_no_une_con_un_alta_de_la_caseta_si_hay_duda(): void
    {
        $prov = $this->enEmpresa(fn () => tap(new Colaborador(['nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'sede_id' => $this->centro->id]), fn (Colaborador $c) => $c->forceFill(['provisional' => true])->save()));
        $imp = fn (array $fila) => $this->enEmpresa(fn () => app(ImportadorColaboradores::class)->existente($fila));
        $this->actingAs($this->admin);
        // Mismo nombre, misma sede: se propone unir
        $this->assertSame($prov->id, $imp(['num_empleado' => 'E-77', 'nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'sede' => 'CEN'])?->id);
        // Otra sede: es otra persona
        $this->assertNull($imp(['num_empleado' => 'E-78', 'nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'sede' => 'PLA']));
        // Ya hay una «Rosa Uc» verificada: no se sabe cuál es; no se une sola
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'E-10', 'nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'sede_id' => $this->centro->id]));
        $this->assertNull($imp(['num_empleado' => 'E-79', 'nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'sede' => 'CEN']));
    }
}
