<?php

namespace Tests\Feature\Direccion;

use App\Models\Empresa;
use App\Models\User;
use App\Support\GruposAvisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 7 · C-38: Configuración ordenada, un solo lugar por ajuste.
 * «Colaboradores: avisos» en Estructura, «Procedimientos» en la sección de la
 * bitácora y el aviso de las autorizaciones de visitas en «Caseta y accesos».
 * Ningún ajuste se pierde y ninguna empresa cambia lo que recibe.
 */
class EtapaSieteConfiguracionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    /** HTML de una sección (de su <section id> al siguiente </section>). */
    private function seccion(string $html, string $id): string
    {
        $inicio = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($inicio, "No está la sección {$id}");

        return substr($html, $inicio, strpos($html, '</section>', $inicio) - $inicio);
    }

    public function test_cada_ajuste_esta_en_un_solo_lugar(): void
    {
        $html = $this->actingAs($this->admin)->get('/configuracion')->assertOk()->getContent();

        $estructura = $this->seccion($html, 'sec-estructura');
        $this->assertStringContainsString('id="config-colaboradores"', $estructura);
        $this->assertStringContainsString('<a href="#sec-estructura" class="chip-config">', $html);

        $caseta = $this->seccion($html, 'caseta');
        $this->assertStringContainsString('id="config-autorizaciones"', $caseta);
        $this->assertStringContainsString('Autorizaciones de visitas: avisos por correo', $caseta);
        $this->assertStringContainsString('name="avisos[autorizacion_departamento]"', $caseta);
        foreach (['config-colaboradores', 'cfg-procedimientos', 'config-procedimientos'] as $id) {
            $this->assertStringNotContainsString('id="'.$id.'"', $caseta, "{$id} ya no está en Caseta y accesos");
        }

        $bitacora = $this->seccion($html, 'sec-novedades');
        $this->assertStringContainsString('id="cfg-procedimientos"', $bitacora);
        $this->assertStringContainsString('id="config-procedimientos"', $bitacora);

        $rh = $this->seccion($html, 'sec-rh');
        $this->assertStringNotContainsString('name="avisos[autorizacion_departamento]"', $rh);

        // Ningún aviso se pierde y cada uno sale una sola vez
        foreach (array_keys(Empresa::AVISOS) as $clave) {
            $this->assertSame(1, substr_count($html, 'name="avisos['.$clave.']"'), "El aviso {$clave} sale una sola vez");
        }
        foreach (['cfg-procedimientos', 'config-colaboradores', 'config-autorizaciones', 'cfg-esperas', 'cfg-motivos'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "La tarjeta {$id} sale una sola vez");
        }
    }

    public function test_los_procedimientos_se_siguen_guardando_y_regresan_a_su_ancla(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'procedimientos', 'procedimientos_acuse' => 'casilla', 'procedimientos_recordatorio_dias' => '5'])
            ->assertSessionHasNoErrors()->assertRedirect(route('configuracion.index').'#cfg-procedimientos');
        $this->assertSame(5, $this->empresa->refresh()->preferencias['caseta']['procedimientos_recordatorio_dias']);
    }

    public function test_el_interruptor_del_grupo_nuevo_hereda_el_de_candidatos(): void
    {
        $this->assertSame('autorizaciones', GruposAvisos::grupoDe('autorizacion_departamento'));
        $migracion = require database_path('migrations/2026_11_17_000100_avisos_de_autorizaciones_en_la_caseta.php');

        // Empresa que tenía apagados los avisos de Recepción y candidatos
        $this->empresa->forceFill(['preferencias' => ['avisos_modulos' => ['candidatos' => false]]])->save();
        $otra = $this->crearEmpresa('Otra');
        $otra->forceFill(['preferencias' => ['avisos_modulos' => ['candidatos' => true]]])->save();

        $migracion->down();
        $this->assertFalse($this->empresa->fresh()->aviso('candidato_llegada'));
        $migracion->up();
        $this->assertFalse($this->empresa->fresh()->preferencias['avisos_modulos']['autorizaciones']);
        $this->assertFalse($this->empresa->fresh()->aviso('autorizacion_departamento'), 'Sigue sin recibirlo, como antes');
        $this->assertArrayNotHasKey('autorizaciones', $otra->fresh()->preferencias['avisos_modulos']);
        $this->assertTrue($otra->fresh()->aviso('autorizacion_departamento'));

        // Revertir y volver a migrar
        $migracion->down();
        $this->assertArrayNotHasKey('autorizaciones', $this->empresa->fresh()->preferencias['avisos_modulos']);
        $migracion->up();
        $this->assertFalse($this->empresa->fresh()->preferencias['avisos_modulos']['autorizaciones']);
        $this->assertSame(1, DB::table('empresas')->where('id', $this->empresa->id)->count());
    }

    public function test_guardar_avisos_del_grupo_nuevo(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['_grupo' => 'autorizaciones', 'avisos_modulos' => ['autorizaciones' => '0'], 'avisos' => ['candidato_llegada' => '1', 'autorizacion_departamento' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->empresa->fresh()->aviso('autorizacion_departamento'));
        $this->assertTrue($this->empresa->fresh()->aviso('candidato_llegada'), 'Apagar las autorizaciones no apaga los de Candidatos');
    }
}
