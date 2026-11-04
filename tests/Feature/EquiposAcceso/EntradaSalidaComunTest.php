<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use App\Models\Auditoria;
use App\Services\Accesos\MovimientoNoPermitido;
use App\Services\Accesos\MovimientosAccesos;
use App\Services\Accesos\RegistroAccesos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Decisión 10: la entrada y la salida de un colaborador sin persona en
 * caseta salen del MISMO servicio que usa la caseta (RegistroAccesos y
 * MovimientosAccesos), con el menor cambio posible. El registro que deja
 * es idéntico al de la caseta para un colaborador «A pie»; la caseta no
 * cambia.
 */
class EntradaSalidaComunTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmpresa();
    }

    /** Columnas que describen el registro (sin id, horas ni autoría). */
    private function forma(Acceso $a): array
    {
        return collect($a->getAttributes())->except(['id', 'created_at', 'updated_at', 'entrada_at', 'creado_por', 'actualizado_por', 'equipo_entrada_id', 'equipo_salida_id', 'colaborador_id'])->all();
    }

    public function test_la_entrada_sin_caseta_deja_el_mismo_registro_que_la_caseta(): void
    {
        $juan = $this->colaborador('1001');
        $ana = $this->colaborador('1002', nombre: 'Ana');
        $this->actingAs($this->admin)->post('/accesos', ['tipo' => 'colaborador', 'colaborador_id' => $juan->id, 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso'])
            ->assertSessionHasNoErrors();
        $deCaseta = $this->enEmpresa(fn () => Acceso::where('colaborador_id', $juan->id)->firstOrFail());
        $this->sinSesion();
        $sinCaseta = $this->enEmpresa(fn () => app(RegistroAccesos::class)->entradaDeColaborador(null, $ana, $this->centro->id));

        $this->assertSame(
            array_merge($this->forma($deCaseta->refresh()), ['nombre' => 'ANA PÉREZ LÓPEZ']),
            $this->forma($sinCaseta->refresh()),
        );
        $this->assertSame('JUAN PÉREZ LÓPEZ', $deCaseta->nombre);
        $this->assertSame((int) $this->admin->id, (int) $deCaseta->creado_por, 'La caseta sigue registrando quién fue');
        $this->assertNull($sinCaseta->creado_por);
        $auditoria = Auditoria::where('evento', 'accesos.creado')->where('auditable_id', $sinCaseta->id)->firstOrFail();
        $this->assertNull($auditoria->user_id);
        $this->assertSame($this->empresa->id, (int) $auditoria->empresa_id);
    }

    public function test_aplica_las_reglas_de_la_caseta_para_el_colaborador(): void
    {
        $dePlaya = $this->colaborador('2001', $this->playa);
        $deBaja = $this->colaborador('2002');
        $this->enEmpresa(fn () => $deBaja->forceFill(['activo' => false])->save());
        $corporativo = $this->colaborador('2003');
        $this->enEmpresa(fn () => $corporativo->forceFill(['sede_id' => null])->save());

        foreach ([$dePlaya, $deBaja] as $c) {
            try {
                $this->enEmpresa(fn () => app(RegistroAccesos::class)->entradaDeColaborador(null, $c, $this->centro->id));
                $this->fail('Debió rechazarse');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('colaborador_id', $e->errors());
            }
        }
        $acceso = $this->enEmpresa(fn () => app(RegistroAccesos::class)->entradaDeColaborador(null, $corporativo->refresh(), $this->centro->id));
        $this->assertSame('en_sitio', $acceso->estado);
    }

    public function test_la_salida_sin_caseta_usa_la_misma_regla_y_guarda_la_hora_del_marcaje(): void
    {
        $juan = $this->colaborador('1001');
        $this->sinSesion();
        $acceso = $this->enEmpresa(fn () => app(RegistroAccesos::class)->entradaDeColaborador(null, $juan, $this->centro->id, ['entrada_at' => now()->subHours(8)]));
        $hora = now()->subMinutes(3)->startOfSecond();
        $this->enEmpresa(fn () => app(MovimientosAccesos::class)->salida(null, $acceso, ['salida_at' => $hora]));

        $acceso->refresh();
        $this->assertSame('finalizado', $acceso->estado);
        $this->assertTrue($acceso->salida_at->equalTo($hora));
        $this->assertNull($acceso->salida_por);
        $this->assertNull(Auditoria::where('evento', 'accesos.salida')->where('auditable_id', $acceso->id)->value('user_id'));
        // Dos veces: la misma respuesta de siempre
        $this->expectException(MovimientoNoPermitido::class);
        $this->enEmpresa(fn () => app(MovimientosAccesos::class)->salida(null, $acceso->refresh()));
    }

    public function test_la_caseta_da_salida_como_siempre(): void
    {
        $juan = $this->colaborador('1001');
        $this->actingAs($this->admin)->post('/accesos', ['tipo' => 'colaborador', 'colaborador_id' => $juan->id, 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso']);
        $acceso = $this->enEmpresa(fn () => Acceso::where('colaborador_id', $juan->id)->firstOrFail());
        $this->actingAs($this->admin)->patch(route('accesos.salida', $acceso->id))->assertSessionHasNoErrors();
        $acceso->refresh();
        $this->assertSame('finalizado', $acceso->estado);
        $this->assertSame((int) $this->admin->id, (int) $acceso->salida_por);
        $this->assertNull($acceso->equipo_salida_id);
    }
}
