<?php

namespace Tests\Feature\Reservas;

use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\ReservaOrden;
use App\Models\ReservaServicio;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\Autorizador;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Services\Reservas\EsperaReservas;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Reservas de áreas (docs/tecnico/reservas.md): disponibilidad con montaje y
 * desmontaje, aforo por montaje, horario, costo (interno, cliente, cortesía),
 * aprobaciones en paralelo (área + departamentos) con rechazo y cambio,
 * confirmación inmediata, órdenes de servicio y «Listo», modificar y
 * cancelar, permisos y alcances, otra empresa, recordatorio y escalamiento.
 *
 * Reloj fijo: lunes 2 de noviembre de 2026, 9:00 en la Ciudad de México.
 */
class ReservasDeAreasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIERCOLES = '2026-11-04';

    private const SABADO = '2026-11-07';

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rita;

    private User $julieta;

    private User $sergio;

    private User $julia;

    private User $director;

    private User $agentePlaya;

    private User $solicitante;

    private ReservaArea $salon;

    private ReservaArea $sala;

    private Departamento $cocina;

    private Departamento $sistemas;

    /** @var array<string, int> */
    private array $servicios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rita = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->julieta = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->sergio = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->julia = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->solicitante = $this->crearUsuario($this->empresa, 'Solicitante', $this->centro);

        $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $this->cocina = Departamento::create(['nombre' => 'Cocina']);
            $this->sistemas = Departamento::create(['nombre' => 'Sistemas']);
            DepartamentoResponsable::create(['departamento_id' => $this->cocina->id, 'user_id' => $this->sergio->id, 'es_suplente' => false, 'sede_id' => null]);
            DepartamentoResponsable::create(['departamento_id' => $this->sistemas->id, 'user_id' => $this->julia->id, 'es_suplente' => false, 'sede_id' => null]);
            app(AjustesReservas::class)->guardar($this->admin, $this->empresa->refresh(), [
                'responsable_id' => $this->julieta->id, 'suplente_id' => $this->admin->id, 'iva_aplica' => '1', 'iva_porcentaje' => 16, 'recordar_horas' => 4, 'escalar_horas' => 24,
            ]);
            $catalogos = app(CatalogosReservas::class);
            $catalogos->asegurar();
            $espacios = app(AdministradorEspacios::class);
            $torre = $espacios->crear($this->admin, $this->centro, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $salon = $espacios->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Salón Maya'], false);
            $sala = $espacios->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala Ceiba'], false);
            $montaje = fn (string $n) => ReservaMontaje::where('nombre', $n)->value('id');
            $this->salon = $catalogos->marcarReservable($this->admin, $salon);
            $catalogos->guardarArea($this->admin, $this->salon, [
                'aforo_maximo' => 120, 'montaje_minutos' => 60, 'desmontaje_minutos' => 30,
                'tarifa_hora' => 1000, 'tarifa_evento' => 10000,
                'montajes' => [$montaje('Auditorio') => ['usar' => '1', 'aforo' => 120], $montaje('Herradura (U)') => ['usar' => '1', 'aforo' => 30]],
            ]);
            $this->sala = $catalogos->marcarReservable($this->admin, $sala);
            $semana = collect(range(1, 5))->mapWithKeys(fn ($d) => [$d => ['abierto' => '1', 'desde' => '08:00', 'hasta' => '20:00']])->all();
            $catalogos->guardarArea($this->admin, $this->sala, ['aforo_maximo' => 12, 'confirmacion_inmediata' => '1', 'con_horario' => '1', 'horario' => $semana]);
            foreach ([
                'coffee' => ['Coffee break', 'alimentos', 'persona', 80, $this->cocina->id, true],
                'proyector' => ['Proyector', 'audio_video', 'evento', 500, $this->sistemas->id, true],
                'microfono' => ['Micrófono', 'audio_video', 'pieza', 100, $this->sistemas->id, false],
            ] as $clave => [$nombre, $categoria, $unidad, $costo, $depto, $aprueba]) {
                $this->servicios[$clave] = $catalogos->guardarCatalogo($this->admin, 'servicios', null, ['nombre' => $nombre, 'categoria' => $categoria,
                    'unidad' => $unidad, 'costo' => $costo, 'departamento_id' => $depto, 'requiere_aprobacion' => $aprueba ? '1' : '0'])->id;
            }
        });
        $this->salon->refresh();
        $this->sala->refresh();
        auth()->forgetGuards();
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @param array<string, mixed> $datos */
    private function reservar(User $quien, array $datos = []): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($quien)->post('/reservas', $datos + [
            'reserva_area_id' => $this->salon->id, 'fecha' => self::MIERCOLES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Junta de jefes', 'asistentes' => 20, 'para_cliente' => '0',
        ]);
    }

    private function ultima(): Reserva
    {
        return $this->enEmpresa(fn () => Reserva::with(['aprobaciones', 'ordenes', 'items'])->latest('id')->firstOrFail());
    }

    private function aprobacion(Reserva $r, string $clave): ReservaAprobacion
    {
        return $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $r->id)->where('vigente', true)->get()->first(fn ($a) => $a->clave() === $clave));
    }

    private function responder(User $quien, ReservaAprobacion $a, string $respuesta, ?string $comentario = null): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($quien)->post("/reservas/aprobaciones/{$a->id}/responder", array_filter(['respuesta' => $respuesta, 'comentario' => $comentario]));
    }

    /** Reserva con coffee break (Cocina) y proyector (Sistemas). */
    private function reservaConServicios(array $extra = []): Reserva
    {
        $this->reservar($this->rita, $extra + ['servicios' => [
            $this->servicios['coffee'] => ['cantidad' => 20, 'notas' => 'A las 11:00'], $this->servicios['proyector'] => ['cantidad' => 1],
        ]])->assertSessionHasNoErrors();

        return $this->ultima();
    }

    private function aprobarTodo(Reserva $r): void
    {
        $this->responder($this->julieta, $this->aprobacion($r, 'area'), 'aprobar')->assertSessionHasNoErrors();
        $this->responder($this->sergio, $this->aprobacion($r, 'departamento:'.$this->cocina->id), 'aprobar')->assertSessionHasNoErrors();
        $this->responder($this->julia, $this->aprobacion($r, 'departamento:'.$this->sistemas->id), 'aprobar')->assertSessionHasNoErrors();
    }

    private function notificaciones(User $u, string $tipo): int
    {
        return Notificacion::withoutGlobalScopes()->where('user_id', $u->id)->where('tipo', $tipo)->count();
    }

    // ------------------------------------------------------------ Configuración

    public function test_el_area_sale_de_zonas_y_edificios_y_se_configura_en_pantalla(): void
    {
        $nuevo = $this->enEmpresa(fn () => app(AdministradorEspacios::class)->crear($this->admin, $this->centro,
            Espacio::where('nombre', 'Torre A')->first(), Espacio::AREA_ESPECIFICA, ['nombre' => 'Terraza Sol'], false));

        $this->actingAs($this->admin)->get('/reservas/configuracion')->assertOk()->assertSee('Agregar área reservable')->assertDontSee('Terraza Sol');
        // La lista se pide al abrir el diálogo
        $this->get(route('reservas.configuracion.opciones'))->assertOk()->assertSee('Terraza Sol');
        $this->post('/reservas/configuracion/areas', ['espacio_id' => $nuevo->id])->assertRedirect();
        $area = $this->enEmpresa(fn () => ReservaArea::where('espacio_id', $nuevo->id)->firstOrFail());
        $this->assertTrue($area->reservable);
        $this->assertSame($this->centro->id, (int) $area->sede_id);

        $coctel = $this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Cóctel')->value('id'));
        $this->put("/reservas/configuracion/areas/{$area->id}", [
            'aforo_maximo' => 80, 'montaje_minutos' => 45, 'desmontaje_minutos' => 15, 'tarifa_dia' => 7000, 'responsable_id' => $this->sergio->id,
            'con_horario' => '1', 'horario' => [6 => ['abierto' => '1', 'desde' => '16:00', 'hasta' => '23:00']],
            'montajes' => [$coctel => ['usar' => '1', 'aforo' => 80]],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $area->refresh();
        $this->assertSame(80, $area->aforo_maximo);
        $this->assertSame([['16:00', '23:00']], $area->horario['6']);
        $this->assertSame([], $area->horario['1']);
        $this->assertSame(['dia' => 7000.0], $area->tarifas());
        $this->assertSame($this->sergio->id, (int) $area->responsable_id);
        $this->assertSame(80, $this->enEmpresa(fn () => $area->montajes()->first()->pivot->aforo));

        // Un montaje con más aforo que el área no se guarda
        $this->put("/reservas/configuracion/areas/{$area->id}", ['aforo_maximo' => 50, 'montajes' => [$coctel => ['usar' => '1', 'aforo' => 80]]])
            ->assertSessionHasErrors('montajes');

        // Dar de baja: ya no se ofrece para reservar
        $this->patch("/reservas/configuracion/areas/{$area->id}/estado", ['reservable' => 0])->assertRedirect();
        $this->assertFalse($area->refresh()->reservable);
        $this->reservar($this->rita, ['reserva_area_id' => $area->id])->assertSessionHasErrors('reserva_area_id');
    }

    public function test_catalogos_y_ajustes_piden_alcance_de_empresa(): void
    {
        $this->actingAs($this->admin)->post('/reservas/configuracion/catalogo', ['catalogo' => 'servicios',
            'nombre' => 'Bocinas', 'categoria' => 'audio_video', 'unidad' => 'pieza', 'costo' => '150', 'departamento_id' => $this->sistemas->id, 'requiere_aprobacion' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->enEmpresa(fn () => ReservaServicio::where('nombre', 'Bocinas')->where('departamento_id', $this->sistemas->id)->exists()));
        $this->post('/reservas/configuracion/catalogo', ['catalogo' => 'servicios', 'nombre' => 'bocinas'])->assertSessionHasErrors('nombre');
        $this->post('/reservas/configuracion/catalogo', ['catalogo' => 'tipos', 'nombre' => 'Capacitación'])->assertSessionHasNoErrors();

        // Jefe de departamento: no configura
        $this->flushSession();
        $this->actingAs($this->julieta)->get('/reservas/configuracion')->assertForbidden();
        $this->post('/reservas/configuracion/catalogo', ['catalogo' => 'tipos', 'nombre' => 'Otra'])->assertForbidden();

        // Ajustes desde Configuración (mismo formulario)
        $this->flushSession();
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Responsable general de reservas')->assertSee('Configurar en Reservas de áreas');
        $this->put('/reservas/ajustes', ['desde' => 'configuracion', 'responsable_id' => $this->sergio->id, 'suplente_id' => $this->sergio->id, 'recordar_horas' => 4, 'escalar_horas' => 24])
            ->assertSessionHasErrors('suplente_id');
        $this->put('/reservas/ajustes', ['desde' => 'configuracion', 'responsable_id' => $this->sergio->id, 'recordar_horas' => 6, 'escalar_horas' => 12, 'iva_porcentaje' => 8, 'iva_aplica' => '1'])
            ->assertRedirect(route('configuracion.index').'#sec-reservas');
        $ajustes = app(AjustesReservas::class)->de($this->empresa->id);
        $this->assertSame($this->sergio->id, $ajustes['responsable_id']);
        $this->assertSame(8.0, $ajustes['iva_porcentaje']);
        $this->assertSame(6, $ajustes['recordar_horas']);
    }

    // ----------------------------------------------------------- Disponibilidad

    public function test_no_se_cruzan_reservas_contando_montaje_y_desmontaje(): void
    {
        // Salón: 60 min de montaje y 30 de desmontaje. La primera ocupa de 9:00 a 12:30
        $this->reservar($this->rita)->assertSessionHasNoErrors();
        $primera = $this->ultima();
        $this->assertSame('2026-11-04 15:00:00', $primera->bloqueo_desde->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 18:30:00', $primera->bloqueo_hasta->format('Y-m-d H:i:s'));

        // 12:15 a 13:00 choca con el desmontaje
        $this->reservar($this->admin, ['hora_inicio' => '12:15', 'hora_fin' => '13:00'])
            ->assertSessionHasErrors(['hora_inicio' => 'El área está ocupada de 09:00 a 12:30 (incluye montaje y desmontaje). Elige otro horario u otra área.']);
        // 13:00 a 14:00: su montaje empieza a las 12:00 y choca
        $this->reservar($this->admin, ['hora_inicio' => '13:00', 'hora_fin' => '14:00'])->assertSessionHasErrors('hora_inicio');
        // 13:30 a 15:00: montaje desde las 12:30, justo cuando termina el desmontaje
        $this->reservar($this->admin, ['hora_inicio' => '13:30', 'hora_fin' => '15:00'])->assertSessionHasNoErrors();
        // En otra área a la misma hora, sin problema
        $this->reservar($this->admin, ['reserva_area_id' => $this->sala->id, 'asistentes' => 5])->assertSessionHasNoErrors();

        // La pantalla de disponibilidad lo muestra
        $this->flushSession();
        $this->actingAs($this->rita)->getJson('/reservas/disponibilidad?area='.$this->salon->id.'&fecha='.self::MIERCOLES)
            ->assertOk()->assertJsonPath('ocupado.0.desde', '09:00')->assertJsonPath('ocupado.0.hasta', '12:30')->assertJsonPath('ocupado.0.inicio', '10:00');

        // Una reserva cancelada ya no ocupa
        $this->post("/reservas/{$primera->id}/cancelar", ['motivo' => 'Se pospuso'])->assertSessionHasNoErrors();
        $this->reservar($this->admin, ['hora_inicio' => '11:00', 'hora_fin' => '12:00'])->assertSessionHasNoErrors();

        // En el pasado no
        $this->reservar($this->rita, ['fecha' => '2026-11-01'])->assertSessionHasErrors('hora_inicio');
        $this->reservar($this->rita, ['hora_inicio' => '12:00', 'hora_fin' => '11:00', 'fecha' => '2026-11-05'])->assertSessionHasErrors('hora_fin');
    }

    public function test_aforo_por_montaje_y_horario_permitido(): void
    {
        $herradura = $this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Herradura (U)')->value('id'));
        $this->reservar($this->rita, ['asistentes' => 40, 'montaje_id' => $herradura])
            ->assertSessionHasErrors(['asistentes' => 'Son más personas que el aforo del montaje Herradura (U): máximo 30.']);
        $this->reservar($this->rita, ['asistentes' => 130])->assertSessionHasErrors(['asistentes' => 'Son más personas que el aforo del área: máximo 120.']);
        $this->reservar($this->rita, ['asistentes' => 40])->assertSessionHasNoErrors();
        $cocteles = $this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Cóctel')->value('id'));
        $this->reservar($this->rita, ['montaje_id' => $cocteles, 'fecha' => '2026-11-05'])->assertSessionHasErrors('montaje_id');

        // Sala: lunes a viernes de 8:00 a 20:00
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 5, 'fecha' => self::SABADO])
            ->assertSessionHasErrors(['hora_inicio' => 'Fuera del horario del área. El sábado esta área no se reserva.']);
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 5, 'hora_inicio' => '19:00', 'hora_fin' => '21:00'])
            ->assertSessionHasErrors(['hora_inicio' => 'Fuera del horario del área. El miércoles se reserva de 08:00 a 20:00.']);
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 5, 'hora_inicio' => '18:00', 'hora_fin' => '20:00'])->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------------------- Costo

    public function test_uso_interno_sin_costo_y_cliente_con_costo_y_cortesia(): void
    {
        $interna = $this->reservaConServicios();
        $this->assertFalse($interna->para_cliente);
        $this->assertSame('0.00', $interna->total);
        $this->assertSame(['0.00', '0.00'], $interna->items->pluck('importe')->all());

        // Cliente: tarifa por evento 10,000 + coffee 20 × 80 + proyector 500 = 12,100 + IVA 16 % = 14,036
        $this->reservar($this->rita, [
            'fecha' => '2026-11-05', 'para_cliente' => '1', 'tarifa' => 'evento', 'cliente_nombre' => 'Laura Castillo', 'cliente_empresa' => 'Agencias del Caribe',
            'servicios' => [$this->servicios['coffee'] => ['cantidad' => 20], $this->servicios['proyector'] => ['cantidad' => 1]],
        ])->assertSessionHasNoErrors();
        $cliente = $this->ultima();
        $this->assertSame('10000.00', $cliente->importe_area);
        $this->assertSame('2100.00', $cliente->importe_servicios);
        $this->assertSame('1936.00', $cliente->iva);
        $this->assertSame('14036.00', $cliente->total);

        // Por hora: cada hora o fracción (10:00 a 12:30 = 3 h)
        $this->reservar($this->rita, ['fecha' => '2026-11-06', 'hora_fin' => '12:30', 'para_cliente' => '1', 'tarifa' => 'hora', 'cliente_nombre' => 'Mario Ruiz'])->assertSessionHasNoErrors();
        $this->assertSame('3000.00', $this->ultima()->importe_area);

        // Sin nombre del cliente no se guarda
        $this->reservar($this->rita, ['fecha' => '2026-11-09', 'para_cliente' => '1'])->assertSessionHasErrors('cliente_nombre');

        // Ajustar costo: solo con reservas.aprobar (Rita no), con motivo
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$cliente->id}/costo", ['cortesia' => '1', 'ajuste_motivo' => 'Cliente frecuente'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin)->put("/reservas/{$cliente->id}/costo", ['descuento' => '1000'])->assertSessionHasErrors('ajuste_motivo');
        $this->put("/reservas/{$cliente->id}/costo", ['descuento' => '1000', 'ajuste_motivo' => 'Cliente frecuente'])->assertSessionHasNoErrors();
        $cliente->refresh();
        $this->assertSame('1000.00', $cliente->descuento);
        $this->assertSame('12876.00', $cliente->total); // (12,100 − 1,000) × 1.16
        $this->put("/reservas/{$cliente->id}/costo", ['cortesia' => '1', 'ajuste_motivo' => 'Cortesía de la gerencia'])->assertSessionHasNoErrors();
        $cliente->refresh();
        $this->assertTrue($cliente->cortesia);
        $this->assertSame('0.00', $cliente->total);
        // Importe del área fijado por evento
        $this->put("/reservas/{$cliente->id}/costo", ['importe_area_manual' => '8000', 'ajuste_motivo' => 'Precio de temporada'])->assertSessionHasNoErrors();
        $this->assertSame('11716.00', $cliente->refresh()->total); // (8,000 + 2,100) × 1.16
        // No pide aprobación de nuevo
        $this->assertSame(3, $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $cliente->id)->where('vigente', true)->where('estado', 'pendiente')->count()));
        // La hoja muestra el desglose
        $this->get("/reservas/{$cliente->id}/imprimir")->assertOk()->assertSee('Orden de evento / Cotización')->assertSee('$11,716.00 MXN')->assertSee('Precio de temporada');
    }

    // ------------------------------------------------------------ Aprobaciones

    public function test_aprobaciones_en_paralelo_del_area_y_de_cada_departamento(): void
    {
        $r = $this->reservaConServicios(['servicios' => [
            $this->servicios['coffee'] => ['cantidad' => 20], $this->servicios['proyector'] => ['cantidad' => 1], $this->servicios['microfono'] => ['cantidad' => 2],
        ]]);
        $this->assertSame(Reserva::POR_APROBAR, $r->estado);
        $claves = $r->aprobaciones->map(fn ($a) => $a->clave())->sort()->values()->all();
        $this->assertSame(['area', 'departamento:'.$this->cocina->id, 'departamento:'.$this->sistemas->id], $claves);

        // Cada quien recibe su aviso con el botón Aprobar y lo ve en Mis pendientes
        foreach ([$this->julieta, $this->sergio, $this->julia] as $quien) {
            $this->assertSame(1, $this->notificaciones($quien, 'reserva_por_aprobar'));
            $this->flushSession();
            $pend = $this->enEmpresa(fn () => collect(app(MisPendientes::class)->para($quien->fresh())['items'])->firstWhere('clave', 'reservas_aprobar'));
            $this->assertSame(1, $pend['total']);
        }
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->sergio->id)->where('tipo', 'reserva_por_aprobar')->first();
        $this->assertSame('Aprobar', $aviso->acciones[0]['etiqueta']);
        $this->assertStringContainsString('Coffee break × 20', $aviso->texto);
        // Sergio (Cocina) no puede responder lo de Sistemas
        $this->responder($this->sergio, $this->aprobacion($r, 'departamento:'.$this->sistemas->id), 'aprobar')->assertSessionHasErrors('respuesta');
        $this->assertSame('pendiente', $this->aprobacion($r, 'departamento:'.$this->sistemas->id)->estado);
        // Sin permisos de reservas: 403
        $this->responder($this->crearUsuario($this->empresa), $this->aprobacion($r, 'departamento:'.$this->sistemas->id), 'aprobar')->assertForbidden();
        // Bandeja «Por aprobar»
        $this->flushSession();
        $this->actingAs($this->julia)->get('/reservas/por-aprobar')->assertOk()->assertSee('Junta de jefes')->assertSee('Piden: Proyector');

        $this->responder($this->julieta, $this->aprobacion($r, 'area'), 'aprobar')->assertSessionHas('ok');
        $this->responder($this->sergio, $this->aprobacion($r, 'departamento:'.$this->cocina->id), 'aprobar');
        $this->assertSame(Reserva::POR_APROBAR, $r->refresh()->estado);
        $this->assertSame(0, $this->enEmpresa(fn () => ReservaOrden::count()));
        $this->responder($this->julia, $this->aprobacion($r, 'departamento:'.$this->sistemas->id), 'aprobar')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'quedó confirmada'));
        $this->assertSame(Reserva::CONFIRMADA, $r->refresh()->estado);
        $this->assertSame(1, $this->notificaciones($this->rita, 'reserva_confirmada'));
        // Órdenes: Cocina y Sistemas (el micrófono no pide aprobación pero sí va en la orden de Sistemas)
        $ordenes = $this->enEmpresa(fn () => ReservaOrden::where('reserva_id', $r->id)->pluck('estado', 'departamento_id')->all());
        $this->assertSame([$this->cocina->id => 'pendiente', $this->sistemas->id => 'pendiente'], $ordenes);
        $this->assertSame(1, $this->notificaciones($this->sergio, 'reserva_orden'));
        // Ya respondida: otra respuesta no cuenta
        $this->responder($this->admin, $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $r->id)->where('tipo', 'area')->first()), 'rechazar', 'Tarde')
            ->assertSessionHasErrors('respuesta');
        // Quien tiene reservas.aprobar responde por otros (Administrador)
        $otra = $this->reservaConServicios(['fecha' => '2026-11-05']);
        $this->responder($this->admin, $this->aprobacion($otra, 'departamento:'.$this->cocina->id), 'aprobar')->assertSessionHasNoErrors();
    }

    public function test_un_rechazo_de_un_departamento_rechaza_la_reserva_y_libera_el_area(): void
    {
        $r = $this->reservaConServicios();
        $this->responder($this->julieta, $this->aprobacion($r, 'area'), 'aprobar');
        $this->responder($this->julia, $this->aprobacion($r, 'departamento:'.$this->sistemas->id), 'rechazar')->assertSessionHasErrors('comentario');
        $this->responder($this->julia, $this->aprobacion($r, 'departamento:'.$this->sistemas->id), 'rechazar', 'No hay proyector disponible ese día')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'rechazada'));
        $r->refresh();
        $this->assertSame(Reserva::RECHAZADA, $r->estado);
        // La de Cocina ya no aplica y pierde sus botones
        $cocina = $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $r->id)->where('departamento_id', $this->cocina->id)->first());
        $this->assertSame('cancelada', $cocina->estado);
        $this->assertNull(Notificacion::withoutGlobalScopes()->where('user_id', $this->sergio->id)->where('tipo', 'reserva_por_aprobar')->first()->acciones);
        $this->assertSame(1, $this->notificaciones($this->rita, 'reserva_rechazada'));
        $this->assertStringContainsString('No hay proyector', Notificacion::withoutGlobalScopes()->where('user_id', $this->rita->id)->where('tipo', 'reserva_rechazada')->value('texto'));
        // El horario quedó libre y la rechazada ya no se modifica
        $this->reservar($this->admin)->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->rita)->get("/reservas/{$r->id}/editar")->assertRedirect("/reservas/{$r->id}");
    }

    public function test_pedir_un_cambio_y_modificar_solo_vuelve_a_pedir_lo_que_cambio(): void
    {
        $r = $this->reservaConServicios();
        $this->responder($this->julieta, $this->aprobacion($r, 'area'), 'aprobar');
        $sistemasAntes = $this->aprobacion($r, 'departamento:'.$this->sistemas->id);
        $this->responder($this->sergio, $this->aprobacion($r, 'departamento:'.$this->cocina->id), 'cambio', 'El coffee break debe ser de 25 personas mínimo')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'regresó a quien la hizo'));
        $this->assertSame(Reserva::CAMBIO_PEDIDO, $r->refresh()->estado);
        $this->assertSame(1, $this->notificaciones($this->rita, 'reserva_cambio'));
        $this->flushSession();
        $pend = $this->enEmpresa(fn () => collect(app(MisPendientes::class)->para($this->rita->fresh())['items'])->firstWhere('clave', 'reservas_cambio'));
        $this->assertSame(1, $pend['total']);
        // Sigue ocupando el horario
        $this->reservar($this->admin)->assertSessionHasErrors('hora_inicio');

        // Rita cambia solo el coffee break
        $this->flushSession();
        $this->actingAs($this->rita)->get("/reservas/{$r->id}/editar")->assertOk()->assertSee('Modificar reserva');
        $this->put("/reservas/{$r->id}", [
            'reserva_area_id' => $this->salon->id, 'fecha' => self::MIERCOLES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'titulo' => 'Junta de jefes',
            'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['cantidad' => 25], $this->servicios['proyector'] => ['cantidad' => 1]],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'se pidió de nuevo'));
        $r->refresh();
        $this->assertSame(Reserva::POR_APROBAR, $r->estado);
        // El área conserva su aprobación; Sistemas sigue esperando la misma (sin aviso nuevo); Cocina se pide de nuevo
        $this->assertSame('aprobada', $this->aprobacion($r, 'area')->estado);
        $this->assertSame($sistemasAntes->id, $this->aprobacion($r, 'departamento:'.$this->sistemas->id)->id);
        $this->assertSame(1, $this->notificaciones($this->julia, 'reserva_por_aprobar'));
        $this->assertSame('pendiente', $this->aprobacion($r, 'departamento:'.$this->cocina->id)->estado);
        $this->assertSame(2, $this->notificaciones($this->sergio, 'reserva_por_aprobar'));
        $this->assertSame(1, $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $r->id)->where('vigente', false)->where('estado', 'cambio')->count()));
        $this->assertTrue($this->enEmpresa(fn () => $r->eventos()->where('evento', 'modificada')->where('titulo', 'like', '%servicios%')->exists()));
    }

    public function test_modificar_una_confirmada_vuelve_a_aprobacion_y_la_orden_se_reinicia(): void
    {
        $r = $this->reservaConServicios();
        $this->aprobarTodo($r);
        $this->assertSame(Reserva::CONFIRMADA, $r->refresh()->estado);
        $orden = $this->enEmpresa(fn () => ReservaOrden::where('reserva_id', $r->id)->where('departamento_id', $this->cocina->id)->first());
        $this->flushSession();
        $this->actingAs($this->sergio)->post("/reservas/ordenes/{$orden->id}/listo")->assertSessionHasNoErrors();
        $this->assertSame('lista', $orden->refresh()->estado);

        // Cambia la hora: todo se vuelve a pedir
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", [
            'reserva_area_id' => $this->salon->id, 'fecha' => self::MIERCOLES, 'hora_inicio' => '15:00', 'hora_fin' => '17:00', 'titulo' => 'Junta de jefes',
            'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['cantidad' => 20, 'notas' => 'A las 11:00'], $this->servicios['proyector'] => ['cantidad' => 1]],
        ])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame(Reserva::POR_APROBAR, $r->estado);
        $this->assertSame(3, $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $r->id)->where('vigente', true)->where('estado', 'pendiente')->count()));
        $this->aprobarTodo($r);
        $this->assertSame(Reserva::CONFIRMADA, $r->refresh()->estado);
        $this->assertSame('pendiente', $orden->refresh()->estado);
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $this->sergio->id)->where('tipo', 'reserva_orden')->where('titulo', 'like', 'Cambió la orden%')->exists());

        // Sin cambios que aprobar (solo el título): sigue confirmada
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", [
            'reserva_area_id' => $this->salon->id, 'fecha' => self::MIERCOLES, 'hora_inicio' => '15:00', 'hora_fin' => '17:00', 'titulo' => 'Junta mensual de jefes',
            'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['cantidad' => 20, 'notas' => 'A las 11:00'], $this->servicios['proyector'] => ['cantidad' => 1]],
        ])->assertSessionHas('ok', 'Cambios guardados: la reserva sigue confirmada.');
    }

    public function test_confirmacion_inmediata_sin_servicios_ni_costo(): void
    {
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 8])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'confirmada'));
        $r = $this->ultima();
        $this->assertSame(Reserva::CONFIRMADA, $r->estado);
        $this->assertCount(0, $r->aprobaciones);
        $this->assertSame(0, $this->notificaciones($this->julieta, 'reserva_por_aprobar'));

        // Con servicios ya no es inmediata: área y Cocina aprueban
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 8, 'fecha' => '2026-11-05',
            'servicios' => [$this->servicios['coffee'] => ['cantidad' => 8]]])->assertSessionHasNoErrors();
        $this->assertSame(Reserva::POR_APROBAR, $this->ultima()->estado);
        $this->assertSame(['area', 'departamento:'.$this->cocina->id], $this->ultima()->aprobaciones->map(fn ($a) => $a->clave())->all());
        // Para un cliente tampoco
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 8, 'fecha' => '2026-11-06', 'para_cliente' => '1', 'cliente_nombre' => 'Ana Ruiz'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Reserva::POR_APROBAR, $this->ultima()->estado);
    }

    // ----------------------------------------------------- Órdenes de servicio

    public function test_orden_de_servicio_listo_y_avance_del_montaje(): void
    {
        $r = $this->reservaConServicios();
        $this->aprobarTodo($r);
        $orden = fn (Departamento $d) => $this->enEmpresa(fn () => ReservaOrden::where('reserva_id', $r->id)->where('departamento_id', $d->id)->first());

        $this->flushSession();
        $this->actingAs($this->sergio)->get('/reservas/ordenes')->assertOk()->assertSee('Coffee break')->assertSee('A las 11:00')->assertDontSee('Proyector</strong>', false);
        // Sergio no atiende Sistemas
        $this->post('/reservas/ordenes/'.$orden($this->sistemas)->id.'/listo')->assertSessionHasErrors('orden');
        $this->assertSame('pendiente', $orden($this->sistemas)->estado);
        $this->post('/reservas/ordenes/'.$orden($this->cocina)->id.'/listo', ['comentario' => 'Montado en la terraza'])->assertSessionHas('ok');
        $this->assertSame('lista', $orden($this->cocina)->estado);
        $this->assertSame($this->sergio->id, (int) $orden($this->cocina)->lista_por);
        $this->assertSame(1, $this->notificaciones($this->rita, 'reserva_orden_lista'));
        // Ya lista: no se marca otra vez
        $this->post('/reservas/ordenes/'.$orden($this->cocina)->id.'/listo')->assertSessionHasErrors('orden');

        $this->flushSession();
        $this->actingAs($this->rita)->get("/reservas/{$r->id}")->assertOk()->assertSee('Montaje: 1 de 2 departamentos listos');
        $this->flushSession();
        $this->actingAs($this->julia)->post('/reservas/ordenes/'.$orden($this->sistemas)->id.'/listo', ['volver' => 'reserva'])->assertRedirect("/reservas/{$r->id}");
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $this->rita->id)->where('titulo', 'like', 'Montaje listo%')->exists());
    }

    public function test_cancelar_avisa_a_los_departamentos_y_libera_el_area(): void
    {
        $r = $this->reservaConServicios();
        $this->aprobarTodo($r);
        $this->flushSession();
        $this->actingAs($this->director)->post("/reservas/{$r->id}/cancelar", ['motivo' => 'No'])->assertSessionHasErrors('motivo');
        $this->flushSession();
        $this->actingAs($this->rita)->post("/reservas/{$r->id}/cancelar", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->post("/reservas/{$r->id}/cancelar", ['motivo' => 'Se cambió la fecha del curso'])->assertSessionHas('ok');
        $r->refresh();
        $this->assertSame(Reserva::CANCELADA, $r->estado);
        $this->assertSame(['cancelada', 'cancelada'], $this->enEmpresa(fn () => ReservaOrden::where('reserva_id', $r->id)->pluck('estado')->all()));
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $this->sergio->id)->where('titulo', 'like', 'Se canceló la reserva (Cocina)%')->exists());
        $this->reservar($this->admin)->assertSessionHasNoErrors();
        // Una cancelada no se vuelve a cancelar ni se modifica
        $this->flushSession();
        $this->actingAs($this->rita)->post("/reservas/{$r->id}/cancelar", ['motivo' => 'Otra vez'])->assertSessionHasErrors('motivo');
        $this->put("/reservas/{$r->id}", ['titulo' => 'x'])->assertSessionHasErrors('reserva');

        // Una pendiente cancelada: quien debía aprobar se entera y pierde sus botones
        $p = $this->reservaConServicios(['fecha' => '2026-11-05']);
        $this->flushSession();
        $this->actingAs($this->rita)->post("/reservas/{$p->id}/cancelar", ['motivo' => 'Ya no se necesita'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->notificaciones($this->julia, 'reserva_cancelada'));
        $this->assertSame(0, $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $p->id)->where('estado', 'pendiente')->count()));
    }

    // -------------------------------------------------------- Permisos y alcance

    public function test_permisos_alcances_otra_sede_y_otra_empresa(): void
    {
        $r = $this->reservaConServicios();

        // Director: consulta (ver) pero no reserva
        $this->flushSession();
        $this->actingAs($this->director)->get('/reservas')->assertOk()->assertSee('Salón Maya')->assertDontSee('Nueva reserva');
        $this->get('/reservas/nueva')->assertForbidden();
        $this->get("/reservas/{$r->id}")->assertOk();
        $this->post('/reservas', ['reserva_area_id' => $this->salon->id])->assertForbidden();

        // Agente de otra sede: no ve ni reserva las áreas del centro
        $this->flushSession();
        $this->actingAs($this->agentePlaya)->get('/reservas')->assertOk()->assertDontSee('Salón Maya');
        $this->reservar($this->agentePlaya)->assertSessionHasErrors(['reserva_area_id' => 'No puedes reservar en esa sede.']);
        $this->flushSession();
        $this->actingAs($this->agentePlaya)->get("/reservas/{$r->id}")->assertForbidden();

        // Solicitante (solo las suyas): reserva y ve lo suyo, no lo de otros
        $this->reservar($this->solicitante, ['reserva_area_id' => $this->sala->id, 'asistentes' => 4])->assertSessionHasNoErrors();
        $suya = $this->ultima();
        $this->flushSession();
        $this->actingAs($this->solicitante)->get("/reservas/{$suya->id}")->assertOk();
        $this->get("/reservas/{$r->id}")->assertForbidden();
        $this->get('/reservas/calendario')->assertOk()->assertSee('Ocupado');
        $this->get('/reservas/mis-reservas')->assertOk()->assertSee('Junta de jefes', false);
        // Solo puede cancelar la suya
        $this->post("/reservas/{$r->id}/cancelar", ['motivo' => 'No es mía'])->assertForbidden();

        // Sin permisos de reservas
        $nadie = $this->crearUsuario($this->empresa);
        $this->flushSession();
        $this->actingAs($nadie)->get('/reservas')->assertForbidden();
        $this->get('/reservas/informes')->assertForbidden();

        // Otra empresa: no existe para ella
        $otra = $this->crearEmpresa('Hotel Otro');
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        $this->flushSession();
        $this->actingAs($ajeno)->get("/reservas/{$r->id}")->assertNotFound();
        $this->get('/reservas')->assertOk()->assertDontSee('Salón Maya');
        $this->post('/reservas/aprobaciones/'.$this->aprobacion($r, 'area')->id.'/responder', ['respuesta' => 'aprobar'])->assertNotFound();
        $this->reservar($ajeno)->assertSessionHasErrors('reserva_area_id');
        $this->getJson('/reservas/disponibilidad?area='.$this->salon->id.'&fecha='.self::MIERCOLES)->assertNotFound();
    }

    public function test_menu_operacion_y_plantillas_de_permisos(): void
    {
        $this->actingAs($this->rita)->get('/')->assertOk()->assertSee('Reservas de áreas');
        $permisos = fn (User $u) => collect(app(Autorizador::class)->permisosEfectivos($u))->keys()->filter(fn ($k) => str_starts_with($k, 'reservas.'))->sort()->values()->all();
        $this->assertSame(['reservas.aprobar', 'reservas.configurar', 'reservas.crear', 'reservas.exportar', 'reservas.imprimir', 'reservas.ver'], $permisos($this->admin));
        $this->assertSame(['reservas.ver'], $permisos($this->director));
        $this->assertSame(['reservas.crear', 'reservas.imprimir', 'reservas.ver'], $permisos($this->rita));
        $this->assertSame(['reservas.aprobar', 'reservas.crear', 'reservas.imprimir', 'reservas.ver'], $permisos($this->julieta));
        $modulo = Modulo::where('clave', 'reservas')->with('menu')->first();
        $this->assertSame('operacion', $modulo->menu->clave);
        $this->assertSame('Reservas', $modulo->seccion_menu);
    }

    // ------------------------------------------------- Recordatorio y escalamiento

    public function test_lo_que_espera_tiene_reloj_recordatorio_y_escalamiento(): void
    {
        $r = $this->reservaConServicios(['fecha' => '2026-11-12']);
        $cocina = $this->aprobacion($r, 'departamento:'.$this->cocina->id);
        $this->artisan('plataforma:reservas-pendientes')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 0.')->assertSuccessful();

        // A las 4 h: recordatorio a quien aprueba (y al suplente del área)
        $this->travel(5)->hours();
        Cache::flush();
        $this->artisan('plataforma:reservas-pendientes')->expectsOutputToContain('Recordatorios enviados: 3. Escalamientos: 0.')->assertSuccessful();
        $this->assertSame(1, $this->notificaciones($this->sergio, 'reserva_recordatorio'));
        $this->assertSame(1, $this->notificaciones($this->admin, 'reserva_recordatorio')); // suplente general (área sin suplente propio)
        $this->artisan('plataforma:reservas-pendientes --si-toca')->doesntExpectOutputToContain('Recordatorios')->assertSuccessful();
        $this->assertSame('ambar', app(EsperaReservas::class)->reloj($cocina->refresh())['nivel']);

        // A las 24 h: aviso al responsable general (Julieta) por lo de Cocina y lo de Sistemas, una sola vez;
        // la del área ya la tenían ella y su suplente (no hay a quién más escalar)
        $this->travel(20)->hours();
        $this->flushSession();
        $this->artisan('plataforma:reservas-pendientes')->expectsOutputToContain('Escalamientos: 2.')->assertSuccessful();
        $this->assertSame(2, $this->notificaciones($this->julieta, 'reserva_escalada'));
        $cocina->refresh();
        $this->assertNotNull($cocina->escalado_en);
        $this->assertContains($this->julieta->id, $cocina->escalado_a);
        $this->assertSame('roja', app(EsperaReservas::class)->reloj($cocina)['nivel']);
        $this->artisan('plataforma:reservas-pendientes')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 0.')->assertSuccessful();
        $this->assertTrue($this->enEmpresa(fn () => $r->eventos()->where('evento', 'escalada')->exists()));

        // A quien se escaló puede responder y Mis pendientes lo marca en rojo
        $this->flushSession();
        $pend = $this->enEmpresa(fn () => collect(app(MisPendientes::class)->para($this->julieta->fresh())['items'])->firstWhere('clave', 'reservas_aprobar'));
        $this->assertSame('roja', $pend['nivel']);
        $this->responder($this->julieta, $cocina, 'aprobar')->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->sergio)->get("/reservas/{$r->id}")->assertOk()->assertSee('Aprobada');
    }

    public function test_delegacion_no_molestar_manda_el_aviso_al_delegado(): void
    {
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $this->sergio->id, 'delegado_id' => $this->julia->id,
            'desde' => now()->subHour(), 'hasta' => now()->addDays(2), 'motivo' => 'Vacaciones']));
        $r = $this->reservaConServicios();
        $this->assertSame(0, $this->notificaciones($this->sergio, 'reserva_por_aprobar'));
        $this->assertSame(2, $this->notificaciones($this->julia, 'reserva_por_aprobar')); // la de Sistemas y, delegada, la de Cocina
        $this->responder($this->julia, $this->aprobacion($r, 'departamento:'.$this->cocina->id), 'aprobar')->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------- Pantallas e informes

    public function test_la_entrada_son_fichas_de_sedes_y_luego_fichas_de_areas(): void
    {
        $this->reservaConServicios(['fecha' => '2026-11-02', 'hora_inicio' => '15:00', 'hora_fin' => '16:00']);
        $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $espacios = app(AdministradorEspacios::class);
            $torre = $espacios->crear($this->admin, $this->playa, null, Espacio::EDIFICIO, ['nombre' => 'Club'], false);
            app(CatalogosReservas::class)->marcarReservable($this->admin, $espacios->crear($this->admin, $this->playa, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Terraza Coral'], false));
        });
        auth()->forgetGuards();

        // Varias sedes: tarjetas de sedes con sus conteos (áreas, reservas de hoy y por aprobar)
        $this->flushSession();
        $sedes = $this->actingAs($this->admin)->get('/reservas')->assertOk()
            ->assertSee('Elige una sede para ver sus áreas reservables.')->assertSee('Sede CEN')->assertSee('Sede PLA')
            ->assertSee('2 áreas reservables')->assertSee('1 reserva hoy')->assertSee('1 por aprobar')->assertSee('Entrar a la sede')
            ->assertSee('data-fichas="reservas-sedes"', false)->assertDontSee('Salón Maya');
        $sedes->assertSee('class="pestanas"', false);

        // La sede: fichas de áreas con aforo, montajes, tarifa o «Sin costo», responsable, próxima y hoy, y botones de ícono
        $this->get('/reservas?sede='.$this->centro->id)->assertOk()
            ->assertSee('data-fichas="reservas-areas"', false)->assertSee('Salón Maya')->assertSee('Sala Ceiba')
            ->assertSee('Hasta 120 personas')->assertSee('Auditorio, Herradura (U)')->assertSee('Por hora $1,000.00 MXN')->assertSee('Sin costo')
            ->assertSee('Aprueba '.$this->julieta->name)->assertSee('Hoy: 1')->assertSee('Próxima: Lunes 02/11 15:00')
            ->assertSee('aria-label="Reservar Salón Maya"', false)->assertSee('class="btn-entrar"', false)->assertSee('title="Calendario del área"', false)
            ->assertSee('title="Editar"', false)->assertSee('title="Eliminar de reservas"', false)->assertSee('Agregar área reservable')
            ->assertSee(route('reservas.calendario', ['area' => $this->salon->id, 'sede' => $this->centro->id]))
            ->assertDontSee('Terraza Coral');

        // Con una sola sede visible se entra directo a sus fichas; sin «configurar» no hay botones Editar ni Eliminar
        $this->flushSession();
        $this->actingAs($this->rita)->get('/reservas')->assertOk()->assertSee('Salón Maya')->assertDontSee('Entrar a la sede')
            ->assertDontSee('title="Editar"', false)->assertDontSee('title="Eliminar de reservas"', false)->assertDontSee('Agregar área reservable')->assertSee('Nueva reserva');
        $this->get('/reservas/calendario?area='.$this->salon->id)->assertOk()->assertSee('Calendario de reservas');

        // Mis reservas, Por aprobar y Órdenes: fichas con botones de ícono
        $this->get('/reservas/mis-reservas')->assertOk()->assertSee('data-fichas="reservas-mias"', false)->assertSee('title="Ver"', false);
        $this->flushSession();
        $this->actingAs($this->sergio)->get('/reservas/por-aprobar')->assertOk()->assertSee('data-fichas="reservas-aprobar"', false)
            ->assertSee('title="Aprobar"', false)->assertSee('title="Rechazar o pedir cambio"', false)->assertSee('Esperando desde');
        $this->flushSession();
        $this->actingAs($this->admin)->get('/reservas/configuracion')->assertOk()->assertSee('Agregar área reservable')
            ->assertSee('dialogoEditar-servicios')->assertSee('RESERVABLE');
    }

    public function test_calendario_mis_reservas_informes_y_exportar(): void
    {
        $r = $this->reservaConServicios();
        $this->aprobarTodo($r);
        $this->reservar($this->rita, ['fecha' => '2026-11-05', 'para_cliente' => '1', 'tarifa' => 'evento', 'cliente_nombre' => 'Laura Castillo'])->assertSessionHasNoErrors();
        $cliente = $this->ultima();
        $this->responder($this->julieta, $this->aprobacion($cliente, 'area'), 'aprobar');

        $this->flushSession();
        $this->actingAs($this->rita)->get('/reservas?vista=semana&fecha='.self::MIERCOLES)->assertOk()->assertSee('Junta de jefes')->assertSee('10:00–12:00');
        $this->get('/reservas?vista=mes&fecha='.self::MIERCOLES)->assertOk()->assertSee('Junta de jefes');
        $this->get('/reservas?vista=dia&fecha='.self::MIERCOLES)->assertOk()->assertSee('Salón Maya')->assertSee('Reservar aquí');
        $this->get('/reservas/mis-reservas')->assertOk()->assertSee('Junta de jefes')->assertSee('Confirmada');
        $this->get('/reservas/nueva?area='.$this->salon->id.'&fecha='.self::MIERCOLES)->assertOk()->assertSee('Guardar reserva')->assertSee('Coffee break');
        $this->get("/reservas/{$r->id}/imprimir")->assertOk()->assertSee('Orden de evento')->assertSee('Coffee break');

        $this->flushSession();
        $this->actingAs($this->admin)->get('/reservas/informes?desde=2026-11-01&hasta=2026-11-30')->assertOk()
            ->assertSee('Ocupación por área')->assertSee('Salón Maya')->assertSee('$11,600.00 MXN');
        $csv = $this->get('/reservas/informes/exportar?desde=2026-11-01&hasta=2026-11-30');
        $csv->assertOk();
        $this->assertStringContainsString('Ingresos de eventos para clientes', $csv->streamedContent());
        $this->flushSession();
        $this->actingAs($this->rita)->get('/reservas/informes/exportar')->assertForbidden();
    }
}
