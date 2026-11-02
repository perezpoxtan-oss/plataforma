<?php

namespace Tests\Feature\Organizacion;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 7 · C-41: después de guardar, el aviso ofrece el siguiente paso
 * natural (regla 5), como «¿Qué sigue?» de Sedes: Departamento → «Nombrar
 * responsable»; Colaborador → «Crear su cuenta» o «Imprimir credencial»;
 * Usuario que puede autorizar → «Nombrarlo responsable».
 */
class EtapaSieteSiguientePasoTest extends TestCase
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

    /** @return list<array{texto: string, url: string}> */
    private function pasos(): array
    {
        $p = session('siguiente_paso');

        return is_array($p) && isset($p['url']) ? [$p] : (array) $p;
    }

    public function test_departamento_nuevo_ofrece_nombrar_responsable(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Mantenimiento', 'todas_las_sedes' => '1'])->assertRedirect('/departamentos');
        $d = $this->enEmpresa(fn () => Departamento::where('nombre', 'Mantenimiento')->sole());
        $pasos = $this->pasos();
        $this->assertSame('Nombrar responsable', $pasos[0]['texto']);
        $this->assertSame(route('autorizaciones.responsables', ['desde' => 'departamentos', 'abrir' => $d->id]).'#depto-'.$d->id, $pasos[0]['url']);

        // El botón se ve en la lista y lleva a la ventana del departamento abierta
        $this->actingAs($this->admin)->get('/departamentos')->assertSee('Siguiente paso:')->assertSee('Nombrar responsable');
        $html = $this->actingAs($this->admin)->get($pasos[0]['url'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoDepto'.$d->id.'"[^>]*data-abrir-al-cargar/u', $html);

        // Sin permiso para nombrar responsables, no se ofrece
        $soloDeptos = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        if (! $soloDeptos->can('autorizaciones.configurar') && $soloDeptos->can('departamentos.crear')) {
            $this->actingAs($soloDeptos)->post('/departamentos', ['nombre' => 'Compras', 'todas_las_sedes' => '1']);
            $this->assertSame([], $this->pasos());
        }
    }

    public function test_colaborador_nuevo_ofrece_crear_su_cuenta_e_imprimir_credencial(): void
    {
        $this->actingAs($this->admin)->post('/colaboradores', ['num_empleado' => 'E-7', 'nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'sede_id' => $this->centro->id])
            ->assertSessionHasNoErrors();
        $c = $this->enEmpresa(fn () => Colaborador::where('num_empleado', 'E-7')->sole());
        $textos = array_column($this->pasos(), 'texto');
        $this->assertContains('Crear su cuenta', $textos);
        $this->assertContains('Imprimir credencial', $textos);
        $cuenta = collect($this->pasos())->firstWhere('texto', 'Crear su cuenta');
        $this->assertSame(route('usuarios.index', ['colaborador' => $c->id]), $cuenta['url']);

        // «Crear su cuenta» abre el alta de usuario con el colaborador ya vinculado (el dato no se vuelve a capturar)
        $html = $this->actingAs($this->admin)->get($cuenta['url'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoNuevoUsuario"[^>]*data-abrir-al-cargar/u', $html);
        $this->assertStringContainsString('name="colaborador_id" value="'.$c->id.'"', $html);
        $this->assertStringContainsString('value="E-7"', $html);
        $this->assertStringContainsString('value="Laura Gómez"', $html);

        // Un colaborador que ya tiene cuenta, o de otra empresa, no prellena nada
        $otra = $this->crearEmpresa('Otra');
        $ajeno = app(Tenant::class)->conEmpresa($otra->id, function () use ($otra) {
            $c = new Colaborador(['sede_id' => $this->crearSede($otra, 'OTR')->id, 'num_empleado' => 'X-1', 'nombre' => 'Ajeno', 'apellido_paterno' => 'Uno']);
            $c->forceFill(['activo' => true])->save();

            return $c;
        });
        $html = $this->actingAs($this->admin)->get(route('usuarios.index', ['colaborador' => $ajeno->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="X-1"', $html);
    }

    public function test_usuario_que_puede_autorizar_ofrece_nombrarlo_responsable(): void
    {
        $rolJefe = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Jefe de departamento')->first();
        if ($rolJefe === null) {
            $this->markTestSkipped('La empresa de prueba no trae el rol Jefe de departamento.');
        }
        $this->actingAs($this->admin)->post('/usuarios', ['name' => 'Julia Pérez', 'username' => 'julia.perez', 'email' => 'julia@ejemplo.mx',
            'password' => 'Clave1234', 'rol_id' => $rolJefe->id, 'sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $pasos = $this->pasos();
        $this->assertSame('Nombrarlo responsable', $pasos[0]['texto'] ?? null);
        $this->assertStringStartsWith(route('autorizaciones.responsables'), $pasos[0]['url']);

        // Un agente (no autoriza visitas) no lo ofrece
        $this->actingAs($this->admin)->post('/usuarios', ['name' => 'Pedro Can', 'username' => 'pedro.can', 'email' => 'pedro@ejemplo.mx',
            'password' => 'Clave1234', 'rol_id' => Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->value('id'),
            'sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $this->assertNotContains('Nombrarlo responsable', array_column($this->pasos(), 'texto'));
    }
}
