<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\MovimientoActivo;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoGafete;
use App\Models\User;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 8 · C-45 (DD-19): historial único `movimientos_activo` de lo que se
 * entrega y se recibe. Vínculos: Préstamo de llaves (prestar, recibir, «No
 * regresó», anular), Bitácora de accesos (gafete prestado, devuelto y no
 * devuelto, acompañantes), Gafetes (recibir y baja), Responsivas (entregar,
 * recibir OK y dañado), «Antes de dar de baja» y la pantalla «Entregas y
 * recepciones» con sus permisos. Los módulos no cambian su comportamiento.
 */
class EtapaOchoHistorialTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function colaborador(string $num = '800'): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Rosa', 'apellido_paterno' => 'Num'.$num, 'sede_id' => $this->centro->id]));
    }

    /** @return list<string> «accion/resultado» del más antiguo al más reciente */
    private function historial(string $tipo, int $id): array
    {
        return $this->enEmpresa(fn () => MovimientoActivo::where('activo_tipo', $tipo)->where('activo_id', $id)->orderBy('id')->get()
            ->map(fn ($m) => $m->accion.($m->resultado ? '/'.$m->resultado : ''))->all());
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

    public function test_llave_prestar_recibir_no_regreso_y_anular_quedan_en_su_historial(): void
    {
        $llave = $this->enEmpresa(fn () => Llave::create(['sede_id' => $this->centro->id, 'nomenclatura' => 'HDC-1', 'descripcion' => 'X', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']));
        $c = $this->colaborador();
        $prestar = fn () => $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $llave->id,
            'tipo_garantia' => 'ine', 'colaborador_id' => $c->id])->assertCreated();

        $prestar();
        $p1 = $this->enEmpresa(fn () => PrestamoLlave::latest('id')->firstOrFail());
        $this->actingAs($this->admin)->patch(route('prestamo_llaves.recibir', $p1->id))->assertSessionHasNoErrors();
        $prestar();
        $p2 = $this->enEmpresa(fn () => PrestamoLlave::latest('id')->firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p2->id}/no-regreso")->assertSessionHasNoErrors();
        // Apareció: se recibe
        $this->actingAs($this->admin)->patch(route('prestamo_llaves.recibir', $p2->id))->assertSessionHasNoErrors();
        $prestar();
        $p3 = $this->enEmpresa(fn () => PrestamoLlave::latest('id')->firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p3->id}/anular")->assertSessionHasNoErrors();

        $this->assertSame(['salida', 'recepcion/ok', 'salida', 'no_regreso', 'recepcion/ok', 'salida', 'anulado'], $this->historial('llave', $llave->id));
        $salida = $this->enEmpresa(fn () => MovimientoActivo::where('activo_tipo', 'llave')->orderBy('id')->first());
        $this->assertSame(['colaborador', $c->id, 'Garantía: INE', $this->admin->id, 'prestamo_llave', $p1->id],
            [$salida->receptor_tipo, $salida->receptor_id, $salida->detalle, $salida->creado_por, $salida->origen_tipo, $salida->origen_id]);

        // La pantalla de Préstamo de llaves no cambia: sigue con su historial y sus botones
        $this->actingAs($this->admin)->get(route('prestamo_llaves.index'))->assertOk();

        // «Entregas y recepciones» desde la ficha de la llave
        $this->actingAs($this->admin)->get(route('llaves.index'))->assertOk()->assertSee(route('activos.historial', ['llave', $llave->id]), false);
        $this->actingAs($this->admin)->get(route('activos.historial', ['llave', $llave->id]))->assertOk()
            ->assertSee('Entregas y recepciones')->assertSee('HDC-1')->assertSee('Dar salida')->assertSee('No regresó')
            ->assertSee('Anulado (captura equivocada)')->assertSee('Rosa Num800')->assertSee('Volver a la ficha');
    }

    public function test_gafete_en_la_bitacora_devuelto_no_devuelto_y_recibido_despues(): void
    {
        $tipo = $this->enEmpresa(fn () => TipoGafete::firstOrCreate(['nombre' => 'Visitante']));
        $g1 = $this->enEmpresa(fn () => Gafete::create(['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, 'nomenclatura' => 'CEN-VIS-001', 'consecutivo' => 1]));
        $g2 = $this->enEmpresa(fn () => Gafete::create(['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, 'nomenclatura' => 'CEN-VIS-002', 'consecutivo' => 2]));
        $acceso = fn (Gafete $g, string $nombre) => $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'motivo_visita' => 'general',
            'persona_visita' => 'X', 'nombre' => $nombre, 'estado' => 'en_sitio', 'gafete_id' => $g->id, 'gafete_texto' => $g->nomenclatura, 'entrada_at' => now()]));

        $a1 = $acceso($g1, 'ANA SOSA');
        $this->actingAs($this->admin)->patch(route('accesos.salida', $a1->id), ['gafetes_revisados' => 1, 'devolvio' => [$g1->id]])->assertSessionHasNoErrors();
        $a2 = $acceso($g2, 'LUIS PAZ');
        $this->actingAs($this->admin)->patch(route('accesos.salida', $a2->id), ['gafetes_revisados' => 1])->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::NO_REGRESO, $g2->fresh()->estado);
        $this->actingAs($this->admin)->patch("/gafetes/{$g2->id}/recibir")->assertSessionHasNoErrors();

        $this->assertSame(['salida', 'recepcion/ok'], $this->historial('gafete', $g1->id));
        $this->assertSame(['salida', 'no_regreso', 'recepcion/ok'], $this->historial('gafete', $g2->id));
        // El nombre no se copia: se lee del acceso de origen
        $this->actingAs($this->admin)->get(route('activos.historial', ['gafete', $g1->id]))->assertOk()->assertSee('ANA SOSA')->assertSee('Recibir');

        // Salida sin revisión (otras pantallas): se da por devuelto, como siempre
        $a3 = $acceso($g1, 'EVA RUIZ');
        $this->actingAs($this->admin)->patch(route('accesos.salida', $a3->id))->assertSessionHasNoErrors();
        $this->assertSame(['salida', 'recepcion/ok', 'salida', 'recepcion/ok'], $this->historial('gafete', $g1->id));

        // Baja y reactivación desde el catálogo
        $this->actingAs($this->admin)->post("/gafetes/{$g1->id}/baja", ['motivo' => 'danado'])->assertSessionHasNoErrors();
        $this->assertSame('baja', last($this->historial('gafete', $g1->id)));
        $this->actingAs($this->admin)->get(route('gafetes.index'))->assertOk()->assertSee(route('activos.historial', ['gafete', $g1->id]), false);
    }

    public function test_responsiva_entrega_y_recepcion_ok_o_danado(): void
    {
        $tipo = $this->enEmpresa(fn () => TipoEquipo::firstOrCreate(['nombre' => 'Radio']));
        $e1 = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'numero_serie' => 'RAD-1']));
        $e2 = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'numero_serie' => 'RAD-2']));
        $c = $this->colaborador();
        $this->actingAs($this->admin)->post('/responsivas', ['_dialogo' => 'nuevo-resguardo', 'sede_id' => $this->centro->id, 'colaborador_id' => $c->id,
            'equipos' => [$e1->id, $e2->id], 'modalidades' => ['prestado', 'asignado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => Responsiva::latest('id')->firstOrFail());
        $filas = $this->enEmpresa(fn () => EquipoResponsiva::where('responsiva_id', $r->id)->orderBy('id')->get());

        $this->actingAs($this->admin)->patch(route('responsivas.recibir', $r->id), ['equipos' => [$filas[0]->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch(route('responsivas.recibir-equipo', [$r->id, $filas[1]->id]), ['estado_recepcion' => 'danado', 'nota' => 'Antena rota'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['salida', 'recepcion/ok'], $this->historial('equipo', $e1->id));
        $this->assertSame(['salida', 'mantenimiento', 'recepcion/danado'], $this->historial('equipo', $e2->id));
        $salida = $this->enEmpresa(fn () => MovimientoActivo::where('activo_tipo', 'equipo')->where('activo_id', $e2->id)->orderBy('id')->first());
        $this->assertSame([$r->folio.' · Fijo', 'colaborador'], [$salida->detalle, $salida->receptor_tipo]);
        $this->actingAs($this->admin)->get(route('activos.historial', ['equipo', $e2->id]))->assertOk()->assertSee('Recibir · Dañado')->assertSee('A mantenimiento');
    }

    public function test_antes_de_dar_de_baja_deja_no_regreso_en_el_historial(): void
    {
        $llave = $this->enEmpresa(fn () => Llave::create(['sede_id' => $this->centro->id, 'nomenclatura' => 'HDC-9', 'descripcion' => 'X', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']));
        $c = $this->colaborador('801');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $llave->id, 'tipo_garantia' => 'ine', 'colaborador_id' => $c->id])->assertCreated();
        $this->actingAs($this->admin)->patch(route('colaboradores.estado', $c->id), ['activo' => 0, 'revisado' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['salida', 'no_regreso'], $this->historial('llave', $llave->id));
    }

    public function test_permisos_de_la_pantalla_de_entregas(): void
    {
        $llave = $this->enEmpresa(fn () => Llave::create(['sede_id' => $this->playa->id, 'nomenclatura' => 'PLA-1', 'descripcion' => 'X', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']));
        // Agente de otra sede: no la ve (404, como «Código e identificación»)
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get(route('activos.historial', ['llave', $llave->id]))->assertNotFound();
        // Sin permiso de ver llaves: 403
        $this->actingAs($this->crearUsuario($this->empresa, 'Solicitante', $this->centro))->get(route('activos.historial', ['llave', $llave->id]))->assertForbidden();
        // Otra empresa: 404
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->get(route('activos.historial', ['llave', $llave->id]))->assertNotFound();
        // Sin movimientos: lo dice y manda a su módulo
        $this->actingAs($this->admin)->get(route('activos.historial', ['llave', $llave->id]))->assertOk()->assertSee('Todavía no hay entregas ni recepciones');
    }
}
