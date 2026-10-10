<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Gafete;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class AccesosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private int $consecutivo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        // Estas pruebas son de la bitácora: el personal externo que va a RR. HH. pasa directo
        // (la espera del «Que pase» de RR. HH. se prueba en CandidatosFase1Test)
        $this->empresa->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => ['rh_autoriza_paso' => false]])])->save();
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function gafete(?Sede $sede = null, string $tipo = 'Visitante', ?Empresa $empresa = null): Gafete
    {
        $sede ??= $this->centro;
        $n = ++$this->consecutivo;

        return $this->enEmpresa(fn () => Gafete::create([
            'sede_id' => $sede->id, 'tipo_gafete_id' => TipoGafete::firstOrCreate(['nombre' => $tipo])->id,
            'nomenclatura' => 'HOT-'.$sede->codigo.'-'.mb_strtoupper(mb_substr($tipo, 0, 3)).'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'consecutivo' => $n,
        ]), $empresa);
    }

    private function colaborador(string $num, ?Sede $sede = null, ?Empresa $empresa = null, string $nombre = 'Roberto'): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create([
            'num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => 'Hernández', 'apellido_materno' => 'Cruz', 'sede_id' => ($sede ?? $this->centro)->id,
        ]), $empresa);
    }

    private function zona(string $nombre, ?Sede $sede = null, ?int $cupo = 40): ZonaEstacionamiento
    {
        return $this->enEmpresa(fn () => ZonaEstacionamiento::create([
            'sede_id' => ($sede ?? $this->centro)->id, 'nombre' => $nombre, 'tipo' => $cupo === null ? 'zona_descarga' : 'estacionamiento', 'cupo_total' => $cupo,
        ]));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function registrar(array $datos, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)->post('/accesos', $datos + ['sede_id' => $this->centro->id, '_dialogo' => 'ingreso']);
    }

    private function ultimo(): Acceso
    {
        return $this->enEmpresa(fn () => Acceso::with('acompanantes')->orderByDesc('id')->firstOrFail());
    }

    private function libre(Gafete $g): bool
    {
        return $this->enEmpresa(fn () => $g->fresh()->disponibleParaAsignar());
    }

    private function proveedorPendiente(array $extra = []): Acceso
    {
        $host = $this->enEmpresa(fn () => Colaborador::where('num_empleado', '2001')->first()) ?? $this->colaborador('2001', null, null, 'Carlos');
        $this->registrar(['tipo' => 'proveedor', 'nombre' => 'Jesús Balam Tun', 'empresa_procedencia' => 'Abarrotes del Caribe', 'host_colaborador_id' => $host->id] + $extra)
            ->assertSessionHasNoErrors();

        return $this->ultimo();
    }

    // ---------------------------------------------------------------- Pantalla

    public function test_pantalla_con_textos_de_segcat_pestanas_y_lector_universal(): void
    {
        $this->actingAs($this->admin)->get('/accesos')->assertOk()
            ->assertSee('Control de Accesos')->assertSee('Registro dinámico según el tipo de persona que ingresa.')
            ->assertSee('Nuevo Ingreso')->assertSee('Gente en Sitio (0)')->assertSee('Historial Finalizados')
            ->assertDontSee('Pendientes de Autorización')
            ->assertSee('No hay personal en sitio.')
            ->assertSee('Registro Inteligente de Ingreso')->assertSee('Autorizar Ingreso y Guardar Datos')->assertSee('Guardar y capturar siguiente')
            ->assertSee('PERSONAL EXTERNO / VISITA')->assertSee('CONTRATISTA (OBRA)')->assertSee('SERVICIO DE EMERGENCIA')
            ->assertSee('Gafete Asignado')->assertSee('Host — ¿Quién lo citó?')->assertSee('ID Custodiada')->assertSee('Enviar a')
            // Donde SEGCAT escaneaba (gafete, colaborador, host, a quién visita, placas) va el lector universal
            ->assertSee('data-lector', false)->assertSee('data-tipos="gafete"', false)->assertSee('data-tipos="colaborador"', false)
            ->assertSee('data-tipos="vehiculo"', false)->assertSee('id="ingreso_host"', false)->assertSee('id="ingreso_visita"', false)
            ->assertSee('Dar Salida')->assertSee('Cambiar Zona')->assertSee('Salida a Tour')->assertSee('Regreso de Tour')
            ->assertDontSee('onclick')->assertDontSee('jsQR.js"></script>', false);
    }

    public function test_el_menu_enlaza_a_la_bitacora(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('accesos.index'), false);
    }

    // --------------------------------------------------------------- Ingresos

    public function test_ingreso_de_colaborador_nunca_lleva_gafete(): void
    {
        $roberto = $this->colaborador('1005');
        $gafete = $this->gafete();

        $this->registrar(['tipo' => 'colaborador', 'colaborador_id' => $roberto->id, 'gafete_id' => $gafete->id])
            ->assertRedirect(route('accesos.index'))->assertSessionHas('ok', 'Ingreso de ROBERTO HERNÁNDEZ CRUZ registrado correctamente.');

        $a = $this->ultimo();
        $this->assertSame(['colaborador', 'en_sitio', 'entrada', 'ROBERTO HERNÁNDEZ CRUZ', $roberto->id, null, 'a_pie', $this->admin->id],
            [$a->tipo, $a->estado, $a->movimiento, $a->nombre, $a->colaborador_id, $a->gafete_id, $a->modo_arribo, $a->creado_por]);
        $this->assertTrue($this->libre($gafete));
        $this->assertTrue(Auditoria::where('evento', 'accesos.creado')->where('auditable_id', $a->id)->exists());

        $this->actingAs($this->admin)->get('/accesos')->assertOk()
            ->assertSee('Gente en Sitio (1)')->assertSee('ROBERTO HERNÁNDEZ CRUZ')->assertSee('EN SITIO')->assertSee('GAFETE: S/G')
            ->assertSee('Registrar Salida')->assertSee('id="acceso-'.$a->id.'"', false);
    }

    public function test_el_colaborador_es_obligatorio_y_debe_ser_de_la_sede(): void
    {
        $this->registrar(['tipo' => 'colaborador'])->assertSessionHasErrors(['colaborador_id' => 'Escanea o busca al colaborador que ingresa.']);

        $dePlaya = $this->colaborador('3001', $this->playa);
        $this->registrar(['tipo' => 'colaborador', 'colaborador_id' => $dePlaya->id])
            ->assertSessionHasErrors(['colaborador_id' => 'El colaborador elegido no existe, está dado de baja o no pertenece a esta sede.']);

        // Corporativo (sin sede) sí entra en cualquier sede; dado de baja, no
        $corporativo = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '9', 'nombre' => 'Ana', 'apellido_paterno' => 'López']));
        $this->registrar(['tipo' => 'colaborador', 'colaborador_id' => $corporativo->id])->assertSessionHasNoErrors();
        $this->enEmpresa(fn () => $corporativo->forceFill(['activo' => false])->save());
        $this->registrar(['tipo' => 'colaborador', 'colaborador_id' => $corporativo->id])->assertSessionHasErrors('colaborador_id');

        // Los errores se ven dentro del diálogo, que se vuelve a abrir solo
        $this->actingAs($this->admin)->post('/accesos', ['tipo' => 'colaborador', 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso']);
        $this->actingAs($this->admin)->withSession(['_old_input' => ['_dialogo' => 'ingreso', 'tipo' => 'colaborador', 'sede_id' => $this->centro->id]])
            ->get('/accesos')->assertSee('data-abrir-al-cargar', false);
    }

    public function test_visitante_con_gafete_vehiculo_zona_y_acompanantes_cae_a_los_padrones(): void
    {
        $g1 = $this->gafete();
        $g2 = $this->gafete();
        $zona = $this->zona('Estacionamiento Huéspedes');
        $andrea = $this->colaborador('1003', null, null, 'Andrea');

        $this->registrar([
            'tipo' => 'visitante', 'nombre' => '  laura   méndez ríos ', 'identificacion' => 'pasaporte', 'gafete_id' => $g1->id,
            'motivo_visita' => 'colaborador', 'visita_colaborador_id' => $andrea->id,
            'modo_arribo' => 'auto', 'placas' => 'abc-123-a', 'tipo_vehiculo' => 'suv', 'marca' => 'nissan', 'color' => 'blanco', 'zona_estacionamiento_id' => $zona->id,
            'num_acompanantes' => 3, 'acompanantes' => [
                ['nombre' => 'pedro ruiz', 'identificacion' => 'ine', 'gafete_id' => $g2->id],
                ['nombre' => '', 'identificacion' => '', 'gafete_id' => ''],
                ['nombre' => 'niña sin id'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok', 'Ingreso de LAURA MÉNDEZ RÍOS registrado correctamente.');

        $a = $this->ultimo();
        $this->assertSame(['LAURA MÉNDEZ RÍOS', 'pasaporte', $g1->nomenclatura, 'ABC123A', $zona->id, 'auto', 3, 'colaborador', 'ANDREA HERNÁNDEZ CRUZ'],
            [$a->nombre, $a->identificacion, $a->gafete_texto, $a->placas, $a->zona_estacionamiento_id, $a->modo_arribo, $a->num_acompanantes, $a->motivo_visita, $a->persona_visita]);
        // Solo las filas con algún dato
        $this->assertSame(['PEDRO RUIZ', 'NIÑA SIN ID'], $a->acompanantes->pluck('nombre')->all());
        $this->assertSame($g2->nomenclatura, $a->acompanantes[0]->gafete_texto);

        // Persona y vehículo nuevos en sus padrones
        $persona = $this->enEmpresa(fn () => Persona::find($a->persona_id));
        $this->assertSame(['Laura Méndez Ríos', 'visitante'], [$persona->nombre_completo, $persona->tipo]);
        $vehiculo = $this->enEmpresa(fn () => Vehiculo::find($a->vehiculo_id));
        $this->assertSame(['ABC123A', 'propio_visitante', 'suv', 'NISSAN', 'BLANCO'], [$vehiculo->placas, $vehiculo->propiedad, $vehiculo->tipo, $vehiculo->marca, $vehiculo->color]);
        $this->assertTrue(Auditoria::where('evento', 'visitantes.creado')->exists());
        $this->assertTrue(Auditoria::where('evento', 'vehiculos.creado')->exists());

        // Gafetes EN SITIO y ocupación de la zona en vivo
        $this->assertFalse($this->libre($g1));
        $this->assertFalse($this->libre($g2));
        $this->assertSame([$zona->id => 1], $this->enEmpresa(fn () => app(OcupacionEstacionamientos::class)->ocupados([$zona->id])));
        $this->actingAs($this->admin)->get('/estacionamientos')->assertSee('1 / 40');

        $this->actingAs($this->admin)->get('/accesos')->assertSee('Visitando a:')->assertSee('ANDREA HERNÁNDEZ CRUZ')
            ->assertSee('Enviado a:')->assertSee('Estacionamiento Huéspedes')->assertSee('Gafete '.$g2->nomenclatura)->assertSee('ID en Caseta:')->assertSee('Pasaporte');

        // El mismo vehículo después: se usa el del padrón y solo se completa lo que faltaba
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Otra Persona', 'modo_arribo' => 'auto', 'placas' => 'ABC 123 A', 'marca' => 'TOYOTA', 'modelo' => 'VERSA']);
        $vehiculo = $this->enEmpresa(fn () => $vehiculo->fresh());
        $this->assertSame(['NISSAN', 'VERSA'], [$vehiculo->marca, $vehiculo->modelo]);
        $this->assertSame(1, $this->enEmpresa(fn () => Vehiculo::count()));
    }

    public function test_reglas_del_gafete_en_sitio(): void
    {
        $g1 = $this->gafete();
        $dePlaya = $this->gafete($this->playa);
        $baja = $this->gafete();
        $this->enEmpresa(fn () => $baja->forceFill(['activo' => false])->save());

        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Primera Visita', 'gafete_id' => $g1->id])->assertSessionHasNoErrors();
        $primera = $this->ultimo();

        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Segunda Visita', 'gafete_id' => $g1->id])
            ->assertSessionHasErrors(['gafete_id' => "El gafete {$g1->nomenclatura} ya está en uso (EN SITIO). Pide que lo devuelvan o elige otro."]);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Segunda Visita', 'gafete_id' => $dePlaya->id])
            ->assertSessionHasErrors(['gafete_id' => 'El gafete no existe o no es de esta sede.']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Segunda Visita', 'gafete_id' => $baja->id])
            ->assertSessionHasErrors(['gafete_id' => "El gafete {$baja->nomenclatura} está dado de baja."]);

        $g2 = $this->gafete();
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Tercera', 'gafete_id' => $g2->id, 'acompanantes' => [['nombre' => 'X', 'gafete_id' => $g2->id]]])
            ->assertSessionHasErrors(['acompanantes.0.gafete_id' => 'El gafete del acompañante 1 ya está elegido para otra persona de este registro.']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Tercera', 'acompanantes' => [['nombre' => 'X', 'gafete_id' => $g1->id]]])
            ->assertSessionHasErrors(['acompanantes.0.gafete_id' => "Acompañante 1: El gafete {$g1->nomenclatura} ya está en uso (EN SITIO). Pide que lo devuelvan o elige otro."]);

        // Al dar salida, el gafete queda libre otra vez
        $this->actingAs($this->admin)->patch("/accesos/{$primera->id}/salida")->assertSessionHas('ok');
        $this->assertTrue($this->libre($g1));
        // Un proveedor pendiente también tiene su gafete reservado
        $this->proveedorPendiente(['gafete_id' => $g1->id]);
        $this->assertFalse($this->libre($g1));
    }

    public function test_proveedor_nace_pendiente_necesita_host_y_se_autoriza(): void
    {
        $this->registrar(['tipo' => 'proveedor', 'nombre' => 'Jesús Balam'])
            ->assertSessionHasErrors(['host_colaborador_id' => 'Indica quién citó al proveedor/contratista (Host).']);

        $a = $this->proveedorPendiente(['modo_arribo' => 'auto', 'placas' => 'UPS-03-CL', 'marca' => 'Isuzu', 'tipo_visita' => 'ejecucion', 'area_trabajo' => 'cuarto de máquinas', 'actividad' => 'Mantenimiento']);
        $this->assertSame(['pendiente', 'ABARROTES DEL CARIBE', 'ejecucion', 'CUARTO DE MÁQUINAS'], [$a->estado, $a->empresa_procedencia, $a->tipo_visita, $a->area_trabajo]);
        $proveedor = $this->enEmpresa(fn () => Proveedor::find($a->proveedor_id));
        $this->assertSame(['Abarrotes del Caribe', 'proveedor', false, [$this->centro->id]], [$proveedor->nombre, $proveedor->categoria, $proveedor->todas_las_sedes, $this->enEmpresa(fn () => $proveedor->sedes()->pluck('sedes.id')->all())]);
        $vehiculo = $this->enEmpresa(fn () => Vehiculo::find($a->vehiculo_id));
        $this->assertSame(['empresa_proveedor', $proveedor->id], [$vehiculo->propiedad, $vehiculo->proveedor_id]);
        $persona = $this->enEmpresa(fn () => Persona::find($a->persona_id));
        $this->assertSame(['proveedor', $proveedor->id], [$persona->tipo, $persona->proveedor_id]);

        $this->actingAs($this->admin)->get('/accesos?pestana=pendientes')->assertOk()
            ->assertSee('Pendientes de Autorización (1)')->assertSee('PENDIENTE')->assertSee('Host (citó):')->assertSee('Carlos Hernández Cruz')
            ->assertSee('Confirmar Autorización')->assertSee('Ejecución de Trabajo');

        // Pendiente → salida no se permite
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/salida")
            ->assertSessionHas('error', 'Este acceso sigue pendiente de autorización: confírmalo antes de registrar su salida.');

        // Pendiente → En sitio
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/autorizar")
            ->assertRedirect(route('accesos.index', ['pestana' => 'pendientes']))->assertSessionHas('ok', 'Acceso autorizado — JESÚS BALAM TUN ya puede ingresar.');
        $a->refresh();
        $this->assertSame(['en_sitio', $this->admin->id], [$a->estado, $a->autorizado_por]);
        $this->assertNotNull($a->autorizado_at);
        $this->assertTrue(Auditoria::where('evento', 'accesos.autorizado')->exists());

        // Autorizar dos veces no se permite
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/autorizar")
            ->assertSessionHas('error', 'Este acceso ya no está pendiente de autorización (alguien más lo atendió). Revisa la lista.');

        // La empresa ya existe: no se duplica (y la misma persona tampoco); contratista crea la suya como contratista
        $this->proveedorPendiente(['persona_decision' => 'misma']);
        $this->assertSame(1, $this->enEmpresa(fn () => Persona::where('nombre_completo', 'Jesús Balam Tun')->count()));
        $this->assertSame(1, $this->enEmpresa(fn () => Proveedor::where('nombre', 'Abarrotes del Caribe')->count()));
        $host = $this->enEmpresa(fn () => Colaborador::where('num_empleado', '2001')->first());
        $this->registrar(['tipo' => 'contratista', 'nombre' => 'José Ek', 'empresa_procedencia' => 'Constructora Maya', 'host_colaborador_id' => $host->id])->assertSessionHasNoErrors();
        $this->assertSame('contratista', $this->enEmpresa(fn () => Proveedor::where('nombre', 'Constructora Maya')->value('categoria')));
    }

    public function test_una_empresa_vetada_no_puede_ingresar(): void
    {
        $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Fletes Rápidos', 'categoria' => 'transportadora', 'activo' => false]));
        $host = $this->colaborador('2001');

        $this->registrar(['tipo' => 'proveedor', 'nombre' => 'Chofer', 'empresa_procedencia' => 'fletes   rápidos', 'host_colaborador_id' => $host->id])
            ->assertSessionHasErrors(['empresa_procedencia' => 'La empresa «Fletes Rápidos» está dada de baja (vetada) en Proveedores: no puede ingresar.']);
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count()));
    }

    public function test_huesped_reserva_estancia_conductor_y_agencia(): void
    {
        $gafete = $this->gafete();

        $this->registrar(['tipo' => 'huesped', 'nombre' => 'Leticia Vázquez', 'tiene_reserva' => '1', 'numero_reserva' => 'abc-1', 'tipo_pase' => 'daypass',
            'habitacion' => '204', 'empresa_procedencia' => 'Viajes Turquesa', 'gafete_id' => $gafete->id, 'conductor' => 'Taxista'])->assertSessionHasNoErrors();
        $a = $this->ultimo();
        // Con reserva el pase es Estancia; sin vehículo no hay conductor; el huésped nunca lleva gafete
        $this->assertSame([true, 'ABC-1', 'estancia', '204', null, null, 'VIAJES TURQUESA'], [$a->tiene_reserva, $a->numero_reserva, $a->tipo_pase, $a->habitacion, $a->conductor, $a->gafete_id, $a->empresa_procedencia]);
        $this->assertSame('agencia_viajes', $this->enEmpresa(fn () => Proveedor::find($a->proveedor_id)->categoria));
        $this->assertNull($a->persona_id);

        $this->registrar(['tipo' => 'huesped', 'nombre' => 'Pamela Alcocer', 'tiene_reserva' => '0', 'tipo_pase' => 'nightpass', 'numero_reserva' => 'X',
            'modo_arribo' => 'auto', 'placas' => 'BET123', 'conductor' => 'juan uber'])->assertSessionHasNoErrors();
        $b = $this->ultimo();
        $this->assertSame([false, null, 'nightpass', 'JUAN UBER'], [$b->tiene_reserva, $b->numero_reserva, $b->tipo_pase, $b->conductor]);
        $this->assertSame('taxi_app', $this->enEmpresa(fn () => Vehiculo::find($b->vehiculo_id)->propiedad));

        $this->registrar(['tipo' => 'huesped', 'nombre' => 'Ayleen Pérez', 'modo_arribo' => 'auto', 'placas' => 'W2M123'])->assertSessionHasNoErrors();
        $this->assertSame('propio_huesped', $this->enEmpresa(fn () => Vehiculo::find($this->ultimo()->vehiculo_id)->propiedad));

        $this->registrar(['tipo' => 'huesped'])->assertSessionHasErrors(['nombre' => 'Escribe el nombre del huésped.']);

        $this->actingAs($this->admin)->get('/accesos')->assertSee('Hab. 204')->assertSee('Reserva:')->assertSee('Sí — #ABC-1')->assertSee('Pase:')->assertSee('Nightpass')
            ->assertSee('Salida a Tour')->assertSee('Salida Final');
    }

    public function test_emergencia_es_de_friccion_minima(): void
    {
        $this->registrar(['tipo' => 'emergencia'])->assertSessionHasNoErrors();
        $a = $this->ultimo();
        $this->assertSame(['UNIDAD DE EMERGENCIA', 'en_sitio', null, null], [$a->nombre, $a->estado, $a->modo_arribo, $a->vehiculo_id]);

        $this->registrar(['tipo' => 'emergencia', 'nombre' => 'Cruz Roja Unidad 12', 'tipo_emergencia' => 'ambulancia', 'placas' => 'CR-012', 'tipo_vehiculo' => 'otro', 'observaciones' => "Paciente en lobby.\nPiso 2."])
            ->assertSessionHasNoErrors();
        $b = $this->ultimo();
        $this->assertSame(['CRUZ ROJA UNIDAD 12', 'ambulancia', 'auto', 'CR012', "Paciente en lobby.\nPiso 2."], [$b->nombre, $b->tipo_emergencia, $b->modo_arribo, $b->placas, $b->observaciones]);
        $this->assertSame('propio_visitante', $this->enEmpresa(fn () => Vehiculo::find($b->vehiculo_id)->propiedad));
    }

    public function test_misma_persona_del_padron_pregunta_antes_de_duplicar(): void
    {
        $existente = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Laura Méndez Ríos']));

        $this->registrar(['tipo' => 'visitante', 'nombre' => 'LAURA MÉNDEZ RÍOS'])
            ->assertSessionHasErrors(['persona_repetida' => 'Ya existe una persona registrada con este nombre: «Laura Méndez Ríos» (Visitante). ¿Es la misma?']);
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count()));

        // El diálogo se vuelve a abrir con la pregunta y sus dos respuestas
        $this->actingAs($this->admin)->from('/accesos')->followingRedirects()
            ->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Laura Méndez Ríos', 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso'])
            ->assertSee('data-abrir-al-cargar', false)->assertSee('Sí, es la misma')->assertSee('No, es alguien distinto')
            ->assertSee('name="persona_decision" value="misma"', false);

        // "Sí, es la misma": se usa la persona del padrón
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'LAURA MÉNDEZ RÍOS', 'persona_decision' => 'misma'])->assertSessionHasNoErrors();
        $this->assertSame($existente->id, $this->ultimo()->persona_id);
        // "No, es alguien distinto": se crea otra
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'LAURA MÉNDEZ RÍOS', 'persona_decision' => 'distinta'])->assertSessionHasNoErrors();
        $this->assertNotSame($existente->id, $this->ultimo()->persona_id);
        $this->assertSame(2, $this->enEmpresa(fn () => Persona::where('nombre_completo', 'Laura Méndez Ríos')->count()));
        // Elegida de la lista: no pregunta
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'cualquier texto', 'persona_id' => $existente->id])->assertSessionHasNoErrors();
        $this->assertSame(['LAURA MÉNDEZ RÍOS', $existente->id], [$this->ultimo()->nombre, $this->ultimo()->persona_id]);
    }

    public function test_validaciones_del_formulario(): void
    {
        $zonaPlaya = $this->zona('Sótano 1A', $this->playa, 8);

        $this->registrar(['tipo' => 'nadie'])->assertSessionHasErrors(['tipo' => 'Elige el tipo de persona de la lista.']);
        $this->registrar(['tipo' => 'visitante'])->assertSessionHasErrors(['nombre' => 'El nombre de la persona es obligatorio.']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'modo_arribo' => 'auto'])
            ->assertSessionHasErrors(['placas' => 'Escribe las placas del vehículo (o elige «A Pie»).']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'modo_arribo' => 'auto', 'placas' => '#'])
            ->assertSessionHasErrors(['placas' => 'Las placas solo llevan letras y números (de 2 a 20).']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'modo_arribo' => 'auto', 'placas' => 'AB12', 'zona_estacionamiento_id' => $zonaPlaya->id])
            ->assertSessionHasErrors(['zona_estacionamiento_id' => 'Elige una zona activa de esta sede (o déjalo «Sin asignar»).']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'motivo_visita' => 'colaborador'])
            ->assertSessionHasErrors(['visita_colaborador_id' => 'Indica a quién visita (busca o escanea al colaborador).']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'num_acompanantes' => 16])
            ->assertSessionHasErrors(['num_acompanantes' => 'Máximo 15 acompañantes por registro.']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'sede_id' => 999999])
            ->assertSessionHasErrors(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
        // A pie: las placas y la zona se ignoran
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'X', 'modo_arribo' => 'a_pie', 'placas' => 'AB12', 'zona_estacionamiento_id' => $zonaPlaya->id])->assertSessionHasNoErrors();
        $this->assertSame([null, null, null], [$this->ultimo()->placas, $this->ultimo()->vehiculo_id, $this->ultimo()->zona_estacionamiento_id]);
    }

    public function test_guardar_y_capturar_siguiente_reabre_el_formulario(): void
    {
        $this->registrar(['tipo' => 'emergencia', 'sede_id' => $this->playa->id, 'siguiente' => '1'])
            ->assertSessionHas('siguiente', fn ($s) => $s['sede_id'] === $this->playa->id && $s['tipo'] === 'emergencia');

        $this->actingAs($this->admin)->withSession(['siguiente' => ['sede_id' => $this->playa->id, 'tipo' => 'emergencia', 'mensaje' => 'Ingreso de UNIDAD DE EMERGENCIA registrado correctamente.']])
            ->get('/accesos')->assertSee('data-abrir-al-cargar', false)->assertSee('Captura el siguiente.')
            ->assertSee('<option value="'.$this->playa->id.'" selected', false)->assertSee('value="emergencia" checked', false);
    }

    // ------------------------------------------------------------ Movimientos

    public function test_salida_libera_gafetes_acompanantes_y_zona(): void
    {
        $g1 = $this->gafete();
        $g2 = $this->gafete();
        $zona = $this->zona('Huéspedes');
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Laura', 'gafete_id' => $g1->id, 'modo_arribo' => 'auto', 'placas' => 'AAA111',
            'zona_estacionamiento_id' => $zona->id, 'acompanantes' => [['nombre' => 'Pedro', 'gafete_id' => $g2->id]]]);
        $a = $this->ultimo();

        $this->actingAs($this->admin)->get('/accesos')->assertSee('Pide de vuelta: gafete '.$g1->nomenclatura.', gafete '.$g2->nomenclatura.' y la identificación (INE / IFE).');

        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/salida")->assertRedirect(route('accesos.index'))->assertSessionHas('ok', 'Salida de LAURA registrada correctamente.');
        $a->refresh();
        $this->assertSame(['finalizado', $this->admin->id], [$a->estado, $a->salida_por]);
        $this->assertNotNull($this->enEmpresa(fn () => AcompananteAcceso::where('acceso_id', $a->id)->value('salida_at')));
        $this->assertTrue($this->libre($g1) && $this->libre($g2));
        $this->assertSame([], $this->enEmpresa(fn () => app(OcupacionEstacionamientos::class)->ocupados([$zona->id])));
        $this->assertTrue(Auditoria::where('evento', 'accesos.salida')->exists());

        // Doble clic / botón atrás: no se "reabre y vuelve a cerrar"
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/salida")->assertSessionHas('error', 'A esta persona ya se le dio salida. Revisa el historial.');

        $this->actingAs($this->admin)->get('/accesos?pestana=historial')->assertSee('LAURA')->assertSee('ID Devuelta por: '.$this->admin->name)->assertSee('Gafete: '.$g1->nomenclatura);
    }

    public function test_salida_a_tour_y_regreso_del_huesped(): void
    {
        $this->registrar(['tipo' => 'huesped', 'nombre' => 'Ayleen Pérez', 'habitacion' => '310', 'acompanantes' => [['nombre' => 'Hija']]]);
        $a = $this->ultimo();

        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal", ['placas' => 'w2m-123', 'marca' => 'kia', 'conductor' => 'guía tours'])
            ->assertSessionHas('ok', 'Salida a tour de AYLEEN PÉREZ registrada.');
        $tour = $this->ultimo();
        $this->assertSame(['salida_temporal', 'en_sitio', $a->id, 'W2M123', 'GUÍA TOURS', '310'], [$tour->movimiento, $tour->estado, $tour->acceso_origen_id, $tour->placas, $tour->conductor, $tour->habitacion]);

        // Sigue contando como una sola persona en sitio, marcada FUERA EN TOUR
        $this->actingAs($this->admin)->get('/accesos')->assertSee('Gente en Sitio (1)')->assertSee('FUERA EN TOUR')->assertSee('Registrar Regreso')->assertDontSee('Salida Final');

        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal")->assertSessionHas('error', 'Esta persona ya está fuera: registra primero su regreso.');

        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/regreso", ['placas' => 'TX-1', 'conductor' => ''])->assertSessionHas('ok', 'Regreso de tour de AYLEEN PÉREZ registrado.');
        $tour->refresh();
        $regreso = $this->ultimo();
        $this->assertSame(['finalizado', 'regreso', 'finalizado', 'TX1'], [$tour->estado, $regreso->movimiento, $regreso->estado, $regreso->placas]);
        $this->assertNotNull($tour->salida_at);
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/regreso")->assertSessionHas('error', 'Esta persona no tiene una salida temporal abierta (ya regresó).');

        $this->actingAs($this->admin)->get('/accesos?pestana=historial')->assertSee('SALIDA A TOUR')->assertSee('REGRESO DE TOUR');

        // Salida final estando fuera en tour: el tour también se cierra
        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal");
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/salida")->assertSessionHas('ok');
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::where('estado', 'en_sitio')->count()));

        // Personal externo no tiene salida temporal; un registro finalizado tampoco
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Visita']);
        $this->actingAs($this->admin)->post("/accesos/{$this->ultimo()->id}/salida-temporal")
            ->assertSessionHas('error', 'La salida temporal solo aplica a huéspedes, proveedores y contratistas.');
        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal")->assertSessionHas('error', 'Solo puede salir temporalmente alguien que está en sitio.');
        $this->assertTrue(Auditoria::where('evento', 'accesos.salida_temporal')->exists() && Auditoria::where('evento', 'accesos.regreso')->exists());
    }

    public function test_salida_temporal_del_proveedor_y_validacion_dentro_del_dialogo(): void
    {
        $a = $this->proveedorPendiente();
        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal")->assertSessionHas('error', 'Solo puede salir temporalmente alguien que está en sitio.');
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/autorizar");

        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal", ['placas' => '#', '_dialogo' => 'dialogoSalidaTemporalAcceso', '_acceso' => $a->id, '_nombre' => $a->nombre])
            ->assertSessionHasErrors(['placas' => 'Las placas solo llevan letras y números (de 2 a 20).']);
        $this->actingAs($this->admin)->withSession(['_old_input' => ['_dialogo' => 'dialogoSalidaTemporalAcceso', '_acceso' => (string) $a->id, '_nombre' => $a->nombre]])
            ->get('/accesos')->assertSee('action="'.route('accesos.salida-temporal', $a->id).'"', false);

        $this->actingAs($this->admin)->post("/accesos/{$a->id}/salida-temporal")->assertSessionHas('ok', 'Salida temporal de JESÚS BALAM TUN registrada.');
        $this->actingAs($this->admin)->get('/accesos')->assertSee('FUERA TEMPORAL');
    }

    public function test_acompanantes_salida_individual_y_temporal(): void
    {
        $g = $this->gafete($this->centro, 'Contratista');
        $a = $this->proveedorPendiente(['acompanantes' => [['nombre' => 'Ayudante Uno', 'gafete_id' => $g->id], ['nombre' => 'Ayudante Dos']]]);
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/autorizar");
        [$uno, $dos] = $this->enEmpresa(fn () => AcompananteAcceso::where('acceso_id', $a->id)->orderBy('id')->get()->all());

        // Sale un rato: su gafete sigue reservado; no puede salir definitivamente sin regresar
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/salida-temporal")->assertSessionHas('ok', 'Salida temporal de AYUDANTE UNO registrada — su gafete sigue reservado.');
        $this->assertFalse($this->libre($g));
        $this->actingAs($this->admin)->get('/accesos')->assertSee('Regresó');
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/salida")
            ->assertSessionHas('error', 'Este acompañante tiene una salida temporal abierta: registra primero su regreso.');
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/salida-temporal")->assertSessionHas('error', 'Este acompañante ya está fuera (o ya salió).');
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/regreso")->assertSessionHas('ok', 'Regreso de AYUDANTE UNO registrado.');
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/regreso")->assertSessionHas('error', 'Este acompañante no tiene una salida temporal abierta.');

        // Salida individual: su gafete queda libre, el titular sigue en sitio
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/salida")->assertSessionHas('ok', 'Salida de AYUDANTE UNO registrada — su gafete ya quedó libre.');
        $this->assertTrue($this->libre($g));
        $this->assertSame('en_sitio', $a->fresh()->estado);
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$uno->id}/salida")->assertSessionHas('error', 'A este acompañante ya se le dio salida.');
        $this->actingAs($this->admin)->get('/accesos')->assertDontSee('AYUDANTE UNO')->assertSee('AYUDANTE DOS');

        // La salida temporal de acompañantes solo es de proveedor/contratista
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'V', 'acompanantes' => [['nombre' => 'Hermano']]]);
        $hermano = $this->ultimo()->acompanantes->first();
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$hermano->id}/salida-temporal")
            ->assertSessionHas('error', 'La salida temporal de acompañantes solo aplica a proveedores y contratistas.');

        // Titular finalizado: sus acompañantes ya no se mueven
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/salida");
        $this->actingAs($this->admin)->patch("/accesos/acompanantes/{$dos->id}/salida")->assertSessionHas('error', 'El titular de este acompañante ya no está en sitio.');
    }

    public function test_cambiar_zona_solo_de_la_sede_y_con_vehiculo(): void
    {
        $z1 = $this->zona('Huéspedes');
        $z2 = $this->zona('Andén', null, null);
        $dePlaya = $this->zona('Lobby', $this->playa, null);
        $inactiva = $this->zona('Obra');
        $this->enEmpresa(fn () => $inactiva->forceFill(['activo' => false])->save());
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Con auto', 'modo_arribo' => 'auto', 'placas' => 'AAA111', 'zona_estacionamiento_id' => $z1->id]);
        $a = $this->ultimo();

        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/zona", ['zona_estacionamiento_id' => $z2->id])->assertSessionHas('ok', 'Zona de estacionamiento actualizada.');
        $this->assertSame($z2->id, $a->fresh()->zona_estacionamiento_id);
        foreach ([$dePlaya, $inactiva] as $mala) {
            $this->actingAs($this->admin)->patch("/accesos/{$a->id}/zona", ['zona_estacionamiento_id' => $mala->id])
                ->assertSessionHasErrors(['zona_estacionamiento_id' => 'Elige una zona activa de la sede del acceso (o «Sin asignar» para liberar).']);
        }
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/zona", ['zona_estacionamiento_id' => ''])->assertSessionHas('ok');
        $this->assertNull($a->fresh()->zona_estacionamiento_id);
        $this->assertTrue(Auditoria::where('evento', 'accesos.zona_cambiada')->exists());

        $this->registrar(['tipo' => 'visitante', 'nombre' => 'A pie']);
        $this->actingAs($this->admin)->patch("/accesos/{$this->ultimo()->id}/zona", ['zona_estacionamiento_id' => $z1->id])->assertSessionHas('error', 'Este acceso no trae vehículo.');
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/salida");
        $this->actingAs($this->admin)->patch("/accesos/{$a->id}/zona", ['zona_estacionamiento_id' => $z1->id])->assertSessionHas('error', 'Solo se cambia la zona de un vehículo que sigue en sitio.');
    }

    // ----------------------------------------------------- Aislamiento y alcance

    public function test_otra_empresa_no_ve_ni_toca_los_accesos(): void
    {
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Visita del Ébano', 'modo_arribo' => 'auto', 'placas' => 'EBA111']);
        $a = $this->ultimo();

        $otra = $this->crearEmpresa('Hotel Coral');
        $sedeOtra = $this->crearSede($otra, 'COR');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $gafeteAjeno = $this->gafete($this->centro);

        $this->flushSession();
        $this->actingAs($adminOtra)->get('/accesos')->assertOk()->assertDontSee('VISITA DEL ÉBANO');
        $this->actingAs($adminOtra)->patch("/accesos/{$a->id}/salida")->assertNotFound();
        $this->actingAs($adminOtra)->patch("/accesos/{$a->id}/zona")->assertNotFound();
        $this->actingAs($adminOtra)->post("/accesos/{$a->id}/salida-temporal")->assertNotFound();
        $this->actingAs($adminOtra)->getJson('/accesos/en-sitio?q=EBA')->assertOk()->assertExactJson(['resultados' => []]);
        $this->assertStringNotContainsString('VISITA DEL ÉBANO', $this->actingAs($adminOtra)->get('/accesos/exportar')->assertOk()->streamedContent());
        $this->actingAs($adminOtra)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'X', 'sede_id' => $sedeOtra->id, 'gafete_id' => $gafeteAjeno->id])
            ->assertSessionHasErrors(['gafete_id' => 'El gafete no existe o no es de esta sede.']);
        $this->actingAs($adminOtra)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'X', 'sede_id' => $this->centro->id])->assertSessionHasErrors('sede_id');
        $this->assertSame('en_sitio', $a->fresh()->estado);
    }

    public function test_alcance_de_sede_solo_ve_y_registra_en_su_sede(): void
    {
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Visita Centro']);
        $centro = $this->ultimo();
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Visita Playa', 'sede_id' => $this->playa->id]);
        $playa = $this->ultimo();

        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->flushSession();
        $this->actingAs($jefe)->get('/accesos')->assertOk()->assertSee('VISITA CENTRO')->assertDontSee('VISITA PLAYA')
            ->assertSee('Gente en Sitio (1)')
            // Su única sede queda fija en el formulario
            ->assertSee('name="sede_id" value="'.$this->centro->id.'"', false)->assertSee('Sede: <strong>Sede CEN</strong>', false);
        $this->actingAs($jefe)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'X', 'sede_id' => $this->playa->id])->assertSessionHasErrors('sede_id');
        $this->actingAs($jefe)->patch("/accesos/{$playa->id}/salida")->assertNotFound();
        $this->actingAs($jefe)->patch("/accesos/{$centro->id}/salida")->assertSessionHas('ok');
        $this->actingAs($jefe)->getJson('/accesos/gafetes?sede='.$this->playa->id)->assertNotFound();
        $csv = $this->actingAs($jefe)->get('/accesos/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString('VISITA CENTRO', $csv);
        $this->assertStringNotContainsString('VISITA PLAYA', $csv);
    }

    public function test_el_agente_registra_y_da_salida_pero_no_autoriza_ni_exporta(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $a = $this->proveedorPendiente();

        $this->actingAs($agente)->get('/accesos?pestana=pendientes')->assertOk()->assertSee('JESÚS BALAM TUN')
            ->assertDontSee('Confirmar Autorización')->assertSee('Espera a que el Host autorice');
        $this->actingAs($agente)->patch("/accesos/{$a->id}/autorizar")->assertForbidden();
        $this->actingAs($agente)->get('/accesos/exportar')->assertForbidden();
        $this->actingAs($agente)->get('/accesos?pestana=historial')->assertDontSee('Exportar a Excel');

        // Registra en caseta (Operación = ver, crear y editar) y da salida
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Visita del Agente'], $agente)->assertSessionHasNoErrors();
        $propia = $this->ultimo();
        $this->assertSame($agente->id, $propia->creado_por);
        $this->actingAs($agente)->patch("/accesos/{$propia->id}/salida")->assertSessionHas('ok');
        // Las búsquedas de la caseta no dependen de administrar los padrones
        $this->actingAs($agente)->getJson('/accesos/buscar?que=persona&q=jes')->assertOk()->assertJsonPath('resultados.0.nombre_completo', 'Jesús Balam Tun');
    }

    public function test_un_usuario_sin_permiso_no_entra(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($rh)->get('/accesos')->assertForbidden();
        $this->actingAs($rh)->post('/accesos', ['tipo' => 'emergencia', 'sede_id' => $this->centro->id])->assertForbidden();
        $this->actingAs($rh)->getJson('/accesos/en-sitio?q=xx')->assertForbidden();
    }

    public function test_super_administrador_elige_la_empresa_de_trabajo(): void
    {
        $super = $this->crearSuperadmin();
        $this->actingAs($super)->get('/accesos')->assertOk()->assertSee('Elige arriba la');
        $this->actingAs($super)->post('/accesos', ['tipo' => 'emergencia', 'sede_id' => $this->centro->id])->assertNotFound();

        $this->actingAs($super)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])
            ->post('/accesos', ['tipo' => 'emergencia', 'sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->enEmpresa(fn () => Acceso::count()));
    }

    // --------------------------------------------------- Búsquedas y lector

    public function test_lector_universal_encuentra_gafete_y_colaborador(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $g = $this->gafete();
        $roberto = $this->colaborador('1005');

        $this->actingAs($agente)->getJson('/lector/resolver?entrada='.$g->nomenclatura.'&tipos=gafete')->assertOk()->assertJsonPath('resultados.0.id', $g->id);
        $this->actingAs($agente)->getJson('/lector/resolver?entrada=1005&tipos=colaborador')->assertOk()->assertJsonPath('resultados.0.id', $roberto->id);
        $this->actingAs($agente)->getJson('/lector/resolver?entrada=/e/'.$g->codigo_qr.'&tipos=gafete')->assertOk()->assertJsonPath('resultados.0.titulo', $g->nomenclatura);
    }

    public function test_gafetes_libres_de_la_sede_para_elegir(): void
    {
        $libre = $this->gafete();
        $ocupado = $this->gafete();
        $this->gafete($this->playa);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Con gafete', 'gafete_id' => $ocupado->id]);

        $this->actingAs($this->admin)->getJson('/accesos/gafetes?sede='.$this->centro->id)->assertOk()
            ->assertJsonCount(1, 'resultados')->assertJsonPath('resultados.0.id', $libre->id)->assertJsonPath('resultados.0.detalle', 'Visitante');
    }

    public function test_buscar_en_sitio_para_dar_salida_por_placas_nombre_o_gafete(): void
    {
        $g = $this->gafete();
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega', 'modo_arribo' => 'auto', 'placas' => 'QRR-44-10',
            'acompanantes' => [['nombre' => 'Asistente', 'gafete_id' => $g->id]]]);
        $a = $this->ultimo();

        $this->actingAs($this->admin)->getJson('/accesos/en-sitio?q=qrr 44')->assertOk()->assertJsonPath('resultados.0.id', $a->id)
            ->assertJsonPath('resultados.0.acompanantes.0.gafete', $g->nomenclatura)
            ->assertJsonPath('resultados.0.url_salida', route('accesos.salida', $a->id))
            ->assertJsonPath('resultados.0.salida_temporal', false);
        $this->actingAs($this->admin)->getJson('/accesos/en-sitio?q=ortega')->assertJsonCount(1, 'resultados');
        // El gafete que devuelven (leído con el lector) dice de quién era, aunque sea de un acompañante
        $this->actingAs($this->admin)->getJson('/accesos/en-sitio?gafete='.$g->id)->assertJsonPath('resultados.0.nombre', 'RICARDO ORTEGA');
        $this->actingAs($this->admin)->getJson('/accesos/en-sitio?q=x')->assertExactJson(['resultados' => []]);
    }

    public function test_sugerencias_al_escribir_usan_los_padrones(): void
    {
        $this->colaborador('1005');
        $this->colaborador('3001', $this->playa, null, 'Pablo');
        $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'ABC123A', 'tipo' => 'suv', 'marca' => 'MAZDA', 'modelo' => 'CX-5', 'color' => 'ROJO', 'propiedad' => 'propio_huesped']));
        $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Viajes Turquesa', 'categoria' => 'agencia_viajes']));

        $this->actingAs($this->admin)->getJson('/accesos/buscar?que=colaborador&q=hern&sede='.$this->centro->id)->assertOk()
            ->assertJsonCount(1, 'resultados')->assertJsonPath('resultados.0.num_empleado', '1005');
        $this->actingAs($this->admin)->getJson('/accesos/buscar?que=vehiculo&q=abc')->assertOk()
            ->assertJsonPath('resultados.0.placas', 'ABC123A')->assertJsonPath('resultados.0.marca', 'MAZDA')->assertJsonPath('resultados.0.tipo', 'suv');
        $this->actingAs($this->admin)->getJson('/accesos/buscar?que=proveedor&q=turq')->assertOk()->assertJsonPath('resultados.0.nombre', 'Viajes Turquesa');
        $this->actingAs($this->admin)->getJson('/accesos/buscar?que=otra&q=x')->assertStatus(422);
    }

    // ------------------------------------------------- Historial y exportación

    public function test_historial_paginado_con_filtros_y_exportacion(): void
    {
        // Mediodía fijo: cerca de medianoche, "hoy" en la sede y en UTC son días distintos
        $this->travelTo(now()->setTimezone('UTC')->setTime(18, 0));
        for ($i = 1; $i <= 26; $i++) {
            $this->registrar(['tipo' => $i % 2 ? 'visitante' : 'emergencia', 'nombre' => 'Persona '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            $this->actingAs($this->admin)->patch('/accesos/'.$this->ultimo()->id.'/salida');
        }
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Sigue Dentro']);
        $this->flushSession();

        $this->actingAs($this->admin)->get('/accesos?pestana=historial')->assertOk()
            ->assertSee('26 registro(s) finalizado(s).')->assertSee('PERSONA 26')->assertDontSee('PERSONA 02')->assertDontSee('SIGUE DENTRO')
            ->assertSee('Exportar a Excel')->assertSee('pestana=historial&amp;page=2', false);
        $this->actingAs($this->admin)->get('/accesos?pestana=historial&page=2')->assertSee('PERSONA 01')->assertSee('PERSONA 02');
        $this->actingAs($this->admin)->get('/accesos?pestana=historial&tipo=emergencia&q=persona 1')->assertSee('PERSONA 10')->assertDontSee('PERSONA 11')
            ->assertSee('con esos filtros');

        $hoy = now('America/Cancun')->format('Y-m-d');
        $this->actingAs($this->admin)->get('/accesos?pestana=historial&desde='.now()->addDays(2)->format('Y-m-d'))->assertSee('Sin resultados para esa búsqueda.');
        $this->actingAs($this->admin)->get('/accesos?pestana=historial&desde=2026-99-01')->assertSessionHasErrors(['desde' => 'Revisa la fecha «Desde».']);

        $csv = $this->actingAs($this->admin)->get('/accesos/exportar?tipo=visitante&desde='.$hoy.'&hasta='.$hoy);
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $contenido = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString('Folio,Sede,Tipo,Movimiento,Nombre', $contenido);
        $this->assertStringContainsString('PERSONA 25', $contenido);
        $this->assertStringNotContainsString('PERSONA 26', $contenido);
        $this->assertStringNotContainsString('SIGUE DENTRO', $contenido);
    }

    // --------------------------------------------------------- Integraciones

    public function test_alta_provisional_de_la_caseta_y_union_con_su_registro(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/accesos')->assertSee('¿No aparece? Darlo de alta provisional')->assertSee('Alta Provisional de Colaborador');

        $request = Request::create('/', 'POST', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'apellido_materno' => 'Tun', 'sede_id' => $this->centro->id]);
        $this->actingAs($agente);
        $provisional = $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->crearProvisional($agente, $this->empresa->id, $request));

        $this->registrar(['tipo' => 'colaborador', 'colaborador_id' => $provisional->id], $agente)->assertSessionHasNoErrors();
        $acceso = $this->ultimo();

        // Recursos Humanos une el provisional con el registro correcto: el acceso se mueve
        $correcto = $this->colaborador('1020', null, null, 'Jorge');
        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->fusionar($this->admin, $provisional->fresh(), $correcto));
        $this->assertSame($correcto->id, $acceso->fresh()->colaborador_id);
    }

    public function test_la_pantalla_carga_todo_sin_consultas_por_tarjeta(): void
    {
        $host = $this->colaborador('2001');
        for ($i = 0; $i < 6; $i++) {
            $g = $this->gafete();
            $this->registrar(['tipo' => 'proveedor', 'nombre' => 'Prov '.$i, 'empresa_procedencia' => 'Empresa '.$i, 'host_colaborador_id' => $host->id,
                'gafete_id' => $g->id, 'acompanantes' => [['nombre' => 'A'.$i]]]);
            $this->actingAs($this->admin)->patch('/accesos/'.$this->ultimo()->id.'/autorizar');
        }

        \DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/accesos')->assertOk()->assertSee('Gente en Sitio (6)');
        $consultas = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        $this->assertLessThan(60, $consultas, "La pantalla hizo {$consultas} consultas.");
    }
}
