<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Importacion\ImportadorColaboradores;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Cierre de los puntos anotados por la revisión independiente de la Etapa
 * 3a (PR #89): cobertura por función en Departamentos y Responsables,
 * delegado dado de baja, aviso de delegación vencida, homónimos por índice
 * sin tope, límite de la ruta vieja de delegar y la cadena en el manual.
 */
class EtapaTresCierreRevisionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $jefa;

    private User $suplente;

    private Departamento $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Cierre');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->jefa = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->suplente = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->recepcion = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Recepción']));
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function responsable(User $u): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->recepcion->id, 'user_id' => $u->id, 'sede_id' => $this->centro->id]));
    }

    // 1 · Cobertura por función: la pantalla no dice «cubierto» mientras firma otra persona
    public function test_departamentos_y_responsables_muestran_la_cobertura_por_funcion(): void
    {
        // Recursos Humanos responde autorizaciones pero no aprueba pases: autoriza visitas, los pases los firma otra persona
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->responsable($rh);
        $this->actingAs($this->admin)->get('/departamentos')->assertOk()
            ->assertSee('Autoriza visitas: Sí · Firma pases: No (lo firma', false);
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertOk()
            ->assertSee('Autoriza visitas: Sí · Firma pases: No (lo firma', false);

        // La jefa de departamento hace las dos cosas
        $this->enEmpresa(fn () => DepartamentoResponsable::query()->delete());
        $this->responsable($this->jefa);
        $this->actingAs($this->admin)->get('/departamentos')->assertOk()->assertSee('Autoriza visitas: Sí · Firma pases: Sí');
    }

    // 2 · Delegado dado de baja: la delegación termina y el titular se entera
    public function test_si_el_delegado_es_dado_de_baja_el_titular_recibe_el_aviso(): void
    {
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->suplente->id, 'hasta' => now()->addDays(3)->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch("/usuarios/{$this->suplente->id}/estado", ['activo' => 0])->assertSessionHas('aviso');

        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->jefa->id)->where('tipo', 'delegacion_terminada')->firstOrFail();
        $this->assertSame('Tu delegación a '.$this->suplente->name.' terminó porque fue dada de baja', $aviso->titulo);
        $this->assertStringContainsString('Tus aprobaciones regresaron a ti', $aviso->texto);
        $this->assertNotNull($this->enEmpresa(fn () => Delegacion::firstOrFail())->cancelada_en);
        $this->actingAs($this->jefa)->get('/mi-cuenta')->assertDontSee('Hoy tus aprobaciones van a');
    }

    // 3 · El aviso «te delegó sus aprobaciones» se marca «Terminó» al vencer
    public function test_el_aviso_de_delegacion_queda_terminado_al_vencer(): void
    {
        $this->actingAs($this->jefa)->post('/mi-cuenta/delegar', ['delegado_id' => $this->suplente->id, 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->suplente->id)->where('tipo', 'delegacion')->firstOrFail();
        $this->assertNull($aviso->leida_en);

        $this->travel(2)->days();
        // Lo hace la consulta de la campana (cada 30 s); aquí, el mismo servicio que la responde
        $this->enEmpresa(fn () => app(CentroNotificaciones::class)->resumen($this->suplente->fresh()));
        $aviso->refresh();
        $this->assertStringStartsWith(CentroNotificaciones::DELEGACION_TERMINO, $aviso->titulo);
        $this->assertNotNull($aviso->leida_en);
    }

    // 4 · Homónimos por índice y sin tope (antes 200 por inicial)
    public function test_la_importacion_encuentra_el_alta_de_la_caseta_aunque_haya_muchos_apellidos_con_la_misma_inicial(): void
    {
        $this->enEmpresa(function () {
            $filas = [];
            for ($i = 0; $i < 230; $i++) {
                $filas[] = ['empresa_id' => $this->empresa->id, 'num_empleado' => 'N'.$i, 'nombre' => 'Persona'.$i, 'apellido_paterno' => 'Uc'.$i,
                    'nombre_normalizado' => Colaborador::normalizarNombre('Persona'.$i, 'Uc'.$i), 'activo' => true, 'provisional' => false,
                    'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('colaboradores')->insert($filas);
            $p = new Colaborador(['nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'sede_id' => $this->centro->id]);
            $p->forceFill(['provisional' => true])->save();
        });
        $this->assertSame('rosa uc', $this->enEmpresa(fn () => Colaborador::where('nombre', 'Rosa')->value('nombre_normalizado')));

        $encontrado = $this->enEmpresa(fn () => app(ImportadorColaboradores::class)->existente(['num_empleado' => 'E-77', 'nombre' => 'ROSA', 'apellido_paterno' => 'Úc']));
        $this->assertNotNull($encontrado);
        $this->assertSame('Rosa', $encontrado->nombre);
        $this->assertCount(1, $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->homonimos('Rosa', 'Uc', null, $this->centro->id)));
    }

    // 5 · La ruta vieja de delegar tiene el mismo límite con nombre propio
    public function test_la_ruta_vieja_de_delegar_tiene_su_limite(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->admin)->post('/autorizaciones/delegaciones', [])->assertStatus(302);
        }
        $this->actingAs($this->admin)->post('/autorizaciones/delegaciones', [])->assertStatus(429);
        // No comparte contador con otras rutas ni con otra cuenta
        $this->actingAs($this->admin)->get('/mi-cuenta')->assertOk();
        $this->actingAs($this->jefa)->post('/autorizaciones/delegaciones', [])->assertStatus(302);
    }

    // 6 · La cadena A→B→C está dicha en el manual de Mi cuenta
    public function test_el_manual_explica_la_cadena(): void
    {
        $texto = (string) file_get_contents(base_path('docs/usuario/mi-cuenta.md'));
        $this->assertStringContainsString('lo tuyo le llega a **la última persona de la cadena que pueda aprobarlo**', $texto);
    }
}
