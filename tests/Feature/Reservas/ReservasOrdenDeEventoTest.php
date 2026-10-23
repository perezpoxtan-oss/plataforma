<?php

namespace Tests\Feature\Reservas;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaArea;
use App\Models\ReservaEvento;
use App\Models\ReservaMontaje;
use App\Models\ReservaOrden;
use App\Models\ReservaServicio;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Reservas con formato de «orden de evento» (docs/tecnico/reservas.md,
 * «Orden de evento»): departamento que solicita (propuesto y editable, de la
 * empresa), excepción de aforo con motivo (visible para quien aprueba), día
 * completo, montaje y desmontaje por reserva (bloqueo y choques, también entre
 * niveles), servicios por departamento con nota y «otro servicio» libre con
 * su aprobación y orden, departamento que hace el montaje, lista sugerida de
 * hotel idempotente, hoja impresa por secciones y migración con down().
 *
 * Reloj fijo: lunes 2 de noviembre de 2026, 9:00 en la Ciudad de México.
 */
class ReservasOrdenDeEventoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const JUEVES = '2026-11-05';

    private const SABADO = '2026-11-07';

    private Empresa $empresa;

    private Empresa $otra;

    private Sede $centro;

    private User $admin;

    private User $rita;

    private User $julieta;

    private User $sergio;

    private User $julia;

    private ReservaArea $salon;

    private ReservaArea $sala;

    private ReservaArea $torre;

    private Departamento $cocina;

    private Departamento $sistemas;

    private Departamento $capacitacion;

    private Departamento $ajena;

    /** @var array<string, int> */
    private array $servicios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rita = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->julieta = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->sergio = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->julia = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        $this->otra = $this->crearEmpresa('Hotel Ajeno');
        $this->ajena = app(Tenant::class)->conEmpresa($this->otra->id, fn () => Departamento::create(['nombre' => 'Capacitación']));

        $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $this->cocina = Departamento::create(['nombre' => 'Cocina']);
            $this->sistemas = Departamento::create(['nombre' => 'Sistemas']);
            $this->capacitacion = Departamento::create(['nombre' => 'Capacitación']);
            DepartamentoResponsable::create(['departamento_id' => $this->cocina->id, 'user_id' => $this->sergio->id, 'es_suplente' => false, 'sede_id' => null]);
            DepartamentoResponsable::create(['departamento_id' => $this->sistemas->id, 'user_id' => $this->julia->id, 'es_suplente' => false, 'sede_id' => null]);
            // Rita es colaboradora de Capacitación: su departamento se propone al reservar
            $colaborador = Colaborador::create(['num_empleado' => 'C-1', 'nombre' => 'Rita', 'apellido_paterno' => 'Uc', 'sede_id' => $this->centro->id,
                'departamento_id' => $this->capacitacion->id]);
            $this->rita->forceFill(['colaborador_id' => $colaborador->id])->save();
            app(AjustesReservas::class)->guardar($this->admin, $this->empresa->refresh(), [
                'responsable_id' => $this->julieta->id, 'suplente_id' => $this->admin->id, 'iva_aplica' => '1', 'iva_porcentaje' => 16, 'recordar_horas' => 4, 'escalar_horas' => 24,
            ]);
            $catalogos = app(CatalogosReservas::class);
            $catalogos->asegurar();
            $espacios = app(AdministradorEspacios::class);
            $torre = $espacios->crear($this->admin, $this->centro, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $salon = $espacios->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Salón Maya'], false);
            $sala = $espacios->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala de capacitación'], false);
            $montaje = fn (string $n) => ReservaMontaje::where('nombre', $n)->value('id');
            $this->salon = $catalogos->marcarReservable($this->admin, $salon);
            $catalogos->guardarArea($this->admin, $this->salon, [
                'aforo_maximo' => 120, 'montaje_minutos' => 60, 'desmontaje_minutos' => 30, 'tarifa_hora' => 1000, 'tarifa_dia' => 9000,
                'montajes' => [$montaje('Auditorio') => ['usar' => '1', 'aforo' => 120], $montaje('Herradura (U)') => ['usar' => '1', 'aforo' => 30]],
            ]);
            $this->sala = $catalogos->marcarReservable($this->admin, $sala);
            $semana = collect(range(1, 5))->mapWithKeys(fn ($d) => [$d => ['abierto' => '1', 'desde' => '08:00', 'hasta' => '20:00']])->all();
            $catalogos->guardarArea($this->admin, $this->sala, ['aforo_maximo' => 25, 'confirmacion_inmediata' => '1', 'con_horario' => '1', 'horario' => $semana,
                'montajes' => [$montaje('Herradura (U)') => ['usar' => '1', 'aforo' => 20]]]);
            // La torre completa también se reserva (bloqueo cruzado con sus salas)
            $this->torre = $catalogos->marcarReservable($this->admin, $torre);
            foreach ([
                'coffee' => ['Coffee break', 'alimentos', 'persona', 80, $this->cocina->id],
                'pan' => ['Pan', 'alimentos', 'evento', null, $this->cocina->id],
                'proyector' => ['Proyector', 'audio_video', 'pieza', 500, $this->sistemas->id],
                'lista' => ['Lista de asistencia', 'otro', 'evento', null, $this->capacitacion->id],
            ] as $clave => [$nombre, $categoria, $unidad, $costo, $depto]) {
                $this->servicios[$clave] = $catalogos->guardarCatalogo($this->admin, 'servicios', null, ['nombre' => $nombre, 'categoria' => $categoria,
                    'unidad' => $unidad, 'costo' => $costo, 'departamento_id' => $depto, 'requiere_aprobacion' => '1'])->id;
            }
        });
        $this->salon->refresh();
        $this->sala->refresh();
        $this->torre->refresh();
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
            'reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0',
        ]);
    }

    private function ultima(): Reserva
    {
        return $this->enEmpresa(fn () => Reserva::with(['aprobaciones', 'ordenes', 'items'])->latest('id')->firstOrFail());
    }

    private function aprobacion(Reserva $r, string $clave): ?ReservaAprobacion
    {
        return $this->enEmpresa(fn () => ReservaAprobacion::where('reserva_id', $r->id)->where('vigente', true)->get()->first(fn ($a) => $a->clave() === $clave));
    }

    private function aprobar(User $quien, ReservaAprobacion $a): void
    {
        $this->flushSession();
        $this->actingAs($quien)->post("/reservas/aprobaciones/{$a->id}/responder", ['respuesta' => 'aprobar'])->assertSessionHasNoErrors();
    }

    private function local(\DateTimeInterface $fecha): string
    {
        return CarbonImmutable::instance($fecha)->setTimezone('America/Mexico_City')->format('Y-m-d H:i');
    }

    // ------------------------------------------------- Departamento solicitante

    public function test_el_departamento_que_solicita_se_propone_se_puede_cambiar_y_es_de_la_empresa(): void
    {
        // El formulario propone el departamento del colaborador de la persona
        $this->actingAs($this->rita)->get('/reservas/nueva')->assertOk()
            ->assertSee('Departamento que solicita')
            ->assertSee('<option value="'.$this->capacitacion->id.'" selected', false);

        // Sin indicarlo, se guarda el propuesto
        $this->reservar($this->rita)->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertSame($this->capacitacion->id, $r->departamento_solicitante_id);
        $this->actingAs($this->rita)->get("/reservas/{$r->id}")->assertOk()
            ->assertSee('Solicitado por')->assertSee($this->rita->name.' — Capacitación')->assertSee('Fecha de solicitud');

        // Se puede cambiar o dejar sin departamento
        $this->reservar($this->rita, ['departamento_solicitante_id' => $this->cocina->id, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'])->assertSessionHasNoErrors();
        $this->assertSame($this->cocina->id, $this->ultima()->departamento_solicitante_id);
        $this->reservar($this->rita, ['departamento_solicitante_id' => '', 'hora_inicio' => '17:00', 'hora_fin' => '18:00'])->assertSessionHasNoErrors();
        $this->assertNull($this->ultima()->departamento_solicitante_id);

        // Un departamento de otra empresa no se acepta
        $this->reservar($this->rita, ['departamento_solicitante_id' => $this->ajena->id, 'hora_inicio' => '20:00', 'hora_fin' => '21:00'])
            ->assertSessionHasErrors(['departamento_solicitante_id' => 'Elige un departamento de tu empresa.']);

        // Al modificar sin el campo, conserva el que tenía
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00',
            'hora_fin' => '12:30', 'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0'])->assertSessionHasNoErrors();
        $this->assertSame($this->capacitacion->id, $r->refresh()->departamento_solicitante_id);
    }

    // ------------------------------------------------------- Excepción de aforo

    public function test_pasar_el_aforo_pide_motivo_y_lo_ve_quien_aprueba(): void
    {
        // Sala de capacitación: aforo 25 (Tipo U: 20) y confirmación inmediata
        $tipoU = $this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Herradura (U)')->value('id'));
        $base = ['reserva_area_id' => $this->sala->id, 'asistentes' => 22, 'montaje_id' => $tipoU];
        $this->reservar($this->rita, $base)
            ->assertSessionHasErrors(['motivo_excepcion_aforo' => 'Son 22 personas y el aforo del montaje Herradura (U) es de 20: escribe el motivo de la excepción (lo verá quien aprueba).']);
        $this->assertSame(0, $this->enEmpresa(fn () => Reserva::count()));

        $this->reservar($this->rita, $base + ['motivo_excepcion_aforo' => 'Se agregan dos sillas; lo autorizó Protección Civil'])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertTrue($r->excede_aforo);
        $this->assertSame('Se agregan dos sillas; lo autorizó Protección Civil', $r->motivo_excepcion_aforo);
        // Aunque el área confirma al momento, la excepción la revisa su responsable
        $this->assertSame(Reserva::POR_APROBAR, $r->estado);
        $this->assertNotNull($this->aprobacion($r, 'area'));

        $this->actingAs($this->julieta)->get('/reservas/por-aprobar')->assertOk()
            ->assertSee('Excede aforo')->assertSee('22 de 20 permitidos')->assertSee('Se agregan dos sillas');
        $this->actingAs($this->julieta)->get("/reservas/{$r->id}")->assertOk()->assertSee('Excepción de aforo:')->assertSee('Se agregan dos sillas');
        $this->actingAs($this->rita)->get("/reservas/{$r->id}/imprimir")->assertOk()->assertSee('Excepción de aforo:')->assertSee('de 20 permitidos');

        // Dentro del aforo no queda marcada y el motivo no se guarda
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'asistentes' => 18, 'montaje_id' => $tipoU, 'hora_inicio' => '14:00', 'hora_fin' => '15:00',
            'motivo_excepcion_aforo' => 'no aplica'])->assertSessionHasNoErrors();
        $dentro = $this->ultima();
        $this->assertFalse($dentro->excede_aforo);
        $this->assertNull($dentro->motivo_excepcion_aforo);
        $this->assertSame(Reserva::CONFIRMADA, $dentro->estado);
    }

    // ------------------------------------------------------------ Día completo

    public function test_dia_completo_usa_el_horario_del_area_y_la_tarifa_de_dia(): void
    {
        // Sala con horario: el jueves de 08:00 a 20:00 (las horas escritas no cuentan)
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'dia_completo' => '1', 'hora_inicio' => '', 'hora_fin' => ''])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertTrue($r->dia_completo);
        $this->assertSame(self::JUEVES.' 08:00', $this->local($r->inicio));
        $this->assertSame(self::JUEVES.' 20:00', $this->local($r->fin));

        // El sábado la sala no se reserva
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'dia_completo' => '1', 'fecha' => self::SABADO])
            ->assertSessionHasErrors(['fecha' => 'Ese día el área no se reserva: elige otro día.']);

        // Salón sin horario: de 00:00 a 23:59; para cliente, con la tarifa de día
        $this->reservar($this->rita, ['dia_completo' => '1', 'fecha' => '2026-11-10', 'para_cliente' => '1', 'cliente_nombre' => 'Juan Pérez'])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertSame('2026-11-10 00:00', $this->local($r->inicio));
        $this->assertSame('2026-11-10 23:59', $this->local($r->fin));
        $this->assertSame('dia', $r->tarifa);
        $this->assertSame('9000.00', (string) $r->importe_area);
        $this->actingAs($this->rita)->get("/reservas/{$r->id}")->assertSee('(día completo)');
    }

    // ------------------------------------------- Montaje y desmontaje por reserva

    public function test_montaje_y_desmontaje_de_la_reserva_cambian_el_bloqueo_y_los_choques(): void
    {
        // El salón pide 60 min de montaje y 30 de desmontaje; esta reserva pide 90 y 0
        $this->reservar($this->rita, ['montaje_minutos' => 90, 'desmontaje_minutos' => 0, 'indicaciones_montaje' => 'Tener montaje listo 30 min antes'])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertSame(90, $r->montaje_minutos);
        $this->assertSame(0, $r->desmontaje_minutos);
        $this->assertSame(self::JUEVES.' 08:30', $this->local($r->bloqueo_desde));
        $this->assertSame(self::JUEVES.' 12:00', $this->local($r->bloqueo_hasta));
        $this->assertSame('Tener montaje listo 30 min antes', $r->indicaciones_montaje);
        $this->actingAs($this->rita)->get("/reservas/{$r->id}")->assertSee('1 h 30 min antes (montaje)')->assertSee('Tener montaje listo 30 min antes');

        // Los minutos iguales a los del área no se guardan (la reserva usa los del área)
        $this->reservar($this->rita, ['montaje_minutos' => 60, 'desmontaje_minutos' => 30, 'fecha' => '2026-11-06'])->assertSessionHasNoErrors();
        $this->assertNull($this->ultima()->montaje_minutos);

        // Otra a las 12:00 con el montaje del área (11:00) choca; sin montaje no
        $this->reservar($this->rita, ['hora_inicio' => '12:00', 'hora_fin' => '13:00'])->assertSessionHasErrors('hora_inicio');
        $this->reservar($this->rita, ['hora_inicio' => '12:00', 'hora_fin' => '13:00', 'montaje_minutos' => 0])->assertSessionHasNoErrors();

        // Bloqueo cruzado por niveles: la torre completa choca con el montaje del salón (08:30)
        $this->reservar($this->rita, ['reserva_area_id' => $this->torre->id, 'hora_inicio' => '07:00', 'hora_fin' => '08:45'])
            ->assertSessionHasErrors('hora_inicio');
        $this->assertStringContainsString('comparte lugar', session('errors')->first('hora_inicio'));
        $this->reservar($this->rita, ['reserva_area_id' => $this->torre->id, 'hora_inicio' => '07:00', 'hora_fin' => '08:30'])->assertSessionHasNoErrors();
        // …y con desmontaje de la torre de 30 min, la sala interior ya no se puede a las 08:45
        $torre = $this->ultima();
        $this->assertSame(self::JUEVES.' 08:30', $this->local($torre->bloqueo_hasta));
        $this->reservar($this->rita, ['reserva_area_id' => $this->sala->id, 'hora_inicio' => '08:00', 'hora_fin' => '08:30', 'desmontaje_minutos' => 0])
            ->assertSessionHasErrors('hora_inicio');

        // Minutos fuera de rango
        $this->reservar($this->rita, ['fecha' => '2026-11-12', 'montaje_minutos' => 5000])->assertSessionHasErrors(['montaje_minutos' => 'Los minutos van de 0 a 1440 (24 horas).']);
    }

    // ------------------------------------------- Servicios por departamento

    public function test_servicios_por_departamento_con_nota_y_otro_servicio_libre_con_aprobacion_y_orden(): void
    {
        $this->actingAs($this->rita)->get('/reservas/nueva')->assertOk()
            ->assertSee('Servicios por departamento')->assertSee('res-depto-titulo', false)
            ->assertSee('Cocina')->assertSee('Sistemas')->assertSee('Otros')->assertSee('Otro servicio');

        $this->reservar($this->rita, [
            'servicios' => [
                $this->servicios['coffee'] => ['usar' => '1', 'cantidad' => '', 'notas' => 'Agua y café'],
                $this->servicios['pan'] => ['usar' => '1'],
                $this->servicios['proyector'] => ['usar' => '0', 'cantidad' => ''],
            ],
            'otros' => [
                ['departamento_id' => $this->sistemas->id, 'nombre' => 'Cable HDMI', 'notas' => 'Largo'],
                ['departamento_id' => '', 'nombre' => 'Flores', 'notas' => 'Blancas'],
                ['departamento_id' => $this->cocina->id, 'nombre' => '  '],
            ],
        ])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertCount(4, $r->items);
        $coffee = $r->items->firstWhere('servicio_id', $this->servicios['coffee']);
        $this->assertSame('20', $coffee->cantidadTexto()); // por persona: las personas
        $this->assertSame('Agua y café', $coffee->notas);
        $hdmi = $r->items->firstWhere('nombre', 'Cable HDMI');
        $this->assertNull($hdmi->servicio_id);
        $this->assertSame($this->sistemas->id, $hdmi->departamento_id);
        $this->assertSame('Largo', $hdmi->notas);
        $this->assertNull($r->items->firstWhere('nombre', 'Flores')->departamento_id);
        $this->assertNull($r->items->firstWhere('servicio_id', $this->servicios['proyector']));

        // Aprueban el área, Cocina y Sistemas (por el «otro servicio»)
        $this->assertNotNull($this->aprobacion($r, 'departamento:'.$this->cocina->id));
        $this->assertNotNull($this->aprobacion($r, 'departamento:'.$this->sistemas->id));
        $this->actingAs($this->julia)->get('/reservas/por-aprobar')->assertSee('Cable HDMI × 1 pza. (Largo)');
        $this->aprobar($this->julieta, $this->aprobacion($r, 'area'));
        $this->aprobar($this->sergio, $this->aprobacion($r, 'departamento:'.$this->cocina->id));
        $this->aprobar($this->julia, $this->aprobacion($r, 'departamento:'.$this->sistemas->id));
        $r = $this->ultima();
        $this->assertSame(Reserva::CONFIRMADA, $r->estado);
        $this->assertEqualsCanonicalizing([$this->cocina->id, $this->sistemas->id], $r->ordenes->pluck('departamento_id')->all());
        $this->actingAs($this->julia)->get('/reservas/ordenes')->assertSee('Cable HDMI');

        // El detalle agrupa por departamento y el formulario de modificar trae el otro servicio
        $this->actingAs($this->rita)->get("/reservas/{$r->id}")->assertOk()->assertSee('res-tabla-grupo', false)->assertSee('Agua y café');
        $this->actingAs($this->rita)->get("/reservas/{$r->id}/editar")->assertOk()->assertSee('value="Cable HDMI"', false)->assertSee('value="Flores"', false);

        // Cambiar la nota de Cocina vuelve a pedir solo a Cocina
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['usar' => '1', 'cantidad' => '20', 'notas' => 'Solo agua'], $this->servicios['pan'] => ['usar' => '1']],
            'otros' => [['departamento_id' => $this->sistemas->id, 'nombre' => 'Cable HDMI', 'notas' => 'Largo'], ['departamento_id' => '', 'nombre' => 'Flores', 'notas' => 'Blancas']],
        ])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->assertSame(ReservaAprobacion::PENDIENTE, $this->aprobacion($r, 'departamento:'.$this->cocina->id)->estado);
        $this->assertSame(ReservaAprobacion::APROBADA, $this->aprobacion($r, 'departamento:'.$this->sistemas->id)->estado);
        $this->assertSame(ReservaAprobacion::APROBADA, $this->aprobacion($r, 'area')->estado);

        // Un «otro servicio» con departamento de otra empresa no se acepta
        $this->reservar($this->rita, ['hora_inicio' => '15:00', 'hora_fin' => '16:00', 'otros' => [['departamento_id' => $this->ajena->id, 'nombre' => 'Algo']]])
            ->assertSessionHasErrors('otros');
    }

    public function test_guardar_sin_cambios_conserva_un_servicio_dado_de_baja_y_no_avisa_a_nadie(): void
    {
        // Confirmada con Coffee break (Cocina) y Proyector (Sistemas)
        $this->reservar($this->rita, ['servicios' => [
            $this->servicios['coffee'] => ['usar' => '1', 'cantidad' => '20', 'notas' => 'Agua'],
            $this->servicios['proyector'] => ['usar' => '1', 'cantidad' => '1'],
        ]])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->aprobar($this->julieta, $this->aprobacion($r, 'area'));
        $this->aprobar($this->sergio, $this->aprobacion($r, 'departamento:'.$this->cocina->id));
        $this->aprobar($this->julia, $this->aprobacion($r, 'departamento:'.$this->sistemas->id));
        $this->assertSame(Reserva::CONFIRMADA, $this->ultima()->estado);

        // Después se da de baja el proyector en el catálogo
        $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            app(CatalogosReservas::class)->cambiarEstado($this->admin, ReservaServicio::findOrFail($this->servicios['proyector']), false);
        });
        auth()->forgetGuards();

        // Ya no se ofrece para reservas nuevas, pero la reserva que lo pidió lo sigue mostrando marcado
        $this->actingAs($this->rita)->get('/reservas/nueva')->assertOk()->assertDontSee('name="servicios['.$this->servicios['proyector'].'][usar]"', false);
        $this->actingAs($this->rita)->get("/reservas/{$r->id}/editar")->assertOk()
            ->assertSee('name="servicios['.$this->servicios['proyector'].'][usar]" value="1" checked', false)
            ->assertSee('Dado de baja: se conserva en esta reserva');

        // Guardar sin cambios (lo que manda el formulario): nada se quita ni se vuelve a pedir
        $avisos = $this->enEmpresa(fn () => Notificacion::count());
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0', 'montaje_minutos' => 60, 'desmontaje_minutos' => 30,
            'servicios' => [
                $this->servicios['coffee'] => ['usar' => '1', 'cantidad' => '20', 'notas' => 'Agua'],
                $this->servicios['proyector'] => ['usar' => '1', 'cantidad' => '1', 'notas' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok', 'Cambios guardados: la reserva sigue confirmada.');
        $r = $this->ultima();
        $this->assertSame(Reserva::CONFIRMADA, $r->estado);
        $this->assertNotNull($r->items->firstWhere('servicio_id', $this->servicios['proyector']));
        $this->assertSame(ReservaOrden::PENDIENTE, $r->ordenes->firstWhere('departamento_id', $this->sistemas->id)->estado);
        $this->assertSame($avisos, $this->enEmpresa(fn () => Notificacion::count()), 'Guardar sin cambios no avisa a nadie');
        $this->assertSame('Modificada por '.$this->rita->name.' (sin cambios)', $this->enEmpresa(fn () => ReservaEvento::where('reserva_id', $r->id)->where('evento', 'modificada')->latest('id')->value('titulo')));

        // Desmarcarlo sí lo quita (y ya no se puede volver a pedir)
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['usar' => '1', 'cantidad' => '20', 'notas' => 'Agua']],
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->ultima()->items->firstWhere('servicio_id', $this->servicios['proyector']));
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['usar' => '1', 'cantidad' => '20', 'notas' => 'Agua'], $this->servicios['proyector'] => ['usar' => '1']],
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->ultima()->items->firstWhere('servicio_id', $this->servicios['proyector']));
    }

    public function test_al_rechazar_las_demas_aprobaciones_se_ven_como_ya_no_aplica(): void
    {
        $this->reservar($this->rita, ['servicios' => [
            $this->servicios['coffee'] => ['usar' => '1'], $this->servicios['proyector'] => ['usar' => '1'],
        ]])->assertSessionHasNoErrors();
        $r = $this->ultima();
        // Una modificación previa deja una aprobación reemplazada (no debe mostrarse)
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$r->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0',
            'servicios' => [$this->servicios['coffee'] => ['usar' => '1', 'notas' => 'Agua'], $this->servicios['proyector'] => ['usar' => '1']],
        ])->assertSessionHasNoErrors();
        $this->travel(1)->minutes();

        $sistemas = $this->aprobacion($r, 'departamento:'.$this->sistemas->id);
        $this->flushSession();
        $this->actingAs($this->julia)->post("/reservas/aprobaciones/{$sistemas->id}/responder", ['respuesta' => 'rechazar', 'comentario' => ''])
            ->assertSessionHasErrors(['comentario' => 'Escribe el motivo del rechazo.']);
        $this->flushSession();
        $this->actingAs($this->julia)->post("/reservas/aprobaciones/{$sistemas->id}/responder", ['respuesta' => 'rechazar', 'comentario' => 'No hay proyector disponible'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Reserva::RECHAZADA, $this->ultima()->estado);

        $html = $this->actingAs($this->rita)->get("/reservas/{$r->id}")->assertOk()->getContent();
        $bloque = (string) str($html)->after('id="t-aprobaciones"')->before('t-historial');
        $this->assertSame(1, substr_count($bloque, 'Rechazada'));
        // Área y Cocina siguen visibles como «Ya no aplica» (una vez cada una) y sin botones
        $this->assertSame(2, substr_count($bloque, 'Ya no aplica'));
        $this->assertStringContainsString('Responsable del área', $bloque);
        $this->assertStringContainsString('Cocina', $bloque);
        $this->assertStringNotContainsString('Aprobar</button>', $this->actingAs($this->sergio)->get("/reservas/{$r->id}")->getContent());

        // Una reserva viva no muestra las aprobaciones reemplazadas
        $this->reservar($this->rita, ['hora_inicio' => '15:00', 'hora_fin' => '16:00', 'servicios' => [$this->servicios['coffee'] => ['usar' => '1']]])->assertSessionHasNoErrors();
        $viva = $this->ultima();
        $this->flushSession();
        $this->actingAs($this->rita)->put("/reservas/{$viva->id}", ['reserva_area_id' => $this->salon->id, 'fecha' => self::JUEVES, 'hora_inicio' => '15:00', 'hora_fin' => '16:00',
            'titulo' => 'Capacitación de inducción', 'asistentes' => 20, 'para_cliente' => '0', 'servicios' => []])->assertSessionHasNoErrors();
        $this->actingAs($this->rita)->get("/reservas/{$viva->id}")->assertOk()->assertDontSee('Ya no aplica');
    }

    public function test_el_departamento_que_hace_el_montaje_recibe_la_orden_del_tipo_de_montaje(): void
    {
        $steward = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Steward']));
        $this->flushSession();
        $this->actingAs($this->admin)->put('/reservas/ajustes', ['responsable_id' => $this->julieta->id, 'suplente_id' => $this->admin->id, 'iva_aplica' => '1',
            'iva_porcentaje' => 16, 'recordar_horas' => 4, 'escalar_horas' => 24, 'departamento_montaje_id' => $steward->id])->assertSessionHasNoErrors();
        $this->assertSame($steward->id, app(AjustesReservas::class)->de($this->empresa->refresh())['departamento_montaje_id']);
        // Un departamento de otra empresa no
        $this->flushSession();
        $this->actingAs($this->admin)->put('/reservas/ajustes', ['recordar_horas' => 4, 'escalar_horas' => 24, 'iva_porcentaje' => 16, 'departamento_montaje_id' => $this->ajena->id])
            ->assertSessionHasErrors('departamento_montaje_id');

        // El tipo de montaje aparece dentro de la tarjeta de Steward
        $html = $this->actingAs($this->rita)->get('/reservas/nueva')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<legend class="res-depto-titulo">Steward<\/legend>\s*<div class="res-montaje-tarjeta">/', $html);

        $tipoU = $this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Herradura (U)')->value('id'));
        $this->reservar($this->rita, ['montaje_id' => $tipoU])->assertSessionHasNoErrors();
        $r = $this->ultima();
        $this->aprobar($this->julieta, $this->aprobacion($r, 'area'));
        $orden = $this->enEmpresa(fn () => ReservaOrden::where('reserva_id', $r->id)->first());
        $this->assertSame($steward->id, $orden?->departamento_id);
        $hoja = $this->actingAs($this->rita)->get("/reservas/{$r->id}/imprimir")->assertOk();
        $hoja->assertSee('Steward')->assertSee('Tipo de montaje:')->assertSee('Herradura (U)');
    }

    // ------------------------------------------------ Lista sugerida de hotel

    public function test_la_lista_sugerida_de_hotel_es_idempotente_y_no_crea_departamentos(): void
    {
        $this->enEmpresa(function () {
            Departamento::create(['nombre' => 'STEWARD']);
            Departamento::create(['nombre' => 'Areas Públicas']);
            Departamento::create(['nombre' => 'Alimentos y Bebidas']);
            // Un montaje inicial eliminado: el botón crea «Tipo Banquete», asegurar() no lo revive
            ReservaMontaje::where('nombre', 'Banquete')->delete();
        });
        $deptos = $this->enEmpresa(fn () => Departamento::count());

        $this->actingAs($this->admin)->get('/reservas/configuracion')->assertOk()->assertSee('Cargar servicios sugeridos de hotel');
        $this->actingAs($this->rita)->post('/reservas/configuracion/sugeridos-hotel')->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->admin)->post('/reservas/configuracion/sugeridos-hotel')->assertRedirect()
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Asigna el departamento de 11 servicios') && str_contains($m, 'Mantenimiento, Animación'));
        $this->enEmpresa(function () use ($deptos) {
            $this->assertSame($deptos, Departamento::count());
            $depto = fn (string $servicio) => ReservaServicio::where('nombre', $servicio)->first()?->departamento?->nombre;
            $this->assertSame('STEWARD', $depto('Mantelería'));
            $this->assertSame('Areas Públicas', $depto('Aromatizar'));
            $this->assertSame('Alimentos y Bebidas', $depto('Bebidas'));
            $this->assertSame('Cocina', $depto('Desayuno'));
            $this->assertSame('Sistemas', $depto('Laptop'));
            $this->assertSame('Capacitación', $depto('Cañón'));
            $this->assertNull($depto('Show'));
            $this->assertNull(ReservaServicio::where('nombre', 'Mantelería')->value('costo'));
            // Ya existían «Pan», «Proyector» y «Lista de asistencia»: no se duplican
            $this->assertSame(1, ReservaServicio::where('nombre', 'Pan')->count());
            $this->assertSame(1, ReservaServicio::where('nombre', 'Proyector')->count());
            $this->assertTrue(ReservaMontaje::where('nombre', 'Tipo Banquete')->exists());
            $this->assertFalse(ReservaMontaje::where('nombre', 'Tipo U')->exists()); // ya hay «Herradura (U)»
        });
        $total = $this->enEmpresa(fn () => ReservaServicio::count());

        // Otra vez: no duplica
        $this->flushSession();
        $this->actingAs($this->admin)->post('/reservas/configuracion/sugeridos-hotel')
            ->assertSessionHas('ok', 'Tu catálogo ya tenía todos los servicios y montajes sugeridos: no se agregó nada.');
        $this->assertSame($total, $this->enEmpresa(fn () => ReservaServicio::count()));
        $this->assertSame(1, $this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Tipo Banquete')->count()));
        // Abrir Configuración no revive el «Banquete» eliminado
        $this->actingAs($this->admin)->get('/reservas/configuracion')->assertOk();
        $this->assertFalse($this->enEmpresa(fn () => ReservaMontaje::where('nombre', 'Banquete')->exists()));
    }

    // ------------------------------------------------------------- Impresión

    public function test_la_orden_de_evento_impresa_trae_encabezado_y_secciones_por_departamento(): void
    {
        $this->reservar($this->rita, [
            'reserva_area_id' => $this->sala->id, 'hora_inicio' => '09:00', 'hora_fin' => '13:30', 'montaje_minutos' => 30,
            'indicaciones_montaje' => 'Tener montaje listo 30 min antes',
            'servicios' => [$this->servicios['coffee'] => ['usar' => '1', 'notas' => 'Agua'], $this->servicios['lista'] => ['usar' => '1']],
        ])->assertSessionHasNoErrors();
        $r = $this->ultima();

        $hoja = $this->actingAs($this->rita)->get("/reservas/{$r->id}/imprimir")->assertOk();
        $hoja->assertSee('Orden de evento')->assertSee($r->folioTexto())->assertSee('Lugar donde se realizará')->assertSee('Sala de capacitación')
            ->assertSee('Núm. de pax')->assertSee('de 25 permitidos')->assertSee('Jueves 05/11/2026')->assertSee('09:00 a 13:30')
            ->assertSee($this->rita->name.' — Capacitación')->assertSee('Fecha de solicitud')->assertSee('30 min antes (montaje)')
            ->assertSee('res-orden-resaltado', false)->assertSee('Tener montaje listo 30 min antes')
            ->assertSee('Cocina')->assertSee('Capacitación')->assertSee('☒')->assertSee('Agua')->assertSee('Lista de asistencia')
            ->assertDontSee('☐')->assertDontSee('Proyector')->assertSee('Mostrar hoja completa');

        // Hoja completa: también las casillas vacías del catálogo
        $this->actingAs($this->rita)->get("/reservas/{$r->id}/imprimir?completa=1")->assertOk()
            ->assertSee('☐')->assertSee('Proyector')->assertSee('Sistemas')->assertSee('Otro servicio:')->assertSee('Solo lo pedido');
    }

    // ------------------------------------------------------------- Migración

    public function test_la_migracion_de_orden_de_evento_se_revierte_y_se_vuelve_a_aplicar(): void
    {
        $this->reservar($this->rita)->assertSessionHasNoErrors();
        $ruta = 'database/migrations/2026_11_07_000100_orden_de_evento_en_reservas.php';
        Artisan::call('migrate:rollback', ['--path' => $ruta, '--force' => true]);
        foreach (['departamento_solicitante_id', 'excede_aforo', 'motivo_excepcion_aforo', 'dia_completo', 'montaje_minutos', 'desmontaje_minutos', 'indicaciones_montaje'] as $columna) {
            $this->assertFalse(Schema::hasColumn('reservas', $columna), $columna);
        }
        // La reserva que ya existía sigue ahí
        $this->assertSame(1, $this->enEmpresa(fn () => Reserva::count()));
        Artisan::call('migrate', ['--path' => $ruta, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('reservas', 'departamento_solicitante_id'));
        $r = $this->ultima();
        $this->assertFalse($r->excede_aforo);
        $this->assertNull($r->montaje_minutos);
        // Sin minutos propios usa los del área
        $this->assertSame(60, $this->enEmpresa(fn () => $r->load('area')->minutosMontaje()));
    }
}
