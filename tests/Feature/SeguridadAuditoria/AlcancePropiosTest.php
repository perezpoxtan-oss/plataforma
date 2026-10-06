<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AZ-04: el alcance "Solo los propios" en un rol sin sede fija se trataba
 * como "Toda la empresa" en las pantallas que solo preguntaban por sedes
 * (Autorizador::sedesPermitidas() da null cuando no hay sede).
 */
class AlcancePropiosTest extends TestCase
{
    use EscenarioAuditoria, RefreshDatabase;

    private User $propio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();

        $rol = Rol::create(['empresa_id' => $this->demo->id, 'nombre' => 'Solo lo mío', 'nivel_jerarquia' => 80]);
        $claves = [
            'auditoria.ver', 'proveedores.ver', 'proveedores.editar', 'proveedores.eliminar', 'departamentos.ver', 'departamentos.editar',
            'turnos.ver', 'turnos.editar', 'sedes.ver', 'sedes.editar', 'sedes.eliminar', 'espacios.ver', 'espacios.editar', 'espacios.eliminar',
            'vehiculos.ver', 'vehiculos.imprimir', 'llaves.ver', 'prestamo_llaves.ver', 'roles.ver', 'roles.editar',
        ];
        foreach ($claves as $clave) {
            [$modulo, $accion] = explode('.', $clave);
            $id = DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->where('m.clave', $modulo)->where('a.clave', $accion)->value('ma.id');
            $this->assertNotNull($id, $clave);
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $id, 'alcance' => Alcance::Propios]);
        }
        // Sin sede fija: "solo los propios" en todas las sedes
        $this->propio = $this->crearUsuario($this->demo, 'Solo lo mío');
        app(Autorizador::class)->olvidar();
    }

    private function deOtro(string $tabla): object
    {
        return DB::table($tabla)->where('empresa_id', $this->demo->id)->where(fn ($q) => $q->whereNull('creado_por')->orWhere('creado_por', '!=', $this->propio->id))->orderBy('id')->firstOrFail();
    }

    public function test_az04_la_bitacora_de_auditoria_solo_muestra_lo_suyo(): void
    {
        $ajeno = DB::table('auditoria')->where('empresa_id', $this->demo->id)->where('evento', 'like', 'llaves.%')->firstOrFail();
        $this->assertNotSame($this->propio->id, (int) $ajeno->user_id);

        $this->actingAs($this->propio)->get('/auditoria?modulo=llaves')->assertOk()->assertSee('Ves solo tus propios movimientos')
            ->assertDontSee('Ana Administradora');
    }

    public function test_az04_proveedores_de_otro_no_se_ven_ni_se_modifican(): void
    {
        $ajeno = $this->deOtro('proveedores');

        $this->actingAs($this->propio)->get("/proveedores/{$ajeno->id}")->assertNotFound();
        $this->actingAs($this->propio)->put("/proveedores/{$ajeno->id}", ['nombre' => 'Hackeado', 'categoria' => $ajeno->categoria])->assertNotFound();
        $this->actingAs($this->propio)->put("/proveedores/{$ajeno->id}/sedes", ['todas_las_sedes' => '0', 'sedes' => []])->assertNotFound();
        $this->actingAs($this->propio)->patch("/proveedores/{$ajeno->id}/estado", ['activo' => '0'])->assertNotFound();

        $fresco = DB::table('proveedores')->find($ajeno->id);
        $this->assertSame($ajeno->nombre, $fresco->nombre);
        $this->assertSame((int) $ajeno->activo, (int) $fresco->activo);
        $this->assertSame((int) $ajeno->todas_las_sedes, (int) $fresco->todas_las_sedes);
    }

    public function test_az04_catalogos_sedes_y_espacios_de_toda_la_empresa_no_se_modifican(): void
    {
        $departamento = $this->deOtro('departamentos');
        $turno = $this->deOtro('turnos');
        $espacio = $this->deOtro('espacios');
        $antes = $this->huellaDatos();

        $this->actingAs($this->propio)->put("/departamentos/{$departamento->id}", ['nombre' => 'Hackeo', 'todas_las_sedes' => '1'])->assertForbidden();
        $this->actingAs($this->propio)->put("/turnos/{$turno->id}", ['nombre' => 'Hackeo', 'hora_inicio' => '01:00', 'hora_fin' => '02:00'])->assertForbidden();
        $this->actingAs($this->propio)->put("/turnos/{$turno->id}/sedes", ['todas_las_sedes' => '0', 'sedes' => []])->assertForbidden();
        $this->actingAs($this->propio)->put("/sedes/{$this->centro->id}", ['nombre' => 'Hackeo', 'codigo' => 'CEN', 'ciudad' => 'X', 'entidad' => 'Y'])->assertNotFound();
        $this->actingAs($this->propio)->patch("/sedes/{$this->playa->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->actingAs($this->propio)->put("/espacios/{$espacio->id}", ['nombre' => 'Hackeo'])->assertForbidden();
        $this->actingAs($this->propio)->patch("/espacios/{$espacio->id}/estado", ['activo' => '0'])->assertForbidden();

        $this->assertSame($antes, $this->huellaDatos());
    }

    public function test_az04_calcomania_historial_y_roles_ajenos(): void
    {
        $vehiculo = $this->deOtro('vehiculos');
        $this->actingAs($this->propio)->get("/vehiculos/{$vehiculo->id}/calcomania")->assertNotFound();

        // Historial de una llave: sin los préstamos que registraron otros
        $prestamo = DB::table('prestamos_llaves')->where('empresa_id', $this->demo->id)->orderBy('id')->firstOrFail();
        $folio = DB::table('colaboradores')->where('id', $prestamo->colaborador_id)->value('nombre');
        $this->actingAs($this->propio)->get("/prestamo-llaves/llaves/{$prestamo->llave_id}/historial")->assertOk()->assertDontSee($folio);

        // Un rol que dio de alta otro
        $agente = Rol::where('empresa_id', $this->demo->id)->where('nombre', 'Agente')->firstOrFail();
        $this->actingAs($this->propio)->put("/roles/{$agente->id}", ['nombre' => 'Agente', 'nivel_jerarquia' => 61])->assertSessionHas('error');
        $this->assertSame(60, $agente->fresh()->nivel_jerarquia);
    }
}
