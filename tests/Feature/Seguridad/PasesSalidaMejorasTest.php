<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Notificacion;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaPaso;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Pases de salida (ADR-0012): firmas de caseta configurables por movimiento y
 * motivo, circuito por valor del equipo, rechazo configurable, avisos en la
 * campana y «Llegan a mi sede». Sin configurar, todo es como siempre.
 */
class PasesSalidaMejorasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $director;

    private User $gerente;

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
        $this->solicitante = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1007', 'nombre' => 'Mariana', 'apellido_paterno' => 'López',
            'sede_id' => $this->centro->id, 'activo' => true]));
        $this->proveedor = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Constructora Maya', 'categoria' => 'contratista', 'todas_las_sedes' => true]));
    }

    // ------------------------------------------------------------- Apoyo

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
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

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => $this->solicitante->id,
            'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id,
            'fecha_salida_programada' => now()->format('Y-m-d'), 'fecha_tentativa_regreso' => now()->addDays(7)->format('Y-m-d'),
            'articulos' => [['cantidad' => 1, 'equipo' => 'Laptop', 'marca' => 'Lenovo', 'serie' => 'ABC123'], ['cantidad' => 2, 'equipo' => 'Radio']],
        ], $extra);
    }

    private function crearPase(array $extra = [], ?User $actor = null): PaseSalida
    {
        $this->actingAs($actor ?? $this->admin)->post('/pases-salida', $this->datos($extra))->assertSessionHasNoErrors();

        return $this->recargar($this->enEmpresa(fn () => PaseSalida::orderByDesc('id')->firstOrFail()));
    }

    private function recargar(PaseSalida $pase): PaseSalida
    {
        return $this->enEmpresa(fn () => PaseSalida::with('firmas', 'articulos', 'aprobaciones', 'bitacora')->findOrFail($pase->id));
    }

    private function actual(PaseSalida $pase): ?PaseSalidaAprobacion
    {
        return $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($this->recargar($pase)));
    }

    private function aprobar(PaseSalida $pase, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)->post("/pases-salida/{$pase->id}/firmas", [
            'paso' => 'aprobacion', 'aprobacion_id' => $this->actual($pase)?->id, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
        ]);
    }

    /** Quién de los aprobadores puede firmar el paso que toca. */
    private function quienFirma(PaseSalida $pase): User
    {
        $actual = $this->actual($pase);
        $quien = collect([$this->admin, $this->director, $this->gerente])->first(fn (User $u) => $this->enEmpresa(
            fn () => app(CircuitoPasesSalida::class)->puedeAprobar($u, $this->recargar($pase), $actual)));
        $this->assertNotNull($quien, 'Nadie puede firmar el paso '.$actual?->nombre);
        app(Autorizador::class)->olvidar();

        return $quien;
    }

    private function aprobarTodo(PaseSalida $pase): void
    {
        while ($this->actual($pase) !== null) {
            $this->aprobar($pase, $this->quienFirma($pase))->assertSessionHasNoErrors();
        }
    }

    private function rechazar(PaseSalida $pase, string $motivo = 'Falta la factura'): TestResponse
    {
        return $this->actingAs($this->quienFirma($pase))->post("/pases-salida/{$pase->id}/rechazar", ['aprobacion_id' => $this->actual($pase)->id, 'motivo_rechazo' => $motivo]);
    }

    /** Paso de caseta con todo lo que se puede mandar; $sin quita campos. */
    /**
     * Da salida hace unos días, cuando su regreso programado aún no pasaba
     * (hoy ya no podría salir sin capturar un nuevo regreso programado).
     */
    private function salidaHaceDias(PaseSalida $pase, ?User $actor = null, int $dias = 3): void
    {
        $this->travel(-$dias)->days();
        $this->flushSession();
        $this->paso($pase, $actor)->assertSessionHasNoErrors();
        $this->travel($dias)->days();
        $this->flushSession();
    }

    private function paso(PaseSalida $pase, ?User $actor = null, array $extra = [], array $sin = []): TestResponse
    {
        $pase = $this->recargar($pase);
        $datos = array_merge([
            'paso' => $pase->pasoFisico(), 'persona_nombre' => 'juan pérez', 'firma_persona' => $this->firmaJpeg(2), 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
            'verificados' => $pase->articulos->pluck('id')->all(),
            'regresa' => $pase->articulos->mapWithKeys(fn ($a) => [$a->id => $a->pendientes()])->all(),
        ], $extra);

        return $this->actingAs($actor ?? $this->admin)->post("/pases-salida/{$pase->id}/firmas", array_diff_key($datos, array_flip($sin)));
    }

    /**
     * El circuito de siempre (Jefe de Departamento, Contraloría, Gerencia) más los ajustes.
     *
     * @param  list<array<string, mixed>>|null  $pasos
     */
    private function configurar(array $ajustes, ?array $pasos = null): TestResponse
    {
        $pasos ??= collect(CircuitoPasesSalida::PREDETERMINADO)->map(fn ($p) => $p + ['obligatorio' => 1])->all();

        return $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos] + $ajustes);
    }

    /** Firmas de caseta: [movimiento => modo para todos], con excepciones opcionales. */
    private function firmas(array $todos, array $excepciones = []): array
    {
        $tabla = [];
        foreach (array_keys(PaseSalida::PASOS_FISICOS) as $m) {
            $tabla[$m] = ['todos' => $todos[$m] ?? 'dos', 'motivos' => $excepciones[$m] ?? []];
        }

        return $tabla;
    }

    /** @return Collection<int, Notificacion> */
    private function notificaciones(User $usuario, ?string $tipo = null)
    {
        return $this->enEmpresa(fn () => Notificacion::where('user_id', $usuario->id)->when($tipo, fn ($q) => $q->where('tipo', $tipo))->orderBy('id')->get());
    }

    // ---------------------------------------------- Omisión: idéntico a hoy

    public function test_sin_configurar_pide_exactamente_las_mismas_firmas_y_pasos_que_antes(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertOk()
            ->assertSee('Firmas en caseta')->assertSee('Al corregir y reenviar un pase rechazado')
            ->assertSee('<option value="dos" selected>Dos firmas (persona y Seguridad)</option>', false)
            ->assertSee('name="rechazo" value="inicio" checked', false)
            ->assertSee('Condición de valor')->assertSee('sin condición: aplica a cualquier valor');
        // El formulario de siempre: sin «Valor por pieza»
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()->assertDontSee('Valor por pieza');
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertDontSee('Firmas en caseta ·');

        $pase = $this->crearPase();
        $this->assertSame(['Jefe del departamento del solicitante', 'Contraloría', 'Gerencia'], $pase->aprobaciones->pluck('nombre')->all());
        $this->assertSame(['salida' => 'dos', 'recepcion' => 'dos', 'salida_regreso' => 'dos', 'regreso' => 'dos'], $pase->firmas_caseta);
        $this->assertNull($pase->valorTotal());
        $this->assertNull($this->enEmpresa(fn () => Empresa::find($this->empresa->id)->preferencias['pases_salida'] ?? null));

        // Rechazo: empieza desde el primer paso (como siempre)
        $this->aprobar($pase, $this->quienFirma($pase))->assertSessionHasNoErrors();
        $this->rechazar($pase)->assertSessionHas('aviso');
        $this->actingAs($this->admin)->put("/pases-salida/{$pase->id}", $this->datos(['comentario' => 'Listo']))->assertSessionHasNoErrors();
        $pase = $this->recargar($pase);
        $this->assertSame(2, $pase->ronda);
        $this->assertSame(['pendiente'], $pase->aprobaciones->where('ronda', 2)->pluck('estado')->unique()->values()->all());
        $this->assertSame('Jefe del departamento del solicitante', $this->actual($pase)->nombre);
        $this->aprobarTodo($pase);

        // Cada movimiento de caseta pide las dos firmas, con los mismos mensajes
        foreach (['salida' => 'Escribe el nombre de quien se lleva el equipo.', 'recepcion' => 'Escribe el nombre de quien entrega el equipo.',
            'salida_regreso' => 'Escribe el nombre de quien se lleva el equipo.', 'regreso' => 'Escribe el nombre de quien entrega el equipo.'] as $movimiento => $mensaje) {
            $this->assertSame($movimiento, $this->recargar($pase)->pasoFisico());
            $this->paso($pase, null, [], ['persona_nombre'])->assertSessionHasErrors(['persona_nombre' => $mensaje]);
            $this->paso($pase, null, [], ['firma_persona'])->assertSessionHasErrors('firma_persona');
            $this->paso($pase, null, [], ['firma'])->assertSessionHasErrors('firma');
            $this->paso($pase)->assertSessionHasNoErrors();
        }
        $pase = $this->recargar($pase);
        $this->assertSame(PaseSalida::REGRESADO, $pase->estado);
        $this->assertCount(8, $pase->firmas->where('grupo', '!=', 'aprobacion'));

        // La hoja muestra las dos firmas de cada movimiento
        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}/imprimir")->assertOk()
            ->assertSee('Quien se lleva el equipo')->assertSee('Seguridad (salida)')->assertSee('Quien entrega en destino')
            ->assertSee('Quien lo entrega al regresar')->assertDontSee('Sin firma: se registra en la plataforma');
    }

    // -------------------------------------------------- A. Firmas en caseta

    public function test_firmas_en_caseta_por_movimiento_y_por_motivo(): void
    {
        $this->configurar(['rechazo' => 'inicio', 'firmas_caseta' => $this->firmas(
            ['salida' => 'seguridad', 'regreso' => 'ninguna'],
            ['salida' => ['reparacion' => 'dos', 'venta' => ''], 'recepcion' => ['venta' => 'ninguna']],
        )])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.ajustes_actualizados']);
        $guardado = $this->enEmpresa(fn () => Empresa::find($this->empresa->id)->preferencias['pases_salida']);
        // Solo se guarda lo distinto de la omisión (venta no tiene recepción: no se guarda)
        $this->assertSame(['firmas_caseta' => ['salida' => ['todos' => 'seguridad', 'motivos' => ['reparacion' => 'dos']], 'regreso' => ['todos' => 'ninguna']]], $guardado);
        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertOk()
            ->assertSee('<option value="seguridad" selected>Solo Seguridad</option>', false);
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Firmas en caseta · Registrar Salida: Solo Seguridad · Reparación: Dos firmas (persona y Seguridad)');

        // Préstamo hacia otra sede: salida solo Seguridad, llegada y salida de regreso dos firmas, regreso sin firma
        $pase = $this->crearPase();
        $this->assertSame(['salida' => 'seguridad', 'recepcion' => 'dos', 'salida_regreso' => 'dos', 'regreso' => 'ninguna'], $pase->firmas_caseta);
        $this->aprobarTodo($pase);
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertOk()
            ->assertSee('En este pase, este movimiento solo pide la firma de Seguridad.')->assertDontSee('firma_persona_pase')
            ->assertSee('Firmas en caseta')->assertSee('Registrar Salida: Solo Seguridad · Registrar Regreso: Sin firma');
        // La verificación de artículos sigue siendo obligatoria
        $this->paso($pase, null, ['verificados' => []], ['persona_nombre', 'firma_persona'])->assertSessionHasErrors('verificados');
        $this->paso($pase, null, [], ['firma'])->assertSessionHasErrors('firma');
        $this->paso($pase, null, [], ['persona_nombre', 'firma_persona'])->assertSessionHasNoErrors();
        $salida = $this->recargar($pase)->firmas->where('grupo', 'salida');
        $this->assertSame(['salida_seguridad'], $salida->pluck('rol')->values()->all());
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.salida_registrada']);

        // Llegada: dos firmas
        $playa = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->paso($pase, $playa, [], ['persona_nombre'])->assertSessionHasErrors('persona_nombre');
        $this->paso($pase, $playa)->assertSessionHasNoErrors();
        $this->paso($pase, $playa)->assertSessionHasNoErrors();
        // Regreso: sin firma; queda el usuario, la hora y lo que regresó
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertSee('Este movimiento no pide firmas');
        $this->paso($pase, null, [], ['persona_nombre', 'firma_persona', 'firma', 'firma_modo'])->assertSessionHasNoErrors();
        $pase = $this->recargar($pase);
        $this->assertSame(PaseSalida::REGRESADO, $pase->estado);
        $this->assertCount(0, $pase->firmas->where('grupo', 'regreso'));
        $registro = $pase->bitacora->last();
        $this->assertSame(['regreso', $this->admin->name, 'ninguna'], [$registro->evento, $registro->usuario_nombre, $registro->detalle['firmas']]);
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertSee('Firmas: sin firma (registrado en la plataforma)');

        // Reparación: la excepción pide dos firmas en la salida
        $reparacion = $this->crearPase(['motivo' => 'reparacion', 'destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $this->assertSame('dos', $reparacion->modoFirma('salida'));
        $this->aprobarTodo($reparacion);
        $this->paso($reparacion, null, [], ['persona_nombre'])->assertSessionHasErrors('persona_nombre');

        // Validación
        $this->configurar(['rechazo' => 'inicio', 'firmas_caseta' => ['salida' => ['todos' => 'tres']]])
            ->assertSessionHasErrors(['firmas_caseta.salida.todos' => 'Elige las firmas de «Registrar Salida».']);
        $this->configurar(['rechazo' => 'otro'])->assertSessionHasErrors(['rechazo' => 'Elige qué pasa al corregir y reenviar un pase rechazado.']);
    }

    public function test_la_hoja_impresa_muestra_solo_los_renglones_de_firma_que_aplican(): void
    {
        $this->configurar(['rechazo' => 'inicio', 'firmas_caseta' => $this->firmas(['salida' => 'seguridad', 'regreso' => 'ninguna'])]);
        $pase = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $this->aprobarTodo($pase);

        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}/imprimir")->assertOk()
            ->assertSee('Seguridad (salida)')->assertDontSee('Quien se lleva el equipo')
            ->assertDontSee('Quien lo entrega al regresar')->assertDontSee('Seguridad (regreso)')
            ->assertSee('Sin firma: se registra en la plataforma.');

        $this->paso($pase, null, [], ['persona_nombre', 'firma_persona']);
        $this->paso($pase, null, [], ['persona_nombre', 'firma_persona', 'firma', 'firma_modo'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}/imprimir")->assertOk()
            ->assertSee('Registró '.$this->admin->name);
    }

    public function test_los_pases_en_curso_conservan_su_configuracion_de_firmas(): void
    {
        $antes = $this->crearPase();
        $this->aprobarTodo($antes);
        $this->configurar(['rechazo' => 'inicio', 'firmas_caseta' => $this->firmas(['salida' => 'ninguna'])])->assertSessionHasNoErrors();
        $despues = $this->crearPase();
        $this->aprobarTodo($despues);

        // El de antes sigue pidiendo dos firmas; el nuevo, ninguna
        $this->paso($antes, null, [], ['persona_nombre', 'firma_persona', 'firma'])->assertSessionHasErrors(['persona_nombre' => 'Escribe el nombre de quien se lleva el equipo.']);
        $this->paso($antes, null, [], ['firma_persona'])->assertSessionHasErrors('firma_persona');
        $this->paso($antes)->assertSessionHasNoErrors();
        $this->paso($despues, null, [], ['persona_nombre', 'firma_persona', 'firma', 'firma_modo'])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->recargar($antes)->firmas->where('grupo', 'salida'));
        $this->assertCount(0, $this->recargar($despues)->firmas->where('grupo', 'salida'));

        // Un pase anterior a esta versión (sin copia) pide dos firmas
        $this->enEmpresa(fn () => PaseSalida::whereKey($antes->id)->update(['firmas_caseta' => null]));
        $this->assertSame('dos', $this->recargar($antes)->modoFirma('recepcion'));

        // Restablecer vuelve a la omisión
        $this->actingAs($this->admin)->delete('/pases-salida/circuito')->assertSessionHas('ok');
        $this->assertNull($this->enEmpresa(fn () => Empresa::find($this->empresa->id)->preferencias['pases_salida'] ?? null));
        $this->assertSame('dos', $this->crearPase()->modoFirma('salida'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.circuito_restablecido']);
    }

    // ------------------------------------------------ B. Condición de valor

    public function test_condicion_de_valor_del_circuito(): void
    {
        $pasos = [
            ['nombre' => 'Jefe de Departamento', 'tipo' => 'permiso', 'departamento' => 'solicitante', 'obligatorio' => 1],
            ['nombre' => 'Gerencia', 'tipo' => 'permiso', 'obligatorio' => 1, 'valor_minimo' => '$50,000'],
            ['nombre' => 'Supervisor', 'tipo' => 'permiso', 'obligatorio' => 1, 'valor_maximo' => '5000'],
        ];
        // Validaciones
        $this->configurar([], [['nombre' => 'A', 'tipo' => 'permiso', 'obligatorio' => 1, 'valor_minimo' => 'mucho']])
            ->assertSessionHasErrors(['pasos.0.valor_minimo' => 'En el paso «A», escribe el valor en pesos (solo números, por ejemplo 50000).']);
        $this->configurar([], [['nombre' => 'A', 'tipo' => 'permiso', 'obligatorio' => 1, 'valor_minimo' => '100', 'valor_maximo' => '50']])
            ->assertSessionHasErrors(['pasos.0.valor_maximo' => 'En el paso «A», el valor «menor a» debe ser mayor que el valor «mayor o igual a».']);
        $this->configurar([], [['nombre' => 'A', 'tipo' => 'permiso', 'obligatorio' => 1, 'valor_minimo' => '100']])
            ->assertSessionHasErrors(['pasos' => 'Cada motivo necesita al menos un paso obligatorio sin condición de valor: «Préstamo» solo tiene pasos que dependen del valor.']);

        $this->configurar([], $pasos)->assertSessionHasNoErrors();
        $this->assertSame([null, 50000.0, null], $this->enEmpresa(fn () => PaseSalidaPaso::orderBy('orden')->pluck('valor_minimo')->all()));
        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertSee('value="50000"', false);
        $this->actingAs($this->admin)->get('/configuracion')->assertSee('Gerencia — quien tenga «Aprobar» · solo con valor de $50,000.00 o más');
        // El formulario pide el valor de cada artículo
        $this->actingAs($this->admin)->get('/pases-salida')->assertSee('Valor por pieza');

        $articulos = fn (?float $laptop, ?float $radio) => ['articulos' => [
            ['cantidad' => 1, 'equipo' => 'Laptop', 'valor' => $laptop], ['cantidad' => 2, 'equipo' => 'Radio', 'valor' => $radio]]];

        // $1,000 + 2 × $500 = $2,000: Jefe y Supervisor (menor a $5,000)
        $barato = $this->crearPase($articulos(1000, 500));
        $this->assertSame(2000.0, $barato->valorTotal());
        $this->assertSame(['Jefe de Departamento', 'Supervisor'], $barato->aprobaciones->pluck('nombre')->all());
        // $30,000 + 2 × $12,500 = $55,000: Jefe y Gerencia
        $caro = $this->crearPase($articulos(30000, 12500));
        $this->assertSame(55000.0, $caro->valorTotal());
        $this->assertSame(['Jefe de Departamento', 'Gerencia'], $caro->aprobaciones->pluck('nombre')->all());
        $this->actingAs($this->admin)->get('/pases-salida/'.$caro->id)->assertSee('$55,000.00 MXN')->assertSee('solo con valor de $50,000.00 o más')
            ->assertSee('Valor: $12,500.00 c/u · $25,000.00');
        // Sin valor en algún artículo: por seguridad, los pasos con condición SÍ aplican
        $sinValor = $this->crearPase($articulos(1000, null));
        $this->assertNull($sinValor->valorTotal());
        $this->assertSame(['Jefe de Departamento', 'Gerencia', 'Supervisor'], $sinValor->aprobaciones->pluck('nombre')->all());

        // El valor del padrón manda sobre lo capturado
        $tipo = $this->enEmpresa(fn () => TipoEquipo::create(['nombre' => 'Radio de Comunicación']));
        $radio = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA',
            'numero_serie' => '752TSFQ505', 'costo' => 60000]));
        $this->actingAs($this->admin)->getJson('/pases-salida/equipos/'.$radio->id)->assertJsonPath('valor', '60000.00');
        $padron = $this->crearPase(['articulos' => [['cantidad' => 1, 'equipo' => 'Radio', 'equipo_id' => $radio->id, 'valor' => 1]]]);
        $this->assertSame(60000.0, $padron->valorTotal());
        $this->assertSame(['Jefe de Departamento', 'Gerencia'], $padron->aprobaciones->pluck('nombre')->all());

        $this->actingAs($this->admin)->post('/pases-salida', $this->datos($articulos(-1, 1)))->assertSessionHasErrors(['articulos.0.valor' => 'El valor de un artículo no puede ser negativo.']);
    }

    // ------------------------------------------------------- C. Rechazo

    public function test_rechazo_retomar_desde_el_paso_que_rechazo_y_recalculo(): void
    {
        $this->configurar(['rechazo' => 'retomar', 'firmas_caseta' => $this->firmas([])], [
            ['nombre' => 'Jefe de Departamento', 'tipo' => 'permiso', 'departamento' => 'solicitante', 'obligatorio' => 1],
            ['nombre' => 'Contraloría', 'tipo' => 'permiso', 'obligatorio' => 1],
            ['nombre' => 'Gerencia', 'tipo' => 'permiso', 'obligatorio' => 1, 'valor_minimo' => '50000'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('retomar', $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->rechazo()));
        $con = fn (float $valor) => ['articulos' => [['cantidad' => 1, 'equipo' => 'Laptop', 'valor' => $valor]]];

        // 1. Rechaza Contraloría; al reenviar se conserva el Jefe y retoma en Contraloría
        $pase = $this->crearPase($con(1000));
        $this->assertSame(['Jefe de Departamento', 'Contraloría'], $pase->aprobaciones->pluck('nombre')->all());
        $jefe = $this->quienFirma($pase);
        $this->aprobar($pase, $jefe)->assertSessionHasNoErrors();
        $this->rechazar($pase)->assertSessionHas('aviso');
        // Al corregir subió el valor: ahora también aplica Gerencia
        $this->actingAs($this->admin)->put("/pases-salida/{$pase->id}", $this->datos($con(60000) + ['comentario' => 'Valor corregido']))->assertSessionHasNoErrors();
        $pase = $this->recargar($pase);
        $ronda = $pase->aprobaciones->where('ronda', 2)->values();
        $this->assertSame(['Jefe de Departamento', 'Contraloría', 'Gerencia'], $ronda->pluck('nombre')->all());
        $this->assertSame(['aprobado', 'pendiente', 'pendiente'], $ronda->pluck('estado')->all());
        $this->assertSame([1, null, null], $ronda->pluck('conservada_de_ronda')->all());
        $this->assertSame($jefe->id, (int) $ronda[0]->resuelto_por);
        $this->assertNotNull($ronda[0]->firma_id);
        $this->assertSame('Contraloría', $this->actual($pase)->nombre);
        $this->assertTrue($pase->bitacora->contains(fn ($b) => $b->evento === 'firma' && str_contains($b->titulo, 'Se conservan las firmas de la ronda anterior: Jefe de Departamento')));
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertSee('firma conservada del envío anterior')->assertSee('Ronda 1 (antes de corregir)');
        // Quien firmó el paso conservado no firma otro de la misma ronda (si hay alguien más)
        $this->assertNotSame($jefe->id, $this->quienFirma($pase)->id);
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.reenviado']);

        // 2. Si al corregir ya no falta ninguna firma, queda aprobado
        $otro = $this->crearPase($con(60000));
        $this->aprobar($otro, $this->quienFirma($otro));
        $this->aprobar($otro, $this->quienFirma($otro));
        $this->assertSame('Gerencia', $this->actual($otro)->nombre);
        $this->rechazar($otro, 'Baja el valor: es préstamo interno');
        $this->actingAs($this->admin)->put("/pases-salida/{$otro->id}", $this->datos($con(1000)))->assertSessionHasNoErrors()
            ->assertSessionHas('ok');
        $otro = $this->recargar($otro);
        $this->assertSame(PaseSalida::APROBADO, $otro->estado);
        $this->assertSame(['aprobado', 'aprobado'], $otro->aprobaciones->where('ronda', 2)->pluck('estado')->values()->all());
        $this->assertSame('Todas las firmas que aplican ya estaban: queda aprobado', $otro->bitacora->last()->titulo);

        // 3. «Empezar desde el primer paso» (omisión): todo pendiente otra vez
        $this->configurar(['rechazo' => 'inicio', 'firmas_caseta' => $this->firmas([])]);
        $tercero = $this->crearPase($con(1000));
        $this->aprobar($tercero, $this->quienFirma($tercero));
        $this->rechazar($tercero);
        $this->actingAs($this->admin)->put("/pases-salida/{$tercero->id}", $this->datos($con(1000)))->assertSessionHasNoErrors();
        $this->assertSame(['pendiente', 'pendiente', 'pendiente'], $this->recargar($tercero)->aprobaciones->where('ronda', 2)->pluck('estado')->values()->all());
        $this->assertSame('Jefe del departamento del solicitante', $this->actual($tercero)->nombre);
    }

    // -------------------------------------------------------- D. Campana

    public function test_avisos_en_la_campana_y_su_resolucion(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $hoy = now($this->centro->zonaHoraria());
        $pase = $this->crearPase(['fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'),
            'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')], $agente);

        // 1. «Te toca firmar» a quien puede firmar el paso, con botón que abre la ficha
        $aviso = $this->notificaciones($this->director, 'pase_por_firmar')->first();
        $this->assertSame('Te toca firmar el pase PS-000001', $aviso->titulo);
        $this->assertStringContainsString('Jefe del departamento del solicitante (paso 1 de 3)', $aviso->texto);
        $this->assertCount(0, $this->notificaciones($agente, 'pase_por_firmar'));
        $this->actingAs($this->director)->getJson('/notificaciones/resumen')->assertOk()
            ->assertJsonPath('lista.0.titulo', 'Te toca firmar el pase PS-000001')
            ->assertJsonPath('lista.0.acciones.0.etiqueta', 'Abrir pase')
            ->assertJsonPath('lista.0.acciones.0.url', route('notificaciones.abrir', $aviso->id));
        $this->actingAs($this->director)->post(route('notificaciones.abrir', $aviso->id))->assertRedirect(route('pases-salida.show', $pase->id));

        // Firmó otro: el aviso pierde su botón y queda leído para todos; llega el del paso 2
        $this->aprobar($pase, $this->admin)->assertSessionHasNoErrors();
        $this->assertNull($this->notificaciones($this->gerente, 'pase_por_firmar')->first()->acciones);
        $this->assertNotNull($this->notificaciones($this->gerente, 'pase_por_firmar')->first()->leida_en);
        $this->assertSame('Te toca firmar el pase PS-000001', $this->notificaciones($this->gerente, 'pase_por_firmar')->last()->titulo);
        $this->assertNotNull($this->notificaciones($this->gerente, 'pase_por_firmar')->last()->acciones);

        // 2. Rechazado (motivo) al solicitante / quien lo registró
        $this->rechazar($pase, 'Falta la factura');
        $rechazo = $this->notificaciones($agente, 'pase_rechazado')->sole();
        $this->assertSame('Tu pase PS-000001 fue rechazado', $rechazo->titulo);
        $this->assertStringContainsString('Motivo: Falta la factura', $rechazo->texto);
        $this->assertTrue($this->notificaciones($this->gerente, 'pase_por_firmar')->every(fn ($n) => $n->acciones === null));

        // Cancelar resuelve lo pendiente
        $cancelado = $this->crearPase([], $agente);
        $this->actingAs($agente)->post("/pases-salida/{$cancelado->id}/cancelar", ['motivo_cancelacion' => 'Ya no'])->assertSessionHas('aviso');
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('referencia_tipo', 'pase_salida_aprobacion')
            ->whereIn('referencia_id', $this->recargar($cancelado)->aprobaciones->pluck('id'))->get())->every(fn ($n) => $n->acciones === null && $n->leida_en !== null));

        // 3. Aprobado
        $this->actingAs($agente)->put("/pases-salida/{$pase->id}", $this->datos(['fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'),
            'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]))->assertSessionHasNoErrors();
        $this->aprobarTodo($pase);
        $this->assertSame('Tu pase PS-000001 fue aprobado', $this->notificaciones($agente, 'pase_aprobado')->sole()->titulo);

        // 4. «Un pase viene a tu sede» a la caseta destino al salir; se resuelve al confirmar la llegada
        $this->salidaHaceDias($pase, $agente);
        $llega = $this->notificaciones($agentePlaya, 'pase_llega')->sole();
        $this->assertSame('Un pase viene a tu sede: PS-000001', $llega->titulo);
        $this->assertStringContainsString('«Confirmar llegada a esta sede»', $llega->texto);
        $this->assertCount(0, $this->notificaciones($agente, 'pase_llega'));
        $this->paso($pase, $agentePlaya)->assertSessionHasNoErrors();
        $this->assertNotNull($this->notificaciones($agentePlaya, 'pase_llega')->sole()->leida_en);

        // 5. Vencido: a dueños y responsables, una vez al día; se resuelve al regresar
        $this->artisan('plataforma:pases-vencidos')->expectsOutput('Recordatorios de pases vencidos enviados: 1.')->assertSuccessful();
        $vencido = $this->notificaciones($agente, 'pase_vencido')->sole();
        $this->assertSame('Pase PS-000001 vencido — debió regresar el '.$hoy->copy()->subDay()->format('d/m/Y'), $vencido->titulo);
        $this->assertCount(1, $this->notificaciones($this->admin, 'pase_vencido'));
        $this->artisan('plataforma:pases-vencidos')->assertSuccessful();
        $this->assertCount(1, $this->notificaciones($agente, 'pase_vencido'));
        $this->paso($pase, $agentePlaya)->assertSessionHasNoErrors();
        $this->paso($pase, $agente)->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::REGRESADO, $this->recargar($pase)->estado);
        $this->assertNotNull($this->notificaciones($agente, 'pase_vencido')->sole()->leida_en);
    }

    public function test_el_recordatorio_de_vencidos_llega_a_la_campana_aunque_el_correo_este_apagado(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/avisos', [])->assertSessionHas('ok');
        $hoy = now($this->centro->zonaHoraria());
        $pase = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null,
            'fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'), 'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]);
        $this->aprobarTodo($pase);
        $this->salidaHaceDias($pase);

        $this->artisan('plataforma:pases-vencidos')->expectsOutput('Recordatorios de pases vencidos enviados: 1.')->assertSuccessful();
        $this->assertCount(1, $this->notificaciones($this->admin, 'pase_vencido'));
        $this->assertSame('Se envió el recordatorio de vencido en la campana', $this->recargar($pase)->bitacora->last()->titulo);
    }

    // ------------------------------------------------ E. Llegan a mi sede

    public function test_llegan_a_mi_sede_con_alcance_y_nombres_de_botones(): void
    {
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $agenteCentro = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $haciaPlaya = $this->crearPase();
        $alProveedor = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $haciaCentro = $this->crearPase(['sede_id' => $this->playa->id, 'sede_destino_id' => $this->centro->id]);
        $pendiente = $this->crearPase();
        foreach ([$haciaPlaya, $alProveedor, $haciaCentro] as $p) {
            $this->aprobarTodo($p);
        }

        // La caseta de Playa ve lo que le llega (aprobado, aún no sale) y qué hará
        $this->actingAs($agentePlaya)->get('/pases-salida')->assertOk()->assertSee('Llegan a mi sede');
        $this->actingAs($agentePlaya)->get('/pases-salida?filtro=llegan')->assertOk()
            ->assertSee('id="pase-'.$haciaPlaya->id.'"', false)->assertDontSee('id="pase-'.$alProveedor->id.'"', false)
            ->assertDontSee('id="pase-'.$haciaCentro->id.'"', false)->assertDontSee('id="pase-'.$pendiente->id.'"', false)
            ->assertSee('Viene a Sede PLA: aún no sale de Sede CEN.')->assertSee('Cuando llegue, toca «Confirmar llegada a esta sede».');
        // Centro solo ve lo que va a Centro
        $this->actingAs($agenteCentro)->get('/pases-salida?filtro=llegan')->assertOk()
            ->assertSee('id="pase-'.$haciaCentro->id.'"', false)->assertDontSee('id="pase-'.$haciaPlaya->id.'"', false);
        // Quien no firma la caseta no ve la píldora
        $this->actingAs($this->director)->get('/pases-salida')->assertOk()->assertDontSee('Llegan a mi sede');

        // En camino: el botón dice qué hacer en la sede destino
        $this->paso($haciaPlaya)->assertSessionHasNoErrors();
        $this->actingAs($agentePlaya)->get('/pases-salida?filtro=llegan')->assertSee('En camino a Sede PLA.')->assertSee('Ver / Confirmar llegada a destino');
        $this->actingAs($agentePlaya)->get('/pases-salida/'.$haciaPlaya->id)->assertOk()
            ->assertSee('Confirmar llegada a esta sede')->assertSee('Caseta de Sede PLA (destino): «Confirmar llegada a esta sede» cuando llegue.');
        $this->paso($haciaPlaya, $agentePlaya)->assertSessionHasNoErrors();
        $this->actingAs($agentePlaya)->get('/pases-salida/'.$haciaPlaya->id)->assertSee('Autorizar salida de regreso');
        // Ya llegó: sale de la lista
        $this->actingAs($agentePlaya)->get('/pases-salida?filtro=llegan')->assertDontSee('id="pase-'.$haciaPlaya->id.'"', false)
            ->assertSee('No viene ningún pase a tu sede.');
    }
}
