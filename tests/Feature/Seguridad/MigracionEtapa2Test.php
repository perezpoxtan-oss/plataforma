<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Migraciones de la Etapa 2 (2026_11_11_000100 a 000500): con datos,
 * migrar → revertir → migrar deja todo igual; la conversión de equipos
 * (responsiva por turno → EN USO) y de empresas vetadas → restringidas va y
 * regresa; la del receptor se niega a revertir si ya hay externos.
 */
class MigracionEtapa2Test extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIGRACIONES = [
        '2026_11_11_000100_estados_unicos_de_activos',
        '2026_11_11_000200_receptor_general_de_activos',
        '2026_11_11_000300_gafete_e_identificacion_al_dar_salida',
        '2026_11_11_000400_motivo_de_excepcion_en_prestamos',
        '2026_11_11_000500_restringir_acceso_en_padrones',
    ];

    private Empresa $empresa;

    private Sede $sede;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->sede = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function migracion(string $nombre): object
    {
        return require database_path("migrations/{$nombre}.php");
    }

    private function revertirTodo(): void
    {
        foreach (array_reverse(self::MIGRACIONES) as $m) {
            $this->migracion($m)->down();
        }
    }

    private function migrarTodo(): void
    {
        foreach (self::MIGRACIONES as $m) {
            $this->migracion($m)->up();
        }
    }

    private function firma(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_migrar_revertir_y_migrar_con_datos(): void
    {
        [$radio, $chaleco, $vetada, $activa] = app(Tenant::class)->conEmpresa($this->empresa->id, function () {
            $tipo = TipoEquipo::firstOrCreate(['nombre' => 'Radio'])->id;
            $radio = Equipo::create(['sede_id' => $this->sede->id, 'tipo_equipo_id' => $tipo, 'numero_serie' => 'RAD-1']);
            $chaleco = Equipo::create(['sede_id' => $this->sede->id, 'tipo_equipo_id' => $tipo, 'numero_serie' => 'CH-1']);
            $vetada = Proveedor::create(['nombre' => 'Elevadores del Sur', 'categoria' => 'contratista', 'todas_las_sedes' => true]);
            $activa = Proveedor::create(['nombre' => 'Limpieza Total', 'categoria' => 'proveedor', 'todas_las_sedes' => true]);

            return [$radio, $chaleco, $vetada, $activa];
        });
        $colaborador = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Colaborador::create(['num_empleado' => '7', 'nombre' => 'Ana', 'apellido_paterno' => 'López', 'sede_id' => $this->sede->id]));
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->sede->id, 'colaborador_id' => $colaborador->id,
            'equipos' => [$radio->id, $chaleco->id], 'modalidades' => ['prestado', 'asignado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $this->assertSame(['en_uso', 'asignado'], [$radio->fresh()->estado, $chaleco->fresh()->estado]);

        // Como estaba antes: la empresa «de baja» (vetada)
        $this->revertirTodo();
        foreach ([['llaves', 'estado'], ['gafetes', 'estado'], ['prestamos_llaves', 'receptor_tipo'], ['responsivas', 'receptor_tipo'],
            ['vouchers_reposicion', 'responsable_tipo'], ['accesos', 'gafete_no_regreso'], ['accesos', 'aviso_padron'],
            ['prestamos_llaves', 'excepcion_motivo'], ['proveedores', 'restringido_en'], ['personas', 'restringido_en'], ['vehiculos', 'restringido_en']] as [$tabla, $columna]) {
            $this->assertFalse(Schema::hasColumn($tabla, $columna), "{$tabla}.{$columna} debió quitarse");
        }
        $this->assertSame(['asignado', 'asignado'], DB::table('equipos')->whereIn('id', [$radio->id, $chaleco->id])->orderBy('id')->pluck('estado')->all());
        DB::table('proveedores')->where('id', $vetada->id)->update(['activo' => false]);

        // Migrar: turno → EN USO, vetada → activa y restringida
        $this->migrarTodo();
        $this->assertSame(['en_uso', 'asignado'], DB::table('equipos')->whereIn('id', [$radio->id, $chaleco->id])->orderBy('id')->pluck('estado')->all());
        $p = DB::table('proveedores')->where('id', $vetada->id)->first();
        $this->assertTrue((bool) $p->activo);
        $this->assertNotNull($p->restringido_en);
        $this->assertStringContainsString('vetada', (string) $p->restringido_motivo);
        $this->assertNull(DB::table('proveedores')->where('id', $activa->id)->value('restringido_en'));
        $this->assertSame('colaborador', DB::table('responsivas')->value('receptor_tipo'));

        // Revertir regresa la vetada a «de baja»; y se puede volver a migrar
        $this->revertirTodo();
        $this->assertFalse((bool) DB::table('proveedores')->where('id', $vetada->id)->value('activo'));
        $this->assertTrue((bool) DB::table('proveedores')->where('id', $activa->id)->value('activo'));
        $this->migrarTodo();
        $this->assertSame(['en_uso', 'asignado'], DB::table('equipos')->whereIn('id', [$radio->id, $chaleco->id])->orderBy('id')->pluck('estado')->all());
    }

    public function test_el_receptor_no_se_revierte_si_ya_hay_entregas_a_externos(): void
    {
        $proveedor = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Proveedor::create(['nombre' => 'Elevadores del Sur', 'categoria' => 'contratista', 'todas_las_sedes' => true]));
        $equipo = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Equipo::create(['sede_id' => $this->sede->id, 'tipo_equipo_id' => TipoEquipo::firstOrCreate(['nombre' => 'Radio'])->id, 'numero_serie' => 'RAD-9']));
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->sede->id, 'receptor_tipo' => 'proveedor', 'receptor_id' => $proveedor->id,
            'equipos' => [$equipo->id], 'modalidades' => ['asignado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('responsivas')->value('colaborador_id'));

        try {
            $this->migracion('2026_11_11_000200_receptor_general_de_activos')->down();
            $this->fail('Debió detenerse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('entregados a personas o empresas externas', $e->getMessage());
        }
        // No tocó nada
        $this->assertTrue(Schema::hasColumn('responsivas', 'receptor_tipo'));
        $this->assertSame('proveedor', DB::table('responsivas')->value('receptor_tipo'));
    }
}
