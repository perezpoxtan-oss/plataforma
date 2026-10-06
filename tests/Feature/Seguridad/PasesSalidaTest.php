<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Mail\AvisoPaseSalida;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\FirmaUsuario;
use App\Models\ModuloAccion;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaFirma;
use App\Models\PaseSalidaPaso;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class PasesSalidaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $director;

    private User $gerente;

    private Colaborador $solicitante;

    private Colaborador $otroColaborador;

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
        $this->solicitante = $this->colaborador('1007', 'Mariana', 'López', $this->centro);
        $this->otroColaborador = $this->colaborador('1005', 'Roberto', 'Hernández', $this->centro);
        $this->proveedor = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Constructora Maya', 'categoria' => 'contratista',
            'direccion' => 'Calle 20 Sur 110, Cancún', 'telefono' => '9981234567', 'todas_las_sedes' => true]));
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function colaborador(string $num, string $nombre, string $paterno, ?Sede $sede, ?Empresa $empresa = null, bool $activo = true): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno,
            'sede_id' => $sede?->id, 'activo' => $activo]), $empresa);
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
            'sede_id' => $this->centro->id,
            'motivo' => 'prestamo',
            'colaborador_id' => $this->solicitante->id,
            'destino_tipo' => 'sede',
            'sede_destino_id' => $this->playa->id,
            'destino_direccion' => ' Blvd. Kukulcán km 9 ',
            'destino_telefono' => '998 123 4567',
            'fecha_salida_programada' => '2026-10-05',
            'fecha_tentativa_regreso' => '2026-10-12',
            'articulos' => [
                ['cantidad' => 1, 'equipo' => ' Laptop ', 'marca' => 'Lenovo', 'modelo' => 'L14', 'serie' => 'ABC123', 'descripcion' => 'Con cargador'],
                ['cantidad' => '', 'equipo' => '', 'marca' => '', 'modelo' => '', 'serie' => '', 'descripcion' => ''],
                ['cantidad' => 2, 'equipo' => 'Radio', 'marca' => 'Motorola'],
            ],
        ], $extra);
    }

    private function crearPase(array $extra = [], ?User $actor = null): PaseSalida
    {
        $this->actingAs($actor ?? $this->admin)->post('/pases-salida', $this->datos($extra))->assertSessionHasNoErrors();

        return $this->recargar($this->enEmpresa(fn () => PaseSalida::orderByDesc('id')->firstOrFail()));
    }

    private function recargar(PaseSalida $pase): PaseSalida
    {
        return $this->enEmpresa(fn () => PaseSalida::with('firmas', 'articulos', 'aprobaciones.rol', 'aprobaciones.departamento', 'bitacora')->findOrFail($pase->id));
    }

    private function actual(PaseSalida $pase): ?PaseSalidaAprobacion
    {
        return $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($this->recargar($pase)));
    }

    private function aprobar(PaseSalida $pase, ?User $actor = null, array $extra = []): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)->post("/pases-salida/{$pase->id}/firmas", array_merge([
            'paso' => 'aprobacion', 'aprobacion_id' => $this->actual($pase)?->id, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
        ], $extra));
    }

    /** Aprueba todos los pasos, cada uno con una persona distinta que pueda firmarlo. */
    private function aprobarTodo(PaseSalida $pase): void
    {
        while (($actual = $this->actual($pase)) !== null) {
            $quien = collect([$this->admin, $this->director, $this->gerente])->first(fn (User $u) => $this->enEmpresa(
                fn () => app(CircuitoPasesSalida::class)->puedeAprobar($u, $this->recargar($pase), $actual)));
            $this->assertNotNull($quien, 'Nadie puede firmar el paso '.$actual->nombre);
            app(Autorizador::class)->olvidar();
            $this->aprobar($pase, $quien)->assertSessionHasNoErrors()->assertRedirect(route('pases-salida.show', $pase->id));
        }
    }

    private function paso(PaseSalida $pase, ?User $actor = null, array $extra = []): TestResponse
    {
        $pase = $this->recargar($pase);

        return $this->actingAs($actor ?? $this->admin)->post("/pases-salida/{$pase->id}/firmas", array_merge([
            'paso' => $pase->pasoFisico(), 'persona_nombre' => ' juan  pérez ', 'firma_persona' => $this->firmaJpeg(2), 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
            'verificados' => $pase->articulos->pluck('id')->all(),
            'regresa' => $pase->articulos->mapWithKeys(fn ($a) => [$a->id => $a->pendientes()])->all(),
        ], $extra));
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    /**
     * @param  list<array<string, mixed>>  $pasos
     */
    private function configurarCircuito(array $pasos, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos]);
    }

    private function rolId(string $nombre): int
    {
        return (int) Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->value('id');
    }

    // ---------------------------------------------------------------- Lista

    public function test_lista_en_fichas_con_pildoras_y_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('Pases de Salida')->assertSee('Control de equipo que sale de la propiedad — préstamo, venta, reparación y más.')
            ->assertSee('Nuevo Pase')->assertSee('Pendientes de mi firma')->assertSee('Pendientes de Aprobación')->assertSee('Aprobados, listos para salir')
            ->assertSee('Fuera de la propiedad')->assertSee('Esperando Regreso')->assertSee('Vencidos')->assertSee('Cerrados')->assertSee('Rechazados')
            ->assertSee('Mi bandeja')->assertSee('Circuito')
            ->assertSee('No se encontraron pases.')->assertSee('Nuevo Pase de Salida')
            ->assertSee('Motivo y Solicitante')->assertSee('Enviar A')->assertSee('Artículos que Salen')
            ->assertSee('Guardar y Enviar a Aprobación')->assertSee('Registrar y capturar siguiente')->assertDontSee('onclick');
    }

    public function test_la_ficha_muestra_folio_pasos_y_quien_debe_firmar(): void
    {
        $pase = $this->crearPase();

        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('id="pase-'.$pase->id.'"', false)->assertSee('PS-000001')->assertSee('Mariana López')
            ->assertSee('Préstamo · 2 artículos')->assertSee('05/10/2026')->assertSee('Pendiente de Aprobación')
            ->assertSee('Ver / Aprobar')->assertSee('Sede CEN')->assertSee('Sede PLA')->assertSee('Creado por')
            ->assertSee('aria-label="Avance del pase"', false)->assertSee('Espera la firma de: Jefe de Departamento (paso 1 de 3)')
            ->assertSee('Espera tu firma');
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_con_folio_consecutivo_circuito_copiado_bitacora_y_auditoria(): void
    {
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos())
            ->assertSessionHasNoErrors()->assertSessionHas('ok', 'Pase PS-000001 registrado y enviado a aprobación.');

        $pase = $this->recargar($this->enEmpresa(fn () => PaseSalida::firstOrFail()));
        $this->assertSame(['PS-000001', 1, PaseSalida::PENDIENTE, true, 1], [$pase->folio, $pase->folio_numero, $pase->estado, $pase->requiere_regreso, $pase->ronda]);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{20}$/', $pase->codigo_verificacion);
        $this->assertSame(['Blvd. Kukulcán km 9', '998 123 4567', '2026-10-12'], [$pase->destino_direccion, $pase->destino_telefono, $pase->fecha_tentativa_regreso->format('Y-m-d')]);
        $this->assertSame(['1x Laptop Lenovo L14 · Serie: ABC123', '2x Radio Motorola'], $pase->articulos->map->resumen()->all());
        // Sin configuración: la cadena de SEGCAT
        $this->assertSame(['Jefe de Departamento', 'Contraloría', 'Gerencia'], $pase->aprobaciones->pluck('nombre')->all());
        $this->assertSame(['pendiente'], $pase->aprobaciones->pluck('estado')->unique()->values()->all());
        $this->assertSame(['creado'], $pase->bitacora->pluck('evento')->all());
        $this->assertSame('127.0.0.1', $pase->bitacora->first()->ip);
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.creado', 'auditable_id' => $pase->id]);

        $this->assertSame('PS-000002', $this->crearPase()->folio);
        $dos = $this->crearEmpresa('Hotel Dos');
        $sedeDos = $this->crearSede($dos, 'UNO');
        $adminDos = $this->crearUsuario($dos, 'Administrador');
        $colabDos = $this->colaborador('1', 'Ana', 'Dos', $sedeDos, $dos);
        $this->actingAs($adminDos)->post('/pases-salida', $this->datos(['sede_id' => $sedeDos->id, 'colaborador_id' => $colabDos->id,
            'destino_tipo' => 'colaborador', 'sede_destino_id' => null, 'colaborador_destino_id' => $colabDos->id]))->assertSessionHasNoErrors();
        $this->assertSame('PS-000001', $this->enEmpresa(fn () => PaseSalida::firstOrFail()->folio, $dos));
    }

    public function test_venta_y_traspaso_definitivo_no_esperan_regreso(): void
    {
        foreach (['venta', 'traspaso_definitivo'] as $motivo) {
            $pase = $this->crearPase(['motivo' => $motivo]);
            $this->assertFalse($pase->requiere_regreso);
            $this->assertNull($pase->fecha_tentativa_regreso, 'Sin regreso no se guarda fecha tentativa');
            $this->assertSame(['salida'], $pase->pasosFisicos());
        }
        foreach (['prestamo', 'consignacion', 'devolucion', 'reparacion'] as $motivo) {
            $this->assertTrue($this->crearPase(['motivo' => $motivo])->requiere_regreso);
        }
    }

    public function test_validaciones_en_espanol(): void
    {
        $this->actingAs($this->admin)->post('/pases-salida', ['_dialogo' => 'crear'])
            ->assertSessionHasErrors([
                'sede_id' => 'Elige la sede de origen (de donde sale el equipo).',
                'motivo' => 'Elige el motivo de salida.',
                'colaborador_id' => 'Elige al solicitante: escanea su gafete, busca su nombre o regístralo con «Nuevo Colaborador».',
                'destino_tipo' => 'Elige el tipo de destino.',
                'articulos' => 'Agrega al menos un artículo que salga en el pase.',
            ]);

        $casos = [
            [['sede_destino_id' => $this->centro->id], 'sede_destino_id', 'La sede de destino no puede ser la misma que la sede de origen: elige una distinta.'],
            [['sede_destino_id' => null], 'sede_destino_id', 'Elige la sede destino.'],
            [['destino_tipo' => 'proveedor'], 'proveedor_id', 'Elige el proveedor destino (o regístralo con «Nuevo Proveedor»).'],
            [['destino_tipo' => 'colaborador'], 'colaborador_destino_id', 'Elige el colaborador que se lleva el equipo.'],
            [['fecha_tentativa_regreso' => '2026-10-01'], 'fecha_tentativa_regreso', 'La fecha tentativa de regreso no puede ser antes de la fecha de salida.'],
            [['destino_telefono' => 'abc'], 'destino_telefono', 'Revisa el teléfono: solo números (7 a 20), con "+" si es de otro país.'],
            [['motivo' => 'regalo'], 'motivo', 'Motivo de salida no válido.'],
            [['articulos' => [['cantidad' => 0, 'equipo' => 'Laptop']]], 'articulos.0.cantidad', 'La cantidad mínima es 1.'],
            [['colaborador_id' => $this->colaborador('9', 'Baja', 'Inactivo', $this->centro, null, false)->id], 'colaborador_id', 'Solicitante no válido: búscalo o regístralo antes de continuar.'],
        ];
        foreach ($casos as [$extra, $campo, $mensaje]) {
            $this->actingAs($this->admin)->post('/pases-salida', $this->datos($extra))->assertSessionHasErrors([$campo => $mensaje]);
        }
        $this->assertSame(0, $this->enEmpresa(fn () => PaseSalida::count()));
    }

    public function test_error_reabre_el_dialogo_y_registrar_y_capturar_siguiente(): void
    {
        $this->actingAs($this->admin)->from('/pases-salida')->post('/pases-salida', $this->datos(['_dialogo' => 'crear', 'sede_destino_id' => $this->centro->id]))
            ->assertRedirect('/pases-salida');
        $respuesta = $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('La sede de destino no puede ser la misma que la sede de origen')
            ->assertSee('Mariana López · Núm. 1007')->assertSee('value="Laptop"', false);
        $this->assertMatchesRegularExpression('/id="dialogoNuevoPase"[^>]*data-abrir-al-cargar/', $respuesta->getContent());

        // "Registrar y capturar siguiente": vuelve a la lista con el diálogo abierto
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos(['siguiente' => 1]))
            ->assertRedirect(route('pases-salida.index', ['nuevo' => 1]))->assertSessionHas('ok');
        $html = $this->actingAs($this->admin)->get('/pases-salida?nuevo=1')->getContent();
        $this->assertMatchesRegularExpression('/id="dialogoNuevoPase"[^>]*data-abrir-al-cargar/', $html);
    }

    public function test_no_acepta_datos_de_otra_empresa(): void
    {
        $dos = $this->crearEmpresa('Hotel Dos');
        $sedeDos = $this->crearSede($dos, 'UNO');
        $colabDos = $this->colaborador('77', 'Ajeno', 'Dos', $sedeDos, $dos);
        $provDos = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Ajeno SA', 'categoria' => 'proveedor']), $dos);
        $tipo = $this->enEmpresa(fn () => TipoEquipo::create(['nombre' => 'Radio']), $dos);
        $equipoDos = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $sedeDos->id, 'tipo_equipo_id' => $tipo->id, 'numero_serie' => 'R-1']), $dos);

        $this->actingAs($this->admin)->post('/pases-salida', $this->datos(['sede_id' => $sedeDos->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos(['colaborador_id' => $colabDos->id]))->assertSessionHasErrors('colaborador_id');
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos(['sede_destino_id' => $sedeDos->id]))->assertSessionHasErrors('sede_destino_id');
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos(['destino_tipo' => 'proveedor', 'proveedor_id' => $provDos->id]))->assertSessionHasErrors('proveedor_id');
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos(['destino_tipo' => 'colaborador', 'colaborador_destino_id' => $colabDos->id]))->assertSessionHasErrors('colaborador_destino_id');

        $pase = $this->crearPase(['articulos' => [['cantidad' => 1, 'equipo' => 'Radio', 'serie' => 'R-1', 'equipo_id' => $equipoDos->id]]]);
        $this->assertNull($pase->articulos->first()->equipo_id);
    }

    // ------------------------------------------------------------- Circuito

    public function test_circuito_completo_hacia_otra_sede_hasta_cerrar(): void
    {
        $pase = $this->crearPase();

        $this->aprobar($pase, $this->admin, ['comentario' => 'Adelante'])->assertSessionHas('ok', 'Aprobación registrada en el pase PS-000001. Se avisó al siguiente aprobador.');
        $this->assertSame(PaseSalida::PENDIENTE, $this->recargar($pase)->estado, 'Con un paso de tres sigue pendiente');
        $this->aprobar($pase, $this->director);
        $this->aprobar($pase, $this->gerente)->assertSessionHas('ok', 'Aprobación registrada en el pase PS-000001. El pase quedó «Aprobado, listo para salir».');
        $aprobado = $this->recargar($pase);
        $this->assertSame([PaseSalida::APROBADO, 'salida'], [$aprobado->estado, $aprobado->pasoFisico()]);
        $this->assertNotNull($aprobado->aprobado_en);
        $this->assertSame([$this->admin->id, $this->director->id, $this->gerente->id], $aprobado->aprobaciones->pluck('resuelto_por')->all());
        $this->assertSame('Adelante', $aprobado->aprobaciones->first()->comentario);

        $this->paso($pase)->assertSessionHasNoErrors()->assertSessionHas('ok', 'Listo: PS-000001 ahora está «Salió — Espera Regreso».');
        $this->assertSame('recepcion', $this->recargar($pase)->pasoFisico());
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::EN_DESTINO, $this->recargar($pase)->estado);
        $this->paso($pase)->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::EN_TRANSITO_REGRESO, $this->recargar($pase)->estado);
        $this->paso($pase)->assertSessionHasNoErrors();

        $final = $this->recargar($pase);
        $this->assertSame([PaseSalida::REGRESADO, null], [$final->estado, $final->pasoFisico()]);
        $this->assertTrue($final->cerrado());
        foreach (['aprobado_en', 'salio_en', 'recibido_destino_en', 'salio_regreso_en', 'regreso_en'] as $fecha) {
            $this->assertNotNull($final->{$fecha}, $fecha);
        }
        $this->assertCount(3 + 4 * 2, $final->firmas, '3 aprobaciones + 2 firmas por cada paso de caseta');
        $this->assertSame([2, 1], $final->articulos->pluck('cantidad_regresada')->reverse()->values()->all());
        $this->assertSame(['creado', 'aprobado', 'aprobado', 'aprobado', 'salida', 'recepcion', 'salida_regreso', 'regreso'], $final->bitacora->pluck('evento')->all());

        // Nombres en mayúsculas, firmas en el disco privado, por empresa
        $persona = $final->firmas->firstWhere('rol', 'salida_lleva');
        $this->assertSame(['JUAN PÉREZ', null], [$persona->nombre_firma, $persona->user_id]);
        $seguridad = $final->firmas->firstWhere('rol', 'salida_seguridad');
        $this->assertSame([mb_strtoupper($this->admin->name), $this->admin->id], [$seguridad->nombre_firma, $seguridad->user_id]);
        foreach ($final->firmas as $f) {
            $this->assertStringStartsWith("firmas/{$this->empresa->id}/pases-salida/", $f->firma_ruta);
            Storage::disk('local')->assertExists($f->firma_ruta);
        }

        foreach (['pases_salida.aprobado', 'pases_salida.salida_registrada', 'pases_salida.recibido_en_destino', 'pases_salida.salida_de_regreso', 'pases_salida.regresado'] as $evento) {
            $this->assertDatabaseHas('auditoria', ['evento' => $evento, 'auditable_id' => $pase->id]);
        }
        $this->assertSame(3, Auditoria::where('evento', 'pases_salida.firmado')->where('auditable_id', $pase->id)->count());

        // Cerrado: ya no acepta firmas
        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/firmas", ['paso' => 'regreso', 'persona_nombre' => 'X'])
            ->assertSessionHasErrors(['paso' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);

        // La bitácora es inmutable
        $this->assertFalse($this->enEmpresa(fn () => $final->bitacora->first()->forceFill(['titulo' => 'Otro'])->save()));
        $this->assertFalse($this->enEmpresa(fn () => $final->firmas->first()->forceFill(['nombre_firma' => 'OTRO'])->save()));
    }

    public function test_hacia_proveedor_o_colaborador_solo_salida_y_regreso(): void
    {
        foreach ([['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id], ['destino_tipo' => 'colaborador', 'colaborador_destino_id' => $this->otroColaborador->id]] as $destino) {
            $pase = $this->crearPase($destino + ['sede_destino_id' => null]);
            $this->assertSame(['salida', 'regreso'], $pase->pasosFisicos());
            $this->aprobarTodo($pase);
            $this->paso($pase)->assertSessionHasNoErrors();
            $this->assertSame('regreso', $this->recargar($pase)->pasoFisico());
            $this->paso($pase, null, ['paso' => 'recepcion'])->assertSessionHasErrors(['paso' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
            $this->paso($pase)->assertSessionHasNoErrors();
            $this->assertSame(PaseSalida::REGRESADO, $this->recargar($pase)->estado);
        }
    }

    public function test_venta_queda_cerrada_al_salir(): void
    {
        $pase = $this->crearPase(['motivo' => 'venta', 'destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id]);
        $this->aprobarTodo($pase);
        $this->paso($pase)->assertSessionHasNoErrors();

        $final = $this->recargar($pase);
        $this->assertSame([PaseSalida::SALIO, null], [$final->estado, $final->pasoFisico()]);
        $this->assertSame(['Salió — Cerrado', 'cerrado'], $final->insignia(false));
        $this->paso($pase, null, ['paso' => 'regreso'])->assertSessionHasErrors('paso');

        $this->actingAs($this->admin)->get('/pases-salida?filtro=cerrados')->assertSee('id="pase-'.$pase->id.'"', false)->assertSee('Salió — Cerrado')->assertSee('Ver Detalle');
        $this->actingAs($this->admin)->get('/pases-salida?filtro=espera_regreso')->assertDontSee('id="pase-'.$pase->id.'"', false);
    }

    public function test_pasos_en_orden_sin_saltar_ni_firmar_dos_veces(): void
    {
        $pase = $this->crearPase();
        $pasos = $pase->aprobaciones;

        // No se salta: firmar el paso 2 con el 1 pendiente
        $this->aprobar($pase, $this->admin, ['aprobacion_id' => $pasos[1]->id])
            ->assertSessionHasErrors(['aprobacion_id' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        // Sin firma, sin pase
        $this->aprobar($pase, $this->admin, ['firma' => ''])->assertSessionHasErrors(['firma' => 'Falta tu firma: firma en el recuadro antes de guardar.']);
        // Antes de aprobarse no hay pasos de caseta
        $this->paso($pase, null, ['paso' => 'salida'])->assertSessionHasErrors('paso');

        $this->aprobar($pase, $this->admin)->assertSessionHasNoErrors();
        // El mismo paso no se firma dos veces (el formulario viejo apunta al paso 1)
        $this->aprobar($pase, $this->director, ['aprobacion_id' => $pasos[0]->id])->assertSessionHasErrors('aprobacion_id');
        // Una persona no firma dos pasos si alguien más puede
        $this->aprobar($pase, $this->admin)->assertForbidden();
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertSee('Ya firmaste otro paso de este pase: este lo debe firmar otra persona.');
        $this->assertSame(1, $this->recargar($pase)->aprobaciones->where('estado', 'aprobado')->count());
    }

    public function test_si_nadie_mas_puede_una_persona_firma_todos_los_pasos(): void
    {
        foreach ([$this->director, $this->gerente] as $u) {
            $u->forceFill(['activo' => false])->save();
        }
        $pase = $this->crearPase();
        foreach (range(1, 3) as $i) {
            $this->aprobar($pase, $this->admin)->assertSessionHasNoErrors();
        }
        $this->assertSame(PaseSalida::APROBADO, $this->recargar($pase)->estado);
    }

    public function test_el_solicitante_no_aprueba_su_propio_pase(): void
    {
        $this->director->forceFill(['colaborador_id' => $this->solicitante->id])->save();
        $pase = $this->crearPase();

        $this->aprobar($pase, $this->director)->assertForbidden();
        $this->actingAs($this->director)->get('/pases-salida/'.$pase->id)->assertOk()
            ->assertSee('Eres el solicitante de este pase')->assertDontSee('data-abrir-dialogo="dialogoAprobarPase"', false);
        $this->actingAs($this->director)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'x', 'aprobacion_id' => $pase->aprobaciones[0]->id])->assertForbidden();
    }

    public function test_cada_paso_lo_firma_quien_dice_la_regla(): void
    {
        $this->configurarCircuito([
            ['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$this->director->id], 'departamento' => 'cualquiera', 'obligatorio' => 1],
            ['nombre' => 'Gerencia', 'tipo' => 'rol', 'rol_id' => $this->rolId('Administrador'), 'departamento' => 'cualquiera', 'obligatorio' => 1],
        ])->assertSessionHasNoErrors();
        $pase = $this->crearPase();

        $this->aprobar($pase, $this->admin)->assertForbidden();
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertSee('Este paso lo firma: '.$this->director->name.'.');
        $this->aprobar($pase, $this->gerente)->assertForbidden();
        $this->aprobar($pase, $this->director)->assertSessionHasNoErrors();
        $this->aprobar($pase, $this->gerente)->assertForbidden();
        $this->actingAs($this->gerente)->get('/pases-salida/'.$pase->id)->assertSee('Este paso lo firma: Rol «Administrador».');
        $this->aprobar($pase, $this->admin)->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::APROBADO, $this->recargar($pase)->estado);
    }

    public function test_rechazo_vuelve_al_solicitante_que_corrige_y_reenvia(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $pase = $this->crearPase([], $agente);
        $this->aprobar($pase, $this->admin);
        $paso2 = $this->actual($pase);

        $this->actingAs($this->director)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => '', 'aprobacion_id' => $paso2->id])
            ->assertSessionHasErrors(['motivo_rechazo' => 'Escribe el motivo del rechazo: el solicitante lo verá para corregir su pase.']);
        $this->actingAs($this->director)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'Falta la factura', 'aprobacion_id' => $paso2->id])
            ->assertSessionHas('aviso', 'Pase PS-000001 rechazado y devuelto al solicitante.');
        $rechazado = $this->recargar($pase);
        $this->assertSame([PaseSalida::RECHAZADO, 'Falta la factura', $this->director->id], [$rechazado->estado, $rechazado->motivo_rechazo, $rechazado->rechazado_por]);
        $this->assertSame(['aprobado', 'rechazado', 'pendiente'], $rechazado->aprobaciones->pluck('estado')->all());
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.rechazado', 'auditable_id' => $pase->id]);

        // Rechazado ya no se aprueba ni se rechaza
        $this->aprobar($pase, $this->gerente)->assertSessionHasErrors('aprobacion_id');
        $this->actingAs($this->director)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'otra vez'])
            ->assertSessionHasErrors(['motivo_rechazo' => 'Este pase ya no está pendiente de aprobación.']);

        // El dueño ve el motivo y "Corregir y reenviar"; otro agente no
        $this->actingAs($agente)->get('/pases-salida/'.$pase->id)->assertOk()->assertSee('Rechazado y devuelto al solicitante.')
            ->assertSee('Falta la factura')->assertSee('Corregir y reenviar')->assertSee('dialogoCorregirPase');
        $otroAgente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($otroAgente)->get('/pases-salida/'.$pase->id)->assertOk()->assertDontSee('dialogoCorregirPase');
        $this->actingAs($otroAgente)->put('/pases-salida/'.$pase->id, $this->datos())->assertForbidden();

        $this->actingAs($agente)->put('/pases-salida/'.$pase->id, $this->datos(['_dialogo' => 'corregir', 'comentario' => 'Ya va la factura',
            'articulos' => [['cantidad' => 1, 'equipo' => 'Laptop', 'serie' => 'XYZ']]]))
            ->assertSessionHasNoErrors()->assertSessionHas('ok', 'Pase PS-000001 corregido y reenviado a aprobación.');
        $reenviado = $this->recargar($pase);
        $this->assertSame([PaseSalida::PENDIENTE, 2], [$reenviado->estado, $reenviado->ronda]);
        $this->assertSame(['1x Laptop · Serie: XYZ'], $reenviado->articulos->map->resumen()->all());
        $this->assertCount(6, $reenviado->aprobaciones, 'La ronda 1 queda en el historial y la 2 empieza de cero');
        $this->assertSame(['pendiente'], $reenviado->aprobaciones->where('ronda', 2)->pluck('estado')->unique()->values()->all());
        $this->assertSame('Ya va la factura', $reenviado->bitacora->firstWhere('evento', 'reenviado')->comentario);

        // Un pase que no está rechazado no se reenvía
        $this->actingAs($agente)->put('/pases-salida/'.$pase->id, $this->datos())->assertSessionHasErrors('estado');
    }

    public function test_cancelar_solo_antes_de_aprobarse_y_por_su_dueno(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $otroAgente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $pase = $this->crearPase([], $agente);

        $this->actingAs($otroAgente)->post("/pases-salida/{$pase->id}/cancelar")->assertForbidden();
        $this->actingAs($agente)->post("/pases-salida/{$pase->id}/cancelar", ['motivo_cancelacion' => 'Ya no se necesita'])
            ->assertSessionHas('aviso', 'Pase PS-000001 cancelado.');
        $cancelado = $this->recargar($pase);
        $this->assertSame([PaseSalida::CANCELADO, 'Ya no se necesita', $agente->id], [$cancelado->estado, $cancelado->motivo_cancelacion, $cancelado->cancelado_por]);
        $this->assertTrue($cancelado->cerrado());
        $this->aprobar($pase, $this->admin)->assertSessionHasErrors('aprobacion_id');

        $aprobado = $this->crearPase([], $agente);
        $this->aprobarTodo($aprobado);
        $this->actingAs($agente)->post("/pases-salida/{$aprobado->id}/cancelar")->assertSessionHasErrors(['motivo_cancelacion' => 'Solo se cancela un pase que aún no se aprueba.']);
        $this->assertSame(PaseSalida::APROBADO, $this->recargar($aprobado)->estado);
    }

    public function test_paso_opcional_se_omite_con_comentario(): void
    {
        $this->configurarCircuito([
            ['nombre' => 'Visto bueno de Sistemas', 'tipo' => 'permiso', 'departamento' => 'cualquiera', 'obligatorio' => 0],
            ['nombre' => 'Gerencia', 'tipo' => 'permiso', 'departamento' => 'cualquiera', 'obligatorio' => 1],
        ])->assertSessionHasNoErrors();
        $pase = $this->crearPase();
        $opcional = $this->actual($pase);

        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertSee('Omitir paso')->assertSee('opcional');
        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/omitir", ['aprobacion_id' => $opcional->id, 'comentario' => ''])
            ->assertSessionHasErrors(['comentario' => 'Escribe por qué se omite este paso.']);
        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/omitir", ['aprobacion_id' => $opcional->id, 'comentario' => 'No es equipo de cómputo'])
            ->assertSessionHas('ok');
        $this->assertSame(['omitido', 'pendiente'], $this->recargar($pase)->aprobaciones->pluck('estado')->all());
        // El obligatorio no se omite
        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/omitir", ['aprobacion_id' => $this->actual($pase)->id, 'comentario' => 'x'])
            ->assertSessionHasErrors(['comentario' => 'Este paso es obligatorio: no se puede omitir.']);
    }

    // ------------------------------------------------------- Configuración

    public function test_configurar_el_circuito_por_empresa(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertOk()->assertSee('Circuito de aprobación')
            ->assertSee('Jefe de Departamento → Contraloría → Gerencia')->assertSee('Guardar circuito');
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Pases de salida')->assertSee('Configurar circuito')
            ->assertSee('Pases de salida: avisar a quien debe aprobar el siguiente paso');

        // Validaciones dentro de la pantalla
        $this->configurarCircuito([])->assertSessionHasErrors(['pasos' => 'El circuito necesita al menos un paso de aprobación.']);
        $this->configurarCircuito([['nombre' => '', 'tipo' => 'rol']])->assertSessionHasErrors([
            'pasos.0.nombre' => 'Escribe el nombre de cada paso (por ejemplo «Contraloría»).', 'pasos.0.rol_id' => 'Elige el rol que firma el paso.']);
        $this->configurarCircuito([['nombre' => 'A', 'tipo' => 'usuarios', 'usuarios' => [$this->crearUsuario($this->crearEmpresa('Otra'))->id]]])
            ->assertSessionHasErrors(['pasos.0.usuarios' => 'Elige usuarios activos de la empresa.']);
        $this->configurarCircuito([['nombre' => 'A', 'tipo' => 'permiso', 'obligatorio' => 0]])
            ->assertSessionHasErrors(['pasos' => 'Cada motivo necesita al menos un paso obligatorio: «Préstamo» no tiene ninguno.']);

        $enCurso = $this->crearPase();
        $this->configurarCircuito([
            ['nombre' => 'Jefe de Seguridad', 'tipo' => 'rol', 'rol_id' => $this->rolId('Jefe de seguridad'), 'departamento' => 'cualquiera', 'obligatorio' => 1],
            ['nombre' => 'Gerencia', 'tipo' => 'rol', 'rol_id' => $this->rolId('Administrador'), 'departamento' => 'cualquiera', 'obligatorio' => 1, 'motivos' => ['venta']],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.circuito_actualizado']);
        $this->assertSame(2, $this->enEmpresa(fn () => PaseSalidaPaso::count()));

        // Los pases en curso conservan sus pasos; los nuevos usan los nuevos y respetan el motivo
        $this->assertCount(3, $this->recargar($enCurso)->aprobaciones);
        $this->assertSame(['Jefe de Seguridad'], $this->crearPase()->aprobaciones->pluck('nombre')->all());
        $this->assertSame(['Jefe de Seguridad', 'Gerencia'], $this->crearPase(['motivo' => 'venta'])->aprobaciones->pluck('nombre')->all());

        // Otra empresa no ve ni toca este circuito
        $this->assertSame(0, $this->enEmpresa(fn () => PaseSalidaPaso::count(), $this->crearEmpresa('Hotel Dos')));

        // Restablecer la cadena de SEGCAT
        $this->actingAs($this->admin)->delete('/pases-salida/circuito')->assertSessionHas('ok');
        $this->assertSame(0, $this->enEmpresa(fn () => PaseSalidaPaso::count()));
    }

    public function test_quien_configura_el_circuito(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        // Con alcance de sede solo se consulta
        $this->actingAs($jefe)->get('/pases-salida/circuito')->assertOk()->assertSee('Solo consulta')->assertDontSee('Guardar circuito');
        $this->configurarCircuito([['nombre' => 'A', 'tipo' => 'permiso', 'obligatorio' => 1]], $jefe)->assertForbidden();
        $this->actingAs($jefe)->delete('/pases-salida/circuito')->assertForbidden();
        // El agente ni la ve
        $this->actingAs($agente)->get('/pases-salida/circuito')->assertForbidden();
        $this->actingAs($agente)->get('/pases-salida')->assertDontSee(route('pases-salida.circuito'));
        $this->assertSame(0, $this->enEmpresa(fn () => PaseSalidaPaso::count()));
    }

    // ----------------------------------------------------------- Caseta

    public function test_salida_verifica_cada_articulo_con_lector_o_a_mano(): void
    {
        $tipo = $this->enEmpresa(fn () => TipoEquipo::create(['nombre' => 'Radio de Comunicación']));
        $radio = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'numero_serie' => '752TSFQ505']));
        $pase = $this->crearPase(['articulos' => [
            ['cantidad' => 1, 'equipo' => 'Radio de Comunicación', 'serie' => '752TSFQ505', 'equipo_id' => $radio->id],
            ['cantidad' => 1, 'equipo' => 'Cargador'],
        ]]);
        $this->aprobarTodo($pase);
        [$conRadio, $cargador] = $pase->articulos->all();

        // La ventana de caseta usa el lector universal para escanear lo que sale
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertOk()->assertSee('Registrar Salida')
            ->assertSee('data-lector data-modo="buscar" data-tipos="equipo"', false)->assertSee('data-equipo-id="'.$radio->id.'"', false)
            ->assertSee('Quien se lleva el equipo')->assertSee('Guardar mi firma para usarla la próxima vez');

        $this->paso($pase, null, ['verificados' => [$conRadio->id]])
            ->assertSessionHasErrors(['verificados' => 'Marca o escanea cada artículo que sale: falta 1.']);
        $this->paso($pase, null, ['persona_nombre' => ''])->assertSessionHasErrors(['persona_nombre' => 'Escribe el nombre de quien se lleva el equipo.']);
        $this->paso($pase, null, ['firma_persona' => ''])->assertSessionHasErrors(['firma_persona' => 'Falta la firma de quien se lleva el equipo: firma en el recuadro antes de guardar.']);
        $this->assertSame(0, $this->recargar($pase)->firmas->where('grupo', 'salida')->count());

        $this->paso($pase, null, ['escaneados' => [$conRadio->id, $cargador->id]])->assertSessionHasNoErrors();
        $articulos = $this->recargar($pase)->articulos;
        $this->assertTrue($articulos[0]->verificado_con_lector);
        $this->assertFalse($articulos[1]->verificado_con_lector, 'Solo cuenta como escaneado lo que es del padrón');
        $this->assertNotNull($articulos[1]->verificado_salida_en);
        $this->assertSame('2 de 2', $this->recargar($pase)->bitacora->firstWhere('evento', 'salida')->detalle['verificados']);
    }

    public function test_recepcion_con_diferencias_pide_comentario(): void
    {
        $pase = $this->crearPase();
        $this->aprobarTodo($pase);
        $this->paso($pase);
        $this->paso($pase, null, ['verificados' => [$pase->articulos[0]->id]])
            ->assertSessionHasErrors(['comentario' => 'Hay artículos sin marcar: anota en el comentario qué falta o qué llegó diferente.']);
        $this->paso($pase, null, ['verificados' => [$pase->articulos[0]->id], 'comentario' => 'Llegó un solo radio'])->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::EN_DESTINO, $this->recargar($pase)->estado);
    }

    public function test_regreso_parcial_y_cierre_con_faltantes(): void
    {
        $pase = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $this->aprobarTodo($pase);
        $this->paso($pase);
        [$laptop, $radios] = $pase->articulos->all();

        $this->paso($pase, null, ['regresa' => [$laptop->id => 0, $radios->id => 0]])
            ->assertSessionHasErrors(['regresa' => 'Indica cuántos artículos regresan (al menos uno).']);
        $this->paso($pase, null, ['regresa' => [$radios->id => 3]])->assertSessionHasErrors(['regresa' => 'De «Radio» solo faltan 2 por regresar.']);
        $this->paso($pase, null, ['regresa' => [$laptop->id => 1, $radios->id => 1]])
            ->assertSessionHasErrors(['comentario' => 'Faltan artículos por regresar: anota en el comentario qué pasó con ellos.']);

        $this->paso($pase, null, ['regresa' => [$laptop->id => 1, $radios->id => 1], 'comentario' => 'Un radio sigue en la obra'])->assertSessionHasNoErrors();
        $parcial = $this->recargar($pase);
        $this->assertSame([PaseSalida::REGRESO_PARCIAL, 'regreso'], [$parcial->estado, $parcial->pasoFisico()]);
        $this->assertSame([1, 1], $parcial->articulos->pluck('cantidad_regresada')->all());
        $this->assertNull($parcial->regreso_en);
        $this->actingAs($this->admin)->get('/pases-salida?filtro=espera_regreso')->assertSee('id="pase-'.$pase->id.'"', false)->assertSee('Regreso parcial — faltan artículos');

        // El último radio no volverá: se cierra con faltantes
        $this->paso($pase, null, ['regresa' => [$radios->id => 0, $laptop->id => 0]])->assertSessionHasErrors('regresa');
        $otro = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $this->aprobarTodo($otro);
        $this->paso($otro);
        [$l2, $r2] = $otro->articulos->all();
        $this->paso($otro, null, ['regresa' => [$l2->id => 1, $r2->id => 1], 'cerrar_con_faltantes' => 1, 'comentario' => 'Un radio se perdió en la obra'])->assertSessionHasNoErrors();
        $cerrado = $this->recargar($otro);
        $this->assertSame([PaseSalida::REGRESADO, true], [$cerrado->estado, $cerrado->cerrado_con_faltantes]);
        $this->assertSame(['Cerrado con faltantes', 'faltantes'], $cerrado->insignia(false));

        // Lo que falta del primero regresa después: queda cerrado
        $this->paso($pase, null, ['regresa' => [$radios->id => 1]])->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::REGRESADO, $this->recargar($pase)->estado);
        $this->assertSame(['salida', 'regreso_parcial', 'regreso'], $this->recargar($pase)->bitacora->whereIn('evento', ['salida', 'regreso_parcial', 'regreso'])->pluck('evento')->values()->all());
    }

    // ------------------------------------------------------------ Permisos

    public function test_agente_ve_y_registra_pero_no_aprueba_y_firma_la_caseta_de_su_sede(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/pases-salida')->assertOk()->assertSee('Nuevo Pase')
            // Altas por verificar (ADR-0006): la caseta da de alta la empresa externa que no existe; queda pendiente de verificar
            ->assertSee('dialogoRegistroRapidoColaborador')->assertSee('dialogoAltaRapidaProveedor');
        $pase = $this->crearPase([], $agente);
        $this->assertSame($agente->id, $pase->creado_por);

        $this->aprobar($pase, $agente)->assertForbidden();
        $this->actingAs($agente)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'x'])->assertForbidden();
        $this->actingAs($agente)->post("/pases-salida/{$pase->id}/omitir", ['comentario' => 'x'])->assertForbidden();
        $this->actingAs($agente)->get('/pases-salida/circuito')->assertForbidden();
        $this->actingAs($agente)->get('/pases-salida/'.$pase->id)->assertOk()
            ->assertSee('Para aprobar se necesita el permiso «Aprobar» de Pases de salida')->assertDontSee('dialogoAprobarPase');

        $this->aprobarTodo($pase);
        $this->actingAs($agente)->get('/pases-salida/'.$pase->id)->assertSee('dialogoPasoPase');
        $this->actingAs($agente)->get('/pases-salida/mis-pendientes')->assertOk()->assertSee('Bandeja de firmas')->assertSee('id="pase-'.$pase->id.'"', false);
        $this->paso($pase, $agente)->assertSessionHasNoErrors();
        $this->assertSame(PaseSalida::SALIO, $this->recargar($pase)->estado);

        // La recepción la firma la sede destino (Playa), no el agente de Centro
        $this->paso($pase, $agente)->assertForbidden();
        $this->actingAs($agente)->get('/pases-salida/'.$pase->id)->assertSee('Tu usuario no firma esta parte');

        $this->actingAs($this->crearUsuario($this->empresa))->get('/pases-salida')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa))->post('/pases-salida', $this->datos())->assertForbidden();
    }

    public function test_director_aprueba_pero_no_registra_ni_firma_la_caseta(): void
    {
        $pase = $this->crearPase();
        $this->actingAs($this->director)->get('/pases-salida')->assertOk()->assertDontSee('data-abrir-dialogo="dialogoNuevoPase"', false);
        $this->actingAs($this->director)->post('/pases-salida', $this->datos())->assertForbidden();
        $this->aprobarTodo($pase);
        $this->paso($pase, $this->director)->assertForbidden();
    }

    public function test_alcance_de_sede_origen_y_destino(): void
    {
        $haciaPlaya = $this->crearPase();
        $alProveedor = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $jefePlaya = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);

        $this->actingAs($jefePlaya)->get('/pases-salida')->assertOk()
            ->assertSee('id="pase-'.$haciaPlaya->id.'"', false)->assertDontSee('id="pase-'.$alProveedor->id.'"', false);
        $this->actingAs($jefePlaya)->get('/pases-salida/'.$alProveedor->id)->assertNotFound();
        $this->aprobar($alProveedor, $jefePlaya)->assertNotFound();
        $this->actingAs($jefePlaya)->post("/pases-salida/{$alProveedor->id}/rechazar", ['motivo_rechazo' => 'x'])->assertNotFound();
        $this->actingAs($jefePlaya)->get("/pases-salida/{$alProveedor->id}/imprimir")->assertNotFound();

        $this->actingAs($jefePlaya)->post('/pases-salida', $this->datos())->assertSessionHasErrors(['sede_id' => 'Elige una sede de origen activa en la que puedas registrar pases.']);
        // Aprobar pide alcance en la sede de ORIGEN
        $this->aprobar($haciaPlaya, $jefePlaya)->assertForbidden();

        // Playa recibe y autoriza la salida de regreso; la salida y el regreso los firma Centro
        $this->aprobarTodo($haciaPlaya);
        $this->paso($haciaPlaya, $jefePlaya)->assertForbidden();
        $this->paso($haciaPlaya)->assertSessionHasNoErrors();
        $this->paso($haciaPlaya, $jefePlaya)->assertSessionHasNoErrors();
        $this->paso($haciaPlaya, $jefePlaya)->assertSessionHasNoErrors();
        $this->paso($haciaPlaya, $jefePlaya)->assertForbidden();
        $this->assertSame(PaseSalida::EN_TRANSITO_REGRESO, $this->recargar($haciaPlaya)->estado);

        $this->actingAs($this->admin)->get('/pases-salida?sede='.$this->playa->id)->assertSee('id="pase-'.$haciaPlaya->id.'"', false)
            ->assertDontSee('id="pase-'.$alProveedor->id.'"', false)->assertSee('Todas las sedes');
        $this->actingAs($jefePlaya)->get('/pases-salida')->assertDontSee('Todas las sedes');
    }

    public function test_cada_empresa_ve_y_toca_solo_sus_pases(): void
    {
        $pase = $this->crearPase();
        $this->aprobar($pase);
        $firma = $this->enEmpresa(fn () => PaseSalidaFirma::firstOrFail());
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $pase = $this->recargar($pase);

        $this->actingAs($ajeno)->get('/pases-salida')->assertOk()->assertDontSee('id="pase-'.$pase->id.'"', false)->assertSee('No se encontraron pases.');
        $this->actingAs($ajeno)->get('/pases-salida/'.$pase->id)->assertNotFound();
        $this->actingAs($ajeno)->get('/pases-salida?pase='.$pase->id)->assertRedirect(route('pases-salida.show', $pase->id));
        $this->aprobar($pase, $ajeno)->assertNotFound();
        $this->paso($pase, $ajeno, ['paso' => 'salida'])->assertNotFound();
        foreach (['rechazar', 'omitir', 'cancelar'] as $accion) {
            $this->actingAs($ajeno)->post("/pases-salida/{$pase->id}/{$accion}", ['motivo_rechazo' => 'x', 'comentario' => 'x'])->assertNotFound();
        }
        $this->actingAs($ajeno)->put("/pases-salida/{$pase->id}", $this->datos())->assertNotFound();
        $this->actingAs($ajeno)->get("/pases-salida/{$pase->id}/imprimir")->assertNotFound();
        $this->actingAs($ajeno)->get("/pases-salida/{$pase->id}/firmas/{$firma->id}")->assertNotFound();
        $this->actingAs($ajeno)->get("/pases-salida/verificar/{$pase->codigo_verificacion}")->assertNotFound();
    }

    public function test_superadmin_elige_la_empresa_de_trabajo(): void
    {
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/pases-salida')->assertOk()->assertSee('empresa de trabajo');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->post('/pases-salida', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/pases-salida')->assertSee('PS-000001');
    }

    // --------------------------------------------------------------- Firmas

    public function test_la_firma_solo_se_entrega_con_permiso(): void
    {
        $pase = $this->crearPase();
        $this->aprobar($pase);
        $firma = $this->enEmpresa(fn () => PaseSalidaFirma::firstOrFail());
        $otro = $this->crearPase();

        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}/firmas/{$firma->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->admin)->get("/pases-salida/{$otro->id}/firmas/{$firma->id}")->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa))->get("/pases-salida/{$pase->id}/firmas/{$firma->id}")->assertForbidden();
        $this->assertFalse(str_starts_with($firma->firma_ruta, 'public/'));
        $this->assertFileDoesNotExist(public_path($firma->firma_ruta));
    }

    public function test_firma_guardada_privada_y_reutilizable(): void
    {
        $uno = $this->crearPase();
        $dos = $this->crearPase();

        // Sin firma guardada no se puede usar
        $this->aprobar($uno, $this->admin, ['firma_modo' => 'guardada'])->assertSessionHasErrors(['firma' => 'No tienes una firma guardada: firma en el recuadro.']);
        $this->actingAs($this->admin)->get('/pases-salida/mi-firma')->assertNotFound();

        $this->aprobar($uno, $this->admin, ['guardar_firma' => 1])->assertSessionHasNoErrors();
        $guardada = $this->enEmpresa(fn () => FirmaUsuario::where('user_id', $this->admin->id)->firstOrFail());
        $this->assertStringStartsWith("firmas/{$this->empresa->id}/usuarios/", $guardada->firma_ruta);
        Storage::disk('local')->assertExists($guardada->firma_ruta);
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.firma_guardada']);

        $this->actingAs($this->admin)->get('/pases-salida/'.$dos->id)->assertSee('Usar mi firma guardada');
        $this->actingAs($this->admin)->get('/pases-salida/mi-firma')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->director)->get('/pases-salida/mi-firma')->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Asistente', $this->centro))->get('/pases-salida/mi-firma')->assertForbidden();

        // Se copia al pase: si después la borra, la del pase queda
        $this->aprobar($dos, $this->admin, ['firma_modo' => 'guardada', 'firma' => ''])->assertSessionHasNoErrors();
        $copia = $this->recargar($dos)->firmas->first();
        $this->assertNotSame($guardada->firma_ruta, $copia->firma_ruta);
        $this->actingAs($this->admin)->delete('/pases-salida/mi-firma')->assertSessionHas('ok');
        $this->assertSame(0, $this->enEmpresa(fn () => FirmaUsuario::count()));
        Storage::disk('local')->assertMissing($guardada->firma_ruta);
        Storage::disk('local')->assertExists($copia->firma_ruta);
    }

    // ------------------------------------------------------------ Avisos

    public function test_avisos_por_correo_del_circuito(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $pase = $this->crearPase([], $agente);
        Mail::assertSent(AvisoPaseSalida::class, fn ($m) => $m->tipo === 'por_aprobar' && $m->paso === 'Jefe de Departamento'
            && $m->hasTo($this->admin->email) && $m->hasTo($this->director->email) && ! $m->hasTo($agente->email));

        $this->aprobarTodo($pase);
        Mail::assertSent(AvisoPaseSalida::class, fn ($m) => $m->tipo === 'aprobado' && $m->hasTo($agente->email) && $m->folio === 'PS-000001');

        $otro = $this->crearPase([], $agente);
        $this->actingAs($this->admin)->post("/pases-salida/{$otro->id}/rechazar", ['motivo_rechazo' => 'Sin factura']);
        Mail::assertSent(AvisoPaseSalida::class, fn ($m) => $m->tipo === 'rechazado' && $m->hasTo($agente->email) && $m->comentario === 'Sin factura');

        // Se apagan desde Configuración → Avisos por correo
        $this->actingAs($this->admin)->put('/configuracion/avisos', [])->assertSessionHas('ok');
        Mail::fake();
        $this->crearPase([], $agente);
        Mail::assertNothingSent();
    }

    public function test_recordatorio_diario_de_vencidos_en_proceso(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $hoy = now($this->centro->zonaHoraria());
        $pase = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null,
            'fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'), 'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]);
        $this->aprobarTodo($pase);
        $this->paso($pase);
        Mail::fake();

        $this->artisan('plataforma:pases-vencidos')->expectsOutput('Recordatorios de pases vencidos enviados: 1.')->assertSuccessful();
        Mail::assertSent(AvisoPaseSalida::class, fn ($m) => $m->tipo === 'vencido' && $m->hasTo($this->admin->email));
        $this->assertSame($hoy->format('Y-m-d'), $this->recargar($pase)->recordatorio_vencido_en->format('Y-m-d'));
        $this->assertSame('recordatorio', $this->recargar($pase)->bitacora->last()->evento);

        // Una vez al día
        Mail::fake();
        $this->artisan('plataforma:pases-vencidos')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertStringContainsString('plataforma:pases-vencidos --si-toca', (string) file_get_contents(base_path('despliegue/desplegar.sh')));
    }

    // ----------------------------------------------- Bandeja, Inicio y filtros

    public function test_bandeja_de_firmas_y_aviso_en_inicio(): void
    {
        $this->configurarCircuito([
            ['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$this->director->id], 'obligatorio' => 1],
        ]);
        $pase = $this->crearPase();

        $this->actingAs($this->director)->get('/')->assertOk()->assertSee('1 pase de salida espera tu aprobación')->assertSee(route('pases-salida.pendientes'));
        $this->actingAs($this->gerente)->get('/')->assertOk()->assertDontSee('espera tu aprobación');
        $this->actingAs($this->director)->get('/pases-salida/mis-pendientes')->assertOk()->assertSee('Bandeja de firmas')->assertSee('id="pase-'.$pase->id.'"', false);
        $this->actingAs($this->gerente)->get('/pases-salida/mis-pendientes')->assertOk()->assertSee('Nada pendiente: no hay pases que esperen tu firma.');
        $this->actingAs($this->director)->get('/pases-salida')->assertSee('Espera tu firma');
    }

    public function test_vencido_compara_contra_la_fecha_local_de_la_sede(): void
    {
        $this->enEmpresa(fn () => $this->centro->forceFill(['zona_horaria' => 'America/Cancun'])->save());
        $hoy = now('America/Cancun');
        $base = ['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null, 'fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d')];
        $vencido = $this->crearPase($base + ['fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]);
        $alDia = $this->crearPase($base + ['fecha_tentativa_regreso' => $hoy->format('Y-m-d')]);
        $pendiente = $this->crearPase(['fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'), 'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]);
        foreach ([$vencido, $alDia] as $p) {
            $this->aprobarTodo($p);
            $this->paso($p);
        }

        $this->actingAs($this->admin)->get('/pases-salida?filtro=vencidos')->assertOk()
            ->assertSee('id="pase-'.$vencido->id.'"', false)->assertDontSee('id="pase-'.$alDia->id.'"', false)
            ->assertDontSee('id="pase-'.$pendiente->id.'"', false)
            ->assertSee('Vencido — Debió Regresar')->assertSee('Debió regresar')->assertSee($hoy->copy()->subDay()->format('d/m/Y'));
        $this->actingAs($this->admin)->get('/pases-salida/'.$vencido->id)->assertSee('debió regresar el '.$hoy->copy()->subDay()->format('d/m/Y'));
    }

    public function test_filtros_conteos_y_busqueda(): void
    {
        $pendiente = $this->crearPase();
        $aprobado = $this->crearPase(['colaborador_id' => $this->otroColaborador->id, 'articulos' => [['cantidad' => 1, 'equipo' => 'Taladro', 'serie' => 'DW-778120']]]);
        $this->aprobarTodo($aprobado);
        $rechazado = $this->crearPase();
        $this->actingAs($this->admin)->post("/pases-salida/{$rechazado->id}/rechazar", ['motivo_rechazo' => 'No']);

        $this->actingAs($this->admin)->get('/pases-salida?filtro=pendientes')->assertSee('id="pase-'.$pendiente->id.'"', false)->assertDontSee('id="pase-'.$aprobado->id.'"', false)
            ->assertSee('aria-current="page"', false);
        $this->actingAs($this->admin)->get('/pases-salida?filtro=aprobados')->assertSee('id="pase-'.$aprobado->id.'"', false)->assertSee('Ver / Registrar Salida')
            ->assertDontSee('id="pase-'.$pendiente->id.'"', false);
        $this->actingAs($this->admin)->get('/pases-salida?filtro=rechazados')->assertSee('id="pase-'.$rechazado->id.'"', false)->assertSee('Ver / Corregir')
            ->assertDontSee('id="pase-'.$pendiente->id.'"', false);
        $this->actingAs($this->admin)->get('/pases-salida?filtro=fuera')->assertSee('No se encontraron pases.');
        $this->actingAs($this->admin)->get('/pases-salida?filtro=xyz')->assertSee('id="pase-'.$pendiente->id.'"', false)->assertSee('id="pase-'.$aprobado->id.'"', false);

        $codigo = $this->recargar($aprobado)->codigo_verificacion;
        foreach (['PS-000002', 'roberto', 'hernández roberto', '1005', 'dw-778', 'taladro', $codigo, 'https://x.mx/pases-salida/verificar/'.$codigo] as $texto) {
            $this->actingAs($this->admin)->get('/pases-salida?q='.urlencode($texto))
                ->assertSee('id="pase-'.$aprobado->id.'"', false)->assertDontSee('id="pase-'.$pendiente->id.'"', false);
        }
        $this->actingAs($this->admin)->get('/pases-salida?q=nadie')->assertSee('No se encontraron pases para «nadie».')->assertSee('Limpiar');
    }

    // ------------------------------------------- Hoja, verificación y ficha

    public function test_ficha_del_pase_hoja_con_qr_y_verificacion(): void
    {
        $pase = $this->crearPase();
        $this->aprobar($pase, $this->admin, ['comentario' => 'Visto']);

        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertOk()
            ->assertSee('PS-000001')->assertSee('Resumen')->assertSee('Artículos')->assertSee('Firmas y bitácora')
            ->assertSee('Jefe de Departamento')->assertSee('Contraloría')->assertSee('Gerencia')->assertSee('Le toca firmar ahora')
            ->assertSee('«Visto»', false)->assertSee('Aprobó: Jefe de Departamento (paso 1 de 3)')->assertSee('IP 127.0.0.1')
            ->assertSee(route('pases-salida.firma', [$pase->id, $this->recargar($pase)->firmas->first()->id]))
            ->assertSee('Imprimir Pase')->assertDontSee('onclick');

        $hoja = $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id.'/imprimir')->assertOk()
            ->assertSee('Pase de Salida PS-000001')->assertSee('<svg', false)->assertSee(route('pases-salida.verificar', $pase->codigo_verificacion))
            ->assertSee('1. Jefe de Departamento')->assertSee(mb_strtoupper($this->admin->name))->assertSee('Salida física')->assertSee('Regreso a origen')
            ->assertSee('Laptop')->assertSee('ABC123')->assertSee('Sí, espera regreso');

        // Verificación del QR: con sesión, solo folio y estado
        auth()->logout();
        $this->get('/pases-salida/verificar/'.$pase->codigo_verificacion)->assertRedirect('/login');
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->playa))->get('/pases-salida/verificar/'.strtolower($pase->codigo_verificacion))->assertOk()
            ->assertSee('Pase auténtico de esta empresa')->assertSee('PS-000001')->assertSee('Aún no está aprobado')->assertDontSee('Mariana');
        $this->actingAs($this->admin)->get('/pases-salida/verificar/NOEXISTE0000')->assertNotFound();
        // Fuera de su alcance (sale de Centro hacia un proveedor): el agente de Playa no lo verifica
        $alProveedor = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->playa))->get('/pases-salida/verificar/'.$alProveedor->codigo_verificacion)->assertNotFound();

        // Sin "imprimir" no hay hoja ni botón
        $sinImprimir = $this->crearUsuario($this->empresa, 'Asistente', $this->centro);
        $rol = $this->rolId('Asistente');
        $this->enEmpresa(fn () => RolPermiso::where('rol_id', $rol)
            ->whereIn('modulo_accion_id', ModuloAccion::whereHas('modulo', fn ($m) => $m->where('clave', 'pases_salida'))->whereHas('accion', fn ($a) => $a->where('clave', 'imprimir'))->pluck('id'))
            ->delete());
        app(Autorizador::class)->olvidar();
        $this->actingAs($sinImprimir)->get('/pases-salida/'.$pase->id.'/imprimir')->assertForbidden();
        $this->actingAs($sinImprimir)->get('/pases-salida/'.$pase->id)->assertOk()->assertDontSee('Imprimir Pase');
    }

    public function test_el_formulario_usa_el_lector_universal_y_los_atajos(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('data-lector data-modo="buscar" data-tipos="colaborador"', false)
            ->assertSee('name="colaborador_id"', false)->assertSee('name="colaborador_destino_id"', false)
            ->assertSee('data-tipos="equipo"', false)->assertSee('Escanear un equipo del padrón (opcional)')
            ->assertSee('Nuevo Colaborador')->assertSee('Nuevo Proveedor')->assertSee('dialogoRegistroRapidoColaborador')->assertSee('dialogoAltaRapidaProveedor')
            ->assertSee('data-direccion="Calle 20 Sur 110, Cancún"', false);

        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=1007&tipos=colaborador')->assertJsonPath('resultados.0.id', $this->solicitante->id);
        $tipo = $this->enEmpresa(fn () => TipoEquipo::create(['nombre' => 'Radio de Comunicación']));
        $radio = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'numero_serie' => '752TSFQ505']));
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=752tsfq505&tipos=equipo')->assertJsonPath('resultados.0.id', $radio->id);
        $this->actingAs($this->admin)->getJson('/pases-salida/equipos/'.$radio->id)->assertOk()
            ->assertExactJson(['equipo_id' => $radio->id, 'equipo' => 'Radio de Comunicación', 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'serie' => '752TSFQ505', 'descripcion' => null]);

        $pase = $this->crearPase(['articulos' => [['cantidad' => 1, 'equipo' => 'Radio de Comunicación', 'serie' => '752TSFQ505', 'equipo_id' => $radio->id]]]);
        $this->assertSame($radio->id, $pase->articulos->first()->equipo_id);

        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($agentePlaya)->getJson('/pases-salida/equipos/'.$radio->id)->assertNotFound();
        $this->actingAs($this->director)->getJson('/pases-salida/equipos/'.$radio->id)->assertForbidden();
    }

    public function test_alta_provisional_del_solicitante_y_union_de_duplicado(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($agente)->postJson('/colaboradores/rapido', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id])
            ->assertCreated()->assertJsonPath('colaborador.provisional', true);
        $jorge = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Jorge')->firstOrFail());

        $pase = $this->crearPase(['colaborador_id' => $jorge->id], $agente);
        $this->assertSame($jorge->id, $pase->colaborador_id);

        $this->actingAs($rh)->put("/colaboradores/{$jorge->id}/fusionar", ['destino_id' => $this->otroColaborador->id])->assertSessionHasNoErrors();
        $this->assertSame($this->otroColaborador->id, $this->recargar($pase)->colaborador_id);
    }

    public function test_menu_enlaza_a_pases_de_salida(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('pases-salida.index'));
    }

    public function test_datos_demo_con_un_pase_en_cada_estado_solo_la_primera_vez(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $pases = $this->enEmpresa(fn () => PaseSalida::orderBy('id')->get(), $demo);
        $this->assertCount(12, $pases);
        $this->assertEqualsCanonicalizing(array_keys(PaseSalida::ESTADOS), $pases->pluck('estado')->unique()->values()->all());
        $this->assertSame(3, $this->enEmpresa(fn () => PaseSalidaPaso::count(), $demo));

        // Aprobaciones pendientes para jefe.demo y admin.demo
        foreach (['jefe.demo', 'admin.demo'] as $usuario) {
            $this->actingAs(User::where('username', $usuario)->firstOrFail())->get('/')->assertSee('pase de salida espera tu aprobación');
        }
    }
}
