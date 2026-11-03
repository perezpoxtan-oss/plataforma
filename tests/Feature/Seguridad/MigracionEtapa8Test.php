<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Activos\HistorialActivos;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Migraciones de la Etapa 8 (2026_11_18_000100 a 000300): con datos,
 * migrar → revertir → migrar deja el esquema y los permisos como estaban; los
 * códigos QR de personas y empresas se generan de nuevo y el permiso
 * «Colaboradores → Credencial» vuelve a quien edita colaboradores.
 */
class MigracionEtapa8Test extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIGRACIONES = [
        '2026_11_18_000100_personas_y_empresas_externas_en_el_lector',
        '2026_11_18_000200_crear_movimientos_activo',
        '2026_11_18_000300_credenciales_de_colaboradores',
        '2026_11_18_000400_credenciales_fase_uno',
        // Decisión del dueño (3 nov): tipo de tarjeta de la credencial (depende de la tabla credenciales)
        '2026_11_19_000200_tarjetas_de_credenciales',
    ];

    private Empresa $empresa;

    private Sede $sede;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->sede = $this->crearSede($this->empresa, 'CEN');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
    }

    protected function tearDown(): void
    {
        HistorialActivos::olvidarTabla();
        parent::tearDown();
    }

    private function migracion(string $nombre): object
    {
        return require database_path("migrations/{$nombre}.php");
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @return array<string, mixed> */
    private function esquema(): array
    {
        return [
            'personas' => Schema::getColumnListing('personas'),
            'proveedores' => Schema::getColumnListing('proveedores'),
            'colaboradores' => Schema::getColumnListing('colaboradores'),
            'movimientos_activo' => Schema::hasTable('movimientos_activo') ? Schema::getColumnListing('movimientos_activo') : null,
            'credenciales' => Schema::hasTable('credenciales') ? Schema::getColumnListing('credenciales') : null,
            'permisos_credencial' => DB::table('rol_permisos as rp')->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
                ->join('acciones as a', 'a.id', '=', 'ma.accion_id')->where('a.clave', 'credencial')->orderBy('rp.rol_id')->get(['rp.rol_id', 'rp.alcance'])
                ->map(fn ($p) => $p->rol_id.':'.$p->alcance)->all(),
        ];
    }

    public function test_migrar_revertir_y_migrar_con_datos(): void
    {
        $persona = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Ana Externa']));
        $proveedor = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Limpieza', 'categoria' => 'proveedor', 'todas_las_sedes' => true]));
        $c = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1', 'nombre' => 'Eva', 'apellido_paterno' => 'Paz', 'sede_id' => $this->sede->id]));
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('credenciales')->count());
        $this->assertGreaterThan(0, DB::table('movimientos_activo')->count());
        $antes = $this->esquema();
        $this->assertNotSame([], $antes['permisos_credencial']);

        foreach (array_reverse(self::MIGRACIONES) as $m) {
            $this->migracion($m)->down();
        }
        HistorialActivos::olvidarTabla();
        $this->assertFalse(Schema::hasTable('credenciales'));
        $this->assertFalse(Schema::hasTable('movimientos_activo'));
        $this->assertFalse(Schema::hasColumn('personas', 'codigo_qr'));
        $this->assertFalse(Schema::hasColumn('proveedores', 'etiqueta_nfc'));
        $this->assertFalse(DB::table('acciones')->where('clave', 'credencial')->exists());
        $this->assertFalse(Schema::hasColumn('colaboradores', 'foto_credencial_ruta'));
        // Los datos de los padrones siguen ahí
        $this->assertSame('Ana Externa', DB::table('personas')->where('id', $persona->id)->value('nombre_completo'));

        foreach (self::MIGRACIONES as $m) {
            $this->migracion($m)->up();
        }
        HistorialActivos::olvidarTabla();
        $despues = $this->esquema();
        $this->assertSame($antes, $despues);
        // Los que ya existían reciben su código otra vez
        $this->assertSame(24, strlen((string) DB::table('personas')->where('id', $persona->id)->value('codigo_qr')));
        $this->assertSame(24, strlen((string) DB::table('proveedores')->where('id', $proveedor->id)->value('codigo_qr')));
        // Y la credencialización vuelve a funcionar
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame('CRED-000001', $this->enEmpresa(fn () => Credencial::firstOrFail()->folio));
    }
}
