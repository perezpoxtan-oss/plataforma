<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Models\ZonaEstacionamiento;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Services\Estacionamientos\SinOcupacion;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class EstacionamientosTest extends TestCase
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

    private function zona(string $nombre, array $extra = [], ?Sede $sede = null, ?Empresa $empresa = null): ZonaEstacionamiento
    {
        $empresa ??= $this->empresa;
        $sede ??= $empresa->is($this->empresa) ? $this->centro : $this->crearSede($empresa, 'Z'.random_int(10, 99));

        return $this->enEmpresa(function () use ($nombre, $extra, $sede) {
            $z = new ZonaEstacionamiento(array_merge(['sede_id' => $sede->id, 'nombre' => $nombre, 'tipo' => 'estacionamiento', 'cupo_total' => 40], array_diff_key($extra, ['activo' => 1])));
            $z->forceFill(['activo' => $extra['activo'] ?? true])->save();

            return $z;
        }, $empresa);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge(['sede_id' => $this->centro->id, 'nombre' => '  Estacionamiento   Huéspedes ', 'tipo' => 'estacionamiento', 'cupo_total' => '40'], $extra);
    }

    public function test_cupos_por_sede_con_textos_de_segcat_y_ocupacion_en_cero(): void
    {
        $this->zona('Sótano 1A', ['cupo_total' => 8], $this->playa);
        $this->zona('Lobby', ['tipo' => 'zona_descarga', 'cupo_total' => null], $this->playa);
        $estac = $this->zona('Estacionamiento Huéspedes');
        $this->zona('Temporal', ['activo' => false]);

        $pagina = $this->actingAs($this->admin)->get('/estacionamientos')->assertOk()
            ->assertSee('Estacionamientos y Zonas')
            ->assertSee('Cupos por sede — Estacionamiento (cuenta espacios) o Zona de Descarga (Lobby, Almacenes, sin cupo numérico).')
            ->assertSee('Nueva Zona')->assertSee('ESTACIONAMIENTO')->assertSee('ZONA DE DESCARGA')
            ->assertSee('0 / 40 espacios')->assertSee('0 / 8 espacios')->assertSee('vehículos usando el andén ahora')
            ->assertSee('cupo-barra', false)->assertSee('Inactiva')
            ->assertSee('id="zona-'.$estac->id.'"', false)
            ->assertSee('data-filtro-sede="zonas"', false)->assertSee('data-filtro-tipo="zonas"', false)
            ->assertDontSee('LLENO')
            ->getContent();

        // Agrupadas por sede: primero Sede CEN, luego Sede PLA
        $this->assertLessThan(strpos($pagina, 'Sótano 1A'), strpos($pagina, 'Estacionamiento Huéspedes'));
        $this->assertSame(2, substr_count($pagina, 'data-grupo-zonas'));
    }

    public function test_la_ocupacion_sale_del_proveedor_de_accesos_y_marca_lleno(): void
    {
        $lleno = $this->zona('Sótano 1A', ['cupo_total' => 8]);
        $medio = $this->zona('Colaboradores', ['cupo_total' => 20]);
        $anden = $this->zona('Andén', ['tipo' => 'zona_descarga', 'cupo_total' => null]);
        $this->assertInstanceOf(SinOcupacion::class, app(OcupacionEstacionamientos::class));

        // Así se conectará la Bitácora de accesos
        $this->app->bind(OcupacionEstacionamientos::class, fn () => new class([$lleno->id => 8, $medio->id => 5, $anden->id => 1]) implements OcupacionEstacionamientos
        {
            public function __construct(private array $conteo) {}

            public function ocupados(array $zonaIds): array
            {
                return array_intersect_key($this->conteo, array_flip($zonaIds));
            }
        });

        $this->actingAs($this->admin)->get('/estacionamientos')->assertOk()
            ->assertSee('8 / 8 espacios')->assertSee('LLENO')->assertSee('cupo-barra-fill lleno', false)->assertSee('width: 100%', false)
            ->assertSee('5 / 20 espacios')->assertSee('width: 25%', false)
            ->assertSee('vehículo usando el andén ahora');
    }

    public function test_sin_zonas_muestra_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get('/estacionamientos')->assertOk()->assertSee('Aún no hay zonas configuradas.');
    }

    public function test_alta_edicion_y_validaciones(): void
    {
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos())
            ->assertSessionHas('ok', 'Zona «Estacionamiento Huéspedes» creada correctamente.');
        $zona = $this->enEmpresa(fn () => ZonaEstacionamiento::firstOrFail());
        $this->assertSame([$this->centro->id, 'estacionamiento', 40, true, $this->admin->id], [$zona->sede_id, $zona->tipo, $zona->cupo_total, $zona->activo, $zona->creado_por]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'estacionamientos.creado', 'auditable_id' => $zona->id]);

        // Nombre único por sede (sin importar mayúsculas); en otra sede sí se puede
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos(['nombre' => 'ESTACIONAMIENTO HUÉSPEDES']))
            ->assertSessionHasErrors(['nombre' => 'Ya existe una zona «Estacionamiento Huéspedes» en esa sede.']);
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos(['sede_id' => $this->playa->id]))->assertSessionHasNoErrors();

        // Cupo obligatorio (≥ 1) en estacionamientos; en descarga se guarda vacío aunque llegue
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos(['nombre' => 'Otro', 'cupo_total' => '']))
            ->assertSessionHasErrors(['cupo_total' => 'Un estacionamiento necesita su cupo total de espacios (1 o más).']);
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos(['nombre' => 'Otro', 'cupo_total' => '0']))
            ->assertSessionHasErrors(['cupo_total' => 'El cupo total debe ser de al menos 1 espacio.']);
        $this->actingAs($this->admin)->post('/estacionamientos', ['_dialogo' => 'crear'])
            ->assertSessionHasErrors(['sede_id' => 'Elige la sede de la zona.', 'nombre' => 'Escribe el nombre de la zona.']);

        $this->actingAs($this->admin)->put("/estacionamientos/{$zona->id}", $this->datos(['nombre' => 'Lobby', 'tipo' => 'zona_descarga', 'cupo_total' => '15']))
            ->assertRedirect(route('estacionamientos.index').'#zona-'.$zona->id)->assertSessionHas('ok', 'Zona «Lobby» actualizada correctamente.');
        $zona->refresh();
        $this->assertSame(['Lobby', 'zona_descarga', null], [$zona->nombre, $zona->tipo, $zona->cupo_total]);
        $auditoria = Auditoria::where('evento', 'estacionamientos.actualizado')->where('auditable_id', $zona->id)->firstOrFail();
        $this->assertSame([40, null], [$auditoria->antes['cupo_total'], $auditoria->despues['cupo_total']]);
    }

    public function test_desactivar_y_reactivar_con_auditoria(): void
    {
        $zona = $this->zona('Sótano 1A');

        $this->actingAs($this->admin)->patch("/estacionamientos/{$zona->id}/estado", ['activo' => 0])
            ->assertSessionHas('aviso', 'Zona «Sótano 1A» desactivada: ya no se podrá asignar en Accesos, pero puedes reactivarla cuando quieras.');
        $this->assertFalse($zona->fresh()->activo);
        // Desactivada también cuenta para el nombre repetido
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos(['nombre' => 'sótano 1a']))
            ->assertSessionHasErrors(['nombre' => 'Ya existe una zona «Sótano 1A» en esa sede (desactivada): reactívala en lugar de crearla otra vez.']);

        $this->actingAs($this->admin)->patch("/estacionamientos/{$zona->id}/estado", ['activo' => 1])
            ->assertSessionHas('ok', 'Zona «Sótano 1A» reactivada correctamente.');
        $this->assertTrue($zona->fresh()->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'estacionamientos.desactivado', 'auditable_id' => $zona->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'estacionamientos.reactivado', 'auditable_id' => $zona->id]);
    }

    public function test_cada_empresa_ve_y_toca_solo_sus_zonas(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajena = $this->zona('Zona Ajena', [], null, $otra);
        $this->zona('Zona Propia');

        $this->actingAs($this->admin)->get('/estacionamientos')->assertOk()->assertSee('Zona Propia')->assertDontSee('Zona Ajena');
        $this->actingAs($this->admin)->put("/estacionamientos/{$ajena->id}", $this->datos())->assertNotFound();
        $this->actingAs($this->admin)->patch("/estacionamientos/{$ajena->id}/estado", ['activo' => 0])->assertNotFound();
        $this->actingAs($this->admin)->post('/estacionamientos', $this->datos(['sede_id' => $ajena->sede_id]))
            ->assertSessionHasErrors(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
        $this->assertTrue($ajena->fresh()->activo);
    }

    public function test_alcance_de_sede_solo_ve_y_administra_sus_sedes(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $delCentro = $this->zona('Huéspedes Centro');
        $dePlaya = $this->zona('Sótano Playa', [], $this->playa);

        $pagina = $this->actingAs($jefe)->get('/estacionamientos')->assertOk()->assertSee('Huéspedes Centro')->assertDontSee('Sótano Playa')->getContent();
        $this->assertStringContainsString(route('estacionamientos.update', $delCentro->id), $pagina);

        $this->actingAs($jefe)->post('/estacionamientos', $this->datos(['sede_id' => $this->playa->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($jefe)->post('/estacionamientos', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($jefe)->put("/estacionamientos/{$dePlaya->id}", $this->datos(['sede_id' => $this->playa->id]))->assertNotFound();
        $this->actingAs($jefe)->patch("/estacionamientos/{$dePlaya->id}/estado", ['activo' => 0])->assertNotFound();
        $this->actingAs($jefe)->put("/estacionamientos/{$delCentro->id}", $this->datos(['nombre' => 'Huéspedes Centro', 'sede_id' => $this->playa->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($jefe)->patch("/estacionamientos/{$delCentro->id}/estado", ['activo' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($delCentro->fresh()->activo);
    }

    public function test_el_agente_consulta_pero_no_crea_ni_edita(): void
    {
        $zona = $this->zona('Sótano 1A');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/estacionamientos')->assertOk()->assertSee('Sótano 1A')->assertSee('0 / 40 espacios')
            ->assertDontSee('Nueva Zona')->assertDontSee('dialogoEditarZona')->assertDontSee(route('estacionamientos.estado', $zona->id));
        $this->actingAs($agente)->post('/estacionamientos', $this->datos())->assertForbidden();
        $this->actingAs($agente)->put("/estacionamientos/{$zona->id}", $this->datos())->assertForbidden();
        $this->actingAs($agente)->patch("/estacionamientos/{$zona->id}/estado", ['activo' => 0])->assertForbidden();

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->get('/estacionamientos')->assertForbidden();
    }

    public function test_menu_enlaza_a_estacionamientos(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('estacionamientos.index'));
    }
}
