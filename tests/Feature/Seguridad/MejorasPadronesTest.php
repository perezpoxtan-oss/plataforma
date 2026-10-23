<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\Gafete;
use App\Models\ImpresionEtiquetas;
use App\Models\Notificacion;
use App\Models\Paradero;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Lector\ImpresionDesdePadron;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Padrones\CampanaPadrones;
use App\Services\Rutas\AdministradorRutas;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Mejoras padrones (ADR-0015): campana de altas por verificar y su
 * resultado, unión de personas verificadas duplicadas, un solo camino de
 * baja/reactivación con voucher (y firma digital) en los tres inventarios,
 * «Dar salida» desde Estacionamientos, un solo camino de impresión, alta
 * corta con «Más datos», siguiente paso tras guardar y vocabulario de baja.
 */
class MejorasPadronesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $jefe;

    private User $supervisor;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function persona(string $nombre, array $extra = []): Persona
    {
        return $this->enEmpresa(function () use ($nombre, $extra) {
            $p = Persona::create(array_merge(['tipo' => 'visitante', 'nombre_completo' => $nombre], array_diff_key($extra, ['creado_por' => 1])));
            // Quien la registró (en la caseta lo pone la sesión)
            if (isset($extra['creado_por'])) {
                $p->forceFill(['creado_por' => $extra['creado_por']])->save();
            }

            return $p;
        });
    }

    /** @return list<Notificacion> */
    private function avisos(User $usuario, string $tipo): array
    {
        return Notificacion::withoutGlobalScopes()->where('user_id', $usuario->id)->where('tipo', $tipo)->get()->all();
    }

    private function gafete(): Gafete
    {
        return $this->enEmpresa(fn () => app(AdministradorGafetes::class)->generarLote($this->admin, $this->empresa->id, [
            'sede_id' => $this->centro->id, 'tipo_gafete_id' => TipoGafete::firstOrCreate(['nombre' => 'Visitante'])->id, 'cantidad' => 1,
        ])->first());
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

    // ------------------------------------------- Altas por verificar en la campana

    public function test_alta_por_verificar_avisa_en_la_campana_y_el_rechazo_le_llega_al_agente(): void
    {
        $persona = $this->persona('Luis Canché', ['creado_por' => $this->agente->id]);
        $this->enEmpresa(fn () => app(AltasPorVerificar::class)->registrarAlta($this->agente, 'personas', $persona, 'accesos'));

        // A quien verifica en esa sede (Jefe y Administrador), con su botón; no a quien la registró
        $this->assertCount(1, $this->avisos($this->jefe, 'alta_por_verificar'));
        $this->assertCount(1, $this->avisos($this->admin, 'alta_por_verificar'));
        $this->assertCount(0, $this->avisos($this->agente, 'alta_por_verificar'));
        $aviso = $this->avisos($this->jefe, 'alta_por_verificar')[0];
        $this->assertSame('Alta por verificar: Luis Canché', $aviso->titulo);
        $this->assertSame([['etiqueta' => 'Verificar', 'abrir' => true]], $aviso->acciones);
        $this->assertStringContainsString('verificacion=pendiente', (string) $aviso->url);

        $this->actingAs($this->jefe)->put(route('personas.rechazar', $persona->id), ['motivo_rechazo' => 'Datos inventados'])->assertRedirect();

        // El aviso del jefe pierde su botón y el agente se entera del motivo
        $this->assertNull($this->avisos($this->jefe, 'alta_por_verificar')[0]->fresh()->acciones);
        $rechazo = $this->avisos($this->agente, 'alta_rechazada');
        $this->assertCount(1, $rechazo);
        $this->assertSame('Tu alta «Luis Canché» fue rechazada', $rechazo[0]->titulo);
        $this->assertStringContainsString('Datos inventados', (string) $rechazo[0]->texto);
    }

    public function test_aceptar_o_unir_un_alta_tambien_le_avisa_a_quien_la_registro(): void
    {
        $correcta = $this->persona('María Pech');
        $alta = $this->persona('Maria Pech', ['creado_por' => $this->agente->id]);
        $otra = $this->persona('Pedro Uc', ['creado_por' => $this->agente->id]);
        $this->enEmpresa(function () use ($alta, $otra) {
            app(AltasPorVerificar::class)->registrarAlta($this->agente, 'personas', $alta, 'accesos');
            app(AltasPorVerificar::class)->registrarAlta($this->agente, 'personas', $otra, 'accesos');
        });

        $this->actingAs($this->jefe)->put(route('personas.unir', $alta->id), ['destino_id' => $correcta->id])->assertRedirect();
        $this->actingAs($this->jefe)->put(route('personas.aceptar', $otra->id), [])->assertRedirect();

        $this->assertStringContainsString('María Pech', (string) $this->avisos($this->agente, 'alta_unida')[0]->texto);
        $this->assertSame('Tu alta «Pedro Uc» quedó verificada', $this->avisos($this->agente, 'alta_verificada')[0]->titulo);

        // QA VER-09: también un vehículo dice «quedó verificada» / «fue rechazada» (concuerda con «alta»)
        [$auto, $falso] = $this->enEmpresa(function () {
            $auto = Vehiculo::create(['placas' => 'XTR901C', 'tipo' => 'sedan', 'marca' => 'VW', 'color' => 'ROJO', 'propiedad' => 'particular']);
            $falso = Vehiculo::create(['placas' => 'FAL001A', 'tipo' => 'sedan', 'marca' => 'VW', 'color' => 'AZUL', 'propiedad' => 'particular']);
            foreach ([$auto, $falso] as $v) {
                $v->forceFill(['creado_por' => $this->agente->id])->save();
                app(AltasPorVerificar::class)->registrarAlta($this->agente, 'vehiculos', $v, 'accesos');
            }

            return [$auto, $falso];
        });
        $this->actingAs($this->jefe)->put(route('vehiculos.aceptar', $auto->id), [])->assertRedirect();
        $this->assertSame('Tu alta «XTR901C» quedó verificada', collect($this->avisos($this->agente, 'alta_verificada'))->last()->titulo);
        $this->enEmpresa(fn () => app(CampanaPadrones::class)->resultado('vehiculos', $falso, 'rechazada', $this->jefe));
        $this->assertSame('Tu alta «FAL001A» fue rechazada', collect($this->avisos($this->agente, 'alta_rechazada'))->last()->titulo);
    }

    public function test_colaborador_por_verificar_va_a_la_campana_y_a_mis_pendientes_de_recursos_humanos(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', [
            'nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id,
        ])->assertCreated();
        $jorge = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Jorge')->firstOrFail());

        $this->assertSame('Alta por verificar: Jorge Méndez', $this->avisos($rh, 'alta_por_verificar')[0]->titulo);
        $pendientes = collect(app(MisPendientes::class)->para($rh)['items'])->keyBy('clave');
        $this->assertSame(1, $pendientes['colaboradores_por_verificar']['total']);

        $this->actingAs($rh)->put("/colaboradores/{$jorge->id}/validar", [
            'nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id, 'num_empleado' => '3001', '_dialogo' => "validar-{$jorge->id}",
        ])->assertSessionHas('ok', '«Jorge Méndez» verificado con el número 3001.');
        $this->assertSame('Tu alta «Jorge Méndez» quedó verificada', $this->avisos($this->agente, 'alta_verificada')[0]->titulo);
        $this->actingAs($rh)->get('/colaboradores')->assertDontSee('POR VALIDAR');
    }

    // ------------------------------------------------ Unir personas verificadas

    public function test_unir_dos_personas_verificadas_mueve_sus_accesos_y_completa_la_correcta(): void
    {
        $correcta = $this->persona('Ana López Uc', ['telefono' => null]);
        $duplicada = $this->persona('Ana Lopez', ['tipo_identificacion' => 'ine', 'folio_identificacion' => 'ABC12345', 'telefono' => '9981112233']);
        $acceso = $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Ana Lopez', 'persona_id' => $duplicada->id, 'entrada_at' => now()]));

        // El botón solo lo ve quien edita y da de baja
        $this->actingAs($this->jefe)->get('/personas')->assertSee(route('personas.unir-duplicado', $duplicada->id), false);
        $this->actingAs($this->supervisor)->get('/personas')->assertDontSee(route('personas.unir-duplicado', $duplicada->id), false);
        $this->actingAs($this->supervisor)->putJson(route('personas.unir-duplicado', $duplicada->id), ['destino_id' => $correcta->id])->assertForbidden();

        // No se une consigo misma
        $this->actingAs($this->jefe)->putJson(route('personas.unir-duplicado', $duplicada->id), ['destino_id' => $duplicada->id])->assertStatus(422);

        $this->actingAs($this->jefe)->putJson(route('personas.unir-duplicado', $duplicada->id), ['destino_id' => $correcta->id])
            ->assertOk()->assertJsonPath('ok', true);

        $this->enEmpresa(function () use ($acceso, $correcta, $duplicada) {
            $this->assertSame($correcta->id, $acceso->fresh()->persona_id);
            $d = $duplicada->fresh();
            $this->assertFalse((bool) $d->activo);
            $this->assertSame($correcta->id, $d->fusionado_en_id);
            $this->assertNull($d->folio_identificacion);
            $c = $correcta->fresh();
            $this->assertSame(['ABC12345', '9981112233'], [$c->folio_identificacion, $c->telefono]);
        });
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.fusionado', 'auditable_id' => $duplicada->id]);
        // Ya unida, no se vuelve a unir (queda fuera del padrón que se verifica)
        $this->assertContains($this->actingAs($this->jefe)->putJson(route('personas.unir-duplicado', $duplicada->id), ['destino_id' => $correcta->id])->status(), [404, 422]);
    }

    public function test_la_empresa_escrita_a_mano_se_liga_a_su_ficha_si_ya_esta_en_el_directorio(): void
    {
        $bimbo = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Grupo Bimbo S.A. de C.V.', 'categoria' => 'proveedor', 'todas_las_sedes' => true]));

        $this->actingAs($this->admin)->post('/personas', [
            'tipo' => 'proveedor', 'nombre_completo' => 'Rafael Tun', 'empresa_procedencia' => 'grupo bimbo', 'tipo_identificacion' => 'ine', '_dialogo' => 'crear',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/personas', [
            'tipo' => 'proveedor', 'nombre_completo' => 'Sara Can', 'empresa_procedencia' => 'Paquetería del Caribe', 'tipo_identificacion' => 'ine', '_dialogo' => 'crear',
        ])->assertSessionHasNoErrors();

        $this->enEmpresa(function () use ($bimbo) {
            $rafael = Persona::where('nombre_completo', 'Rafael Tun')->firstOrFail();
            $this->assertSame([$bimbo->id, null], [$rafael->proveedor_id, $rafael->empresa_procedencia]);
            $sara = Persona::where('nombre_completo', 'Sara Can')->firstOrFail();
            $this->assertSame([null, 'Paquetería del Caribe'], [$sara->proveedor_id, $sara->empresa_procedencia]);
        });

        // El filtro de categoría solo aplica a visitantes y el motivo va plegado en «Más datos»
        $this->actingAs($this->admin)->get('/personas')->assertSee('data-solo-con-tipo="visitante" hidden', false)
            ->assertSee('Motivo habitual de sus visitas')->assertSee('data-mas-datos', false)->assertSee('-- Otra empresa (escríbela abajo) --');
    }

    public function test_la_empresa_escrita_se_liga_a_la_exacta_y_si_hay_varias_parecidas_pide_elegir(): void
    {
        // QA PER-04: con «Abarrotes del Caribe» y «Abarrotes del Caribe S.A. de C.V.» gana la que coincide exacto
        [$exacta, $conSa] = $this->enEmpresa(fn () => [
            Proveedor::create(['nombre' => 'Abarrotes del Caribe', 'categoria' => 'proveedor', 'todas_las_sedes' => true]),
            Proveedor::create(['nombre' => 'Abarrotes del Caribe S.A. de C.V.', 'categoria' => 'proveedor', 'todas_las_sedes' => true]),
        ]);
        $this->actingAs($this->admin)->post('/personas', [
            'tipo' => 'proveedor', 'nombre_completo' => 'Prueba Ligada Uno', 'empresa_procedencia' => 'abarrotes del caribe', 'tipo_identificacion' => 'ine', '_dialogo' => 'crear',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/personas', [
            'tipo' => 'proveedor', 'nombre_completo' => 'Prueba Ligada Dos', 'empresa_procedencia' => 'ABARROTES DEL CARIBE, S.A. DE C.V.', 'tipo_identificacion' => 'ine', '_dialogo' => 'crear',
        ])->assertSessionHasNoErrors();
        // Sin coincidencia exacta y con dos que empatan sin «S.A. de C.V.»: no adivina, pide elegir
        $this->actingAs($this->admin)->post('/personas', [
            'tipo' => 'proveedor', 'nombre_completo' => 'Prueba Ligada Tres', 'empresa_procedencia' => 'Abarrotes del Caribe S de RL', 'tipo_identificacion' => 'ine', '_dialogo' => 'crear',
        ])->assertSessionHasErrors(['empresa_procedencia' => 'Hay varias empresas registradas con ese nombre («Abarrotes del Caribe», «Abarrotes del Caribe S.A. de C.V.»). Elige la correcta en «Empresa que representa».']);

        $this->enEmpresa(function () use ($exacta, $conSa) {
            $this->assertSame($exacta->id, Persona::where('nombre_completo', 'Prueba Ligada Uno')->value('proveedor_id'));
            $this->assertSame($conSa->id, Persona::where('nombre_completo', 'Prueba Ligada Dos')->value('proveedor_id'));
            $this->assertFalse(Persona::where('nombre_completo', 'Prueba Ligada Tres')->exists());
            $this->assertSame(2, Proveedor::count(), 'No se crean empresas repetidas');
        });
    }

    // ------------------------------------------- Bajas con voucher y reactivación

    public function test_baja_de_gafete_con_firma_digital_y_reactivar_cancela_su_voucher(): void
    {
        $g = $this->gafete();
        $this->actingAs($this->admin)->get('/gafetes')->assertSee('name="firma_modo"', false)->assertSee('Se le cobra al colaborador')->assertDontSee('CXC');
        // Si se elige «Firma digital» y no se firma, el navegador detiene el envío y muestra qué falta (antes solo pintaba el recuadro en rojo)
        $this->actingAs($this->admin)->get('/gafetes')
            ->assertSee('<p class="firma-falta-texto" role="alert" data-firma-falta hidden>Falta la firma de Seguridad: firma en el recuadro o elige «Firma física».</p>', false)
            ->assertSee('Falta la firma del responsable: que firme en el recuadro o elige «Firma física».');

        // Firma digital: sin la firma de Seguridad no se da de baja
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'danado', 'firma_modo' => 'digital'])->assertSessionHasErrors('firma_seguridad');
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'danado', 'firma_modo' => 'digital', 'firma_seguridad' => $this->firma()])
            ->assertSessionHas('aviso');
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());
        $this->assertSame('digital', $voucher->firma_modo);
        $this->assertNotNull($voucher->firma_seguridad);

        // La tarjeta dice el motivo y lleva a su voucher
        $this->actingAs($this->admin)->get('/gafetes')->assertSee('DE BAJA · DAÑADO')->assertSee($voucher->folio)->assertDontSee('NO DISPONIBLE');

        // Reactivar desde la ficha = «Recuperado»: el voucher se conserva cancelado
        $this->actingAs($this->admin)->patch("/gafetes/{$g->id}/reactivar")->assertSessionHas('ok');
        $this->assertTrue($this->enEmpresa(fn () => $g->fresh()->activo));
        $this->assertSame('cancelado_recuperacion', $this->enEmpresa(fn () => $voucher->fresh()->estado));
        $this->assertDatabaseHas('auditoria', ['evento' => 'vouchers.recuperado', 'auditable_id' => $voucher->id]);
    }

    public function test_reactivar_con_voucher_con_cobro_lleva_a_recuperado_y_abre_su_dialogo(): void
    {
        $g = $this->gafete();
        $colab = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '77', 'nombre' => 'Eva', 'apellido_paterno' => 'Poot', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => 120, 'colaborador_id' => $colab->id]);
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());

        $destino = route('vouchers.index', ['q' => $voucher->folio, 'recuperar' => $voucher->id]).'#voucher-'.$voucher->id;
        $this->actingAs($this->admin)->patch("/gafetes/{$g->id}/reactivar")->assertRedirect($destino)->assertSessionHas('aviso');
        $this->assertFalse($this->enEmpresa(fn () => $g->fresh()->activo));
        $html = $this->actingAs($this->admin)->get(route('vouchers.index', ['q' => $voucher->folio, 'recuperar' => $voucher->id]))->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoRecuperadoVoucher"[^>]*data-abrir-al-cargar/', $html);
    }

    // -------------------------------------------------- Estacionamientos

    public function test_dar_salida_desde_la_zona_corrige_la_ocupacion(): void
    {
        $zona = $this->enEmpresa(fn () => ZonaEstacionamiento::create(['sede_id' => $this->centro->id, 'nombre' => 'Huéspedes', 'tipo' => 'estacionamiento', 'cupo_total' => 10]));
        $acceso = $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Juan Pérez', 'placas' => 'ABC123A',
            'zona_estacionamiento_id' => $zona->id, 'entrada_at' => now()]));

        $this->actingAs($this->agente)->get('/estacionamientos')->assertSee('1 / 10 espacios')->assertSee('¿Quién ocupa? (1)')
            ->assertSee(route('estacionamientos.salida', [$zona->id, $acceso->id]), false)->assertSee('Estacionamientos y zonas de descarga');
        // Quien no da salida en Accesos no ve ni usa el botón
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->get('/estacionamientos')->assertDontSee('¿Quién ocupa?');
        $this->actingAs($director)->patch(route('estacionamientos.salida', [$zona->id, $acceso->id]))->assertForbidden();

        $this->actingAs($this->agente)->patch(route('estacionamientos.salida', [$zona->id, $acceso->id]))->assertSessionHas('ok');
        $this->assertSame('finalizado', $this->enEmpresa(fn () => $acceso->fresh()->estado));
        $this->assertDatabaseHas('auditoria', ['evento' => 'accesos.salida', 'auditable_id' => $acceso->id]);
        $this->actingAs($this->agente)->get('/estacionamientos')->assertSee('0 / 10 espacios');
        // Dos veces no
        $this->actingAs($this->agente)->patch(route('estacionamientos.salida', [$zona->id, $acceso->id]))->assertSessionHas('error');
    }

    // ------------------------------------------- Un solo camino de impresión

    public function test_impresion_desde_los_padrones_con_hoja_propia_o_con_plantillas(): void
    {
        $g = $this->gafete();

        // Por omisión, como siempre: hoja propia con recordatorio de escala y el camino a Etiquetas QR
        $this->actingAs($this->admin)->get(route('gafetes.imprimir', ['gafetes' => [$g->id]]))->assertOk()
            ->assertSee('Impresión Doble Vista (Libro)')->assertSee('Escala 100 %')->assertSee('Imprimir con una plantilla de Etiquetas QR')
            ->assertSee('value="gafete-'.$g->id.'"', false);
        $this->assertSame(0, $this->enEmpresa(fn () => ImpresionEtiquetas::count()));

        // Solo quien configura plantillas para toda la empresa cambia el modo
        $this->actingAs($this->jefe)->put(route('etiquetas.plantillas.impresion-padrones'), ['impresion' => 'plantillas'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('etiquetas.plantillas'))->assertSee('¿Cómo imprimen Llaves, Gafetes, Vehículos y Equipos?');
        $this->actingAs($this->admin)->put(route('etiquetas.plantillas.impresion-padrones'), ['impresion' => 'plantillas'])->assertSessionHas('ok');
        $this->assertSame(ImpresionDesdePadron::PLANTILLAS, app(ImpresionDesdePadron::class)->modo($this->empresa->id));
        $this->assertDatabaseHas('auditoria', ['evento' => 'etiquetas_qr.configurado']);

        // Con plantillas: el mismo botón queda registrado en el Historial de Etiquetas QR
        $respuesta = $this->actingAs($this->admin)->get(route('gafetes.imprimir', ['gafetes' => [$g->id]]));
        $impresion = $this->enEmpresa(fn () => ImpresionEtiquetas::firstOrFail());
        $respuesta->assertRedirect(route('etiquetas.impresion', $impresion->id));
        $this->assertSame('Gafete', $impresion->plantilla_nombre);
        $this->actingAs($this->admin)->get(route('etiquetas.impresion', $impresion->id))->assertOk()->assertSee('Escala 100 %');
    }

    // ------------------------------------------------ Rutas y Protección Civil

    public function test_paradero_escrito_distinto_no_se_duplica(): void
    {
        $existente = $this->enEmpresa(fn () => Paradero::create(['sede_id' => $this->centro->id, 'nombre' => 'OXXO CENTRO']));
        $mismo = $this->enEmpresa(fn () => app(AdministradorRutas::class)->paraderoParaRuta($this->admin, $this->centro, Paradero::normalizarNombre('Óxxo-Centro.')));
        $this->assertSame($existente->id, $mismo->id);
        $this->assertSame(1, $this->enEmpresa(fn () => Paradero::count()));
    }

    public function test_equipo_pc_muestra_su_ultima_revision_y_la_clave_de_la_etiqueta(): void
    {
        $extintor = $this->enEmpresa(fn () => EquipoPc::create(['sede_id' => $this->centro->id, 'categoria' => 'extintor', 'numero_serie' => 'EXT-01']));
        $this->actingAs($this->admin)->get('/equipos-pc')->assertSee('CLAVE DE LA ETIQUETA')->assertSee('Todavía sin revisar en un recorrido');

        $this->enEmpresa(function () use ($extintor) {
            $recorrido = DB::table('recorridos_pc')->insertGetId(['empresa_id' => $this->empresa->id, 'sede_id' => $this->centro->id, 'numero' => 1,
                'creado_por' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('recorrido_pc_revisiones')->insert(['empresa_id' => $this->empresa->id, 'recorrido_pc_id' => $recorrido, 'equipo_pc_id' => $extintor->id,
                'identificador' => 'EXT-01', 'categoria' => 'extintor', 'criterios' => '[]', 'resultado' => 'falla', 'creado_por' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->actingAs($this->admin)->get('/equipos-pc')->assertSee('Última revisión:')->assertSee('con falla');
    }

    // ------------------------------------------ Alta corta y siguiente paso

    public function test_alta_corta_de_llave_y_siguiente_paso_tras_guardar(): void
    {
        $this->actingAs($this->admin)->get('/llaves')->assertSee('data-mas-datos', false)->assertSee('Más datos')->assertDontSee('Guardar e Indexar Llave');

        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'll-01', 'descripcion' => 'Bodega',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global', '_dialogo' => 'crear'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/llaves')->assertSee('Siguiente paso:')->assertSee('Imprimir su etiqueta');

        // Gafetes: «Imprimir este lote» con justo los recién generados
        $tipo = $this->enEmpresa(fn () => TipoGafete::firstOrCreate(['nombre' => 'Visitante']));
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, 'cantidad' => 2, '_dialogo' => 'lote']);
        $ids = $this->enEmpresa(fn () => Gafete::orderBy('id')->pluck('id')->all());
        $this->actingAs($this->admin)->get('/gafetes')->assertSee('Imprimir este lote')
            ->assertSee(e(route('gafetes.imprimir', ['gafetes' => $ids])), false);
    }

    // ------------------------------------------------------ Un solo vocabulario

    public function test_un_solo_vocabulario_para_dar_de_baja_y_nombres_claros(): void
    {
        // Matriz de permisos y menú (migración y catálogo)
        $this->assertSame('Dar de baja', DB::table('acciones')->where('clave', 'eliminar')->value('nombre'));

        $p = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Gas del Sur', 'categoria' => 'proveedor', 'todas_las_sedes' => true, 'activo' => false]));
        $this->actingAs($this->admin)->get('/proveedores')->assertSee('DE BAJA')->assertDontSee('VETADA')->assertDontSee('title="Desactivar"', false);
        $this->actingAs($this->admin)->get("/proveedores/{$p->id}")->assertSee('Ficha de empresa externa')->assertDontSee('Ficha de Proveedor');
        $this->actingAs($this->admin)->get('/vehiculos')->assertSee('¿De quién es?')->assertSee('¿Qué forma tiene?')->assertSee('<option value="particular"', false);
        $this->actingAs($this->admin)->get('/turnos')->assertDontSee('title="Desactivar"', false);
        $this->actingAs($this->admin)->get('/puestos')->assertSee('es el rango');

        // Con una sola sede, la empresa externa se quita o agrega a la sede con un toque
        $soloCen = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->enEmpresa(fn () => $p->forceFill(['activo' => true])->save());
        $this->actingAs($soloCen)->get('/proveedores')->assertSee('Quitar de mi sede');
    }

    public function test_quitar_de_mi_sede_deja_volver_a_agregarla(): void
    {
        // QA PRV-05: al quitarla deja de verse en la lista; el aviso ofrece «Agregar a mi sede»
        $soloCen = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        [$lav, $otra] = $this->enEmpresa(function () {
            $lav = Proveedor::create(['nombre' => 'Lavandería Industrial del Sur', 'categoria' => 'proveedor', 'todas_las_sedes' => false]);
            $lav->sedes()->sync([$this->centro->id, $this->playa->id]);
            $otra = Proveedor::create(['nombre' => 'Hielo Playa', 'categoria' => 'proveedor', 'todas_las_sedes' => false]);
            $otra->sedes()->sync([$this->playa->id]);

            return [$lav, $otra];
        });

        $this->actingAs($soloCen)->put("/proveedores/{$lav->id}/sedes", ['_volver' => 'lista'])
            ->assertRedirect('/proveedores')->assertSessionHas('ok', 'Sedes de «Lavandería Industrial del Sur» actualizadas.');
        $this->assertSame([$this->playa->id], $this->enEmpresa(fn () => $lav->sedes()->pluck('sedes.id')->all()), 'Las demás sedes se quedan');
        $lista = $this->actingAs($soloCen)->get('/proveedores');
        $lista->assertSee('ya no aparece en tu lista')->assertSee(route('proveedores.sedes', $lav->id), false)->assertSee('Agregar a mi sede');

        $this->actingAs($soloCen)->put("/proveedores/{$lav->id}/sedes", ['_volver' => 'lista', 'sedes' => [$this->centro->id]])->assertSessionHas('ok');
        $this->assertEqualsCanonicalizing([$this->centro->id, $this->playa->id], $this->enEmpresa(fn () => $lav->sedes()->pluck('sedes.id')->all()));
        $this->actingAs($soloCen)->get('/proveedores')->assertSee('Lavandería Industrial del Sur')->assertDontSee('ya no aparece en tu lista');

        // Fuera de su sede: solo la que él quitó y solo para AGREGAR su sede; cualquier otra por su número no existe
        $this->actingAs($soloCen)->put("/proveedores/{$otra->id}/sedes", ['sedes' => [$this->centro->id]])->assertNotFound();
        $this->actingAs($soloCen)->put("/proveedores/{$otra->id}/sedes", ['_volver' => 'lista'])->assertNotFound();
        $this->actingAs($soloCen)->put("/proveedores/{$otra->id}/sedes", ['sedes' => [$this->playa->id]])->assertNotFound();
        $this->assertSame([$this->playa->id], $this->enEmpresa(fn () => $otra->sedes()->pluck('sedes.id')->all()));
        // Dada de baja tampoco
        $this->enEmpresa(fn () => $otra->forceFill(['activo' => false])->save());
        $this->actingAs($soloCen)->put("/proveedores/{$otra->id}/sedes", ['sedes' => [$this->centro->id]])->assertNotFound();
    }
}
