<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\Notificacion;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\RecorridoPc;
use App\Models\RoboDetalle;
use App\Models\Turno;
use App\Models\User;
use App\Services\Novedades\TurnosNovedades;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\PasesSalida\EsperaPasesSalida;
use App\Support\Menu\ConstructorMenu;
use App\Support\Menu\MisPendientes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Mejoras de Incidentes (ADR-0013): canalizar avisa, el expediente hereda la
 * ubicación, «Pendiente de turno» con dueño, hora y aviso, «Registrar objeto
 * encontrado», una sola ficha de robo, categoría del hallazgo de recorrido y
 * sus avisos, nombre del menú configurable, y reloj/color/escalamiento de las
 * firmas de pases de salida. Sin configurar, todo se ve como siempre.
 */
class MejorasIncidentesTest extends PruebaNovedades
{
    private User $agente;

    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->agente->forceFill(['name' => 'Luis Agente'])->save();
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->jefe->forceFill(['name' => 'Rosa Jefa'])->save();
    }

    /** Después de viajar en el tiempo, la sesión sigue activa (no cuenta como inactividad). */
    private function entrar(User $usuario): static
    {
        return $this->withSession(['ultima_actividad' => now()->getTimestamp()])->actingAs($usuario);
    }

    /** @return Collection<int, Notificacion> */
    private function avisos(User $usuario, ?string $tipo = null): Collection
    {
        return $this->enEmpresa(fn () => Notificacion::where('user_id', $usuario->id)->when($tipo, fn ($q) => $q->where('tipo', $tipo))->orderBy('id')->get());
    }

    private function ultima(): Novedad
    {
        return $this->enEmpresa(fn () => Novedad::with('articulos')->orderByDesc('id')->firstOrFail());
    }

    // ------------------------------------------------------------ Canalizar

    public function test_canalizar_avisa_en_la_campana_y_en_mis_pendientes_y_cerrar_avisa_a_quien_lo_levanto(): void
    {
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['asignado_a' => $this->agente->id]))
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'Le avisamos a Luis Agente.'));
        $n = $this->ultima();

        $aviso = $this->avisos($this->agente, 'novedad_canalizada')->sole();
        $this->assertSame("Te canalizaron el ticket {$n->folio()}", $aviso->titulo);
        $this->assertStringContainsString('lo canalizó Ana Administradora', $aviso->texto);
        $this->assertSame('Atender', $aviso->acciones[0]['etiqueta']);
        $this->assertCount(0, $this->avisos($this->admin, 'novedad_canalizada'));

        // Mis pendientes y la lista «canalizados a mí»
        $pendientes = collect(app(MisPendientes::class)->para($this->agente)['items'])->keyBy('clave');
        $this->assertSame(1, $pendientes['novedades_mias']['total']);
        $this->actingAs($this->agente)->get('/novedades?mios=1')->assertOk()->assertSee('canalizados a ti')->assertSee($n->folio());

        // Cambiar a quién: el anterior pierde el botón y el nuevo recibe el aviso
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['asignado_a' => $this->jefe->id]))->assertSessionHasNoErrors();
        $this->assertNull($this->avisos($this->agente, 'novedad_canalizada')->sole()->acciones);
        $this->assertCount(1, $this->avisos($this->jefe, 'novedad_canalizada'));

        // Al cerrar, quien lo levantó y el canalizado se enteran (no quien lo cerró)
        $this->actingAs($this->jefe)->put("/novedades/{$n->id}", $this->expediente($n, ['asignado_a' => $this->jefe->id, 'estatus' => 'resuelto', 'resolucion' => 'Se revisó la tubería.']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Ticket '.$n->folio().' resuelto', $this->avisos($this->admin, 'novedad_resuelta')->sole()->titulo);
        $this->assertCount(0, $this->avisos($this->jefe, 'novedad_resuelta'));
    }

    public function test_sin_canalizar_no_hay_avisos_y_el_correo_va_apagado_por_omision(): void
    {
        $this->actingAs($this->agente)->post('/novedades', $this->datos())->assertSessionHasNoErrors();
        $this->assertSame(0, $this->enEmpresa(fn () => Notificacion::count()));
        $this->assertFalse($this->empresa->fresh()->aviso('novedad_canalizada'));
        $this->assertFalse($this->empresa->fresh()->aviso('hallazgo_recorrido_pc'));
    }

    // ------------------------------------------------------------ Ubicación heredada

    public function test_el_expediente_hereda_el_area_del_ticket_sin_volver_a_preguntarla(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 2', $torre);
        $n = $this->novedad(['area_id' => $piso->id, 'categoria' => 'incidente_general'], null, $this->admin);

        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()
            ->assertSee('Torre A · Piso 2')->assertSee('— del reporte inicial')->assertSee('Cambiar edificio o piso');

        // Sin área en el ticket, los combos se ven como siempre
        $sinArea = $this->novedad(['categoria' => 'incidente_general'], null, $this->admin);
        $this->actingAs($this->admin)->get('/novedades?abrir='.$sinArea->id)->assertOk()->assertDontSee('Cambiar edificio o piso');
    }

    // ------------------------------------------------------------ Pendiente de turno

    public function test_pendiente_de_turno_sin_turnos_avisa_de_inmediato_al_personal_de_la_sede(): void
    {
        $n = $this->novedad([], null, $this->admin);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => 'pendiente_turno']))->assertSessionHasNoErrors();

        $n = $this->buscar($n->id);
        $this->assertNotNull($n->pendiente_turno_en);
        $this->assertNotNull($n->turno_avisado_en);
        $this->assertCount(1, $this->avisos($this->agente, 'novedad_turno'));
        $this->assertCount(1, $this->avisos($this->jefe, 'novedad_turno'));
        $this->assertCount(0, $this->avisos($this->admin, 'novedad_turno'), 'Quien lo deja no se avisa a sí mismo');
        $this->assertStringContainsString('sin dueño', $this->avisos($this->agente, 'novedad_turno')->sole()->texto);

        $this->actingAs($this->admin)->get('/novedades')->assertSee('PENDIENTE DE TURNO')->assertSee('Sin dueño');
        $pendientes = collect(app(MisPendientes::class)->para($this->agente)['items'])->keyBy('clave');
        $this->assertSame(1, $pendientes['novedades_turno']['total']);

        // Sin turnos no hay hora límite: la tarea no escala
        $this->travel(2)->days();
        $this->artisan('plataforma:novedades-pendientes')->expectsOutputToContain('Avisos al turno: 0. Escalamientos al jefe: 0.')->assertSuccessful();
    }

    public function test_pendiente_de_turno_avisa_al_iniciar_el_siguiente_turno_y_escala_si_termina_sin_atenderse(): void
    {
        $zona = $this->centro->zonaHoraria();
        $this->enEmpresa(function () {
            Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'todas_las_sedes' => true]);
            Turno::create(['nombre' => 'Vespertino', 'hora_inicio' => '15:00', 'hora_fin' => '23:00', 'todas_las_sedes' => true]);
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', $zona));
        $n = $this->novedad(['asignado_a' => $this->agente->id], null, $this->admin);
        $this->entrar($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => 'pendiente_turno', 'asignado_a' => $this->agente->id]))
            ->assertSessionHasNoErrors();

        // Al marcarlo: solo su dueño lo sabe y la ficha dice qué turno lo recibe
        $aviso = $this->avisos($this->agente, 'novedad_turno')->sole();
        $this->assertStringContainsString('el turno Vespertino', $aviso->texto);
        $this->assertCount(0, $this->avisos($this->jefe, 'novedad_turno'));
        $this->entrar($this->admin)->get('/novedades')->assertSee('Lo recibe el turno Vespertino (15:00)')->assertSee('Luis Agente');
        $this->artisan('plataforma:novedades-pendientes --si-toca')->doesntExpectOutputToContain('Avisos')->assertSuccessful();

        // Empieza el Vespertino: aviso al dueño, una sola vez
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:05', $zona));
        $this->artisan('plataforma:novedades-pendientes')->expectsOutputToContain('Avisos al turno: 1. Escalamientos al jefe: 0.')->assertSuccessful();
        $this->artisan('plataforma:novedades-pendientes')->expectsOutputToContain('Avisos al turno: 0. Escalamientos al jefe: 0.')->assertSuccessful();
        $this->assertCount(2, $this->avisos($this->agente, 'novedad_turno'));
        $this->assertNull($this->avisos($this->agente, 'novedad_turno')->first()->acciones, 'El aviso anterior queda resuelto');

        // Termina el Vespertino sin atenderlo: el jefe se entera, una sola vez
        $this->travelTo(CarbonImmutable::parse('2026-10-20 23:10', $zona));
        app(TurnosNovedades::class)->revisar();
        app(TurnosNovedades::class)->revisar();
        $escalada = $this->avisos($this->jefe, 'novedad_turno_escalada')->sole();
        $this->assertSame("Nadie atendió el ticket {$n->folio()}", $escalada->titulo);
        $this->assertStringContainsString('dueño: Luis Agente', $escalada->texto);
        $this->assertTrue(Auditoria::where('evento', 'novedades.turno_escalado')->where('auditable_id', $n->id)->exists());
        $this->assertTrue(Auditoria::where('evento', 'novedades.turno_avisado')->where('auditable_id', $n->id)->exists());

        // Al atenderlo, los avisos pierden su botón
        $this->entrar($this->agente)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => 'abierto', 'asignado_a' => $this->agente->id, 'nueva_nota' => 'Atendido']))
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->avisos($this->agente, 'novedad_turno')->every(fn ($a) => $a->acciones === null));
    }

    // ------------------------------------------------------------ Lost & Found

    public function test_el_ticket_rapido_de_lost_and_found_captura_el_objeto_y_no_queda_a_medias(): void
    {
        $this->actingAs($this->agente)->post('/novedades', $this->datos(['categoria' => 'lost_found', 'lf_objeto' => ' paraguas  negro ', 'lf_tipo_valor' => 'OTRO', 'lf_ubicacion_bodega' => 'anaquel 3']))
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'quedó en resguardo con el folio LF-'));
        $n = $this->ultima();
        $this->assertSame(['PARAGUAS NEGRO'], $n->articulos->pluck('objeto')->all());
        $this->assertSame('ANAQUEL 3', $n->articulos->first()->ubicacion_bodega);
        $this->assertSame(LostFoundArticulo::EN_RESGUARDO, $n->articulos->first()->estatus);

        // Sin objeto (un reporte de pérdida) el ticket nace como siempre
        $this->actingAs($this->agente)->post('/novedades', $this->datos(['categoria' => 'lost_found']))->assertSessionHasNoErrors();
        $this->assertCount(0, $this->ultima()->articulos);
        // Otra categoría ignora los campos del objeto
        $this->actingAs($this->agente)->post('/novedades', $this->datos(['lf_objeto' => 'Cartera']))->assertSessionHasNoErrors();
        $this->assertCount(0, $this->ultima()->articulos);
    }

    public function test_registrar_objeto_encontrado_desde_el_archivo_crea_el_ticket_por_detras(): void
    {
        $this->actingAs($this->agente)->get('/lost-found')->assertOk()->assertSee('Registrar objeto encontrado')->assertSee('dialogoObjetoLf');

        $this->actingAs($this->agente)->post('/lost-found/objetos', [
            '_dialogo' => 'objeto-lf', 'sede_id' => $this->centro->id, 'lf_objeto' => 'cartera café', 'lf_tipo_valor' => 'ALTO_VALOR',
            'ubicacion' => 'lobby', 'reportado_por' => 'camarista rosa', 'lf_ubicacion_bodega' => 'caja b',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, '(CARTERA CAFÉ) registrado en resguardo'))
            ->assertSessionHas('siguientePasoLf');
        $n = $this->ultima();
        $this->assertSame(['lost_found', 'LOBBY', 'CAMARISTA ROSA'], [$n->categoria, $n->ubicacion, $n->reportado_por]);
        $this->assertStringContainsString('Objeto encontrado: CARTERA CAFÉ', $n->descripcion);
        $articulo = $n->articulos->sole();
        $this->assertSame(['CARTERA CAFÉ', 'ALTO_VALOR', 'CAJA B'], [$articulo->objeto, $articulo->tipo_valor, $articulo->ubicacion_bodega]);
        $this->assertTrue(Auditoria::where('evento', 'lost_found.objeto_registrado')->where('auditable_id', $articulo->id)->exists());

        // Lo indispensable se pide con mensajes claros
        $this->actingAs($this->agente)->post('/lost-found/objetos', ['_dialogo' => 'objeto-lf', 'sede_id' => $this->centro->id])
            ->assertSessionHasErrors(['ubicacion', 'reportado_por']);
        $this->actingAs($this->agente)->post('/lost-found/objetos', ['_dialogo' => 'objeto-lf', 'sede_id' => $this->centro->id, 'ubicacion' => 'Lobby', 'reportado_por' => 'Yo'])
            ->assertSessionHasErrors(['lf_objeto' => 'Escribe qué objeto se encontró (por ejemplo: paraguas, cartera, celular).']);
        // Sede ajena: no
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($agentePlaya)->post('/lost-found/objetos', ['sede_id' => $this->centro->id, 'lf_objeto' => 'X', 'ubicacion' => 'L', 'reportado_por' => 'Y'])
            ->assertSessionHasErrors('sede_id');
        // Sin permiso de crear tickets: 403
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->post('/lost-found/objetos', ['lf_objeto' => 'X'])->assertForbidden();
    }

    public function test_un_ticket_a_medias_se_completa_desde_el_archivo(): void
    {
        $n = $this->novedad(['categoria' => 'lost_found', 'ubicacion' => 'ALBERCA'], null, $this->agente);
        $this->actingAs($this->agente)->get('/lost-found')->assertOk()->assertSee('Sin artículos capturados aún')->assertSee('Agregar el objeto aquí');

        $this->actingAs($this->agente)->post('/lost-found/objetos', ['novedad_id' => $n->id, 'lf_objeto' => 'lentes de sol'])->assertSessionHasNoErrors();
        $n = $this->buscar($n->id)->load('articulos');
        $this->assertSame(['LENTES DE SOL'], $n->articulos->pluck('objeto')->all());
        $this->assertStringContainsString('Registró el objeto encontrado LF-', $n->notas->last()->texto);
        $this->actingAs($this->agente)->get('/lost-found')->assertDontSee('Sin artículos capturados aún');

        // Solo a tickets de Lost & Found abiertos y de su alcance
        $otro = $this->novedad(['categoria' => 'incidente_general'], null, $this->agente);
        $this->actingAs($this->agente)->post('/lost-found/objetos', ['novedad_id' => $otro->id, 'lf_objeto' => 'X'])
            ->assertSessionHasErrors(['objeto' => 'Solo se agregan objetos a un ticket de Lost & Found que siga abierto.']);
        $ajeno = $this->novedad(['categoria' => 'lost_found'], $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->agente)->post('/lost-found/objetos', ['novedad_id' => $ajeno->id, 'lf_objeto' => 'X'])->assertNotFound();
    }

    // ------------------------------------------------------------ Robo: una sola ficha

    public function test_robo_abre_el_mismo_expediente_de_la_bitacora_y_regresa_a_robo(): void
    {
        $robo = $this->novedad(['categoria' => 'robo'], null, $this->admin);
        $this->enEmpresa(fn () => RoboDetalle::create(['novedad_id' => $robo->id]));

        $this->actingAs($this->admin)->get('/robos')->assertOk()
            ->assertSee(route('novedades.index', ['abrir' => $robo->id, 'volver' => 'robo']));
        $this->actingAs($this->admin)->get('/novedades?abrir='.$robo->id.'&volver=robo')->assertOk()
            ->assertSee('Volver a Seguimiento de robos')->assertSee('name="_volver" value="robo"', false);

        $this->actingAs($this->admin)->put("/novedades/{$robo->id}", $this->expediente($robo, ['_volver' => 'robo', 'nueva_nota' => 'Se revisaron cámaras']))
            ->assertSessionHasNoErrors()->assertRedirect(route('robo.index').'#robo-'.$robo->id);
        // Sin «volver», como siempre
        $this->actingAs($this->admin)->put("/novedades/{$robo->id}", $this->expediente($robo))->assertRedirect(route('novedades.index').'#novedad-'.$robo->id);
    }

    // ------------------------------------------------------------ Recorridos de PC

    private function recorridoConFalla(): RecorridoPc
    {
        $equipo = $this->enEmpresa(function () {
            $e = new EquipoPc(['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-01']);
            $e->forceFill(['activo' => true])->save();

            return $e;
        });
        $this->actingAs($this->agente)->post('/recorridos-pc', ['sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => RecorridoPc::orderByDesc('id')->firstOrFail());
        $this->actingAs($this->agente)->post("/recorridos-pc/{$r->id}/revisiones", [
            '_punto' => 'equipo', 'equipo_pc_id' => $equipo->id, 'criterios' => [], 'observaciones' => 'Despresurizado',
        ])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => RecorridoPc::findOrFail($r->id));
    }

    public function test_el_hallazgo_de_recorrido_avisa_al_jefe_y_por_omision_sigue_siendo_siniestro(): void
    {
        $r = $this->recorridoConFalla();
        $ticket = $this->buscar($r->novedad_id);
        $this->assertSame('proteccion_civil', $ticket->categoria);

        $aviso = $this->avisos($this->jefe, 'recorrido_pc_hallazgo')->sole();
        $this->assertSame("Recorrido {$r->folio()} encontró una falla", $aviso->titulo);
        $this->assertStringContainsString('EXT-01', $aviso->texto);
        $this->assertCount(0, $this->avisos($this->agente, 'recorrido_pc_hallazgo'), 'Quien recorre no se avisa a sí mismo');

        $this->actingAs($this->agente)->put("/recorridos-pc/{$r->id}", ['finalizar' => '1'])->assertSessionHasNoErrors();
        $this->assertSame("Recorrido {$r->folio()} terminó con 1 hallazgo", $this->avisos($this->jefe, 'recorrido_pc_hallazgo')->last()->titulo);
    }

    public function test_la_empresa_puede_abrir_el_hallazgo_como_mantenimiento_de_equipo_pc(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['categoria_hallazgo_pc' => 'mantenimiento_pc'])->assertSessionHasNoErrors();
        $r = $this->recorridoConFalla();
        $ticket = $this->buscar($r->novedad_id);
        $this->assertSame('mantenimiento_pc', $ticket->categoria);
        $this->assertSame('Mantenimiento de equipo PC', $ticket->etiquetaCategoria());

        $this->actingAs($this->admin)->get('/novedades?abrir='.$ticket->id)->assertOk()
            ->assertSee('Detalle: Mantenimiento de equipo PC')->assertSee('EXT-01 (Extintor)');
        $this->actingAs($this->admin)->get('/novedades/'.$ticket->id.'/imprimir')->assertOk()->assertSee('Detalle: Mantenimiento de equipo PC');
        // No se elige a mano al despachar
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['categoria' => 'mantenimiento_pc']))->assertSessionHasErrors('categoria');
        // Se puede guardar y cerrar como cualquier ticket
        $this->actingAs($this->admin)->put("/novedades/{$ticket->id}", $this->expediente($ticket, ['estatus' => 'resuelto', 'resolucion' => 'Se recargó el extintor.']))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------ Nombre del menú

    public function test_el_nombre_del_menu_es_configurable_sin_cambiar_rutas_ni_permisos(): void
    {
        $this->actingAs($this->agente)->get('/novedades')->assertOk()->assertSee('Despacho y Novedades');
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Bitácora de novedades')->assertSee('Nombre en el menú');

        $this->actingAs($this->admin)->put('/configuracion/novedades', ['nombre_menu' => ' Incidentes ', 'categoria_hallazgo_pc' => 'proteccion_civil'])
            ->assertSessionHasNoErrors()->assertRedirect(route('configuracion.index').'#novedades');
        $this->assertTrue(Auditoria::where('evento', 'novedades.configurado')->exists());

        app()->forgetInstance(ConstructorMenu::class); // en la plataforma cada petición arma su menú
        $pagina = $this->actingAs($this->agente)->get('/novedades')->assertOk()->getContent();
        $this->assertStringContainsString('<h1>Incidentes</h1>', $pagina);
        $this->assertMatchesRegularExpression('/barra-inferior-item[^>]*>\s*<i[^>]*><\/i>\s*<span>Incidentes<\/span>/u', $pagina);

        // Volver a «Novedades» (vacío) lo deja como siempre
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['nombre_menu' => '', 'categoria_hallazgo_pc' => 'proteccion_civil'])->assertSessionHasNoErrors();
        app()->forgetInstance(ConstructorMenu::class);
        $this->actingAs($this->agente)->get('/novedades')->assertSee('Despacho y Novedades')->assertSee('<span>Novedades</span>', false);

        // Solo con «editar» de Configuración en toda la empresa; nombres raros no
        $this->actingAs($this->jefe)->put('/configuracion/novedades', ['nombre_menu' => 'X', 'categoria_hallazgo_pc' => 'proteccion_civil'])->assertForbidden();
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['nombre_menu' => '<b>x</b>', 'categoria_hallazgo_pc' => 'proteccion_civil'])->assertSessionHasErrors('nombre_menu');
    }

    // ------------------------------------------------------------ Pases de salida que tardan

    private function pase(User $director, User $gerente, array $espera = []): PaseSalida
    {
        Storage::fake('local');
        $pasos = [
            ['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$director->id], 'obligatorio' => 1],
            ['nombre' => 'Gerencia', 'tipo' => 'usuarios', 'usuarios' => [$gerente->id], 'obligatorio' => 1],
        ];
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos, 'rechazo' => 'inicio', 'espera' => $espera])->assertSessionHasNoErrors();
        $colaborador = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1007', 'nombre' => 'Mariana', 'apellido_paterno' => 'López', 'sede_id' => $this->centro->id, 'activo' => true]));
        $this->actingAs($this->admin)->post('/pases-salida', [
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => $colaborador->id, 'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id,
            'fecha_salida_programada' => now()->format('Y-m-d'), 'fecha_tentativa_regreso' => now()->addDays(7)->format('Y-m-d'),
            'articulos' => [['cantidad' => 1, 'equipo' => 'Laptop']],
        ])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => PaseSalida::orderByDesc('id')->firstOrFail());
    }

    public function test_el_pase_que_espera_firma_muestra_su_reloj_y_por_omision_no_avisa_ni_cambia_de_color(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $gerente = $this->crearUsuario($this->empresa, 'Director');
        $pase = $this->pase($director, $gerente);
        $this->travel(5)->hours();

        $this->entrar($this->admin)->get('/pases-salida')->assertOk()->assertSee('Esperando firma desde hace 5 h')->assertDontSee('reloj-pase espera-', false);
        $this->entrar($this->admin)->get("/pases-salida/{$pase->id}")->assertOk()->assertSee('Esperando firma desde hace 5 h');
        $this->travel(10)->days();
        $this->artisan('plataforma:escalar-pases')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 0.')->assertSuccessful();
        $this->assertCount(0, $this->avisos($director, 'pase_firma_recordatorio'));
    }

    public function test_con_horas_configuradas_recuerda_al_firmante_y_avisa_al_siguiente_nivel_una_sola_vez(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $director->forceFill(['name' => 'Diego Contralor'])->save();
        $gerente = $this->crearUsuario($this->empresa, 'Director');
        $gerente->forceFill(['name' => 'Gema Gerente'])->save();
        $pase = $this->pase($director, $gerente, ['recordar' => '4', 'recordar_unidad' => 'horas', 'escalar' => '1', 'escalar_unidad' => 'dias']);
        $this->enEmpresa(fn () => $this->assertSame(['recordar' => 4, 'escalar' => 24], app(CircuitoPasesSalida::class)->espera()));
        $this->entrar($this->admin)->get('/pases-salida/circuito')->assertSee('Firmas que tardan')->assertSee('value="4"', false);

        $this->travel(2)->hours();
        $this->artisan('plataforma:escalar-pases --si-toca')->doesntExpectOutputToContain('Recordatorios')->assertSuccessful();
        $this->entrar($this->admin)->get('/pases-salida')->assertSee('reloj-pase espera-normal', false);

        $this->travel(3)->hours();
        $this->artisan('plataforma:escalar-pases')->expectsOutputToContain('Recordatorios enviados: 1. Escalamientos: 0.')->assertSuccessful();
        $this->artisan('plataforma:escalar-pases')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 0.')->assertSuccessful();
        $recordatorio = $this->avisos($director, 'pase_firma_recordatorio')->sole();
        $this->assertSame("Recordatorio: el pase {$pase->folio} espera tu firma", $recordatorio->titulo);
        $this->assertCount(0, $this->avisos($gerente, 'pase_firma_recordatorio'));
        $this->entrar($this->admin)->get('/pases-salida')->assertSee('reloj-pase espera-ambar', false);

        $this->travel(1)->days();
        app(EsperaPasesSalida::class)->escalar();
        app(EsperaPasesSalida::class)->escalar();
        $escalada = $this->avisos($gerente, 'pase_firma_escalada')->sole();
        $this->assertSame("Firma atrasada: pase {$pase->folio}", $escalada->titulo);
        $this->assertStringContainsString('«Contraloría»', $escalada->texto);
        $this->entrar($this->admin)->get("/pases-salida/{$pase->id}")->assertSee('reloj-pase grande espera-roja', false)->assertSee('Se avisó a: Gema Gerente');
        $this->assertTrue(Auditoria::where('evento', 'pases_salida.recordatorio')->where('auditable_id', $pase->id)->exists());
        $this->assertTrue(Auditoria::where('evento', 'pases_salida.escalada')->where('auditable_id', $pase->id)->exists());
        $this->assertTrue(Auditoria::where('evento', 'pases_salida.tiempos_espera')->exists());

        // Al firmar, el recordatorio pierde su botón y el reloj del paso siguiente empieza de cero
        $a = $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($pase->fresh(['aprobaciones'])));
        $this->entrar($director)->post("/pases-salida/{$pase->id}/firmas", ['paso' => 'aprobacion', 'aprobacion_id' => $a->id, 'firma_modo' => 'nueva', 'firma' => self::firmaJpeg()])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->avisos($director, 'pase_firma_recordatorio')->sole()->acciones);
        $this->entrar($this->admin)->get("/pases-salida/{$pase->id}")->assertSee('Esperando firma desde hace 1 min');
        $this->assertSame(PaseSalidaAprobacion::PENDIENTE, $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($pase->fresh(['aprobaciones']))->estado));
    }

    public function test_tiempos_de_espera_invalidos_se_explican(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $pasos = [['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$director->id], 'obligatorio' => 1]];
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos, 'rechazo' => 'inicio', 'espera' => ['recordar' => '2', 'recordar_unidad' => 'dias', 'escalar' => '24', 'escalar_unidad' => 'horas']])
            ->assertSessionHasErrors(['espera.escalar' => 'El aviso al siguiente nivel debe ir después del recordatorio (más horas o días).']);
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos, 'rechazo' => 'inicio', 'espera' => ['recordar' => 'dos']])
            ->assertSessionHasErrors('espera.recordar');
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos, 'rechazo' => 'inicio', 'espera' => ['recordar' => '31', 'recordar_unidad' => 'dias']])
            ->assertSessionHasErrors(['espera.recordar' => 'En «Recordar a quien debe firmar» el máximo es 30 días.']);
        $this->assertSame([], $this->enEmpresa(fn () => Empresa::find($this->empresa->id)->preferencias['pases_salida']['espera'] ?? []));
    }
}
