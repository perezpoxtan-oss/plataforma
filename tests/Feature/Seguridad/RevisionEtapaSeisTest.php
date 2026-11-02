<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Services\Privacidad\DatosDePersona;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;
use ZipArchive;

/**
 * Revisión independiente de la Etapa 6 (PR #93): discrepancias entre lo
 * nuevo de la etapa y los módulos con los que se cruza.
 */
class RevisionEtapaSeisTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    private Colaborador $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->empresa->forceFill(['preferencias' => ['recepcion' => ['proveedores_requieren_autorizacion' => false, 'contratistas_requieren_autorizacion' => false]]])->save();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->host = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '2001', 'nombre' => 'Carlos', 'apellido_paterno' => 'Host', 'sede_id' => $this->centro->id]));
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** Proveedor con herramienta que sale con algo distinto (la nota del guardia lo nombra). */
    private function proveedorQueSalioConAlgoDistinto(): Acceso
    {
        $this->actingAs($this->agente)->post('/accesos', ['tipo' => 'proveedor', 'nombre' => 'Jesús Balam', 'empresa_procedencia' => 'Elevadores del Sur',
            'host_colaborador_id' => $this->host->id, 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso',
            'articulos' => [['articulo' => 'Taladro', 'cantidad' => '1', 'serie' => 'DW-123']]])->assertSessionHasNoErrors();
        $a = $this->enEmpresa(fn () => Acceso::orderByDesc('id')->firstOrFail());
        if ($a->estado === 'pendiente') {
            $this->actingAs($this->admin)->patch("/accesos/{$a->id}/autorizar");
        }
        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida", ['articulos_revisados' => 1, 'articulos_nota' => 'Jesús Balam lleva una escalera más']);
        $a->refresh();
        $this->assertSame('distinto', $a->articulos_salida);

        return $a;
    }

    /**
     * C-19 contra ARCO y conservación: «¿Qué notaste?» es texto libre del
     * guardia sobre la persona (como «Observaciones»), así que se borra al
     * eliminar sus datos; y lo que trajo viaja en la exportación ARCO.
     */
    public function test_arco_exporta_lo_que_trajo_y_al_eliminar_quita_la_nota_del_guardia(): void
    {
        $a = $this->proveedorQueSalioConAlgoDistinto();
        $arco = app(DatosDePersona::class);

        Storage::fake('local');
        $this->enEmpresa(fn () => $arco->exportar(['acceso' => [$a->id]], 'arco/prueba.zip', 'ARCO-1'));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path('arco/prueba.zip')) === true);
        $trajo = json_decode((string) $zip->getFromName('datos/accesos_lo_que_trajo.json'), true);
        $zip->close();
        $this->assertSame(['TALADRO'], array_column((array) $trajo, 'articulo'));
        $this->assertSame(['DW-123'], array_column((array) $trajo, 'serie'));

        $this->enEmpresa(fn () => $arco->anonimizarAcceso($a->fresh(), 'DATOS ELIMINADOS'));
        $a->refresh();
        $this->assertNull($a->articulos_nota);
        $this->assertSame('distinto', $a->articulos_salida); // el hecho se conserva, sin el texto libre
    }
}
