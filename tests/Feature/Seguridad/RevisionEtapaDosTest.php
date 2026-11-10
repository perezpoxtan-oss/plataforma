<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\Sede;
use App\Models\User;
use App\Services\Informes\InformeEjecutivo;
use App\Services\Informes\Tablero;
use App\Services\Lector\AccionesLector;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Revisión independiente de la Etapa 2 (PR #88): errores encontrados entre
 * módulos vinculados (§9 del plan). Cada prueba falla sin su corrección.
 *
 *  - R2-01: «Antes de dar de baja» ofrecía y aceptaba como reemplazo cuentas
 *    de OTRA empresa (users no tiene el scope de tenant).
 *  - R2-02: el lector decía «Está en caseta, lista para prestarse» de una
 *    llave marcada «No regresó».
 *  - R2-03: Tablero e Informe ejecutivo contaban como «Disponibles» las
 *    llaves que no regresaron.
 *  - R2-04: «No regresó» desde «Antes de dar de baja» marcaba una llave que
 *    no estaba prestada (POST directo).
 */
class RevisionEtapaDosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function llave(string $nomenclatura): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura) {
            $l = new Llave(['sede_id' => $this->centro->id, 'nomenclatura' => $nomenclatura, 'descripcion' => 'Prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
            $l->save();

            return $l;
        });
    }

    private function colaborador(string $num): Colaborador
    {
        return $this->enEmpresa(function () use ($num) {
            $c = new Colaborador(['num_empleado' => $num, 'nombre' => 'Persona', 'apellido_paterno' => 'Num'.$num, 'sede_id' => $this->centro->id]);
            $c->save();

            return $c;
        });
    }

    /** Presta la llave y la marca «No regresó» como lo hace la caseta. */
    private function llaveQueNoRegreso(string $nomenclatura): Llave
    {
        $l = $this->llave($nomenclatura);
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $l->id,
            'colaborador_id' => $this->colaborador('N'.$l->id)->id, 'tipo_garantia' => 'ine'])->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::where('llave_id', $l->id)->firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/no-regreso")->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::NO_REGRESO, $l->fresh()->estado);

        return $l;
    }

    // R2-01 ----------------------------------------------------------------

    public function test_el_reemplazo_del_unico_responsable_solo_es_de_la_misma_empresa(): void
    {
        $julieta = $this->colaborador('3000');
        $cuenta = $this->crearUsuario($this->empresa, 'Jefe de departamento');
        $cuenta->forceFill(['colaborador_id' => $julieta->id])->save();
        $depto = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Mantenimiento']));
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $depto->id, 'user_id' => $cuenta->id]));

        $otraEmpresa = $this->crearEmpresa('Hotel Ajeno');
        $ajeno = $this->crearUsuario($otraEmpresa, 'Administrador');
        $ajeno->forceFill(['name' => 'Cuenta De Otra Empresa'])->save();
        $propio = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        // La lista de reemplazos no muestra cuentas de otra empresa
        $this->actingAs($this->admin)->get(route('bajas.revisar', ['tipo' => 'colaboradores', 'id' => $julieta->id]))->assertOk()
            ->assertSee('¿Quién lo reemplaza como responsable?')->assertSee($propio->name)->assertDontSee('Cuenta De Otra Empresa');

        // Ni se acepta en un envío directo
        $this->actingAs($this->admin)->patch("/colaboradores/{$julieta->id}/estado", ['activo' => 0, 'revisado' => 1, 'reemplazo_id' => $ajeno->id])
            ->assertSessionHasErrors('reemplazo_id');
        $this->assertTrue($julieta->fresh()->activo);
        $this->assertSame($cuenta->id, $this->enEmpresa(fn () => DepartamentoResponsable::where('departamento_id', $depto->id)->value('user_id')));

        // Con una cuenta de la empresa, sí
        $this->actingAs($this->admin)->patch("/colaboradores/{$julieta->id}/estado", ['activo' => 0, 'revisado' => 1, 'reemplazo_id' => $propio->id])
            ->assertSessionHasNoErrors();
        $this->assertFalse($julieta->fresh()->activo);
        $this->assertSame($propio->id, $this->enEmpresa(fn () => DepartamentoResponsable::where('departamento_id', $depto->id)->value('user_id')));
    }

    // R2-02 ----------------------------------------------------------------

    public function test_el_lector_no_ofrece_prestar_una_llave_que_no_regreso_sino_recibirla(): void
    {
        $l = $this->llaveQueNoRegreso('HDC-LEC');
        $p = $this->enEmpresa(fn () => PrestamoLlave::where('llave_id', $l->id)->firstOrFail());

        $menu = $this->enEmpresa(fn () => app(AccionesLector::class)->para($this->admin,
            ['tipo' => 'llave', 'id' => $l->id, 'titulo' => 'HDC-LEC', 'detalle' => null, 'url' => route('llaves.index')]));

        $this->assertNotNull($menu);
        $this->assertStringNotContainsString('lista para prestarse', (string) $menu['estado']);
        $this->assertStringContainsString('No regresó', (string) $menu['estado']);
        $this->assertSame(['Recibir'], array_column($menu['acciones'], 'etiqueta'));
        $this->assertSame(route('prestamo_llaves.recibir', $p->id), $menu['acciones'][0]['url']);

        // «Recibir» desde el lector la regresa a DISPONIBLE
        $this->actingAs($this->admin)->patch($menu['acciones'][0]['url'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::DISPONIBLE, $l->fresh()->estado);
    }

    // R2-03 ----------------------------------------------------------------

    public function test_tablero_e_informe_ejecutivo_no_cuentan_como_disponibles_las_llaves_que_no_regresaron(): void
    {
        $this->llaveQueNoRegreso('HDC-NR');
        $this->llave('HDC-OK');

        $grafica = $this->enEmpresa(fn () => app(Tablero::class)->detalle($this->admin, 'llaves', null)['grafica']);
        $this->assertStringContainsString('Disponibles: 1', $grafica);
        $this->assertStringContainsString('No regresaron: 1', $grafica);

        $bloques = json_encode($this->enEmpresa(fn () => app(InformeEjecutivo::class)->bloques($this->admin, $this->empresa->id, null, true)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Disponibles: 1', $bloques);
        $this->assertStringNotContainsString('Disponibles: 2', $bloques);
        $this->assertStringContainsString('No regresaron: 1', $bloques);
    }

    // R2-04 ----------------------------------------------------------------

    public function test_no_regreso_desde_antes_de_dar_de_baja_exige_que_alguien_la_tenga(): void
    {
        $l = $this->llave('HDC-LIBRE');
        $this->actingAs($this->admin)->post("/antes-de-dar-de-baja/llaves/{$l->id}/no-regreso")->assertSessionHasErrors('baja');
        $this->assertSame(EstadoActivo::DISPONIBLE, $l->fresh()->estado);
    }
}
