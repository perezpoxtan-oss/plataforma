<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AltaPorVerificarRegistrada;
use App\Models\Acceso;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Padrones\AltasPorVerificar;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Altas pendientes de verificar (ADR-0006): la caseta, desde Operación,
 * registra el vehículo, la empresa externa o la persona que no está en su
 * padrón; antes se le sugieren los parecidos, se usa de inmediato y quien
 * edita el padrón la acepta, la rechaza o la une con el registro correcto.
 */
class AltasPorVerificarTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function vehiculo(string $placas, array $extra = [], ?Empresa $empresa = null): Vehiculo
    {
        return $this->enEmpresa(fn () => Vehiculo::create(array_merge([
            'placas' => Vehiculo::normalizarPlacas($placas), 'propiedad' => 'propio_huesped', 'tipo' => 'sedan', 'marca' => 'NISSAN', 'modelo' => 'VERSA', 'color' => 'BLANCO',
        ], $extra)), $empresa);
    }

    private function persona(string $nombre, array $extra = [], ?Empresa $empresa = null): Persona
    {
        return $this->enEmpresa(fn () => Persona::create(array_merge(['tipo' => 'visitante', 'nombre_completo' => $nombre], $extra)), $empresa);
    }

    private function proveedor(string $nombre, ?Sede $soloEn = null, ?Empresa $empresa = null): Proveedor
    {
        return $this->enEmpresa(function () use ($nombre, $soloEn) {
            $p = Proveedor::create(['nombre' => $nombre, 'categoria' => 'proveedor', 'todas_las_sedes' => $soloEn === null]);
            $p->sedes()->sync($soloEn ? [$soloEn->id] : []);

            return $p;
        }, $empresa);
    }

    private function pendiente(Vehiculo|Persona|Proveedor $registro, ?Sede $sede = null): Vehiculo|Persona|Proveedor
    {
        $this->enEmpresa(fn () => $registro->forceFill(['verificacion' => 'pendiente', 'origen_alta' => 'accesos', 'sede_alta_id' => ($sede ?? $this->centro)->id, 'creado_por' => $this->agente->id])->save());

        return $registro;
    }

    private function fresco(Vehiculo|Persona|Proveedor $registro): Vehiculo|Persona|Proveedor
    {
        return $this->enEmpresa(fn () => $registro->fresh());
    }

    private function datosVehiculo(array $extra = []): array
    {
        return array_merge(['placas' => 'xtr-901-c', 'propiedad' => 'propio_visitante', 'tipo' => 'sedan', 'marca' => 'kia', 'color' => 'naranja'], $extra);
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    /**
     * @return list<string>
     */
    private function titulos(string $padron, array $datos, ?array $sedes = null): array
    {
        return array_column($this->enEmpresa(fn () => app(AltasPorVerificar::class)->parecidos($padron, $datos, $sedes)), 'titulo');
    }

    // ------------------------------------------------------- Referencias

    /**
     * Al unir un alta con el registro correcto, toda columna que apunta al
     * padrón debe moverse. Si un módulo nuevo agrega una y no la registra en
     * AltasPorVerificar::REFERENCIAS, esta prueba falla.
     */
    public function test_toda_columna_que_apunta_a_vehiculos_proveedores_o_personas_esta_en_referencias(): void
    {
        $tablas = ['vehiculos' => 'vehiculos', 'proveedores' => 'proveedores', 'personas' => 'personas'];
        // No son referencias de "quién": la unión misma y las sedes donde opera (se agregan al correcto)
        $excluidas = ['vehiculos.fusionado_en_id', 'proveedores.fusionado_en_id', 'personas.fusionado_en_id', 'proveedor_sede.proveedor_id'];

        $faltan = [];
        foreach (Schema::getTableListing() as $tabla) {
            $tabla = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
            foreach (Schema::getForeignKeys($tabla) as $fk) {
                $padron = array_search($fk['foreign_table'], $tablas, true);
                if ($padron === false) {
                    continue;
                }
                $registradas = collect(AltasPorVerificar::REFERENCIAS[$padron])->map(fn ($r) => "{$r[0]}.{$r[1]}");
                foreach ($fk['columns'] as $columna) {
                    $clave = "{$tabla}.{$columna}";
                    if (! in_array($clave, $excluidas, true) && ! $registradas->contains($clave)) {
                        $faltan[] = "{$padron}: {$clave}";
                    }
                }
            }
        }

        $this->assertSame([], $faltan, 'Columnas sin registrar en AltasPorVerificar::REFERENCIAS');
    }

    // -------------------------------------------------------- Parecidos

    public function test_parecidos_sin_mayusculas_acentos_ni_separadores_y_con_los_de_baja_marcados(): void
    {
        $versa = $this->vehiculo('ABC-123-A');
        $baja = $this->vehiculo('XYZ-900', ['activo' => false]);
        $rechazado = $this->pendiente($this->vehiculo('ABC-124-A'));
        $this->enEmpresa(fn () => $rechazado->forceFill(['verificacion' => 'rechazado', 'activo' => false])->save());
        $this->vehiculo('ABC-123-A', [], $otra = $this->crearEmpresa('Hotel Ajeno'));

        // Placas sin separadores y con O/0 e I/1 confundidos; nunca los rechazados ni los de otra empresa
        $this->assertSame(['ABC123A'], $this->titulos('vehiculos', ['placas' => 'abc 12-3 a']));
        $this->assertSame(['ABC123A'], $this->titulos('vehiculos', ['placas' => 'ABC-I23-A']));
        $this->assertSame(['ABC123A'], $this->titulos('vehiculos', ['placas' => 'ABC128A']));
        $this->assertSame([], $this->titulos('vehiculos', ['placas' => 'QWE-555']));
        $deBaja = $this->enEmpresa(fn () => app(AltasPorVerificar::class)->parecidos('vehiculos', ['placas' => 'XYZ-901']));
        $this->assertSame('XYZ900', $deBaja[0]['titulo']);
        $this->assertFalse($deBaja[0]['usable']);
        $this->assertSame('Dado de baja', $deBaja[0]['estado_texto']);

        // Personas: sin acentos, en otro orden o con un apellido de más; el folio exacto también cuenta
        $laura = $this->persona('Laura Méndez Ríos', ['tipo_identificacion' => 'ine', 'folio_identificacion' => 'MNRSLR85031423M700']);
        $this->persona('Pedro Canul');
        $this->assertSame(['Laura Méndez Ríos'], $this->titulos('personas', ['nombre_completo' => 'LAURA MENDEZ RIOS']));
        $this->assertSame(['Laura Méndez Ríos'], $this->titulos('personas', ['nombre_completo' => 'Mendez Rios Laura']));
        $this->assertSame(['Laura Méndez Ríos'], $this->titulos('personas', ['nombre_completo' => 'Laura Mendes Rios']));
        $this->assertSame(['Laura Méndez Ríos'], $this->titulos('personas', ['nombre_completo' => 'Otra Persona', 'folio_identificacion' => 'MNRSLR85031423M700']));
        $this->assertSame([], $this->titulos('personas', ['nombre_completo' => 'Juan Pérez']));

        // Empresas: sin "S.A. de C.V." ni signos; solo las que operan en las sedes indicadas
        $this->proveedor('Abarrotes del Caribe');
        $this->proveedor('Constructora Maya', $this->playa);
        $this->assertSame(['Abarrotes del Caribe'], $this->titulos('proveedores', ['nombre' => 'ABARROTES DEL CARIBE, S.A. DE C.V.']));
        $this->assertSame(['Constructora Maya'], $this->titulos('proveedores', ['nombre' => 'Constructora Maya SA de CV']));
        $this->assertSame([], $this->titulos('proveedores', ['nombre' => 'Constructora Maya SA de CV'], [$this->centro->id]));

        $this->assertNotNull($versa->id);
        $this->assertNotNull($baja->id);
        $this->assertNotNull($laura->id);
    }

    public function test_el_endpoint_de_parecidos_respeta_permisos(): void
    {
        $this->vehiculo('ABC-123-A');
        $sinRol = $this->crearUsuario($this->empresa);

        $this->actingAs($this->agente)->getJson('/altas-por-verificar/parecidos?padron=vehiculos&placas=ABC128A&origen=accesos')
            ->assertOk()->assertJsonPath('resultados.0.placas', 'ABC123A');
        $this->actingAs($sinRol)->getJson('/altas-por-verificar/parecidos?padron=vehiculos&placas=ABC128A&origen=accesos')->assertForbidden();
        $this->actingAs($this->agente)->getJson('/altas-por-verificar/parecidos?padron=otro&q=x')->assertForbidden();
        // Comparar un alta ("de") es solo para quien verifica
        $alta = $this->pendiente($this->vehiculo('ABC-128-A'));
        $this->actingAs($this->agente)->getJson("/altas-por-verificar/parecidos?padron=vehiculos&de={$alta->id}")->assertForbidden();
        $this->actingAs($this->admin)->getJson("/altas-por-verificar/parecidos?padron=vehiculos&de={$alta->id}")
            ->assertOk()->assertJsonCount(1, 'resultados')->assertJsonPath('resultados.0.placas', 'ABC123A');
    }

    // ------------------------------------------------- Alta desde Operación

    public function test_el_agente_da_altas_pendientes_solo_desde_operacion(): void
    {
        // Padrones: solo consulta
        $this->actingAs($this->agente)->post('/vehiculos', $this->datosVehiculo())->assertForbidden();
        // Un origen que no corresponde a ese padrón, o sin ningún permiso operativo: no
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['origen' => 'lost_found']))->assertForbidden();
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($rh)->postJson('/personas/rapido', ['tipo' => 'visitante', 'nombre_completo' => 'Pedro Canul Dzib'])->assertForbidden();
        $this->actingAs($rh)->postJson('/proveedores/rapido', ['nombre' => 'Plomería Express', 'categoria' => 'contratista', 'origen' => 'pases_salida'])->assertForbidden();

        // Desde Operación: el alta nace pendiente de verificar y se usa de inmediato
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['origen' => 'accesos']))
            ->assertCreated()->assertJsonPath('vehiculo.placas', 'XTR901C')->assertJsonPath('vehiculo.verificacion', 'pendiente');
        $this->actingAs($this->agente)->postJson('/personas/rapido', ['tipo' => 'visitante', 'nombre_completo' => 'Pedro Canul Dzib', 'origen' => 'lost_found'])
            ->assertCreated()->assertJsonPath('persona.verificacion', 'pendiente');
        $this->actingAs($this->agente)->postJson('/proveedores/rapido', ['nombre' => 'Plomería Express', 'categoria' => 'contratista', 'origen' => 'pases_salida'])
            ->assertCreated()->assertJsonPath('proveedor.verificacion', 'pendiente');

        [$v, $p, $e] = $this->enEmpresa(fn () => [Vehiculo::firstWhere('placas', 'XTR901C'), Persona::firstWhere('nombre_completo', 'Pedro Canul Dzib'), Proveedor::with('sedes')->firstWhere('nombre', 'Plomería Express')]);
        $this->assertSame(['pendiente', 'accesos', $this->centro->id], [$v->verificacion, $v->origen_alta, $v->sede_alta_id]);
        $this->assertSame(['pendiente', 'lost_found'], [$p->verificacion, $p->origen_alta]);
        // La empresa externa queda en la sede de la caseta
        $this->assertSame([$this->centro->id], $e->sedes->pluck('id')->all());
        $this->assertFalse($e->todas_las_sedes);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vehiculos.provisional', 'auditable_id' => $v->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.provisional', 'auditable_id' => $p->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.provisional', 'auditable_id' => $e->id]);

        // Se puede usar de inmediato en la búsqueda, marcado
        $this->actingAs($this->agente)->getJson('/vehiculos/buscar?q=XTR')->assertOk()->assertJsonPath('resultados.0.verificacion', 'pendiente');

        // Nunca verifica ni edita el padrón
        $this->actingAs($this->agente)->put("/vehiculos/{$v->id}/aceptar")->assertForbidden();
        $this->actingAs($this->agente)->put("/vehiculos/{$v->id}", $this->datosVehiculo())->assertForbidden();
        $this->actingAs($this->agente)->put("/proveedores/{$e->id}/rechazar", ['motivo_rechazo' => 'No existe'])->assertForbidden();
        $this->actingAs($this->agente)->put("/personas/{$p->id}/unir", ['destino_id' => 1])->assertForbidden();

        // Una empresa existente se usa tal cual, sin tocar el directorio
        $maya = $this->proveedor('Constructora Maya', $this->playa);
        $this->actingAs($this->agente)->postJson('/proveedores/rapido', ['nombre' => 'constructora  maya', 'categoria' => 'contratista', 'origen' => 'pases_salida'])
            ->assertOk()->assertJsonPath('ya_existia', true)->assertJsonPath('proveedor.id', $maya->id);
        $this->assertSame([$this->playa->id], $this->enEmpresa(fn () => $maya->sedes()->pluck('sedes.id')->all()));
    }

    public function test_quien_puede_editar_el_padron_registra_verificado(): void
    {
        $this->actingAs($this->admin)->postJson('/vehiculos/rapido', $this->datosVehiculo(['origen' => 'accesos']))
            ->assertCreated()->assertJsonPath('vehiculo.verificacion', 'verificado');
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->postJson('/personas/rapido', ['tipo' => 'visitante', 'nombre_completo' => 'Pedro Canul', 'origen' => 'accesos'])
            ->assertCreated()->assertJsonPath('persona.verificacion', 'verificado');
        $this->assertDatabaseMissing('auditoria', ['evento' => 'vehiculos.provisional']);
    }

    public function test_antes_de_crear_pregunta_es_alguno_de_estos(): void
    {
        $versa = $this->vehiculo('ABC-123-A');
        $this->persona('Laura Méndez Ríos');
        $this->proveedor('Abarrotes del Caribe');

        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['placas' => 'ABC-128-A', 'origen' => 'accesos']))
            ->assertStatus(409)->assertJsonPath('parecidos.0.id', $versa->id)->assertJsonPath('parecidos.0.usable', true);
        $this->actingAs($this->agente)->postJson('/personas/rapido', ['tipo' => 'visitante', 'nombre_completo' => 'LAURA MENDEZ RIOS', 'origen' => 'accesos'])
            ->assertStatus(409)->assertJsonPath('parecidos.0.nombre_completo', 'Laura Méndez Ríos');
        $this->actingAs($this->agente)->postJson('/proveedores/rapido', ['nombre' => 'Abarrotes del Caribe S.A. de C.V.', 'categoria' => 'proveedor', 'origen' => 'pases_salida'])
            ->assertStatus(409)->assertJsonPath('parecidos.0.nombre', 'Abarrotes del Caribe');
        // Nada se creó ni se auditó
        $this->assertSame(1, $this->enEmpresa(fn () => Vehiculo::count()));
        $this->assertSame(1, $this->enEmpresa(fn () => Persona::count()));
        $this->assertDatabaseMissing('auditoria', ['evento' => 'vehiculos.creado']);

        // La validación va primero: con datos incompletos responde el error, no los parecidos
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', ['placas' => 'ABC-128-A', 'origen' => 'accesos'])->assertStatus(422);

        // "No es ninguno": se registra
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['placas' => 'ABC-128-A', 'origen' => 'accesos', 'confirmar_nuevo' => '1']))
            ->assertCreated()->assertJsonPath('vehiculo.verificacion', 'pendiente');
    }

    public function test_la_bitacora_de_accesos_registra_pendientes_y_respeta_unidos_y_rechazados(): void
    {
        $this->actingAs($this->agente)->post('/accesos', ['_dialogo' => 'ingreso', 'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Pedro Canul Dzib',
            'modo_arribo' => 'auto', 'placas' => 'xtr-901-c', 'marca' => 'kia', 'color' => 'naranja'])->assertSessionHasNoErrors();

        [$v, $p] = $this->enEmpresa(fn () => [Vehiculo::firstWhere('placas', 'XTR901C'), Persona::firstWhere('nombre_completo', 'Pedro Canul Dzib')]);
        $this->assertSame(['pendiente', 'accesos', $this->centro->id], [$v->verificacion, $v->origen_alta, $v->sede_alta_id]);
        $this->assertSame('pendiente', $p->verificacion);

        // Rechazado: la caseta ya no lo puede usar
        $this->actingAs($this->admin)->put("/vehiculos/{$v->id}/rechazar", ['motivo_rechazo' => 'Placas inventadas'])->assertRedirect();
        $this->actingAs($this->agente)->post('/accesos', ['_dialogo' => 'ingreso', 'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Otra Visita',
            'modo_arribo' => 'auto', 'placas' => 'XTR901C', 'persona_decision' => 'distinta'])
            ->assertSessionHasErrors(['placas' => '«XTR901C» fue rechazado al verificar el Padrón vehicular (motivo: Placas inventadas). Ya no se puede usar: revisa los datos o avisa a tu supervisor.']);

        // Unida con la correcta: se usa la correcta
        $correcta = $this->persona('Pedro Canul');
        $this->actingAs($this->admin)->put("/personas/{$p->id}/unir", ['destino_id' => $correcta->id])->assertRedirect();
        $this->actingAs($this->agente)->post('/accesos', ['_dialogo' => 'ingreso', 'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'PEDRO CANUL DZIB', 'persona_id' => $p->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($correcta->id, $this->enEmpresa(fn () => Acceso::orderByDesc('id')->value('persona_id')));
    }

    // ------------------------------------------------------------ Verificar

    public function test_aceptar_completando_los_datos(): void
    {
        $alta = $this->pendiente($this->vehiculo('XTR-901-C', ['marca' => 'KIA', 'modelo' => null, 'color' => 'NARANJA']));

        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/aceptar", ['placas' => 'XTR901C', 'propiedad' => 'propio_visitante', 'tipo' => 'suv', 'marca' => 'kia', 'modelo' => 'soul', 'color' => 'naranja'])
            ->assertOk()->assertJson(['ok' => true])->assertJsonPath('ir', route('vehiculos.index').'#vehiculo-'.$alta->id);

        $alta = $this->fresco($alta);
        $this->assertSame(['verificado', $this->admin->id, 'SOUL', 'suv'], [$alta->verificacion, $alta->verificado_por, $alta->modelo, $alta->tipo]);
        $this->assertNotNull($alta->verificado_en);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vehiculos.verificado', 'auditable_id' => $alta->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vehiculos.actualizado', 'auditable_id' => $alta->id]);

        // Ya verificado: ninguna otra transición
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/aceptar")->assertStatus(422)->assertJsonValidationErrors('verificacion');
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/rechazar", ['motivo_rechazo' => 'Ya no'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/unir", ['destino_id' => $this->vehiculo('ABC-1')->id])->assertStatus(422);

        // Los datos corregidos pasan por las reglas del padrón
        $otra = $this->pendiente($this->persona('Pedro Canul'));
        $this->actingAs($this->admin)->putJson("/personas/{$otra->id}/aceptar", ['nombre_completo' => '', 'tipo' => 'visitante'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre_completo');
        $this->assertSame('pendiente', $this->fresco($otra)->verificacion);
        // Sin datos: se acepta tal cual
        $this->actingAs($this->admin)->put("/personas/{$otra->id}/aceptar")->assertRedirect(route('personas.index').'#persona-'.$otra->id);
        $this->assertSame('verificado', $this->fresco($otra)->verificacion);
    }

    public function test_rechazar_con_motivo_y_ya_no_se_usa_ni_se_reactiva(): void
    {
        $alta = $this->pendiente($this->proveedor('Fletes Fantasma', $this->centro));

        $this->actingAs($this->admin)->putJson("/proveedores/{$alta->id}/rechazar", ['motivo_rechazo' => 'no'])->assertStatus(422)->assertJsonValidationErrors('motivo_rechazo');
        $this->actingAs($this->admin)->putJson("/proveedores/{$alta->id}/rechazar", ['motivo_rechazo' => 'La empresa no existe'])->assertOk();

        $alta = $this->fresco($alta);
        $this->assertSame(['rechazado', false, 'La empresa no existe'], [$alta->verificacion, $alta->activo, $alta->motivo_rechazo]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.rechazado', 'auditable_id' => $alta->id]);

        // Se queda como historia: no se reactiva, no se edita, no sale en la búsqueda, no se puede volver a dar de alta
        $this->actingAs($this->admin)->patch("/proveedores/{$alta->id}/estado", ['activo' => 1])->assertSessionHasErrors('activo');
        $this->actingAs($this->admin)->put("/proveedores/{$alta->id}", ['nombre' => 'Fletes', 'categoria' => 'proveedor'])->assertSessionHasErrors('nombre');
        $this->assertFalse($this->fresco($alta)->activo);
        $this->actingAs($this->admin)->getJson('/proveedores/buscar?q=fletes')->assertOk()->assertJsonCount(0, 'resultados');
        $this->actingAs($this->agente)->postJson('/proveedores/rapido', ['nombre' => 'Fletes Fantasma', 'categoria' => 'proveedor', 'origen' => 'pases_salida'])
            ->assertStatus(422)->assertJsonPath('errores.nombre.0', '«Fletes Fantasma» fue rechazada al verificar el directorio de Empresas externas (motivo: La empresa no existe). Ya no se puede usar: revisa los datos o avisa a tu supervisor.');
        $this->actingAs($this->admin)->get('/proveedores')->assertOk()->assertSee('Rechazada')->assertSee('Motivo: La empresa no existe');
    }

    public function test_unir_mueve_todas_las_referencias_al_registro_correcto(): void
    {
        $correcto = $this->vehiculo('ABC-123-A');
        $this->actingAs($this->agente)->post('/accesos', ['_dialogo' => 'ingreso', 'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Pedro Canul',
            'modo_arribo' => 'auto', 'placas' => 'ABC-128-A', 'marca' => 'nissan', 'color' => 'blanco'])->assertSessionHasNoErrors();
        $alta = $this->enEmpresa(fn () => Vehiculo::firstWhere('placas', 'ABC128A'));
        $acceso = $this->enEmpresa(fn () => Acceso::orderByDesc('id')->first());
        $this->assertSame($alta->id, $acceso->vehiculo_id);

        // Solo con un registro activo y verificado
        $otraAlta = $this->pendiente($this->vehiculo('ABC-129-A'));
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/unir", ['destino_id' => $otraAlta->id])->assertStatus(422)->assertJsonValidationErrors('destino_id');
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/unir", ['destino_id' => $alta->id])->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/unir", [])->assertStatus(422)->assertJsonValidationErrors('destino_id');
        $ajeno = $this->vehiculo('ABC-123-A', [], $this->crearEmpresa('Hotel Ajeno'));
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/unir", ['destino_id' => $ajeno->id])->assertNotFound();

        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/unir", ['destino_id' => $correcto->id])->assertOk()
            ->assertJsonPath('ir', route('vehiculos.index').'#vehiculo-'.$correcto->id);

        $this->assertSame($correcto->id, $this->enEmpresa(fn () => $acceso->fresh()->vehiculo_id));
        $alta = $this->fresco($alta);
        $this->assertSame(['rechazado', false, $correcto->id, 'Unido con «ABC123A»'], [$alta->verificacion, $alta->activo, $alta->fusionado_en_id, $alta->motivo_rechazo]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vehiculos.fusionado', 'auditable_id' => $alta->id]);

        // Las placas del alta unida llevan al correcto
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['placas' => 'ABC128A', 'origen' => 'accesos']))
            ->assertStatus(409)->assertJsonPath('vehiculo.id', $correcto->id);
    }

    public function test_unir_empresas_y_personas_mueve_su_gente_flotilla_sedes_e_identificacion(): void
    {
        $correcta = $this->proveedor('Abarrotes del Caribe', $this->playa);
        $alta = $this->pendiente($this->proveedor('Abarrotes Caribe SA', $this->centro));
        $chofer = $this->persona('Jesús Balam', ['tipo' => 'proveedor', 'proveedor_id' => $alta->id]);
        $camion = $this->vehiculo('UPS-03', ['propiedad' => 'empresa_proveedor', 'proveedor_id' => $alta->id]);

        $this->actingAs($this->admin)->put("/proveedores/{$alta->id}/unir", ['destino_id' => $correcta->id])->assertRedirect();
        $this->assertSame($correcta->id, $this->fresco($chofer)->proveedor_id);
        $this->assertSame($correcta->id, $this->fresco($camion)->proveedor_id);
        $this->assertEqualsCanonicalizing([$this->centro->id, $this->playa->id], $this->enEmpresa(fn () => $correcta->sedes()->pluck('sedes.id')->all()));

        // Persona: si la correcta no tenía identificación, se queda con la que capturó la caseta
        $laura = $this->persona('Laura Méndez Ríos');
        $altaLaura = $this->pendiente($this->persona('Laura Mendez Rios', ['tipo_identificacion' => 'ine', 'folio_identificacion' => 'MNRSLR85031423M700']));
        $this->actingAs($this->admin)->put("/personas/{$altaLaura->id}/unir", ['destino_id' => $laura->id])->assertRedirect();
        $this->assertSame('MNRSLR85031423M700', $this->fresco($laura)->folio_identificacion);
        $this->assertNull($this->fresco($altaLaura)->folio_identificacion);
    }

    // ---------------------------------------------- Aislamiento y alcance

    public function test_otra_empresa_y_otra_sede_no_ven_ni_verifican(): void
    {
        $alta = $this->pendiente($this->vehiculo('XTR-901-C'));
        $empresaAlta = $this->pendiente($this->proveedor('Plomería Express', $this->centro));

        $otra = $this->crearEmpresa('Hotel Ajeno');
        $this->crearSede($otra, 'AJE');
        $adminAjeno = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminAjeno)->putJson("/vehiculos/{$alta->id}/aceptar")->assertNotFound();
        $this->actingAs($adminAjeno)->putJson("/proveedores/{$empresaAlta->id}/rechazar", ['motivo_rechazo' => 'No existe'])->assertNotFound();

        // El jefe de Playa no verifica lo registrado en Centro; el de Centro sí
        $jefePlaya = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);
        $jefeCentro = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefePlaya)->putJson("/vehiculos/{$alta->id}/aceptar")->assertNotFound();
        $this->actingAs($jefePlaya)->putJson("/proveedores/{$empresaAlta->id}/aceptar")->assertNotFound();
        $this->actingAs($jefePlaya)->get('/')->assertDontSee('altas por verificar');
        $this->actingAs($jefeCentro)->get('/')->assertSee('2 altas por verificar');
        $this->actingAs($jefeCentro)->putJson("/vehiculos/{$alta->id}/aceptar")->assertOk();
        $this->assertSame('verificado', $this->fresco($alta)->verificacion);
    }

    // ------------------------------------------------------ Avisos y pantallas

    public function test_aviso_en_inicio_pildora_y_dialogo_solo_para_quien_verifica(): void
    {
        $this->pendiente($this->vehiculo('XTR-901-C'));
        $this->pendiente($this->vehiculo('KTR-222'));
        $this->pendiente($this->persona('Pedro Canul'));

        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('3 altas por verificar')
            ->assertSee('Vehículos')->assertSee('Personas')->assertDontSee('Empresas externas')
            ->assertSee(route('vehiculos.index', ['verificacion' => 'pendiente']), false);
        $this->actingAs($this->agente)->get('/')->assertOk()->assertDontSee('altas por verificar');
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($rh)->get('/')->assertOk()->assertDontSee('altas por verificar');

        $this->actingAs($this->admin)->get('/vehiculos?verificacion=pendiente')->assertOk()
            ->assertSee('Pendientes de verificar')->assertSee('data-encender', false)
            ->assertSee('id="dialogoVerificarAlta"', false)->assertSee('data-verificar-alta', false);
        // El agente ve la insignia, pero no verifica
        $this->actingAs($this->agente)->get('/vehiculos')->assertOk()->assertSee('Pendiente de verificar')
            ->assertDontSee('data-filtro-verificacion', false)->assertDontSee('dialogoVerificarAlta')->assertDontSee('data-verificar-alta', false);
    }

    public function test_las_pantallas_de_operacion_ofrecen_el_alta_y_las_sugerencias_al_agente(): void
    {
        $this->actingAs($this->agente)->get('/accesos')->assertOk()
            ->assertSee('id="dialogoRegistroRapidoPersona"', false)->assertSee('data-alta-sugerencias', false)
            ->assertSee('name="origen" value="accesos"', false)->assertSee('pendiente de verificar')
            // Las placas siguen leyéndose con el lector universal
            ->assertSee('id="ingreso_placas"', false);
        $this->actingAs($this->agente)->get('/pases-salida')->assertOk()
            ->assertSee('id="dialogoAltaRapidaProveedor"', false)->assertSee('name="origen" value="pases_salida"', false);
        // En los padrones el agente no tiene altas rápidas
        $this->actingAs($this->agente)->get('/personas')->assertOk()->assertDontSee('dialogoRegistroRapidoPersona');
    }

    public function test_correo_a_quien_verifica_y_se_apaga_en_configuracion(): void
    {
        Mail::fake();
        $this->configurarCorreo();

        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['origen' => 'accesos']))->assertCreated();
        Mail::assertSent(AltaPorVerificarRegistrada::class, fn ($m) => $m->titulo === 'XTR901C' && $m->tipo === 'vehículo'
            && $m->origen === 'Bitácora de accesos' && $m->hasTo($this->admin->email) && ! $m->hasTo($this->agente->email)
            && str_contains($m->enlace, 'verificacion=pendiente'));

        // Quien registra verificado no genera aviso
        Mail::fake();
        $this->actingAs($this->admin)->postJson('/vehiculos/rapido', $this->datosVehiculo(['placas' => 'KTR-222', 'origen' => 'accesos']))->assertCreated();
        Mail::assertNothingSent();

        // Configuración → Avisos por correo
        $this->actingAs($this->admin)->put('/configuracion/avisos', [])->assertSessionHas('ok');
        Mail::fake();
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datosVehiculo(['placas' => 'QWE-555', 'origen' => 'accesos']))->assertCreated();
        Mail::assertNothingSent();
    }
}
