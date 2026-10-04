<?php

namespace Tests\Feature\Nucleo;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\Alcance;
use Database\Seeders\RolesPlantillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El Agente opera en Operación y solo consulta los Padrones (QA M-01).
 */
class PlantillaAgenteTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
    }

    private function agente(?Empresa $empresa = null): Rol
    {
        return Rol::where('empresa_id', ($empresa ?? $this->empresa)->id)->where('nombre', 'Agente')->firstOrFail();
    }

    /**
     * Permisos de la plantilla anterior: ver, crear, editar, imprimir y firmar
     * en todos los módulos de Seguridad, con alcance de su sede.
     */
    private function ponerPlantillaAnterior(Rol $rol): void
    {
        RolPermiso::where('rol_id', $rol->id)->delete();

        ModuloAccion::with('modulo.area', 'accion')->get()
            ->filter(fn ($ma) => $ma->modulo->area->clave === 'seguridad'
                && in_array($ma->accion->clave, ['ver', 'crear', 'editar', 'imprimir', 'firmar'], true))
            ->each(fn ($ma) => RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]));
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_05_000200_agente_solo_consulta_padrones.php'))->up();
    }

    public function test_el_agente_opera_en_operacion_y_solo_consulta_padrones(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        // Operación (incluidos los submódulos de Novedades)
        $this->assertTrue($agente->can('accesos.crear'));
        $this->assertTrue($agente->can('pases_salida.firmar'));
        $this->assertTrue($agente->can('lost_found.imprimir'));
        $this->assertTrue($agente->can('responsivas.editar'));

        // Padrones: solo ver
        foreach (['llaves', 'gafetes', 'vehiculos', 'visitantes', 'proveedores', 'rutas', 'equipos', 'estacionamientos', 'vouchers'] as $padron) {
            $this->assertTrue($agente->can("{$padron}.ver"), "{$padron}.ver");
            $this->assertFalse($agente->can("{$padron}.crear"), "{$padron}.crear");
            $this->assertFalse($agente->can("{$padron}.editar"), "{$padron}.editar");
        }
        $this->assertFalse($agente->can('llaves.imprimir'));
        $this->assertFalse($agente->can('vehiculos.imprimir'));
    }

    public function test_padrones_se_decide_por_el_menu_y_no_por_el_nombre(): void
    {
        $this->assertTrue(RolesPlantillaSeeder::esPadron(Modulo::where('clave', 'llaves')->firstOrFail()));
        $this->assertFalse(RolesPlantillaSeeder::esPadron(Modulo::where('clave', 'accesos')->firstOrFail()));
        $this->assertFalse(RolesPlantillaSeeder::esPadron(Modulo::where('clave', 'lost_found')->firstOrFail()));

        // Si un módulo se mueve de menú, la regla lo sigue
        Modulo::where('clave', 'llaves')->update(['menu_id' => Modulo::where('clave', 'accesos')->value('menu_id')]);
        $this->assertFalse(RolesPlantillaSeeder::esPadron(Modulo::where('clave', 'llaves')->firstOrFail()));
    }

    public function test_la_migracion_reduce_los_agentes_iguales_a_la_plantilla_anterior(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->ponerPlantillaAnterior($this->agente());
        $this->ponerPlantillaAnterior($this->agente($otra));

        // En "Hotel Dos" el cliente ya había ajustado su Agente: no se toca
        $ajustado = $this->agente($otra);
        RolPermiso::where('rol_id', $ajustado->id)->firstOrFail()->update(['alcance' => Alcance::Empresa]);
        $antes = RolPermiso::where('rol_id', $ajustado->id)->count();

        $this->migrar();

        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->assertTrue($agente->can('llaves.ver'));
        $this->assertFalse($agente->can('llaves.crear'));
        $this->assertTrue($agente->can('accesos.crear'));

        $this->assertSame($antes, RolPermiso::where('rol_id', $ajustado->id)->count());
        $deOtra = $this->crearUsuario($otra, 'Agente');
        $this->assertTrue($deOtra->can('llaves.crear'));

        // En los menús de caseta (Operación y Padrones) el resultado coincide con la
        // plantilla nueva. (Desde que Reportes se unió a Seguridad, la "plantilla
        // anterior" de esta prueba también lleva los reportes; la migración no los toca.)
        $plantilla = Rol::plantillas()->where('nombre', 'Agente')->firstOrFail();
        $deCaseta = fn (Rol $rol) => RolPermiso::where('rol_id', $rol->id)->with('moduloAccion.modulo.menu', 'moduloAccion.modulo.padre.menu')->get()
            ->filter(fn ($p) => in_array(RolesPlantillaSeeder::menuDe($p->moduloAccion->modulo), ['operacion', 'padrones'], true))
            ->pluck('modulo_accion_id')->all();
        $this->assertEqualsCanonicalizing($deCaseta($plantilla), $deCaseta($this->agente()));
    }

    public function test_la_migracion_no_toca_agentes_con_permisos_de_mas(): void
    {
        $rol = $this->agente();
        $this->ponerPlantillaAnterior($rol);
        $extra = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'accesos'))
            ->whereHas('accion', fn ($q) => $q->where('clave', 'exportar'))->firstOrFail();
        RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $extra->id, 'alcance' => Alcance::Sede]);
        $antes = RolPermiso::where('rol_id', $rol->id)->count();

        $this->migrar();

        $this->assertSame($antes, RolPermiso::where('rol_id', $rol->id)->count());
    }
}
