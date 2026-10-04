<?php

namespace Tests\Feature\Nucleo;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutorizadorTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
    }

    public function test_el_superadmin_puede_todo(): void
    {
        $this->assertTrue($this->crearSuperadmin()->can('accesos.eliminar'));
    }

    public function test_un_usuario_sin_roles_no_puede_nada(): void
    {
        $this->assertFalse($this->crearUsuario($this->empresa)->can('accesos.ver'));
    }

    public function test_el_rol_otorga_solo_sus_acciones(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->assertTrue($agente->can('accesos.ver'));
        $this->assertTrue($agente->can('accesos.crear'));
        $this->assertFalse($agente->can('accesos.eliminar'));
        $this->assertFalse($agente->can('usuarios.ver'));
    }

    public function test_un_modulo_desactivado_para_la_empresa_no_da_acceso(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $modulo = Modulo::where('clave', 'accesos')->firstOrFail();

        $this->empresa->modulos()->updateExistingPivot($modulo->id, ['activo' => false]);
        app(Autorizador::class)->olvidar();

        $this->assertFalse($agente->can('accesos.ver'));
        $this->assertTrue($agente->can('novedades.ver'));
    }

    public function test_un_submodulo_cae_si_su_modulo_padre_esta_desactivado(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->assertTrue($agente->can('lost_found.ver'));

        $novedades = Modulo::where('clave', 'novedades')->firstOrFail();
        $this->empresa->modulos()->updateExistingPivot($novedades->id, ['activo' => false]);
        app(Autorizador::class)->olvidar();

        $this->assertFalse($agente->can('lost_found.ver'));
    }

    public function test_una_empresa_que_no_contrato_el_modulo_no_lo_usa(): void
    {
        $limitada = $this->crearEmpresa('Solo accesos', ['accesos']);
        $agente = $this->crearUsuario($limitada, 'Agente');

        $this->assertTrue($agente->can('accesos.ver'));
        $this->assertFalse($agente->can('llaves.ver'));
    }

    public function test_alcance_de_sede(): void
    {
        $s1 = $this->crearSede($this->empresa, 'S1');
        $s2 = $this->crearSede($this->empresa, 'S2');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $s1);

        $this->assertTrue($agente->can('accesos.ver', $this->registro(['sede_id' => $s1->id])));
        $this->assertFalse($agente->can('accesos.ver', $this->registro(['sede_id' => $s2->id])));
    }

    public function test_rol_sin_sede_aplica_a_todas_las_sedes(): void
    {
        $s2 = $this->crearSede($this->empresa, 'S2');
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->assertTrue($agente->can('accesos.ver', $this->registro(['sede_id' => $s2->id])));
    }

    public function test_nunca_alcanza_registros_de_otra_empresa(): void
    {
        $otra = $this->crearEmpresa('Otra');
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        $this->assertFalse($admin->can('accesos.ver', $this->registro(['empresa_id' => $otra->id])));
    }

    public function test_alcance_de_solo_propios(): void
    {
        $usuario = $this->crearUsuario($this->empresa);
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Capturista', 'nivel_jerarquia' => 70]);
        RolPermiso::create([
            'rol_id' => $rol->id,
            'modulo_accion_id' => $this->moduloAccion('novedades', 'editar'),
            'alcance' => Alcance::Propios,
        ]);
        $this->darRol($usuario, 'Capturista');

        $this->assertTrue($usuario->can('novedades.editar', $this->registro(['creado_por' => $usuario->id])));
        $this->assertFalse($usuario->can('novedades.editar', $this->registro(['creado_por' => $usuario->id + 1])));
    }

    public function test_un_usuario_desactivado_no_puede_nada(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $admin->forceFill(['activo' => false])->save();

        $this->assertFalse($admin->can('accesos.ver'));
    }

    public function test_un_rol_desactivado_no_cuenta(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->update(['activo' => false]);

        $this->assertFalse($agente->can('accesos.ver'));
    }

    /**
     * Registro de prueba: un modelo cualquiera con los atributos de alcance.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function registro(array $atributos): Model
    {
        $modelo = new class extends Model
        {
            protected $table = 'registros_prueba';
        };
        $modelo->setRawAttributes($atributos + ['empresa_id' => $this->empresa->id], true);
        $modelo->exists = true;

        return $modelo;
    }

    private function moduloAccion(string $modulo, string $accion): int
    {
        return Modulo::where('clave', $modulo)->firstOrFail()
            ->moduloAcciones()->whereHas('accion', fn ($q) => $q->where('clave', $accion))->value('id');
    }
}
