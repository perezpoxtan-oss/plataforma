<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Mail\ValeTaxiRegistrado;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\MovimientoTransporte;
use App\Models\Paradero;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Ruta;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vehiculo;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Rutas\AdministradorRutas;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class TransporteTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private Proveedor $kinha;

    private RutaHorario $llegada;   // RUTA 1 llegada Centro, tope 250

    private RutaHorario $salida;    // RUTA 1 salida Centro, sin tope

    private RutaHorario $llegadaPlaya;

    /** @var list<Colaborador> */
    private array $colabs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Lunes 5 de octubre de 2026, 07:00 en Ciudad de México (zona de la empresa)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-10-05 13:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');

        [$turno, $this->kinha] = $this->enEmpresa(fn () => [
            Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '07:00:00', 'hora_fin' => '15:00:00', 'todas_las_sedes' => true, 'activo' => true]),
            Proveedor::create(['nombre' => 'Transportes Kin-Ha', 'categoria' => 'transporte_personal', 'activo' => true, 'todas_las_sedes' => true]),
        ]);
        $ruta = fn (Sede $sede, string $sentido, string $nombre, ?float $tope, string $ini, string $fin) => $this->enEmpresa(fn () => app(AdministradorRutas::class)->crear($this->admin, $sede, [
            'sentido' => $sentido, 'nombre' => $nombre, 'turno_id' => $turno->id, 'proveedor_id' => $this->kinha->id, 'costo_maximo_taxi' => $tope,
            'horarios' => [['nombre' => 'Diario', 'dias' => [], 'hora_inicio' => $ini, 'hora_fin' => $fin, 'paraderos' => [['nombre' => 'Chedraui Portillo', 'hora' => '']]]],
        ]))->horarios()->first();
        $this->llegada = $ruta($this->centro, 'llegada', 'RUTA 1 - REGIÓN 94', 250, '06:00', '06:40');
        $this->salida = $ruta($this->centro, 'salida', 'RUTA 1 - REGIÓN 94', null, '15:10', '16:00');
        $this->llegadaPlaya = $ruta($this->playa, 'llegada', 'RUTA P - BONFIL', 280, '06:00', '06:50');

        foreach ([['1005', 'Roberto', 'Hernández'], ['1007', 'Mariana', 'López'], ['1009', 'Guadalupe', 'Chan']] as [$num, $nombre, $paterno]) {
            $this->colabs[] = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno, 'sede_id' => $this->centro->id]));
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------- Apoyo

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
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

    /** @return array<string, mixed> */
    private function normal(array $extra = []): array
    {
        return array_merge([
            '_dialogo' => 'crear', 'sede_id' => $this->centro->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'a_tiempo',
            'ruta_horario_id' => $this->llegada->id, 'placas' => 'tkh-101', 'chofer' => 'juan carlos  poot chan', 'cantidad_pax' => '18',
            'tipo_unidad' => 'autobus', 'marca' => 'mercedes-benz', 'modelo' => 'boxer', 'economico' => '101', 'capacidad' => '40',
            'chofer_telefono' => '998 123 4567', 'observaciones' => 'Llegó con tráfico.',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function taxis(array $extra = [], ?array $taxis = null): array
    {
        return array_merge([
            '_dialogo' => 'crear', 'sede_id' => $this->centro->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'no_llego',
            'ruta_horario_id' => $this->llegada->id, 'firma_guardia' => $this->firma(), 'observaciones' => 'La unidad se descompuso.',
            'taxis' => $taxis ?? [
                ['placas' => 'TX-230', 'chofer' => 'Alberto Canché', 'monto' => '180', 'destino' => 'chedraui portillo', 'tipo' => 'sedan',
                    'pasajeros' => [$this->colabs[0]->id, $this->colabs[1]->id], 'firma' => $this->firma()],
                ['placas' => 'TX-231', 'chofer' => 'Pedro Pech', 'monto' => '320', 'destino' => 'Plaza Las Américas', 'tipo' => 'suv',
                    'justificacion' => 'Lluvia intensa.', 'pasajeros' => [$this->colabs[2]->id], 'firma' => $this->firma()],
            ],
        ], $extra);
    }

    private function registrar(array $datos, ?User $actor = null): MovimientoTransporte
    {
        $actor ??= $this->admin;
        $this->actingAs($actor)->post('/transporte', $datos)->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => MovimientoTransporte::latest('id')->firstOrFail());
    }

    /**
     * @param  array<string, Alcance>  $permisos
     */
    private function usuarioCon(array $permisos, ?Sede $sede = null): User
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Rol transporte '.uniqid(), 'nivel_jerarquia' => 40]);
        $roles->sincronizarPermisos($sa, $rol, $permisos);
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => Rol::findOrFail($rol->id)->id, 'sede_id' => $sede?->id]);

        return $usuario;
    }

    private function contar(): int
    {
        return $this->enEmpresa(fn () => MovimientoTransporte::count());
    }

    // ------------------------------------------------------------ Pantalla

    public function test_menu_y_pantalla_con_textos_de_segcat_lector_y_firmas(): void
    {
        $this->assertSame('transporte.index', Modulo::where('clave', 'transporte')->value('ruta'));

        $this->actingAs($this->admin)->get('/transporte')->assertOk()
            ->assertSee('Bitácora de Transporte')->assertSee('Registro operativo e incidencias multi-taxi en tiempo real.')
            ->assertSee('Registrar Movimiento')->assertSee('Registrar Bitácora Logística')
            ->assertSee('LLEGADA')->assertSee('(A la Sede)')->assertSee('(Hacia Paraderos)')
            ->assertSee('SERVICIO NORMAL')->assertSee('FALLA DE FLETERA')->assertSee('Despacho de Unidades de Emergencia (Taxis)')
            ->assertSee('Añadir otro Taxi')->assertSee('Datos de la Unidad')->assertSee('Registrar y capturar siguiente')
            ->assertSee('No se encontraron registros en este periodo.')
            ->assertSee('Exportar Excel')->assertSee('Reportes Avanzados')
            // Lector universal para los pasajeros (sin buscadores propios) y firmas en pantalla
            ->assertSee('data-plantilla-taxi', false)->assertSee('data-tipos="colaborador"', false)
            ->assertSee('name="firma_guardia"', false)->assertSee('taxis[__T__][firma]', false)
            // Ruta sugerida (la más cercana a las 07:00 en la sede): la llegada de las 06:40
            ->assertSee('value="'.$this->llegada->id.'" data-sede="'.$this->centro->id.'" data-sentido="llegada"', false)
            ->assertDontSee('Hotel Sede')->assertDontSee('Al Hotel');
    }

    public function test_el_horario_sugerido_es_el_mas_cercano_a_la_hora_de_la_sede(): void
    {
        $bitacora = app(BitacoraTransporte::class);
        $horarios = $this->enEmpresa(fn () => $bitacora->horariosPara([$this->centro->id]));
        $s = $bitacora->sugerencia($horarios, CarbonImmutable::parse('2026-10-05 07:00', 'America/Mexico_City'));
        $this->assertSame(['llegada' => $this->llegada->id, 'salida' => $this->salida->id, 'sentido' => 'llegada'], $s);
        $s = $bitacora->sugerencia($horarios, CarbonImmutable::parse('2026-10-05 15:00', 'America/Mexico_City'));
        $this->assertSame('salida', $s['sentido']);
    }

    // ------------------------------------------------------------------ Alta

    public function test_alta_normal_registra_unidad_y_chofer_en_sus_padrones_con_auditoria(): void
    {
        $this->actingAs($this->admin)->post('/transporte', $this->normal())
            ->assertSessionHasNoErrors()->assertSessionHas('ok', 'Movimiento registrado correctamente.');

        $m = $this->enEmpresa(fn () => MovimientoTransporte::with('vehiculo', 'chofer')->firstOrFail());
        $this->assertSame(['llegada', 'a_tiempo', 18, '2026-10-05'], [$m->tipo_movimiento, $m->estatus, $m->cantidad_pax, $m->fecha->toDateString()]);
        $this->assertSame([$this->centro->id, $this->llegada->ruta_id, $this->llegada->id, $this->admin->id], [$m->sede_id, $m->ruta_id, $m->ruta_horario_id, $m->creado_por]);
        $this->assertSame(['TKH101', 'transporte_personal', 'autobus', 'MERCEDES-BENZ', 'BOXER', '101', 40, $this->kinha->id],
            [$m->vehiculo->placas, $m->vehiculo->propiedad, $m->vehiculo->tipo, $m->vehiculo->marca, $m->vehiculo->modelo, $m->vehiculo->numero_economico, $m->vehiculo->capacidad, $m->vehiculo->proveedor_id]);
        $this->assertSame(['JUAN CARLOS POOT CHAN', 'proveedor', $this->kinha->id, '9981234567'], [$m->chofer->nombre_completo, $m->chofer->tipo, $m->chofer->proveedor_id, $m->chofer->telefono]);
        $this->assertNull($m->monto);
        $this->assertTrue(Auditoria::where('evento', 'transporte.creado')->where('auditable_id', $m->id)->exists());
        $this->assertTrue(Auditoria::where('evento', 'vehiculos.creado')->exists());
        $this->assertTrue(Auditoria::where('evento', 'visitantes.creado')->exists());

        // La misma unidad y chofer se reutilizan (sin duplicar) y se completan datos faltantes
        $this->enEmpresa(fn () => Vehiculo::where('placas', 'TKH101')->update(['modelo' => null]));
        $this->registrar($this->normal(['estatus' => 'retraso', 'placas' => 'TKH 101', 'marca' => 'OTRA', 'cantidad_pax' => '']));
        $this->assertSame(1, $this->enEmpresa(fn () => Vehiculo::where('placas', 'TKH101')->count()));
        $this->assertSame(1, $this->enEmpresa(fn () => Persona::where('nombre_completo', 'JUAN CARLOS POOT CHAN')->count()));
        $v = $this->enEmpresa(fn () => Vehiculo::where('placas', 'TKH101')->first());
        $this->assertSame(['MERCEDES-BENZ', 'BOXER'], [$v->marca, $v->modelo]);

        // Todo opcional en el servicio normal: sin unidad ni chofer
        $sinDatos = $this->registrar($this->normal(['placas' => '', 'chofer' => '', 'cantidad_pax' => '', 'marca' => '', 'modelo' => '', 'economico' => '', 'capacidad' => '', 'chofer_telefono' => '']));
        $this->assertNull($sinDatos->vehiculo_id);
        $this->assertSame(0, $sinDatos->cantidad_pax);

        $this->actingAs($this->admin)->get('/transporte')->assertSee('RUTA 1 - REGIÓN 94')->assertSee('Autobús (TKH101 · Eco. 101)')
            ->assertSee('JUAN CARLOS POOT CHAN')->assertSee('A TIEMPO')->assertSee('RETRASO')->assertSee('Registrado por:');
    }

    public function test_alta_de_taxis_un_registro_por_taxi_con_pasajeros_firmas_destino_y_correo(): void
    {
        Mail::fake();
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
        $this->empresa->forceFill(['preferencias' => ['avisos_destinatarios' => ['vale_taxi' => ['finanzas@ejemplo.com']]]])->save();

        $r = $this->actingAs($this->admin)->post('/transporte', $this->taxis());
        $r->assertSessionHasNoErrors()->assertSessionHas('vales');

        $vales = $this->enEmpresa(fn () => MovimientoTransporte::with('vehiculo', 'chofer', 'paradero', 'pasajeros')->orderBy('id')->get());
        $this->assertCount(2, $vales);
        [$a, $b] = $vales->all();
        $this->assertSame($a->lote, $b->lote);
        $this->assertNotNull($a->lote);
        $this->assertSame(['no_llego', '180.00', 2, 'TX230', 'taxi_app', null, 'ALBERTO CANCHÉ', 'CHEDRAUI PORTILLO'],
            [$a->estatus, $a->monto, $a->cantidad_pax, $a->vehiculo->placas, $a->vehiculo->propiedad, $a->vehiculo->proveedor_id, $a->chofer->nombre_completo, $a->paradero->nombre]);
        $this->assertSame([$this->colabs[0]->id, $this->colabs[1]->id], $a->pasajeros->pluck('id')->sort()->values()->all());
        $this->assertSame(['320.00', 'Lluvia intensa.', 'suv', 'PLAZA LAS AMÉRICAS'], [$b->monto, $b->justificacion, $b->vehiculo->tipo, $b->paradero->nombre]);
        // El destino nuevo se agregó al catálogo de paraderos de la sede
        $this->assertTrue($this->enEmpresa(fn () => Paradero::where('sede_id', $this->centro->id)->where('nombre', 'PLAZA LAS AMÉRICAS')->exists()));

        // Firmas en el disco privado, nunca en public/
        foreach ([$a->firma_guardia, $a->firma_taxista, $b->firma_taxista] as $ruta) {
            $this->assertStringStartsWith("firmas/{$this->empresa->id}/transporte/", $ruta);
            Storage::disk('local')->assertExists($ruta);
        }
        $this->assertSame($a->firma_guardia, $b->firma_guardia);
        $this->assertNotSame($a->firma_taxista, $b->firma_taxista);
        $this->assertFileDoesNotExist(public_path($a->firma_taxista));

        Mail::assertSent(ValeTaxiRegistrado::class, 2);
        Mail::assertSent(ValeTaxiRegistrado::class, fn ($m) => $m->hasTo('finanzas@ejemplo.com') && $m->folio === $a->folio());

        $this->actingAs($this->admin)->get('/transporte')->assertSee('NO LLEGO (USO DE TAXIS)')->assertSee('Pago: $320.00')
            ->assertSee('Imprimir Planilla')->assertSee('Justificación de costo:')->assertSee('Pendiente de Vo.Bo.')
            ->assertSee('Roberto Hernández [1005]')->assertSee('Imprime los vales de caja chica');
    }

    public function test_sin_lista_de_correos_el_vale_va_a_quien_puede_autorizar_y_se_puede_apagar(): void
    {
        Mail::fake();
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
        $director = $this->crearUsuario($this->empresa, 'Director');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]), $agente);
        Mail::assertSent(ValeTaxiRegistrado::class, fn ($m) => $m->hasTo($director->email) && ! $m->hasTo($agente->email));

        // Configuración → Avisos: lista de correos y apagado
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => ['alta_provisional' => 1], 'destinatarios' => ['vale_taxi' => 'caja@ejemplo.com, x']])
            ->assertSessionHasErrors(['destinatarios.vale_taxi' => 'Revisa estos correos: x.']);
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => ['vale_taxi' => 1], 'destinatarios' => ['vale_taxi' => "Caja@Ejemplo.com\nfinanzas@ejemplo.com"]])
            ->assertSessionHas('ok');
        $this->assertSame(['caja@ejemplo.com', 'finanzas@ejemplo.com'], $this->empresa->fresh()->destinatariosAviso('vale_taxi'));
        $this->actingAs($this->admin)->get('/configuracion')->assertSee('Correos que lo reciben')->assertSee('caja@ejemplo.com');

        $this->actingAs($this->admin)->put('/configuracion/avisos', [])->assertSessionHas('ok');
        $this->assertFalse($this->empresa->fresh()->aviso('vale_taxi'));
        Mail::fake();
        $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]), $agente);
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------ Validación

    public function test_validaciones_del_alta_con_mensajes_claros_y_sin_guardar_nada(): void
    {
        $this->actingAs($this->admin)->post('/transporte', ['_dialogo' => 'crear'])->assertSessionHasErrors([
            'sede_id' => 'Elige la sede donde ocurre el movimiento.',
            'tipo_movimiento' => 'Elige si es una LLEGADA o una SALIDA.',
            'estatus' => 'Elige el estatus del servicio: A tiempo, Con retraso o No llegó (uso de taxis).',
            'ruta_horario_id' => 'Elige la ruta programada.',
        ]);
        // Ruta de otra sede, de otro sentido o suspendida
        foreach ([$this->llegadaPlaya->id, $this->salida->id] as $horario) {
            $this->actingAs($this->admin)->post('/transporte', $this->normal(['ruta_horario_id' => $horario]))
                ->assertSessionHasErrors(['ruta_horario_id' => 'La ruta elegida no es de esta sede, no es de llegada o está suspendida. Elige otra de la lista.']);
        }
        $this->enEmpresa(fn () => Ruta::whereKey($this->llegada->ruta_id)->update(['activo' => false]));
        $this->actingAs($this->admin)->post('/transporte', $this->normal())->assertSessionHasErrors('ruta_horario_id');
        $this->enEmpresa(fn () => Ruta::whereKey($this->llegada->ruta_id)->update(['activo' => true]));

        $this->actingAs($this->admin)->post('/transporte', $this->normal(['placas' => '##', 'cantidad_pax' => '-3', 'capacidad' => '500', 'chofer_telefono' => '123']))
            ->assertSessionHasErrors([
                'placas' => 'Revisa las placas: solo letras y números, de 2 a 20 (los espacios y guiones se quitan solos).',
                'cantidad_pax' => 'Los pasajeros deben ser un número entero de 0 a 999.',
                'capacidad' => 'La capacidad debe ser de 1 a 99 personas.',
                'chofer_telefono' => 'El teléfono del chofer debe tener de 10 a 15 dígitos.',
            ]);

        // Taxis: al menos uno; cada uno con placas, chofer, monto > 0, destino, pasajeros y firma; tope de la ruta
        $this->actingAs($this->admin)->post('/transporte', $this->taxis([], []))
            ->assertSessionHasErrors(['taxis' => 'Debe añadir al menos una unidad de taxi para documentar el fallo.']);
        $this->actingAs($this->admin)->post('/transporte', $this->taxis(['firma_guardia' => ''], [
            ['placas' => 'TX1', 'chofer' => 'Uno', 'monto' => '300', 'destino' => 'Centro', 'pasajeros' => [$this->colabs[0]->id], 'firma' => $this->firma()],
            ['placas' => '', 'chofer' => '', 'monto' => '0', 'destino' => '', 'pasajeros' => [$this->colabs[0]->id, 999999]],
        ]))->assertSessionHasErrors([
            'firma_guardia' => 'Falta la firma del guardia: firma en el recuadro antes de guardar.',
            'taxis.0.justificacion' => 'Taxi 1: el monto ($300.00) supera el tope de la ruta ($250.00). Escribe la justificación.',
            'taxis.1.placas' => 'Taxi 2: faltan las placas.',
            'taxis.1.chofer' => 'Taxi 2: falta el nombre del conductor.',
            'taxis.1.monto' => 'Taxi 2: el monto del vale debe ser mayor a cero.',
            'taxis.1.destino' => 'Taxi 2: falta el destino (paradero).',
            'taxis.1.pasajeros' => 'Taxi 2: «Roberto Hernández» ya va en el Taxi 1.',
            'taxis.1.firma' => 'Taxi 2: falta la firma del taxista: que firme en el recuadro.',
        ]);

        // Sin pasajeros válidos (de otra empresa o dados de baja)
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1', 'nombre' => 'Ajeno', 'apellido_paterno' => 'X']), $otra);
        $this->enEmpresa(fn () => $this->colabs[2]->forceFill(['activo' => false])->save());
        $this->actingAs($this->admin)->post('/transporte', $this->taxis([], [['placas' => 'TX1', 'chofer' => 'Uno', 'monto' => '100', 'destino' => 'Centro',
            'pasajeros' => [$ajeno->id, $this->colabs[2]->id], 'firma' => $this->firma()]]))
            ->assertSessionHasErrors(['taxis.0.pasajeros' => 'Taxi 1: agrega al menos un colaborador transportado (escanea su gafete o escribe su número de empleado).']);

        $this->assertSame(0, $this->contar());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $this->enEmpresa(fn () => Vehiculo::count()));
    }

    public function test_el_error_reabre_el_dialogo_con_los_taxis_y_pasajeros_capturados(): void
    {
        $this->actingAs($this->admin)->from('/transporte')->post('/transporte', $this->taxis([], [
            ['placas' => 'TX-777', 'chofer' => 'Uno', 'monto' => '', 'destino' => 'Centro', 'pasajeros' => [$this->colabs[1]->id]],
        ]))->assertRedirect('/transporte');

        $this->actingAs($this->admin)->get('/transporte')
            ->assertSee('id="dialogoAltaTransporte"', false)->assertSee('data-abrir-al-cargar', false)
            ->assertSee('value="TX-777"', false)->assertSee('Mariana López · Núm. 1007')
            ->assertSee('name="taxis[0][pasajeros][]" value="'.$this->colabs[1]->id.'"', false);
    }

    public function test_sin_permiso_de_firmar_no_se_piden_firmas_en_pantalla(): void
    {
        $sinFirma = $this->usuarioCon(['transporte.ver' => Alcance::Empresa, 'transporte.crear' => Alcance::Empresa, 'colaboradores.ver' => Alcance::Empresa]);
        $this->actingAs($sinFirma)->get('/transporte')->assertOk()->assertDontSee('name="firma_guardia"', false)
            ->assertSee('El vale impreso trae los espacios para firmar a mano');

        $m = $this->registrar($this->taxis(['firma_guardia' => ''], [['placas' => 'TX1', 'chofer' => 'Uno', 'monto' => '100', 'destino' => 'Centro', 'pasajeros' => [$this->colabs[0]->id]]]), $sinFirma);
        $this->assertNull($m->firma_guardia);
        $this->assertNull($m->firma_taxista);
    }

    // -------------------------------------------------- Aislamiento y alcance

    public function test_aislamiento_entre_empresas(): void
    {
        $m = $this->registrar($this->taxis());
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->crearUsuario($otra, 'Administrador');

        $this->actingAs($ajeno)->get('/transporte?fecha_inicio=2026-10-01&fecha_fin=2026-10-31')->assertOk()->assertDontSee('TX230')->assertDontSee('RUTA 1 - REGIÓN 94');
        foreach (["/transporte/{$m->id}", "/transporte/{$m->id}/vale", "/transporte/{$m->id}/firma/guardia"] as $url) {
            $this->actingAs($ajeno)->get($url)->assertNotFound();
        }
        $this->actingAs($ajeno)->put("/transporte/{$m->id}", ['monto' => 1])->assertNotFound();
        $this->actingAs($ajeno)->patch("/transporte/{$m->id}/anular")->assertNotFound();
        $this->actingAs($ajeno)->patch("/transporte/{$m->id}/autorizar")->assertNotFound();
        // Ni con una ruta ajena
        $this->actingAs($ajeno)->post('/transporte', $this->normal())->assertSessionHasErrors(['sede_id' => 'Elige la sede donde ocurre el movimiento.']);
        $this->assertFalse($this->enEmpresa(fn () => MovimientoTransporte::find($m->id)->anulado));
    }

    public function test_alcance_de_sede_y_el_agente_registra_y_firma_pero_no_anula_autoriza_ni_exporta(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->assertTrue($agente->can('transporte.crear'));
        $this->assertTrue($agente->can('transporte.firmar'));
        $playa = $this->registrar($this->normal(['sede_id' => $this->playa->id, 'ruta_horario_id' => $this->llegadaPlaya->id, 'placas' => 'PLA-1']));

        // Solo su sede: no ve ni registra en Playa
        $this->actingAs($agente)->get('/transporte')->assertOk()->assertDontSee('RUTA P - BONFIL')->assertDontSee('Sede PLA')
            ->assertSee('Sede: <strong>Sede CEN</strong>', false)->assertDontSee('Anular registro');
        $this->actingAs($agente)->get("/transporte/{$playa->id}")->assertNotFound();
        $this->actingAs($agente)->post('/transporte', $this->normal(['sede_id' => $this->playa->id, 'ruta_horario_id' => $this->llegadaPlaya->id]))
            ->assertSessionHasErrors(['sede_id' => 'Elige la sede donde ocurre el movimiento.']);

        // Registra con taxis y firma en su sede
        $vale = $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]), $agente);
        $this->assertSame($agente->id, $vale->creado_por);
        $this->actingAs($agente)->get("/transporte/{$vale->id}")->assertOk()->assertSee('Editar registro')->assertDontSee('Autorizar (Vo.Bo.)');
        $this->actingAs($agente)->get("/transporte/{$vale->id}/firma/taxista")->assertOk();

        // Sin eliminar, aprobar ni exportar
        $this->actingAs($agente)->patch("/transporte/{$vale->id}/anular")->assertForbidden();
        $this->actingAs($agente)->patch("/transporte/{$vale->id}/autorizar")->assertForbidden();
        $this->actingAs($agente)->get('/transporte/exportar')->assertForbidden();
        $this->actingAs($agente)->get('/transporte')->assertDontSee('Exportar Excel');

        // Un usuario con "ver" en Centro y "editar" solo en Playa: ve, pero no edita (403)
        $mixto = $this->usuarioCon(['transporte.ver' => Alcance::Sede, 'transporte.editar' => Alcance::Sede], $this->centro);
        UsuarioRol::where('user_id', $mixto->id)->update(['sede_id' => $this->centro->id]);
        $sinEditar = $this->usuarioCon(['transporte.ver' => Alcance::Sede]);
        $this->actingAs($sinEditar)->put("/transporte/{$vale->id}", ['monto' => 1])->assertForbidden();
    }

    public function test_alcance_propios_solo_ve_lo_que_registro(): void
    {
        $propio = $this->usuarioCon(['transporte.ver' => Alcance::Propios, 'transporte.crear' => Alcance::Propios, 'transporte.editar' => Alcance::Propios, 'colaboradores.ver' => Alcance::Empresa]);
        $deOtro = $this->registrar($this->normal(['placas' => 'OTRO-1']));
        $mio = $this->registrar($this->normal(['placas' => 'MIO-1']), $propio);

        $this->actingAs($propio)->get('/transporte')->assertSee('(MIO1')->assertDontSee('(OTRO1')
            // Sin permiso del Padrón vehicular no se sugieren sus placas
            ->assertDontSee('<option value="OTRO1">', false);
        $this->actingAs($propio)->get("/transporte/{$deOtro->id}")->assertNotFound();
        $this->actingAs($propio)->put("/transporte/{$mio->id}", ['cantidad_pax' => 3, 'estatus' => 'a_tiempo'])->assertSessionHasNoErrors();
    }

    public function test_superadmin_trabaja_con_la_empresa_elegida(): void
    {
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/transporte')->assertOk()->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->post('/transporte', $this->normal())->assertSessionHas('ok');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/transporte')->assertSee('TKH101');
    }

    // ------------------------------------------------- Estados y transiciones

    public function test_anular_y_reactivar_con_sus_transiciones_prohibidas(): void
    {
        $m = $this->registrar($this->normal());

        $this->actingAs($this->admin)->patch("/transporte/{$m->id}/reactivar")
            ->assertSessionHasErrors(['estado' => 'Este registro no está anulado: no hay nada que reactivar.']);
        $this->actingAs($this->admin)->from("/transporte/{$m->id}")->patch("/transporte/{$m->id}/anular")
            ->assertRedirect("/transporte/{$m->id}#movimiento-{$m->id}")->assertSessionHas('aviso');
        $m->refresh();
        $this->assertTrue($m->anulado);
        $this->assertSame($this->admin->id, $m->anulado_por);
        $this->actingAs($this->admin)->patch("/transporte/{$m->id}/anular")->assertSessionHasErrors(['estado' => 'Este registro ya estaba anulado.']);
        $this->actingAs($this->admin)->put("/transporte/{$m->id}", ['cantidad_pax' => 5])
            ->assertSessionHasErrors(['estado' => 'Este registro está anulado — no se puede editar.']);
        $this->actingAs($this->admin)->get("/transporte/{$m->id}")->assertSee('ANULADO por '.$this->admin->name);

        $this->actingAs($this->admin)->patch("/transporte/{$m->id}/reactivar")->assertSessionHas('ok');
        $m->refresh();
        $this->assertFalse($m->anulado);
        $this->assertNull($m->anulado_por);
        $this->assertSame(['transporte.creado', 'transporte.anulado', 'transporte.reactivado'],
            Auditoria::where('auditable_type', MovimientoTransporte::class)->where('auditable_id', $m->id)->orderBy('id')->pluck('evento')->all());
    }

    public function test_vobo_del_vale_y_sus_transiciones_prohibidas(): void
    {
        $normal = $this->registrar($this->normal());
        $this->actingAs($this->admin)->patch("/transporte/{$normal->id}/autorizar")
            ->assertSessionHasErrors(['estado' => 'Solo los vales de taxi llevan Vo.Bo. de autorización.']);

        $vale = $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]));
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->patch("/transporte/{$vale->id}/autorizar")->assertSessionHas('ok');
        $vale->refresh();
        $this->assertSame($director->id, $vale->autorizado_por);
        $this->assertNotNull($vale->autorizado_en);
        $this->actingAs($director)->patch("/transporte/{$vale->id}/autorizar")->assertSessionHasErrors(['estado' => 'Este vale ya estaba autorizado.']);
        $this->actingAs($this->admin)->put("/transporte/{$vale->id}", ['monto' => '50', 'destino' => 'X', 'pasajeros' => [$this->colabs[0]->id]])
            ->assertSessionHasErrors(['estado' => 'Este vale ya tiene el Vo.Bo. de autorización: ya no se puede editar.']);
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}")->assertSee('Vo.Bo.: '.$director->name)->assertDontSee('Editar registro');
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/vale")->assertSee('Vo.Bo. electrónico');

        // Un vale anulado no se autoriza
        $otro = $this->registrar($this->taxis([], [$this->taxis()['taxis'][1]]));
        $this->actingAs($this->admin)->patch("/transporte/{$otro->id}/anular");
        $this->actingAs($director)->patch("/transporte/{$otro->id}/autorizar")->assertSessionHasErrors(['estado' => 'Este vale está anulado: no se puede autorizar.']);
        $this->assertTrue(Auditoria::where('evento', 'transporte.autorizado')->where('auditable_id', $vale->id)->exists());
    }

    // ---------------------------------------------------------------- Edición

    public function test_editar_servicio_normal_y_vale_de_taxi(): void
    {
        $m = $this->registrar($this->normal());
        $this->actingAs($this->admin)->put("/transporte/{$m->id}", ['_dialogo' => 'editar-'.$m->id, 'cantidad_pax' => '22', 'estatus' => 'retraso', 'observaciones' => 'Corregido'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
        $m->refresh();
        $this->assertSame([22, 'retraso', 'Corregido', $this->admin->id], [$m->cantidad_pax, $m->estatus, $m->observaciones, $m->editado_por]);
        // Un servicio normal no pasa a "no llegó" desde la edición
        $this->actingAs($this->admin)->put("/transporte/{$m->id}", ['cantidad_pax' => '1', 'estatus' => 'no_llego'])
            ->assertSessionHasErrors(['estatus' => 'En un servicio normal solo se puede corregir entre A TIEMPO y RETRASO. Si la unidad no llegó, anula este registro y registra los taxis.']);

        $vale = $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]));
        $this->actingAs($this->admin)->put("/transporte/{$vale->id}", ['monto' => '300', 'destino' => 'Nuevo destino', 'pasajeros' => [$this->colabs[2]->id]])
            ->assertSessionHasErrors(['justificacion' => 'El costo ($300.00) supera el tope de la ruta ($250.00). Debes anotar la justificación.']);
        $this->actingAs($this->admin)->put("/transporte/{$vale->id}", ['monto' => '0', 'destino' => '', 'pasajeros' => []])
            ->assertSessionHasErrors(['monto' => 'El costo del servicio debe ser mayor a cero.', 'destino' => 'Falta el destino (paradero).', 'pasajeros' => 'Debe quedar al menos un colaborador registrado.']);
        $this->actingAs($this->admin)->put("/transporte/{$vale->id}", ['monto' => '300', 'justificacion' => 'Tráfico.', 'destino' => 'nuevo destino', 'pasajeros' => [$this->colabs[2]->id, $this->colabs[0]->id]])
            ->assertSessionHasNoErrors();
        $vale = $this->enEmpresa(fn () => MovimientoTransporte::with('pasajeros', 'paradero')->find($vale->id));
        $this->assertSame(['300.00', 'Tráfico.', 'NUEVO DESTINO', 2], [$vale->monto, $vale->justificacion, $vale->paradero->nombre, $vale->cantidad_pax]);
        $this->assertTrue(Auditoria::where('evento', 'transporte.actualizado')->where('auditable_id', $vale->id)->exists());
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}")->assertSee('Editado por '.$this->admin->name);
    }

    public function test_la_union_de_colaboradores_duplicados_mueve_los_pasajeros(): void
    {
        $this->assertSame('colaborador_id', AdministradorColaboradores::REFERENCIAS['movimiento_transporte_pasajeros']);
        $vale = $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]));
        $provisional = $this->colabs[0];
        $this->enEmpresa(fn () => $provisional->forceFill(['provisional' => true])->save());
        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->fusionar($this->admin, $provisional->fresh(), $this->colabs[2]->fresh()));
        $this->assertSame([$this->colabs[1]->id, $this->colabs[2]->id], $this->enEmpresa(fn () => $vale->pasajeros()->pluck('colaboradores.id')->sort()->values()->all()));
    }

    // ----------------------------------------------------- Firmas e impresos

    public function test_firmas_solo_con_permiso_y_vale_de_caja_chica(): void
    {
        $vale = $this->registrar($this->taxis([], [$this->taxis()['taxis'][0]]));
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/firma/taxista")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/firma/guardia")->assertOk();
        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/firma/otra")->assertNotFound();

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->get("/transporte/{$vale->id}/firma/taxista")->assertForbidden();
        $dePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($dePlaya)->get("/transporte/{$vale->id}/firma/taxista")->assertNotFound();

        $this->actingAs($this->admin)->get("/transporte/{$vale->id}/vale")->assertOk()
            ->assertSee('VALE DE CAJA CHICA - TAXI DE OPERACIÓN')->assertSee('1. COPIA PARA CONTABILIDAD / CAJA CHICA')
            ->assertSee('2. COPIA PARA CONTROL INTERNO DE CASETA')->assertSee('3. COPIA PARA EL OPERADOR DE TAXI')
            ->assertSee($vale->folio())->assertSee('$180.00 MXN')->assertSee('Sede Origen:')->assertSee('COLABORADORES ABORDADOS (2):')
            ->assertSee('Vo.Bo Autorización')->assertSee(route('transporte.firma', [$vale->id, 'taxista']))
            ->assertDontSee('Hotel Origen');

        $normal = $this->registrar($this->normal());
        $this->actingAs($this->admin)->get("/transporte/{$normal->id}/vale")->assertNotFound();
        $this->actingAs($this->admin)->get("/transporte/{$normal->id}/firma/guardia")->assertNotFound(); // sin firma
    }

    // --------------------------------------------- Filtros, reportes y CSV

    public function test_filtros_por_dia_local_de_la_sede_y_texto(): void
    {
        // 23:30 del lunes en la sede = 05:30 UTC del martes: cuenta como lunes
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06 05:30:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-10-06 05:30:00', 'UTC'));
        $noche = $this->registrar($this->normal(['tipo_movimiento' => 'salida', 'ruta_horario_id' => $this->salida->id, 'placas' => 'NOCHE-1']));
        $this->assertSame('2026-10-05', $noche->fecha->toDateString());

        $this->actingAs($this->admin)->get('/transporte?fecha_inicio=2026-10-05&fecha_fin=2026-10-05')->assertSee('(NOCHE1');
        $this->actingAs($this->admin)->get('/transporte?fecha_inicio=2026-10-06&fecha_fin=2026-10-06')->assertDontSee('(NOCHE1');
        $this->actingAs($this->admin)->get('/transporte?fecha_inicio=2026-10-05&fecha_fin=2026-10-05&q=noche')->assertSee('(NOCHE1');
        $this->actingAs($this->admin)->get('/transporte?fecha_inicio=2026-10-05&fecha_fin=2026-10-05&q=zzz')->assertDontSee('(NOCHE1');
        $this->actingAs($this->admin)->get('/transporte?fecha_inicio=2026-10-05&fecha_fin=2026-10-05&estatus=no_llego')->assertDontSee('(NOCHE1');
        $this->actingAs($this->admin)->get('/transporte?fecha_inicio=2026-10-05&fecha_fin=2026-10-05&tipo=llegada')->assertDontSee('(NOCHE1');
    }

    public function test_reportes_con_resumen_sin_anulados_y_csv_con_los_mismos_filtros(): void
    {
        $this->registrar($this->normal());
        $vales = $this->actingAs($this->admin)->post('/transporte', $this->taxis());
        $anulado = $this->enEmpresa(fn () => MovimientoTransporte::where('monto', 320)->first());
        $this->actingAs($this->admin)->patch("/transporte/{$anulado->id}/anular");
        $otro = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Shuttle Riviera', 'categoria' => 'transporte_personal', 'activo' => true, 'todas_las_sedes' => true]));

        $this->actingAs($this->admin)->get('/transporte/reportes')->assertOk()
            ->assertSee('Reportes de Transporte')->assertSee('Filtros avanzados y exportación de la operación.')
            ->assertSee('Incidencias (Taxis)')->assertSee('$180.00')->assertDontSee('$500.00')->assertSee('· ANULADO')
            ->assertSee('Exportar (mismos filtros)');
        $this->actingAs($this->admin)->get('/transporte/reportes?proveedor='.$otro->id)->assertSee('No hay movimientos con estos filtros.');
        $this->actingAs($this->admin)->get('/transporte/reportes?estatus=a_tiempo')->assertSee('TKH101')->assertDontSee('TX230');

        $resumen = $this->enEmpresa(fn () => app(BitacoraTransporte::class)->resumen(MovimientoTransporte::query()));
        $this->assertSame(['total' => 2, 'a_tiempo' => 1, 'retraso' => 0, 'taxis' => 1, 'gasto' => 180.0, 'pax' => 20, 'anulados' => 1], $resumen);

        $csv = $this->actingAs($this->admin)->get('/transporte/exportar?fecha_inicio=2026-10-05&fecha_fin=2026-10-05&estatus=no_llego');
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $contenido = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString('Monto taxi ($)', $contenido);
        $this->assertStringContainsString('320.00', $contenido);
        $this->assertStringContainsString('Roberto Hernández [1005]', $contenido);
        $this->assertStringNotContainsString('TKH101', $contenido);
    }

    public function test_datos_demo_una_semana_con_retrasos_y_dos_fallas_con_taxis(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful(); // la segunda vez no duplica

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $todos = $this->enEmpresa(fn () => MovimientoTransporte::with('pasajeros')->get(), $demo);
        $this->assertGreaterThan(30, $todos->count());
        $this->assertGreaterThanOrEqual(6, $todos->pluck('fecha')->map->toDateString()->unique()->count());
        $this->assertTrue($todos->contains('estatus', 'retraso'));
        $vales = $todos->where('estatus', 'no_llego');
        $this->assertCount(3, $vales);
        $this->assertCount(2, $vales->pluck('lote')->unique());
        $this->assertCount(1, $vales->whereNotNull('justificacion'));
        $this->assertCount(1, $vales->whereNotNull('autorizado_en'));
        $this->assertTrue($vales->every(fn ($v) => $v->pasajeros->isNotEmpty()));
    }

    public function test_reportes_paginan_de_25_en_25(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->enEmpresa(fn () => MovimientoTransporte::create(['sede_id' => $this->centro->id, 'ruta_id' => $this->llegada->ruta_id, 'ruta_horario_id' => $this->llegada->id,
                'tipo_movimiento' => 'llegada', 'estatus' => 'a_tiempo', 'fecha' => '2026-10-05', 'cantidad_pax' => 1]));
        }
        $this->actingAs($this->admin)->get('/transporte/reportes')->assertSee('27 registros en total, página 1 de 2.');
    }
}
