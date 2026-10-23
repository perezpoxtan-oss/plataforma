<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaPaso;
use App\Models\Sede;
use App\Models\User;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Paso «Jefe del departamento del solicitante» (decisión del dueño
 * 2026-10-23): lo firman los responsables de Departamentos del departamento
 * del colaborador solicitante (titular; si no hay, suplente; con su delegado
 * de «No molestar»). Sin responsables: quien tenga «Aprobar» de ese
 * departamento y, si tampoco, cualquiera con «Aprobar» en la sede. El
 * circuito predeterminado empieza con él; los circuitos propios no cambian
 * solos («Agregar el paso del jefe del departamento»).
 */
class PasesSalidaJefeDepartamentoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private Departamento $recepcion;

    private Departamento $mantenimiento;

    private User $admin;

    private User $director;

    private Colaborador $sofia;

    private User $solicitante;

    private User $jefa;

    private User $suplente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->recepcion = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Recepción']));
        $this->mantenimiento = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Mantenimiento']));
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->sofia = $this->colaborador('2001', 'Sofía', $this->recepcion);
        $this->solicitante = $this->usuarioDe('Solicitante', $this->sofia);
        // Jefa y suplente sin colaborador de Recepción: los define Departamentos, no su colaborador
        $this->jefa = $this->usuarioDe('Jefe de departamento', $this->colaborador('2005', 'Julieta', $this->mantenimiento));
        $this->suplente = $this->usuarioDe('Jefe de departamento', $this->colaborador('2006', 'Héctor', $this->mantenimiento));
    }

    // ------------------------------------------------------------- Apoyo

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function colaborador(string $num, string $nombre, ?Departamento $depto, ?Sede $sede = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => 'Pérez',
            'sede_id' => ($sede ?? $this->centro)->id, 'departamento_id' => $depto?->id, 'activo' => true]));
    }

    private function usuarioDe(string $rol, Colaborador $colaborador, ?Sede $sede = null): User
    {
        $usuario = $this->crearUsuario($this->empresa, $rol, $sede ?? $this->centro);
        $usuario->forceFill(['colaborador_id' => $colaborador->id, 'name' => $colaborador->nombre.' Pérez'])->save();

        return $usuario->fresh();
    }

    private function responsable(User $usuario, bool $suplente = false, ?Departamento $depto = null, ?Sede $sede = null): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => ($depto ?? $this->recepcion)->id, 'user_id' => $usuario->id,
            'es_suplente' => $suplente, 'sede_id' => $sede?->id]));
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

    private function crearPase(?Colaborador $colaborador = null, ?User $actor = null): PaseSalida
    {
        $this->actingAs($actor ?? $this->solicitante)->post('/pases-salida', [
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => ($colaborador ?? $this->sofia)->id,
            'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id,
            'fecha_salida_programada' => now()->format('Y-m-d'), 'fecha_tentativa_regreso' => now()->addDays(5)->format('Y-m-d'),
            'articulos' => [['cantidad' => 1, 'equipo' => 'Laptop', 'marca' => 'Lenovo', 'serie' => 'ABC123']],
        ])->assertSessionHasNoErrors();

        return $this->recargar($this->enEmpresa(fn () => PaseSalida::orderByDesc('id')->firstOrFail()));
    }

    private function recargar(PaseSalida $pase): PaseSalida
    {
        return $this->enEmpresa(fn () => PaseSalida::with('aprobaciones.departamento', 'sede')->findOrFail($pase->id));
    }

    private function actual(PaseSalida $pase): ?PaseSalidaAprobacion
    {
        return $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($this->recargar($pase)));
    }

    private function puede(User $usuario, PaseSalida $pase): bool
    {
        app(Autorizador::class)->olvidar();

        return $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->puedeAprobar($usuario, $this->recargar($pase)));
    }

    /** @return array{como: string, usuarios: list<int>} */
    private function jefes(PaseSalida $pase): array
    {
        $r = $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->jefesDelDepartamento($this->recargar($pase), $this->actual($pase)));

        return ['como' => $r['como'], 'usuarios' => $r['usuarios']->pluck('id')->map(fn ($id) => (int) $id)->all()];
    }

    private function aprobar(PaseSalida $pase, User $actor): TestResponse
    {
        app(Autorizador::class)->olvidar();

        return $this->actingAs($actor)->post("/pases-salida/{$pase->id}/firmas", [
            'paso' => 'aprobacion', 'aprobacion_id' => $this->actual($pase)?->id, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
        ]);
    }

    /** Circuito propio: Contraloría (Director) y Gerencia (Administrador). */
    private function circuitoPropio(): void
    {
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => [
            ['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$this->director->id], 'departamento' => 'cualquiera', 'obligatorio' => 1],
            ['nombre' => 'Gerencia', 'tipo' => 'usuarios', 'usuarios' => [$this->admin->id], 'departamento' => 'cualquiera', 'obligatorio' => 1],
        ]])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------ Resolución del firmante

    public function test_firma_el_responsable_titular_del_departamento_del_solicitante(): void
    {
        $this->responsable($this->jefa);
        $this->responsable($this->suplente, true);
        // Una compañera de Recepción con «Aprobar» que no es responsable
        $otraJefa = $this->usuarioDe('Jefe de departamento', $this->colaborador('2007', 'Paola', $this->recepcion));

        $pase = $this->crearPase();
        $paso = $this->actual($pase);
        $this->assertSame(CircuitoPasesSalida::NOMBRE_PASO_JEFE, $paso->nombre);
        $this->assertSame(PaseSalidaPaso::JEFE_DEPARTAMENTO, $paso->tipo);
        $this->assertSame($this->recepcion->id, (int) $paso->departamento_id);
        $this->assertSame('Jefe del departamento de Recepción', $paso->quienFirma());
        $this->assertSame(['como' => 'titular', 'usuarios' => [$this->jefa->id]], $this->jefes($pase));

        // Solo la titular: ni el suplente, ni otra jefa de Recepción, ni el administrador
        $this->assertTrue($this->puede($this->jefa, $pase));
        foreach ([$this->suplente, $otraJefa, $this->admin] as $otro) {
            $this->assertFalse($this->puede($otro, $pase));
        }
        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}")->assertOk()
            ->assertSee('Este paso lo firma: Jefe del departamento de Recepción.')->assertSee('Firma: Julieta Pérez');
        $this->aprobar($pase, $this->admin)->assertForbidden();

        $this->aprobar($pase, $this->jefa)->assertSessionHasNoErrors();
        $this->assertSame('Contraloría', $this->actual($pase)->nombre);
        $this->assertSame($this->jefa->id, (int) $this->recargar($pase)->aprobaciones->firstWhere('orden', 1)->resuelto_por);
    }

    public function test_sin_titular_que_pueda_firmar_firma_el_suplente(): void
    {
        // Solo suplente registrado
        $this->responsable($this->suplente, true);
        $pase = $this->crearPase();
        $this->assertSame(['como' => 'suplente', 'usuarios' => [$this->suplente->id]], $this->jefes($pase));
        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}")->assertOk()
            ->assertSee('El departamento no tiene titular que pueda firmar; firma su suplente: Héctor Pérez');
        $this->aprobar($pase, $this->suplente)->assertSessionHasNoErrors();
        $this->assertSame('Contraloría', $this->actual($pase)->nombre);

        // Titular sin «Aprobar» (rol Solicitante): también firma el suplente
        $sinPermiso = $this->usuarioDe('Solicitante', $this->colaborador('2008', 'Rita', $this->mantenimiento));
        $this->responsable($sinPermiso);
        $otro = $this->crearPase();
        $this->assertSame(['como' => 'suplente', 'usuarios' => [$this->suplente->id]], $this->jefes($otro));

        // La jefa pide un pase a su nombre: lo firma su suplente, nunca ella
        $this->responsable($this->jefa);
        $deLaJefa = $this->colaborador('2009', 'Julieta', $this->recepcion);
        $this->jefa->forceFill(['colaborador_id' => $deLaJefa->id])->save();
        $propio = $this->crearPase($deLaJefa, $this->admin);
        $this->assertSame(['como' => 'suplente', 'usuarios' => [$this->suplente->id]], $this->jefes($propio));
        $this->assertFalse($this->puede($this->jefa->fresh(), $propio));
    }

    public function test_el_delegado_del_titular_tambien_firma(): void
    {
        $this->responsable($this->jefa);
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $this->jefa->id, 'delegado_id' => $this->suplente->id,
            'desde' => now()->subHour(), 'hasta' => now()->addDay(), 'motivo' => 'Vacaciones']));

        $pase = $this->crearPase();
        $this->assertSame(['como' => 'titular', 'usuarios' => [$this->jefa->id, $this->suplente->id]], $this->jefes($pase));
        $this->aprobar($pase, $this->suplente)->assertSessionHasNoErrors();
        $this->assertSame('Contraloría', $this->actual($pase)->nombre);
    }

    public function test_sin_responsable_firma_quien_tenga_aprobar_del_departamento_y_si_no_cualquiera_de_la_sede(): void
    {
        // Sin responsables: una jefa de Recepción por su colaborador (la regla anterior)
        $deRecepcion = $this->usuarioDe('Jefe de departamento', $this->colaborador('2007', 'Paola', $this->recepcion));
        $pase = $this->crearPase();
        $this->assertSame(['como' => 'departamento', 'usuarios' => [$deRecepcion->id]], $this->jefes($pase));
        $this->assertFalse($this->puede($this->admin, $pase));
        $this->actingAs($this->admin)->get("/pases-salida/{$pase->id}")->assertOk()
            ->assertSee('El departamento no tiene responsable en Departamentos; firma quien tenga «Aprobar» en él: Paola Pérez');
        $this->aprobar($pase, $deRecepcion)->assertSessionHasNoErrors();

        // Nadie en Lavandería: la regla general (cualquiera con «Aprobar» en la sede), el pase no se atora
        $lavanderia = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Lavandería']));
        $tecnico = $this->colaborador('2010', 'Javier', $lavanderia);
        $deMantenimiento = $this->crearPase($tecnico, $this->admin);
        $this->assertSame(['como' => 'sede', 'usuarios' => []], $this->jefes($deMantenimiento));
        $this->assertTrue($this->puede($this->admin, $deMantenimiento));
        $this->actingAs($this->admin)->get("/pases-salida/{$deMantenimiento->id}")->assertOk()
            ->assertSee('nadie cumple la regla: firma cualquiera con permiso «Aprobar» de la sede');

        // Solicitante sin departamento: también la regla general
        $sinDepto = $this->colaborador('2011', 'Luis', null);
        $pase3 = $this->crearPase($sinDepto, $this->admin);
        $this->assertNull($this->actual($pase3)->departamento_id);
        $this->assertSame('Jefe del departamento del solicitante', $this->actual($pase3)->quienFirma());
        $this->assertSame('sede', $this->jefes($pase3)['como']);
    }

    // ------------------------------------------------------ Circuito por empresa

    public function test_empresa_nueva_empieza_con_el_paso_del_jefe_del_departamento(): void
    {
        $this->assertSame(CircuitoPasesSalida::NOMBRE_PASO_JEFE, CircuitoPasesSalida::PREDETERMINADO[0]['nombre']);
        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertOk()
            ->assertSee('Jefe del departamento del solicitante → Contraloría → Gerencia')
            ->assertSee('<option value="jefe_departamento" selected>El jefe del departamento del solicitante</option>', false)
            ->assertDontSee('Agregar el paso del jefe del departamento');

        $this->responsable($this->jefa);
        $pase = $this->crearPase();
        $this->assertSame([CircuitoPasesSalida::NOMBRE_PASO_JEFE, 'Contraloría', 'Gerencia'], $pase->aprobaciones->sortBy('orden')->pluck('nombre')->values()->all());
        $this->assertSame(PaseSalidaPaso::JEFE_DEPARTAMENTO, $pase->aprobaciones->firstWhere('orden', 1)->tipo);

        // Guardar el circuito con ese tipo: el departamento siempre es el del solicitante
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => [
            ['nombre' => 'Jefe', 'tipo' => PaseSalidaPaso::JEFE_DEPARTAMENTO, 'departamento' => 'especifico', 'departamento_id' => $this->mantenimiento->id, 'obligatorio' => 1],
        ]])->assertSessionHasNoErrors();
        $guardado = $this->enEmpresa(fn () => PaseSalidaPaso::sole());
        $this->assertSame(['solicitante', null], [$guardado->departamento, $guardado->departamento_id]);
        $this->assertStringContainsString('el jefe del departamento del solicitante', $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->resumen()[0]));

        // Restablecer vuelve al predeterminado, con el paso del jefe
        $this->actingAs($this->admin)->delete('/pases-salida/circuito')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Jefe del departamento del solicitante, Contraloría y Gerencia'));
        $this->assertSame(PaseSalidaPaso::JEFE_DEPARTAMENTO, $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->pasos()->first()->tipo));
    }

    public function test_circuito_propio_no_cambia_solo_y_se_ofrece_agregar_el_paso(): void
    {
        $this->circuitoPropio();
        $nombres = fn () => $this->enEmpresa(fn () => PaseSalidaPaso::orderBy('orden')->pluck('nombre')->all());
        $this->assertSame(['Contraloría', 'Gerencia'], $nombres());

        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertOk()
            ->assertSee('Tu circuito no tiene el paso del jefe del departamento.')
            ->assertSee(route('pases-salida.circuito.paso-jefe'), false);
        // Ver la pantalla no cambia nada
        $this->assertSame(['Contraloría', 'Gerencia'], $nombres());

        $this->actingAs($this->admin)->post('/pases-salida/circuito/paso-jefe')->assertRedirect(route('pases-salida.circuito'))
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Agregamos el paso «Jefe del departamento del solicitante» al inicio del circuito')
                && str_contains($m, 'los que ya están en curso no cambian'));
        $this->assertSame([CircuitoPasesSalida::NOMBRE_PASO_JEFE, 'Contraloría', 'Gerencia'], $nombres());
        $this->assertSame([1, 2, 3], $this->enEmpresa(fn () => PaseSalidaPaso::orderBy('orden')->pluck('orden')->all()));
        // Los demás pasos no cambian (usuarios elegidos incluidos)
        $this->assertSame([$this->director->id], $this->enEmpresa(fn () => PaseSalidaPaso::where('nombre', 'Contraloría')->sole()->usuarios->pluck('id')->all()));
        // Auditado: el guardado del circuito propio y el paso agregado
        $this->assertSame(2, $this->enEmpresa(fn () => Auditoria::where('evento', 'pases_salida.circuito_actualizado')->count()));

        $this->actingAs($this->admin)->get('/pases-salida/circuito')->assertOk()->assertDontSee('Tu circuito no tiene el paso del jefe del departamento.');
        $this->actingAs($this->admin)->post('/pases-salida/circuito/paso-jefe')->assertSessionHasErrors(['pasos' => 'El circuito ya tiene el paso «Jefe del departamento del solicitante».']);

        // Con el predeterminado no hay nada que agregar
        $this->actingAs($this->admin)->delete('/pases-salida/circuito');
        $this->actingAs($this->admin)->post('/pases-salida/circuito/paso-jefe')->assertSessionHasErrors('pasos');

        // Sin permiso de configurar con alcance de empresa: prohibido
        $this->actingAs($this->jefa)->post('/pases-salida/circuito/paso-jefe')->assertForbidden();
    }

    public function test_con_ocho_pasos_no_se_agrega(): void
    {
        $pasos = collect(range(1, CircuitoPasesSalida::MAX_PASOS))->map(fn ($n) => ['nombre' => "Paso {$n}", 'tipo' => 'permiso', 'departamento' => 'cualquiera', 'obligatorio' => 1])->all();
        $this->actingAs($this->admin)->put('/pases-salida/circuito', ['pasos' => $pasos])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/pases-salida/circuito/paso-jefe')
            ->assertSessionHasErrors(['pasos' => 'El circuito ya tiene 8 pasos (el máximo): quita uno antes de agregar el del jefe del departamento.']);
        $this->assertSame(8, $this->enEmpresa(fn () => PaseSalidaPaso::count()));
    }

    public function test_el_pase_en_curso_conserva_su_circuito(): void
    {
        $this->circuitoPropio();
        $this->responsable($this->jefa);
        $enCurso = $this->crearPase();
        $this->assertSame(['Contraloría', 'Gerencia'], $enCurso->aprobaciones->sortBy('orden')->pluck('nombre')->values()->all());

        $this->actingAs($this->admin)->post('/pases-salida/circuito/paso-jefe')->assertSessionHasNoErrors();

        $enCurso = $this->recargar($enCurso);
        $this->assertSame(['Contraloría', 'Gerencia'], $enCurso->aprobaciones->sortBy('orden')->pluck('nombre')->values()->all());
        $this->assertSame('Contraloría', $this->actual($enCurso)->nombre);
        $this->assertFalse($this->puede($this->jefa, $enCurso));

        $nuevo = $this->crearPase();
        $this->assertSame([CircuitoPasesSalida::NOMBRE_PASO_JEFE, 'Contraloría', 'Gerencia'], $nuevo->aprobaciones->sortBy('orden')->pluck('nombre')->values()->all());
        $this->assertTrue($this->puede($this->jefa, $nuevo));
    }

    // ------------------------------------------- Mis pendientes, campana y espera

    public function test_mis_pendientes_campana_y_escalamiento_del_jefe(): void
    {
        $this->responsable($this->jefa);
        $otroJefe = $this->usuarioDe('Jefe de departamento', $this->colaborador('2012', 'Mario', $this->mantenimiento));
        $this->responsable($otroJefe, false, $this->mantenimiento);

        $pase = $this->crearPase();
        $total = fn (User $u) => collect(app(MisPendientes::class)->para($u)['items'])->firstWhere('clave', 'pases_salida')['total'] ?? 0;
        $this->assertSame(1, $total($this->jefa));
        $this->assertSame(0, $total($otroJefe));
        $this->actingAs($this->jefa)->get('/pases-salida/mis-pendientes')->assertOk()->assertSee('id="pase-'.$pase->id.'"', false);
        $this->actingAs($otroJefe)->get('/pases-salida/mis-pendientes')->assertOk()->assertDontSee('id="pase-'.$pase->id.'"', false);

        // Campana: «Te toca firmar» solo a la jefa de Recepción
        $avisos = fn (User $u, string $tipo) => $this->enEmpresa(fn () => Notificacion::where('user_id', $u->id)->where('tipo', $tipo)->get());
        $this->assertCount(1, $avisos($this->jefa, 'pase_por_firmar'));
        $this->assertStringContainsString('Jefe del departamento del solicitante (paso 1 de 3)', $avisos($this->jefa, 'pase_por_firmar')->first()->texto);
        $this->assertCount(0, $avisos($otroJefe, 'pase_por_firmar'));
        $this->assertCount(0, $avisos($this->admin, 'pase_por_firmar'));

        // Firmas que tardan: recordatorio a la jefa y aviso al siguiente nivel (Contraloría), como en los demás pasos
        $this->actingAs($this->admin)->put('/pases-salida/circuito', [
            'pasos' => [
                ['nombre' => CircuitoPasesSalida::NOMBRE_PASO_JEFE, 'tipo' => PaseSalidaPaso::JEFE_DEPARTAMENTO, 'obligatorio' => 1],
                ['nombre' => 'Contraloría', 'tipo' => 'usuarios', 'usuarios' => [$this->director->id], 'departamento' => 'cualquiera', 'obligatorio' => 1],
            ],
            'rechazo' => 'inicio', 'espera' => ['recordar' => 4, 'recordar_unidad' => 'horas', 'escalar' => 1, 'escalar_unidad' => 'dias'],
        ])->assertSessionHasNoErrors();
        $atrasado = $this->crearPase();
        $this->travel(25)->hours();
        $this->artisan('plataforma:escalar-pases')->assertSuccessful();
        $this->assertTrue($avisos($this->jefa, 'pase_firma_recordatorio')->contains(fn ($n) => str_contains($n->titulo, $atrasado->folio)));
        $this->assertTrue($avisos($this->director, 'pase_firma_escalada')->contains(fn ($n) => str_contains($n->titulo.$n->texto, $atrasado->folio)));
    }
}
