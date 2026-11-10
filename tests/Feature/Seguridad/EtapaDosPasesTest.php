<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Notificacion;
use App\Models\PaseSalida;
use App\Models\PaseSalidaArticulo;
use App\Models\PaseSalidaBitacora;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\Activos\CustodiaActivos;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 2, C-18 (GC-04): los pases de salida cambian el estado del equipo.
 * Al guardar: DISPONIBLE, de la sede de origen, una pieza y en un solo pase
 * abierto. Al salir: FUERA (venta: BAJA; traspaso: DISPONIBLE en la sede
 * destino). Al regresar: DISPONIBLE. «Cerrar con faltantes»: NO REGRESÓ con
 * aviso para su voucher. Vínculos: Equipos, Responsivas, Tablero y campana.
 */
class EtapaDosPasesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $director;

    private User $gerente;

    private User $jefe;

    private Colaborador $solicitante;

    private Proveedor $proveedor;

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
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->solicitante = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1007', 'nombre' => 'Mariana', 'apellido_paterno' => 'López', 'sede_id' => $this->centro->id]));
        $this->proveedor = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Taller Maya', 'categoria' => 'proveedor', 'todas_las_sedes' => true]));
        $this->travelTo(Carbon::parse('2026-10-04 10:00', 'America/Cancun'));
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function equipo(string $serie, string $estado = 'disponible', ?Sede $sede = null): Equipo
    {
        return $this->enEmpresa(function () use ($serie, $estado, $sede) {
            $e = new Equipo(['sede_id' => ($sede ?? $this->centro)->id, 'tipo_equipo_id' => TipoEquipo::firstOrCreate(['nombre' => 'Laptop'])->id, 'numero_serie' => $serie, 'costo' => 15000]);
            $e->forceFill(['estado' => $estado])->save();

            return $e;
        });
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

    /** @param  list<Equipo>  $equipos */
    private function datos(array $equipos, array $extra = []): array
    {
        return array_merge([
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => $this->solicitante->id,
            'destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id,
            'fecha_salida_programada' => '2026-10-05', 'fecha_tentativa_regreso' => '2026-10-12',
            'articulos' => array_map(fn (Equipo $e) => ['cantidad' => 1, 'equipo' => 'Laptop', 'serie' => $e->numero_serie, 'equipo_id' => $e->id], $equipos),
        ], $extra);
    }

    private function crear(array $equipos, array $extra = []): PaseSalida
    {
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos($equipos, $extra))->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => PaseSalida::with('articulos')->orderByDesc('id')->firstOrFail());
    }

    private function aprobarTodo(PaseSalida $pase): void
    {
        $circuito = app(CircuitoPasesSalida::class);
        while (($actual = $this->enEmpresa(fn () => $circuito->actual(PaseSalida::findOrFail($pase->id)))) !== null) {
            $quien = collect([$this->admin, $this->director, $this->gerente])->first(fn (User $u) => $this->enEmpresa(fn () => $circuito->puedeAprobar($u, PaseSalida::findOrFail($pase->id), $actual)));
            app(Autorizador::class)->olvidar();
            $this->actingAs($quien)->post("/pases-salida/{$pase->id}/firmas", ['paso' => 'aprobacion', 'aprobacion_id' => $actual->id, 'firma_modo' => 'nueva', 'firma' => $this->firma()])
                ->assertSessionHasNoErrors();
        }
    }

    private function paso(PaseSalida $pase, array $extra = []): TestResponse
    {
        $pase = $this->enEmpresa(fn () => PaseSalida::with('articulos')->findOrFail($pase->id));
        $this->flushSession(); // el reloj avanzó días: sin esto la sesión vence por inactividad

        return $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/firmas", array_merge([
            'paso' => $pase->pasoFisico(), 'persona_nombre' => 'Juan Pérez', 'firma_persona' => $this->firma(), 'firma_modo' => 'nueva', 'firma' => $this->firma(),
            'verificados' => $pase->articulos->pluck('id')->all(),
            'regresa' => $pase->articulos->mapWithKeys(fn ($a) => [$a->id => $a->pendientes()])->all(),
        ], $extra));
    }

    /** Pruebas de GC: pase con un equipo dado de baja, asignado, de otra sede, cantidad 3 o repetido en otro pase. */
    public function test_al_guardar_el_equipo_debe_estar_disponible_en_la_sede_y_en_un_solo_pase(): void
    {
        $baja = $this->equipo('LT-BAJA', EstadoActivo::BAJA);
        $asignado = $this->equipo('LT-ASG', EstadoActivo::ASIGNADO);
        $otraSede = $this->equipo('LT-PLA', EstadoActivo::DISPONIBLE, $this->playa);
        $libre = $this->equipo('LT-OK');

        $error = fn (array $datos) => $this->actingAs($this->admin)->post('/pases-salida', $datos);
        $error($this->datos([$baja]))->assertSessionHasErrors(['articulos.0.equipo_id' => 'El equipo LT-BAJA no está DISPONIBLE (está DE BAJA). Solo sale un equipo disponible.']);
        $error($this->datos([$asignado]))->assertSessionHasErrors(['articulos.0.equipo_id' => 'El equipo LT-ASG no está DISPONIBLE (está ASIGNADO). Primero recíbelo en Responsivas.']);
        $error($this->datos([$otraSede]))->assertSessionHasErrors(['articulos.0.equipo_id' => 'El equipo LT-PLA es de otra sede: solo salen equipos de Sede CEN.']);
        $tres = $this->datos([$libre]);
        $tres['articulos'][0]['cantidad'] = 3;
        $error($tres)->assertSessionHasErrors(['articulos.0.equipo_id' => 'El equipo LT-OK es una sola pieza del padrón: su cantidad debe ser 1.']);
        $error($this->datos([$libre, $libre]))->assertSessionHasErrors(['articulos.1.equipo_id' => 'El equipo LT-OK está dos veces en el pase.']);
        $this->assertSame(0, $this->enEmpresa(fn () => PaseSalida::count()));

        $primero = $this->crear([$libre]);
        $error($this->datos([$libre]))->assertSessionHasErrors(['articulos.0.equipo_id' => "El equipo LT-OK ya está en el pase {$primero->folio}, que sigue abierto."]);
    }

    public function test_salida_fuera_regreso_disponible_y_cerrar_con_faltantes_no_regreso(): void
    {
        $a = $this->equipo('LT-A');
        $b = $this->equipo('LT-B');
        $pase = $this->crear([$a, $b]);
        $this->aprobarTodo($pase);
        $this->travelTo(Carbon::parse('2026-10-05 09:00', 'America/Cancun'));
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::FUERA, EstadoActivo::FUERA], [$a->fresh()->estado, $b->fresh()->estado]);
        $this->actingAs($this->admin)->get('/equipos')->assertSee('Fuera en el pase')->assertSee($pase->folio);
        // Responsivas no entrega lo que está FUERA
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->centro->id, 'colaborador_id' => $this->solicitante->id, 'equipos' => [$a->id], 'modalidades' => ['prestado'], 'firma' => $this->firma()])
            ->assertSessionHasErrors(['equipos' => 'El equipo LT-A no está DISPONIBLE (está FUERA).']);
        // Catálogo tampoco lo da de baja
        $this->actingAs($this->admin)->post("/equipos/{$a->id}/baja", ['motivo' => 'extraviado'])->assertSessionHasErrors('motivo');

        // Regresa solo A y se cierra con faltantes: B queda NO REGRESÓ con aviso
        $pase = $this->enEmpresa(fn () => PaseSalida::with('articulos')->findOrFail($pase->id));
        [$artA, $artB] = $pase->articulos->sortBy('id')->values()->all();
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'America/Cancun'));
        $this->paso($pase, ['regresa' => [$artA->id => 1, $artB->id => 0], 'comentario' => 'La B se quedó en el taller', 'cerrar_con_faltantes' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'El equipo LT-B quedó «No regresó»'));
        $this->assertSame([EstadoActivo::DISPONIBLE, EstadoActivo::NO_REGRESO], [$a->fresh()->estado, $b->fresh()->estado]);
        // Le llega al jefe de seguridad de la sede (quien cerró el pase ya lo vio en el mensaje)
        $this->assertSame([$this->jefe->id], Notificacion::withoutGlobalScopes()->where('tipo', CustodiaActivos::TIPO_AVISO)->where('titulo', 'No regresó: equipo LT-B')->pluck('user_id')->all());
        $this->actingAs($this->admin)->get('/informes/tablero')->assertSee('1 no regresaron');
    }

    public function test_venta_da_de_baja_y_traspaso_cambia_de_sede(): void
    {
        $vendido = $this->equipo('LT-VEN');
        $venta = $this->crear([$vendido], ['motivo' => 'venta']);
        $this->aprobarTodo($venta);
        $this->travelTo(Carbon::parse('2026-10-05 09:00', 'America/Cancun'));
        $this->paso($venta)->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::BAJA, $vendido->fresh()->estado);

        $traspasado = $this->equipo('LT-TRA');
        $traspaso = $this->crear([$traspasado], ['motivo' => 'traspaso_definitivo', 'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id, 'proveedor_id' => null]);
        $this->aprobarTodo($traspaso);
        $this->paso($traspaso)->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::DISPONIBLE, $this->playa->id], [$traspasado->fresh()->estado, (int) $traspasado->fresh()->sede_id]);
    }

    public function test_el_resguardo_personal_ya_no_es_un_pase(): void
    {
        $otro = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1005', 'nombre' => 'Roberto', 'apellido_paterno' => 'Hdz', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos([$this->equipo('LT-R')], ['destino_tipo' => 'colaborador', 'colaborador_destino_id' => $otro->id, 'proveedor_id' => null]))
            ->assertSessionHasErrors(['destino_tipo' => 'Para que un colaborador se lleve un equipo a su resguardo usa Responsivas (Operación → Responsivas): ahí queda a su cargo con su firma.']);
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()->assertDontSee('Un Colaborador se lo lleva')->assertSee('Eso se hace en <strong>Operación → Responsivas</strong>', false);
    }

    // ===================================================== Revisión Etapa 2 (A-1, A-2, A-3)

    /** A-1 y A-3: el equipo que no regresó de un pase cerrado propone al solicitante en su voucher y se puede «Recibir» desde su ficha. */
    public function test_equipo_que_no_regreso_de_un_pase_propone_al_solicitante_y_se_recibe_desde_su_ficha(): void
    {
        $b = $this->equipo('LT-R0');
        $a = $this->equipo('LT-R1');
        $pase = $this->crear([$b, $a]);
        $this->aprobarTodo($pase);
        $this->travelTo(Carbon::parse('2026-10-05 09:00', 'America/Cancun'));
        $this->paso($pase)->assertSessionHasNoErrors();
        $pase = $this->enEmpresa(fn () => PaseSalida::with('articulos')->findOrFail($pase->id));
        [$art, $artB] = $pase->articulos->sortBy('id')->values()->all();
        $this->travelTo(Carbon::parse('2026-10-08 12:00', 'America/Cancun'));
        $this->paso($pase, ['regresa' => [$art->id => 1, $artB->id => 0], 'comentario' => 'La B se quedó con el proveedor', 'cerrar_con_faltantes' => 1])->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::DISPONIBLE, EstadoActivo::NO_REGRESO], [$b->fresh()->estado, $a->fresh()->estado]);

        // A-3: el voucher propone al solicitante del pase
        $propuesta = $this->enEmpresa(fn () => app(CustodiaActivos::class)->responsablePropuesto($a->fresh()));
        $this->assertSame(['tipo' => 'colaborador', 'id' => $this->solicitante->id], ['tipo' => $propuesta['tipo'], 'id' => $propuesta['id']]);
        $this->flushSession();
        $this->actingAs($this->admin)->get('/equipos')->assertSee('&quot;id&quot;:'.$this->solicitante->id, false)->assertSee('Recibir');

        // El informe de pases dice cuántos faltan
        Cache::flush();
        $this->actingAs($this->admin)->get('/informes/ejecutivo')->assertOk()->assertSee('Cerrado con faltantes (faltan 1)');

        // A-1: apareció → Recibir desde su ficha, como una llave
        $this->actingAs($this->admin)->patch("/equipos/{$a->id}/recibir")->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertSame(EstadoActivo::DISPONIBLE, $a->fresh()->estado);
        // El pase cerrado lo refleja: lo regresado, su bitácora y el informe
        $this->assertSame(1, $this->enEmpresa(fn () => PaseSalidaArticulo::where('equipo_id', $a->id)->value('cantidad_regresada')));
        $nota = $this->enEmpresa(fn () => PaseSalidaBitacora::where('pase_salida_id', $pase->id)->where('evento', 'recibido_tarde')->firstOrFail());
        $this->assertStringStartsWith('Recibido después del cierre por '.$this->admin->name.', 08/10/2026', $nota->titulo);
        $this->assertStringContainsString('(LT-R1)', $nota->titulo);
        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}")->assertOk()->assertSee('Recibido después del cierre por '.$this->admin->name);
        Cache::flush();
        $this->actingAs($this->admin)->get('/informes/ejecutivo')->assertOk()->assertSee('Cerrado con faltantes · lo que faltaba se recibió después')->assertDontSee('(faltan 1)');
        $this->assertNull(Notificacion::withoutGlobalScopes()->where('tipo', CustodiaActivos::TIPO_AVISO)->where('titulo', 'No regresó: equipo LT-R1')->first()?->acciones);
        // No se «recibe» lo que no está marcado No regresó
        $this->actingAs($this->admin)->patch("/equipos/{$a->id}/recibir")->assertSessionHasErrors('estado');
    }

    /** A-2: equipo apartado por un pase que aún no sale: la ficha lo dice, responsiva y baja piden motivo y la salida no lo ignora. */
    public function test_equipo_apartado_por_un_pase_pide_motivo_y_la_salida_no_lo_ignora(): void
    {
        $a = $this->equipo('LT-AP');
        $pase = $this->crear([$a]);
        $this->assertSame(EstadoActivo::DISPONIBLE, $a->fresh()->estado);
        $this->actingAs($this->admin)->get('/equipos')->assertSee('Apartado para el pase')->assertSee($pase->folio);

        // Responsiva: avisa y pide motivo
        $entrega = ['sede_id' => $this->centro->id, 'colaborador_id' => $this->solicitante->id, 'equipos' => [$a->id], 'modalidades' => ['prestado'], 'firma' => $this->firma(), '_dialogo' => 'nuevo-resguardo'];
        $this->actingAs($this->admin)->post('/responsivas', $entrega)->assertSessionHasErrors('motivo_apartado');
        $this->assertSame(EstadoActivo::DISPONIBLE, $a->fresh()->estado);
        $this->actingAs($this->admin)->from('/responsivas')->followingRedirects()->post('/responsivas', $entrega)->assertSee('Entregar de todos modos: motivo')->assertSee('está apartado para el pase '.$pase->folio);
        // Baja: avisa y pide motivo
        $this->actingAs($this->admin)->post("/equipos/{$a->id}/baja", ['motivo' => 'danado'])->assertSessionHasErrors('motivo_apartado');
        $this->assertSame(EstadoActivo::DISPONIBLE, $a->fresh()->estado);

        // Con motivo se entrega y queda en la auditoría
        $this->actingAs($this->admin)->post('/responsivas', $entrega + ['motivo_apartado' => 'Se necesita hoy en el turno'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::EN_USO, $a->fresh()->estado);
        $this->assertTrue(Auditoria::where('evento', 'responsivas.creado')->get()->contains(fn ($r) => str_contains(json_encode($r->despues, JSON_UNESCAPED_UNICODE), 'Se necesita hoy en el turno')));

        // La salida del pase ya no lo ignora en silencio
        $this->aprobarTodo($pase);
        $this->travelTo(Carbon::parse('2026-10-05 09:00', 'America/Cancun'));
        $this->paso($pase)->assertSessionHasErrors(['verificados' => 'El equipo LT-AP ya no está DISPONIBLE (está EN USO): no puede salir con este pase. Si regresó, recíbelo primero; si no, cancela el pase y pide uno nuevo sin él.']);
        $this->assertSame(PaseSalida::APROBADO, $this->enEmpresa(fn () => PaseSalida::findOrFail($pase->id))->estado);
    }
}
