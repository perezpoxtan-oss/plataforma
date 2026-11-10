<?php

namespace Tests\Feature\Nucleo;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Manual\Manual;
use App\Services\Permisos\Autorizador;
use App\Support\DependenciasModulos;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pendientes de la revisión de la Etapa 1 que se entregan con la Etapa 2:
 *  - A-3: sin Recursos Humanos, las altas provisionales las verifica el administrador.
 *  - A-2: Autorizaciones tiene su propia página del manual (Estructura);
 *    la de Recursos Humanos queda solo para Recepción, con enlaces cruzados.
 * (A-5, Reservas en Mis pendientes, está en ReservasDeAreasTest.)
 */
class RevisionEtapaUnoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private function apagar(int $empresaId, string $modulo): void
    {
        DB::table('empresa_modulos')->where('empresa_id', $empresaId)
            ->where('modulo_id', DB::table('modulos')->where('clave', $modulo)->value('id'))->update(['activo' => false]);
        app(Autorizador::class)->olvidar();
        Cache::flush();
    }

    public function test_sin_recursos_humanos_las_altas_provisionales_las_verifica_el_administrador(): void
    {
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();
        $sede = $this->crearSede($empresa, 'CEN');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        app(Tenant::class)->conEmpresa($empresa->id, fn () => Colaborador::create(['num_empleado' => 'P-1', 'nombre' => 'Luis', 'apellido_paterno' => 'Can', 'sede_id' => $sede->id])
            ->forceFill(['provisional' => true])->save());

        // Con Recursos Humanos (Candidatos contratado)
        $this->assertSame('Recursos Humanos', DependenciasModulos::quienVerificaAltas($empresa->id));
        $this->actingAs($admin)->get('/colaboradores')->assertOk()->assertSee('Registrado en la caseta: Recursos Humanos debe verificarlo.');

        // Plan sin Recursos Humanos
        $this->apagar($empresa->id, 'candidatos');
        $this->flushSession();
        $this->assertSame('el administrador', DependenciasModulos::quienVerificaAltas($empresa->id));
        $this->actingAs($admin)->get('/colaboradores')->assertOk()
            ->assertSee('Registrado en la caseta: el administrador debe verificarlo.')
            ->assertDontSee('Recursos Humanos debe verificarlo');
    }

    public function test_autorizaciones_tiene_su_pagina_en_estructura_y_recepcion_queda_para_recursos_humanos(): void
    {
        $manual = app(Manual::class);
        $autorizaciones = $manual->pagina('autorizaciones');
        $recepcion = $manual->pagina('recepcion-y-autorizaciones');
        $this->assertNotNull($autorizaciones);
        $this->assertSame(['Autorizaciones', 'Estructura', ['autorizaciones']], [$autorizaciones->titulo, $autorizaciones->seccion, $autorizaciones->modulos]);
        $this->assertSame(['Recursos Humanos', ['recepcion_rh']], [$recepcion->seccion, $recepcion->modulos]);

        $textoAut = (string) file_get_contents(base_path('docs/usuario/autorizaciones.md'));
        $textoRec = (string) file_get_contents(base_path('docs/usuario/recepcion-y-autorizaciones.md'));
        // Cada tema en su página, con enlaces cruzados
        $this->assertStringContainsString('## Cómo delegar tus autorizaciones («No molestar»)', $textoAut);
        $this->assertStringNotContainsString('## Cómo delegar', $textoRec);
        $this->assertStringNotContainsString('## Cómo atender a quien espera en Recepción', $textoAut);
        $this->assertStringContainsString('](recepcion-y-autorizaciones.md)', $textoAut);
        $this->assertStringContainsString('](autorizaciones.md', $textoRec);

        // El botón de ayuda de Autorizaciones abre su página, también en un plan sin Recursos Humanos
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $this->apagar($empresa->id, 'candidatos');
        $this->apagar($empresa->id, 'recepcion_rh');
        $this->assertSame('autorizaciones', $manual->paginaDeModulo('autorizaciones', User::findOrFail($admin->id))?->slug);
        $this->assertFalse($manual->puedeVer(User::findOrFail($admin->id), $recepcion));
    }
}
