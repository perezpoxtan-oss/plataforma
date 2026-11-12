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
use App\Support\AjustesCaseta;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 8 · C-45 (DD-19), versión mínima segura: una recepción común OK /
 * Dañado / No regresó en Préstamo de llaves, Gafetes y Responsivas (y el
 * lector), y la garantía y la firma como ajuste por tipo de objeto
 * (Configuración → Caseta y activos). Sin configurar, todo funciona como
 * antes: sin el campo «resultado», recibir es OK.
 */
class EtapaOchoRecepcionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function prestada(string $nomenclatura = 'HDC-1'): PrestamoLlave
    {
        $llave = $this->enEmpresa(fn () => Llave::create(['sede_id' => $this->centro->id, 'nomenclatura' => $nomenclatura, 'descripcion' => 'X', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']));
        $c = $this->enEmpresa(fn () => Colaborador::firstOrCreate(['num_empleado' => '950'], ['nombre' => 'Iris', 'apellido_paterno' => 'Luna', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $llave->id, 'tipo_garantia' => 'ine', 'colaborador_id' => $c->id])->assertCreated();

        return $this->enEmpresa(fn () => PrestamoLlave::where('llave_id', $llave->id)->latest('id')->firstOrFail());
    }

    private function ultimo(string $tipo, int $id): string
    {
        $m = $this->enEmpresa(fn () => MovimientoActivo::where('activo_tipo', $tipo)->where('activo_id', $id)->latest('id')->firstOrFail());

        return $m->accion.($m->resultado ? '/'.$m->resultado : '');
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

    public function test_llave_recibir_ok_danado_y_no_regreso_con_la_misma_accion(): void
    {
        // Sin el campo: OK, como siempre
        $p1 = $this->prestada('HDC-1');
        $this->actingAs($this->agente)->patch(route('prestamo_llaves.recibir', $p1->id))
            ->assertSessionHas('ok', 'Llave «HDC-1» recibida de vuelta correctamente. Devuelve la identificación en garantía.');
        $this->assertSame('recepcion/ok', $this->ultimo('llave', $p1->llave_id));

        // Dañada: se recibe igual y se propone la baja a quien puede
        $p2 = $this->prestada('HDC-2');
        $this->actingAs($this->admin)->patch(route('prestamo_llaves.recibir', $p2->id), ['resultado' => 'danado'])
            ->assertSessionHas('ok')->assertSessionHas('siguientes', [['Dar de baja la llave', route('llaves.index', ['baja' => $p2->llave_id]).'#llave-'.$p2->llave_id, 'bi-slash-circle']]);
        $this->assertSame([PrestamoLlave::DEVUELTA, EstadoActivo::DISPONIBLE], [$p2->fresh()->estado, Llave::withoutGlobalScopes()->find($p2->llave_id)->estado]);
        $this->assertSame('recepcion/danado', $this->ultimo('llave', $p2->llave_id));

        // No regresó desde la misma recepción: igual que su botón
        $p3 = $this->prestada('HDC-3');
        $this->actingAs($this->agente)->patch(route('prestamo_llaves.recibir', $p3->id), ['resultado' => 'no_regreso'])->assertSessionHas('aviso');
        $this->assertSame(PrestamoLlave::NO_REGRESO, $p3->fresh()->estado);
        $this->assertSame('no_regreso', $this->ultimo('llave', $p3->llave_id));

        // La ficha conserva sus botones y agrega «¿Viene dañada?» plegado
        $this->actingAs($this->agente)->get(route('prestamo_llaves.index'))->assertOk()
            ->assertSee('Recibir Llave a Caseta')->assertSee('¿Viene dañada?')->assertSee('name="resultado" value="ok"', false);
    }

    public function test_el_lector_ofrece_la_recepcion_comun(): void
    {
        $p = $this->prestada();
        $llave = Llave::withoutGlobalScopes()->find($p->llave_id);
        $this->actingAs($this->agente)->get(route('lector.ir', $llave->codigo_qr))->assertOk()
            ->assertSee('Llave prestada')->assertSee('name="resultado" value="ok"', false)->assertSee('value="danado"', false)->assertSee('value="no_regreso"', false);

        // Gafete «No regresó» que apareció: Recibir (OK o dañado)
        $tipo = $this->enEmpresa(fn () => TipoGafete::firstOrCreate(['nombre' => 'Visitante']));
        $g = $this->enEmpresa(fn () => Gafete::create(['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, 'nomenclatura' => 'CEN-VIS-009', 'consecutivo' => 9]));
        $a = $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'motivo_visita' => 'general', 'persona_visita' => 'X',
            'nombre' => 'ANA', 'estado' => 'en_sitio', 'gafete_id' => $g->id, 'gafete_texto' => $g->nomenclatura, 'entrada_at' => now()]));
        $this->actingAs($this->agente)->patch(route('accesos.salida', $a->id), ['gafetes_revisados' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->agente)->get(route('lector.ir', $g->codigo_qr))->assertOk()->assertSee('Gafete que no regresó')
            ->assertSee(route('gafetes.recibir', $g->id), false)->assertDontSee('value="no_regreso"', false);
        $this->actingAs($this->agente)->patch(route('gafetes.recibir', $g->id), ['resultado' => 'danado'])->assertSessionHas('ok', 'Gafete CEN-VIS-009 recibido dañado. Avísale a tu supervisor si ya no sirve.');
        $this->assertSame(EstadoActivo::DISPONIBLE, $g->fresh()->estado);
        $this->assertSame('recepcion/danado', $this->ultimo('gafete', $g->id));
        // Ya disponible: el lector abre la ficha como antes
        $this->actingAs($this->agente)->get(route('lector.ir', $g->codigo_qr))->assertRedirect(route('gafetes.index').'#gafete-'.$g->id);
    }

    public function test_responsiva_acepta_la_recepcion_comun(): void
    {
        $tipo = $this->enEmpresa(fn () => TipoEquipo::firstOrCreate(['nombre' => 'Radio']));
        $e = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'numero_serie' => 'RAD-7']));
        $c = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '951', 'nombre' => 'Leo', 'apellido_paterno' => 'Sol', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->admin)->post('/responsivas', ['_dialogo' => 'nuevo-resguardo', 'sede_id' => $this->centro->id, 'colaborador_id' => $c->id,
            'equipos' => [$e->id], 'modalidades' => ['prestado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => Responsiva::latest('id')->firstOrFail());
        $fila = $this->enEmpresa(fn () => EquipoResponsiva::where('responsiva_id', $r->id)->firstOrFail());

        // «Dañado» de la recepción común = «Dañado» de Responsivas (con su nota)
        $this->actingAs($this->admin)->patch(route('responsivas.recibir-equipo', [$r->id, $fila->id]), ['resultado' => 'danado'])
            ->assertSessionHasErrors(['nota' => 'Escribe una nota: qué daño tiene o qué pasó con el equipo.']);
        $this->actingAs($this->admin)->patch(route('responsivas.recibir-equipo', [$r->id, $fila->id]), ['resultado' => 'danado', 'nota' => 'Pantalla rota'])
            ->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::EN_MANTENIMIENTO, 'danado'], [$e->fresh()->estado, $fila->fresh()->estado_devolucion]);
    }

    public function test_garantia_de_la_llave_obligatoria_por_configuracion(): void
    {
        $this->actingAs($this->admin)->get(route('prestamo_llaves.index'))->assertSee('Ninguna (Riesgo)');
        $this->actingAs($this->admin)->get(route('configuracion.tema', 'activos'))->assertOk()
            ->assertSee('Avanzado: garantía de la llave')->assertSee('Avanzado: firma de la responsiva');

        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'llaves'] + array_merge(AjustesCaseta::OMISION, ['llaves_garantia' => 'obligatoria']))->assertSessionHasNoErrors();
        $this->assertSame('obligatoria', AjustesCaseta::valor($this->empresa->id, 'llaves_garantia'));
        $this->actingAs($this->admin)->get(route('prestamo_llaves.index'))->assertDontSee('Ninguna (Riesgo)');

        $llave = $this->enEmpresa(fn () => Llave::create(['sede_id' => $this->centro->id, 'nomenclatura' => 'HDC-8', 'descripcion' => 'X', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']));
        $c = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '952', 'nombre' => 'Ari', 'apellido_paterno' => 'Paz', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $llave->id, 'tipo_garantia' => 'ninguna', 'colaborador_id' => $c->id])
            ->assertStatus(422)->assertJsonPath('errores.tipo_garantia.0', 'Tu empresa pide identificación en garantía para prestar llaves: elige cuál deja.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $llave->id, 'tipo_garantia' => 'ine', 'colaborador_id' => $c->id])->assertCreated();

        // Valor inválido
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'llaves'] + array_merge(AjustesCaseta::OMISION, ['llaves_garantia' => 'otra']))
            ->assertSessionHasErrors(['llaves_garantia' => 'Elige una de las opciones de la lista.']);
    }

    public function test_firma_de_la_responsiva_en_papel_por_configuracion(): void
    {
        $tipo = $this->enEmpresa(fn () => TipoEquipo::firstOrCreate(['nombre' => 'Radio']));
        $e = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'numero_serie' => 'RAD-9']));
        $c = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '953', 'nombre' => 'Uma', 'apellido_paterno' => 'Rey', 'sede_id' => $this->centro->id]));
        $datos = ['_dialogo' => 'nuevo-resguardo', 'sede_id' => $this->centro->id, 'colaborador_id' => $c->id, 'equipos' => [$e->id], 'modalidades' => ['prestado']];

        // De fábrica: la firma en pantalla es obligatoria
        $this->actingAs($this->admin)->post('/responsivas', $datos)->assertSessionHasErrors('firma');

        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'responsivas'] + array_merge(AjustesCaseta::OMISION, ['responsivas_firma' => 'papel']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('responsivas.index'))->assertOk()->assertSee('Tu empresa firma la responsiva a mano');
        $this->actingAs($this->admin)->post('/responsivas', $datos)->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => Responsiva::latest('id')->firstOrFail());
        $this->assertSame('', $r->firma_ruta);
        $this->actingAs($this->admin)->get(route('responsivas.hoja', $r->id))->assertOk()->assertDontSee(route('responsivas.firma', $r->id), false)->assertSee('FIRMA DE CONFORMIDAD');
        $this->actingAs($this->admin)->get(route('responsivas.index'))->assertDontSee('data-ver-firma="'.route('responsivas.firma', $r->id).'"', false);
    }
}
