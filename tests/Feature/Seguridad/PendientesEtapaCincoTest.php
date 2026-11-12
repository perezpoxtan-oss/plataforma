<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\PaseSalida;
use App\Models\Puesto;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\Limitadores;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Pendientes de la revisión de la Etapa 5 (PR #92). Cada prueba falla sin su
 * corrección:
 *  - A-2 la conversión de residentes no hace residente a nadie solo por no
 *    tener cuenta; lo dudoso se queda como Personal «por confirmar» y el
 *    down() conserva la vivienda de los residentes nuevos;
 *  - A-3 Pases de salida encuentra a los residentes por nombre y los acepta
 *    como solicitante (residente o personal, como el receptor general);
 *  - A-4 el área común no se nombra «Vivienda» en la tarjeta de caseta, en el
 *    título de Zonas y edificios ni en la caja de llamada;
 *  - A-5 la tarjeta de caseta dice «Espera a: X (delegó en Y)»;
 *  - A-6 «Restablecer valor original» de la bitácora regresa también el
 *    departamento que recibe los accidentes;
 *  - A-8 cada ruta con límite lleva su propio limitador con nombre.
 */
class PendientesEtapaCincoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIGRACION_RESIDENTES = '2026_11_14_000100_residentes_y_viviendas';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    // ------------------------------------------------------------------ Ayudas

    /** @return array{0: Empresa, 1: Sede, 2: User} */
    private function empresaDeGiro(string $rubro, array $recepcion = []): array
    {
        $empresa = app(ProvisionarEmpresa::class)->crear(Rubro::where('clave', $rubro)->firstOrFail(), ['nombre_comercial' => 'Giro '.$rubro.' '.uniqid()]);
        $empresa->forceFill(['preferencias' => ['recepcion' => $recepcion + ['rh_autoriza_paso' => false, 'visitas_requieren_autorizacion' => false,
            'proveedores_requieren_autorizacion' => false, 'contratistas_requieren_autorizacion' => false]]])->save();
        $sede = $this->crearSede($empresa, 'S1');

        return [$empresa, $sede, $this->crearUsuario($empresa, 'Administrador')];
    }

    private function en(Empresa $empresa, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($empresa->id, $fn);
    }

    private function olvidar(): void
    {
        app(Autorizador::class)->olvidar();
        $this->app->forgetScopedInstances();
        Cache::flush();
    }

    /** Torre A › Planta baja › 101, 102, Alberca (área común). @return array<string, Espacio> */
    private function viviendas(Empresa $empresa, Sede $sede, User $admin): array
    {
        return $this->en($empresa, function () use ($sede, $admin) {
            $this->actingAs($admin);
            $esp = app(AdministradorEspacios::class);
            $torre = $esp->crear($admin, $sede, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $piso = $esp->crear($admin, $sede, $torre, Espacio::AREA, ['nombre' => 'Planta baja'], false);
            $esp->crearLote($admin, $piso, ['101', '102', 'Alberca'], null, null);
            Espacio::where('nombre', 'Alberca')->update(['uso' => Espacio::USO_AREA_COMUN]);

            return Espacio::where('nivel', Espacio::AREA_ESPECIFICA)->get()->keyBy('nombre')->all();
        });
    }

    private function migracion(): object
    {
        return require database_path('migrations/'.self::MIGRACION_RESIDENTES.'.php');
    }

    private function ingreso(User $actor, Sede $sede, array $datos): TestResponse
    {
        return $this->actingAs($actor)->post('/accesos', $datos + ['sede_id' => $sede->id, '_dialogo' => 'ingreso', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
    }

    // ============================================ A-2 · Conversión de residentes

    public function test_a2_solo_es_residente_quien_tiene_vivienda_o_puesto_y_lo_dudoso_queda_por_confirmar(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin);
        $this->migracion()->down();
        $this->assertFalse(Schema::hasColumn('colaboradores', 'tipo'));

        // Datos de antes: todos en el directorio; solo el guardia tiene cuenta
        [$propietario, $jardineria, $mantto] = $this->en($condo, fn () => [
            Puesto::create(['nombre' => 'Propietario']), Puesto::create(['nombre' => 'Jardinero']), Departamento::create(['nombre' => 'Mantenimiento']),
        ]);
        $ahora = now();
        $base = ['empresa_id' => $condo->id, 'sede_id' => $sede->id, 'apellido_paterno' => 'X', 'activo' => true, 'provisional' => false,
            'nacionalidad' => 'Mexicana', 'created_at' => $ahora, 'updated_at' => $ahora];
        $nuevo = fn (string $num, string $nombre, array $extra = []) => DB::table('colaboradores')->insertGetId($base + $extra
            + ['num_empleado' => $num, 'nombre' => $nombre, 'nombre_normalizado' => mb_strtolower($nombre).' x']);
        $ana = $nuevo('R-101', 'Ana');                                                  // su número coincide con una vivienda
        $pablo = $nuevo('P-7', 'Pablo', ['puesto_id' => $propietario->id]);             // su puesto dice que vive ahí
        $ciro = $nuevo('R-999', 'Ciro');                                                // número de residente sin vivienda: dudoso
        $sinNada = $nuevo('X-5', 'Saúl');                                               // sin puesto ni departamento: dudoso
        $jardinero = $nuevo('J-1', 'Julio', ['puesto_id' => $jardineria->id, 'departamento_id' => $mantto->id]); // personal sin cuenta
        $guardia = $nuevo('G-1', 'Gil');
        DB::table('users')->where('id', $admin->id)->update(['colaborador_id' => $guardia]);

        $this->migracion()->up();
        $fila = fn (int $id) => DB::table('colaboradores')->where('id', $id)->first(['tipo', 'espacio_id', 'tipo_por_confirmar']);
        $this->assertSame(['residente', $v['101']->id, false], [$fila($ana)->tipo, (int) $fila($ana)->espacio_id, (bool) $fila($ana)->tipo_por_confirmar]);
        $this->assertSame(['residente', null], [$fila($pablo)->tipo, $fila($pablo)->espacio_id], 'Puesto de residente: residente; la vivienda la completa el administrador');
        $this->assertSame(['personal', true], [$fila($ciro)->tipo, (bool) $fila($ciro)->tipo_por_confirmar], 'No tener cuenta no basta: se queda como Personal por confirmar');
        $this->assertSame(['personal', true], [$fila($sinNada)->tipo, (bool) $fila($sinNada)->tipo_por_confirmar]);
        $this->assertSame(['personal', false], [$fila($jardinero)->tipo, (bool) $fila($jardinero)->tipo_por_confirmar], 'El personal sin cuenta sigue en Personal');
        $this->assertSame(['personal', false], [$fila($guardia)->tipo, (bool) $fila($guardia)->tipo_por_confirmar]);

        // El administrador lo ve en Residentes («Por confirmar») y en Personal (aviso)
        DB::table('users')->where('id', $admin->id)->update(['colaborador_id' => null]);
        $this->olvidar();
        $this->actingAs($admin)->get(route('residentes.index'))->assertOk()
            ->assertSee('Por confirmar: ¿residente o personal? (2)')->assertSee('Ciro X')->assertSee('Saúl X')->assertDontSee('Julio X')
            ->assertSee('Residente · Torre A › Planta baja › 102')->assertDontSee('Residente · Torre A › Planta baja › Alberca');
        $this->actingAs($admin)->get(route('colaboradores.index'))->assertOk()->assertSee('Confirmar en Residentes');

        // Confirmar: Ciro es residente de la 102; Saúl es personal
        $this->actingAs($admin)->put(route('residentes.confirmar', $ciro), ['como' => (string) $v['102']->id])->assertSessionHasNoErrors()->assertRedirect(route('residentes.index'));
        $this->actingAs($admin)->put(route('residentes.confirmar', $sinNada), ['como' => 'personal'])->assertSessionHasNoErrors();
        $this->assertSame(['residente', $v['102']->id, false], [$fila($ciro)->tipo, (int) $fila($ciro)->espacio_id, (bool) $fila($ciro)->tipo_por_confirmar]);
        $this->assertSame(['personal', false], [$fila($sinNada)->tipo, (bool) $fila($sinNada)->tipo_por_confirmar]);
        $this->assertTrue(DB::table('auditoria')->where('evento', 'colaboradores.actualizado')->where('auditable_id', $ciro)->exists());
        // Un área común no es vivienda; y ya confirmado, no se vuelve a tocar (404)
        $this->actingAs($admin)->put(route('residentes.confirmar', $jardinero), ['como' => 'personal'])->assertNotFound();
        $this->actingAs($admin)->get(route('residentes.index'))->assertDontSee('Por confirmar: ¿residente o personal?');

        // Otra empresa: 404 sin revelar
        DB::table('colaboradores')->where('id', $jardinero)->update(['tipo_por_confirmar' => true]);
        [, , $adminOtro] = $this->empresaDeGiro('condominio');
        $this->actingAs($adminOtro)->put(route('residentes.confirmar', $jardinero), ['como' => 'personal'])->assertNotFound();
        $this->actingAs($admin)->put(route('residentes.confirmar', $jardinero), ['como' => (string) $v['Alberca']->id])->assertSessionHasErrors('como');
        $this->assertTrue((bool) $fila($jardinero)->tipo_por_confirmar);
    }

    public function test_a2_el_down_conserva_la_vivienda_de_los_residentes_nuevos_y_lo_confirmado(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin);
        // Alta con la versión nueva: sin número de empleado (la deducción no la encontraría)
        $this->actingAs($admin)->post('/residentes', ['_dialogo' => 'crear', 'nombre' => 'Rosa', 'apellido_paterno' => 'Paz', 'espacio_id' => $v['102']->id])
            ->assertSessionHasNoErrors();
        $rosa = (int) DB::table('colaboradores')->where('nombre', 'Rosa')->value('id');
        // Alguien que el administrador ya confirmó como personal (sin puesto ni departamento: la deducción lo volvería a marcar)
        $saul = $this->en($condo, fn () => Colaborador::create(['num_empleado' => 'X-5', 'nombre' => 'Saúl', 'apellido_paterno' => 'Y', 'sede_id' => $sede->id]))->id;
        $hotel = $this->crearEmpresa('Hotel Respaldo');
        $deHotel = $this->en($hotel, fn () => Colaborador::create(['num_empleado' => 'H-1', 'nombre' => 'Hugo', 'apellido_paterno' => 'Z']))->id;

        $this->migracion()->down();
        $this->assertTrue(Schema::hasTable('respaldo_residentes'), 'El down() guarda el tipo y la vivienda');
        $this->assertSame($v['102']->id, (int) DB::table('respaldo_residentes')->where('colaborador_id', $rosa)->value('espacio_id'));
        $this->assertFalse(DB::table('respaldo_residentes')->where('colaborador_id', $deHotel)->exists(), 'Solo las empresas residenciales');

        $this->migracion()->up();
        $fila = fn (int $id) => DB::table('colaboradores')->where('id', $id)->first(['tipo', 'espacio_id', 'tipo_por_confirmar']);
        $this->assertSame(['residente', $v['102']->id], [$fila($rosa)->tipo, (int) $fila($rosa)->espacio_id], 'La vivienda del residente nuevo no se pierde');
        $this->assertSame(['personal', false], [$fila($saul)->tipo, (bool) $fila($saul)->tipo_por_confirmar], 'Lo confirmado gana sobre la deducción');
        $this->assertSame('personal', $fila($deHotel)->tipo);
        $this->assertFalse(Schema::hasTable('respaldo_residentes'), 'El up() restaura y borra el respaldo');

        // Una vivienda borrada mientras tanto no deja una llave rota
        $this->migracion()->down();
        DB::table('colaboradores')->where('id', $rosa)->update(['updated_at' => now()]);
        DB::table('espacios')->where('id', $v['102']->id)->delete();
        $this->migracion()->up();
        $this->assertSame(['residente', null], [$fila($rosa)->tipo, $fila($rosa)->espacio_id]);
    }

    // ============================================ A-3 · Pases de salida

    public function test_a3_pases_encuentra_al_residente_por_nombre_y_lo_acepta_como_solicitante(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $v = $this->viviendas($condo, $sede, $admin);
        $destino = $this->crearSede($condo, 'S2');
        $alejandra = $this->en($condo, fn () => Colaborador::create(['tipo' => Colaborador::RESIDENTE, 'nombre' => 'Alejandra', 'apellido_paterno' => 'Fuentes',
            'sede_id' => $sede->id, 'espacio_id' => $v['101']->id]));

        // Pases busca residentes y personal (lo mismo que encuentra el lector al escanear); Usuarios, solo personal
        $r = $this->actingAs($admin)->getJson(route('colaboradores.buscar', ['q' => 'Alejandra', 'residentes' => 1]))->assertOk()->json('resultados');
        $this->assertSame([[$alejandra->id, true, '101']], array_map(fn ($c) => [$c['id'], $c['residente'], $c['vivienda']], $r));
        $this->assertSame([], $this->actingAs($admin)->getJson(route('colaboradores.buscar', ['q' => 'Alejandra']))->json('resultados'));

        // El formulario lo dice y pide la búsqueda con residentes
        $this->actingAs($admin)->get(route('pases-salida.index'))->assertOk()
            ->assertSee('Solicitante: residente o personal (escanea su gafete o busca su nombre)')
            ->assertSee(route('colaboradores.buscar', ['residentes' => 1]), false);

        // Un residente solicita el pase (receptor general: residente o personal)
        $this->actingAs($admin)->post('/pases-salida', ['sede_id' => $sede->id, 'motivo' => 'prestamo', 'colaborador_id' => $alejandra->id,
            'destino_tipo' => 'sede', 'sede_destino_id' => $destino->id, 'articulos' => [['cantidad' => 1, 'equipo' => 'Podadora']]])
            ->assertSessionHasNoErrors();
        $pase = $this->en($condo, fn () => PaseSalida::orderByDesc('id')->firstOrFail());
        $this->assertSame($alejandra->id, (int) $pase->colaborador_id);
        // y se encuentra por su nombre en la lista de pases
        $this->actingAs($admin)->get(route('pases-salida.index', ['q' => 'Alejandra']))->assertOk()->assertSee($pase->folio);

        // En hotel no cambia nada
        $hotel = $this->crearEmpresa('Hotel Pases');
        $this->crearSede($hotel, 'H1');
        $this->actingAs($this->crearUsuario($hotel, 'Administrador'))->get(route('pases-salida.index'))->assertOk()
            ->assertSee('Solicitante (escanea su gafete o busca su nombre)')->assertDontSee('Solicitante: residente o personal');
    }

    // ============================================ A-4 · Área común no es «Vivienda»

    public function test_a4_el_area_comun_no_se_llama_vivienda_en_la_tarjeta_zonas_y_edificios_ni_la_llamada(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $agente = $this->crearUsuario($condo, 'Agente', $sede);
        $v = $this->viviendas($condo, $sede, $admin);

        // Caja de llamada: la sugerencia avisa que es un área común
        $r = collect($this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'visita', 'q' => 'Alberca', 'sede' => $sede->id]))
            ->assertOk()->json('resultados'))->firstWhere('clave', 'e:'.$v['Alberca']->id);
        $this->assertSame(['Área común Alberca', true], [$r['titulo'], $r['area_comun']]);

        // Registro con llamada: la constancia dice «Área común», no «Vivienda»
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Nadia Visita', 'motivo_visita' => 'colaborador', 'a_quien' => 'e:'.$v['Alberca']->id, 'llamada' => 'autorizo'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains(mb_strtolower($m), 'área común alberca') && ! str_contains(mb_strtolower($m), 'vivienda'));
        $acceso = $this->en($condo, fn () => Acceso::orderByDesc('id')->firstOrFail());
        $this->assertSame($v['Alberca']->id, (int) $acceso->espacio_id);

        // Tarjeta de caseta (servidor y la que pinta el JS)
        $html = $this->actingAs($agente)->get(route('accesos.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/badge-hab[^>]*>\s*Área común Alberca/iu', $html);
        $this->assertDoesNotMatchRegularExpression('/badge-hab[^>]*>\s*Viv\. Alberca/iu', $html);
        $resumen = $this->en($condo, fn () => app(ConsultaAccesos::class)->resumen($acceso->fresh()));
        $this->assertSame(['Área común', 'Área común'], [$resumen['etiqueta_espacio'], $resumen['nombre_espacio']]);
        // Una vivienda sigue siendo vivienda
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Otra Visita', 'motivo_visita' => 'colaborador', 'a_quien' => 'e:'.$v['101']->id, 'llamada' => 'autorizo'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Viv.', $this->en($condo, fn () => app(ConsultaAccesos::class)->resumen(Acceso::orderByDesc('id')->firstOrFail())['etiqueta_espacio']));

        // Zonas y edificios: el título del nivel
        $this->actingAs($admin)->get(route('espacios.show', $v['Alberca']->id))->assertOk()
            ->assertSee('<h1>Área común Alberca', false)->assertSee('<small>Área común</small>Alberca', false)->assertDontSee('<h1>Vivienda Alberca', false);
        $this->actingAs($admin)->get(route('espacios.show', $v['101']->id))->assertOk()->assertSee('<h1>Vivienda 101', false);
    }

    // ============================================ A-5 · «Espera a: X (delegó en Y)»

    public function test_a5_la_tarjeta_de_caseta_dice_en_quien_delego_la_persona_citada(): void
    {
        [$corp, $sede] = $this->empresaDeGiro('corporativo', ['visitas_requieren_autorizacion' => true]);
        $agente = $this->crearUsuario($corp, 'Agente', $sede);
        $jefe = $this->crearUsuario($corp, 'Jefe de departamento', $sede);
        $persona = $this->en($corp, function () use ($sede, $jefe) {
            $d = Departamento::create(['nombre' => 'Finanzas']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $jefe->id, 'sede_id' => null, 'es_suplente' => false]);

            return Colaborador::create(['num_empleado' => 'C-1', 'nombre' => 'Sergio', 'apellido_paterno' => 'Luna', 'sede_id' => $sede->id, 'departamento_id' => $d->id]);
        });
        $sergio = $this->crearUsuario($corp, 'Jefe de departamento', $sede);
        $sergio->forceFill(['colaborador_id' => $persona->id])->save();
        $laura = $this->crearUsuario($corp, 'Jefe de departamento', $sede);
        $laura->forceFill(['name' => 'Laura Delegada'])->save();

        // Sin delegar: «Espera a: Sergio Luna (suplente: Finanzas)»
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Ana Cliente', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id])->assertSessionHasNoErrors();
        $primero = $this->en($corp, fn () => Acceso::orderByDesc('id')->firstOrFail());
        $this->assertSame('Espera a: Sergio Luna (suplente: Finanzas)', $this->en($corp, fn () => app(Escalamiento::class)->esperaDe($primero))['texto']);

        // Delegó en Laura: el aviso le llega a ella y la tarjeta lo dice
        $this->en($corp, fn () => Delegacion::create(['user_id' => $sergio->id, 'delegado_id' => $laura->id, 'desde' => now()->subHour(), 'hasta' => now()->addDay(), 'motivo' => 'Vacaciones']));
        $this->olvidar();
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Beto Cliente', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id])->assertSessionHasNoErrors();
        $acceso = $this->en($corp, fn () => Acceso::orderByDesc('id')->firstOrFail());
        $aut = $this->en($corp, fn () => Autorizacion::where('acceso_id', $acceso->id)->firstOrFail());
        $this->assertSame([$laura->id], $aut->avisados);
        $texto = $this->en($corp, fn () => app(Escalamiento::class)->esperaDe($acceso))['texto'];
        $this->assertSame('Espera a: Sergio Luna (delegó en Laura Delegada) · suplente: Finanzas', $texto);
        $this->actingAs($agente)->get(route('accesos.index', ['pestana' => 'pendientes']))->assertOk()->assertSee('Espera a: Sergio Luna (delegó en Laura Delegada)');
    }

    // ============================================ A-6 · Restablecer valor original

    public function test_a6_restablecer_la_bitacora_regresa_el_departamento_de_accidentes_de_fabrica(): void
    {
        $empresa = $this->crearEmpresa('Hotel Restablecer');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        [$gente, $rh] = $this->en($empresa, fn () => [Departamento::create(['nombre' => 'Gente y Cultura']), Departamento::create(['nombre' => 'Recursos Humanos'])]);
        $ajustes = app(AjustesNovedades::class);
        $this->actingAs($admin)->put(route('configuracion.novedades'), ['nombre_opcion' => 'Incidentes', 'categoria_hallazgo_pc' => 'proteccion_civil',
            'departamento_accidentes' => $gente->id])->assertSessionHasNoErrors();
        $this->assertSame($gente->id, $ajustes->departamentoAccidentes($empresa->refresh()));

        $this->actingAs($admin)->put(route('configuracion.restablecer'), ['ajuste' => 'novedades'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'el departamento que recibe los accidentes de personal'));
        $empresa->refresh();
        $this->assertArrayNotHasKey('departamento_accidentes', $empresa->preferencias['novedades'] ?? []);
        $this->assertSame($rh->id, $ajustes->departamentoAccidentes($empresa), 'Vuelve el de fábrica: el de Recursos Humanos');

        // También desde «Ninguno»
        $this->actingAs($admin)->put(route('configuracion.novedades'), ['nombre_opcion' => 'Novedades', 'categoria_hallazgo_pc' => 'proteccion_civil',
            'departamento_accidentes' => '0'])->assertSessionHasNoErrors();
        $this->assertNull($ajustes->departamentoAccidentes($empresa->refresh()));
        $this->actingAs($admin)->put(route('configuracion.restablecer'), ['ajuste' => 'novedades'])->assertSessionHasNoErrors();
        $this->assertSame($rh->id, $ajustes->departamentoAccidentes($empresa->refresh()));
        $this->actingAs($admin)->get(route('configuracion.tema', 'novedades'))->assertOk()
            ->assertSee('el departamento que recibe los accidentes de personal');
    }

    // ============================================ A-8 · Un limitador con nombre por ruta

    public function test_a8_ninguna_ruta_usa_un_limite_sin_nombre_ni_comparte_el_de_otra(): void
    {
        $porLimitador = [];
        foreach (Route::getRoutes() as $ruta) {
            foreach ($ruta->gatherMiddleware() as $m) {
                if (! is_string($m) || ! str_starts_with($m, 'throttle:')) {
                    continue;
                }
                $nombre = explode(',', substr($m, 9))[0];
                $this->assertDoesNotMatchRegularExpression('/^\d+$/', $nombre, "La ruta «{$ruta->getName()}» usa un límite sin nombre ({$m}): todas las rutas lo comparten.");
                $this->assertNotNull(RateLimiter::limiter($nombre), "El limitador «{$nombre}» de la ruta «{$ruta->getName()}» no está registrado.");
                $porLimitador[$nombre][] = $ruta->getName();
            }
        }
        // Los de Limitadores son uno por ruta, y se llaman como ella
        foreach (array_keys(Limitadores::POR_MINUTO) as $nombre) {
            $this->assertSame([$nombre], $porLimitador[$nombre] ?? [], "El limitador «{$nombre}» debe ser de una sola ruta, la del mismo nombre.");
        }
        $this->assertGreaterThanOrEqual(60, count(Limitadores::POR_MINUTO));
    }

    public function test_a8_agotar_un_limite_no_bloquea_las_demas_acciones(): void
    {
        $empresa = $this->crearEmpresa('Hotel Limites');
        $sede = $this->crearSede($empresa, 'H1');
        $agente = $this->crearUsuario($empresa, 'Agente', $sede);
        // «Quién recibe» (120 por minuto) se agota…
        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($agente)->getJson(route('receptores.buscar', ['tipo' => 'persona', 'q' => 'x'.$i]))->assertSuccessful();
        }
        $this->actingAs($agente)->getJson(route('receptores.buscar', ['tipo' => 'persona', 'q' => 'otra']))->assertStatus(429);
        // …y el lector, las coincidencias y el estado de la caseta (antes con el mismo contador) siguen
        $this->actingAs($agente)->getJson(route('lector.resolver', ['entrada' => 'ABC', 'tipos' => 'llave']))->assertSuccessful();
        $this->assertNotSame(429, $this->actingAs($agente)->getJson(route('accesos.autorizaciones-estado'))->getStatusCode());
        $this->assertNotSame(429, $this->actingAs($agente)->getJson(route('notificaciones.resumen'))->getStatusCode());
        // Sin sesión, por IP y por ruta: agotar «Olvidé mi contraseña» no bloquea pedírselo al administrador
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('contrasena.solicitar'), ['cuenta' => 'nadie'.$i]);
        }
        $this->post(route('contrasena.solicitar'), ['cuenta' => 'nadie9'])->assertStatus(429);
        $this->assertNotSame(429, $this->post(route('contrasena.administrador'), ['cuenta' => 'nadie'])->getStatusCode());
    }
}
