<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\EnviaFirmasComoArchivo;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * «Recorrido del pase»: nombres coherentes (lo programado contra lo real por
 * momento y lugar), reglas de coherencia de fechas y datos de ejemplo
 * coherentes.
 */
class PasesSalidaRecorridoTest extends TestCase
{
    use CreaDatosNucleo, EnviaFirmasComoArchivo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $director;

    private User $gerente;

    private Colaborador $solicitante;

    private Colaborador $otroColaborador;

    private Proveedor $proveedor;

    private string $zona;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->gerente = $this->crearUsuario($this->empresa, 'Director');
        $this->solicitante = $this->colaborador('1007', 'Mariana', 'López');
        $this->otroColaborador = $this->colaborador('1005', 'Roberto', 'Hernández');
        $this->proveedor = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Constructora Maya', 'categoria' => 'contratista',
            'direccion' => 'Calle 20 Sur 110, Cancún', 'telefono' => '9981234567', 'todas_las_sedes' => true]));
        $this->zona = $this->enEmpresa(fn () => Sede::with('empresa')->findOrFail($this->centro->id)->zonaHoraria());
    }

    // ------------------------------------------------------------- Ayudantes

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function colaborador(string $num, string $nombre, string $paterno): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno,
            'sede_id' => $this->centro->id, 'activo' => true]));
    }

    /** Lleva el reloj a una fecha y hora local de la sede ("2026-10-05 09:10"). */
    private function a(string $local): void
    {
        $this->travelTo(Carbon::parse($local, $this->zona));
        // Sesión nueva: la de antes «expiraría» por inactividad al saltar días
        $this->flushSession();
    }

    private function firmaJpeg(int $grosor = 1): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagesetthickness($img, $grosor);
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    private function crearPase(array $extra = []): PaseSalida
    {
        $this->actingAs($this->admin)->post('/pases-salida', array_merge([
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => $this->solicitante->id,
            'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id,
            'fecha_salida_programada' => '2026-10-05', 'fecha_tentativa_regreso' => '2026-10-12',
            'articulos' => [['cantidad' => 3, 'equipo' => 'Radio', 'marca' => 'Motorola']],
        ], $extra))->assertSessionHasNoErrors();

        return $this->recargar($this->enEmpresa(fn () => PaseSalida::orderByDesc('id')->firstOrFail()));
    }

    private function recargar(PaseSalida $pase): PaseSalida
    {
        return $this->enEmpresa(fn () => PaseSalida::with('articulos', 'aprobaciones', 'bitacora')->findOrFail($pase->id));
    }

    private function actual(PaseSalida $pase): ?PaseSalidaAprobacion
    {
        return $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($this->recargar($pase)));
    }

    private function aprobarTodo(PaseSalida $pase): void
    {
        while (($actual = $this->actual($pase)) !== null) {
            $quien = collect([$this->admin, $this->director, $this->gerente])->first(fn (User $u) => $this->enEmpresa(
                fn () => app(CircuitoPasesSalida::class)->puedeAprobar($u, $this->recargar($pase), $actual)));
            app(Autorizador::class)->olvidar();
            $this->actingAs($quien)->post("/pases-salida/{$pase->id}/firmas", [
                'paso' => 'aprobacion', 'aprobacion_id' => $actual->id, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
            ])->assertSessionHasNoErrors();
        }
    }

    private function paso(PaseSalida $pase, array $extra = []): TestResponse
    {
        $pase = $this->recargar($pase);

        return $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/firmas", array_merge([
            'paso' => $pase->pasoFisico(), 'persona_nombre' => 'Juan Pérez', 'firma_persona' => $this->firmaJpeg(2), 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
            'verificados' => $pase->articulos->pluck('id')->all(),
            'regresa' => $pase->articulos->mapWithKeys(fn ($a) => [$a->id => $a->pendientes()])->all(),
        ], $extra));
    }

    private function ver(PaseSalida $pase): TestResponse
    {
        return $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertOk();
    }

    /** Ninguno de los nombres que mezclaban lo programado con lo real. */
    private function sinNombresViejos(TestResponse $r): TestResponse
    {
        return $r->assertDontSee('Fecha de salida')->assertDontSee('Regreso tentativo')->assertDontSee('<dt>Salió</dt>', false)
            ->assertDontSee('<dt>Regresó</dt>', false)->assertDontSee('Regresa (tentativo)');
    }

    // ------------------------------------------------- Nombres en el resumen

    public function test_recorrido_hacia_otra_sede_muestra_cada_momento_con_su_lugar(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase();
        $this->aprobarTodo($pase);
        $this->a('2026-10-05 09:10');
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->a('2026-10-05 10:25');
        $this->paso($pase)->assertSessionHasNoErrors();

        // A medio camino: llegó a destino; la salida de regreso y el regreso, pendientes
        $this->sinNombresViejos($this->ver($pase))->assertSee('Recorrido del pase')->assertSee('Espera regreso a Sede CEN')
            ->assertSeeInOrder(['Momento', 'Programado', 'Real',
                'Sale de Sede CEN', '05/10/2026', '05/10/2026 09:10',
                'Llega a Sede PLA', '05/10/2026 10:25',
                'Sale de regreso de Sede PLA', 'Pendiente',
                'Regresa a Sede CEN', '12/10/2026', 'Pendiente']);

        $this->a('2026-10-11 15:40');
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->a('2026-10-11 16:55');
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->a('2026-10-20 12:00');

        $r = $this->sinNombresViejos($this->ver($pase))->assertSee('Ya regresó a Sede CEN')
            ->assertSeeInOrder(['Sale de Sede CEN', '05/10/2026', '05/10/2026 09:10',
                'Llega a Sede PLA', '05/10/2026 10:25',
                'Sale de regreso de Sede PLA', '11/10/2026 15:40',
                'Regresa a Sede CEN', '12/10/2026', '11/10/2026 16:55'])
            ->assertDontSee('de lo programado')->assertDontSee('¿Regresa?');
        $this->assertStringNotContainsString('Vencido', strip_tags((string) $r->getContent()));
    }

    public function test_recorrido_a_proveedor_sin_destino_y_salida_fuera_del_dia_programado(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase(['destino_tipo' => 'proveedor', 'sede_destino_id' => null, 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($pase);
        $this->a('2026-10-07 08:30');
        $this->paso($pase)->assertSessionHasNoErrors();

        $this->sinNombresViejos($this->ver($pase))
            ->assertSeeInOrder(['Sale de Sede CEN', '05/10/2026', '07/10/2026 08:30', '2 días después de lo programado',
                'Regresa a Sede CEN', '12/10/2026', 'Pendiente'])
            ->assertDontSee('Llega a')->assertDontSee('Sale de regreso de');

        // Salió un día antes de lo programado
        $this->a('2026-10-04 11:00');
        $antes = $this->crearPase(['destino_tipo' => 'proveedor', 'sede_destino_id' => null, 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($antes);
        $this->a('2026-10-04 17:00');
        $this->paso($antes)->assertSessionHasNoErrors();
        $this->ver($antes)->assertSee('1 día antes de lo programado');
    }

    public function test_salida_definitiva_solo_tiene_la_salida_y_su_leyenda(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase(['motivo' => 'venta', 'destino_tipo' => 'proveedor', 'sede_destino_id' => null, 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($pase);
        $this->a('2026-10-05 12:30');
        $this->paso($pase)->assertSessionHasNoErrors();

        $this->sinNombresViejos($this->ver($pase))->assertSee('Salida definitiva, no espera regreso')
            ->assertSeeInOrder(['Sale de Sede CEN', '05/10/2026', '05/10/2026 12:30'])
            ->assertDontSee('Regresa a Sede CEN')->assertDontSee('Espera regreso a');
    }

    public function test_regreso_parcial_de_un_colaborador_dice_cuantos_y_cuando(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase(['destino_tipo' => 'colaborador', 'sede_destino_id' => null, 'colaborador_destino_id' => $this->otroColaborador->id]);
        $this->aprobarTodo($pase);
        $this->a('2026-10-05 09:00');
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->a('2026-10-09 17:10');
        $articulo = $this->recargar($pase)->articulos->first();
        $this->paso($pase, ['regresa' => [$articulo->id => 1], 'comentario' => 'Los otros dos regresan el lunes.'])->assertSessionHasNoErrors();

        $this->a('2026-10-10 09:00');
        $this->sinNombresViejos($this->ver($pase))
            ->assertSeeInOrder(['Sale de Sede CEN', '05/10/2026 09:00', 'Regresa a Sede CEN', '12/10/2026', 'Parcial: 1 de 3 · el último, 09/10/2026 17:10'])
            ->assertDontSee('Llega a');

        // Vence con el regreso parcial: lo dice en el mismo renglón
        $this->a('2026-10-13 09:00');
        $this->ver($pase)->assertSee('Parcial: 1 de 3 · el último, 09/10/2026 17:10 · Vencido (debía regresar el 12/10/2026)');
    }

    public function test_vencido_dice_pendiente_y_desde_cuando_debia_regresar(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase(['destino_tipo' => 'proveedor', 'sede_destino_id' => null, 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($pase);
        $this->a('2026-10-05 11:15');
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->a('2026-10-20 10:00');

        $this->sinNombresViejos($this->ver($pase))->assertSee('Vencido — Debió Regresar')
            ->assertSeeInOrder(['Sale de Sede CEN', '05/10/2026 11:15', 'Regresa a Sede CEN', '12/10/2026', 'Pendiente · Vencido (debía regresar el 12/10/2026)']);
        // La lista, la hoja impresa y la verificación por QR usan los mismos nombres
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()->assertSee('Salida programada')->assertSee('Debió regresar')->assertDontSee('Regresa (tentativo)');
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id.'/imprimir')->assertOk()->assertSee('Recorrido del pase')
            ->assertSee('Pendiente · Vencido (debía regresar el 12/10/2026)')->assertDontSee('Regreso tentativo')->assertDontSee('Fecha de salida programada');
        $codigo = $this->recargar($pase)->codigo_verificacion;
        $this->actingAs($this->admin)->get('/pases-salida/verificar/'.$codigo)->assertOk()->assertSee('Recorrido del pase')
            ->assertSee('Regresa a Sede CEN')->assertDontSee('Constructora Maya');
    }

    public function test_el_formulario_dice_salida_programada_y_regreso_programado(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('Salida programada')->assertSee('Regreso programado')
            ->assertDontSee('Fecha de Salida Programada')->assertDontSee('Fecha Tentativa de Regreso');
    }

    // ------------------------------------------------------ Reglas (servidor)

    public function test_no_sale_si_su_regreso_programado_ya_paso_hasta_capturar_uno_nuevo(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase();
        $this->aprobarTodo($pase);
        $this->ver($pase)->assertDontSee('name="nuevo_regreso"', false);

        $this->a('2026-10-20 09:30');
        $this->ver($pase)->assertSee('name="nuevo_regreso"', false)->assertSee('Nuevo regreso programado')
            ->assertSee('El regreso programado (12/10/2026) ya pasó.');

        $this->paso($pase)->assertSessionHasErrors(['nuevo_regreso' => 'El regreso programado (12/10/2026) ya pasó: escribe el nuevo regreso programado antes de dar salida.']);
        $this->paso($pase, ['nuevo_regreso' => '2026-10-19'])->assertSessionHasErrors(['nuevo_regreso' => 'El nuevo regreso programado no puede ser antes de hoy (20/10/2026).']);
        $this->paso($pase, ['nuevo_regreso' => '2026-02-30'])->assertSessionHasErrors(['nuevo_regreso' => 'El nuevo regreso programado no es una fecha válida.']);
        $this->assertSame(PaseSalida::APROBADO, $this->recargar($pase)->estado, 'Sin un nuevo regreso programado no sale');

        $this->paso($pase, ['nuevo_regreso' => '2026-10-25'])->assertSessionHasNoErrors();
        $pase = $this->recargar($pase);
        $this->assertSame([PaseSalida::SALIO, '2026-10-25'], [$pase->estado, $pase->fecha_tentativa_regreso->format('Y-m-d')]);
        $cambio = $pase->bitacora->firstWhere('evento', 'regreso_reprogramado');
        $this->assertSame('Cambió el regreso programado: 12/10/2026 → 25/10/2026', $cambio->titulo);
        $this->assertSame(['antes' => '2026-10-12', 'despues' => '2026-10-25'], $cambio->detalle);
        $this->assertLessThan($pase->bitacora->firstWhere('evento', 'salida')->id, $cambio->id, 'El cambio queda antes de la salida en la bitácora');
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.regreso_reprogramado', 'auditable_id' => $pase->id]);
        // «Vencido» usa el regreso programado corregido
        $this->assertFalse($this->enEmpresa(fn () => app(AdministradorPasesSalida::class)->vencido($pase)));
        $this->ver($pase)->assertSee('Cambió el regreso programado: 12/10/2026 → 25/10/2026')->assertSee('25/10/2026');
        $this->a('2026-10-26 09:00');
        $this->assertTrue($this->enEmpresa(fn () => app(AdministradorPasesSalida::class)->vencido($this->recargar($pase))));
    }

    public function test_al_aprobar_avisa_que_la_caseta_pedira_un_nuevo_regreso(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase();
        $this->ver($pase)->assertDontSee('la caseta pedirá un nuevo regreso programado');
        $this->a('2026-10-20 09:30');
        $this->ver($pase)->assertSee('El regreso programado (12/10/2026) ya pasó: al dar salida, la caseta pedirá un nuevo regreso programado.');
    }

    public function test_sin_regreso_programado_vencido_o_sin_regreso_no_se_pide_nueva_fecha(): void
    {
        $this->a('2026-10-04 10:00');
        $venta = $this->crearPase(['motivo' => 'venta', 'destino_tipo' => 'proveedor', 'sede_destino_id' => null, 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($venta);
        $alDia = $this->crearPase(['fecha_tentativa_regreso' => '2026-10-30']);
        $this->aprobarTodo($alDia);
        $this->a('2026-10-20 09:30');
        $this->paso($venta)->assertSessionHasNoErrors();
        $this->paso($alDia)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-30', $this->recargar($alDia)->fecha_tentativa_regreso->format('Y-m-d'));
        $this->assertNull($this->recargar($alDia)->bitacora->firstWhere('evento', 'regreso_reprogramado'));
    }

    public function test_el_regreso_programado_no_puede_ser_antes_de_la_salida_programada(): void
    {
        $this->actingAs($this->admin)->post('/pases-salida', [
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => $this->solicitante->id,
            'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id,
            'fecha_salida_programada' => '2026-10-10', 'fecha_tentativa_regreso' => '2026-10-09',
            'articulos' => [['cantidad' => 1, 'equipo' => 'Radio']],
        ])->assertSessionHasErrors(['fecha_tentativa_regreso' => 'El regreso programado no puede ser antes de la salida programada.']);
        $this->assertSame(0, $this->enEmpresa(fn () => PaseSalida::count()));

        // El mismo día sí
        $this->crearPase(['fecha_salida_programada' => '2026-10-10', 'fecha_tentativa_regreso' => '2026-10-10']);
    }

    public function test_las_marcas_reales_quedan_en_orden_y_ningun_paso_se_registra_fuera_de_orden(): void
    {
        $this->a('2026-10-04 10:00');
        $pase = $this->crearPase();
        $this->aprobarTodo($pase);

        // Fuera de orden (estado): no se puede registrar el regreso ni la llegada antes de la salida
        foreach (['regreso', 'recepcion', 'salida_regreso'] as $paso) {
            $this->paso($pase, ['paso' => $paso])->assertSessionHasErrors(['paso' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        }
        $this->a('2026-10-05 10:00');
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->paso($pase, ['paso' => 'regreso'])->assertSessionHasErrors('paso');
        $this->paso($pase, ['paso' => 'salida'])->assertSessionHasErrors('paso');

        // Reloj atrasado: la llegada quedaría antes de la salida
        $this->a('2026-10-05 09:00');
        $this->paso($pase)->assertSessionHasErrors(['paso' => 'La hora de este movimiento quedaría antes del movimiento anterior del pase (05/10/2026 10:00). Revisa la hora del equipo e intenta de nuevo.']);
        $this->assertSame(PaseSalida::SALIO, $this->recargar($pase)->estado);

        foreach (['2026-10-05 11:00', '2026-10-08 15:00', '2026-10-08 16:30'] as $momento) {
            $this->a($momento);
            $this->paso($pase)->assertSessionHasNoErrors();
        }
        $p = $this->recargar($pase);
        $this->assertSame(PaseSalida::REGRESADO, $p->estado);
        $this->assertTrue($p->salio_en->lte($p->recibido_destino_en));
        $this->assertTrue($p->recibido_destino_en->lte($p->salio_regreso_en));
        $this->assertTrue($p->salio_regreso_en->lte($p->regreso_en));
        $this->assertSame($p->salio_regreso_en->toDateTimeString(), $p->marcaAnterior('regreso')->toDateTimeString());
        $this->assertNull($p->marcaAnterior('salida'));

        // Sin destino (proveedor): el regreso va después de la salida
        $this->a('2026-10-04 10:00');
        $otro = $this->crearPase(['destino_tipo' => 'proveedor', 'sede_destino_id' => null, 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($otro);
        $this->a('2026-10-06 10:00');
        $this->paso($otro)->assertSessionHasNoErrors();
        $this->a('2026-10-06 09:59');
        $this->paso($otro)->assertSessionHasErrors('paso');
        $this->a('2026-10-07 10:00');
        $this->paso($otro)->assertSessionHasNoErrors();
        $o = $this->recargar($otro);
        $this->assertTrue($o->salio_en->lt($o->regreso_en));
    }

    // ------------------------------------------------------- Datos de ejemplo

    public function test_los_pases_de_ejemplo_tienen_marcas_reales_coherentes(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $pases = $this->enEmpresa(fn () => PaseSalida::with('sede.empresa', 'articulos')->orderBy('id')->get(), $demo);
        $this->assertNotEmpty($pases);
        $servicio = app(AdministradorPasesSalida::class);
        foreach ($pases as $p) {
            $marcas = collect(PaseSalida::MARCAS_REALES)->map(fn ($c) => $p->{$c})->filter()->values();
            for ($i = 1; $i < $marcas->count(); $i++) {
                $this->assertTrue($marcas[$i]->gt($marcas[$i - 1]), "{$p->folio}: las marcas reales van en orden y no en el mismo minuto");
            }
            if ($p->salio_en !== null) {
                $this->assertNotSame($p->salio_en->format('Y-m-d H:i'), $p->regreso_en?->format('Y-m-d H:i'), "{$p->folio}: salió y regresó en el mismo minuto");
                $this->assertTrue($p->created_at->lt($p->salio_en), "{$p->folio}: se creó antes de salir");
                // Sale el día programado o a un día de distancia, entre las 7:00 y las 20:00
                $local = $p->salio_en->copy()->setTimezone($p->sede->zonaHoraria());
                $this->assertLessThanOrEqual(1, abs(Carbon::parse($p->fecha_salida_programada->format('Y-m-d'))->diffInDays(Carbon::parse($local->format('Y-m-d')))), "{$p->folio}: salida lejos de lo programado");
                $this->assertTrue((int) $local->format('G') >= 7 && (int) $local->format('G') <= 20 || $p->estado === PaseSalida::SALIO, "{$p->folio}: hora de salida poco razonable");
            }
            if ($p->regreso_en !== null) {
                $this->assertTrue($p->regreso_en->gt($p->salio_en), "{$p->folio}: regresó antes de salir");
            }
            if ($p->fecha_tentativa_regreso !== null && $p->fecha_salida_programada !== null) {
                $this->assertTrue($p->fecha_tentativa_regreso->gte($p->fecha_salida_programada), "{$p->folio}: regreso programado antes de la salida programada");
            }
        }

        // Solo el pase del taller está vencido; uno aprobado espera un nuevo regreso programado al salir
        $vencidos = $this->enEmpresa(fn () => $pases->filter(fn ($p) => $servicio->vencido($p))->map(fn ($p) => $p->articulos->first()->equipo)->values()->all(), $demo);
        $this->assertSame(['PUERTA CORREDIZA DE CRISTAL'], array_map('mb_strtoupper', $vencidos));
        $piden = $this->enEmpresa(fn () => $pases->filter(fn ($p) => $servicio->pideNuevoRegreso($p))->count(), $demo);
        $this->assertSame(1, $piden);
        // Hay un recorrido completo a otra sede (salió, llegó, salió de regreso y regresó)
        $this->assertTrue($pases->contains(fn ($p) => $p->destino_tipo === 'sede' && $p->regreso_en !== null && $p->recibido_destino_en !== null && $p->salio_regreso_en !== null));
        // El reloj volvió al presente
        $this->assertFalse(Carbon::hasTestNow());
    }
}
