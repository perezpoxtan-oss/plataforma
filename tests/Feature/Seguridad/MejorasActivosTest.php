<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoProcedimiento;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Llave;
use App\Models\MovimientoTransporte;
use App\Models\Notificacion;
use App\Models\PrestamoLlave;
use App\Models\Procedimiento;
use App\Models\ProcedimientoAcuse;
use App\Models\ProcedimientoCategoria;
use App\Models\Proveedor;
use App\Models\Responsiva;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\Turno;
use App\Models\User;
use App\Services\Auditoria\LectorAuditoria;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Rutas\AdministradorRutas;
use App\Support\AjustesCaseta;
use App\Support\CorreoPlataforma;
use App\Support\Menu\MisPendientes;
use App\Support\RelojVoBo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Mejoras de Transporte, Préstamo de llaves, Procedimientos, Responsivas y
 * Lector universal (ADR-0014). Sin configurar nada, todo funciona como el
 * diseño original; lo configurable vive en Configuración → Caseta y activos.
 */
class MejorasActivosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $agente;

    private User $jefe;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Lunes 5 de octubre de 2026, 09:00 en Ciudad de México
        $this->viajar('2026-10-05 15:00:00');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->director = $this->crearUsuario($this->empresa, 'Director');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------- Apoyo

    private function viajar(string $utc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        if ($this->app !== null && $this->app->bound('session')) {
            $this->flushSession(); // que el salto de horas no cuente como inactividad
        }
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @param  array<string, int|string>  $ajustes */
    private function configurar(array $ajustes): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', array_merge(AjustesCaseta::OMISION, $ajustes))->assertSessionHasNoErrors();
        $this->empresa->refresh();
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

    private function colaborador(string $num, ?Sede $sede = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Persona', 'apellido_paterno' => 'Num'.$num, 'sede_id' => ($sede ?? $this->centro)->id]));
    }

    /** @return list<Notificacion> */
    private function avisos(User $u, string $tipo): array
    {
        return $this->enEmpresa(fn () => Notificacion::where('user_id', $u->id)->where('tipo', $tipo)->orderBy('id')->get()->all());
    }

    /** @return array<string, array<string, mixed>> */
    private function pendientes(User $u): array
    {
        app()->forgetScopedInstances();
        $this->actingAs($u);

        return collect(app(MisPendientes::class)->para($u)['items'])->keyBy('clave')->all();
    }

    private function llave(string $nomenclatura, ?Sede $sede = null): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $sede) {
            $l = new Llave(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nomenclatura, 'descripcion' => 'Bodega',
                'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
            $l->forceFill(['activo' => true])->save();

            return $l;
        });
    }

    private function prestamo(Llave $llave, Colaborador $c, string $hace = '-1 hour', string $garantia = 'ine', ?string $folio = 'A123'): PrestamoLlave
    {
        return $this->enEmpresa(function () use ($llave, $c, $hace, $garantia, $folio) {
            $p = new PrestamoLlave(['sede_id' => $llave->sede_id, 'llave_id' => $llave->id, 'colaborador_id' => $c->id, 'tipo_garantia' => $garantia, 'folio_garantia' => $folio]);
            $p->forceFill(['estado' => PrestamoLlave::EN_USO, 'prestado_en' => now()->modify($hace), 'entregado_por' => $this->agente->id])->save();

            return $p;
        });
    }

    private function equipo(string $serie): Equipo
    {
        return $this->enEmpresa(function () use ($serie) {
            $tipo = TipoEquipo::firstOrCreate(['nombre' => 'Radio de Comunicación']);
            $e = new Equipo(['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'numero_serie' => $serie]);
            $e->forceFill(['estado' => 'disponible'])->save();

            return $e;
        });
    }

    /**
     * @param  list<Equipo>  $equipos
     * @param  list<string>  $modalidades
     */
    private function responsiva(array $equipos, array $modalidades = []): Responsiva
    {
        $c = $this->colaborador('R'.count($equipos).uniqid());
        $this->actingAs($this->admin)->post('/responsivas', [
            '_dialogo' => 'nuevo-resguardo', 'sede_id' => $this->centro->id, 'colaborador_id' => $c->id,
            'equipos' => array_map(fn (Equipo $e) => $e->id, $equipos),
            'modalidades' => $modalidades ?: array_fill(0, count($equipos), 'prestado'), 'firma' => $this->firma(),
        ])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => Responsiva::with('equipos')->latest('id')->firstOrFail());
    }

    private function horario(): RutaHorario
    {
        return $this->enEmpresa(function () {
            $turno = Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '07:00:00', 'hora_fin' => '15:00:00', 'todas_las_sedes' => true, 'activo' => true]);
            $proveedor = Proveedor::create(['nombre' => 'Transportes del Centro', 'categoria' => 'transporte_personal', 'activo' => true, 'todas_las_sedes' => true]);

            return app(AdministradorRutas::class)->crear($this->admin, $this->centro, [
                'sentido' => 'llegada', 'nombre' => 'RUTA 1', 'turno_id' => $turno->id, 'proveedor_id' => $proveedor->id, 'costo_maximo_taxi' => 250,
                'horarios' => [['nombre' => 'Diario', 'dias' => [], 'hora_inicio' => '06:00', 'hora_fin' => '06:40', 'paraderos' => [['nombre' => 'Centro', 'hora' => '']]]],
            ])->horarios()->first();
        });
    }

    /** @return array<string, mixed> */
    private function taxis(RutaHorario $h, array $quitar = []): array
    {
        $c1 = $this->colaborador('T1'.uniqid());
        $c2 = $this->colaborador('T2'.uniqid());
        $datos = [
            '_dialogo' => 'crear', 'sede_id' => $this->centro->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'no_llego',
            'ruta_horario_id' => $h->id, 'firma_guardia' => $this->firma(),
            'taxis' => [
                ['placas' => 'TX-230', 'chofer' => 'Alberto Canché', 'monto' => '180', 'destino' => 'Centro', 'tipo' => 'sedan', 'pasajeros' => [$c1->id], 'firma' => $this->firma()],
                ['placas' => 'TX-231', 'chofer' => 'Pedro Pech', 'monto' => '200', 'destino' => 'Centro', 'tipo' => 'sedan', 'pasajeros' => [$c2->id], 'firma' => $this->firma()],
            ],
        ];
        if (in_array('guardia', $quitar, true)) {
            unset($datos['firma_guardia']);
        }
        if (in_array('taxista', $quitar, true)) {
            foreach ($datos['taxis'] as $i => $t) {
                unset($datos['taxis'][$i]['firma']);
            }
        }

        return $datos;
    }

    private function categoria(): int
    {
        return $this->enEmpresa(function () {
            app(AdministradorProcedimientos::class)->categorias();

            return ProcedimientoCategoria::where('nombre', 'Emergencias')->value('id');
        });
    }

    private function conColaborador(User $u, Sede $sede): User
    {
        $depto = $this->enEmpresa(fn () => Departamento::firstOrCreate(['nombre' => 'Seguridad'])->id);
        $c = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'N'.$u->id, 'nombre' => $u->name, 'apellido_paterno' => 'Prueba',
            'sede_id' => $sede->id, 'departamento_id' => $depto]));
        $u->forceFill(['colaborador_id' => $c->id])->save();

        return $u->refresh();
    }

    private function procedimiento(bool $critico = true, bool $enviar = true): Procedimiento
    {
        $this->actingAs($this->admin)->post('/procedimientos', [
            'clave' => 'PRO-'.uniqid(), 'titulo' => 'Robo en habitación', 'categoria_id' => $this->categoria(), 'objetivo' => 'Atender el reporte.',
            'aplica' => 'todas', 'pasos' => [['texto' => 'Escuchar al huésped', 'critico' => $critico ? '1' : '0']], 'enviar' => $enviar ? '1' : '0',
        ])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => Procedimiento::with('vigente', 'trabajo')->orderByDesc('id')->firstOrFail());
    }

    private function publicar(Procedimiento $p): Procedimiento
    {
        $p = $this->enEmpresa(fn () => Procedimiento::with('trabajo')->findOrFail($p->id));
        $this->actingAs($this->director)->post("/procedimientos/{$p->id}/aprobar", ['version_id' => $p->trabajo->id, 'firma_modo' => 'nueva', 'firma' => $this->firma()])
            ->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => Procedimiento::with('vigente')->findOrFail($p->id));
    }

    // ===================================================== Configuración

    public function test_sin_configurar_todo_funciona_como_el_diseno_original(): void
    {
        $this->assertSame(AjustesCaseta::OMISION, AjustesCaseta::de($this->empresa));
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()
            // Configuración por secciones: las tarjetas de caseta y activos van en su sección (Caseta y accesos, Activos, Transporte)
            ->assertSee('Caseta y accesos')->assertSee('Activos')->assertSee('Guardia y taxista firman en pantalla (original)')
            ->assertSee('Casilla «Leí y entendí» y firma dibujada en cada versión (original)')->assertSee('id="cfg-llaves"', false);

        $llave = $this->llave('HDC-101');
        $this->prestamo($llave, $this->colaborador('1005'), '-30 hours');
        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertOk()->assertDontSee('reloj-llave')->assertDontSee('semaforo-');
        $this->responsiva([$this->equipo('RAD-1')]);
        $this->viajar('2026-10-07 15:00:00');
        $this->actingAs($this->admin)->get('/responsivas')->assertOk()->assertDontSee('reloj-turno')->assertDontSee('Debe regresar');
        $this->artisan('plataforma:activos-pendientes')->assertSuccessful()->expectsOutputToContain('Llaves fuera de tiempo avisadas: 0. Responsivas con equipos de turno sin regresar avisadas: 0.');
        $this->assertSame([], $this->avisos($this->agente, 'llave_fuera'));
    }

    public function test_guardar_ajustes_valida_audita_y_solo_guarda_lo_distinto(): void
    {
        $this->configurar(['transporte_firmas' => 'guardia', 'llaves_horas_aviso' => '8', 'llaves_horas_escalar' => '12']);
        $this->assertSame(['transporte_firmas' => 'guardia', 'llaves_horas_aviso' => 8, 'llaves_horas_escalar' => 12], $this->empresa->preferencias['caseta']);
        $this->assertSame('Cambio de ajustes de caseta y activos', LectorAuditoria::ACCIONES['caseta']);
        $this->assertTrue(Auditoria::where('evento', 'configuracion.caseta')->exists());

        $this->actingAs($this->admin)->put('/configuracion/caseta', array_merge(AjustesCaseta::OMISION, ['llaves_horas_aviso' => '12', 'llaves_horas_escalar' => '8']))
            ->assertSessionHasErrors(['llaves_horas_escalar' => 'Las horas para avisar al jefe deben ser más que las del recordatorio a la caseta.']);
        $this->actingAs($this->admin)->put('/configuracion/caseta', array_merge(AjustesCaseta::OMISION, ['responsivas_horas_turno' => '99', 'procedimientos_acuse' => 'otro']))
            ->assertSessionHasErrors(['responsivas_horas_turno' => 'Escribe un número entero de horas, de 0 a 24 (0 = apagado).', 'procedimientos_acuse' => 'Elige una de las opciones de la lista.']);

        // Volver a lo original quita la clave
        $this->actingAs($this->admin)->put('/configuracion/caseta', AjustesCaseta::OMISION)
            ->assertSessionHas('ok', 'Ajustes de caseta guardados: todo funciona como el diseño original.');
        $this->assertArrayNotHasKey('caseta', $this->empresa->refresh()->preferencias ?? []);

        // Solo quien edita Configuración en toda la empresa
        $this->actingAs($this->agente)->put('/configuracion/caseta', AjustesCaseta::OMISION)->assertForbidden();
        $this->actingAs($this->jefe)->put('/configuracion/caseta', AjustesCaseta::OMISION)->assertForbidden();
    }

    // ============================================================ Transporte

    public function test_la_unidad_no_llego_en_lugar_de_falla_de_fletera(): void
    {
        $this->horario();
        $this->actingAs($this->admin)->get('/transporte')->assertOk()
            ->assertSee('LA UNIDAD NO LLEGÓ')->assertSee('(Se usan taxis)')->assertSee('La unidad no llegó (taxis)')->assertDontSee('FLETERA')->assertDontSee('fletera');
        $this->actingAs($this->admin)->get('/transporte/reportes')->assertOk()->assertSee('Transportista')->assertDontSee('Fletera');
    }

    public function test_firmas_del_vale_configurables_con_las_dos_por_omision(): void
    {
        $h = $this->horario();
        // Original: faltan las dos firmas
        $this->actingAs($this->admin)->post('/transporte', $this->taxis($h, ['guardia', 'taxista']))
            ->assertSessionHasErrors(['firma_guardia', 'taxis.0.firma', 'taxis.1.firma']);
        $this->actingAs($this->admin)->get('/transporte')->assertSee('Firma del guardia (Seguridad)')->assertSee('Firma del taxista (recibí)');

        // Solo el guardia en pantalla: el taxista firma en papel
        $this->configurar(['transporte_firmas' => 'guardia']);
        $this->actingAs($this->admin)->get('/transporte')->assertSee('Firma del guardia (Seguridad)')->assertDontSee('Firma del taxista (recibí)')
            ->assertSee('el conductor firma a mano en el vale impreso');
        $this->actingAs($this->admin)->post('/transporte', $this->taxis($h, ['taxista']))->assertSessionHasNoErrors();
        $vale = $this->enEmpresa(fn () => MovimientoTransporte::latest('id')->firstOrFail());
        $this->assertNotNull($vale->firma_guardia);
        $this->assertNull($vale->firma_taxista);
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/vale")->assertOk()->assertSee('El conductor y quien autoriza firman a mano.');

        // Solo el taxista
        $this->configurar(['transporte_firmas' => 'taxista']);
        $this->actingAs($this->admin)->post('/transporte', $this->taxis($h, ['guardia']))->assertSessionHasNoErrors();
        $vale = $this->enEmpresa(fn () => MovimientoTransporte::latest('id')->firstOrFail());
        $this->assertNull($vale->firma_guardia);
        $this->assertNotNull($vale->firma_taxista);

        // Nadie en pantalla
        $this->configurar(['transporte_firmas' => 'ninguna']);
        $this->actingAs($this->admin)->get('/transporte')->assertDontSee('Firma del guardia (Seguridad)')->assertDontSee('Firma del taxista (recibí)');
        $this->actingAs($this->admin)->post('/transporte', $this->taxis($h, ['guardia', 'taxista']))->assertSessionHasNoErrors();
        $vale = $this->enEmpresa(fn () => MovimientoTransporte::latest('id')->firstOrFail());
        $this->assertNull($vale->firma_guardia);
        $this->assertNull($vale->firma_taxista);
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/vale")->assertSee('Seguridad, el conductor y quien autoriza firman a mano.');
    }

    public function test_el_vale_espera_el_vobo_en_la_campana_y_mis_pendientes_y_se_resuelve(): void
    {
        $h = $this->horario();
        $this->actingAs($this->agente)->post('/transporte', $this->taxis($h))->assertSessionHasNoErrors();
        [$uno, $dos] = $this->enEmpresa(fn () => MovimientoTransporte::orderBy('id')->get()->all());

        $avisos = $this->avisos($this->director, 'vale_taxi_por_autorizar');
        $this->assertCount(2, $avisos);
        $this->assertSame('Vale de taxi por autorizar: '.$uno->folio(), $avisos[0]->titulo);
        $this->assertStringContainsString('$180.00', $avisos[0]->texto);
        $this->assertSame([], $this->avisos($this->agente, 'vale_taxi_por_autorizar')); // quien lo registró no autoriza
        $pend = $this->pendientes($this->director);
        $this->assertSame(2, $pend['vales_taxi']['total']);
        $this->assertStringContainsString('estado=por_autorizar', $pend['vales_taxi']['url']);
        $this->assertArrayNotHasKey('vales_taxi', $this->pendientes($this->agente));

        $this->actingAs($this->director)->patch("/transporte/{$uno->id}/autorizar")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch("/transporte/{$dos->id}/anular")->assertSessionHasNoErrors();
        $avisos = $this->avisos($this->director, 'vale_taxi_por_autorizar');
        $this->assertNotNull($avisos[0]->leida_en);
        $this->assertNull($avisos[0]->acciones);
        $this->assertNotNull($avisos[1]->leida_en);
        $this->assertSame(0, $this->pendientes($this->director)['vales_taxi']['total']);
    }

    public function test_vobo_pendiente_muestra_reloj_y_color_con_texto_en_vales_y_procedimientos(): void
    {
        // Umbrales claros: normal < 24 h, amarillo 24–72 h, rojo > 72 h, y siempre con texto
        $base = CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC');
        $this->assertSame('normal', RelojVoBo::de($base, $base->addHours(23)->addMinutes(59))['nivel']);
        $this->assertSame('ambar', RelojVoBo::de($base, $base->addHours(24))['nivel']);
        $this->assertSame('ambar', RelojVoBo::de($base, $base->addHours(72))['nivel']);
        $this->assertSame('roja', RelojVoBo::de($base, $base->addHours(72)->addMinute())['nivel']);
        $this->assertSame('Esperando Vo.Bo. hace 3 h (a tiempo)', RelojVoBo::de($base, $base->addHours(3))['texto']);
        $this->assertSame('Esperando Vo.Bo. hace 4 días 2 h (más de 3 días)', RelojVoBo::de($base, $base->addHours(98))['texto']);

        // Vale de taxi sin Vo.Bo.: en la lista dice desde cuándo espera y cambia de color
        $h = $this->horario();
        $this->actingAs($this->agente)->post('/transporte', $this->taxis($h))->assertSessionHasNoErrors();
        $this->viajar('2026-10-05 18:00:00'); // 3 h después
        $lista = $this->actingAs($this->director)->get('/transporte?estado=por_autorizar')->assertOk()->getContent();
        $this->assertStringContainsString('data-reloj-vobo="normal"', $lista);
        $this->assertMatchesRegularExpression('/Hace 3 h · <strong>a tiempo<\/strong>/u', $lista);
        $this->assertNull($this->pendientes($this->director)['vales_taxi']['nivel']);

        $this->viajar('2026-10-06 21:00:00'); // 30 h: se llega desde Mis pendientes (lleva la fecha del más antiguo)
        $lista = $this->actingAs($this->director)->get($this->pendientes($this->director)['vales_taxi']['url'])->assertOk()->getContent();
        $this->assertStringContainsString('data-reloj-vobo="ambar"', $lista);
        $this->assertStringContainsString('<strong>más de 1 día</strong>', $lista);
        $this->assertSame('ambar', $this->pendientes($this->director)['vales_taxi']['nivel']);

        $this->viajar('2026-10-09 15:00:00'); // 96 h
        $lista = $this->actingAs($this->director)->get($this->pendientes($this->director)['vales_taxi']['url'])->assertOk()->getContent();
        $this->assertStringContainsString('data-reloj-vobo="roja"', $lista);
        $this->assertStringContainsString('<strong>más de 3 días</strong>', $lista);
        $this->assertSame('roja', $this->pendientes($this->director)['vales_taxi']['nivel']);

        // Uno recibe su Vo.Bo.: su reloj desaparece y el otro sigue esperando en rojo
        $uno = $this->enEmpresa(fn () => MovimientoTransporte::orderBy('id')->firstOrFail());
        $this->actingAs($this->director)->patch("/transporte/{$uno->id}/autorizar")->assertSessionHasNoErrors();
        $this->actingAs($this->director)->get('/transporte?estado=por_autorizar&fecha_inicio=2026-10-05')->assertOk()->assertSee('data-reloj-vobo="roja"', false); // el otro sigue esperando
        $this->assertSame(1, $this->pendientes($this->director)['vales_taxi']['total']);

        // Procedimiento en revisión: el reloj cuenta desde que se envió
        $p = $this->procedimiento();
        $this->viajar('2026-10-11 15:00:00'); // 48 h después de enviarlo
        $this->actingAs($this->director)->get('/procedimientos')->assertOk()
            ->assertSee('data-reloj-vobo="ambar"', false)->assertSee('Hace 2 días · <strong>más de 1 día</strong>', false);
        $this->publicar($p);
        $this->actingAs($this->director)->get('/procedimientos')->assertOk()->assertDontSee('data-reloj-vobo', false);
    }

    // ===================================================== Préstamo de llaves

    public function test_recibir_una_llave_escaneandola_desde_la_pantalla_principal(): void
    {
        $llave = $this->llave('HDC-101');
        $p = $this->prestamo($llave, $this->colaborador('1005'));
        $this->actingAs($this->agente)->get('/prestamo-llaves')->assertOk()
            ->assertSee('Recibir: escanea la llave que te devuelven')->assertSee('data-recibir-llave-escaneo', false);

        $this->actingAs($this->agente)->post('/prestamo-llaves/recibir', ['lectura' => ' hdc-101 '])
            ->assertRedirect('/prestamo-llaves')
            ->assertSessionHas('ok', 'Llave «HDC-101» recibida de Persona Num1005. Devuelve su garantía: INE (A123). Escanea la siguiente si hay más.');
        $this->assertSame(PrestamoLlave::DEVUELTA, $this->enEmpresa(fn () => PrestamoLlave::find($p->id))->estado);

        $this->actingAs($this->agente)->post('/prestamo-llaves/recibir', ['lectura' => 'HDC-101'])
            ->assertSessionHasErrors(['lectura' => 'Esa llave no está prestada: ya está en caseta.']);
        $this->actingAs($this->agente)->post('/prestamo-llaves/recibir', ['lectura' => 'NO-EXISTE'])
            ->assertSessionHasErrors(['lectura' => 'No encontré ninguna llave con «NO-EXISTE». Revisa la etiqueta o escribe su nombre completo.']);
        $this->actingAs($this->agente)->post('/prestamo-llaves/recibir', ['lectura' => ''])->assertSessionHasErrors('lectura');

        // Con el QR (dirección /e/…) y desde otra sede
        $otra = $this->llave('PLA-7', $this->playa);
        $this->prestamo($otra, $this->colaborador('2001', $this->playa));
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($this->agente)->post('/prestamo-llaves/recibir', ['lectura' => 'PLA-7'])->assertSessionHasErrors('lectura');
        $this->actingAs($agentePlaya)->post('/prestamo-llaves/recibir', ['lectura' => url('/e/'.$otra->codigo_qr)])->assertSessionHasNoErrors();
        $this->assertTrue(Auditoria::where('evento', 'prestamo_llaves.recibido')->where('user_id', $agentePlaya->id)->exists());
    }

    public function test_folio_de_garantia_se_llena_con_el_gafete_escaneado(): void
    {
        $this->actingAs($this->agente)->get('/prestamo-llaves')->assertOk()
            ->assertSee('data-folio-garantia', false)->assertSee('Con «Gafete interno» se llena solo con el número del gafete que escaneaste.');
    }

    public function test_semaforo_y_avisos_de_llaves_que_llevan_mucho_tiempo_fuera(): void
    {
        $this->configurar(['llaves_horas_aviso' => '2', 'llaves_horas_escalar' => '4']);
        $llave = $this->llave('HDC-101');
        $p = $this->prestamo($llave, $this->colaborador('1005'), '-150 minutes');
        $reciente = $this->prestamo($this->llave('HDC-102'), $this->colaborador('1006'), '-10 minutes');

        $this->actingAs($this->agente)->get('/prestamo-llaves')->assertOk()
            ->assertSee('semaforo-ambar')->assertSee('Fuera hace 2 h 30 min · pide que la regresen')->assertSee('Fuera hace 10 min');

        $this->artisan('plataforma:activos-pendientes --si-toca')->assertSuccessful()->expectsOutputToContain('Llaves fuera de tiempo avisadas: 1.');
        $avisos = $this->avisos($this->agente, 'llave_fuera');
        $this->assertCount(1, $avisos);
        $this->assertSame('Llave HDC-101 fuera desde hace 2 h 30 min', $avisos[0]->titulo);
        $jefeAntes = count($this->avisos($this->jefe, 'llave_fuera')); // el jefe recibe en su sede como caseta
        $this->artisan('plataforma:activos-pendientes --si-toca')->assertSuccessful()->doesntExpectOutputToContain('Llaves');
        $this->assertCount(1, $this->avisos($this->agente, 'llave_fuera'));
        $pend = $this->pendientes($this->agente);
        $this->assertSame(1, $pend['llaves_fuera']['total']);
        $this->assertSame('ambar', $pend['llaves_fuera']['nivel']);

        // Pasan las horas de escalamiento: rojo y aviso al jefe de seguridad
        $this->viajar('2026-10-05 17:00:00');
        $this->artisan('plataforma:activos-pendientes')->assertSuccessful();
        $this->assertGreaterThan($jefeAntes, count($this->avisos($this->jefe, 'llave_fuera')));
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefe->id)->where('referencia_tipo', 'prestamo_llave_escalada')->where('referencia_id', $p->id)->exists()));
        // el agente no anula préstamos: no le llega el rojo
        $this->assertFalse($this->enEmpresa(fn () => Notificacion::where('user_id', $this->agente->id)->where('referencia_tipo', 'prestamo_llave_escalada')->exists()));
        $this->assertSame('roja', $this->pendientes($this->agente)['llaves_fuera']['nivel']);
        $this->actingAs($this->agente)->get('/prestamo-llaves')->assertSee('semaforo-rojo')->assertSee('pasó del límite');

        // Al recibirla, los avisos se resuelven
        $this->actingAs($this->agente)->patch("/prestamo-llaves/{$p->id}/recibir")->assertSessionHasNoErrors();
        $this->assertFalse($this->enEmpresa(fn () => Notificacion::where('referencia_id', $p->id)->whereIn('referencia_tipo', ['prestamo_llave_aviso', 'prestamo_llave_escalada'])
            ->whereNull('leida_en')->exists()));
        // Queda la otra llave, en ámbar
        $this->assertSame(1, $this->pendientes($this->agente)['llaves_fuera']['total']);
        $this->assertSame('ambar', $this->pendientes($this->agente)['llaves_fuera']['nivel']);
        $this->actingAs($this->agente)->patch("/prestamo-llaves/{$reciente->id}/recibir")->assertSessionHasNoErrors();
        $this->assertSame(0, $this->pendientes($this->agente)['llaves_fuera']['total']);
    }

    // ======================================================== Procedimientos

    public function test_enviar_a_revision_avisa_a_quien_aprueba_y_el_autor_se_entera(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
        Mail::fake();
        $p = $this->procedimiento();

        $this->assertCount(1, $this->avisos($this->director, 'procedimiento_revision'));
        $this->assertSame([], $this->avisos($this->admin, 'procedimiento_revision')); // su autor no se aprueba a sí mismo
        $this->assertSame([], $this->avisos($this->jefe, 'procedimiento_revision')); // aplica a todas las sedes: lo aprueba alguien de toda la empresa
        $this->assertSame('Procedimiento por aprobar: '.$p->clave, $this->avisos($this->director, 'procedimiento_revision')[0]->titulo);
        $this->assertSame(1, $this->pendientes($this->director)['procedimientos_aprobar']['total']);
        Mail::assertSent(AvisoProcedimiento::class, fn ($m) => $m->tipo === 'revision' && $m->hasTo($this->director->email) && ! $m->hasTo($this->admin->email));

        // Pedir cambios: el autor se entera con el comentario
        $version = $this->enEmpresa(fn () => Procedimiento::with('trabajo')->findOrFail($p->id))->trabajo;
        $this->actingAs($this->director)->post("/procedimientos/{$p->id}/rechazar", ['version_id' => $version->id, 'comentario' => 'Falta el paso de llamar al gerente.'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($this->avisos($this->director, 'procedimiento_revision')[0]->leida_en);
        $rechazo = $this->avisos($this->admin, 'procedimiento_rechazado');
        $this->assertCount(1, $rechazo);
        $this->assertStringContainsString('Falta el paso de llamar al gerente.', $rechazo[0]->texto);
        $this->assertSame(0, $this->pendientes($this->director)['procedimientos_aprobar']['total']);

        // Reenviar y aprobar: el autor sabe que se publicó y el personal lo ve en la campana
        $this->actingAs($this->admin)->post("/procedimientos/{$p->id}/enviar")->assertSessionHasNoErrors();
        $agente = $this->conColaborador($this->agente, $this->centro);
        $this->publicar($p);
        $this->assertCount(1, $this->avisos($this->admin, 'procedimiento_aprobado'));
        $porLeer = $this->avisos($agente, 'procedimiento_publicado');
        $this->assertCount(1, $porLeer);
        $this->assertSame('Procedimiento por leer: '.$p->clave, $porLeer[0]->titulo);
        $this->assertSame([['etiqueta' => 'Leer ahora', 'abrir' => true]], $porLeer[0]->acciones);
    }

    public function test_acuse_con_firma_por_omision_y_con_solo_casilla_configurable(): void
    {
        $agente = $this->conColaborador($this->agente, $this->centro);
        $p = $this->publicar($this->procedimiento(critico: false));
        // Original: pide firma
        $this->actingAs($agente)->get("/procedimientos/{$p->id}/leer")->assertOk()->assertSee('Firmar de enterado')->assertDontSee('Guardar «Leí y entendí»');
        $this->actingAs($agente)->post("/procedimientos/{$p->id}/acuse", ['version_id' => $p->vigente->id, 'entendido' => '1', 'firma_modo' => 'nueva'])
            ->assertSessionHasErrors('firma');

        // Solo casilla: queda con nombre, fecha y hora, sin firma
        $this->configurar(['procedimientos_acuse' => 'casilla']);
        $this->actingAs($agente)->get("/procedimientos/{$p->id}/leer")->assertOk()->assertSee('Guardar «Leí y entendí»')->assertDontSee('Firmar de enterado');
        $this->actingAs($agente)->post("/procedimientos/{$p->id}/acuse", ['version_id' => $p->vigente->id, 'entendido' => '1'])->assertSessionHasNoErrors();
        $acuse = $this->enEmpresa(fn () => ProcedimientoAcuse::where('user_id', $agente->id)->firstOrFail());
        $this->assertNull($acuse->firma_ruta);
        $this->assertNotNull($this->avisos($agente, 'procedimiento_publicado')[0]->leida_en);
        $this->actingAs($this->director)->get("/procedimientos/{$p->id}?pestana=acuses")->assertOk()->assertSee('Casilla');
        $this->actingAs($this->director)->get("/procedimientos/{$p->id}/acuses/{$acuse->id}/firma")->assertNotFound();

        // Casilla salvo puntos críticos
        $this->configurar(['procedimientos_acuse' => 'casilla_criticos']);
        $critico = $this->publicar($this->procedimiento(critico: true));
        $this->actingAs($agente)->post("/procedimientos/{$critico->id}/acuse", ['version_id' => $critico->vigente->id, 'entendido' => '1', 'firma_modo' => 'nueva'])
            ->assertSessionHasErrors('firma');
        $this->actingAs($agente)->post("/procedimientos/{$critico->id}/acuse", ['version_id' => $critico->vigente->id, 'entendido' => '1', 'firma_modo' => 'nueva', 'firma' => $this->firma()])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($this->enEmpresa(fn () => ProcedimientoAcuse::where('version_id', $critico->vigente->id)->value('firma_ruta')));
    }

    // =========================================================== Responsivas

    public function test_un_solo_recibir_con_casillas_cubre_parcial_y_completo(): void
    {
        $r = $this->responsiva([$this->equipo('RAD-1'), $this->equipo('RAD-2'), $this->equipo('RAD-3')]);
        [$f1, $f2, $f3] = $r->equipos->all();
        $this->actingAs($this->admin)->get('/responsivas')->assertOk()
            ->assertSee('Recibir marcados (OK)')->assertSee('Dañado o falta')->assertDontSee('Recibir Lote Completo (OK)')
            ->assertSee('name="equipos[]" value="'.$f1->id.'" checked', false);

        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir", ['equipos' => [$f1->id, $f2->id]])
            ->assertSessionHas('ok', "Responsiva {$r->folio}: 2 equipos vuelven a DISPONIBLE. Falta 1 equipo por regresar.");
        $this->assertSame(Responsiva::EN_CAMPO, $this->enEmpresa(fn () => Responsiva::find($r->id))->estado);
        $this->assertSame('disponible', $this->enEmpresa(fn () => Equipo::find($f1->equipo_id))->estado);
        $this->assertSame('asignado', $this->enEmpresa(fn () => Equipo::find($f3->equipo_id))->estado);

        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir", ['equipos' => [$f1->id]])
            ->assertSessionHasErrors(['equipos' => 'Uno de los equipos marcados ya se había recibido. Revisa la responsiva e intenta de nuevo.']);
        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir", ['equipos' => []])
            ->assertSessionHasErrors(['equipos' => 'Marca al menos un equipo que regresa.']);

        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir", ['equipos' => [$f3->id]])
            ->assertSessionHas('ok', "Responsiva {$r->folio} recibida: 1 equipo vuelve a DISPONIBLE.");
        $this->assertSame(Responsiva::DEVUELTA, $this->enEmpresa(fn () => Responsiva::find($r->id))->estado);
        $this->assertTrue(Auditoria::where('evento', 'responsivas.recibido')->exists());
        $this->assertSame((int) $this->admin->id, (int) $this->enEmpresa(fn () => EquipoResponsiva::find($f3->id))->recibido_por);
    }

    public function test_un_solo_termino_responsiva_en_pantalla_y_hoja(): void
    {
        $r = $this->responsiva([$this->equipo('RAD-1')]);
        $this->actingAs($this->admin)->get('/responsivas')->assertOk()->assertSeeText('Nueva responsiva')
            ->assertDontSeeText('Lote')->assertDontSeeText('Resguardo')->assertDontSeeText('resguardo');
        $this->actingAs($this->admin)->get("/responsivas/{$r->id}/hoja")->assertOk()->assertSee('RESPONSIVA DE EQUIPO DE SEGURIDAD')
            ->assertSee('Imprimir responsiva')->assertDontSeeText('RESGUARDO');
    }

    public function test_equipos_de_turno_con_hora_de_regreso_semaforo_y_avisos(): void
    {
        $this->configurar(['responsivas_horas_turno' => '8', 'responsivas_horas_escalar' => '2']);
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $r = $this->responsiva([$this->equipo('RAD-1'), $this->equipo('CH-1')], ['prestado', 'asignado']);
        $fijo = $this->responsiva([$this->equipo('CH-2')], ['asignado']);

        // Entregado a las 09:00 → debe regresar a las 17:00 (hora de la sede)
        $this->actingAs($this->admin)->get('/responsivas')->assertOk()->assertSee('Debe regresar a las 17:00 del 05/10')->assertSee('(1 equipo de turno)');
        $this->artisan('plataforma:activos-pendientes')->assertSuccessful()->expectsOutputToContain('Responsivas con equipos de turno sin regresar avisadas: 0.');

        $this->viajar('2026-10-06 00:30:00'); // 18:30 local: ámbar
        $this->actingAs($this->admin)->get('/responsivas')->assertSee('Debía regresar a las 17:00')->assertSee('semaforo-ambar');
        $this->artisan('plataforma:activos-pendientes')->assertSuccessful()->expectsOutputToContain('Responsivas con equipos de turno sin regresar avisadas: 1.');
        $this->assertCount(1, $this->avisos($supervisor, 'equipo_turno'));
        $this->assertSame("Responsiva {$r->folio}: 1 equipo de turno sin regresar", $this->avisos($supervisor, 'equipo_turno')[0]->titulo);
        $this->assertSame('ambar', $this->pendientes($supervisor)['equipos_turno']['nivel']);

        $this->viajar('2026-10-06 02:30:00'); // 20:30 local: rojo, al jefe de seguridad (da de baja equipos)
        $this->artisan('plataforma:activos-pendientes')->assertSuccessful();
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefe->id)->where('referencia_tipo', 'responsiva_turno_escalada')->exists()));
        $this->assertCount(1, $this->avisos($supervisor, 'equipo_turno')); // el supervisor no da de baja equipos: el rojo va al jefe
        $this->assertSame('roja', $this->pendientes($supervisor)['equipos_turno']['nivel']);

        // Regresa el radio de turno: el fijo se queda y los avisos se resuelven
        $turno = $r->equipos->firstWhere('modalidad', 'prestado');
        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir", ['equipos' => [$turno->id]])->assertSessionHasNoErrors();
        $this->assertNotNull($this->avisos($supervisor, 'equipo_turno')[0]->leida_en);
        $this->assertNull(collect($this->avisos($this->jefe, 'equipo_turno'))->firstWhere('leida_en', null));
        $this->assertSame(0, $this->pendientes($supervisor)['equipos_turno']['total']);
        $this->actingAs($this->admin)->get('/responsivas')->assertDontSee('regresar a las');
        $this->assertNotNull($fijo->id);
    }

    // ======================================================= Lector universal

    public function test_escanear_una_llave_fuera_de_un_formulario_ofrece_recibir_o_prestar(): void
    {
        $llave = $this->llave('HDC-101');
        $p = $this->prestamo($llave, $this->colaborador('1005'));

        $this->actingAs($this->agente)->get('/e/'.$llave->codigo_qr)->assertOk()
            ->assertSee('Llave prestada')->assertSee('HDC-101')->assertSee('La tiene Persona Num1005')->assertSee('Garantía: INE (A123).')
            ->assertSee('Recibir')->assertSee(route('prestamo_llaves.recibir', $p->id), false)->assertSee('Solo ver la ficha');
        $this->actingAs($this->agente)->patch("/prestamo-llaves/{$p->id}/recibir")->assertSessionHasNoErrors();

        // En caseta: «Prestar» abre el diálogo con la llave elegida
        $this->actingAs($this->agente)->get('/e/'.$llave->codigo_qr)->assertOk()->assertSee('Llave en caseta')
            ->assertSee(route('prestamo_llaves.index', ['prestar' => $llave->id]), false);
        $this->actingAs($this->agente)->get('/prestamo-llaves?prestar='.$llave->id)->assertOk()
            ->assertSee('id="dialogoPrestarLlave" class="dialogo dialogo-prestar" aria-labelledby="titulo-prestar-llave"  data-abrir-al-cargar', false)
            ->assertSee('name="llave_id" value="'.$llave->id.'"', false);

        // Sin permiso de préstamos: se abre la ficha como siempre
        $director = $this->director;
        $this->actingAs($director)->get('/e/'.$llave->codigo_qr)->assertRedirect(route('llaves.index').'#llave-'.$llave->id);
    }

    public function test_escanear_un_equipo_en_responsiva_ofrece_recibirlo(): void
    {
        $radio = $this->equipo('RAD-1');
        $r = $this->responsiva([$radio, $this->equipo('RAD-2')]);
        $fila = $r->equipos->firstWhere('equipo_id', $radio->id);

        $this->actingAs($this->admin)->get('/e/'.$radio->codigo_qr)->assertOk()
            ->assertSee('Equipo en responsiva')->assertSee('Serie: RAD-1')->assertSee($r->folio)->assertSee('Recibir (OK)')
            ->assertSee('name="equipos[]" value="'.$fila->id.'"', false)->assertSee('Viene dañado o falta: abrir la responsiva');
        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir", ['equipos' => [$fila->id]])->assertSessionHasNoErrors();
        $this->assertSame('disponible', $this->enEmpresa(fn () => Equipo::find($radio->id))->estado);

        // Ya en el almacén: se abre su ficha
        $this->actingAs($this->admin)->get('/e/'.$radio->codigo_qr)->assertRedirect(route('equipos.index').'#equipo-'.$radio->id);
    }
}
