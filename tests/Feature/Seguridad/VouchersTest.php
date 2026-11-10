<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\VoucherReposicion;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class VouchersTest extends TestCase
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
     * Voucher directo (Llaves y Equipos aún no existen en esta rama: el
     * origen se guarda por tipo e id).
     */
    private function voucher(string $folio, string $origen, ?Sede $sede, array $extra = [], ?Empresa $empresa = null, ?User $autor = null): VoucherReposicion
    {
        return $this->enEmpresa(function () use ($folio, $origen, $sede, $extra, $autor) {
            $v = new VoucherReposicion(array_merge([
                'sede_id' => $sede?->id, 'folio' => $folio, 'origen_tipo' => $origen, 'origen_id' => 1,
                'origen_descripcion' => strtoupper($origen).'-'.$folio, 'motivo' => 'extraviado',
            ], $extra));
            $v->forceFill(['creado_por' => ($autor ?? $this->admin)->id])->save();

            return $v;
        }, $empresa);
    }

    /**
     * @param  array<string, Alcance>  $permisos
     */
    private function usuarioCon(array $permisos, ?Sede $sede = null): User
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Rol vouchers '.uniqid(), 'nivel_jerarquia' => 40]);
        $roles->sincronizarPermisos($sa, $rol, $permisos);
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => Rol::findOrFail($rol->id)->id, 'sede_id' => $sede?->id]);

        return $usuario;
    }

    public function test_lista_con_folio_cobro_monto_origen_y_responsable(): void
    {
        $roberto = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1005', 'nombre' => 'Roberto', 'apellido_paterno' => 'Hernández']));
        $con = $this->voucher('VR-2610-00001', 'gafete', $this->centro, [
            'origen_descripcion' => 'HOT-CEN-VIS-010', 'aplica_cobro' => true, 'monto' => 150, 'colaborador_id' => $roberto->id,
            'descripcion' => 'Se fue sin devolverlo',
        ]);
        $this->voucher('VR-2610-00002', 'llave', $this->playa, ['origen_descripcion' => 'LLAVE-MAESTRA', 'motivo' => 'danado']);

        $this->actingAs($this->admin)->get('/vouchers')->assertOk()
            ->assertSee('Vouchers de Reposición')->assertSee('Historial de bajas por extravío, daño o robo')
            ->assertSee('VR-2610-00001')->assertSee('CON COBRO')->assertSee('$150.00')->assertSee('HOT-CEN-VIS-010')
            ->assertSee('Roberto Hernández')->assertSee('Se fue sin devolverlo')->assertSee('Sede CEN')
            ->assertSee('VR-2610-00002')->assertSee('SIN COBRO')->assertSee('Dañado')->assertSee('No especificado')
            ->assertSee('Generado por')->assertSee(route('vouchers.imprimir', $con->id))
            ->assertSee('2 vouchers')->assertDontSee('onclick');
    }

    public function test_filtros_por_sede_origen_cobro_fechas_y_texto(): void
    {
        $this->voucher('VR-2610-00001', 'gafete', $this->centro, ['aplica_cobro' => true, 'monto' => 80]);
        $viejo = $this->voucher('VR-2601-00002', 'llave', $this->playa);
        $this->enEmpresa(fn () => $viejo->forceFill(['created_at' => Carbon::parse('2026-01-15 18:00:00', 'UTC')])->save());

        $ver = fn (string $q) => $this->actingAs($this->admin)->get('/vouchers?'.$q)->assertOk();

        $ver('sede='.$this->centro->id)->assertSee('VR-2610-00001')->assertDontSee('VR-2601-00002');
        $ver('origen=llave')->assertSee('VR-2601-00002')->assertDontSee('VR-2610-00001');
        $ver('cobro=1')->assertSee('VR-2610-00001')->assertDontSee('VR-2601-00002');
        $ver('cobro=0')->assertSee('VR-2601-00002')->assertDontSee('VR-2610-00001');
        $ver('desde=2026-01-01&hasta=2026-01-31')->assertSee('VR-2601-00002')->assertDontSee('VR-2610-00001');
        $ver('q=00001')->assertSee('VR-2610-00001')->assertDontSee('VR-2601-00002')->assertSee('Quitar filtros');
        $ver('q=nadaquecoincida')->assertSee('No hay vouchers que coincidan con tu búsqueda.');
        // Valores inválidos se ignoran
        $ver('desde=2026-13-45&origen=DROP&cobro=x')->assertSee('VR-2610-00001')->assertSee('VR-2601-00002');
    }

    public function test_sin_vouchers_muestra_el_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get('/vouchers')->assertOk()
            ->assertSee('Todavía no se ha generado ningún voucher.')
            ->assertSee('Se generan solos al dar de baja una llave, un gafete o un equipo');
    }

    public function test_solo_ve_vouchers_de_los_modulos_que_puede_consultar(): void
    {
        $this->voucher('VR-2610-00001', 'gafete', $this->centro);
        $this->voucher('VR-2610-00002', 'llave', $this->centro);

        $soloGafetes = $this->usuarioCon(['vouchers.ver' => Alcance::Empresa, 'vouchers.imprimir' => Alcance::Empresa, 'gafetes.ver' => Alcance::Empresa]);
        $llave = $this->enEmpresa(fn () => VoucherReposicion::where('origen_tipo', 'llave')->firstOrFail());

        $this->actingAs($soloGafetes)->get('/vouchers')->assertOk()->assertSee('VR-2610-00001')->assertDontSee('VR-2610-00002');
        $this->actingAs($soloGafetes)->get("/vouchers/{$llave->id}/imprimir")->assertNotFound();

        // Sin permiso de ningún módulo de origen: aviso claro
        $ninguno = $this->usuarioCon(['vouchers.ver' => Alcance::Empresa]);
        $this->actingAs($ninguno)->get('/vouchers')->assertOk()->assertDontSee('VR-2610-00001')
            ->assertSee('no tienes permiso de consultar ninguno de ellos');
    }

    public function test_con_alcance_de_sede_solo_ve_los_de_su_sede(): void
    {
        $this->voucher('VR-2610-00001', 'gafete', $this->centro);
        $dePlaya = $this->voucher('VR-2610-00002', 'gafete', $this->playa);
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        $this->actingAs($jefe)->get('/vouchers')->assertOk()->assertSee('VR-2610-00001')->assertDontSee('VR-2610-00002');
        $this->actingAs($jefe)->get("/vouchers/{$dePlaya->id}/imprimir")->assertNotFound();
        $this->actingAs($jefe)->get('/vouchers?sede='.$this->playa->id)->assertDontSee('VR-2610-00002');
    }

    public function test_impresion_de_tres_copias_en_una_hoja(): void
    {
        $roberto = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1005', 'nombre' => 'Roberto', 'apellido_paterno' => 'Hernández']));
        $v = $this->voucher('VR-2610-00001', 'gafete', $this->centro, [
            'origen_descripcion' => 'HOT-CEN-VIS-010', 'aplica_cobro' => true, 'monto' => 150, 'colaborador_id' => $roberto->id, 'descripcion' => "Línea 1\n<b>Línea 2</b>",
        ]);

        $html = $this->actingAs($this->admin)->get("/vouchers/{$v->id}/imprimir")->assertOk()
            ->assertSee('Voucher de Reposición — 3 copias en una sola hoja')
            // Ronda 5 (LL-04): Seguridad, Recepción y Administración; el colaborador no recibe copia
            ->assertSee('Copia Seguridad')->assertSee('Copia Recepción')->assertSee('Copia Administración')->assertDontSee('Copia Colaborador')
            ->assertSee('VR-2610-00001')->assertSee('Hotel Uno')->assertSee('Sede CEN')->assertSee('HOT-CEN-VIS-010')
            ->assertSee('Roberto Hernández (Núm. 1005)')->assertSee('$150.00 MXN')->assertSee('Referencia de pago (a mano):')
            ->assertSee('Extraviado')->assertSee('&lt;b&gt;Línea 2&lt;/b&gt;', false)
            ->getContent();
        $this->assertSame(3, substr_count($html, 'class="copia-voucher"'));
        $this->assertStringNotContainsString('onclick', $html);

        $sinCobro = $this->voucher('VR-2610-00003', 'gafete', $this->centro);
        $this->actingAs($this->admin)->get("/vouchers/{$sinCobro->id}/imprimir")->assertOk()->assertSee('NO APLICA')->assertDontSee('Referencia de pago');
    }

    public function test_cada_empresa_ve_solo_sus_vouchers(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->voucher('VR-2610-99999', 'gafete', $this->crearSede($otra, 'OTR'), [], $otra);

        $this->actingAs($this->admin)->get('/vouchers')->assertDontSee('VR-2610-99999');
        $this->actingAs($this->admin)->get("/vouchers/{$ajeno->id}/imprimir")->assertNotFound();
    }

    public function test_el_agente_consulta_pero_no_imprime_y_son_de_solo_lectura(): void
    {
        $v = $this->voucher('VR-2610-00001', 'gafete', $this->centro);
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/vouchers')->assertOk()->assertSee('VR-2610-00001')->assertDontSee(route('vouchers.imprimir', $v->id));
        $this->actingAs($agente)->get("/vouchers/{$v->id}/imprimir")->assertForbidden();
        // No hay alta, edición ni borrado de vouchers
        $this->actingAs($this->admin)->post('/vouchers', [])->assertStatus(405);
        $this->assertContains($this->actingAs($this->admin)->delete("/vouchers/{$v->id}")->status(), [404, 405]);

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->get('/vouchers')->assertForbidden();
    }

    public function test_unir_un_colaborador_duplicado_mueve_sus_vouchers(): void
    {
        [$provisional, $destino] = $this->enEmpresa(function () {
            $p = Colaborador::create(['num_empleado' => 'P-1', 'nombre' => 'Beto', 'apellido_paterno' => 'Hernández']);
            $p->forceFill(['provisional' => true])->save();

            return [$p, Colaborador::create(['num_empleado' => '1005', 'nombre' => 'Roberto', 'apellido_paterno' => 'Hernández'])];
        });
        $v = $this->voucher('VR-2610-00001', 'gafete', $this->centro, ['aplica_cobro' => true, 'monto' => 50, 'colaborador_id' => $provisional->id]);

        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->fusionar($this->admin, $provisional, $destino));

        $this->assertSame($destino->id, $this->enEmpresa(fn () => $v->fresh()->colaborador_id));
    }
}
