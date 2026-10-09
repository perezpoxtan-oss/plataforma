<?php

namespace Tests\Feature\Nucleo;

use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\PaseSalida;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vacante;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Database\Seeders\RolesPlantillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Roles base «Solicitante» (nivel 70: levanta solicitudes y ve las suyas) y
 * «Jefe de departamento» (nivel 45: aprueba las de su departamento en su
 * sede, responde sus autorizaciones y pide vacantes).
 */
class PlantillaSolicitudesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private Departamento $recepcion;

    private Departamento $mantenimiento;

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
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function colaborador(string $num, string $nombre, Departamento $depto, ?Sede $sede = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => 'Pérez',
            'sede_id' => ($sede ?? $this->centro)->id, 'departamento_id' => $depto->id, 'activo' => true]));
    }

    /** Usuario con el rol en la sede indicada y vinculado a su colaborador. */
    private function usuarioDe(string $rol, Colaborador $colaborador, ?Sede $sede = null): User
    {
        $usuario = $this->crearUsuario($this->empresa, $rol, $sede ?? $this->centro);
        $usuario->forceFill(['colaborador_id' => $colaborador->id])->save();

        return $usuario->fresh();
    }

    /**
     * @return list<string> módulo.acción:alcance
     */
    private function permisos(Rol $rol): array
    {
        return RolPermiso::where('rol_id', $rol->id)->with('moduloAccion.modulo', 'moduloAccion.accion')->get()
            ->map(fn ($p) => $p->moduloAccion->clave().':'.$p->alcance->value)
            ->sort()->values()->all();
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

    /** @return array<string, mixed> */
    private function datosPase(Colaborador $solicitante, array $extra = []): array
    {
        return array_merge([
            'sede_id' => $this->centro->id, 'motivo' => 'prestamo', 'colaborador_id' => $solicitante->id,
            'destino_tipo' => 'sede', 'sede_destino_id' => $this->playa->id,
            'articulos' => [['cantidad' => 1, 'equipo' => 'Laptop', 'marca' => 'Lenovo', 'serie' => 'ABC123']],
        ], $extra);
    }

    private function ultimoPase(): PaseSalida
    {
        return $this->enEmpresa(fn () => PaseSalida::with('aprobaciones.rol', 'aprobaciones.departamento', 'sede')->orderByDesc('id')->firstOrFail());
    }

    // ------------------------------------------------------------- Plantillas

    public function test_las_plantillas_existen_con_su_nivel_y_permisos_exactos(): void
    {
        $solicitante = Rol::plantillas()->where('nombre', 'Solicitante')->firstOrFail();
        $jefe = Rol::plantillas()->where('nombre', 'Jefe de departamento')->firstOrFail();

        $this->assertSame(70, $solicitante->nivel_jerarquia);
        $this->assertSame(45, $jefe->nivel_jerarquia);
        $this->assertSame(['pases_salida.crear:propios', 'pases_salida.ver:propios', 'procedimientos.ver:sede'], $this->permisos($solicitante));
        $this->assertSame([
            'autorizaciones.responder:sede', 'autorizaciones.ver:sede',
            'pases_salida.aprobar:sede', 'pases_salida.crear:propios', 'pases_salida.imprimir:sede', 'pases_salida.ver:sede',
            'procedimientos.ver:sede', 'vacantes.crear:propios', 'vacantes.ver:sede',
        ], $this->permisos($jefe));
    }

    public function test_una_empresa_nueva_los_recibe_y_la_migracion_los_agrega_sin_duplicar(): void
    {
        $this->assertSame(1, Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Solicitante')->count());
        $this->assertSame(1, Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Jefe de departamento')->count());

        // Empresa que ya existía sin los roles (y con el nivel 45 ocupado por un rol propio)
        $antigua = $this->crearEmpresa('Hotel Antiguo');
        Rol::where('empresa_id', $antigua->id)->whereIn('nombre', ['Solicitante', 'Jefe de departamento'])->get()->each->delete();
        Rol::create(['empresa_id' => $antigua->id, 'nombre' => 'Gerente de área', 'nivel_jerarquia' => 45]);

        $migracion = require database_path('migrations/2026_10_18_000100_agregar_roles_solicitante_y_jefe_de_departamento.php');
        $migracion->up();
        $migracion->up();

        $this->assertSame(1, Rol::where('empresa_id', $antigua->id)->where('nombre', 'Solicitante')->count());
        $this->assertSame(0, Rol::where('empresa_id', $antigua->id)->where('nombre', 'Jefe de departamento')->count());
        $this->assertSame(1, Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Solicitante')->count());
        $this->assertSame(1, Rol::plantillas()->where('nombre', RolesPlantillaSeeder::JEFE_DEPARTAMENTO)->count());
    }

    // ------------------------------------------------------------ Solicitante

    public function test_el_solicitante_pide_pases_a_su_nombre_y_solo_ve_los_suyos(): void
    {
        $sofia = $this->colaborador('2001', 'Sofía', $this->recepcion);
        $otro = $this->colaborador('2002', 'Mario', $this->recepcion);
        $usuario = $this->usuarioDe('Solicitante', $sofia);
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        // El formulario ya trae su nombre como solicitante
        $this->actingAs($usuario)->get('/pases-salida?nuevo=1')->assertOk()->assertSee('Nuevo Pase de Salida')
            ->assertSee('Sofía Pérez · Núm. 2001')->assertSee('name="colaborador_id" value="'.$sofia->id.'"', false);

        // A nombre de otra persona, no
        $this->actingAs($usuario)->post('/pases-salida', $this->datosPase($otro))
            ->assertSessionHasErrors(['colaborador_id' => 'Solo puedes solicitar pases de salida a tu nombre.']);
        $this->actingAs($usuario)->post('/pases-salida', $this->datosPase($sofia))->assertSessionHasNoErrors();
        $suyo = $this->ultimoPase();
        $this->assertSame($usuario->id, (int) $suyo->creado_por);

        // El pase de otra persona no existe para él
        $this->actingAs($admin)->post('/pases-salida', $this->datosPase($otro))->assertSessionHasNoErrors();
        $ajeno = $this->ultimoPase();
        $this->flushSession(); // sin el aviso «Pase … registrado» del administrador
        $this->actingAs($usuario)->get('/pases-salida')->assertOk()->assertSee($suyo->folio)->assertDontSee($ajeno->folio);
        $this->actingAs($usuario)->get('/pases-salida/'.$ajeno->id)->assertNotFound();
        $this->actingAs($usuario)->get('/pases-salida/'.$suyo->id)->assertOk();

        // No aprueba, no opera la caseta ni consulta el directorio
        $this->assertFalse($usuario->can('pases_salida.aprobar'));
        $this->assertFalse($usuario->can('pases_salida.firmar'));
        foreach (['/accesos', '/colaboradores', '/llaves', '/autorizaciones', '/vacantes', '/usuarios'] as $pantalla) {
            $this->actingAs($usuario)->get($pantalla)->assertForbidden();
        }
        $this->actingAs($usuario)->getJson('/colaboradores/buscar?q=Mario')->assertForbidden();
        // Procedimientos y Manual sí
        $this->actingAs($usuario)->get('/procedimientos')->assertOk();
        $this->actingAs($usuario)->get('/manual')->assertOk();
    }

    public function test_el_solicitante_sin_colaborador_vinculado_recibe_un_aviso_claro(): void
    {
        $usuario = $this->crearUsuario($this->empresa, 'Solicitante', $this->centro);
        $alguien = $this->colaborador('2003', 'Luis', $this->recepcion);

        $this->actingAs($usuario)->post('/pases-salida', $this->datosPase($alguien))
            ->assertSessionHasErrors(['colaborador_id' => 'Tu usuario no está vinculado a un colaborador: pide a tu administrador que lo vincule en Usuarios para poder solicitar pases.']);
    }

    // ---------------------------------------------------- Jefe de departamento

    public function test_el_jefe_de_departamento_aprueba_los_pases_de_su_gente_en_su_sede(): void
    {
        $sofia = $this->colaborador('2001', 'Sofía', $this->recepcion);
        $tecnico = $this->colaborador('2004', 'Javier', $this->mantenimiento);
        $solicitante = $this->usuarioDe('Solicitante', $sofia);
        $jefa = $this->usuarioDe('Jefe de departamento', $this->colaborador('2005', 'Julieta', $this->recepcion));
        $jefeMantenimiento = $this->usuarioDe('Jefe de departamento', $this->colaborador('2006', 'Héctor', $this->mantenimiento));
        $jefaPlaya = $this->usuarioDe('Jefe de departamento', $this->colaborador('2007', 'Paola', $this->recepcion, $this->playa), $this->playa);

        // Circuito de siempre: 1. Jefe de Departamento (del solicitante) → Contraloría → Gerencia
        $this->actingAs($solicitante)->post('/pases-salida', $this->datosPase($sofia))->assertSessionHasNoErrors();
        $pase = $this->ultimoPase();
        $paso = $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($pase));
        $this->assertSame('Jefe de Departamento', $paso->nombre);

        // Solo la jefa de Recepción de Centro lo tiene en «Mis pendientes»
        $this->assertSame(1, collect(app(MisPendientes::class)->para($jefa)['items'])->firstWhere('clave', 'pases_salida')['total'] ?? 0);
        $this->actingAs($jefa)->get('/pases-salida/mis-pendientes')->assertOk()->assertSee('id="pase-'.$pase->id.'"', false);
        $this->actingAs($jefeMantenimiento)->get('/pases-salida/mis-pendientes')->assertOk()->assertDontSee('id="pase-'.$pase->id.'"', false);
        // La de Playa lo ve porque va a su sede, pero no lo aprueba: se firma en la sede de origen
        $this->assertFalse($this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->puedeAprobar($jefaPlaya, $this->ultimoPase())));

        $firmar = fn (User $quien) => $this->actingAs($quien)->post("/pases-salida/{$pase->id}/firmas", [
            'paso' => 'aprobacion', 'aprobacion_id' => $paso->id, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg()]);
        $firmar($jefeMantenimiento)->assertForbidden();
        app(Autorizador::class)->olvidar();
        $firmar($jefa)->assertSessionHasNoErrors();
        $this->assertNotSame($paso->id, $this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->actual($this->ultimoPase()))?->id);

        // Un pase de Mantenimiento lo firma su jefe, no la de Recepción
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->post('/pases-salida', $this->datosPase($tecnico))->assertSessionHasNoErrors();
        $deMantenimiento = $this->ultimoPase();
        $this->assertTrue($this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->puedeAprobar($jefeMantenimiento, $deMantenimiento)));
        $this->assertFalse($this->enEmpresa(fn () => app(CircuitoPasesSalida::class)->puedeAprobar($jefa, $deMantenimiento)));

        // También pide pases como cualquier solicitante, pero no opera la caseta
        $this->assertTrue($jefa->can('pases_salida.crear'));
        $this->assertFalse($jefa->can('pases_salida.firmar'));
        $this->actingAs($jefa)->get('/accesos')->assertForbidden();
    }

    public function test_el_jefe_de_departamento_responde_las_autorizaciones_de_su_departamento(): void
    {
        $jefa = $this->usuarioDe('Jefe de departamento', $this->colaborador('2005', 'Julieta', $this->recepcion));
        $otro = $this->usuarioDe('Jefe de departamento', $this->colaborador('2006', 'Héctor', $this->mantenimiento));
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->recepcion->id, 'user_id' => $jefa->id, 'es_suplente' => false, 'sede_id' => $this->centro->id]));
        $a = $this->enEmpresa(fn () => Autorizacion::create(['sede_id' => $this->centro->id, 'departamento_id' => $this->recepcion->id, 'tipo' => 'visita', 'solicitada_en' => now()]));

        $this->assertContains('autorizaciones', array_column(app(MisPendientes::class)->para($jefa)['items'], 'clave'));
        $this->actingAs($jefa)->get('/autorizaciones')->assertOk();
        $this->actingAs($jefa)->get('/autorizaciones/'.$a->id)->assertOk();
        // Quien no es responsable de ese departamento no la ve
        $this->assertNotContains('autorizaciones', array_column(app(MisPendientes::class)->para($otro)['items'], 'clave'));
        $this->actingAs($otro)->get('/autorizaciones/'.$a->id)->assertNotFound();
        // No configura responsables
        $this->actingAs($jefa)->get('/autorizaciones/responsables')->assertForbidden();
    }

    public function test_el_jefe_de_departamento_pide_vacantes_que_recursos_humanos_publica(): void
    {
        $jefa = $this->usuarioDe('Jefe de departamento', $this->colaborador('2005', 'Julieta', $this->recepcion));
        $otro = $this->usuarioDe('Jefe de departamento', $this->colaborador('2006', 'Héctor', $this->mantenimiento));
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');

        $this->actingAs($jefa)->post('/vacantes', ['titulo' => 'Recepcionista bilingüe', 'plazas' => '1', 'todas_las_sedes' => '0',
            'sedes' => [$this->centro->id], 'departamento_id' => $this->recepcion->id, 'publicar' => '1'])->assertSessionHasNoErrors()
            ->assertSessionHas('ok', 'Vacante «Recepcionista bilingüe» enviada como borrador: Recursos Humanos la revisará y la publicará.');
        $vacante = $this->enEmpresa(fn () => Vacante::where('titulo', 'Recepcionista bilingüe')->firstOrFail());
        // Queda en borrador aunque pida publicar: la publica Recursos Humanos
        $this->assertSame('borrador', $vacante->estado);

        $this->flushSession();
        $this->actingAs($jefa)->get('/vacantes')->assertOk()->assertSee('Recepcionista bilingüe')->assertSee('Guardar borrador')->assertDontSee('Guardar y publicar');
        $this->actingAs($otro)->get('/vacantes')->assertOk()->assertDontSee('Recepcionista bilingüe');
        $this->actingAs($jefa)->patch("/vacantes/{$vacante->id}/estado", ['estado' => 'publicada'])->assertForbidden();
        $this->actingAs($jefa)->put("/vacantes/{$vacante->id}", ['titulo' => 'Otra cosa', 'plazas' => '1'])->assertForbidden();

        $this->actingAs($rh)->get('/vacantes')->assertOk()->assertSee('Recepcionista bilingüe');
        $this->actingAs($rh)->patch("/vacantes/{$vacante->id}/estado", ['estado' => 'publicada'])->assertSessionHasNoErrors();
        $this->assertSame('publicada', $vacante->fresh()->estado);
        $this->actingAs($otro)->get('/vacantes')->assertOk()->assertSee('Recepcionista bilingüe');
    }
}
