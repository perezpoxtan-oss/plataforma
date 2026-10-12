<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Notificaciones\PreferenciasAvisos;
use App\Services\Permisos\Autorizador;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Mejoras de Estructura y plataforma (ADR-0016).
 */
class MejorasEstructuraTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Uno');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function rol(string $nombre): Rol
    {
        return Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->firstOrFail();
    }

    /** @return array<string, string> */
    private function permisosDe(Rol $rol): array
    {
        return DB::table('rol_permisos as rp')->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('rp.rol_id', $rol->id)->get(['m.clave as modulo', 'a.clave as accion', 'rp.alcance'])
            ->mapWithKeys(fn ($p) => [$p->modulo.'.'.$p->accion => $p->alcance])->sortKeys()->all();
    }

    // ------------------------------------------------------------- Roles

    public function test_el_nivel_se_elige_debajo_o_arriba_de_otro_rol(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Coordinador', 'posicion_relacion' => 'debajo', 'posicion_rol' => $this->rol('Jefe de seguridad')->id])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'nivel 35'));
        $this->assertSame(35, $this->rol('Coordinador')->nivel_jerarquia);

        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Auxiliar', 'posicion_relacion' => 'arriba', 'posicion_rol' => $this->rol('Agente')->id]);
        $this->assertSame(55, $this->rol('Auxiliar')->nivel_jerarquia);

        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Último', 'posicion_relacion' => 'debajo', 'posicion_rol' => $this->rol('Solicitante')->id]);
        $this->assertSame(80, $this->rol('Último')->nivel_jerarquia);

        // Sin lugar entre dos niveles seguidos: lo dice, sin inventar números
        Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Pegado', 'nivel_jerarquia' => 36]);
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Otro', 'posicion_relacion' => 'debajo', 'posicion_rol' => $this->rol('Coordinador')->id])
            ->assertSessionHasErrors(['posicion_rol' => 'No hay lugar entre «Coordinador» y «Pegado»: elige otra posición o escribe el número en «Avanzado».']);

        // Arriba de su propio nivel, nunca
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Jefe máximo', 'posicion_relacion' => 'arriba', 'posicion_rol' => $this->rol('Administrador')->id])
            ->assertSessionHasErrors('posicion_rol');
        $this->assertNull(Rol::where('nombre', 'Jefe máximo')->first());
    }

    public function test_editar_sin_posicion_conserva_el_nivel_y_con_posicion_lo_mueve(): void
    {
        $agente = $this->rol('Agente');
        $this->actingAs($this->admin)->put("/roles/{$agente->id}", ['nombre' => 'Agente', 'nivel_jerarquia' => 60, 'activo' => 1])->assertSessionHasNoErrors();
        $this->assertSame(60, $agente->fresh()->nivel_jerarquia);

        $this->actingAs($this->admin)->put("/roles/{$agente->id}", ['nombre' => 'Agente', 'nivel_jerarquia' => 60, 'activo' => 1,
            'posicion_relacion' => 'debajo', 'posicion_rol' => $this->rol('Solicitante')->id])->assertSessionHasNoErrors();
        $this->assertSame(80, $agente->fresh()->nivel_jerarquia);
    }

    public function test_crear_a_partir_de_otro_rol_copia_solo_lo_que_quien_crea_puede_otorgar(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Agente nocturno', 'posicion_relacion' => 'debajo', 'posicion_rol' => $this->rol('Agente')->id,
            'copiar_de' => $this->rol('Agente')->id])->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, 'con los permisos de «Agente»'));
        $this->assertSame($this->permisosDe($this->rol('Agente')), $this->permisosDe($this->rol('Agente nocturno')));
        $this->assertTrue(Auditoria::where('evento', 'permisos.rol_actualizado')->where('auditable_id', $this->rol('Agente nocturno')->id)->exists());

        // Un jefe de seguridad (alcance de sede) no puede copiar lo que no tiene
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $rolJefe = $this->rol('Jefe de seguridad');
        foreach (['roles.ver', 'roles.crear', 'permisos.ver', 'permisos.editar'] as $p) {
            [$m, $a] = explode('.', $p);
            $ma = DB::table('modulo_acciones')->join('modulos', 'modulos.id', '=', 'modulo_acciones.modulo_id')->join('acciones', 'acciones.id', '=', 'modulo_acciones.accion_id')
                ->where('modulos.clave', $m)->where('acciones.clave', $a)->value('modulo_acciones.id');
            DB::table('rol_permisos')->updateOrInsert(['rol_id' => $rolJefe->id, 'modulo_accion_id' => $ma], ['alcance' => 'empresa']);
        }
        $this->actingAs($jefe)->post('/roles', ['nombre' => 'Copia de RH', 'posicion_relacion' => 'debajo', 'posicion_rol' => $this->rol('Solicitante')->id,
            'copiar_de' => $this->rol('Recursos Humanos')->id])->assertSessionHas('ok', fn ($m) => str_contains($m, 'que tú no tienes'));
        $copia = $this->permisosDe($this->rol('Copia de RH'));
        $this->assertArrayNotHasKey('candidatos.contratar', $copia);
        $this->assertSame('sede', $copia['colaboradores.ver'] ?? null);
    }

    // ------------------------------------------------------------- Matriz

    public function test_el_asistente_aplica_un_perfil_y_la_matriz_muestra_menus_y_proximamente(): void
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Caseta nocturna', 'nivel_jerarquia' => 65]);
        $plantilla = Rol::whereNull('empresa_id')->where('nombre', 'Agente')->firstOrFail();

        $this->actingAs($this->admin)->get("/permisos?rol={$rol->id}")->assertOk()
            ->assertSee('¿Qué hace este rol?')->assertSee('Ningún menú: solo verá Inicio')
            ->assertSee('data-matriz-avanzada', false)->assertDontSee('data-matriz-avanzada open', false)
            ->assertSeeInOrder(['Tablero', 'Próximamente'], false);

        $this->actingAs($this->admin)->put("/permisos/{$rol->id}/perfil", ['perfil' => 'perfil-'.$plantilla->id])
            ->assertRedirect("/permisos?rol={$rol->id}")->assertSessionHas('ok', fn ($m) => str_contains($m, 'ahora tiene los permisos de «Agente»'));
        $this->assertSame($this->permisosDe($this->rol('Agente')), $this->permisosDe($rol));

        $this->actingAs($this->admin)->get("/permisos?rol={$rol->id}")->assertSee('Bitácora de accesos')->assertDontSee('Ningún menú: solo verá Inicio');

        // «Igual que otro rol de la empresa»
        $this->actingAs($this->admin)->put("/permisos/{$rol->id}/perfil", ['perfil' => 'otro', 'perfil_rol' => 'rol-'.$this->rol('Solicitante')->id])->assertSessionHas('ok');
        $this->assertSame($this->permisosDe($this->rol('Solicitante')), $this->permisosDe($rol));
    }

    public function test_los_modulos_sin_pantalla_conservan_sus_permisos_y_no_se_otorgan_desde_la_matriz(): void
    {
        $director = $this->rol('Director');
        $antes = collect($this->permisosDe($director))->filter(fn ($v, $k) => str_starts_with($k, 'dashboard.'))->all();
        $this->assertNotEmpty($antes);

        $agente = $this->rol('Agente');
        $this->actingAs($this->admin)->put("/permisos/{$director->id}", ['permisos' => ['accesos' => ['acciones' => ['ver'], 'alcance' => 'empresa']]])->assertSessionHas('ok');
        $this->assertSame($antes, collect($this->permisosDe($director))->filter(fn ($v, $k) => str_starts_with($k, 'dashboard.'))->all());

        $this->actingAs($this->admin)->put("/permisos/{$agente->id}", ['permisos' => ['dashboard' => ['acciones' => ['ver'], 'alcance' => 'sede']]])->assertSessionHas('ok');
        $this->assertArrayNotHasKey('dashboard.ver', $this->permisosDe($agente));
    }

    public function test_la_matriz_conserva_el_alcance_distinto_por_accion_de_las_plantillas(): void
    {
        $jefe = $this->rol('Jefe de departamento');
        $this->assertSame(['propios', 'sede'], [$this->permisosDe($jefe)['pases_salida.crear'], $this->permisosDe($jefe)['pases_salida.ver']]);
        $this->actingAs($this->admin)->get("/permisos?rol={$jefe->id}")->assertSee('Distinto por acción');

        // Lo que manda la pantalla sin tocar el alcance: se conserva por acción
        $this->actingAs($this->admin)->put("/permisos/{$jefe->id}", ['permisos' => ['pases_salida' => ['acciones' => ['ver', 'crear'], 'alcance' => 'sede', 'mixto' => 1,
            'alcances' => ['ver' => 'sede', 'crear' => 'propios']]]])->assertSessionHas('ok');
        $this->assertSame('propios', $this->permisosDe($jefe)['pases_salida.crear']);

        // Al cambiar el alcance del módulo (sin «mixto»), se usa uno solo
        $this->actingAs($this->admin)->put("/permisos/{$jefe->id}", ['permisos' => ['pases_salida' => ['acciones' => ['ver', 'crear'], 'alcance' => 'sede']]]);
        $this->assertSame('sede', $this->permisosDe($jefe)['pases_salida.crear']);
    }

    // ------------------------------------------------------ Configuración

    public function test_el_interruptor_por_modulo_apaga_sus_avisos_y_por_omision_todo_sigue_igual(): void
    {
        $this->assertTrue($this->empresa->fresh()->aviso('pase_salida_aprobacion'));
        $this->assertFalse($this->empresa->fresh()->aviso('movimientos_sensibles'));

        $avisos = collect(Empresa::AVISOS)->map(fn ($d) => $d[1] ? 1 : null)->filter()->all();
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => $avisos, 'avisos_modulos' => ['pases_salida' => 0, 'cuentas' => 1], '_grupo' => 'pases_salida'])
            ->assertRedirect(route('configuracion.index').'#config-pases_salida')->assertSessionHas('ok', fn ($m) => str_contains($m, 'Pases de salida'));

        $empresa = $this->empresa->fresh();
        $this->assertFalse($empresa->aviso('pase_salida_aprobacion'));
        $this->assertFalse($empresa->aviso('pase_salida_vencido'));
        $this->assertTrue($empresa->aviso('vale_taxi'));
        $this->assertTrue($empresa->aviso('cuenta_bloqueada'));

        $this->actingAs($this->admin)->get('/configuracion')->assertOk()
            ->assertSeeInOrder(['Versión instalada', 'Cuentas y seguridad', 'Pases de salida', 'Avisos de este módulo', 'apagados'], false);
    }

    public function test_la_configuracion_muestra_la_version_instalada(): void
    {
        $archivo = base_path('VERSION');
        $habia = is_file($archivo) ? file_get_contents($archivo) : null;
        file_put_contents($archivo, "v2.7.1\nabc123\n");
        try {
            $this->actingAs($this->admin)->get('/configuracion')->assertSee('Versión instalada: <strong>v2.7.1</strong>', false);
        } finally {
            $habia === null ? unlink($archivo) : file_put_contents($archivo, $habia);
        }
    }

    // --------------------------------------------------------- Auditoría

    public function test_ver_historial_filtra_la_bitacora_por_registro(): void
    {
        $agente = $this->rol('Agente');
        $this->actingAs($this->admin)->put("/roles/{$agente->id}", ['nombre' => 'Agente de caseta', 'nivel_jerarquia' => 60, 'activo' => 1]);
        $this->actingAs($this->admin)->put("/sedes/{$this->centro->id}", ['nombre' => 'Hotel Centro', 'codigo' => 'CEN', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo']);

        $this->actingAs($this->admin)->get('/roles')->assertSee(e(route('auditoria.index', ['registro' => 'rol', 'id' => $agente->id])), false);
        $this->actingAs($this->admin)->get("/auditoria?registro=rol&id={$agente->id}")->assertOk()
            ->assertSee('Historial de <strong>Rol · Agente de caseta</strong>', false)->assertSee('Edición')->assertDontSee('Sede · Hotel Centro');
        // Un tipo desconocido no filtra nada raro
        $this->actingAs($this->admin)->get('/auditoria?registro=no_existe&id=1')->assertOk()->assertDontSee('Historial de');
    }

    public function test_su_sede_ve_solo_lo_suyo_por_omision_y_los_movimientos_de_su_sede_si_la_empresa_lo_elige(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $ma = DB::table('modulo_acciones')->join('modulos', 'modulos.id', '=', 'modulo_acciones.modulo_id')->join('acciones', 'acciones.id', '=', 'modulo_acciones.accion_id')
            ->where('modulos.clave', 'auditoria')->where('acciones.clave', 'ver')->value('modulo_acciones.id');
        DB::table('rol_permisos')->updateOrInsert(['rol_id' => $this->rol('Jefe de seguridad')->id, 'modulo_accion_id' => $ma], ['alcance' => 'sede']);
        $agenteCentro = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        foreach ([[$agenteCentro, 'Persona del centro'], [$agentePlaya, 'Persona de la playa']] as [$u, $nombre]) {
            Auditoria::create(['empresa_id' => $this->empresa->id, 'user_id' => $u->id, 'evento' => 'accesos.creado', 'auditable_type' => Sede::class, 'auditable_id' => $u->sedeAsignadaId(), 'despues' => ['nombre' => $nombre]]);
        }
        $this->assertSame($this->centro->id, Auditoria::where('user_id', $agenteCentro->id)->value('sede_id'));

        $this->actingAs($jefe)->get('/auditoria')->assertSee('Ves solo tus propios movimientos.')->assertDontSee($agenteCentro->name);

        $this->actingAs($this->admin)->put('/configuracion/auditoria', ['auditoria_sede' => 'sede'])->assertSessionHas('ok');
        $this->actingAs($jefe)->get('/auditoria')->assertSee('Ves los movimientos de tu sede.')
            ->assertSee($agenteCentro->name)->assertDontSee($agentePlaya->name);
    }

    public function test_los_movimientos_sensibles_avisan_solo_si_la_empresa_lo_enciende(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $agente = $this->rol('Agente');
        $guardar = fn () => $this->actingAs($this->admin)->put("/permisos/{$agente->id}", ['permisos' => ['accesos' => ['acciones' => ['ver'], 'alcance' => 'sede']]]);

        $guardar();
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('tipo', 'movimiento_sensible')->count());

        $this->empresa->forceFill(['preferencias' => ['avisos' => ['movimientos_sensibles' => true]]])->save();
        $guardar();
        $avisados = Notificacion::withoutGlobalScopes()->where('tipo', 'movimiento_sensible')->pluck('user_id')->all();
        $this->assertSame([$director->id], $avisados, 'Llega a quien ve toda la bitácora, nunca a quien lo hizo');
        $this->assertStringContainsString('Matriz de permisos · Cambio de permisos', Notificacion::withoutGlobalScopes()->where('tipo', 'movimiento_sensible')->value('titulo'));
    }

    // ------------------------------------------- Inicio y Mis pendientes

    public function test_inicio_y_mis_pendientes_muestran_lo_mismo_sin_renglones_en_cero(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->postJson('/colaboradores/rapido', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id])->assertCreated();
        $this->app->forgetScopedInstances();

        $vista = app(MisPendientes::class)->para($rh)['vista'];
        $this->assertSame(['altas_provisionales'], array_column($vista, 'clave'));

        $this->actingAs($rh)->get('/')->assertOk()->assertSee('Altas provisionales por validar')->assertSee('1 alta provisional por validar')
            ->assertDontSee('Solicitudes por revisar')->assertDontSee('Consola de Monitoreo Central');
        $this->assertSame([], app()->build(MisPendientes::class)->para($this->crearUsuario($this->empresa, 'Agente', $this->centro))['vista'] ?? []);
    }

    public function test_los_renglones_de_candidatos_se_agrupan_con_su_desglose(): void
    {
        $vista = MisPendientes::vista([
            ['clave' => 'recepcion', 'titulo' => 'Esperando en Recepción', 'icono' => 'a', 'total' => 2, 'url' => '/r', 'nivel' => null],
            ['clave' => 'solicitudes', 'titulo' => 'Solicitudes por revisar', 'icono' => 'b', 'total' => 0, 'url' => '/s', 'nivel' => null],
            ['clave' => 'elegidos', 'titulo' => 'Elegidos por contratar', 'icono' => 'c', 'total' => 1, 'url' => '/e', 'nivel' => 'roja'],
            ['clave' => 'cuentas', 'titulo' => 'Cuentas por revisar', 'icono' => 'd', 'total' => 1, 'url' => '/u', 'nivel' => null],
        ]);
        $this->assertSame(['candidatos', 'cuentas'], array_column($vista, 'clave'));
        $this->assertSame(3, $vista[0]['total']);
        $this->assertSame('roja', $vista[0]['nivel']);
        $this->assertSame(['Esperando en Recepción', 'Elegidos por contratar'], array_column($vista[0]['detalle'], 'titulo'));
    }

    // ------------------------------------------------ Preferencias de avisos

    public function test_cada_persona_silencia_sus_avisos_de_la_campana_y_del_correo(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->get('/mi-cuenta')->assertOk()->assertSee('Mis avisos')->assertSee('Un pase de salida espera tu firma');

        $this->actingAs($jefe)->put('/mi-cuenta/avisos', ['campana' => ['pase_vencido' => 1], 'correo' => []])
            ->assertRedirect(route('mi-cuenta.index').'#mis-avisos')->assertSessionHas('ok', fn ($m) => str_contains($m, 'Mis pendientes'));
        $silenciados = PreferenciasAvisos::silenciados($jefe->fresh());
        $this->assertContains('pase_por_firmar', $silenciados['campana']);
        $this->assertNotContains('pase_vencido', $silenciados['campana']);
        $this->assertContains('pase_salida_aprobacion', $silenciados['correo']);

        $centro = app(CentroNotificaciones::class);
        $this->assertSame([$this->admin->id], $centro->avisar($this->empresa->id, [$jefe->id, $this->admin->id], 'pase_por_firmar', ['titulo' => 'Firma']));
        $this->assertSame([$jefe->id], $centro->avisar($this->empresa->id, [$jefe->id], 'pase_vencido', ['titulo' => 'Vencido']));
        $this->assertSame([$this->admin->email], PreferenciasAvisos::correosQueLoReciben($this->empresa->id, 'pase_salida_aprobacion', [$jefe->email, $this->admin->email]));

        // Los avisos de la propia cuenta no se silencian
        $this->assertSame([$jefe->id], $centro->avisar($this->empresa->id, [$jefe->id], 'cuenta_contrasena', ['titulo' => 'Tu contraseña cambió']));
    }

    // ------------------------------------------------------------ Usuarios

    public function test_una_cuenta_puede_atender_varias_sedes_sin_ver_toda_la_empresa(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', [
            'name' => 'Laura Supervisora', 'username' => 'laura.sup', 'email' => 'laura@hotel.mx', 'contrasena_modo' => 'manual', 'password' => 'Segura1234',
            'rol_id' => $this->rol('Supervisor')->id, 'sede_id' => $this->centro->id, 'sedes_extra' => [$this->playa->id, $this->centro->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $laura = User::where('username', 'laura.sup')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->centro->id, $this->playa->id], UsuarioRol::where('user_id', $laura->id)->pluck('sede_id')->all());
        $this->assertSame([$this->centro->id, $this->playa->id], collect(app(Autorizador::class)->sedesPermitidas($laura, 'accesos.ver'))->sort()->values()->all());
        $this->assertSame($this->centro->id, $laura->sedeAsignadaId());

        $this->actingAs($this->admin)->get('/usuarios')->assertSee('Sede CEN + Sede PLA')->assertSee(e('"sedes_extra":['.$this->playa->id.']'), false);

        // Un jefe con alcance de sede (solo Centro) la edita sin quitarle la otra sede
        $jefe = $this->crearUsuario($this->empresa, 'Administrador');
        UsuarioRol::where('user_id', $jefe->id)->update(['sede_id' => $this->centro->id]);
        DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->join('modulos', 'modulos.id', '=', 'modulo_acciones.modulo_id')
            ->where('modulos.clave', 'usuarios')->pluck('modulo_acciones.id'))->where('rol_id', $this->rol('Administrador')->id)->update(['alcance' => 'sede']);
        $this->actingAs($jefe)->put("/usuarios/{$laura->id}", ['name' => 'Laura Supervisora', 'username' => 'laura.sup', 'email' => 'laura@hotel.mx',
            'rol_id' => $this->rol('Supervisor')->id, 'sede_id' => $this->centro->id, 'activo' => 1])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->centro->id, $this->playa->id], UsuarioRol::where('user_id', $laura->id)->pluck('sede_id')->all());
        // …y no puede agregarle una sede que no es suya
        $tercera = $this->crearSede($this->empresa, 'TER');
        $this->actingAs($jefe)->put("/usuarios/{$laura->id}", ['name' => 'Laura Supervisora', 'username' => 'laura.sup', 'email' => 'laura@hotel.mx',
            'rol_id' => $this->rol('Supervisor')->id, 'sede_id' => $this->centro->id, 'sedes_extra' => [$tercera->id], 'activo' => 1])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no es una de ellas'));
    }

    public function test_las_cuentas_que_piden_pases_sin_colaborador_quedan_por_revisar(): void
    {
        $sol = $this->crearUsuario($this->empresa, 'Solicitante', $this->centro);
        $this->actingAs($this->admin)->get('/usuarios')->assertSee('sin colaborador vinculado: no puede pedir pases');
        $cuentas = collect(app(MisPendientes::class)->para($this->admin)['items'])->firstWhere('clave', 'cuentas');
        $this->assertSame(1, $cuentas['total']);
        $this->assertNotNull($sol);
    }

    // ------------------------------------------------------------ Empresas

    public function test_el_superadmin_elige_los_modulos_de_cada_empresa_y_direccion_siempre_queda(): void
    {
        $sa = $this->crearSuperadmin();
        $rh = Modulo::where('clave', 'colaboradores')->value('id');
        $accesos = Modulo::where('clave', 'accesos')->value('id');

        $this->actingAs($this->admin)->put("/empresas/{$this->empresa->id}/modulos", ['modulos' => [$accesos]])->assertForbidden();

        $this->actingAs($sa)->get('/empresas')->assertSee('Módulos de «Hotel Uno»')->assertSee('módulos contratados');
        $this->actingAs($sa)->put("/empresas/{$this->empresa->id}/modulos", ['modulos' => [$accesos]])->assertSessionHas('ok');

        $activos = DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('activo', true)->pluck('modulo_id')->all();
        $this->assertContains($accesos, $activos);
        $this->assertNotContains($rh, $activos);
        $this->assertContains(Modulo::where('clave', 'usuarios')->value('id'), $activos);
        $this->assertTrue(Auditoria::where('evento', 'empresas.modulos_actualizados')->exists());

        $this->actingAs($this->admin)->get('/colaboradores')->assertForbidden();
        $this->actingAs($this->admin)->get('/')->assertOk()->assertDontSee(url('/colaboradores'), false);
        // El Super Administrador ve en qué empresa está parado en cualquier pantalla
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/manual')->assertSee('chip-empresa-trabajo', false)->assertSee('Hotel Uno');
    }

    // --------------------------------------------------- Sedes, espacios y manual

    public function test_tras_crear_una_sede_se_ofrecen_los_siguientes_pasos(): void
    {
        $this->actingAs($this->admin)->post('/sedes', ['nombre' => 'Hotel Norte', 'codigo' => 'NOR', 'ciudad' => 'Mérida', 'entidad' => 'Yucatán'])
            ->assertSessionHas('siguientes', fn ($s) => array_column($s, 1) === [route('espacios.index'), route('usuarios.index')]);
        $this->actingAs($this->admin)->followingRedirects()->post('/sedes', ['nombre' => 'Hotel Sur', 'codigo' => 'SUR', 'ciudad' => 'Mérida', 'entidad' => 'Yucatán'])
            ->assertSee('Capturar sus zonas y edificios')->assertSee('Dar de alta a su personal en Usuarios');
    }

    public function test_zonas_y_edificios_tiene_nombre_consistente_y_migas_con_el_nivel(): void
    {
        $this->assertSame('Zonas y edificios', Modulo::where('clave', 'espacios')->value('nombre'));
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->centro->id, 'nombre' => 'Torre A'])->assertSessionHasNoErrors();
        $torre = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Espacio::where('nombre', 'Torre A')->firstOrFail());
        $this->actingAs($this->admin)->get('/espacios')->assertSee('<h1>Zonas y edificios</h1>', false);
        $this->actingAs($this->admin)->get("/espacios/{$torre->id}")->assertOk()
            ->assertSeeInOrder(['Zonas y edificios', 'Sede CEN', 'Zona / Edificio', 'Torre A'], false);
    }

    public function test_el_buscador_del_manual_busca_en_el_contenido_y_lleva_al_apartado(): void
    {
        $this->actingAs($this->admin)->get('/manual?q=desbloquear')->assertOk()
            ->assertSee('Usuarios')->assertSee(route('manual.ver', 'usuarios').'#como-desbloquear-una-cuenta', false);
        $this->actingAs($this->admin)->get('/usuarios')->assertSee(route('manual.ver', 'usuarios').'#como-dar-de-alta-una-cuenta', false);
        $this->actingAs($this->admin)->get('/manual/usuarios')->assertSee('¿Esta página no resolvió tu duda?');
        // Quien no ve la página no recibe el enlace
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/manual?q=desbloquear')->assertDontSee(route('manual.ver', 'usuarios'), false);
    }
}
