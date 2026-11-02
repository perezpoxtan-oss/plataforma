<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Accesos\ArticulosDelProveedor;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Notificaciones\PreferenciasAvisos;
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

    /**
     * C-19 contra la campana y Mi cuenta (reglas 1 y 3 de la Etapa 4): el
     * aviso «Salió con algo distinto» espera una acción («Atender»), así que
     * se ve como alerta, aparece en Mi cuenta con su módulo y, si se silencia,
     * el asunto sigue en su renglón de Mis pendientes.
     */
    public function test_el_aviso_de_salida_con_algo_distinto_es_alerta_con_su_renglon_y_aparece_en_mi_cuenta(): void
    {
        $this->assertSame(['bi-tools', 'alerta'], CentroNotificaciones::TIPOS[ArticulosDelProveedor::TIPO_AVISO] ?? null);
        $this->assertSame('articulos_caseta', PreferenciasAvisos::RENGLON_DE_TIPO[ArticulosDelProveedor::TIPO_AVISO] ?? null);
        $this->assertFalse(PreferenciasAvisos::noSilenciable(ArticulosDelProveedor::TIPO_AVISO)); // tiene su renglón en Mis pendientes

        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->proveedorQueSalioConAlgoDistinto();
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $jefe->id)->where('tipo', ArticulosDelProveedor::TIPO_AVISO)->exists());

        // En Mi cuenta sale con su módulo y su texto, no como «Otros avisos: Acceso articulos»
        $campana = app(PreferenciasAvisos::class)->opciones($jefe)['campana'];
        $this->assertArrayHasKey(ArticulosDelProveedor::TIPO_AVISO, $campana['Bitácora de accesos'] ?? []);
        $this->assertArrayNotHasKey(ArticulosDelProveedor::TIPO_AVISO, $campana['Otros avisos'] ?? []);
    }
}
