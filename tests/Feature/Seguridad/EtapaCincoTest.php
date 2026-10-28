<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Notificacion;
use App\Models\Novedad;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaOrden;
use App\Models\ReservaServicio;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Services\Arranque\AsistenteArranque;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Novedades\CanalizarDepartamento;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Services\Privacidad\DatosDePersona;
use App\Services\Relojes\MotorRelojes;
use App\Services\Reservas\AdministradorReservas;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Services\Reservas\FlujoReservas;
use App\Support\BaseDatos\Restricciones;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 5 · Quién decide en la caseta y los giros residenciales:
 * C-10 (residentes con vivienda), C-30 (lugares del catálogo), C-09 (un solo
 * «¿A quién viene?», DD-12), C-28 (canalizar a un departamento, DD-15) y
 * C-34 (reservas por vivienda). Cada prueba revisa también los módulos
 * vinculados de la matriz §9 (caseta, padrones, autorizaciones, Mis
 * pendientes, campana, informes, búsqueda, ARCO, reservas y relojes).
 */
class EtapaCincoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIGRACIONES = [
        '2026_11_14_000100_residentes_y_viviendas',
        '2026_11_14_000200_lugares_de_catalogo_en_accesos',
        '2026_11_14_000300_a_quien_viene',
        '2026_11_14_000400_canalizar_a_departamento',
        '2026_11_14_000500_reservas_por_vivienda',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    // ------------------------------------------------------------------ Ayudas

    /**
     * @return array{0: Empresa, 1: Sede, 2: User}
     */
    private function empresaDeGiro(string $rubro, array $recepcion = []): array
    {
        $empresa = app(ProvisionarEmpresa::class)->crear(Rubro::where('clave', $rubro)->firstOrFail(), ['nombre_comercial' => 'Giro '.$rubro]);
        $empresa->forceFill(['preferencias' => ['recepcion' => $recepcion + ['rh_autoriza_paso' => false, 'visitas_requieren_autorizacion' => false,
            'proveedores_requieren_autorizacion' => false, 'contratistas_requieren_autorizacion' => false]]])->save();
        $sede = $this->crearSede($empresa, 'S1');

        return [$empresa, $sede, $this->crearUsuario($empresa, 'Administrador')];
    }

    private function en(Empresa $empresa, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($empresa->id, $fn);
    }

    /** Torre A › Piso 1 › 101, 102 (y lo que se pida). @return array<string, Espacio> */
    private function viviendas(Empresa $empresa, Sede $sede, User $admin, array $nombres = ['101', '102']): array
    {
        return $this->en($empresa, function () use ($sede, $admin, $nombres) {
            $this->actingAs($admin);
            $esp = app(AdministradorEspacios::class);
            $torre = $esp->crear($admin, $sede, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $piso = $esp->crear($admin, $sede, $torre, Espacio::AREA, ['nombre' => 'Piso 1'], false);
            $esp->crearLote($admin, $piso, $nombres, null, null);

            return Espacio::where('nivel', Espacio::AREA_ESPECIFICA)->get()->keyBy('nombre')->all();
        });
    }

    private function residente(Empresa $empresa, Sede $sede, ?Espacio $vivienda, string $nombre = 'Alejandra', string $tel = '3310001010'): Colaborador
    {
        return $this->en($empresa, fn () => Colaborador::create(['tipo' => Colaborador::RESIDENTE, 'nombre' => $nombre, 'apellido_paterno' => 'Fuentes',
            'sede_id' => $sede->id, 'espacio_id' => $vivienda?->id, 'telefono' => $tel]));
    }

    private function ingreso(User $actor, Sede $sede, array $datos): TestResponse
    {
        return $this->actingAs($actor)->post('/accesos', $datos + ['sede_id' => $sede->id, '_dialogo' => 'ingreso', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
    }

    private function ultimo(Empresa $empresa): Acceso
    {
        return $this->en($empresa, fn () => Acceso::orderByDesc('id')->firstOrFail());
    }

    private function migracion(string $nombre): object
    {
        return require database_path("migrations/{$nombre}.php");
    }

    private function olvidar(): void
    {
        app(Autorizador::class)->olvidar();
        $this->app->forgetScopedInstances();
        Cache::flush();
    }

    // ============================================================ Migraciones

    public function test_las_migraciones_van_y_regresan_con_datos_y_convierten_residentes_host_y_habitaciones(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin, ['101', 'B-101']);
        $hotel = $this->crearEmpresa('Hotel Mig');
        $sedeHotel = $this->crearSede($hotel, 'H1');

        foreach (array_reverse(self::MIGRACIONES) as $m) {
            $this->migracion($m)->down();
        }
        $this->assertSame('Residente', json_decode((string) DB::table('rubros')->where('clave', 'condominio')->value('terminologia'), true)['colaborador']);
        $this->assertSame('Departamento', json_decode((string) DB::table('rubros')->where('clave', 'condominio')->value('terminologia'), true)['area_especifica']);

        // Datos de antes: en el condominio, residentes como colaboradores con su número; el guardia con cuenta
        $ahora = now();
        $base = ['empresa_id' => $condo->id, 'sede_id' => $sede->id, 'apellido_paterno' => 'X', 'activo' => true, 'provisional' => false, 'nacionalidad' => 'Mexicana', 'created_at' => $ahora, 'updated_at' => $ahora];
        $r101 = DB::table('colaboradores')->insertGetId($base + ['num_empleado' => 'R-101', 'nombre' => 'Ana', 'nombre_normalizado' => 'ana x']);
        $rb101 = DB::table('colaboradores')->insertGetId($base + ['num_empleado' => 'R-B101', 'nombre' => 'Bruno', 'nombre_normalizado' => 'bruno x']);
        $rSin = DB::table('colaboradores')->insertGetId($base + ['num_empleado' => 'R-999', 'nombre' => 'Ciro', 'nombre_normalizado' => 'ciro x']);
        $guardia = DB::table('colaboradores')->insertGetId($base + ['num_empleado' => 'G-1', 'nombre' => 'Gil', 'nombre_normalizado' => 'gil x']);
        DB::table('users')->where('id', $admin->id)->update(['colaborador_id' => $guardia]);
        $deHotel = DB::table('colaboradores')->insertGetId(['empresa_id' => $hotel->id, 'sede_id' => $sedeHotel->id, 'num_empleado' => '1'] + array_diff_key($base, ['empresa_id' => 1, 'sede_id' => 1]) + ['nombre' => 'Hugo', 'nombre_normalizado' => 'hugo x']);
        $acc = ['empresa_id' => $condo->id, 'sede_id' => $sede->id, 'movimiento' => 'entrada', 'estado' => 'en_sitio', 'num_acompanantes' => 0, 'entrada_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora];
        $proveedor = DB::table('accesos')->insertGetId($acc + ['tipo' => 'proveedor', 'nombre' => 'GAS', 'host_colaborador_id' => $r101]);
        $visita = DB::table('accesos')->insertGetId($acc + ['tipo' => 'visitante', 'nombre' => 'VISITA', 'habitacion' => '101', 'visita_colaborador_id' => $r101]);
        $checkEstado = Restricciones::valoresDelCheck('accesos', 'estado');
        foreach (self::MIGRACIONES as $m) {
            $this->migracion($m)->up();
        }
        // Las llaves se agregan sin reconstruir tablas: nada se pierde ni se sueltan las reglas (CHECK) de accesos
        $this->assertSame($guardia, (int) DB::table('users')->where('id', $admin->id)->value('colaborador_id'));
        $this->assertSame($r101, (int) DB::table('accesos')->where('id', $visita)->value('visita_colaborador_id'));
        $this->assertSame($checkEstado, Restricciones::valoresDelCheck('accesos', 'estado'));
        $col = fn (int $id) => DB::table('colaboradores')->where('id', $id)->first();
        $this->assertSame(['residente', $v['101']->id], [$col($r101)->tipo, (int) $col($r101)->espacio_id]);
        $this->assertSame(['residente', $v['B-101']->id], [$col($rb101)->tipo, (int) $col($rb101)->espacio_id], 'R-B101 se deduce como B-101');
        $this->assertSame(['residente', null], [$col($rSin)->tipo, $col($rSin)->espacio_id], 'Sin vivienda que coincida: se queda sin vivienda');
        $this->assertSame('personal', $col($guardia)->tipo, 'Quien tiene cuenta es personal');
        $this->assertSame('personal', $col($deHotel)->tipo, 'En hotel nada cambia');
        $this->assertSame($r101, (int) DB::table('accesos')->where('id', $proveedor)->value('visita_colaborador_id'), 'El host pasa a «a quién viene» sin perderse');
        $this->assertSame($r101, (int) DB::table('accesos')->where('id', $proveedor)->value('host_colaborador_id'));
        $this->assertSame($v['101']->id, (int) DB::table('accesos')->where('id', $visita)->value('espacio_id'), 'La habitación de texto se liga al catálogo');
        $t = json_decode((string) DB::table('rubros')->where('clave', 'condominio')->value('terminologia'), true);
        $this->assertSame(['Personal', 'Vivienda'], [$t['colaborador'], $t['area_especifica']]);

        // Y otra vez abajo y arriba (la del host): el host se queda en host_colaborador_id
        $this->migracion(self::MIGRACIONES[4])->down();
        $this->migracion(self::MIGRACIONES[3])->down();
        $this->migracion(self::MIGRACIONES[2])->down();
        $this->assertNull(DB::table('accesos')->where('id', $proveedor)->value('visita_colaborador_id'));
        $this->assertSame($r101, (int) DB::table('accesos')->where('id', $proveedor)->value('host_colaborador_id'));
        foreach (array_slice(self::MIGRACIONES, 2) as $m) {
            $this->migracion($m)->up();
        }
        $this->assertSame($r101, (int) DB::table('accesos')->where('id', $proveedor)->value('visita_colaborador_id'));
    }

    public function test_escribir_en_a_quien_viene_no_gasta_el_limite_de_las_demas_acciones(): void
    {
        [, $sede, $admin] = $this->empresaDeGiro('condominio');
        // Las búsquedas de «¿A quién viene?» y de lugares llevan su propio contador: 65 teclazos
        // no deben bloquear las acciones con límite de 60 por minuto (responder, verificar, coincidencias)
        for ($i = 0; $i < 65; $i++) {
            $this->actingAs($admin)->getJson('/lugares/buscar?sede_id='.$sede->id.'&q=1'.$i)->assertOk();
            $this->actingAs($admin)->getJson('/accesos/buscar?que=destino&q=a'.$i)->assertStatus(200);
        }
        $this->assertNotSame(429, $this->actingAs($admin)->getJson('/novedades/coincidencias?q=x')->getStatusCode());
    }

    // ============================================================ C-10 · Residentes

    public function test_residentes_tienen_alta_corta_con_vivienda_y_se_separan_del_personal(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin);

        // Menú: Padrones → Residentes; el directorio se llama Personal
        $inicio = $this->actingAs($admin)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(route('residentes.index'), $inicio);
        $this->actingAs($admin)->get('/residentes')->assertOk()->assertSee('Nuevo residente')->assertSee('Torre A › Piso 1 › 101');

        // Alta corta: nombre, apellidos, vivienda y teléfono; la sede sale de la vivienda
        $this->actingAs($admin)->post('/residentes', ['_dialogo' => 'crear', 'nombre' => 'lucía', 'apellido_paterno' => 'paz', 'espacio_id' => $v['101']->id, 'telefono' => '33 1234 5678'])
            ->assertRedirect(route('residentes.index'))->assertSessionHas('ok', fn ($m) => str_contains($m, '¿A quién viene?'));
        $lucia = $this->en($condo, fn () => Colaborador::where('nombre', 'Lucía')->firstOrFail());
        $this->assertSame([Colaborador::RESIDENTE, $sede->id, $v['101']->id, null], [$lucia->tipo, (int) $lucia->sede_id, (int) $lucia->espacio_id, $lucia->num_empleado]);
        // Sin vivienda no se registra; la misma persona en la misma vivienda tampoco
        $this->actingAs($admin)->post('/residentes', ['_dialogo' => 'crear', 'nombre' => 'Otro', 'apellido_paterno' => 'Más'])->assertSessionHasErrors('espacio_id');
        $this->actingAs($admin)->post('/residentes', ['_dialogo' => 'crear', 'nombre' => 'Lucía', 'apellido_paterno' => 'Paz', 'espacio_id' => $v['101']->id])->assertSessionHasErrors('nombre');
        // Editar: cambia de vivienda
        $this->actingAs($admin)->put(route('residentes.update', $lucia->id), ['nombre' => 'Lucía', 'apellido_paterno' => 'Paz', 'espacio_id' => $v['102']->id])->assertSessionHasNoErrors();
        $this->assertSame($v['102']->id, (int) $lucia->fresh()->espacio_id);

        // El directorio (Personal) no muestra residentes; Usuarios y Pases solo buscan al personal
        $this->en($condo, fn () => Colaborador::create(['num_empleado' => 'P-1', 'nombre' => 'Rogelio', 'apellido_paterno' => 'Paz', 'sede_id' => $sede->id]));
        $this->actingAs($admin)->get('/colaboradores')->assertOk()->assertSee('Rogelio')->assertDontSee('id="colaborador-'.$lucia->id.'"', false);
        $busqueda = $this->actingAs($admin)->getJson(route('colaboradores.buscar', ['q' => 'Paz']))->assertOk()->json('resultados');
        $this->assertSame(['Rogelio Paz'], array_column($busqueda, 'nombre_completo'));
        // Una cuenta no se vincula a un residente
        $this->actingAs($admin)->post('/usuarios', ['name' => 'Lucía', 'username' => 'lucia.paz', 'email' => 'lucia@x.test', 'password' => 'Segura.2026!x', 'password_confirmation' => 'Segura.2026!x',
            'colaborador_id' => $lucia->id, 'rol_id' => Rol::where('empresa_id', $condo->id)->where('nombre', 'Agente')->value('id')])->assertSessionHasErrors('colaborador_id');

        // Baja y reactivación
        $this->actingAs($admin)->patch(route('residentes.estado', $lucia->id), ['activo' => 0, 'revisado' => 1])->assertSessionHas('aviso');
        $this->assertFalse((bool) $lucia->fresh()->activo);

        // Configura tu empresa: «Personal de la administración» y «Residentes y viviendas»
        $pasos = app(AsistenteArranque::class)->avance($admin, $condo)['pasos'];
        $this->assertContains('Residentes y viviendas', array_column($pasos, 'titulo'));
        $this->assertContains('Personal de la administración', array_column($pasos, 'titulo'));

        // Hotel: no hay Residentes
        $hotel = $this->crearEmpresa('Hotel Res');
        $this->actingAs($this->crearUsuario($hotel, 'Administrador'))->get('/residentes')->assertNotFound();
    }

    public function test_terminos_personal_y_vivienda_sin_plural_roto(): void
    {
        $condo = Terminos::deRubro('condominio', Rubro::where('clave', 'condominio')->value('terminologia'));
        $this->assertSame(['Personal', 'Personal', 'Residente', 'Vivienda'], [$condo->colaborador(), $condo->colaboradores(), $condo->residente(), $condo->espacio()]);
        $this->assertSame('Alguien del personal entra; el personal y las viviendas', $condo->texto('Un colaborador entra; los colaboradores y las habitaciones'));
        $this->assertSame('Viv.', $condo->abreviaturaEspacio());
        // Datos de antes con «Residente» como palabra del colaborador: ya no nombra al personal
        $this->assertSame('Personal', Terminos::deRubro('condominio', ['colaborador' => 'Residente'])->colaborador());
        $this->assertSame('Colaborador', Terminos::deRubro('corporativo', [])->colaborador());
    }

    // ============================================================ C-09 · ¿A quién viene?

    public function test_condominio_visita_al_residente_con_llamada_y_constancia(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin);
        $alejandra = $this->residente($condo, $sede, $v['101']);
        $agente = $this->crearUsuario($condo, 'Agente', $sede);

        // El formulario: un solo motivo (marcado) y un solo campo
        $this->actingAs($agente)->get('/accesos')->assertOk()->assertSee('¿A quién viene?')->assertSee('No autorizó')->assertDontSee('Host — ¿Quién lo citó?');

        // Llamé y autorizó: entra con constancia; la vivienda sale sola del residente
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Daniel Romero', 'a_quien' => 'c:'.$alejandra->id, 'a_quien_texto' => 'ALEJANDRA', 'llamada' => 'autorizo'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'autorizó por teléfono'));
        $a = $this->ultimo($condo);
        $this->assertSame(['en_sitio', Acceso::POR_LLAMADA, $agente->id, 'colaborador', $alejandra->id, '101', $v['101']->id],
            [$a->estado, $a->autorizacion, (int) $a->autorizado_por, $a->motivo_visita, (int) $a->visita_colaborador_id, $a->habitacion, (int) $a->espacio_id]);
        $this->actingAs($agente)->get('/accesos')->assertSee('Autorizó por teléfono')->assertSee('Viv. 101');

        // No autorizó: no entra, no se le presta gafete y queda la constancia
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Pedro Nava', 'a_quien' => 'e:'.$v['101']->id, 'llamada' => 'no_autorizo'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'no autorizó'));
        $b = $this->ultimo($condo);
        $this->assertSame(['finalizado', Acceso::LLAMADA_NO, null, $alejandra->id], [$b->estado, $b->autorizacion, $b->gafete_id, (int) $b->visita_colaborador_id], 'Una vivienda con un solo residente se liga a él');
        $this->actingAs($agente)->get('/accesos?pestana=historial')->assertSee('Se llamó y no autorizó');

        // Una vivienda que no está en el catálogo: se queda el texto «sin catálogo»
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Rosa Gil', 'a_quien_texto' => 'PH-9', 'llamada' => 'autorizo'])->assertSessionHasNoErrors();
        $c = $this->ultimo($condo);
        $this->assertSame(['PH-9', null, Acceso::POR_LLAMADA], [$c->habitacion, $c->espacio_id, $c->autorizacion]);
        $this->actingAs($agente)->get('/accesos')->assertSee('Sin catálogo: no está en Zonas y edificios');

        // Servicio (gas) que viene con el residente: también se llama; sin respuesta registrada queda pendiente como antes
        $this->ingreso($agente, $sede, ['tipo' => 'proveedor', 'nombre' => 'Enrique Díaz', 'empresa_procedencia' => 'GAS DEL BAJIO', 'a_quien' => 'c:'.$alejandra->id, 'llamada' => 'autorizo'])
            ->assertSessionHasNoErrors();
        $d = $this->ultimo($condo);
        $this->assertSame(['en_sitio', Acceso::POR_LLAMADA, $alejandra->id, $alejandra->id, '101'], [$d->estado, $d->autorizacion, (int) $d->visita_colaborador_id, (int) $d->host_colaborador_id, $d->habitacion]);
        $this->ingreso($agente, $sede, ['tipo' => 'proveedor', 'nombre' => 'Otro Gas', 'empresa_procedencia' => 'GAS DEL BAJIO', 'host_colaborador_id' => $alejandra->id])->assertSessionHasNoErrors();
        $this->assertSame('pendiente', $this->ultimo($condo)->estado);

        // Sugerencias: el residente (con teléfono, se llama) y la vivienda con quien vive ahí
        $r = $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'visita', 'q' => '101', 'sede' => $sede->id]))->assertOk()->json('resultados');
        $vivienda = collect($r)->firstWhere('clave', 'e:'.$v['101']->id);
        $this->assertNotNull($vivienda);
        $this->assertTrue($vivienda['llamar']);
        $this->assertSame('3310001010', $vivienda['telefono']);
        $r = $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'visita', 'q' => 'Alejandra', 'sede' => $sede->id]))->json('resultados');
        $this->assertSame(['residente', true, false], [$r[0]['tipo'], $r[0]['llamar'], $r[0]['cuenta']]);

        // Informes: «Viviendas más visitadas» en lugar de departamentos
        $this->actingAs($admin)->get(route('tendencias.index'))->assertOk()->assertSee('Viviendas más visitadas');
    }

    public function test_la_persona_citada_con_cuenta_autoriza_y_su_departamento_es_el_suplente(): void
    {
        [$corp, $sede, $admin] = $this->empresaDeGiro('corporativo', ['visitas_requieren_autorizacion' => true]);
        $agente = $this->crearUsuario($corp, 'Agente', $sede);
        $jefe = $this->crearUsuario($corp, 'Jefe de departamento', $sede);
        [$finanzas, $persona] = $this->en($corp, function () use ($sede, $jefe) {
            $d = Departamento::create(['nombre' => 'Finanzas']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $jefe->id, 'sede_id' => null, 'es_suplente' => false]);

            return [$d, Colaborador::create(['num_empleado' => 'C-1', 'nombre' => 'Sergio', 'apellido_paterno' => 'Luna', 'sede_id' => $sede->id, 'departamento_id' => $d->id])];
        });
        $sergio = $this->crearUsuario($corp, 'Solicitante', $sede);
        $sergio->forceFill(['colaborador_id' => $persona->id])->save();

        // Sugerencia: la persona trae su departamento y que tiene cuenta (el botón anticipa la espera)
        $r = $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'visita', 'q' => 'Sergio', 'sede' => $sede->id]))->json('resultados');
        $this->assertSame(['c:'.$persona->id, true, $finanzas->id, false], [$r[0]['clave'], $r[0]['cuenta'], $r[0]['departamento_id'], $r[0]['llamar']]);

        // La visita espera a Sergio (no a Finanzas)
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Ana Cliente', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id, 'a_quien_texto' => 'SERGIO LUNA'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'espera la autorización de Sergio Luna'));
        $acceso = $this->ultimo($corp);
        $this->assertSame(['pendiente', 'esperando', $finanzas->id], [$acceso->estado, $acceso->autorizacion, (int) $acceso->departamento_id]);
        $aut = $this->en($corp, fn () => Autorizacion::where('acceso_id', $acceso->id)->firstOrFail());
        $this->assertSame([$persona->id, [$sergio->id]], [(int) $aut->colaborador_id, $aut->avisados]);
        $this->assertSame([$sergio->id], Notificacion::withoutGlobalScopes()->where('tipo', 'autorizacion_visita')->where('referencia_id', $aut->id)->pluck('user_id')->all());
        // La caseta ve a quién espera; Mis pendientes de Sergio tiene el renglón
        $this->actingAs($agente)->get('/accesos?pestana=pendientes')->assertSee('Espera a: Sergio Luna (suplente: Finanzas)');
        $this->olvidar();
        $mis = app(MisPendientes::class)->para($sergio);
        $this->assertSame(1, collect($mis['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? null);
        // Si no contesta, pasa al responsable de Finanzas
        $this->assertContains($jefe->id, $this->en($corp, fn () => app(Escalamiento::class)->aQuienEscalar($aut->fresh()))->pluck('id')->all());
        // Sergio la ve y la autoriza aunque no tenga la bandeja de Autorizaciones
        $this->actingAs($sergio)->get(route('autorizaciones.show', $aut->id))->assertOk()->assertSee('Autorizar ingreso');
        $this->actingAs($sergio)->post(route('autorizaciones.responder', $aut->id), ['respuesta' => 'autorizar'])->assertRedirect(route('autorizaciones.show', $aut->id));
        $this->assertSame('en_sitio', $acceso->fresh()->estado);
        // Otra persona con cuenta no la puede responder
        $otro = $this->crearUsuario($corp, 'Solicitante', $sede);
        $this->actingAs($otro)->get(route('autorizaciones.show', $aut->id))->assertForbidden();

        // Un área: espera a su responsable, como antes, y el motivo queda «departamento»
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Beto Área', 'motivo_visita' => 'colaborador', 'a_quien' => 'd:'.$finanzas->id])->assertSessionHasNoErrors();
        $b = $this->ultimo($corp);
        $this->assertSame(['departamento', $finanzas->id, 'esperando'], [$b->motivo_visita, (int) $b->departamento_id, $b->autorizacion]);
        $this->assertNull($this->en($corp, fn () => Autorizacion::where('acceso_id', $b->id)->value('colaborador_id')));

        // Proveedor que cita Sergio: le pide a él (aunque los proveedores no esperen al departamento) y el departamento se deduce
        $this->ingreso($agente, $sede, ['tipo' => 'proveedor', 'nombre' => 'Técnico', 'empresa_procedencia' => 'COPIADORAS SA', 'a_quien' => 'c:'.$persona->id])->assertSessionHasNoErrors();
        $p = $this->ultimo($corp);
        $this->assertSame([$finanzas->id, $persona->id, 'esperando'], [(int) $p->departamento_id, (int) $p->visita_colaborador_id, $p->autorizacion]);
        $this->assertSame($persona->id, (int) $this->en($corp, fn () => Autorizacion::where('acceso_id', $p->id)->value('colaborador_id')));

        // Sin autorización de visitas: entra directo y al visitado se le avisa
        $corp->refresh()->forceFill(['preferencias' => array_replace_recursive($corp->preferencias, ['recepcion' => ['visitas_requieren_autorizacion' => false]])])->save();
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Carla Directa', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id])->assertSessionHasNoErrors();
        $this->assertSame('en_sitio', $this->ultimo($corp)->estado);
        $this->assertSame([$sergio->id], Notificacion::withoutGlobalScopes()->where('tipo', 'visita_llego')->pluck('user_id')->all());

        // Sin elegir de la lista (en corporativo) no se adivina
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Sin Elegir', 'motivo_visita' => 'colaborador', 'a_quien_texto' => 'alguien'])->assertSessionHasErrors('a_quien');
    }

    public function test_hotel_visita_a_huesped_se_llama_a_la_habitacion_y_la_habitacion_sale_del_catalogo(): void
    {
        $hotel = $this->crearEmpresa('Hotel Huésped');
        $hotel->forceFill(['preferencias' => ['recepcion' => ['visitas_requieren_autorizacion' => false]]])->save();
        $sede = $this->crearSede($hotel, 'H1');
        $admin = $this->crearUsuario($hotel, 'Administrador');
        $agente = $this->crearUsuario($hotel, 'Agente', $sede);
        $v = $this->viviendas($hotel, $sede, $admin, ['204', '305']);

        // El huésped llega: la habitación se elige del catálogo
        $this->ingreso($agente, $sede, ['tipo' => 'huesped', 'nombre' => 'Leticia Vázquez', 'habitacion_id' => $v['204']->id, 'tiene_reserva' => '1'])->assertSessionHasNoErrors();
        $huesped = $this->ultimo($hotel);
        $this->assertSame(['204', $v['204']->id], [$huesped->habitacion, (int) $huesped->espacio_id]);
        // Escrita a mano, también se liga si coincide; si no, queda «sin catálogo»
        $this->ingreso($agente, $sede, ['tipo' => 'huesped', 'nombre' => 'Mario Paz', 'habitacion' => '305', 'tiene_reserva' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($v['305']->id, (int) $this->ultimo($hotel)->espacio_id);
        $this->ingreso($agente, $sede, ['tipo' => 'huesped', 'nombre' => 'Nora Gil', 'habitacion' => 'VILLA 9', 'tiene_reserva' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($this->ultimo($hotel)->espacio_id);

        // Visita a huésped: el huésped en sitio con su habitación; «Llamé a la habitación» deja constancia
        $r = $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'huesped', 'q' => 'Leticia', 'sede' => $sede->id]))->json('resultados');
        $this->assertSame('h:'.$huesped->id, $r[0]['clave']);
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Amigo', 'motivo_visita' => 'huesped', 'a_quien' => 'h:'.$huesped->id, 'llamada' => 'autorizo'])->assertSessionHasNoErrors();
        $vis = $this->ultimo($hotel);
        $this->assertSame(['LETICIA VÁZQUEZ', '204', $v['204']->id, Acceso::POR_LLAMADA], [$vis->persona_visita, $vis->habitacion, (int) $vis->espacio_id, $vis->autorizacion]);

        // C-30: el área de trabajo del proveedor sale del árbol
        $this->ingreso($agente, $sede, ['tipo' => 'proveedor', 'nombre' => 'Plomero', 'empresa_procedencia' => 'PLOMERÍA', 'a_quien_texto' => '', 'host_colaborador_id' => '',
            'departamento_id' => $this->en($hotel, fn () => Departamento::create(['nombre' => 'Mantenimiento']))->id, 'area_espacio_id' => $v['305']->id])->assertSessionHasNoErrors();
        $pro = $this->ultimo($hotel);
        $this->assertSame(['TORRE A › PISO 1 › 305', $v['305']->id], [$pro->area_trabajo, (int) $pro->espacio_id]);
        // Lugares para cualquier formulario que captura un lugar
        $l = $this->actingAs($agente)->getJson(route('lugares.buscar', ['q' => '30', 'sede' => $sede->id]))->assertOk()->json('resultados');
        $this->assertSame('Torre A › Piso 1 › 305', $l[0]['ruta']);
        $this->actingAs($this->crearUsuario($hotel))->getJson(route('lugares.buscar', ['q' => '30']))->assertForbidden();
    }

    // ============================================================ C-28 · Canalizar a Mantenimiento

    public function test_un_incidente_se_canaliza_a_mantenimiento_que_ve_solo_su_ticket_con_reloj(): void
    {
        $empresa = $this->crearEmpresa('Hotel Mantto');
        $sede = $this->crearSede($empresa, 'H1');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $agente = $this->crearUsuario($empresa, 'Agente', $sede);
        $jefa = $this->crearUsuario($empresa, 'Jefe de departamento', $sede);
        $otroJefe = $this->crearUsuario($empresa, 'Jefe de departamento', $sede);
        $v = $this->viviendas($empresa, $sede, $admin, ['204']);
        $mantto = $this->en($empresa, function () use ($jefa) {
            $d = Departamento::create(['nombre' => 'Mantenimiento']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $jefa->id, 'sede_id' => null, 'es_suplente' => false]);

            return $d;
        });

        // Despachar con lugar del árbol (C-30) y canalizar a Mantenimiento (C-28)
        $this->actingAs($agente)->post('/novedades', ['_dialogo' => 'crear', 'sede_id' => $sede->id, 'categoria' => 'incidente_general', 'reportado_por' => 'Guardia',
            'lugar_id' => $v['204']->id, 'ubicacion' => 'baño', 'descripcion' => 'Fuga de agua en el baño', 'departamento_id' => $mantto->id])->assertSessionHasNoErrors();
        $n = $this->en($empresa, fn () => Novedad::orderByDesc('id')->firstOrFail());
        $this->assertSame([$mantto->id, 'TORRE A › PISO 1 › 204 · BAÑO', $v['204']->id], [(int) $n->departamento_id, $n->ubicacion, (int) $n->area_especifica_id]);
        $this->assertNotNull($n->departamento_en);
        $this->assertSame([$jefa->id], Notificacion::withoutGlobalScopes()->where('tipo', 'novedad_departamento')->where('referencia_id', $n->id)->pluck('user_id')->all());

        // Reloj de la Etapa 4 y Mis pendientes de la jefa de Mantenimiento
        $reloj = app(MotorRelojes::class)->reloj('novedades_departamento');
        $this->assertCount(1, $this->en($empresa, fn () => $reloj->esperas($empresa->id)));
        $this->olvidar();
        $mis = $this->en($empresa, fn () => app(MisPendientes::class)->para($jefa));
        $this->assertSame(1, collect($mis['items'])->firstWhere('clave', 'novedades_departamento')['total'] ?? null);

        // Solo su ticket: lo ve, anota y atiende; otro jefe sin el departamento no entra
        $this->actingAs($jefa)->get(route('novedades.departamento.index'))->assertOk()->assertSee($n->folio())->assertSee('POR ATENDER');
        $this->actingAs($jefa)->get(route('novedades.departamento.show', $n->id))->assertOk()->assertSee('Fuga de agua en el baño')->assertSee('Canalizado a Mantenimiento');
        $this->actingAs($otroJefe)->get(route('novedades.departamento.index'))->assertForbidden();
        $this->actingAs($otroJefe)->get(route('novedades.departamento.show', $n->id))->assertNotFound();
        $this->actingAs($jefa)->post(route('novedades.departamento.nota', $n->id), ['nota' => 'Voy en camino'])->assertSessionHasNoErrors();
        $this->assertTrue($this->en($empresa, fn () => $n->notas()->where('texto', 'like', '%[MANTENIMIENTO] Voy en camino%')->exists()));
        $this->actingAs($jefa)->post(route('novedades.departamento.atender', $n->id), ['nota' => 'Se cambió el empaque'])->assertRedirect(route('novedades.departamento.index'));
        $n->refresh();
        $this->assertSame($jefa->id, (int) $n->departamento_atendido_por);
        $this->assertSame([$agente->id], Notificacion::withoutGlobalScopes()->where('tipo', 'novedad_departamento_atendida')->pluck('user_id')->all());
        $this->assertCount(0, $this->en($empresa, fn () => $reloj->esperas($empresa->id)));
        // Seguridad lo ve en el expediente; el lugar sin catálogo también se acepta
        $this->actingAs($admin)->get(route('novedades.index', ['abrir' => $n->id]))->assertOk()->assertSee('Mantenimiento atendió su parte', false);
        $this->actingAs($agente)->post('/novedades', ['_dialogo' => 'crear', 'sede_id' => $sede->id, 'categoria' => 'incidente_general', 'reportado_por' => 'Guardia',
            'lugar_texto' => 'Estacionamiento norte', 'descripcion' => 'Luminaria'])->assertSessionHasNoErrors();
        $this->actingAs($agente)->post('/novedades', ['_dialogo' => 'crear', 'sede_id' => $sede->id, 'categoria' => 'incidente_general', 'reportado_por' => 'Guardia',
            'descripcion' => 'Sin lugar'])->assertSessionHasErrors('ubicacion');
        // Un departamento dado de baja ya no se ofrece
        $this->en($empresa, fn () => $mantto->forceFill(['activo' => false])->save());
        $this->actingAs($agente)->post('/novedades', ['_dialogo' => 'crear', 'sede_id' => $sede->id, 'categoria' => 'incidente_general', 'reportado_por' => 'Guardia',
            'ubicacion' => 'Lobby', 'descripcion' => 'Otra', 'departamento_id' => $mantto->id])->assertSessionHasErrors('departamento_id');
    }

    public function test_canalizar_desde_el_expediente_cambia_de_departamento_y_reinicia_su_reloj(): void
    {
        $empresa = $this->crearEmpresa('Hotel Expediente');
        $sede = $this->crearSede($empresa, 'H1');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $jefa = $this->crearUsuario($empresa, 'Jefe de departamento', $sede);
        [$mantto, $ama, $n] = $this->en($empresa, function () use ($sede, $jefa, $admin) {
            $this->actingAs($admin);
            $m = Departamento::create(['nombre' => 'Mantenimiento']);
            $a = Departamento::create(['nombre' => 'Ama de llaves']);
            DepartamentoResponsable::create(['departamento_id' => $a->id, 'user_id' => $jefa->id, 'sede_id' => null, 'es_suplente' => false]);
            $n = app(AdministradorNovedades::class)->crear($admin, ['sede_id' => $sede->id, 'categoria' => 'incidente_general',
                'reportado_por' => 'Guardia', 'ubicacion' => 'Lobby', 'descripcion' => 'Derrame', 'departamento_id' => $m->id]);

            return [$m, $a, $n];
        });
        $this->assertSame($mantto->id, (int) $n->departamento_id);
        // En el expediente se cambia a Ama de llaves: le llega a su responsable y el reloj empieza de nuevo
        $this->travel(2)->hours();
        $this->en($empresa, fn () => app(AdministradorNovedades::class)->actualizar($admin, $n->fresh(),
            ['categoria' => 'incidente_general', 'estatus' => Novedad::ABIERTO, 'departamento_id' => $ama->id]));
        $n->refresh();
        $this->assertSame($ama->id, (int) $n->departamento_id);
        $this->assertTrue($n->departamento_en->greaterThan(now()->subMinute()));
        $this->assertSame([$jefa->id], Notificacion::withoutGlobalScopes()->where('tipo', 'novedad_departamento')->pluck('user_id')->all());
        $this->assertTrue($this->en($empresa, fn () => $n->notas()->where('texto', 'Canalizado a Ama de llaves.')->exists()));
        // Quitarlo: ya no espera a nadie
        $this->en($empresa, fn () => app(AdministradorNovedades::class)->actualizar($admin, $n->fresh(),
            ['categoria' => 'incidente_general', 'estatus' => Novedad::ABIERTO, 'departamento_id' => '']));
        $this->assertNull($n->fresh()->departamento_id);
    }

    public function test_accidente_de_colaborador_avisa_a_rrhh_de_la_sede_sin_tocar_el_formato(): void
    {
        $empresa = $this->crearEmpresa('Hotel RH');
        $sede = $this->crearSede($empresa, 'H1');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $rh = $this->crearUsuario($empresa, 'Jefe de departamento', $sede);
        $n = $this->en($empresa, function () use ($sede, $rh, $admin) {
            $d = Departamento::create(['nombre' => 'Recursos Humanos']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $rh->id, 'sede_id' => null, 'es_suplente' => false]);
            $n = new Novedad(['sede_id' => $sede->id, 'categoria' => 'accidente', 'reportado_por' => 'GUARDIA', 'ubicacion' => 'COCINA', 'descripcion' => 'Corte']);
            $n->numero = 1;
            $n->save();
            app(CanalizarDepartamento::class)->accidenteDeColaborador($n, $admin);

            return $n->fresh();
        });
        $this->assertSame('Recursos Humanos', $this->en($empresa, fn () => $n->departamento?->nombre));
        $this->assertSame([$rh->id], Notificacion::withoutGlobalScopes()->where('tipo', 'novedad_departamento')->pluck('user_id')->all());
    }

    // ============================================================ C-34 · Reservas por vivienda

    public function test_reservas_en_condominio_son_de_un_residente_y_su_vivienda_y_validan_departamentos(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 15:00:00', 'UTC'));
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin, ['101', 'Salón']);
        $res = $this->residente($condo, $sede, $v['101']);
        [$area, $limpieza, $baja] = $this->en($condo, function () use ($admin, $condo, $v) {
            $this->actingAs($admin);
            app(AjustesReservas::class)->guardar($admin, $condo->refresh(), ['responsable_id' => $admin->id, 'suplente_id' => null, 'recordar_horas' => 4, 'escalar_horas' => 24]);
            $cat = app(CatalogosReservas::class);
            $cat->asegurar();
            $area = $cat->marcarReservable($admin, $v['Salón']);
            $cat->guardarArea($admin, $area, ['aforo_maximo' => 40, 'confirmacion_inmediata' => '1']);
            $area->forceFill(['confirmacion_inmediata' => true])->save();
            $limpieza = Departamento::create(['nombre' => 'Limpieza']); // sin responsable
            $baja = Departamento::create(['nombre' => 'Viejo']);
            $baja->forceFill(['activo' => false])->save();
            ReservaServicio::create(['nombre' => 'Limpieza final', 'categoria' => 'otro', 'unidad' => 'evento', 'departamento_id' => $limpieza->id, 'requiere_aprobacion' => false, 'activo' => true]);
            ReservaServicio::create(['nombre' => 'Mesas', 'categoria' => 'otro', 'unidad' => 'pieza', 'departamento_id' => null, 'requiere_aprobacion' => false, 'activo' => true]);

            return [$area, $limpieza, $baja];
        });

        // El formulario dice «Para un residente» y deja montaje en Avanzado
        $this->actingAs($admin)->get(route('reservas.create'))->assertOk()->assertSee('Para un residente')->assertSee('Alejandra Fuentes · Vivienda 101')->assertDontSee('Para un cliente de la sede');
        $base = ['reserva_area_id' => $area->id, 'fecha' => '2026-11-05', 'hora_inicio' => '18:00', 'hora_fin' => '20:00', 'titulo' => 'Cumpleaños', 'asistentes' => 20, 'para_cliente' => '0'];
        $reserva = $this->en($condo, fn () => app(AdministradorReservas::class)->crear($admin, $base + ['para_residente' => '1', 'residente_id' => $res->id,
            'servicios' => [ReservaServicio::where('nombre', 'Limpieza final')->value('id') => ['usar' => '1']]]));
        $this->assertSame([$res->id, $v['101']->id, false], [(int) $reserva->residente_id, (int) $reserva->vivienda_id, (bool) $reserva->para_cliente]);
        $this->actingAs($admin)->get(route('reservas.show', $reserva->id))->assertSee('Residente: Alejandra Fuentes · Vivienda 101');
        // Sin residente elegido no se guarda; uso de la administración sí
        $this->expectValidacion(fn () => $this->en($condo, fn () => app(AdministradorReservas::class)->crear($admin, ['fecha' => '2026-11-06'] + $base + ['para_residente' => '1', 'residente_id' => ''])), 'residente_id');
        $interna = $this->en($condo, fn () => app(AdministradorReservas::class)->crear($admin, ['fecha' => '2026-11-06'] + $base + ['para_residente' => '0', 'residente_id' => '']));
        $this->assertNull($interna->residente_id);
        // Departamento dado de baja: no
        $this->expectValidacion(fn () => $this->en($condo, fn () => app(AdministradorReservas::class)->crear($admin, ['fecha' => '2026-11-07', 'departamento_solicitante_id' => $baja->id] + $base)), 'departamento_solicitante_id');

        $admin2 = $this->crearUsuario($condo, 'Administrador'); // otra persona que configura las reservas
        // Se aprueba y nace la orden de Limpieza; sin responsable de Limpieza ni responsable general, nadie la atiende
        $this->en($condo, function () use ($admin, $reserva, $condo) {
            app(AjustesReservas::class)->guardar($admin, $condo->refresh(), ['responsable_id' => null, 'suplente_id' => null, 'recordar_horas' => 4, 'escalar_horas' => 24]);
            foreach (ReservaAprobacion::where('reserva_id', $reserva->id)->where('estado', 'pendiente')->get() as $ap) {
                app(FlujoReservas::class)->responder($admin, $ap, 'aprobar', null);
            }
        });
        // La orden de Limpieza no tiene quien la atienda: se marca y se avisa a quien configura
        $orden = $this->en($condo, fn () => ReservaOrden::where('reserva_id', $reserva->id)->first());
        $this->assertNotNull($orden);
        $this->assertTrue((bool) $orden->sin_quien_atienda);
        $this->assertSame([$admin2->id], Notificacion::withoutGlobalScopes()->where('tipo', 'reserva_orden_sin_quien')->pluck('user_id')->all());
        $this->actingAs($admin)->get(route('reservas.ordenes'))->assertOk()->assertSee('Sin quién la atienda');
        // Configuración avisa de los servicios sin departamento
        $this->actingAs($admin)->get(route('reservas.configuracion'))->assertOk()->assertSee('un departamento activo que reciba su orden')->assertSee('Mesas');
        // ARCO encuentra al residente y su reserva
        $hallazgos = $this->en($condo, fn () => app(DatosDePersona::class)->buscar(['nombre' => 'Alejandra Fuentes']));
        $this->assertStringContainsString('Residente', $hallazgos['colaborador'][0]['detalle']);
        $this->assertContains($reserva->id, array_column($hallazgos['reserva'], 'id'));
    }

    private function expectValidacion(callable $fn, string $campo): void
    {
        try {
            $fn();
            $this->fail("Se esperaba un error en «{$campo}»");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());
        }
    }

    // ============================================================ Demo de fraccionamiento

    public function test_el_demo_de_fraccionamiento_tiene_casas_residentes_personal_y_mantenimiento(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful()->expectsOutputToContain('Fraccionamiento Demo');
        $frac = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA_FRACCIONAMIENTO)->firstOrFail();
        $this->assertSame('fraccionamiento', $frac->rubro->clave);
        foreach (['frac.admin.demo', 'frac.jefe.demo', 'frac.agente.demo'] as $u) {
            $this->assertSame($frac->id, User::where('username', $u)->value('empresa_id'));
        }
        $this->en($frac, function () {
            $this->assertSame(4, Colaborador::residentes()->whereNotNull('espacio_id')->count());
            $this->assertSame(2, Colaborador::personal()->count());
            $this->assertTrue(DepartamentoResponsable::where('departamento_id', Departamento::where('nombre', 'Mantenimiento')->value('id'))->exists());
            $this->assertSame(3, Acceso::count());
        });
        $condo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA_CONDOMINIO)->firstOrFail();
        $this->en($condo, fn () => $this->assertSame(5, Colaborador::residentes()->whereNotNull('espacio_id')->count()));
        $agente = User::where('username', 'frac.agente.demo')->firstOrFail();
        $this->actingAs($agente)->get('/accesos')->assertOk()->assertSee('Casa 3')->assertSee('Autorizó por teléfono');
        $this->actingAs(User::where('username', 'frac.admin.demo')->firstOrFail())->get('/residentes')->assertOk()->assertSee('Calle Robles › Manzana 1 › 1');
        // Repetible: no duplica
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->en($frac, fn () => $this->assertSame(3, Acceso::count()));
    }
}
