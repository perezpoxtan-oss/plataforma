<?php

namespace Tests\Feature\Nucleo;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rol "Asistente" de nivel 40, como en SEGCAT (QA U-02).
 */
class PlantillaAsistenteTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    private function asistente(?Empresa $empresa): ?Rol
    {
        return Rol::where('empresa_id', $empresa?->id)->where('nombre', 'Asistente')->first();
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_06_000100_agregar_rol_asistente.php'))->up();
    }

    /**
     * @return list<string> módulo.acción con alcance
     */
    private function permisos(Rol $rol): array
    {
        return RolPermiso::where('rol_id', $rol->id)->with('moduloAccion.modulo', 'moduloAccion.accion')->get()
            ->map(fn ($p) => $p->moduloAccion->clave().':'.$p->alcance->value)
            ->sort()->values()->all();
    }

    public function test_la_plantilla_existe_en_nivel_40_con_alcance_de_sede(): void
    {
        $plantilla = $this->asistente(null);

        $this->assertNotNull($plantilla);
        $this->assertSame(40, $plantilla->nivel_jerarquia);
        $this->assertSame('Apoyo de gestión de seguridad en su sede', $plantilla->descripcion);
        $this->assertSame(['sede'], RolPermiso::where('rol_id', $plantilla->id)->pluck('alcance')->map->value->unique()->values()->all());
    }

    public function test_una_empresa_nueva_recibe_el_asistente(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuario($empresa, 'Asistente');

        $this->assertSame(40, $this->asistente($empresa)->nivel_jerarquia);

        // Operación y Padrones: captura, corrige, imprime y exporta
        $this->assertTrue($usuario->can('accesos.crear'));
        $this->assertTrue($usuario->can('accesos.exportar'));
        $this->assertTrue($usuario->can('llaves.crear'));
        $this->assertTrue($usuario->can('llaves.editar'));
        $this->assertTrue($usuario->can('vehiculos.imprimir'));
        $this->assertTrue($usuario->can('bitacora_dia.exportar'));

        // No elimina, no aprueba ni firma; nada de administración
        $this->assertFalse($usuario->can('accesos.eliminar'));
        $this->assertFalse($usuario->can('accesos.aprobar'));
        $this->assertFalse($usuario->can('responsivas.firmar'));
        $this->assertFalse($usuario->can('usuarios.ver'));
        $this->assertFalse($usuario->can('sedes.ver'));
    }

    public function test_la_migracion_agrega_el_asistente_a_empresas_existentes_sin_duplicar(): void
    {
        $empresa = $this->crearEmpresa('Hotel Antiguo');
        $conAsistente = $this->crearEmpresa('Hotel Al Día');

        // Base anterior: ni la plantilla ni el rol de la empresa existían
        $this->asistente($empresa)->delete();
        $this->asistente(null)->delete();

        $this->migrar();
        $this->migrar(); // idempotente

        $plantilla = $this->asistente(null);
        $this->assertNotNull($plantilla);
        $this->assertSame(1, Rol::whereNull('empresa_id')->where('nombre', 'Asistente')->count());

        $copia = $this->asistente($empresa);
        $this->assertNotNull($copia);
        $this->assertSame(40, $copia->nivel_jerarquia);
        $this->assertSame($this->permisos($plantilla), $this->permisos($copia));

        foreach ([$empresa, $conAsistente] as $e) {
            $this->assertSame(1, Rol::where('empresa_id', $e->id)->where('nombre', 'Asistente')->count());
        }
    }

    public function test_la_migracion_respeta_un_nivel_40_propio_de_la_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $this->asistente($empresa)->delete();
        app(AdministradorRoles::class)->crearRol($this->crearSuperadmin(), $empresa->id, ['nombre' => 'Coordinador', 'nivel_jerarquia' => 40]);

        $this->migrar();

        $this->assertNull($this->asistente($empresa));
        $this->assertSame('Coordinador', Rol::where('empresa_id', $empresa->id)->where('nivel_jerarquia', 40)->value('nombre'));
    }

    public function test_el_seeder_sigue_siendo_idempotente(): void
    {
        $antes = [Rol::count(), RolPermiso::count()];

        $this->sembrarCatalogo();

        $this->assertSame($antes, [Rol::count(), RolPermiso::count()]);
    }
}
