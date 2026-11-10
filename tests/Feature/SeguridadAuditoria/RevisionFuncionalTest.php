<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PaseSalida;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Regresiones de la revisión funcional 2026-10-06
 * (docs/seguridad/revision-funcional-2026-10-06.md).
 */
class RevisionFuncionalTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful();
    }

    /**
     * FUN-01: el diálogo «Registrar Bitácora Logística» guardaba sus horarios
     * sugeridos en data-sugerencias, el mismo atributo de las cajas de
     * sugerencias de Accesos. El manejador global de Escape / clic afuera lo
     * tomaba por una caja abierta: lo ocultaba y le borraba todo el contenido
     * (el diálogo quedaba abierto, invisible y la página sin responder).
     */
    public function test_fun01_el_dialogo_de_transporte_no_usa_el_atributo_de_las_cajas_de_sugerencias(): void
    {
        $admin = User::where('username', 'admin.demo')->firstOrFail();

        $html = $this->actingAs($admin)->get(route('transporte.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<dialog id="dialogoAltaTransporte"[^>]*data-horarios-sugeridos="/', $html);
        $this->assertDoesNotMatchRegularExpression('/<dialog[^>]*data-sugerencias/', $html);

        // Ninguna vista usa data-sugerencias con valor: queda reservado a las cajas (vacías) de sugerencias
        $conValor = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($f) => preg_match('/data-sugerencias="/', $f->getContents()))
            ->map(fn ($f) => $f->getRelativePathname())->values()->all();
        $this->assertSame([], $conValor);

        $js = File::get(public_path('js/plataforma.js'));
        $this->assertStringContainsString("getAttribute('data-horarios-sugeridos')", $js);
        $this->assertStringNotContainsString("getAttribute('data-sugerencias')", $js);
    }

    /**
     * FUN-02: al unir un alta provisional con el colaborador correcto, el host
     * y «a quién visita» de Accesos y el colaborador destino de un Pase de
     * salida se quedaban apuntando al provisional (dado de baja).
     */
    public function test_fun02_unir_un_duplicado_mueve_host_visita_y_destino_del_pase(): void
    {
        $empresa = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $provisional = Colaborador::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('nombre', 'Beto')->firstOrFail();
        $correcto = Colaborador::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('nombre', 'Roberto')->where('apellido_paterno', 'Hernández')->firstOrFail();

        $acceso = Acceso::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        $pase = PaseSalida::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        DB::table('accesos')->where('id', $acceso->id)->update(['host_colaborador_id' => $provisional->id, 'visita_colaborador_id' => $provisional->id]);
        DB::table('pases_salida')->where('id', $pase->id)->update(['colaborador_destino_id' => $provisional->id]);

        $rh = User::where('username', 'rh.demo')->firstOrFail();
        $this->actingAs($rh)->put(route('colaboradores.fusionar', $provisional->id), ['destino_id' => $correcto->id])
            ->assertRedirect(route('colaboradores.index'))->assertSessionHasNoErrors();

        $this->assertSame($correcto->id, (int) DB::table('accesos')->where('id', $acceso->id)->value('host_colaborador_id'));
        $this->assertSame($correcto->id, (int) DB::table('accesos')->where('id', $acceso->id)->value('visita_colaborador_id'));
        $this->assertSame($correcto->id, (int) DB::table('pases_salida')->where('id', $pase->id)->value('colaborador_destino_id'));
    }

    /**
     * FUN-02 (guarda): toda llave foránea a colaboradores debe moverse al unir
     * duplicados. Si un módulo nuevo agrega una columna y no la registra, esta
     * prueba falla.
     */
    public function test_fun02_toda_columna_que_apunta_a_colaboradores_se_mueve_al_unir(): void
    {
        $registradas = collect(AdministradorColaboradores::REFERENCIAS)->map(fn ($c, $t) => "{$t}.{$c}")->values()
            ->merge(collect(AdministradorColaboradores::REFERENCIAS_ADICIONALES)->map(fn ($p) => "{$p[0]}.{$p[1]}"));
        // No son referencias de "quién": la sede adicional se borra con el provisional y la unión misma
        $excluidas = ['colaborador_sede.colaborador_id', 'colaboradores.fusionado_en_id'];

        $faltan = [];
        foreach (Schema::getTableListing() as $tabla) {
            $tabla = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
            foreach (Schema::getForeignKeys($tabla) as $fk) {
                if ($fk['foreign_table'] !== 'colaboradores') {
                    continue;
                }
                foreach ($fk['columns'] as $columna) {
                    $clave = "{$tabla}.{$columna}";
                    if (! in_array($clave, $excluidas, true) && ! $registradas->contains($clave)) {
                        $faltan[] = $clave;
                    }
                }
            }
        }

        $this->assertSame([], $faltan, 'Columnas que apuntan a colaboradores sin registrar en AdministradorColaboradores::REFERENCIAS(_ADICIONALES)');
    }

    /**
     * FUN-03: en el teléfono (390 px) las hojas para imprimir con tablas
     * anchas (expediente de Lost & Found, acuse, auditoría de inventario)
     * movían toda la página de lado. La tabla ahora se desplaza dentro de la hoja.
     */
    public function test_fun03_las_tablas_de_las_hojas_se_desplazan_dentro_de_la_hoja_en_el_telefono(): void
    {
        $css = File::get(public_path('css/plataforma.css'));

        $this->assertMatchesRegularExpression(
            '/@media screen and \(max-width: 575\.98px\) \{\s*\.tabla-impresion \{ display: block; overflow-x: auto; \}/',
            $css
        );
    }
}
