<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Personas\AdministradorPersonas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class PersonasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const FOLIO = 'MNRSLR85031423M700';

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function proveedor(string $nombre, string $categoria = 'proveedor', ?Empresa $empresa = null, bool $activo = true): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::create(['nombre' => $nombre, 'categoria' => $categoria, 'activo' => $activo]), $empresa);
    }

    private function persona(string $nombre, array $extra = [], ?Empresa $empresa = null, ?User $autor = null): Persona
    {
        return $this->enEmpresa(function () use ($nombre, $extra, $autor) {
            $p = new Persona(array_merge(['nombre_completo' => $nombre], $extra));
            $p->forceFill(['creado_por' => ($autor ?? $this->admin)->id])->save();

            return $p;
        }, $empresa);
    }

    private function buscarPersona(string $nombre): Persona
    {
        return $this->enEmpresa(fn () => Persona::where('nombre_completo', $nombre)->firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'visitante',
            'categoria' => 'general',
            'nombre_completo' => '  Laura   Méndez Ríos ',
            'proveedor_id' => '',
            'empresa_procedencia' => 'Particular',
            'tipo_identificacion' => 'ine',
            'folio_identificacion' => 'mnrslr-850314 23m700',
            'telefono' => '(998) 145-7820',
            'motivo_visita' => 'Reunión con Gerencia.',
        ], $extra);
    }

    /**
     * @param  array<string, Alcance>  $permisos
     */
    private function usuarioCon(string $rol, array $permisos): User
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $modelo = $roles->crearRol($sa, $this->empresa->id, ['nombre' => $rol, 'nivel_jerarquia' => 45]);
        $roles->sincronizarPermisos($sa, $modelo, $permisos);

        return $this->crearUsuario($this->empresa, $rol, $this->centro);
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_normaliza_audita_enmascarado_y_aparece_en_la_lista(): void
    {
        $this->actingAs($this->admin)->post('/personas', $this->datos())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ok', 'Persona «Laura Méndez Ríos» registrada correctamente.');

        $p = $this->buscarPersona('Laura Méndez Ríos');
        $this->assertSame($this->empresa->id, $p->empresa_id);
        $this->assertSame(self::FOLIO, $p->folio_identificacion);
        $this->assertSame('9981457820', $p->telefono);
        $this->assertSame('ine', $p->tipo_identificacion);
        $this->assertSame($this->admin->id, $p->creado_por);

        $auditoria = Auditoria::where('evento', 'visitantes.creado')->where('auditable_id', $p->id)->firstOrFail();
        $this->assertSame('**************M700', $auditoria->despues['folio_identificacion']);
        $this->assertSame('******7820', $auditoria->despues['telefono']);
        $this->assertStringNotContainsString(self::FOLIO, json_encode($auditoria->despues));

        // El administrador puede editarla: ve el folio completo y lo encuentra en el buscador
        $this->actingAs($this->admin)->get('/personas')->assertOk()
            ->assertSee('Padrón de Personas')->assertSee('Registrar Persona')->assertSee('Laura Méndez Ríos')
            ->assertSee('Visitante general')->assertSee('Viene de:')->assertSee('Particular')
            ->assertSee(self::FOLIO)->assertSee('id="persona-'.$p->id.'"', false)
            ->assertSee('data-texto="laura méndez ríos particular visitante general '.mb_strtolower(self::FOLIO).'"', false);
    }

    public function test_redireccion_tras_guardar_resalta_la_ficha(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/personas', $this->datos());
        $p = $this->buscarPersona('Laura Méndez Ríos');
        $respuesta->assertRedirect(route('personas.index').'#persona-'.$p->id);

        $this->actingAs($this->admin)->put("/personas/{$p->id}", $this->datos(['nombre_completo' => 'Laura Méndez']))
            ->assertRedirect(route('personas.index').'#persona-'.$p->id)
            ->assertSessionHas('ok', 'Perfil de «Laura Méndez» actualizado correctamente.');
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.actualizado', 'auditable_id' => $p->id]);
    }

    public function test_folio_unico_por_empresa_sin_importar_formato_y_se_permite_en_otra(): void
    {
        $this->actingAs($this->admin)->post('/personas', $this->datos())->assertSessionHasNoErrors();

        // Mismo folio escrito distinto: choca y dice quién lo tiene
        $this->actingAs($this->admin)->post('/personas', $this->datos(['nombre_completo' => 'Impostor', 'folio_identificacion' => 'MNRSLR 850314.23/M700']))
            ->assertSessionHasErrors(['folio_identificacion' => 'Ya existe otra persona registrada en esta empresa con ese folio de identificación: «Laura Méndez Ríos» (Visitante).']);

        // Al editarse a sí misma no choca
        $p = $this->buscarPersona('Laura Méndez Ríos');
        $this->actingAs($this->admin)->put("/personas/{$p->id}", $this->datos(['folio_identificacion' => self::FOLIO]))->assertSessionHasNoErrors();

        // Sin folio no chocan entre sí
        $this->actingAs($this->admin)->post('/personas', $this->datos(['nombre_completo' => 'Sin folio 1', 'folio_identificacion' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/personas', $this->datos(['nombre_completo' => 'Sin folio 2', 'folio_identificacion' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->buscarPersona('Sin folio 1')->tipo_identificacion);

        // Otra empresa: el mismo folio se permite
        $otra = $this->crearEmpresa('Hotel Dos');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->post('/personas', $this->datos())->assertSessionHasNoErrors();
        $this->assertSame(2, Persona::withoutGlobalScopes()->where('folio_identificacion', self::FOLIO)->count());
    }

    public function test_validaciones_y_reglas_de_tipo(): void
    {
        $this->actingAs($this->admin)->post('/personas', $this->datos(['telefono' => '12345']))
            ->assertSessionHasErrors(['telefono' => 'El teléfono debe tener de 10 a 15 dígitos.']);
        $this->actingAs($this->admin)->post('/personas', $this->datos(['tipo' => 'Prospecto RRHH']))->assertSessionHasErrors('tipo');
        $this->actingAs($this->admin)->post('/personas', $this->datos(['nombre_completo' => ' ']))
            ->assertSessionHasErrors(['nombre_completo' => 'El nombre completo es obligatorio.']);
        $this->actingAs($this->admin)->post('/personas', $this->datos(['tipo_identificacion' => '']))
            ->assertSessionHasErrors(['tipo_identificacion' => 'Elige el tipo de identificación del folio.']);
        $this->actingAs($this->admin)->post('/personas', $this->datos(['folio_identificacion' => 'AB#12$']))->assertSessionHasErrors('folio_identificacion');
        $this->actingAs($this->admin)->post('/personas', $this->datos(['tipo_identificacion' => 'IFE']))->assertSessionHasErrors('tipo_identificacion');

        // Proveedor de otra empresa o desactivado: no
        $ajeno = $this->proveedor('Ajeno SA', 'proveedor', $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->admin)->post('/personas', $this->datos(['tipo' => 'proveedor', 'proveedor_id' => $ajeno->id]))
            ->assertSessionHasErrors(['proveedor_id' => 'El proveedor no existe en esta empresa o está desactivado.']);
        $inactivo = $this->proveedor('Inactivo SA', 'proveedor', null, false);
        $this->actingAs($this->admin)->post('/personas', $this->datos(['tipo' => 'proveedor', 'proveedor_id' => $inactivo->id]))->assertSessionHasErrors('proveedor_id');

        // Proveedor registrado: se liga y el texto libre sobra; la categoría solo aplica a visitantes
        $maya = $this->proveedor('Mantenimiento Maya', 'contratista');
        $this->actingAs($this->admin)->post('/personas', $this->datos(['tipo' => 'contratista', 'categoria' => 'familiar', 'proveedor_id' => $maya->id]))->assertSessionHasNoErrors();
        $p = $this->buscarPersona('Laura Méndez Ríos');
        $this->assertSame([$maya->id, null, 'general'], [$p->proveedor_id, $p->empresa_procedencia, $p->categoria]);
        $this->actingAs($this->admin)->get('/personas')->assertSee('Mantenimiento Maya')->assertSee('Contratista');

        // Un visitante no representa a un proveedor
        $this->actingAs($this->admin)->put("/personas/{$p->id}", $this->datos(['tipo' => 'visitante', 'categoria' => 'prospecto_rrhh', 'proveedor_id' => $maya->id]))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame([null, 'Particular', 'prospecto_rrhh'], [$p->proveedor_id, $p->empresa_procedencia, $p->categoria]);
        $this->actingAs($this->admin)->get('/personas')->assertSee('Candidato/Prospecto');

        // Editar conserva un proveedor que ya se desactivó
        $p->forceFill(['tipo' => 'proveedor', 'proveedor_id' => $inactivo->id])->save();
        $this->actingAs($this->admin)->put("/personas/{$p->id}", $this->datos(['tipo' => 'proveedor', 'proveedor_id' => $inactivo->id]))->assertSessionHasNoErrors();
        $this->assertSame($inactivo->id, $p->fresh()->proveedor_id);
    }

    // ------------------------------------------------------ Permisos y alcance

    public function test_quien_solo_consulta_ve_el_folio_enmascarado_y_no_registra(): void
    {
        $this->persona('Laura Méndez Ríos', ['tipo_identificacion' => 'ine', 'folio_identificacion' => self::FOLIO, 'telefono' => '9981457820']);

        // Plantilla Agente: en Padrones solo consulta
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $html = $this->actingAs($agente)->get('/personas')->assertOk()
            ->assertSee('Laura Méndez Ríos')->assertSee('••••M700')
            ->assertDontSee(self::FOLIO)->assertDontSee(mb_strtolower(self::FOLIO))->assertDontSee('9981457820')
            ->assertDontSee('Registrar Persona')->assertDontSee('data-accion="editar-registro"', false)
            ->assertDontSee('dialogoNuevaPersona')->getContent();
        $this->assertStringNotContainsString('M700"', $html);

        $this->actingAs($agente)->post('/personas', $this->datos())->assertForbidden();
        $p = $this->buscarPersona('Laura Méndez Ríos');
        $this->actingAs($agente)->put("/personas/{$p->id}", $this->datos())->assertForbidden();
        $this->actingAs($agente)->patch("/personas/{$p->id}/estado", ['activo' => '0'])->assertForbidden();
        $this->actingAs($agente)->postJson('/personas/rapido', $this->datos())->assertForbidden();
        // Sí busca (la caseta consulta antes de registrar en la bitácora)
        $this->actingAs($agente)->getJson('/personas/buscar?q=laura')->assertOk()->assertJsonPath('resultados.0.folio', 'INE ••••M700');

        $this->actingAs($this->crearUsuario($this->empresa))->get('/personas')->assertForbidden();
    }

    public function test_con_alcance_propios_edita_solo_lo_que_registro(): void
    {
        $capturista = $this->usuarioCon('Capturista', [
            'visitantes.ver' => Alcance::Propios, 'visitantes.crear' => Alcance::Propios,
            'visitantes.editar' => Alcance::Propios, 'visitantes.eliminar' => Alcance::Propios,
        ]);
        $ajena = $this->persona('Del Administrador', ['tipo_identificacion' => 'pasaporte', 'folio_identificacion' => 'G48291736']);

        $this->actingAs($capturista)->post('/personas', $this->datos())->assertSessionHasNoErrors();
        $propia = $this->buscarPersona('Laura Méndez Ríos');
        $this->assertSame($capturista->id, $propia->creado_por);

        // Ve todo el padrón de la empresa, pero el folio completo solo de lo suyo
        $this->actingAs($capturista)->get('/personas')->assertOk()
            ->assertSee('Del Administrador')->assertSee('••••1736')->assertDontSee('G48291736')
            ->assertSee(self::FOLIO)
            ->assertSee('data-url="'.route('personas.update', $propia->id).'"', false)
            ->assertDontSee('data-url="'.route('personas.update', $ajena->id).'"', false)
            ->assertDontSee(route('personas.estado', $ajena->id));

        $this->actingAs($capturista)->put("/personas/{$propia->id}", $this->datos(['nombre_completo' => 'Laura M.']))->assertSessionHasNoErrors();
        $this->actingAs($capturista)->put("/personas/{$ajena->id}", $this->datos(['folio_identificacion' => '']))->assertNotFound();
        $this->actingAs($capturista)->patch("/personas/{$ajena->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->actingAs($capturista)->patch("/personas/{$propia->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertTrue($ajena->fresh()->activo);

        // El aviso de folio repetido dice quién lo tiene aunque no sea suya (puede ver el padrón)
        $this->actingAs($capturista)->post('/personas', $this->datos(['nombre_completo' => 'Otro', 'tipo_identificacion' => 'pasaporte', 'folio_identificacion' => 'g4829-1736']))
            ->assertSessionHasErrors(['folio_identificacion' => 'Ya existe otra persona registrada en esta empresa con ese folio de identificación: «Del Administrador» (Visitante).']);

        // Alcance de sede (Jefe de seguridad): las personas no tienen sede, edita todo el padrón
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->put("/personas/{$ajena->id}", $this->datos(['nombre_completo' => 'Editado por el jefe', 'folio_identificacion' => '']))->assertSessionHasNoErrors();
        $this->assertSame('Editado por el jefe', $ajena->fresh()->nombre_completo);
        $this->assertNull(app(AdministradorPersonas::class)->idsEnAlcance($jefe, 'visitantes.editar'));
    }

    // -------------------------------------------- Contrato con la ficha del proveedor

    public function test_desde_la_ficha_del_proveedor_abre_el_alta_con_el_proveedor_elegido(): void
    {
        $maya = $this->proveedor('Mantenimiento Maya', 'contratista');
        $sureste = $this->proveedor('Alimentos del Sureste', 'proveedor');
        $ajeno = $this->proveedor('Ajeno SA', 'contratista', $this->crearEmpresa('Hotel Dos'));

        $html = $this->actingAs($this->admin)->get("/personas?nuevo=1&proveedor={$maya->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoNuevaPersona"[^>]*data-abrir-al-cargar/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$maya->id.'" data-categoria="contratista"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="contratista"\s+selected/', $html);
        $this->assertStringContainsString('name="volver" value="proveedor"', $html);
        $this->assertStringContainsString('Registrando personal de <strong>Mantenimiento Maya</strong>', $html);

        $html = $this->actingAs($this->admin)->get("/personas?nuevo=1&proveedor={$sureste->id}")->getContent();
        $this->assertMatchesRegularExpression('/<option value="proveedor"\s+selected/', $html);

        // Proveedor de otra empresa: se abre el alta, sin preselección ni regreso
        $html = $this->actingAs($this->admin)->get("/personas?nuevo=1&proveedor={$ajeno->id}")->assertDontSee('Ajeno SA')->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoNuevaPersona"[^>]*data-abrir-al-cargar/', $html);
        $this->assertStringContainsString('name="volver" value=""', $html);

        // Sin ?nuevo=1 el diálogo no se abre solo
        $html = $this->actingAs($this->admin)->get('/personas')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<dialog id="dialogoNuevaPersona"[^>]*data-abrir-al-cargar/', $html);

        // Quien no puede registrar no recibe el diálogo
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get("/personas?nuevo=1&proveedor={$maya->id}")->assertOk()->assertDontSee('dialogoNuevaPersona');
    }

    public function test_al_guardar_con_volver_proveedor_regresa_a_la_ficha_si_existe(): void
    {
        $maya = $this->proveedor('Mantenimiento Maya', 'contratista');
        $datos = $this->datos(['tipo' => 'contratista', 'proveedor_id' => $maya->id, 'volver' => 'proveedor']);

        // Mientras la ficha del proveedor no exista, regresa al padrón
        if (! Route::has('proveedores.show')) {
            $respuesta = $this->actingAs($this->admin)->post('/personas', $datos);
            $p = $this->buscarPersona('Laura Méndez Ríos');
            $respuesta->assertRedirect(route('personas.index').'#persona-'.$p->id);
            $p->delete();
        }

        Route::get('/proveedores/{proveedor}', fn () => 'ficha')->middleware('web')->name('proveedores.show');
        app('router')->getRoutes()->refreshNameLookups();

        $this->actingAs($this->admin)->post('/personas', $datos)->assertRedirect(route('proveedores.show', $maya->id));
        $p = $this->buscarPersona('Laura Méndez Ríos');
        $this->actingAs($this->admin)->put("/personas/{$p->id}", $datos + ['nombre_completo' => 'X'])->assertRedirect(route('proveedores.show', $maya->id));

        // Sin proveedor válido (visitante) o sin la bandera: al padrón
        $this->actingAs($this->admin)->put("/personas/{$p->id}", array_merge($datos, ['tipo' => 'visitante']))->assertRedirect(route('personas.index').'#persona-'.$p->id);
        $this->actingAs($this->admin)->put("/personas/{$p->id}", array_merge($datos, ['volver' => 'https://evil.example']))->assertRedirect(route('personas.index').'#persona-'.$p->id);

        // Con error, regresa al alta con el regreso conservado
        $this->actingAs($this->admin)->from("/personas?nuevo=1&proveedor={$maya->id}")
            ->post('/personas', array_merge($datos, ['telefono' => '1', '_dialogo' => 'crear']))->assertSessionHasErrors('telefono');
        $html = $this->actingAs($this->admin)->get("/personas?nuevo=1&proveedor={$maya->id}")->getContent();
        $this->assertStringContainsString('name="volver" value="proveedor"', $html);
    }

    // ------------------------------------------------------- JSON para Accesos

    public function test_buscar_por_nombre_o_folio_dentro_de_la_empresa(): void
    {
        $maya = $this->proveedor('Mantenimiento Maya', 'contratista');
        $this->persona('José Luis Ek Cauich', ['tipo' => 'contratista', 'proveedor_id' => $maya->id, 'tipo_identificacion' => 'ine', 'folio_identificacion' => 'EKCSJS88111523H800']);
        $this->persona('José Pérez', ['empresa_procedencia' => 'Particular']);
        $this->persona('José Baja', ['activo' => false]);
        $this->persona('José Ajeno', [], $this->crearEmpresa('Hotel Dos'));

        $this->actingAs($this->admin)->getJson('/personas/buscar?q=j')->assertOk()->assertExactJson(['resultados' => []]);
        $res = $this->actingAs($this->admin)->getJson('/personas/buscar?q=jos')->assertOk()->json('resultados');
        $this->assertSame(['José Luis Ek Cauich', 'José Pérez'], array_column($res, 'nombre_completo'));
        $this->assertSame([
            'tipo' => 'contratista', 'empresa' => 'Mantenimiento Maya', 'folio' => 'INE ••••H800',
        ], array_intersect_key($res[0], array_flip(['tipo', 'empresa', 'folio'])));
        $this->assertSame('Particular', $res[1]['empresa']);
        $this->assertStringNotContainsString('EKCSJS', json_encode($res));

        // Varias palabras y folio (con guiones o minúsculas, por su inicio)
        $this->assertCount(1, $this->actingAs($this->admin)->getJson('/personas/buscar?q=luis%20cauich')->json('resultados'));
        $this->assertSame('José Luis Ek Cauich', $this->actingAs($this->admin)->getJson('/personas/buscar?q=ekcs-js88')->json('resultados.0.nombre_completo'));
        $this->assertCount(0, $this->actingAs($this->admin)->getJson('/personas/buscar?q=H800')->json('resultados'));

        // Máximo 15
        foreach (range(1, 17) as $n) {
            $this->persona("Visitante número {$n}");
        }
        $this->assertCount(15, $this->actingAs($this->admin)->getJson('/personas/buscar?q=visitante')->json('resultados'));

        $this->actingAs($this->crearUsuario($this->empresa))->getJson('/personas/buscar?q=jos')->assertForbidden();
    }

    public function test_registro_rapido_responde_json_y_409_con_la_persona_del_folio(): void
    {
        $maya = $this->proveedor('Mantenimiento Maya', 'contratista');

        $this->actingAs($this->admin)->postJson('/personas/rapido', $this->datos(['tipo' => 'contratista', 'proveedor_id' => $maya->id]))
            ->assertCreated()->assertJson(['ok' => true, 'persona' => [
                'nombre_completo' => 'Laura Méndez Ríos', 'tipo' => 'contratista', 'tipo_etiqueta' => 'Contratista',
                'empresa' => 'Mantenimiento Maya', 'proveedor_id' => $maya->id, 'folio' => 'INE ••••M700', 'activo' => true,
            ]]);
        $p = $this->buscarPersona('Laura Méndez Ríos');
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.creado', 'auditable_id' => $p->id]);

        $this->actingAs($this->admin)->postJson('/personas/rapido', $this->datos(['nombre_completo' => 'Otra', 'folio_identificacion' => 'MNRSLR85031423-M700']))
            ->assertStatus(409)->assertJson(['ok' => false, 'persona' => ['id' => $p->id, 'nombre_completo' => 'Laura Méndez Ríos', 'folio' => 'INE ••••M700']])
            ->assertJsonPath('mensaje', 'Ya existe otra persona registrada en esta empresa con ese folio de identificación: «Laura Méndez Ríos» (Contratista).');

        $this->actingAs($this->admin)->postJson('/personas/rapido', $this->datos(['nombre_completo' => '', 'folio_identificacion' => '']))
            ->assertStatus(422)->assertJson(['ok' => false, 'mensaje' => 'El nombre completo es obligatorio.'])->assertJsonValidationErrors('nombre_completo', 'errores');
        // Aunque el navegador no pida JSON, la respuesta es JSON
        $this->actingAs($this->admin)->post('/personas/rapido', $this->datos(['telefono' => '1', 'folio_identificacion' => '']))
            ->assertStatus(422)->assertJsonPath('errores.telefono.0', 'El teléfono debe tener de 10 a 15 dígitos.');

        // El parcial para la Bitácora de accesos se dibuja para quien puede registrar
        $this->actingAs($this->admin);
        $html = $this->enEmpresa(fn () => view('seguridad.personas._registro-rapido', ['proveedorSugerido' => $maya->id])->render());
        $this->assertStringContainsString('Registro Rápido de Persona', $html);
        $this->assertStringContainsString('data-registro-rapido-persona', $html);
        $this->assertMatchesRegularExpression('/<option value="contratista"\s+selected/', $html);
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro));
        $this->assertStringNotContainsString('data-registro-rapido-persona', $this->enEmpresa(fn () => view('seguridad.personas._registro-rapido')->render()));
    }

    // ------------------------------------------------- Estado y empresa

    public function test_baja_logica_y_reactivacion(): void
    {
        $p = $this->persona('Laura Méndez Ríos');

        $this->actingAs($this->admin)->patch("/personas/{$p->id}/estado", ['activo' => '0'])
            ->assertRedirect(route('personas.index').'#persona-'.$p->id)
            ->assertSessionHas('aviso', 'Registro de «Laura Méndez Ríos» dado de baja. Puedes reactivarlo con un clic cuando quieras.');
        $this->assertFalse($p->fresh()->activo);
        $this->actingAs($this->admin)->get('/personas')->assertSee('BAJA')->assertSee('¿Reactivar este registro?');
        $this->actingAs($this->admin)->patch("/personas/{$p->id}/estado", ['activo' => '1'])->assertSessionHas('ok', 'Registro de «Laura Méndez Ríos» reactivado correctamente.');
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.desactivado', 'auditable_id' => $p->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.reactivado', 'auditable_id' => $p->id]);

        // Editar nunca cambia el estado (en SEGCAT el formulario traía "Estatus" y bastaba "editar")
        $this->actingAs($this->admin)->put("/personas/{$p->id}", $this->datos(['activo' => '0', 'estatus' => '0']))->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->activo);
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $p = $this->persona('Exclusivo Uno', ['tipo_identificacion' => 'ine', 'folio_identificacion' => self::FOLIO]);
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');

        $this->actingAs($ajeno)->get('/personas')->assertOk()->assertDontSee('Exclusivo Uno');
        $this->actingAs($ajeno)->put("/personas/{$p->id}", $this->datos())->assertNotFound();
        $this->actingAs($ajeno)->patch("/personas/{$p->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->actingAs($ajeno)->getJson('/personas/buscar?q=exclusivo')->assertExactJson(['resultados' => []]);
        // El folio de otra empresa no choca ni se revela
        $this->actingAs($ajeno)->postJson('/personas/rapido', $this->datos())->assertCreated();
        $this->assertSame('Exclusivo Uno', $p->fresh()->nombre_completo);
    }

    public function test_superadmin_elige_empresa_y_el_menu_enlaza_la_pantalla(): void
    {
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/personas')->assertOk()->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/personas')->assertSee('Registrar Persona');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->post('/personas', $this->datos())->assertSessionHasNoErrors();
        $this->assertSame($this->empresa->id, $this->buscarPersona('Laura Méndez Ríos')->empresa_id);
        $this->flushSession();
        $this->actingAs($sa)->post('/personas', $this->datos(['folio_identificacion' => '']))->assertNotFound();

        $this->assertSame('personas.index', Modulo::where('clave', 'visitantes')->value('ruta'));
        $this->actingAs($this->admin)->get('/')->assertSee(route('personas.index'));
    }

    public function test_datos_demo_con_folios_validos_y_unicos(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $todas = $this->enEmpresa(fn () => Persona::with('proveedor')->get(), $demo);

        $this->assertCount(12, $todas);
        $this->assertSame(['Raúl Domínguez Can'], $todas->where('activo', false)->pluck('nombre_completo')->values()->all());
        $this->assertSame(['contratista' => 3, 'proveedor' => 3, 'visitante' => 6], $todas->countBy('tipo')->sortKeys()->all());
        $this->assertSame(['familiar' => 1, 'general' => 9, 'prospecto_rrhh' => 2], $todas->countBy('categoria')->sortKeys()->all());
        $folios = $todas->pluck('folio_identificacion')->filter();
        $this->assertCount(11, $folios->unique());
        foreach ($folios as $folio) {
            $this->assertMatchesRegularExpression(AdministradorPersonas::FOLIO, $folio);
        }
        foreach ($todas as $p) {
            $this->assertNotNull($p->empresaQueRepresenta() ?? ($p->categoria !== 'general' ? 'ok' : null), $p->nombre_completo);
        }
    }
}
