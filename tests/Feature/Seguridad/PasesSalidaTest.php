<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\ModuloAccion;
use App\Models\PaseSalida;
use App\Models\PaseSalidaFirma;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function firmaJpeg(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
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

        return $this->enEmpresa(fn () => PaseSalida::orderByDesc('id')->firstOrFail());
    }

    private function firmar(PaseSalida $pase, string $rol, ?User $actor = null, array $extra = []): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)->post("/pases-salida/{$pase->id}/firmas",
            array_merge(['rol' => $rol, 'nombre_firma' => ' juan  pérez ', 'firma' => $this->firmaJpeg()], $extra));
    }

    private function firmarGrupo(PaseSalida $pase, string $grupo, ?User $actor = null): void
    {
        foreach (array_keys(PaseSalida::GRUPOS[$grupo][1]) as $rol) {
            $this->firmar($pase, $rol, $actor)->assertSessionHasNoErrors()->assertRedirect(route('pases-salida.index', ['pase' => $pase->id]));
        }
    }

    private function recargar(PaseSalida $pase): PaseSalida
    {
        return $this->enEmpresa(fn () => PaseSalida::with('firmas', 'articulos')->findOrFail($pase->id));
    }

    // ---------------------------------------------------------------- Lista

    public function test_lista_con_textos_y_filtros_de_segcat_y_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('Pases de Salida')->assertSee('Control de equipo que sale de la propiedad — préstamo, venta, reparación y más.')
            ->assertSee('Nuevo Pase')->assertSee('Pendientes de Aprobación')->assertSee('Aprobados, listos para salir')
            ->assertSee('Fuera de la propiedad')->assertSee('Esperando Regreso')->assertSee('Vencidos')
            ->assertSee('No se encontraron pases.')->assertSee('Nuevo Pase de Salida')
            ->assertSee('Motivo y Solicitante')->assertSee('Enviar A')->assertSee('Artículos que Salen')
            ->assertSee('Guardar y Enviar a Aprobación')->assertDontSee('onclick');
    }

    public function test_la_tarjeta_muestra_folio_solicitante_motivo_y_boton_segun_el_estado(): void
    {
        $pase = $this->crearPase();

        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('id="pase-'.$pase->id.'"', false)->assertSee('PS-000001')->assertSee('Mariana López')
            ->assertSee('Préstamo')->assertSee('2 artículos')->assertSee('Sale: 05/10/2026')->assertSee('Pendiente de Aprobación')
            ->assertSee('Ver / Aprobar')->assertSee('Sede CEN')->assertSee('Sede PLA')->assertSee('Creado por');
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_con_folio_consecutivo_por_empresa_articulos_y_auditoria(): void
    {
        $this->actingAs($this->admin)->post('/pases-salida', $this->datos())
            ->assertSessionHasNoErrors()->assertSessionHas('ok', 'Pase PS-000001 registrado y enviado a aprobación.');

        $pase = $this->recargar($this->enEmpresa(fn () => PaseSalida::firstOrFail()));
        $this->assertSame(['PS-000001', 1, PaseSalida::PENDIENTE, true, $this->centro->id, $this->playa->id],
            [$pase->folio, $pase->folio_numero, $pase->estado, $pase->requiere_regreso, $pase->sede_id, $pase->sede_destino_id]);
        $this->assertSame(['Blvd. Kukulcán km 9', '998 123 4567', '2026-10-12'], [$pase->destino_direccion, $pase->destino_telefono, $pase->fecha_tentativa_regreso->format('Y-m-d')]);
        $this->assertSame(['1x Laptop Lenovo L14 · Serie: ABC123', '2x Radio Motorola'], $pase->articulos->map->resumen()->all());
        $this->assertSame($this->admin->id, $pase->creado_por);
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.creado', 'auditable_id' => $pase->id]);

        // El folio es consecutivo por empresa: otra empresa empieza en PS-000001
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

    public function test_error_reabre_el_dialogo_con_el_mensaje_dentro_y_conserva_el_solicitante(): void
    {
        $this->actingAs($this->admin)->from('/pases-salida')->post('/pases-salida', $this->datos(['_dialogo' => 'crear', 'sede_destino_id' => $this->centro->id]))
            ->assertRedirect('/pases-salida');

        $respuesta = $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('La sede de destino no puede ser la misma que la sede de origen')
            ->assertSee('Mariana López · Núm. 1007')->assertSee('value="Laptop"', false);
        $this->assertMatchesRegularExpression('/id="dialogoNuevoPase"[^>]*data-abrir-al-cargar/', $respuesta->getContent());
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

        // Un equipo de otra empresa no se liga: se guarda solo el texto
        $pase = $this->crearPase(['articulos' => [['cantidad' => 1, 'equipo' => 'Radio', 'serie' => 'R-1', 'equipo_id' => $equipoDos->id]]]);
        $this->assertNull($this->recargar($pase)->articulos->first()->equipo_id);
    }

    // ------------------------------------------------------------- Circuito

    public function test_circuito_completo_hacia_otra_sede_hasta_regresar(): void
    {
        $pase = $this->crearPase();

        $this->firmar($pase, 'jefe_depto')->assertSessionHas('ok', 'Firma de «Jefe de Departamento» registrada en el pase PS-000001.');
        $this->assertSame(PaseSalida::PENDIENTE, $this->recargar($pase)->estado, 'Con una firma de tres sigue pendiente');
        $this->firmar($pase, 'contraloria_salida');
        $this->firmar($pase, 'gerencia')->assertSessionHas('ok', 'Firma de «Gerencia» registrada en el pase PS-000001. El pase avanzó a «Aprobado, listo para salir».');
        $this->assertSame(PaseSalida::APROBADO, $this->recargar($pase)->estado);
        $this->assertNotNull($this->recargar($pase)->aprobado_en);

        $this->firmarGrupo($pase, 'salida_fisica');
        $this->assertSame(PaseSalida::SALIO, $this->recargar($pase)->estado);
        $this->assertSame('recepcion_destino', $this->recargar($pase)->grupoAbierto());

        $this->firmarGrupo($pase, 'recepcion_destino');
        $this->assertSame(PaseSalida::EN_DESTINO, $this->recargar($pase)->estado);

        $this->firmarGrupo($pase, 'salida_regreso');
        $this->assertSame(PaseSalida::EN_TRANSITO_REGRESO, $this->recargar($pase)->estado);

        $this->firmarGrupo($pase, 'regreso');
        $final = $this->recargar($pase);
        $this->assertSame(PaseSalida::REGRESADO, $final->estado);
        $this->assertNull($final->grupoAbierto());
        $this->assertCount(20, $final->firmas);
        foreach (['aprobado_en', 'salio_en', 'recibido_destino_en', 'salio_regreso_en', 'regreso_en'] as $fecha) {
            $this->assertNotNull($final->{$fecha}, $fecha);
        }

        // Nombre en mayúsculas y firma en el disco privado, por empresa
        $firma = $final->firmas->first();
        $this->assertSame('JUAN PÉREZ', $firma->nombre_firma);
        $this->assertStringStartsWith("firmas/{$this->empresa->id}/pases-salida/", $firma->firma_ruta);
        Storage::disk('local')->assertExists($firma->firma_ruta);

        foreach (['pases_salida.aprobado', 'pases_salida.salida_registrada', 'pases_salida.recibido_en_destino', 'pases_salida.salida_de_regreso', 'pases_salida.regresado'] as $evento) {
            $this->assertDatabaseHas('auditoria', ['evento' => $evento, 'auditable_id' => $pase->id]);
        }
        $this->assertSame(20, Auditoria::where('evento', 'pases_salida.firmado')->where('auditable_id', $pase->id)->count());

        // Ya cerrado: no acepta más firmas
        $this->firmar($pase, 'recibe_regreso')->assertSessionHasErrors(['rol' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
    }

    public function test_hacia_proveedor_o_colaborador_no_hay_recepcion_en_destino(): void
    {
        foreach ([['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id], ['destino_tipo' => 'colaborador', 'colaborador_destino_id' => $this->otroColaborador->id]] as $destino) {
            $pase = $this->crearPase($destino + ['sede_destino_id' => null]);
            $this->assertSame(['aprobacion', 'salida_fisica', 'regreso'], $pase->gruposDelCircuito());
            $this->firmarGrupo($pase, 'aprobacion');
            $this->firmarGrupo($pase, 'salida_fisica');
            $this->assertSame('regreso', $this->recargar($pase)->grupoAbierto());
            $this->firmar($pase, 'recibe_destino')->assertSessionHasErrors(['rol' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
            $this->firmar($pase, 'jefe_depto_salida_regreso')->assertSessionHasErrors('rol');
            $this->firmarGrupo($pase, 'regreso');
            $this->assertSame(PaseSalida::REGRESADO, $this->recargar($pase)->estado);
        }
    }

    public function test_venta_queda_cerrada_al_salir(): void
    {
        $pase = $this->crearPase(['motivo' => 'venta', 'destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id]);
        $this->firmarGrupo($pase, 'aprobacion');
        $this->firmarGrupo($pase, 'salida_fisica');

        $final = $this->recargar($pase);
        $this->assertSame([PaseSalida::SALIO, null], [$final->estado, $final->grupoAbierto()]);
        $this->assertSame(['Salió — Cerrado', 'cerrado'], $final->insignia(false));
        $this->firmar($pase, 'recibe_regreso')->assertSessionHasErrors('rol');

        $this->actingAs($this->admin)->get('/pases-salida?filtro=fuera')->assertSee('Salió — Cerrado')->assertSee('Ver Detalle');
        $this->actingAs($this->admin)->get('/pases-salida?filtro=espera_regreso')->assertDontSee('id="pase-'.$pase->id.'"', false);
    }

    public function test_transiciones_prohibidas_y_firmas_repetidas(): void
    {
        $pase = $this->crearPase();

        // Grupos fuera de turno
        foreach (['recibe_salida', 'recibe_destino', 'traslada_salida_regreso', 'recibe_regreso'] as $rol) {
            $this->firmar($pase, $rol)->assertSessionHasErrors(['rol' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        }
        $this->firmar($pase, 'director_general')->assertSessionHasErrors(['rol' => 'Rol de firma no válido.']);
        $this->firmar($pase, 'jefe_depto', null, ['firma' => ''])->assertSessionHasErrors(['firma' => 'Falta la firma: firma en el recuadro antes de guardar.']);
        $this->firmar($pase, 'jefe_depto', null, ['nombre_firma' => ''])->assertSessionHasErrors(['nombre_firma' => 'Falta el nombre completo de quien firma.']);

        // Cada rol firma una sola vez
        $this->firmar($pase, 'jefe_depto')->assertSessionHasNoErrors();
        $this->firmar($pase, 'jefe_depto')->assertSessionHasErrors(['rol' => 'Este rol ya firmó este pase.']);
        $this->assertSame(1, $this->recargar($pase)->firmas->count());

        // Tras un error, el detalle reabre con el mensaje dentro
        $html = $this->actingAs($this->admin)->get('/pases-salida?pase='.$pase->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-dialogo-detalle-pase\s+data-abrir-al-cargar/', $html);

        // Aprobado ya no se rechaza; rechazado ya no se firma
        $this->firmar($pase, 'contraloria_salida');
        $this->firmar($pase, 'gerencia');
        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'Tarde'])
            ->assertSessionHasErrors(['motivo_rechazo' => 'Este pase ya no está pendiente de aprobación.']);

        $otro = $this->crearPase();
        $this->actingAs($this->admin)->post("/pases-salida/{$otro->id}/rechazar", ['motivo_rechazo' => 'Sin factura']);
        $this->firmar($otro, 'jefe_depto')->assertSessionHasErrors('rol');
        $this->firmar($otro, 'recibe_salida')->assertSessionHasErrors('rol');
    }

    public function test_rechazo_con_motivo_solo_mientras_esta_pendiente(): void
    {
        $pase = $this->crearPase();
        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => ''])
            ->assertSessionHasErrors(['motivo_rechazo' => 'Escribe el motivo del rechazo.']);

        $this->actingAs($this->admin)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'Falta la factura'])
            ->assertSessionHas('aviso', 'Pase PS-000001 rechazado.');
        $final = $this->recargar($pase);
        $this->assertSame([PaseSalida::RECHAZADO, 'Falta la factura', $this->admin->id], [$final->estado, $final->motivo_rechazo, $final->rechazado_por]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'pases_salida.rechazado', 'auditable_id' => $pase->id]);

        $this->actingAs($this->admin)->get('/pases-salida?pase='.$pase->id)->assertSee('Rechazado.')->assertSee('Falta la factura')->assertDontSee('Rechazar Pase');
    }

    // ------------------------------------------------------------ Permisos

    public function test_agente_registra_y_firma_la_salida_pero_no_aprueba_ni_rechaza(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/pases-salida')->assertOk()->assertSee('Nuevo Pase')
            ->assertSee('dialogoRegistroRapidoColaborador')->assertDontSee('dialogoAltaRapidaProveedor');
        $pase = $this->crearPase([], $agente);
        $this->assertSame($agente->id, $pase->creado_por);

        // Aprobar y rechazar piden "aprobar"
        $this->firmar($pase, 'jefe_depto', $agente)->assertForbidden();
        $this->actingAs($agente)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'x'])->assertForbidden();
        $this->actingAs($agente)->get('/pases-salida/'.$pase->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('Tu usuario no firma esta parte')->assertDontSee('data-firmar-rol', false)->assertDontSee('Rechazar Pase');

        // La salida física pide "firmar": el agente de la sede de origen sí
        $this->firmarGrupo($pase, 'aprobacion');
        $this->actingAs($agente)->get('/pases-salida/'.$pase->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertSee('data-firmar-rol="seguridad_salida"', false);
        $this->firmarGrupo($pase, 'salida_fisica', $agente);
        $this->assertSame(PaseSalida::SALIO, $this->recargar($pase)->estado);

        // Sin "pases_salida.ver" no entra
        $this->actingAs($this->crearUsuario($this->empresa))->get('/pases-salida')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa))->post('/pases-salida', $this->datos())->assertForbidden();
    }

    public function test_director_aprueba_pero_no_firma_la_salida_ni_registra(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $pase = $this->crearPase();

        $this->actingAs($director)->get('/pases-salida')->assertOk()->assertDontSee('data-abrir-dialogo="dialogoNuevoPase"', false);
        $this->actingAs($director)->post('/pases-salida', $this->datos())->assertForbidden();
        $this->firmarGrupo($pase, 'aprobacion', $director);
        $this->assertSame(PaseSalida::APROBADO, $this->recargar($pase)->estado);
        $this->firmar($pase, 'seguridad_salida', $director)->assertForbidden();
    }

    public function test_asistente_registra_pero_no_firma(): void
    {
        $asistente = $this->crearUsuario($this->empresa, 'Asistente', $this->centro);
        $pase = $this->crearPase([], $asistente);
        $this->firmar($pase, 'jefe_depto', $asistente)->assertForbidden();
        $this->firmarGrupo($pase, 'aprobacion');
        $this->firmar($pase, 'seguridad_salida', $asistente)->assertForbidden();
    }

    public function test_alcance_de_sede_origen_y_destino(): void
    {
        $haciaPlaya = $this->crearPase();
        $alProveedor = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null]);
        $jefePlaya = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);

        // Ve el pase que va a su sede, no el que sale de Centro hacia un proveedor
        $this->actingAs($jefePlaya)->get('/pases-salida')->assertOk()
            ->assertSee('id="pase-'.$haciaPlaya->id.'"', false)->assertDontSee('id="pase-'.$alProveedor->id.'"', false);
        $this->actingAs($jefePlaya)->get('/pases-salida/'.$alProveedor->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        $this->firmar($alProveedor, 'jefe_depto', $jefePlaya)->assertNotFound();
        $this->actingAs($jefePlaya)->post("/pases-salida/{$alProveedor->id}/rechazar", ['motivo_rechazo' => 'x'])->assertNotFound();
        $this->actingAs($jefePlaya)->get("/pases-salida/{$alProveedor->id}/imprimir")->assertNotFound();

        // No registra pases que salen de otra sede; tampoco aprueba los de Centro
        $this->actingAs($jefePlaya)->post('/pases-salida', $this->datos())->assertSessionHasErrors(['sede_id' => 'Elige una sede de origen activa en la que puedas registrar pases.']);
        $this->firmar($haciaPlaya, 'jefe_depto', $jefePlaya)->assertForbidden();

        // Recibe en destino y autoriza la salida de regreso (su sede), pero el regreso lo firma el origen
        $this->firmarGrupo($haciaPlaya, 'aprobacion');
        $this->firmar($haciaPlaya, 'seguridad_salida', $jefePlaya)->assertForbidden();
        $this->firmarGrupo($haciaPlaya, 'salida_fisica');
        $this->firmarGrupo($haciaPlaya, 'recepcion_destino', $jefePlaya);
        $this->firmarGrupo($haciaPlaya, 'salida_regreso', $jefePlaya);
        $this->firmar($haciaPlaya, 'recibe_regreso', $jefePlaya)->assertForbidden();
        $this->assertSame(PaseSalida::EN_TRANSITO_REGRESO, $this->recargar($haciaPlaya)->estado);

        // El filtro de sede (solo lo ve quien tiene varias)
        $this->actingAs($this->admin)->get('/pases-salida?sede='.$this->playa->id)->assertSee('id="pase-'.$haciaPlaya->id.'"', false)
            ->assertDontSee('id="pase-'.$alProveedor->id.'"', false)->assertSee('Todas las sedes');
        $this->actingAs($jefePlaya)->get('/pases-salida')->assertDontSee('Todas las sedes');
    }

    public function test_cada_empresa_ve_y_toca_solo_sus_pases(): void
    {
        $pase = $this->crearPase();
        $this->firmar($pase, 'jefe_depto');
        $firma = $this->enEmpresa(fn () => PaseSalidaFirma::firstOrFail());
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');

        $this->actingAs($ajeno)->get('/pases-salida')->assertOk()->assertDontSee('id="pase-'.$pase->id.'"', false)->assertSee('No se encontraron pases.');
        $this->actingAs($ajeno)->get('/pases-salida/'.$pase->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        $this->actingAs($ajeno)->get('/pases-salida?pase='.$pase->id)->assertNotFound();
        $this->firmar($pase, 'gerencia', $ajeno)->assertNotFound();
        $this->actingAs($ajeno)->post("/pases-salida/{$pase->id}/rechazar", ['motivo_rechazo' => 'x'])->assertNotFound();
        $this->actingAs($ajeno)->get("/pases-salida/{$pase->id}/imprimir")->assertNotFound();
        $this->actingAs($ajeno)->get("/pases-salida/{$pase->id}/firmas/{$firma->id}")->assertNotFound();
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
        $this->firmar($pase, 'jefe_depto');
        $firma = $this->enEmpresa(fn () => PaseSalidaFirma::firstOrFail());
        $otro = $this->crearPase();

        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}/firmas/{$firma->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // La firma de otro pase no se entrega por este
        $this->actingAs($this->admin)->get("/pases-salida/{$otro->id}/firmas/{$firma->id}")->assertNotFound();
        // Sin permiso, nada; y la imagen nunca está en public/
        $this->actingAs($this->crearUsuario($this->empresa))->get("/pases-salida/{$pase->id}/firmas/{$firma->id}")->assertForbidden();
        $this->assertFalse(str_starts_with($firma->firma_ruta, 'public/'));
        $this->assertFileDoesNotExist(public_path($firma->firma_ruta));
    }

    // --------------------------------------------------------- Vencidos y filtros

    public function test_vencido_compara_contra_la_fecha_local_de_la_sede(): void
    {
        $this->enEmpresa(fn () => $this->centro->forceFill(['zona_horaria' => 'America/Cancun'])->save());
        $hoy = now('America/Cancun');
        $vencido = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null,
            'fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'), 'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]);
        $alDia = $this->crearPase(['destino_tipo' => 'proveedor', 'proveedor_id' => $this->proveedor->id, 'sede_destino_id' => null,
            'fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'), 'fecha_tentativa_regreso' => $hoy->format('Y-m-d')]);
        $pendiente = $this->crearPase(['fecha_salida_programada' => $hoy->copy()->subDays(5)->format('Y-m-d'), 'fecha_tentativa_regreso' => $hoy->copy()->subDay()->format('Y-m-d')]);
        foreach ([$vencido, $alDia] as $p) {
            $this->firmarGrupo($p, 'aprobacion');
            $this->firmarGrupo($p, 'salida_fisica');
        }

        $this->actingAs($this->admin)->get('/pases-salida?filtro=vencidos')->assertOk()
            ->assertSee('id="pase-'.$vencido->id.'"', false)->assertDontSee('id="pase-'.$alDia->id.'"', false)
            ->assertDontSee('id="pase-'.$pendiente->id.'"', false, 'Un pase que no ha salido no está vencido')
            ->assertSee('Vencido — Debió Regresar')->assertSee('Debió regresar el '.$hoy->copy()->subDay()->format('d/m/Y'));
        $this->actingAs($this->admin)->get('/pases-salida?filtro=espera_regreso')->assertSee('id="pase-'.$vencido->id.'"', false)->assertSee('id="pase-'.$alDia->id.'"', false)
            ->assertSee('Regresa (tentativo): '.$hoy->format('d/m/Y'));
    }

    public function test_filtros_conteos_y_busqueda(): void
    {
        $pendiente = $this->crearPase();
        $aprobado = $this->crearPase(['colaborador_id' => $this->otroColaborador->id, 'articulos' => [['cantidad' => 1, 'equipo' => 'Taladro', 'serie' => 'DW-778120']]]);
        $this->firmarGrupo($aprobado, 'aprobacion');

        $this->actingAs($this->admin)->get('/pases-salida?filtro=pendientes')->assertSee('id="pase-'.$pendiente->id.'"', false)->assertDontSee('id="pase-'.$aprobado->id.'"', false)
            ->assertSee('aria-current="page"', false);
        $this->actingAs($this->admin)->get('/pases-salida?filtro=aprobados')->assertSee('id="pase-'.$aprobado->id.'"', false)->assertSee('Ver / Registrar Salida')
            ->assertDontSee('id="pase-'.$pendiente->id.'"', false);
        $this->actingAs($this->admin)->get('/pases-salida?filtro=fuera')->assertSee('No se encontraron pases.');
        // Filtro desconocido = Todos
        $this->actingAs($this->admin)->get('/pases-salida?filtro=xyz')->assertSee('id="pase-'.$pendiente->id.'"', false)->assertSee('id="pase-'.$aprobado->id.'"', false);

        foreach (['PS-000002', 'roberto', 'hernández roberto', '1005', 'dw-778', 'taladro'] as $texto) {
            $this->actingAs($this->admin)->get('/pases-salida?q='.urlencode($texto))
                ->assertSee('id="pase-'.$aprobado->id.'"', false)->assertDontSee('id="pase-'.$pendiente->id.'"', false);
        }
        $this->actingAs($this->admin)->get('/pases-salida?q=nadie')->assertSee('No se encontraron pases para «nadie».')->assertSee('Limpiar');
    }

    // ------------------------------------------- Lector, atajos y detalle

    public function test_el_formulario_usa_el_lector_universal_y_los_atajos(): void
    {
        $this->actingAs($this->admin)->get('/pases-salida')->assertOk()
            ->assertSee('data-lector data-modo="buscar" data-tipos="colaborador"', false)
            ->assertSee('name="colaborador_id"', false)->assertSee('name="colaborador_destino_id"', false)
            ->assertSee('data-tipos="equipo"', false)->assertSee('Escanear un equipo del padrón (opcional)')
            ->assertSee('Nuevo Colaborador')->assertSee('Nuevo Proveedor')->assertSee('dialogoRegistroRapidoColaborador')->assertSee('dialogoAltaRapidaProveedor')
            ->assertSee('data-direccion="Calle 20 Sur 110, Cancún"', false);

        // El lector encuentra al solicitante por su número (gafete) y al equipo por su serie
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=1007&tipos=colaborador')->assertJsonPath('resultados.0.id', $this->solicitante->id);
        $tipo = $this->enEmpresa(fn () => TipoEquipo::create(['nombre' => 'Radio de Comunicación']));
        $radio = $this->enEmpresa(fn () => Equipo::create(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'numero_serie' => '752TSFQ505']));
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=752tsfq505&tipos=equipo')->assertJsonPath('resultados.0.id', $radio->id);
        $this->actingAs($this->admin)->getJson('/pases-salida/equipos/'.$radio->id)->assertOk()
            ->assertExactJson(['equipo_id' => $radio->id, 'equipo' => 'Radio de Comunicación', 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'serie' => '752TSFQ505', 'descripcion' => null]);

        // El equipo escaneado queda ligado a su renglón
        $pase = $this->crearPase(['articulos' => [['cantidad' => 1, 'equipo' => 'Radio de Comunicación', 'serie' => '752TSFQ505', 'equipo_id' => $radio->id]]]);
        $this->assertSame($radio->id, $this->recargar($pase)->articulos->first()->equipo_id);

        // Un equipo de una sede fuera de su alcance no se entrega
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($agentePlaya)->getJson('/pases-salida/equipos/'.$radio->id)->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->getJson('/pases-salida/equipos/'.$radio->id)->assertForbidden();
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

        // Recursos Humanos lo une con su registro correcto: el pase pasa al colaborador verdadero
        $this->actingAs($rh)->put("/colaboradores/{$jorge->id}/fusionar", ['destino_id' => $this->otroColaborador->id])->assertSessionHasNoErrors();
        $this->assertSame($this->otroColaborador->id, $this->recargar($pase)->colaborador_id);
    }

    public function test_detalle_firmas_del_pase_e_impresion(): void
    {
        $pase = $this->crearPase();
        $this->firmar($pase, 'jefe_depto');

        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id)->assertRedirect(route('pases-salida.index', ['pase' => $pase->id]));
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('PS-000001')->assertSee('Firmas del pase')->assertSee('Aprobaciones')->assertSee('En curso · 1 de 3')
            ->assertSee('Salida Física')->assertSee('Recepción en Destino')->assertSee('Salida de Regreso (desde Destino)')->assertSee('Recepción de Regreso (en Origen)')
            ->assertSee('Jefe de Departamento</strong> — JUAN PÉREZ', false)->assertSee('data-firmar-rol="gerencia"', false)
            ->assertSee('valida que coincidan con lo que llegó/regresa')->assertSee('Rechazar Pase')->assertSee('Imprimir Pase')
            ->assertSee(route('pases-salida.firma', [$pase->id, $this->recargar($pase)->firmas->first()->id]))
            ->assertDontSee('onclick');

        // Al firmar se propone el nombre: el solicitante en sus roles y el usuario en sesión en los de Seguridad
        $this->firmar($pase, 'contraloria_salida');
        $this->firmar($pase, 'gerencia');
        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertSee('data-firmar-rol="solicitante_salida" data-rol-texto="Solicitante" data-sugerido="MARIANA LÓPEZ"', false)
            ->assertSee('data-sugerido="'.mb_strtoupper($this->admin->name).'"', false)->assertSee('Pase aprobado — listo para que el equipo salga físicamente.');

        $this->actingAs($this->admin)->get('/pases-salida/'.$pase->id.'/imprimir')->assertOk()
            ->assertSee('Pase de Salida PS-000001')->assertSee('Recepción de Regreso (en Origen)')->assertSee('JUAN PÉREZ')
            ->assertSee('Laptop')->assertSee('ABC123')->assertSee('Sí, espera regreso');

        // Sin "imprimir" no hay hoja ni botón
        $sinImprimir = $this->crearUsuario($this->empresa, 'Asistente', $this->centro);
        $this->enEmpresa(fn () => RolPermiso::where('rol_id', Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Asistente')->value('id'))
            ->whereIn('modulo_accion_id', ModuloAccion::whereHas('modulo', fn ($m) => $m->where('clave', 'pases_salida'))->whereHas('accion', fn ($a) => $a->where('clave', 'imprimir'))->pluck('id'))
            ->delete());
        app(Autorizador::class)->olvidar();
        $this->actingAs($sinImprimir)->get('/pases-salida/'.$pase->id.'/imprimir')->assertForbidden();
        $this->actingAs($sinImprimir)->get('/pases-salida/'.$pase->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertDontSee('Imprimir Pase');
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
        $this->assertCount(9, $pases);
        $this->assertEqualsCanonicalizing(array_keys(PaseSalida::ESTADOS), $pases->pluck('estado')->unique()->values()->all());
        $this->assertSame(['PS-000001', 'PS-000009'], [$pases->first()->folio, $pases->last()->folio]);
    }
}
