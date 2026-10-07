<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Equipos\AdministradorEquipos;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class ResponsivasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function equipo(string $serie, ?Sede $sede = null, string $estado = 'disponible', ?Empresa $empresa = null): Equipo
    {
        return $this->enEmpresa(function () use ($serie, $sede, $estado) {
            $tipo = TipoEquipo::firstOrCreate(['nombre' => 'Radio de Comunicación']);
            $e = new Equipo(['sede_id' => ($sede ?? $this->centro)->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'numero_serie' => $serie]);
            $e->forceFill(['estado' => $estado])->save();

            return $e;
        }, $empresa);
    }

    private function colaborador(string $num, ?Sede $sede = null, ?Empresa $empresa = null, array $extra = []): Colaborador
    {
        return $this->enEmpresa(function () use ($num, $sede, $extra) {
            $c = new Colaborador(['num_empleado' => $num, 'nombre' => 'Persona', 'apellido_paterno' => 'Num'.$num, 'sede_id' => $sede?->id]);
            $c->forceFill($extra)->save();

            return $c;
        }, $empresa);
    }

    /** Firma como la deja el recuadro: JPEG en base64. */
    private function firma(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 40, 150, 560, 40, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * @param  list<Equipo>  $equipos
     * @param  list<string>  $modalidades
     * @return array<string, mixed>
     */
    private function datos(Colaborador $colaborador, array $equipos, array $modalidades = [], array $extra = []): array
    {
        return array_merge([
            '_dialogo' => 'nuevo-resguardo',
            'sede_id' => $this->centro->id,
            'colaborador_id' => $colaborador->id,
            'equipos' => array_map(fn (Equipo $e) => $e->id, $equipos),
            'modalidades' => $modalidades ?: array_fill(0, count($equipos), 'prestado'),
            'firma' => $this->firma(),
        ], $extra);
    }

    private function estado(Equipo $equipo): string
    {
        return $this->enEmpresa(fn () => Equipo::findOrFail($equipo->id)->estado);
    }

    private function crearLote(User $actor, Colaborador $colaborador, array $equipos, ?Sede $sede = null): Responsiva
    {
        $this->actingAs($actor)->post('/responsivas', $this->datos($colaborador, $equipos, [], ['sede_id' => ($sede ?? $this->centro)->id]))->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => Responsiva::latest('id')->firstOrFail());
    }

    // ---------------------------------------------------------------- Lista

    public function test_pantalla_con_pestanas_lotes_y_textos_de_segcat(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $abierto = $this->crearLote($this->admin, $c, [$this->equipo('752TSFQ504'), $this->equipo('LT-0001')]);
        $cerrado = $this->crearLote($this->admin, $c, [$this->equipo('DM-1187')]);
        $this->actingAs($this->admin)->patch("/responsivas/{$cerrado->id}/recibir");

        $this->actingAs($this->admin)->get('/responsivas')->assertOk()
            ->assertSee('Control de Resguardos')->assertSee('Lotes de asignación con firma digital y rastreo por Serie.')
            ->assertSee('Nuevo Resguardo (Lote)')->assertSee('Equipos en Campo (1 Lotes)')->assertSee('Historial Devueltos (1 Lotes)')
            ->assertSee('id="responsiva-'.$abierto->id.'"', false)->assertSee('EN CAMPO')->assertSee('CENRES-000001')
            ->assertSee('Resguardante Activo:')->assertSee('ACTIVOS VINCULADOS AL RESGUARDO:')->assertSee('S/N: 752TSFQ504')
            ->assertSee('Entregó Caseta:')->assertSee('Recibir Lote Completo (OK)')->assertSee('Firma')->assertSee('Hoja')
            ->assertSee('LOTE CERRADO')->assertSee('Resguardó en su momento:')->assertSee('Devuelto a Caseta:')->assertSee('Archivo')
            ->assertSee('+ Añadir Equipo')->assertSee('Firma Digital del Colaborador')->assertSee('Guardar Lote de Resguardo')
            ->assertSee('Sede de Origen')->assertDontSee('Hotel de Origen')->assertDontSee('onclick');
    }

    public function test_sin_resguardos_muestra_los_estados_vacios(): void
    {
        $this->actingAs($this->admin)->get('/responsivas')->assertOk()
            ->assertSee('No hay equipos prestados actualmente.')->assertSee('El historial está limpio.');
    }

    public function test_el_dialogo_usa_el_lector_universal_la_firma_y_solo_equipos_disponibles(): void
    {
        $disponible = $this->equipo('RAD-1');
        $this->equipo('RAD-MANT', null, 'en_mantenimiento');
        $this->equipo('RAD-PLA', $this->playa);

        $pagina = $this->actingAs($this->admin)->get('/responsivas')->assertOk()->getContent();
        $this->assertStringContainsString('data-tipos="equipo"', $pagina);
        $this->assertStringContainsString('data-tipos="colaborador"', $pagina);
        $this->assertStringContainsString('data-firma-requerida', $pagina);
        $this->assertStringContainsString('value="'.$disponible->id.'" data-sede="'.$this->centro->id.'"', $pagina);
        // Ronda 6 (EQ-04): el número con su rótulo
        $this->assertStringContainsString('Serie: RAD-PLA ·', $pagina);
        $this->assertStringNotContainsString('Serie: RAD-MANT', $pagina);
        $this->assertStringNotContainsString('canvas.toBlob', $pagina);
    }

    // ---------------------------------------------------------------- Alta

    public function test_nuevo_resguardo_asigna_los_equipos_guarda_la_firma_privada_y_audita(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $radio = $this->equipo('752TSFQ504');
        $chaleco = $this->equipo('CH-001');

        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio, $chaleco], ['prestado', 'asignado']))
            ->assertSessionHasNoErrors()->assertSessionHas('ok', 'Resguardo CENRES-000001 guardado con 2 equipos. Ya puedes imprimir la hoja.');

        $r = $this->enEmpresa(fn () => Responsiva::with('equipos')->firstOrFail());
        $this->assertSame(['CENRES-000001', 1, Responsiva::EN_CAMPO, $this->centro->id, $c->id, $this->admin->id],
            [$r->folio, $r->numero, $r->estado, $r->sede_id, $r->colaborador_id, $r->entregado_por]);
        $this->assertSame(['prestado', 'asignado'], $r->equipos->pluck('modalidad')->all());
        $this->assertSame(['asignado', 'asignado'], [$this->estado($radio), $this->estado($chaleco)]);

        // La firma va al disco privado, nunca a public/
        $this->assertStringStartsWith('firmas/'.$this->empresa->id.'/responsivas/', $r->firma_ruta);
        Storage::disk('local')->assertExists($r->firma_ruta);
        $this->assertArrayNotHasKey('firma_ruta', $r->toArray());

        $auditoria = Auditoria::where('evento', 'responsivas.creado')->where('auditable_id', $r->id)->firstOrFail();
        $this->assertSame(['752TSFQ504 (Turno)', 'CH-001 (Fijo)'], $auditoria->despues['equipos']);
        $this->assertArrayNotHasKey('firma_ruta', $auditoria->despues);
        $this->assertSame(2, Auditoria::where('evento', 'equipos.asignado')->count());
    }

    public function test_el_folio_es_consecutivo_por_empresa_con_las_letras_de_la_sede(): void
    {
        $this->crearLote($this->admin, $this->colaborador('1', $this->centro), [$this->equipo('A-1')]);
        $segundo = $this->crearLote($this->admin, $this->colaborador('2', $this->playa), [$this->equipo('A-2', $this->playa)], $this->playa);
        $this->assertSame('PLARES-000002', $segundo->folio);

        // Sin código, las letras salen del nombre sin artículos (como SEGCAT)
        $this->assertSame('BAHRES-000001', Responsiva::folioPara(new Sede(['codigo' => '', 'nombre' => 'Hotel de la Bahía']), 1));
    }

    public function test_validaciones_con_mensajes_claros_y_sin_firmas_huerfanas(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $radio = $this->equipo('RAD-1');

        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->centro->id, 'colaborador_id' => $c->id, 'firma' => $this->firma()])
            ->assertSessionHasErrors(['equipos' => 'Debe añadir al menos 1 equipo al lote.']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [], [], ['equipos' => [$radio->id, '']]))
            ->assertSessionHasErrors(['equipos' => 'Seleccione un equipo en todas las filas agregadas (o quite la fila vacía).']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio, $radio]))
            ->assertSessionHasErrors(['equipos' => 'Un mismo equipo está en dos filas. Quita la fila repetida.']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio], ['permanente']))
            ->assertSessionHasErrors(['modalidades.0' => 'Elige «Turno» o «Fijo» en cada equipo.']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio], [], ['colaborador_id' => '']))
            ->assertSessionHasErrors(['colaborador_id' => 'Escanea el gafete o busca al colaborador responsable.']);

        // Firma obligatoria y real
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio], [], ['firma' => '']))
            ->assertSessionHasErrors(['firma' => 'Falta la firma del colaborador: firma en el recuadro antes de guardar.']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio], [], ['firma' => 'data:image/png;base64,'.base64_encode(str_repeat('x', 200))]))
            ->assertSessionHasErrors(['firma' => 'No se pudo leer la firma del colaborador. Vuelve a firmar.']);

        // Reglas de los equipos
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$this->equipo('RAD-MANT', null, 'en_mantenimiento')]))
            ->assertSessionHasErrors(['equipos' => 'El equipo RAD-MANT no está DISPONIBLE (está EN MANTENIMIENTO).']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$this->equipo('RAD-PLA', $this->playa)]))
            ->assertSessionHasErrors(['equipos' => 'El equipo RAD-PLA es de otra sede: solo se resguardan equipos de Sede CEN.']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [], [], ['equipos' => [999999]]))
            ->assertSessionHasErrors(['equipos' => 'Uno de los equipos no existe en el inventario de tu empresa.']);
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($this->colaborador('1008', $this->playa), [$radio]))
            ->assertSessionHasErrors(['colaborador_id' => 'Persona Num1008 no trabaja en la sede Sede CEN. El colaborador elegido no es válido para esta sede.']);

        $this->assertSame(0, $this->enEmpresa(fn () => Responsiva::count()));
        $this->assertSame([], Storage::disk('local')->allFiles('firmas'));
        $this->assertSame('disponible', $this->estado($radio));
    }

    public function test_un_equipo_no_puede_estar_en_dos_resguardos_abiertos(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $radio = $this->equipo('RAD-1');
        $primero = $this->crearLote($this->admin, $c, [$radio]);

        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio]))
            ->assertSessionHasErrors(['equipos' => 'El equipo RAD-1 sigue en el resguardo CENRES-000001 sin recibir.']);

        // Aunque lo hayan dado de baja y reactivado mientras estaba en campo
        $equipos = app(AdministradorEquipos::class);
        $this->enEmpresa(function () use ($equipos, $radio) {
            $modelo = Equipo::findOrFail($radio->id);
            $equipos->darDeBaja($this->admin, $modelo, ['motivo' => 'extraviado', 'descripcion' => 'Se perdió', 'aplica_cobro' => false]);
            $equipos->reactivar($this->admin, $modelo);
        });
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($c, [$radio]))
            ->assertSessionHasErrors(['equipos' => 'El equipo RAD-1 sigue en el resguardo CENRES-000001 sin recibir.']);
        $this->assertSame(1, $this->enEmpresa(fn () => EquipoResponsiva::where('equipo_id', $radio->id)->count()));
        $this->assertTrue($this->enEmpresa(fn () => Responsiva::findOrFail($primero->id))->enCampo());
    }

    // ------------------------------------------------------- Transiciones

    public function test_recibir_lote_completo_vuelve_a_disponible_y_no_dos_veces(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $radio = $this->equipo('RAD-1');
        $lampara = $this->equipo('LT-1');
        $r = $this->crearLote($this->admin, $c, [$radio, $lampara]);

        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir")
            ->assertSessionHas('ok', 'Lote CENRES-000001 recibido: 2 equipos vuelven a DISPONIBLE.');
        $r = $this->enEmpresa(fn () => Responsiva::with('equipos')->findOrFail($r->id));
        $this->assertSame([Responsiva::DEVUELTA, $this->admin->id], [$r->estado, $r->recibido_por]);
        $this->assertNotNull($r->devuelto_en);
        $this->assertSame(['ok', 'ok'], $r->equipos->pluck('estado_devolucion')->all());
        $this->assertSame(['disponible', 'disponible'], [$this->estado($radio), $this->estado($lampara)]);
        $this->assertTrue(Auditoria::where('evento', 'responsivas.recibido')->where('auditable_id', $r->id)->exists());
        $this->assertSame(2, Auditoria::where('evento', 'equipos.devuelto')->count());

        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir")->assertSessionHasErrors(['responsiva' => 'El resguardo CENRES-000001 ya se había recibido.']);

        // Ya devueltos, se pueden resguardar otra vez
        $this->crearLote($this->admin, $c, [$radio]);
    }

    public function test_un_equipo_dado_de_baja_en_campo_se_queda_de_baja_al_recibir_el_lote(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $radio = $this->equipo('RAD-1');
        $perdido = $this->equipo('LT-1');
        $r = $this->crearLote($this->admin, $c, [$radio, $perdido]);
        $this->enEmpresa(fn () => app(AdministradorEquipos::class)->darDeBaja($this->admin, Equipo::findOrFail($perdido->id),
            ['motivo' => 'extraviado', 'descripcion' => 'Se perdió en el rondín', 'aplica_cobro' => false]));

        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir")
            ->assertSessionHas('ok', 'Lote CENRES-000001 recibido: 1 equipo vuelve a DISPONIBLE. 1 ya estaba(n) dado(s) de baja y se quedan así.');
        $this->assertSame(['disponible', 'baja'], [$this->estado($radio), $this->estado($perdido)]);
        $this->assertSame('baja', $this->enEmpresa(fn () => EquipoResponsiva::where('equipo_id', $perdido->id)->value('estado_devolucion')));
    }

    // ------------------------------------------------ Firma, hoja e historial

    public function test_la_firma_solo_se_ve_con_permiso_y_dentro_de_su_sede(): void
    {
        $r = $this->crearLote($this->admin, $this->colaborador('1005', $this->centro), [$this->equipo('RAD-1')]);
        $r2 = $this->crearLote($this->admin, $this->colaborador('1008', $this->playa), [$this->equipo('RAD-2', $this->playa)], $this->playa);

        $respuesta = $this->actingAs($this->admin)->get("/responsivas/{$r->id}/firma")->assertOk();
        $this->assertSame('image/jpeg', $respuesta->headers->get('content-type'));
        $this->assertSame('nosniff', $respuesta->headers->get('x-content-type-options'));

        // Jefe de Centro: la de Playa no
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->get("/responsivas/{$r->id}/firma")->assertOk();
        $this->actingAs($jefe)->get("/responsivas/{$r2->id}/firma")->assertNotFound();

        // Sin el módulo, ni la firma
        $this->actingAs($this->crearUsuario($this->empresa, 'Recursos Humanos'))->get("/responsivas/{$r->id}/firma")->assertForbidden();

        // Otra empresa
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->get("/responsivas/{$r->id}/firma")->assertNotFound();

        // Nunca en la carpeta pública
        $this->assertFileDoesNotExist(public_path($r->firma_ruta));
        $this->assertStringNotContainsString('firmas/', $this->actingAs($this->admin)->get('/responsivas')->getContent());
    }

    public function test_hoja_de_resguardo_multiple_para_imprimir(): void
    {
        $r = $this->crearLote($this->admin, $this->colaborador('1005', $this->centro), [$this->equipo('RAD-1'), $this->equipo('CH-1')]);

        $this->actingAs($this->admin)->get("/responsivas/{$r->id}/hoja")->assertOk()
            ->assertSee('RESGUARDO MÚLTIPLE DE ACTIVOS DE SEGURIDAD')->assertSee('FOLIO: CENRES-000001')
            ->assertSee('Ubicación Emisión:')->assertSee('Datos del Colaborador Resguardante')->assertSee('No. Nómina / Empleado:')
            ->assertSee('S/N')->assertSee('RAD-1')->assertSee('Préstamo de Turno')->assertSee('Cláusula de Daños y Extravío:')
            ->assertSee('FIRMA DE CONFORMIDAD')->assertSee('ENTREGADO POR (CASETA)')
            ->assertSee(route('responsivas.firma', $r->id), false)->assertDontSee('onclick');

        $this->actingAs($this->crearUsuario($this->empresa, 'Recursos Humanos'))->get("/responsivas/{$r->id}/hoja")->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->get("/responsivas/{$r->id}/hoja")->assertOk();
    }

    public function test_historial_de_un_equipo(): void
    {
        $c = $this->colaborador('1005', $this->centro);
        $radio = $this->equipo('RAD-1');
        $r = $this->crearLote($this->admin, $c, [$radio]);
        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir");
        $this->crearLote($this->admin, $c, [$radio]);

        $this->actingAs($this->admin)->get("/responsivas/equipos/{$radio->id}/historial")->assertOk()
            ->assertSee('Historial de Auditoría')->assertSee('S/N RAD-1')->assertSee('EN USO ACTUALMENTE')->assertSee('DEVUELTO (OK)')
            ->assertSee('Resguardó:')->assertSee('Persona Num1005')->assertSee('CENRES-000002');
        $nuevo = $this->equipo('RAD-NUEVO');
        $this->actingAs($this->admin)->get("/responsivas/equipos/{$nuevo->id}/historial")->assertSee('Este equipo es nuevo, nunca ha sido prestado.');
    }

    // ------------------------------------------------- Permisos y aislamiento

    public function test_cada_empresa_ve_y_toca_solo_sus_resguardos(): void
    {
        $mio = $this->crearLote($this->admin, $this->colaborador('1005', $this->centro), [$this->equipo('MIO-1')]);
        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeOtra = $this->crearSede($otra, 'Z1');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $equipoAjeno = $this->equipo('AJENO-1', $sedeOtra, 'disponible', $otra);
        $this->actingAs($adminOtra)->post('/responsivas', ['sede_id' => $sedeOtra->id, 'colaborador_id' => $this->colaborador('9001', $sedeOtra, $otra)->id,
            'equipos' => [$equipoAjeno->id], 'modalidades' => ['prestado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $ajeno = Responsiva::withoutGlobalScopes()->where('empresa_id', $otra->id)->firstOrFail();
        $this->assertSame('Z1RES-000001', $ajeno->folio);

        $this->actingAs($this->admin)->get('/responsivas')->assertSee('MIO-1')->assertDontSee('AJENO-1');
        $this->actingAs($this->admin)->patch("/responsivas/{$ajeno->id}/recibir")->assertNotFound();
        $this->actingAs($this->admin)->get("/responsivas/{$ajeno->id}/hoja")->assertNotFound();
        $this->actingAs($this->admin)->get("/responsivas/equipos/{$equipoAjeno->id}/historial")->assertNotFound();
        $this->assertSame(Responsiva::EN_CAMPO, Responsiva::withoutGlobalScopes()->findOrFail($ajeno->id)->estado);

        // Ni resguardar un equipo de otra empresa
        $this->actingAs($this->admin)->post('/responsivas', $this->datos($this->colaborador('1006', $this->centro), [], [], ['equipos' => [$equipoAjeno->id]]))
            ->assertSessionHasErrors(['equipos' => 'Uno de los equipos no existe en el inventario de tu empresa.']);
        $this->assertTrue($this->enEmpresa(fn () => Responsiva::findOrFail($mio->id))->enCampo());
    }

    public function test_alcance_de_sede_solo_ve_y_resguarda_en_sus_sedes(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $deCentro = $this->crearLote($this->admin, $this->colaborador('1005', $this->centro), [$this->equipo('CEN-1')]);
        $dePlaya = $this->crearLote($this->admin, $this->colaborador('1008', $this->playa), [$this->equipo('PLA-1', $this->playa)], $this->playa);
        $this->equipo('PLA-LIBRE', $this->playa);

        $pagina = $this->actingAs($jefe)->get('/responsivas')->assertOk()->assertSee('CEN-1')->assertDontSee('PLA-1')->getContent();
        $this->assertStringNotContainsString('[PLA-LIBRE]', $pagina);
        $this->actingAs($jefe)->patch("/responsivas/{$dePlaya->id}/recibir")->assertNotFound();
        $this->actingAs($jefe)->get("/responsivas/{$dePlaya->id}/hoja")->assertNotFound();

        $this->actingAs($jefe)->post('/responsivas', $this->datos($this->colaborador('1010', $this->playa), [$this->equipo('PLA-2', $this->playa)], [], ['sede_id' => $this->playa->id]))
            ->assertSessionHasErrors(['sede_id' => 'Elige una de tus sedes activas.']);
        $this->actingAs($jefe)->patch("/responsivas/{$deCentro->id}/recibir")->assertSessionHasNoErrors();
    }

    public function test_el_agente_resguarda_y_recibe_en_su_sede(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $c = $this->colaborador('1003', $this->centro);
        $radio = $this->equipo('RAD-1');

        $this->actingAs($agente)->get('/responsivas')->assertOk()->assertSee('Nuevo Resguardo (Lote)');
        $r = $this->crearLote($agente, $c, [$radio]);
        $this->actingAs($agente)->get("/responsivas/{$r->id}/firma")->assertOk();
        $this->actingAs($agente)->get("/responsivas/{$r->id}/hoja")->assertOk();
        $this->actingAs($agente)->patch("/responsivas/{$r->id}/recibir")->assertSessionHasNoErrors();
        $this->assertSame('disponible', $this->estado($radio));

        $this->actingAs($this->crearUsuario($this->empresa))->get('/responsivas')->assertForbidden();
    }

    public function test_sin_permiso_de_firmar_no_se_crea_el_resguardo(): void
    {
        // El Asistente captura y edita pero no firma
        $asistente = $this->crearUsuario($this->empresa, 'Asistente', $this->centro);
        $r = $this->crearLote($this->admin, $this->colaborador('1005', $this->centro), [$this->equipo('RAD-1')]);

        $this->actingAs($asistente)->get('/responsivas')->assertOk()->assertDontSee('Nuevo Resguardo (Lote)')->assertDontSee('dialogoNuevoResguardo"', false);
        $this->actingAs($asistente)->post('/responsivas', $this->datos($this->colaborador('1006', $this->centro), [$this->equipo('RAD-2')]))->assertForbidden();
        $this->actingAs($asistente)->patch("/responsivas/{$r->id}/recibir")->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------- Ganchos con otros módulos

    public function test_la_ficha_del_equipo_dice_quien_lo_tiene(): void
    {
        $this->crearLote($this->admin, $this->colaborador('1005', $this->centro), [$this->equipo('RAD-1')]);

        $this->actingAs($this->admin)->get('/equipos')->assertOk()->assertSee('A cargo de:')->assertSee('Persona Num1005')->assertSee('CENRES-000001');
    }

    public function test_unir_un_colaborador_provisional_mueve_sus_resguardos(): void
    {
        $provisional = $this->colaborador('', $this->centro, null, ['provisional' => true, 'num_empleado' => null]);
        $correcto = $this->colaborador('1005', $this->centro);
        $r = $this->crearLote($this->admin, $provisional, [$this->equipo('RAD-1')]);

        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->fusionar($this->admin, $provisional, $correcto));

        $this->assertSame($correcto->id, $this->enEmpresa(fn () => Responsiva::findOrFail($r->id))->colaborador_id);
    }

    public function test_datos_demo_de_prestamos_y_resguardos_solo_la_primera_vez(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $prestamos = $this->enEmpresa(fn () => PrestamoLlave::all(), $demo);
        $this->assertCount(6, $prestamos);
        $this->assertSame(3, $prestamos->filter->vigente()->count());
        $this->assertSame(1, $prestamos->where('anulado', true)->count());
        $resguardos = $this->enEmpresa(fn () => Responsiva::with('equipos')->get(), $demo);
        $this->assertSame(['CENRES-000001', 'CENRES-000002', 'PLARES-000003'], $resguardos->pluck('folio')->all());
        $this->assertSame(2, $resguardos->filter->enCampo()->count());
        $resguardos->each(fn (Responsiva $r) => Storage::disk('local')->assertExists($r->firma_ruta));
        // Quien registró: el Agente en Centro, como en la caseta
        $agente = User::where('username', 'agente.demo')->firstOrFail();
        $this->assertSame($agente->id, $resguardos->first()->entregado_por);
    }

    public function test_menu_enlaza_a_responsivas(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('responsivas.index'));
    }
}
