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
use App\Models\Notificacion;
use App\Models\Novedad;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Services\Accesos\AQuienViene;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Novedades\CanalizarDepartamento;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Services\Reservas\CatalogosReservas;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Revisión independiente de la Etapa 5 (PR #92). Cada prueba falla sin su
 * corrección:
 *  - R5-01 la persona citada que delegó sigue la cadena de la Etapa 3 (C-12);
 *  - R5-02 el Accidente de un colaborador va al departamento configurado
 *    («Departamento que recibe accidentes de personal»), no por su nombre,
 *    y nunca por un residente;
 *  - R5-03 las áreas comunes del condominio no se llaman «Vivienda»;
 *  - R5-04 el residente como receptor (préstamo y responsivas) usa el mismo
 *    receptor general, con su nombre en el selector;
 *  - seguridad: la persona citada solo ve y responde lo suyo, el tenant de
 *    /residentes y de «Tickets de mi departamento», y el límite de lugares.
 */
class RevisionEtapaCincoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

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

    private function ingreso(User $actor, Sede $sede, array $datos): TestResponse
    {
        return $this->actingAs($actor)->post('/accesos', $datos + ['sede_id' => $sede->id, '_dialogo' => 'ingreso', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
    }

    private function ultimo(Empresa $empresa): Acceso
    {
        return $this->en($empresa, fn () => Acceso::orderByDesc('id')->firstOrFail());
    }

    private function delegar(Empresa $empresa, User $titular, User $delegado): void
    {
        $this->en($empresa, fn () => Delegacion::create(['user_id' => $titular->id, 'delegado_id' => $delegado->id,
            'desde' => now()->subHour(), 'hasta' => now()->addDay(), 'motivo' => 'Vacaciones']));
        $this->olvidar();
    }

    /**
     * Corporativo con Finanzas (responsable: $jefe) y Sergio, del personal, con cuenta.
     *
     * @return array{0: Empresa, 1: Sede, 2: User, 3: User, 4: Departamento, 5: Colaborador, 6: User}
     */
    private function corporativoConPersonaCitada(): array
    {
        [$corp, $sede] = $this->empresaDeGiro('corporativo', ['visitas_requieren_autorizacion' => true]);
        $agente = $this->crearUsuario($corp, 'Agente', $sede);
        $jefe = $this->crearUsuario($corp, 'Jefe de departamento', $sede);
        [$finanzas, $persona] = $this->en($corp, function () use ($sede, $jefe) {
            $d = Departamento::create(['nombre' => 'Finanzas']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $jefe->id, 'sede_id' => null, 'es_suplente' => false]);

            return [$d, Colaborador::create(['num_empleado' => 'C-1', 'nombre' => 'Sergio', 'apellido_paterno' => 'Luna', 'sede_id' => $sede->id, 'departamento_id' => $d->id])];
        });
        $sergio = $this->crearUsuario($corp, 'Jefe de departamento', $sede);
        $sergio->forceFill(['colaborador_id' => $persona->id])->save();

        return [$corp, $sede, $agente, $jefe, $finanzas, $persona, $sergio];
    }

    // ============================================ R5-01 · Delegación de la persona citada

    public function test_la_persona_citada_que_delego_sigue_la_cadena_y_su_departamento_es_suplente(): void
    {
        [$corp, $sede, $agente, $jefe, $finanzas, $persona, $sergio] = $this->corporativoConPersonaCitada();
        $laura = $this->crearUsuario($corp, 'Jefe de departamento', $sede);   // puede responder en la sede
        $pedro = $this->crearUsuario($corp, 'Solicitante', $sede);            // no puede responder
        // Sergio → Laura → Pedro: le llega al último de la cadena que pueda responder (Laura)
        $this->delegar($corp, $sergio, $laura);
        $this->delegar($corp, $laura, $pedro);

        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Ana Cliente', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id])
            ->assertSessionHasNoErrors();
        $acceso = $this->ultimo($corp);
        $aut = $this->en($corp, fn () => Autorizacion::where('acceso_id', $acceso->id)->firstOrFail());
        $this->assertSame($persona->id, (int) $aut->colaborador_id, 'Sigue siendo la visita de Sergio');
        $this->assertSame([$laura->id], $aut->avisados, 'El aviso sigue la cadena de delegación (la misma regla de la Etapa 3)');
        $this->assertSame([$laura->id], Notificacion::withoutGlobalScopes()->where('tipo', 'autorizacion_visita')->where('referencia_id', $aut->id)->pluck('user_id')->all());

        // Laura la ve en su bandeja y en Mis pendientes, y la puede responder; Pedro no
        $this->olvidar();
        $mis = $this->en($corp, fn () => app(MisPendientes::class)->para($laura));
        $this->assertSame(1, collect($mis['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? null);
        $this->assertStringContainsStringIgnoringCase('Ana Cliente', $this->actingAs($laura)->get(route('autorizaciones.index'))->assertOk()->getContent());
        $this->actingAs($laura)->get(route('autorizaciones.show', $aut->id))->assertOk()->assertSee('Autorizar ingreso');
        $this->actingAs($pedro)->get(route('autorizaciones.show', $aut->id))->assertForbidden();
        // Sergio («No molestar»): ya no le espera a él, pero puede responderla si quiere
        $this->olvidar();
        $misSergio = $this->en($corp, fn () => app(MisPendientes::class)->para($sergio));
        $this->assertSame(0, collect($misSergio['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? 0);
        // Si Laura no contesta, pasa a Finanzas (el suplente)
        $this->assertContains($jefe->id, $this->en($corp, fn () => app(Escalamiento::class)->aQuienEscalar($aut->fresh()))->pluck('id')->all());

        $this->actingAs($laura)->post(route('autorizaciones.responder', $aut->id), ['respuesta' => 'autorizar'])->assertSessionHasNoErrors();
        $this->assertSame('en_sitio', $acceso->fresh()->estado);
    }

    public function test_si_nadie_de_la_cadena_puede_responder_el_aviso_se_queda_con_la_persona_citada(): void
    {
        [$corp, $sede, $agente, , , $persona, $sergio] = $this->corporativoConPersonaCitada();
        $pedro = $this->crearUsuario($corp, 'Solicitante', $sede);
        $this->delegar($corp, $sergio, $pedro);

        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Beto Cliente', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id])
            ->assertSessionHasNoErrors();
        $aut = $this->en($corp, fn () => Autorizacion::where('acceso_id', $this->ultimo($corp)->id)->firstOrFail());
        $this->assertSame([$sergio->id], $aut->avisados);
    }

    // ============================================ R5-02 · Accidente → departamento configurado

    public function test_el_accidente_de_personal_va_al_departamento_configurado_y_no_depende_del_nombre(): void
    {
        $empresa = $this->crearEmpresa('Hotel Gente');
        $sede = $this->crearSede($empresa, 'H1');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $responsable = $this->crearUsuario($empresa, 'Jefe de departamento', $sede);
        [$gente, $rh] = $this->en($empresa, function () use ($responsable) {
            $gente = Departamento::create(['nombre' => 'Gente y Cultura']); // no se parece a «Recursos Humanos»
            $rh = Departamento::create(['nombre' => 'Recursos Humanos']);
            foreach ([$gente, $rh] as $d) {
                DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $responsable->id, 'sede_id' => null, 'es_suplente' => false]);
            }

            return [$gente, $rh];
        });
        $ajustes = app(AjustesNovedades::class);
        // De fábrica: el que se llama como Recursos Humanos (y se muestra así en Configuración)
        $this->assertSame($rh->id, $this->en($empresa, fn () => $ajustes->departamentoAccidentes($empresa->refresh())));
        $this->actingAs($admin)->get(route('configuracion.index'))->assertOk()->assertSee('Departamento que recibe accidentes de personal');

        // Configuración: «Gente y Cultura»
        $this->actingAs($admin)->put(route('configuracion.novedades'), ['nombre_opcion' => 'Novedades', 'categoria_hallazgo_pc' => 'proteccion_civil',
            'departamento_accidentes' => $gente->id])->assertSessionHasNoErrors();
        $this->assertSame($gente->id, $this->en($empresa, fn () => $ajustes->departamentoAccidentes($empresa->refresh())));
        $n = $this->accidente($empresa, $sede, $admin);
        $this->assertSame($gente->id, (int) $n->departamento_id);

        // Renombrar el departamento no cambia nada
        $this->en($empresa, fn () => $gente->forceFill(['nombre' => 'Talento'])->save());
        $this->assertSame($gente->id, (int) $this->accidente($empresa, $sede, $admin)->departamento_id);

        // «Ninguno»: el accidente no se canaliza solo
        $this->actingAs($admin)->put(route('configuracion.novedades'), ['nombre_opcion' => 'Novedades', 'categoria_hallazgo_pc' => 'proteccion_civil',
            'departamento_accidentes' => '0'])->assertSessionHasNoErrors();
        $this->assertNull($this->accidente($empresa, $sede, $admin)->departamento_id);

        // Un departamento de otra empresa no se acepta
        $otra = $this->crearEmpresa('Otra');
        $ajeno = $this->en($otra, fn () => Departamento::create(['nombre' => 'Ajeno']));
        $this->actingAs($admin)->put(route('configuracion.novedades'), ['nombre_opcion' => 'Novedades', 'categoria_hallazgo_pc' => 'proteccion_civil',
            'departamento_accidentes' => $ajeno->id])->assertSessionHasErrors('departamento_accidentes');
    }

    public function test_la_migracion_fija_el_departamento_de_fabrica_y_un_residente_no_va_a_rrhh(): void
    {
        $empresa = $this->crearEmpresa('Hotel Fijo');
        $sede = $this->crearSede($empresa, 'H1');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $responsable = $this->crearUsuario($empresa, 'Jefe de departamento', $sede);
        $rh = $this->en($empresa, function () use ($responsable) {
            $rh = Departamento::create(['nombre' => 'RR. HH.']);
            DepartamentoResponsable::create(['departamento_id' => $rh->id, 'user_id' => $responsable->id, 'sede_id' => null, 'es_suplente' => false]);

            return $rh;
        });
        $migracion = require database_path('migrations/2026_11_15_000100_revision_etapa_cinco.php');
        $migracion->down();
        $migracion->up();
        $this->assertSame($rh->id, (int) ($empresa->refresh()->preferencias['novedades']['departamento_accidentes'] ?? 0), 'La migración deja el valor de fábrica guardado');
        // Ya no depende del nombre
        $this->en($empresa, fn () => $rh->forceFill(['nombre' => 'Personas'])->save());
        $this->assertSame($rh->id, (int) $this->accidente($empresa, $sede, $admin)->departamento_id);

        // Condominio: el accidente de un residente (elegido en el mismo formato) no es de personal
        [$condo, $sedeC, $adminC] = $this->empresaDeGiro('condominio');
        $respC = $this->crearUsuario($condo, 'Jefe de departamento', $sedeC);
        $this->en($condo, function () use ($respC) {
            $d = Departamento::create(['nombre' => 'Recursos Humanos']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $respC->id, 'sede_id' => null, 'es_suplente' => false]);
        });
        $vecino = $this->en($condo, fn () => Colaborador::create(['tipo' => Colaborador::RESIDENTE, 'nombre' => 'Vecino', 'apellido_paterno' => 'Uno', 'sede_id' => $sedeC->id]));
        $this->assertNull($this->accidente($condo, $sedeC, $adminC, $vecino)->departamento_id);
    }

    private function accidente(Empresa $empresa, Sede $sede, User $actor, ?Colaborador $afectado = null): Novedad
    {
        return $this->en($empresa, function () use ($sede, $actor, $afectado) {
            $n = new Novedad(['sede_id' => $sede->id, 'categoria' => 'accidente', 'reportado_por' => 'GUARDIA', 'ubicacion' => 'COCINA', 'descripcion' => 'Corte']);
            $n->numero = (int) Novedad::max('numero') + 1;
            $n->save();
            app(CanalizarDepartamento::class)->accidenteDeColaborador($n, $actor, $afectado?->id);

            return $n->fresh();
        });
    }

    // ============================================ R5-03 · Vivienda y área común

    public function test_las_areas_comunes_del_condominio_no_se_llaman_vivienda(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        $agente = $this->crearUsuario($condo, 'Agente', $sede);
        $e = $this->en($condo, function () use ($admin, $sede) {
            $this->actingAs($admin);
            $esp = app(AdministradorEspacios::class);
            $torre = $esp->crear($admin, $sede, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $piso = $esp->crear($admin, $sede, $torre, Espacio::AREA, ['nombre' => 'Planta baja'], false);
            $esp->crearLote($admin, $piso, ['101', 'Alberca', 'Salón de fiestas'], null, null);

            return Espacio::where('nivel', Espacio::AREA_ESPECIFICA)->get()->keyBy('nombre')->all();
        });

        // La migración clasifica lo que ya existía: sin número y sin residentes → área común
        $migracion = require database_path('migrations/2026_11_15_000100_revision_etapa_cinco.php');
        $migracion->down();
        $migracion->up();
        $uso = fn (Espacio $x) => DB::table('espacios')->where('id', $x->id)->value('uso');
        $this->assertSame([null, Espacio::USO_AREA_COMUN, Espacio::USO_AREA_COMUN], [$uso($e['101']), $uso($e['Alberca']), $uso($e['Salón de fiestas'])]);

        // «¿A quién viene?»: «Vivienda 101» y «Área común Alberca»
        $r = $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'visita', 'q' => 'Alberca', 'sede' => $sede->id]))->assertOk()->json('resultados');
        $titulos = array_column($r, 'titulo');
        $this->assertContains('Área común Alberca', $titulos);
        $this->assertNotContains('Vivienda Alberca', $titulos);
        $r = $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'contexto' => 'visita', 'q' => '101', 'sede' => $sede->id]))->json('resultados');
        $this->assertContains('Vivienda 101', array_column($r, 'titulo'));
        $this->assertSame('Área común Alberca', $this->en($condo, fn () => app(AQuienViene::class)->titulo('e:'.$e['Alberca']->id, Terminos::actual())));

        // Residentes: solo viviendas (ni en la lista ni al guardar)
        $this->actingAs($admin)->get('/residentes')->assertOk()->assertSee('Torre A › Planta baja › 101')->assertDontSee('Torre A › Planta baja › Alberca');
        $this->actingAs($admin)->post('/residentes', ['_dialogo' => 'crear', 'nombre' => 'Rosa', 'apellido_paterno' => 'Paz', 'espacio_id' => $e['Alberca']->id])
            ->assertSessionHasErrors('espacio_id');

        // Zonas y edificios: se marca o desmarca en la edición; reservable = área común (coherente con Reservas)
        $this->actingAs($admin)->put(route('espacios.update', $e['101']->id), ['nombre' => '101', 'area_comun' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(Espacio::USO_AREA_COMUN, $uso($e['101']));
        $this->actingAs($admin)->put(route('espacios.update', $e['101']->id), ['nombre' => '101', 'area_comun' => '0'])->assertSessionHasNoErrors();
        $this->assertNull($uso($e['101']));
        $this->en($condo, function () use ($e, $admin) {
            DB::table('espacios')->where('id', $e['Salón de fiestas']->id)->update(['uso' => null]);
            $cat = app(CatalogosReservas::class);
            $cat->asegurar();
            $cat->marcarReservable($admin, Espacio::find($e['Salón de fiestas']->id));
        });
        $this->assertSame(Espacio::USO_AREA_COMUN, $uso($e['Salón de fiestas']));
        $this->actingAs($admin)->get(route('espacios.show', $e['Alberca']->padre_id))->assertOk()->assertSee('Área común');

        // En hotel la casilla no existe y nada cambia
        $hotel = $this->crearEmpresa('Hotel Uso');
        $this->assertFalse(Terminos::deRubro('hotel', [])->esResidencial());
        $this->assertSame('Habitación', Terminos::deRubro('hotel', [])->nombreLugar(null));
        $this->assertNotNull($hotel);
    }

    // ============================================ R5-04 · Residente como receptor (C-16)

    public function test_el_residente_se_entrega_con_el_mismo_receptor_general_y_se_nombra_asi(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        // Préstamo de llaves y Responsivas: el receptor de siempre, con el nombre que incluye al residente
        $this->actingAs($admin)->get(route('prestamo_llaves.index'))->assertOk()->assertSee('Residente o personal')
            ->assertSee('Escanea su gafete o escribe su nombre: residente o personal.');
        $this->actingAs($admin)->get(route('responsivas.index'))->assertOk()->assertSee('Residente o personal');
        // Hotel y corporativo no cambian
        $hotel = $this->crearEmpresa('Hotel Receptor');
        $this->actingAs($this->crearUsuario($hotel, 'Administrador'))->get(route('prestamo_llaves.index'))->assertOk()->assertDontSee('Residente o personal');
    }

    // ============================================ Seguridad

    public function test_la_persona_citada_solo_ve_y_responde_lo_suyo_dentro_de_su_empresa(): void
    {
        [$corp, $sede, $agente, $jefe, $finanzas, $persona] = $this->corporativoConPersonaCitada();
        $sinRol = $this->crearUsuario($corp); // cuenta sin rol, ligada a la persona citada
        User::where('colaborador_id', $persona->id)->update(['colaborador_id' => null]);
        $sinRol->forceFill(['colaborador_id' => $persona->id])->save();
        $otro = $this->en($corp, fn () => Colaborador::create(['num_empleado' => 'C-2', 'nombre' => 'Marta', 'apellido_paterno' => 'Ríos', 'sede_id' => $sede->id, 'departamento_id' => $finanzas->id]));

        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Para Sergio', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$persona->id])->assertSessionHasNoErrors();
        $suya = $this->en($corp, fn () => Autorizacion::where('acceso_id', $this->ultimo($corp)->id)->firstOrFail());
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Para Finanzas', 'motivo_visita' => 'colaborador', 'a_quien' => 'd:'.$finanzas->id])->assertSessionHasNoErrors();
        $deArea = $this->en($corp, fn () => Autorizacion::where('acceso_id', $this->ultimo($corp)->id)->firstOrFail());
        $this->ingreso($agente, $sede, ['tipo' => 'visitante', 'nombre' => 'Para Marta', 'motivo_visita' => 'colaborador', 'a_quien' => 'c:'.$otro->id])->assertSessionHasNoErrors();

        // La bandeja no es suya (no tiene el permiso); su visita sí la abre, y en Mis pendientes solo cuenta la suya
        $this->actingAs($sinRol)->get(route('autorizaciones.index'))->assertForbidden();
        $this->actingAs($sinRol)->get(route('autorizaciones.show', $suya->id))->assertOk()->assertSee('Autorizar ingreso');
        $this->olvidar();
        $mis = $this->en($corp, fn () => app(MisPendientes::class)->para($sinRol));
        $this->assertSame(1, collect($mis['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? null);
        // No ve ni responde la del área
        $this->actingAs($sinRol)->get(route('autorizaciones.show', $deArea->id))->assertNotFound();
        $this->actingAs($sinRol)->post(route('autorizaciones.responder', $deArea->id), ['respuesta' => 'autorizar']);
        $this->assertSame('pendiente', $deArea->fresh()->estado);
        // Ni la caseta ni los accesos: no tiene permiso de la bitácora
        $this->actingAs($sinRol)->get('/accesos')->assertForbidden();

        // Una persona citada de otra empresa (con su cuenta) no la ve ni la responde
        // (una cuenta no puede ligarse a la persona de otra: users.colaborador_id es único)
        $otraEmpresa = $this->crearEmpresa('Ajena');
        $ajena = $this->crearUsuario($otraEmpresa);
        $suPersona = $this->en($otraEmpresa, fn () => Colaborador::create(['num_empleado' => 'A-1', 'nombre' => 'Ajeno', 'apellido_paterno' => 'Uno', 'sede_id' => $this->crearSede($otraEmpresa, 'A1')->id]));
        $ajena->forceFill(['colaborador_id' => $suPersona->id])->save();
        $this->actingAs($ajena)->get(route('autorizaciones.show', $suya->id))->assertNotFound();
        $this->actingAs($ajena)->post(route('autorizaciones.responder', $suya->id), ['respuesta' => 'autorizar']);
        $this->assertSame('pendiente', $suya->fresh()->estado);

        // Dada de baja: ya no responde
        $sinRol->forceFill(['activo' => false])->save();
        $this->assertFalse($this->en($corp, fn () => app(Autorizaciones::class)->puedeResponder($sinRol->fresh(), $suya->fresh())));
    }

    public function test_tenant_en_residentes_tickets_de_departamento_y_el_limite_de_lugares(): void
    {
        [$condo, $sede, $admin] = $this->empresaDeGiro('condominio');
        [$otro, $sedeOtro, $adminOtro] = $this->empresaDeGiro('condominio');
        $residente = $this->en($condo, fn () => Colaborador::create(['tipo' => Colaborador::RESIDENTE, 'nombre' => 'Rita', 'apellido_paterno' => 'Mar', 'sede_id' => $sede->id]));
        $this->actingAs($adminOtro)->put(route('residentes.update', $residente->id), ['nombre' => 'X', 'apellido_paterno' => 'Y'])->assertNotFound();
        $this->actingAs($adminOtro)->patch(route('residentes.estado', $residente->id), ['activo' => 0, 'revisado' => 1])->assertNotFound();
        $this->actingAs($adminOtro)->get('/residentes')->assertOk()->assertDontSee('Rita');
        $this->assertTrue((bool) $residente->fresh()->activo);

        // Tickets de mi departamento: solo los de sus departamentos y de su empresa
        $jefa = $this->crearUsuario($condo, 'Jefe de departamento', $sede);
        $jefaOtra = $this->crearUsuario($otro, 'Jefe de departamento', $sedeOtro);
        [$mantto, $limpieza] = $this->en($condo, function () use ($jefa) {
            $m = Departamento::create(['nombre' => 'Mantenimiento']);
            $l = Departamento::create(['nombre' => 'Limpieza']);
            DepartamentoResponsable::create(['departamento_id' => $m->id, 'user_id' => $jefa->id, 'sede_id' => null, 'es_suplente' => false]);

            return [$m, $l];
        });
        $this->en($otro, function () use ($jefaOtra) {
            $d = Departamento::create(['nombre' => 'Mantenimiento']);
            DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $jefaOtra->id, 'sede_id' => null, 'es_suplente' => false]);
        });
        $ticket = fn (Departamento $d, string $texto) => $this->en($condo, function () use ($sede, $d, $texto) {
            $n = new Novedad(['sede_id' => $sede->id, 'categoria' => 'incidente_general', 'reportado_por' => 'GUARDIA', 'ubicacion' => 'LOBBY', 'descripcion' => $texto]);
            $n->numero = (int) Novedad::max('numero') + 1;
            $n->forceFill(['departamento_id' => $d->id, 'departamento_en' => now()])->save();

            return $n;
        });
        $suyo = $ticket($mantto, 'Fuga en lobby');
        $ajeno = $ticket($limpieza, 'Derrame en pasillo');
        $this->actingAs($jefa)->get(route('novedades.departamento.index'))->assertOk()->assertSee('Fuga en lobby')->assertDontSee('Derrame en pasillo');
        $this->actingAs($jefa)->get(route('novedades.departamento.show', $ajeno->id))->assertNotFound();
        $this->actingAs($jefa)->post(route('novedades.departamento.atender', $ajeno->id), ['nota' => 'x'])->assertNotFound();
        $this->actingAs($jefaOtra)->get(route('novedades.departamento.show', $suyo->id))->assertNotFound();
        $this->actingAs($jefaOtra)->post(route('novedades.departamento.nota', $suyo->id), ['nota' => 'x'])->assertNotFound();
        $this->assertNull($suyo->fresh()->departamento_atendido_en);

        // Lugares: su propio límite (120 por minuto) y solo de su empresa
        $agente = $this->crearUsuario($condo, 'Agente', $sede);
        $this->actingAs($agente)->getJson(route('lugares.buscar', ['q' => 'x', 'sede' => $sedeOtro->id]))->assertOk()->assertJson(['resultados' => []]);
        for ($i = 0; $i < 119; $i++) {
            $this->actingAs($agente)->getJson(route('lugares.buscar', ['q' => 'x'.$i]))->assertOk();
        }
        $this->actingAs($agente)->getJson(route('lugares.buscar', ['q' => 'otra']))->assertStatus(429);
        // y no gasta el de «¿A quién viene?»
        $this->actingAs($agente)->getJson(route('accesos.buscar', ['que' => 'destino', 'q' => 'a']))->assertOk();
    }
}
