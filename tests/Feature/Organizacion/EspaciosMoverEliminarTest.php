<?php

namespace Tests\Feature\Organizacion;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Zonas y edificios: «Mover a…» y «Eliminar definitivamente las marcadas».
 */
class EspaciosMoverEliminarTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $sede;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->sede = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function nuevo(string $nivel, string $nombre, ?Espacio $padre = null, ?Sede $sede = null): Espacio
    {
        $datos = ['nivel' => $nivel, 'nombre' => $nombre] + ($padre ? ['padre_id' => $padre->id] : ['sede_id' => ($sede ?? $this->sede)->id]);
        $this->actingAs($this->admin)->post('/espacios', $datos)->assertSessionHasNoErrors();

        return app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Espacio::where('nombre', $nombre)
            ->when($padre, fn ($q) => $q->where('padre_id', $padre->id), fn ($q) => $q->whereNull('padre_id'))->firstOrFail());
    }

    private function recargar(Espacio $e): Espacio
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Espacio::findOrFail($e->id));
    }

    private function llave(string $nomenclatura, Espacio $espacio): void
    {
        $id = DB::table('llaves')->insertGetId([
            'empresa_id' => $this->empresa->id, 'sede_id' => $espacio->sede_id, 'nomenclatura' => $nomenclatura,
            'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'zona', 'codigo_qr' => substr(md5($nomenclatura), 0, 16), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('espacio_llave')->insert(['llave_id' => $id, 'espacio_id' => $espacio->id]);
    }

    public function test_mueve_una_habitacion_con_lo_que_tiene_dentro_y_conserva_su_id(): void
    {
        $torre = $this->nuevo('edificio', 'Torre A');
        $piso1 = $this->nuevo('area', 'Piso 1', $torre);
        $piso2 = $this->nuevo('area', 'Piso 2', $torre);
        $hab = $this->nuevo('area_especifica', '101', $piso1);
        $bano = $this->nuevo('subarea', 'Baño', $hab);
        $lavabo = $this->nuevo('elemento', 'Lavabo', $bano);
        $this->llave('HDC-101', $hab);

        // La lista de destinos ofrece el otro piso (o la zona directo, según Espacio::PADRES), no el actual
        $this->actingAs($this->admin)->getJson("/espacios/{$hab->id}/destinos")->assertOk()
            ->assertJsonPath('destinos', [['id' => $torre->id, 'nombre' => 'Torre A'], ['id' => $piso2->id, 'nombre' => 'Torre A › Piso 2']]);
        $this->actingAs($this->admin)->get("/espacios/{$piso1->id}")->assertSee('data-accion="mover-espacio"', false)->assertSee('Dar de baja');

        $this->actingAs($this->admin)->from("/espacios/{$piso1->id}")->patch("/espacios/{$hab->id}/mover", ['destino_id' => $piso2->id])
            ->assertRedirect("/espacios/{$piso1->id}")->assertSessionHasNoErrors()
            ->assertSessionHas('ok', '«101» se movió a «Piso 2» con todo lo que tiene dentro.');

        $hab = $this->recargar($hab);
        $this->assertSame($piso2->id, $hab->padre_id);
        $this->assertSame("/{$torre->id}/{$piso2->id}/{$hab->id}/", $hab->ruta);
        $this->assertSame("/{$torre->id}/{$piso2->id}/{$hab->id}/{$bano->id}/{$lavabo->id}/", $this->recargar($lavabo)->ruta);
        $this->assertSame(4, $this->recargar($lavabo)->profundidad);
        $this->assertDatabaseHas('espacio_llave', ['espacio_id' => $hab->id]);

        // Bitácora de auditoría con el lugar de antes y después
        $evento = Auditoria::where('evento', 'espacios.movido')->sole();
        $this->assertSame($hab->id, (int) $evento->auditable_id);
        $this->assertSame('Torre A › Piso 1', $evento->antes['lugar']);
        $this->assertSame('Torre A › Piso 2', $evento->despues['lugar']);

        // Un piso cambia de zona y arrastra sus habitaciones
        $torreB = $this->nuevo('edificio', 'Torre B');
        $this->actingAs($this->admin)->patch("/espacios/{$piso2->id}/mover", ['destino_id' => $torreB->id])->assertSessionHasNoErrors();
        $this->assertSame("/{$torreB->id}/{$piso2->id}/{$hab->id}/{$bano->id}/", $this->recargar($bano)->ruta);
    }

    public function test_mover_rechaza_nivel_invalido_descendiente_inactivo_y_nombre_repetido(): void
    {
        $torre = $this->nuevo('edificio', 'Torre A');
        $piso1 = $this->nuevo('area', 'Piso 1', $torre);
        $piso2 = $this->nuevo('area', 'Piso 2', $torre);
        $hab = $this->nuevo('area_especifica', '101', $piso1);
        $bano = $this->nuevo('subarea', 'Baño', $hab);
        $this->nuevo('area_especifica', '101', $piso2);
        $otra = $this->nuevo('area_especifica', '102', $piso2);

        // Nivel: una habitación no va dentro de otra habitación
        $this->actingAs($this->admin)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $otra->id])
            ->assertSessionHasErrors(['destino_id' => '«101» solo puede ir dentro de: piso o zona / edificio.']);
        // Dentro de sí mismo o de lo que tiene adentro
        $this->actingAs($this->admin)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $bano->id])
            ->assertSessionHasErrors(['destino_id' => 'No se puede mover «101» dentro de sí mismo ni dentro de lo que tiene adentro.']);
        $this->actingAs($this->admin)->patch("/espacios/{$torre->id}/mover", ['destino_id' => $piso1->id])->assertSessionHasErrors('destino_id');
        // Nombre repetido en el destino
        $this->actingAs($this->admin)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $piso2->id])
            ->assertSessionHasErrors(['destino_id' => 'Ya existe «101» en ese piso.']);
        // Destino inactivo
        $piso3 = $this->nuevo('area', 'Piso 3', $torre);
        $this->actingAs($this->admin)->patch("/espacios/{$piso3->id}/estado", ['activo' => '0']);
        $this->actingAs($this->admin)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $piso3->id])
            ->assertSessionHasErrors(['destino_id' => '«Piso 3» está desactivado: reactívalo o elige otro lugar.']);

        $this->assertSame($piso1->id, $this->recargar($hab)->padre_id);
        $this->assertSame(0, Auditoria::where('evento', 'espacios.movido')->count());
    }

    public function test_mover_solo_dentro_de_la_misma_sede_y_empresa(): void
    {
        $torre = $this->nuevo('edificio', 'Torre A');
        $piso = $this->nuevo('area', 'Piso 1', $torre);
        $hab = $this->nuevo('area_especifica', '101', $piso);
        $playa = $this->crearSede($this->empresa, 'PLA');
        $villas = $this->nuevo('edificio', 'Villas', null, $playa);
        $pisoPlaya = $this->nuevo('area', 'Planta baja', $villas);

        $this->actingAs($this->admin)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $pisoPlaya->id])
            ->assertSessionHasErrors(['destino_id' => 'Solo se puede mover dentro de la misma sede.']);
        $this->actingAs($this->admin)->getJson("/espacios/{$hab->id}/destinos")->assertJsonPath('destinos', [['id' => $torre->id, 'nombre' => 'Torre A']]);

        // Otra empresa no ve ni mueve
        $otraEmpresa = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($otraEmpresa, 'OTR');
        $ajeno = $this->crearUsuario($otraEmpresa, 'Administrador');
        $this->actingAs($ajeno)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $piso->id])->assertNotFound();
        $this->actingAs($ajeno)->getJson("/espacios/{$hab->id}/destinos")->assertNotFound();

        // Sin espacios.editar no hay «Mover a…»
        $sa = $this->crearSuperadmin();
        $rol = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Consulta', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $rol, ['espacios.ver' => Alcance::Empresa]);
        $consulta = $this->crearUsuario($this->empresa, 'Consulta');
        $this->actingAs($consulta)->get("/espacios/{$piso->id}")->assertOk()->assertDontSee('data-accion="mover-espacio"', false);
        $this->actingAs($consulta)->patch("/espacios/{$hab->id}/mover", ['destino_id' => $piso->id])->assertForbidden();
    }

    public function test_elimina_varias_solo_las_que_nadie_uso_y_dice_por_que_no_las_demas(): void
    {
        $torre = $this->nuevo('edificio', 'Torre A');
        $piso = $this->nuevo('area', 'Piso 1', $torre);
        $libre1 = $this->nuevo('area_especifica', '103', $piso);
        $libre2 = $this->nuevo('area_especifica', '104', $piso);
        $conArea = $this->nuevo('area_especifica', '105', $piso);
        $bano = $this->nuevo('subarea', 'Baño', $conArea);
        $usada = $this->nuevo('area_especifica', '101', $piso);
        $this->llave('HDC-101', $usada);
        $usadaDentro = $this->nuevo('area_especifica', '102', $piso);
        $terraza = $this->nuevo('subarea', 'Terraza', $usadaDentro);
        $this->llave('HDC-102T', $terraza);
        $ids = [$libre1->id, $libre2->id, $conArea->id, $usada->id, $usadaDentro->id];

        $this->actingAs($this->admin)->get("/espacios/{$piso->id}")->assertSee('Eliminar definitivamente las marcadas')->assertSee('data-marcar-espacio', false);

        // Confirmación incorrecta: no se borra nada
        $this->actingAs($this->admin)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => $ids, 'confirmacion' => '3'])
            ->assertSessionHas('error', 'Para confirmar escribe 5 (la cantidad marcada) o ELIMINAR.');
        $this->assertDatabaseHas('espacios', ['id' => $libre1->id]);

        $this->actingAs($this->admin)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => $ids, 'confirmacion' => '5'])
            ->assertRedirect("/espacios/{$piso->id}")
            ->assertSessionHas('aviso', 'Se eliminaron 3; 2 no se pudieron: 101: tiene 1 llave que la abre (HDC-101); '
                .'102: dentro, «Terraza» tiene 1 llave que la abre (HDC-102T). Lo que está en uso puedes darlo de baja.');

        foreach ([$libre1, $libre2, $conArea, $bano] as $borrado) {
            $this->assertDatabaseMissing('espacios', ['id' => $borrado->id]);
        }
        foreach ([$usada, $usadaDentro, $terraza] as $queda) {
            $this->assertDatabaseHas('espacios', ['id' => $queda->id]);
        }

        // Cada una (y lo que tenía dentro) con su copia en la Bitácora de auditoría
        $copias = Auditoria::where('evento', 'espacios.eliminado_definitivo')->get();
        $this->assertEqualsCanonicalizing([$libre1->id, $libre2->id, $conArea->id, $bano->id], $copias->pluck('auditable_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame('105', $copias->firstWhere('auditable_id', $conArea->id)->antes['nombre']);

        // Con ELIMINAR también se confirma
        $this->actingAs($this->admin)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => [$usada->id], 'confirmacion' => 'eliminar'])
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, '1 no se pudo: 101: tiene 1 llave'));
    }

    public function test_eliminar_varias_requiere_permiso_y_no_toca_otra_empresa_ni_otro_padre(): void
    {
        $torre = $this->nuevo('edificio', 'Torre A');
        $piso = $this->nuevo('area', 'Piso 1', $torre);
        $piso2 = $this->nuevo('area', 'Piso 2', $torre);
        $hab = $this->nuevo('area_especifica', '101', $piso);
        $ajena = $this->nuevo('area_especifica', '201', $piso2);

        // Sin espacios.borrar: 403 y sin casillas
        $sa = $this->crearSuperadmin();
        $rol = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Editor', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $rol, ['espacios.ver' => Alcance::Empresa, 'espacios.eliminar' => Alcance::Empresa]);
        $editor = $this->crearUsuario($this->empresa, 'Editor');
        $this->actingAs($editor)->get("/espacios/{$piso->id}")->assertOk()->assertDontSee('Eliminar definitivamente las marcadas')->assertDontSee('data-marcar-espacio', false);
        $this->actingAs($editor)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => [$hab->id], 'confirmacion' => 'ELIMINAR'])->assertForbidden();

        // Otra empresa: 404
        $otraEmpresa = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($otraEmpresa, 'OTR');
        $ajeno = $this->crearUsuario($otraEmpresa, 'Administrador');
        $this->actingAs($ajeno)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => [$hab->id], 'confirmacion' => 'ELIMINAR'])->assertNotFound();

        // Un id que no cuelga de este piso no se borra
        $this->actingAs($this->admin)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => [$ajena->id], 'confirmacion' => '1'])
            ->assertSessionHas('error', 'Ninguno de los marcados está en esta lista: no se eliminó nada.');

        $this->assertDatabaseHas('espacios', ['id' => $hab->id]);
        $this->assertDatabaseHas('espacios', ['id' => $ajena->id]);
        $this->assertSame(0, Auditoria::where('evento', 'espacios.eliminado_definitivo')->count());

        // Tope por envío
        $this->actingAs($this->admin)->delete("/espacios/{$piso->id}/eliminar-varias", ['espacios' => range(1, 201), 'confirmacion' => 'ELIMINAR'])
            ->assertSessionHasErrors('espacios');
    }
}
