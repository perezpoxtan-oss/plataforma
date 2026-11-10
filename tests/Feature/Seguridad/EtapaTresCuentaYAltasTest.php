<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Importacion;
use App\Models\Notificacion;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Permisos\Autorizador;
use App\Support\Menu\ConstructorMenu;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 3 · C-11 (la cuenta sigue al colaborador, DD-22, A-06) y C-27 (una
 * sola bandeja «Altas por verificar», A-11 y B-11).
 */
class EtapaTresCuentaYAltasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Cuentas');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function colaborador(array $datos = []): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create($datos + ['num_empleado' => '100', 'nombre' => 'Laura', 'apellido_paterno' => 'Chan', 'sede_id' => $this->centro->id]));
    }

    private function provisional(string $nombre, string $paterno): Colaborador
    {
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', ['nombre' => $nombre, 'apellido_paterno' => $paterno, 'sede_id' => $this->centro->id])->assertCreated();

        return $this->enEmpresa(fn () => Colaborador::where('nombre', $nombre)->where('provisional', true)->firstOrFail());
    }

    private function olvidar(): void
    {
        app(Autorizador::class)->olvidar();
        $this->app->forgetScopedInstances();
        Cache::flush();
    }

    // ================================================================ C-11

    public function test_c11_el_colaborador_manda_en_el_nombre_y_el_numero_de_su_cuenta(): void
    {
        $c = $this->colaborador();
        $cuenta = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $cuenta->forceFill(['colaborador_id' => $c->id, 'name' => 'Nombre Viejo', 'numero_colaborador' => 'X-9'])->save();

        // Editar al colaborador actualiza la cuenta y queda en la bitácora
        $this->actingAs($this->rh)->put("/colaboradores/{$c->id}", ['num_empleado' => '101', 'nombre' => 'Laura Elena', 'apellido_paterno' => 'Chan',
            'apellido_materno' => 'Ek', 'sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $this->assertSame(['Laura Elena Chan Ek', '101'], array_values($cuenta->fresh()->only(['name', 'numero_colaborador'])));
        $this->assertTrue(DB::table('auditoria')->where('evento', 'usuarios.sincronizado')->where('auditable_id', $cuenta->id)->exists());

        // En Usuarios, el nombre que se escriba no manda: se toma del colaborador
        $rol = UsuarioRol::where('user_id', $cuenta->id)->value('rol_id');
        $this->actingAs($this->admin)->put("/usuarios/{$cuenta->id}", ['name' => 'Otro Nombre', 'username' => $cuenta->username, 'email' => $cuenta->email,
            'colaborador_id' => $c->id, 'numero_colaborador' => '101', 'rol_id' => $rol, 'sede_id' => $this->centro->id, 'activo' => 1]);
        $this->assertSame('Laura Elena Chan Ek', $cuenta->fresh()->name);
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()->assertSee('data-nombre-cuenta', false)->assertSee('cambiar en Colaboradores');

        // Cambia de sede y su cuenta solo entra a Centro: «¿Mover su cuenta a Playa?»
        $this->actingAs($this->rh)->put("/colaboradores/{$c->id}", ['num_empleado' => '101', 'nombre' => 'Laura Elena', 'apellido_paterno' => 'Chan',
            'apellido_materno' => 'Ek', 'sede_id' => $this->playa->id])->assertSessionHasNoErrors();
        $aviso = Notificacion::withoutGlobalScopes()->where('tipo', 'cuenta_sede')->where('user_id', $this->admin->id)->first();
        $this->assertNotNull($aviso);
        $this->assertSame('¿Mover su cuenta a '.$this->playa->nombre.'?', $aviso->titulo);

        // La migración de una sola vez vuelve a copiar nombre y número
        DB::table('users')->where('id', $cuenta->id)->update(['name' => 'Desfasado', 'numero_colaborador' => null]);
        (require database_path('migrations/2026_11_12_000100_cuentas_siguen_al_colaborador.php'))->up();
        $this->assertSame(['Laura Elena Chan Ek', '101'], array_values($cuenta->fresh()->only(['name', 'numero_colaborador'])));
    }

    public function test_c11_unir_duplicados_y_verificar_pasan_nombre_y_numero_y_la_baja_es_la_de_la_etapa_2(): void
    {
        $correcto = $this->colaborador(['num_empleado' => '200', 'nombre' => 'Mario', 'apellido_paterno' => 'Poot']);
        $prov = $this->provisional('Mario', 'Pot');
        $cuenta = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $cuenta->forceFill(['colaborador_id' => $prov->id, 'name' => 'Mario Pot', 'numero_colaborador' => null])->save();

        $this->actingAs($this->rh)->put("/colaboradores/{$prov->id}/fusionar", ['destino_id' => $correcto->id])->assertSessionHas('ok');
        $this->assertSame([$correcto->id, 'Mario Poot', '200'], array_values($cuenta->fresh()->only(['colaborador_id', 'name', 'numero_colaborador'])));

        // Baja del colaborador (Etapa 2): su cuenta se da de baja sola; al reactivarlo, la cuenta no se reactiva sola
        $this->actingAs($this->rh)->patch("/colaboradores/{$correcto->id}/estado", ['activo' => 0, 'revisado' => 1])->assertSessionHas('aviso');
        $this->assertFalse($cuenta->fresh()->activo);
        $this->actingAs($this->rh)->patch("/colaboradores/{$correcto->id}/estado", ['activo' => 1])->assertSessionHas('ok');
        $this->assertFalse($cuenta->fresh()->activo);
    }

    // ================================================================ C-27

    public function test_c27_una_sola_bandeja_con_pestanas_y_los_tres_caminos_para_colaboradores(): void
    {
        $jorge = $this->provisional('Jorge', 'Méndez');
        $falso = $this->provisional('Nadie', 'Inventado');
        $persona = $this->enEmpresa(function () {
            $p = Persona::create(['nombre_completo' => 'PERSONA NUEVA', 'tipo' => 'visitante']);
            $p->forceFill(['verificacion' => Persona::PENDIENTE, 'creado_por' => $this->agente->id])->save();

            return $p;
        });

        // Menú: una sola entrada «Altas por verificar» en Padrones
        $this->olvidar();
        $menu = collect(app(ConstructorMenu::class)->para($this->admin))->firstWhere('clave', 'padrones');
        $this->assertContains('altas_por_verificar', collect($menu['secciones'])->flatten(1)->pluck('clave')->all());

        // Mis pendientes: un solo renglón con el desglose por pestaña (sin «Colaboradores por verificar» aparte)
        $this->olvidar();
        $items = collect(app(MisPendientes::class)->para($this->admin)['items'])->keyBy('clave');
        $this->assertArrayNotHasKey('colaboradores_por_verificar', $items->all());
        $this->assertSame(3, $items['altas']['total']);
        $this->assertSame(['Personas', 'Colaboradores'], array_column($items['altas']['detalle'], 'titulo'));
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('3 altas por verificar');

        // La bandeja: pestañas con su número; Personas usa el diálogo de los padrones (Es correcto / Es el mismo / Rechazar)
        $this->actingAs($this->admin)->get('/altas-por-verificar?pestana=personas')->assertOk()
            ->assertSee('Personas')->assertSee('Vehículos')->assertSee('Empresas')->assertSee('Colaboradores')
            ->assertSee('PERSONA NUEVA')->assertSee('Es correcto')->assertSee('Es el mismo')->assertSee('Aprobar · es el mismo')->assertSee('Por verificar');
        $r = $this->actingAs($this->admin)->get('/altas-por-verificar?pestana=colaboradores')->assertOk()
            ->assertSee('Jorge Méndez')->assertSee('Nadie Inventado')->assertSee('Es correcto')->assertSee('Es el mismo')->assertSee('Rechazar')
            ->assertSee('Las verifica Recursos Humanos');

        // Rechazar con motivo: se da de baja, deja de estar por verificar y avisa a quien la registró
        $this->actingAs($this->admin)->put("/colaboradores/{$falso->id}/rechazar", ['motivo_rechazo' => 'x'])->assertSessionHasErrors('motivo_rechazo');
        $this->actingAs($this->admin)->put("/colaboradores/{$falso->id}/rechazar", ['motivo_rechazo' => 'No trabaja aquí', 'volver' => 'altas'])
            ->assertRedirect(route('altas_por_verificar.index', ['pestana' => 'colaboradores']))->assertSessionHas('ok');
        $this->assertFalse($falso->fresh()->activo);
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->agente->id)->where('tipo', 'alta_rechazada')->firstOrFail();
        $this->assertStringContainsString('No trabaja aquí', $aviso->texto);
        $this->assertTrue(DB::table('auditoria')->where('evento', 'colaboradores.rechazado')->exists());

        // Es correcto (desde la bandeja regresa a la bandeja)
        $this->actingAs($this->admin)->put("/colaboradores/{$jorge->id}/validar", ['num_empleado' => '3001', 'nombre' => 'Jorge', 'apellido_paterno' => 'Méndez',
            'sede_id' => $this->centro->id, 'volver' => 'altas'])->assertRedirect(route('altas_por_verificar.index', ['pestana' => 'colaboradores']));
        $this->assertFalse($jorge->fresh()->provisional);
        $this->olvidar();
        $this->assertSame(1, collect(app(MisPendientes::class)->para($this->admin)['items'])->firstWhere('clave', 'altas')['total']);

        // Quien no verifica nada: 403
        $this->actingAs($this->agente)->get('/altas-por-verificar')->assertForbidden();
    }

    public function test_c27_sin_recursos_humanos_verifica_el_administrador_y_la_importacion_une_con_el_alta_de_la_caseta(): void
    {
        $prov = $this->provisional('Rosa', 'Uc');
        // Plan sin Recursos Humanos (Etapa 2, A-3): el texto dice quién verifica
        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)
            ->where('modulo_id', DB::table('modulos')->where('clave', 'candidatos')->value('id'))->update(['activo' => false]);
        $this->olvidar();
        $this->actingAs($this->admin)->get('/altas-por-verificar?pestana=colaboradores')->assertOk()->assertSee('Las verifica el administrador');

        // Importar la plantilla con «Rosa Uc» no la duplica: propone unirla con el alta de la caseta
        $csv = "Número de empleado,Nombre(s),Apellido paterno\nE-77,Rosa,Uc\n";
        $this->actingAs($this->admin)->post('/importar/colaboradores', ['archivo' => UploadedFile::fake()->createWithContent('p.csv', $csv)]);
        $i = Importacion::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('existe', $i->filas[0]['estado']);
        $this->assertStringContainsString('alta de la caseta por verificar', $i->filas[0]['mensaje']);
        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i), ['existentes' => 'actualizar'])->assertSessionHas('ok');
        $prov->refresh();
        $this->assertSame(['E-77', false], [$prov->num_empleado, (bool) $prov->provisional]);
        $this->assertSame(1, $this->enEmpresa(fn () => Colaborador::where('nombre', 'Rosa')->count()), 'Sin duplicado');
    }
}
