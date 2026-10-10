<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\EnlaceKiosco;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Candidatos\Kiosco;
use App\Services\Candidatos\Postulaciones;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Solicitud de empleo formal (lección 36): datos personales oficiales,
 * domicilio, referencias, declaración y firma, hoja impresa, datos sensibles
 * protegidos y «Contratar» que copia los datos al colaborador.
 */
class SolicitudEmpleoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $rh;

    private User $agente;

    private Departamento $cocina;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->cocina = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Cocina']));
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function candidato(string $etapa = 'registrado', array $datos = []): Candidato
    {
        return $this->enEmpresa(function () use ($etapa, $datos) {
            $c = new Candidato($datos + ['sede_id' => $this->centro->id, 'nombre_completo' => 'Luis Chi Canul', 'departamento_id' => $this->cocina->id,
                'origen' => 'rh', 'llegada_en' => now()]);
            $c->forceFill(['etapa' => $etapa, 'privacidad_aceptada_en' => now()])->save();

            return $c;
        });
    }

    private function firma(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 40, 150, 560, 40, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    /** @return array<string, mixed> */
    private function solicitud(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'ana maría', 'apellido_paterno' => 'pool', 'apellido_materno' => 'canché', 'telefono' => '998 123 4567', 'correo' => 'ana@correo.mx',
            'sexo' => 'mujer', 'fecha_nacimiento' => '1996-04-10', 'lugar_nacimiento' => 'Yucatán', 'nacionalidad' => 'Mexicana', 'estado_civil' => 'soltero',
            'dependientes' => '1', 'curp' => 'poca960410mynlnn05', 'rfc' => 'POCA960410AB1', 'nss' => '1234-5678-901',
            'licencia_tipo' => 'automovilista', 'licencia_vigencia' => '2028-01-31',
            'calle_numero' => 'Calle 10 Mz 2 Lt 3', 'colonia' => 'Región 100', 'codigo_postal' => '77500', 'municipio' => 'Benito Juárez',
            'estado_domicilio' => 'Quintana Roo', 'tiempo_residencia' => '3 años', 'telefono_fijo' => '9988801122',
            'emergencia_nombre' => 'Rosa Canché', 'emergencia_parentesco' => 'Mamá', 'emergencia_telefono' => '9981110000',
            'escolaridad' => [['nivel' => 'licenciatura', 'institucion' => 'UT Cancún', 'periodo' => '2014 a 2018', 'documento' => 'titulo', 'titulo' => 'Gastronomía']],
            'experiencia' => [['empresa' => 'Hotel Sol', 'puesto' => 'Cocinera', 'ingreso' => '2019-01', 'salida' => '2022-07', 'sueldo_final' => '$9,500',
                'jefe' => 'Pedro Can', 'jefe_telefono' => '9987776655', 'motivo_salida' => 'Cambio de ciudad', 'pedir_referencias' => 'si']],
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233', 'relacion' => 'Vecina', 'anos_conocerlo' => '5'],
                ['nombre' => 'Rosa Uc', 'telefono' => '9984445566', 'relacion' => 'Maestra', 'anos_conocerlo' => '10']],
            'referencias_laborales' => [['nombre' => 'Pedro Can', 'telefono' => '9987776655', 'relacion' => 'Jefe de cocina', 'anos_conocerlo' => '3']],
            'medio_vacante' => 'cartel', 'tiene_familiares' => '1', 'familiares_nombre' => 'Juan Pool, Mantenimiento', 'trabajo_antes_aqui' => '0',
            'rolar_turnos' => '1', 'puede_viajar' => '0', 'cambiar_residencia' => '0', 'fecha_inicio_posible' => now()->addWeek()->format('Y-m-d'),
            'disponibilidad' => 'una_semana', 'pretension' => '10,000', 'departamento_id' => $this->cocina->id,
        ];
    }

    private function kiosco(Candidato $c): string
    {
        return (string) parse_url($this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $c))['url'], PHP_URL_PATH);
    }

    // ------------------------------------------------------------------ Captura

    public function test_rh_captura_la_solicitud_completa_y_se_normaliza(): void
    {
        $c = $this->candidato();
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud())->assertSessionHas('ok');
        $c->refresh();

        $this->assertSame('Ana María Pool Canché', $c->nombre_completo, 'El nombre completo se arma con nombre y apellidos');
        $this->assertSame(['Ana María', 'Pool', 'Canché'], [$c->nombre, $c->apellido_paterno, $c->apellido_materno]);
        $this->assertSame('POCA960410MYNLNN05', $c->curp);
        $this->assertSame('12345678901', $c->nss);
        $this->assertSame('9981234567', $c->telefono);
        $this->assertSame('Benito Juárez', $c->ciudad, 'La ciudad se toma del municipio');
        $this->assertSame('Calle 10 Mz 2 Lt 3, Col. Región 100, C.P. 77500, Benito Juárez, Quintana Roo', $c->domicilioCompleto());
        $this->assertSame(3, $c->experiencia[0]['anos'], 'Los años se calculan con las fechas');
        $this->assertSame(9500.0, (float) $c->experiencia[0]['sueldo_final']);
        $this->assertSame('titulo', $c->escolaridad[0]['documento']);
        $this->assertTrue($c->escolaridad[0]['concluido']);
        $this->assertCount(2, $c->referencias);
        $this->assertCount(1, $c->referencias_laborales);
        $this->assertTrue($c->tiene_familiares);
        $this->assertFalse($c->puede_viajar);
        $this->assertSame('cartel', $c->medio_vacante);

        // La ficha muestra todo a RR. HH. (sección «Solo RR. HH.»)
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('POCA960410MYNLNN05')->assertSee('Región 100')
            ->assertSee('Rosa Canché')->assertSee('Juan Pool, Mantenimiento')->assertSee('Imprimir');

        // Si contesta «No» a familiares, el nombre no se guarda
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['tiene_familiares' => '0']))->assertSessionHas('ok');
        $this->assertNull($c->fresh()->familiares_nombre);
    }

    public function test_validaciones_de_curp_rfc_nss_cp_y_fechas_en_espanol(): void
    {
        $c = $this->candidato();
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['curp' => 'ABC123', 'rfc' => 'XX', 'nss' => '123', 'codigo_postal' => '7750',
            'telefono_fijo' => '123', 'emergencia_telefono' => '55', 'estado_civil' => 'otro', 'lugar_nacimiento' => 'Marte', '_dialogo' => 'cv']))
            ->assertSessionHasErrors([
                'curp' => 'El CURP no tiene el formato correcto: son 18 letras y números (ej. GOMA850101HQRRRN09).',
                'rfc' => 'El RFC no tiene el formato correcto: son 12 o 13 letras y números (ej. GOMA850101AB1).',
                'nss' => 'El NSS (número de seguro social) lleva exactamente 11 números.',
                'codigo_postal' => 'El código postal lleva exactamente 5 números.',
                'telefono_fijo', 'emergencia_telefono', 'estado_civil', 'lugar_nacimiento',
            ]);
        $this->assertNull($c->fresh()->curp);

        // Salida antes del ingreso y mes inválido
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['experiencia' => [['empresa' => 'Hotel', 'ingreso' => '2022-05', 'salida' => '2021-01']]]))
            ->assertSessionHasErrors(['experiencia' => 'En empleos anteriores, el mes de salida no puede ser antes del de ingreso.']);
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['experiencia' => [['empresa' => 'Hotel', 'ingreso' => '2022-13']]]))
            ->assertSessionHasErrors('experiencia.0.ingreso');
        // Nombre sin apellido paterno
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['apellido_paterno' => '']))
            ->assertSessionHasErrors(['apellido_paterno' => 'Escribe el apellido paterno.']);

        // Los errores se muestran dentro del diálogo «Editar solicitud»
        $this->actingAs($this->rh)->from("/candidatos/{$c->id}")->put("/candidatos/{$c->id}", $this->solicitud(['curp' => 'MAL', '_dialogo' => 'cv']))
            ->assertRedirect("/candidatos/{$c->id}");
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('data-abrir-al-cargar', false)->assertSee('El CURP no tiene el formato correcto');
    }

    // ------------------------------------------------------------------ Kiosco: declaración y firma

    public function test_en_el_kiosco_la_declaracion_la_firma_y_dos_referencias_son_obligatorias(): void
    {
        $c = $this->candidato();
        $url = $this->kiosco($c);
        auth()->logout();

        $this->post($url, $this->solicitud(['acepta_privacidad' => '1']))->assertSessionHasErrors(['declaracion', 'firma']);
        $this->post($url, $this->solicitud(['acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firma(),
            'referencias' => [['nombre' => 'Solo Una', 'telefono' => '9981112233']]]))
            ->assertSessionHasErrors(['referencias' => 'Escribe al menos 2 referencias personales (nombre y teléfono).']);
        $this->post($url, $this->solicitud(['acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => 'data:image/png;base64,AAAA']))
            ->assertSessionHasErrors('firma');
        $this->assertNull($c->fresh()->firma_ruta);
        $this->assertSame(0, $this->enEmpresa(fn () => EnlaceKiosco::sole()->usos), 'Un envío con errores no gasta el enlace');

        $this->post($url, $this->solicitud(['acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firma()]))->assertSessionHas('kiosco_enviado');
        $c->refresh();
        $this->assertSame('kiosco', $c->firma_medio);
        $this->assertNull($c->firma_capturada_por);
        $this->assertNotNull($c->declaracion_aceptada_en);
        $this->assertTrue($c->solicitudFirmada());
        // Firma en el disco PRIVADO, nunca en public
        $this->assertStringStartsWith('firmas/'.$this->empresa->id.'/candidatos/', $c->firma_ruta);
        Storage::disk('local')->assertExists($c->firma_ruta);
        $this->assertFileDoesNotExist(public_path($c->firma_ruta));
    }

    public function test_la_firma_solo_se_ve_con_permiso_y_dentro_de_la_empresa(): void
    {
        $c = $this->candidato();
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['firma' => $this->firma(), 'declaracion' => '1']))->assertSessionHas('ok');
        $c->refresh();
        $this->assertSame('rh', $c->firma_medio);
        $this->assertSame($this->rh->id, $c->firma_capturada_por, 'Queda quién de RR. HH. capturó la firma');

        $this->actingAs($this->rh)->get("/candidatos/{$c->id}/firma")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->agente)->get("/candidatos/{$c->id}/firma")->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->get("/candidatos/{$c->id}/firma")->assertForbidden();
        $ajeno = $this->crearUsuario($this->crearEmpresa('Otro Hotel'), 'Administrador');
        $this->actingAs($ajeno)->get("/candidatos/{$c->id}/firma")->assertNotFound();
        $this->get('/storage/'.$c->firma_ruta)->assertNotFound();

        // RR. HH.: firma sin declaración no se guarda
        $otro = $this->candidato('registrado', ['nombre_completo' => 'Otra Persona']);
        $this->actingAs($this->rh)->put("/candidatos/{$otro->id}", $this->solicitud(['firma' => $this->firma()]))
            ->assertSessionHasErrors(['declaracion' => 'Para guardar la firma, marca «Declaro que la información es verdadera».']);

        // Eliminar la ficha borra la firma
        $ruta = $c->firma_ruta;
        $this->actingAs($this->rh)->delete("/candidatos/{$c->id}")->assertRedirect('/candidatos');
        Storage::disk('local')->assertMissing($ruta);
    }

    // ------------------------------------------------------------------ Hoja impresa

    public function test_hoja_impresa_de_la_solicitud(): void
    {
        $c = $this->candidato();
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud(['firma' => $this->firma(), 'declaracion' => '1']));

        $this->actingAs($this->rh)->get("/candidatos/{$c->id}/solicitud")->assertOk()
            ->assertSee('Solicitud de empleo')->assertSee('SOL-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT))->assertSee('Hotel Ébano')
            ->assertSee('POCA960410MYNLNN05')->assertSee('Hotel Sol')->assertSee('Martha Chablé')->assertSee('Pedro Can')
            ->assertSee(route('candidatos.firma', $c->id), false)->assertSee('Declaro que la información de esta solicitud es verdadera')
            ->assertSee('data-accion="imprimir"', false)->assertDontSee('onclick', false);
        $this->actingAs($this->agente)->get("/candidatos/{$c->id}/solicitud")->assertForbidden();
        $ajeno = $this->crearUsuario($this->crearEmpresa('Otro Hotel'), 'Administrador');
        $this->actingAs($ajeno)->get("/candidatos/{$c->id}/solicitud")->assertNotFound();
    }

    // ------------------------------------------------------------------ Datos sensibles

    public function test_los_datos_sensibles_no_salen_en_resumen_avisos_csv_ni_auditoria(): void
    {
        Mail::fake();
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
        $chef = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->cocina->id, 'user_id' => $chef->id]));
        $c = $this->candidato('revision');
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud())->assertSessionHas('ok');
        $sensibles = ['POCA960410MYNLNN05', 'POCA960410AB1', '12345678901', 'Región 100', 'Calle 10 Mz 2', 'Rosa Canché', '9981110000', '9988801122'];

        // Auditoría: CURP/RFC/NSS enmascarados; domicilio y emergencia nunca
        $foto = json_encode(Auditoria::where('evento', 'candidatos.actualizado')->latest('id')->value('despues'));
        foreach ($sensibles as $dato) {
            $this->assertStringNotContainsString($dato, (string) $foto, "La auditoría no guarda {$dato}");
        }
        $this->assertStringContainsString('NN05', (string) $foto, 'Se sabe que cambió (enmascarado)');

        // Resumen al departamento (pantalla, campana y correo)
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'aprobado_rh'])->assertSessionHas('ok');
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $resumen = $this->actingAs($chef)->get("/autorizaciones/{$a->id}")->assertOk();
        $avisos = $this->enEmpresa(fn () => Notificacion::all()->map(fn ($n) => $n->titulo.' '.$n->texto)->join(' '));
        foreach ($sensibles as $dato) {
            $resumen->assertDontSee($dato);
            $this->assertStringNotContainsString($dato, $avisos);
        }
        Mail::assertSent(AvisoRecepcion::class, function ($m) use ($sensibles) {
            $texto = $m->titulo.' '.implode(' ', $m->lineas);
            foreach ($sensibles as $dato) {
                if (str_contains($texto, $dato)) {
                    return false;
                }
            }

            return true;
        });

        // CSV: sin datos personales salvo que se pidan expresamente (columnas marcadas)
        $csv = $this->actingAs($this->rh)->get('/candidatos/exportar')->assertOk()->streamedContent();
        foreach ($sensibles as $dato) {
            $this->assertStringNotContainsString($dato, $csv);
        }
        $completo = $this->actingAs($this->rh)->get('/candidatos/exportar?datos=personales')->assertOk()->streamedContent();
        $this->assertStringContainsString('CURP [DATO PERSONAL]', $completo);
        $this->assertStringContainsString('POCA960410MYNLNN05', $completo);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.exportado_con_datos_personales')->exists());
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->get('/candidatos/exportar?datos=personales')->assertForbidden();
    }

    // ------------------------------------------------------------------ Contratar

    public function test_contratar_copia_los_datos_oficiales_al_colaborador(): void
    {
        $c = $this->candidato();
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", $this->solicitud())->assertSessionHas('ok');
        // La etapa vive en la postulación (la ficha es su espejo)
        $this->enEmpresa(fn () => app(Postulaciones::class)->cambiar(app(Postulaciones::class)->asegurar($c), null, ['etapa' => 'seleccionado'], null));

        // El diálogo propone nombre y apellidos tal como los capturó
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('value="Ana María"', false)->assertSee('value="Pool"', false);
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '3001', 'nombre' => 'Ana María', 'apellido_paterno' => 'Pool',
            'apellido_materno' => 'Canché'])->assertSessionHas('ok');

        $col = $this->enEmpresa(fn () => Colaborador::where('num_empleado', '3001')->sole());
        $this->assertSame('POCA960410MYNLNN05', $col->curp);
        $this->assertSame('POCA960410AB1', $col->rfc);
        $this->assertSame('12345678901', $col->nss);
        $this->assertSame('1996-04-10', $col->fecha_nacimiento?->format('Y-m-d'));
        $this->assertSame('Yucatán', $col->lugar_nacimiento);
        $this->assertSame('Mexicana', $col->nacionalidad);
        $this->assertSame('ana@correo.mx', $col->correo_personal);
        $this->assertSame('9981234567', $col->telefono);
        $this->assertSame('Calle 10 Mz 2 Lt 3, Col. Región 100, C.P. 77500, Benito Juárez, Quintana Roo', $col->direccion_completa);

        // Un CURP que ya tiene otro colaborador se avisa dentro del diálogo
        $otro = $this->candidato('seleccionado', ['nombre_completo' => 'Clon Pool', 'curp' => 'POCA960410MYNLNN05']);
        $this->actingAs($this->rh)->post("/candidatos/{$otro->id}/contratar", ['num_empleado' => '3002', 'nombre' => 'Clon', 'apellido_paterno' => 'Pool', '_dialogo' => 'contratar'])
            ->assertSessionHasErrors(['curp' => 'Ya existe otro colaborador registrado con ese CURP.']);
        $this->assertSame('seleccionado', $otro->fresh()->etapa);
    }

    public function test_los_candidatos_anteriores_siguen_funcionando(): void
    {
        // Solo nombre completo (caseta): la ficha y el diálogo proponen nombre y apellidos
        $c = $this->candidato('registrado', ['nombre_completo' => 'Karla Pérez Uc', 'experiencia' => [['empresa' => 'Hotel Sol', 'anos' => 2]]]);
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('value="Karla"', false)->assertSee('value="Pérez"', false)
            ->assertSee('name="experiencia[0][anos]" value="2"', false);
        $this->assertSame(2, $c->anosExperiencia());
        // RR. HH. puede guardar por partes (sin referencias ni firma)
        $this->actingAs($this->rh)->put("/candidatos/{$c->id}", ['nombre_completo' => 'Karla Pérez Uc', 'experiencia' => [['empresa' => 'Hotel Sol', 'anos' => '2']]])
            ->assertSessionHas('ok');
        $this->assertSame(2, $c->fresh()->anosExperiencia());
    }
}
