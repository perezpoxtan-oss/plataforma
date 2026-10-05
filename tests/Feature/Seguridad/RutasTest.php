<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Paradero;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Ruta;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Rutas\AdministradorRutas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class RutasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private Turno $matutino;

    private Proveedor $transportes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->matutino = $this->turno('Matutino', '07:00', '15:00');
        $this->transportes = $this->proveedor('Transportes Kin-Ha');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function turno(string $nombre, string $inicio, string $fin, ?Empresa $empresa = null, ?Sede $soloEn = null, bool $activo = true): Turno
    {
        return $this->enEmpresa(function () use ($nombre, $inicio, $fin, $soloEn, $activo) {
            $t = Turno::create(['nombre' => $nombre, 'hora_inicio' => "{$inicio}:00", 'hora_fin' => "{$fin}:00", 'todas_las_sedes' => $soloEn === null, 'activo' => $activo]);
            if ($soloEn) {
                $t->sedes()->sync([$soloEn->id]);
            }

            return $t;
        }, $empresa);
    }

    private function proveedor(string $nombre, ?Empresa $empresa = null, bool $activo = true, ?Sede $soloEn = null): Proveedor
    {
        return $this->enEmpresa(function () use ($nombre, $activo, $soloEn) {
            $p = Proveedor::create(['nombre' => $nombre, 'categoria' => 'transporte_personal', 'activo' => $activo, 'todas_las_sedes' => $soloEn === null]);
            if ($soloEn) {
                $p->sedes()->sync([$soloEn->id]);
            }

            return $p;
        }, $empresa);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = [], ?Sede $sede = null): array
    {
        return array_merge([
            'sede_id' => ($sede ?? $this->centro)->id,
            'sentido' => 'llegada',
            'nombre' => '  ruta 1 -  región 94 ',
            'turno_id' => $this->matutino->id,
            'proveedor_id' => $this->transportes->id,
            'costo_maximo_taxi' => '250.50',
            'horarios' => [
                ['nombre' => 'Lunes a viernes', 'dias' => ['LU', 'MA', 'MI', 'JU', 'VI'], 'hora_inicio' => '05:45', 'hora_fin' => '06:40', 'paraderos' => [
                    ['nombre' => 'Región 94 (crucero)', 'hora' => '05:45'],
                    ['nombre' => 'chedraui  portillo', 'hora' => ''],
                ]],
                ['nombre' => '', 'dias' => ['SA', 'DO'], 'hora_inicio' => '06:15', 'hora_fin' => '07:00', 'paraderos' => [
                    ['nombre' => 'REGIÓN 94 (CRUCERO)', 'hora' => '06:15'],
                ]],
            ],
        ], $extra);
    }

    private function crearRuta(array $extra = [], ?Sede $sede = null, ?User $actor = null): Ruta
    {
        $sede ??= $this->centro;

        return $this->enEmpresa(fn () => app(AdministradorRutas::class)->crear($actor ?? $this->admin, Sede::find($sede->id), $this->datos($extra, $sede)), Empresa::find($sede->empresa_id));
    }

    private function rutaEn(callable $fn): mixed
    {
        return $this->enEmpresa($fn);
    }

    /**
     * @param  array<string, Alcance>  $permisos
     */
    private function usuarioCon(array $permisos, ?Sede $sede = null): User
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Rol rutas '.uniqid(), 'nivel_jerarquia' => 40]);
        $roles->sincronizarPermisos($sa, $rol, $permisos);
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => Rol::findOrFail($rol->id)->id, 'sede_id' => $sede?->id]);

        return $usuario;
    }

    // ------------------------------------------------------------- Pantallas

    public function test_menu_enlaza_a_rutas_y_lista_de_sedes_con_conteos_y_proximas(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 05:00', 'America/Mexico_City')); // lunes
        $this->crearRuta();
        $this->crearRuta(['sentido' => 'salida', 'nombre' => 'RUTA 1', 'horarios' => [['hora_inicio' => '15:10', 'hora_fin' => '16:00']]]);
        $suspendida = $this->crearRuta(['nombre' => 'RUTA 9']);
        $this->rutaEn(fn () => app(AdministradorRutas::class)->cambiarEstado($this->admin, $suspendida, false));

        $this->actingAs($this->admin)->get('/rutas')->assertOk()
            ->assertSee('Rutas de Transporte')->assertSee('Elige una sede para ver sus rutas de llegada y salida.')
            ->assertSee('Sede CEN')->assertSee('Sede PLA')
            ->assertSee('2 Llegadas')->assertSee('1 Salida')->assertSee('0 Llegadas')
            ->assertSee('2 Paraderos')
            ->assertSee('Próx. Llegadas')->assertSee('Hoy 05:45 · RUTA 1 - REGIÓN 94')
            ->assertSee('Hoy 15:10 · RUTA 1')
            ->assertDontSee('RUTA 9 ·', false)
            ->assertSee('Con 1 ruta suspendida')
            ->assertSee('Transportes Kin-Ha')
            ->assertSee(route('rutas.sede', $this->centro->id))
            ->assertSee(route('rutas.dia', $this->centro->id))
            ->assertSee('data-fichas="rutas-sedes"', false);

        $this->assertSame('rutas.index', Modulo::where('clave', 'rutas')->value('ruta'));
    }

    public function test_pantalla_de_la_sede_con_pestanas_y_textos_de_segcat(): void
    {
        $ruta = $this->crearRuta();
        $this->crearRuta(['sentido' => 'salida', 'nombre' => 'RUTA 1']);

        $this->actingAs($this->admin)->get(route('rutas.sede', $this->centro->id))->assertOk()
            ->assertSee('Llegadas (1)')->assertSee('Salidas (1)')->assertSee('Paraderos (2)')
            ->assertSee('Nueva Llegada')->assertSee('Configurar Ruta y Horarios')->assertSee('Modificar Ruta y Horarios')
            ->assertSee('Llegada a la Sede')->assertSee('Salida de la Sede')->assertDontSee('Llegada al Hotel')->assertDontSee('Hotel / Sede')
            ->assertSee('id="ruta-'.$ruta->id.'"', false)
            ->assertSee('RUTA 1 - REGIÓN 94')->assertSee('05:45–06:40')->assertSee('L-V')->assertSee('S-D')
            ->assertSee('Taxi máx. $250.50')
            ->assertSee('Creado por '.$this->admin->name)
            ->assertSee(route('rutas.itinerario', $ruta->id))
            ->assertSee('data-plantilla-horario', false)
            ->assertSee('<datalist id="paraderosSede">', false)
            ->assertSee('<option value="CHEDRAUI PORTILLO">', false);

        $this->actingAs($this->admin)->get(route('rutas.sede', ['sede' => $this->centro->id, 'tab' => 'salidas']))->assertOk()
            ->assertSee('Nueva Salida');
        $this->actingAs($this->admin)->get(route('rutas.sede', ['sede' => $this->centro->id, 'tab' => 'paraderos']))->assertOk()
            ->assertSee('Nuevo Paradero')->assertSee('REGIÓN 94 (CRUCERO)')->assertSee('Lo usan 2 rutas activas');
    }

    // ------------------------------------------------------------------ Alta

    public function test_alta_con_horarios_paraderos_resumen_y_auditoria(): void
    {
        $existente = $this->enEmpresa(fn () => Paradero::create(['sede_id' => $this->centro->id, 'nombre' => 'CHEDRAUI PORTILLO']));

        $respuesta = $this->actingAs($this->admin)->post('/rutas', $this->datos());

        $ruta = $this->rutaEn(fn () => Ruta::with('horarios.paradas.paradero')->firstOrFail());
        $respuesta->assertRedirect(route('rutas.sede', ['sede' => $this->centro->id, 'tab' => 'llegadas']).'#ruta-'.$ruta->id)
            ->assertSessionHas('ok', 'Ruta «RUTA 1 - REGIÓN 94» creada correctamente.');

        $this->assertSame('RUTA 1 - REGIÓN 94', $ruta->nombre);
        $this->assertSame([$this->empresa->id, $this->centro->id, $this->admin->id], [$ruta->empresa_id, $ruta->sede_id, $ruta->creado_por]);
        $this->assertSame('250.50', $ruta->costo_maximo_taxi);
        // Resumen: el horario más temprano y la unión de días
        $this->assertSame(['05:45:00', '06:40:00', 'LU,MA,MI,JU,VI,SA,DO'], [$ruta->hora_inicio, $ruta->hora_fin, $ruta->dias]);

        [$semana, $finde] = $ruta->horarios->all();
        $this->assertSame(['Lunes a viernes', 'LU,MA,MI,JU,VI', 'Lunes a viernes'], [$semana->nombre, $semana->dias, $semana->textoDias()]);
        $this->assertSame(['Horario 2', 'SA,DO', 'S-D'], [$finde->nombre, $finde->dias, $finde->patronDias()]);
        $this->assertSame(['REGIÓN 94 (CRUCERO)', 'CHEDRAUI PORTILLO'], $semana->paradas->map(fn ($p) => $p->paradero->nombre)->all());
        $this->assertSame(['05:45', ''], $semana->paradas->map->horaCorta()->all());

        // Paraderos por sede: se reutiliza el existente y el nuevo se crea una sola vez
        $paraderos = $this->rutaEn(fn () => Paradero::orderBy('id')->get());
        $this->assertCount(2, $paraderos);
        $this->assertSame($existente->id, $semana->paradas[1]->paradero_id);
        $this->assertSame($semana->paradas[0]->paradero_id, $finde->paradas[0]->paradero_id);

        $auditoria = Auditoria::where('evento', 'rutas.creado')->where('auditable_type', Ruta::class)->firstOrFail();
        $this->assertSame('RUTA 1 - REGIÓN 94', $auditoria->despues['nombre']);
        $this->assertStringContainsString('L-V · 05:45-06:40 · REGIÓN 94 (CRUCERO) 05:45, CHEDRAUI PORTILLO', $auditoria->despues['horarios'][0]);
        $this->assertTrue(Auditoria::where('evento', 'rutas.creado')->where('auditable_type', Paradero::class)->exists());
    }

    public function test_sin_dias_marcados_opera_todos_los_dias_y_puede_cruzar_la_medianoche(): void
    {
        $this->actingAs($this->admin)->post('/rutas', $this->datos(['sentido' => 'salida', 'horarios' => [
            ['hora_inicio' => '23:20', 'hora_fin' => '00:30'],
        ]]))->assertSessionHasNoErrors();

        $h = $this->rutaEn(fn () => RutaHorario::firstOrFail());
        $this->assertSame('LU,MA,MI,JU,VI,SA,DO', $h->dias);
        $this->assertSame('Todos los días', $h->textoDias());
        $this->assertTrue($h->cruzaMedianoche());
        $this->assertTrue($h->aplicaEn(7));

        $this->actingAs($this->admin)->get(route('rutas.sede', ['sede' => $this->centro->id, 'tab' => 'salidas']))
            ->assertSee('23:20–00:30')->assertSee('title="Llega al día siguiente"', false);
    }

    public function test_validaciones_con_mensajes_claros(): void
    {
        $this->actingAs($this->admin)->post('/rutas', $this->datos(['horarios' => []]))
            ->assertSessionHasErrors(['horarios' => 'Toda ruta necesita al menos un horario con su hora de inicio y su hora de llegada.']);
        // Un bloque totalmente vacío no cuenta como horario
        $this->actingAs($this->admin)->post('/rutas', $this->datos(['horarios' => [['nombre' => '', 'hora_inicio' => '', 'hora_fin' => '']]]))
            ->assertSessionHasErrors(['horarios' => 'Toda ruta necesita al menos un horario con su hora de inicio y su hora de llegada.']);

        $errores = $this->actingAs($this->admin)->post('/rutas', $this->datos(['horarios' => [
            ['nombre' => 'Mañana', 'hora_inicio' => '06:00', 'hora_fin' => '07:00'],
            ['nombre' => 'A medias', 'hora_inicio' => '', 'hora_fin' => '25:00', 'paraderos' => [['nombre' => '', 'hora' => '08:00'], ['nombre' => 'Kabah', 'hora' => ''], ['nombre' => 'KABAH', 'hora' => '']]],
            ['hora_inicio' => '09:00', 'hora_fin' => '09:00'],
        ]]))->assertSessionHasErrors('horarios')->getSession()->get('errors')->get('horarios');
        $this->assertSame([
            'Horario 2: falta la hora de inicio del recorrido.',
            'Horario 2: escribe la hora de llegada como HH:MM (24 horas).',
            'Horario 2: hay un paradero con hora (08:00) pero sin nombre: escríbelo o quita la fila.',
            'Horario 2: el paradero «KABAH» está repetido.',
            'Horario 3: la hora de llegada debe ser distinta a la de inicio.',
        ], $errores);

        $this->actingAs($this->admin)->post('/rutas', $this->datos(['nombre' => '', 'sentido' => 'ida', 'costo_maximo_taxi' => '-5', 'turno_id' => '']))
            ->assertSessionHasErrors([
                'nombre' => 'Escribe el nombre de la ruta (por ejemplo: RUTA 1 - CENTRO).',
                'sentido' => 'Elige si la ruta es de llegada o de salida.',
                'costo_maximo_taxi' => 'El costo máximo por taxi no puede ser negativo.',
                'turno_id' => 'Elige el turno de la ruta.',
            ]);

        $this->assertSame(0, $this->rutaEn(fn () => Ruta::count()));
        $this->assertSame(0, $this->rutaEn(fn () => Paradero::count()));
    }

    public function test_el_error_vuelve_a_abrir_el_dialogo_con_lo_capturado(): void
    {
        $this->actingAs($this->admin)->from(route('rutas.sede', $this->centro->id))
            ->post('/rutas', $this->datos(['_dialogo' => 'crear', 'nombre' => '', 'horarios' => [
                ['nombre' => 'Entre semana', 'dias' => ['LU'], 'hora_inicio' => '06:00', 'hora_fin' => '07:00', 'paraderos' => [['nombre' => 'Kabah', 'hora' => '06:10']]],
            ]]))->assertRedirect(route('rutas.sede', $this->centro->id));

        $this->actingAs($this->admin)->get(route('rutas.sede', $this->centro->id))
            ->assertSee('id="dialogoNuevaRuta"', false)
            ->assertSee('data-abrir-al-cargar', false)
            ->assertSee('value="Entre semana"', false)
            ->assertSee('value="Kabah"', false)
            ->assertSee('value="06:10"', false);
    }

    public function test_turno_y_transportista_se_revalidan_contra_la_sede_y_la_empresa(): void
    {
        $soloPlaya = $this->turno('Mixto Playa', '10:00', '18:00', soloEn: $this->playa);
        $inactivo = $this->turno('Viejo', '06:00', '14:00', activo: false);
        $otra = $this->crearEmpresa('Hotel Dos');
        $turnoAjeno = $this->turno('Ajeno', '07:00', '15:00', $otra);
        $proveedorAjeno = $this->proveedor('Ajeno SA', $otra);
        $vetado = $this->proveedor('Vetado SA', activo: false);
        $deLaPlaya = $this->proveedor('Solo Playa SA', soloEn: $this->playa);

        foreach ([$soloPlaya, $inactivo, $turnoAjeno] as $t) {
            $this->actingAs($this->admin)->post('/rutas', $this->datos(['turno_id' => $t->id]))
                ->assertSessionHasErrors(['turno_id' => 'El turno elegido no existe, está desactivado o no se usa en esta sede. Elige otro de la lista.']);
        }
        foreach ([$proveedorAjeno, $vetado, $deLaPlaya] as $p) {
            $this->actingAs($this->admin)->post('/rutas', $this->datos(['proveedor_id' => $p->id]))
                ->assertSessionHasErrors(['proveedor_id' => 'La empresa transportista no existe, está dada de baja o no opera en esta sede. Elige otra de la lista.']);
        }
        // En la sede de playa sí se pueden usar
        $this->actingAs($this->admin)->post('/rutas', $this->datos(['turno_id' => $soloPlaya->id, 'proveedor_id' => $deLaPlaya->id], $this->playa))
            ->assertSessionHasNoErrors();

        // La lista del diálogo solo ofrece los que aplican en la sede
        $this->actingAs($this->admin)->get(route('rutas.sede', $this->centro->id))
            ->assertSee('Matutino (07:00 - 15:00)')->assertDontSee('Mixto Playa')->assertDontSee('Viejo')
            ->assertDontSee('Ajeno')->assertDontSee('Vetado SA')->assertDontSee('Solo Playa SA');
    }

    public function test_nombre_unico_por_sede_y_sentido(): void
    {
        $this->crearRuta();

        $this->actingAs($this->admin)->post('/rutas', $this->datos(['nombre' => 'Ruta 1 - Región 94']))
            ->assertSessionHasErrors(['nombre' => 'Ya existe una ruta de llegada llamada «RUTA 1 - REGIÓN 94» en esta sede.']);
        // Mismo nombre como salida o en otra sede: permitido
        $this->actingAs($this->admin)->post('/rutas', $this->datos(['sentido' => 'salida']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/rutas', $this->datos([], $this->playa))->assertSessionHasNoErrors();
        $this->assertSame(3, $this->rutaEn(fn () => Ruta::count()));
    }

    // -------------------------------------------------------------- Edición

    public function test_edicion_conserva_horarios_existentes_y_quita_los_que_faltan(): void
    {
        $ruta = $this->crearRuta();
        $otra = $this->crearRuta(['nombre' => 'OTRA']);
        [$semana, $finde] = $this->rutaEn(fn () => $ruta->horarios()->get()->all());
        $ajeno = $this->rutaEn(fn () => $otra->horarios()->first());

        $this->actingAs($this->admin)->put(route('rutas.update', $ruta->id), $this->datos([
            'nombre' => 'RUTA 1 - REGIÓN 94 Y KABAH',
            'costo_maximo_taxi' => '',
            'horarios' => [
                ['id' => $semana->id, 'nombre' => 'Entre semana', 'dias' => ['LU', 'MA', 'MI', 'JU', 'VI'], 'hora_inicio' => '05:30', 'hora_fin' => '06:30', 'paraderos' => [['nombre' => 'Kabah', 'hora' => '05:50']]],
                // Un id de otra ruta se trata como horario nuevo
                ['id' => $ajeno->id, 'nombre' => 'Domingo', 'dias' => ['DO'], 'hora_inicio' => '07:00', 'hora_fin' => '07:40'],
            ],
        ]))->assertRedirect(route('rutas.sede', ['sede' => $this->centro->id, 'tab' => 'llegadas']).'#ruta-'.$ruta->id)
            ->assertSessionHas('ok', 'Ruta «RUTA 1 - REGIÓN 94 Y KABAH» actualizada correctamente.');

        $horarios = $this->rutaEn(fn () => $ruta->horarios()->with('paradas.paradero')->get());
        $this->assertCount(2, $horarios);
        $this->assertSame($semana->id, $horarios[0]->id);
        $this->assertSame(['Entre semana', '05:30:00', ['KABAH']], [$horarios[0]->nombre, $horarios[0]->hora_inicio, $horarios[0]->paradas->map(fn ($p) => $p->paradero->nombre)->all()]);
        $this->assertNotSame($ajeno->id, $horarios[1]->id);
        $this->assertNull($this->rutaEn(fn () => RutaHorario::find($finde->id)));
        $this->assertNotNull($this->rutaEn(fn () => RutaHorario::find($ajeno->id)));

        $ruta = $this->rutaEn(fn () => $ruta->fresh());
        $this->assertNull($ruta->costo_maximo_taxi);
        $this->assertSame(['05:30:00', 'LU,MA,MI,JU,VI,DO'], [$ruta->hora_inicio, $ruta->dias]);

        $auditoria = Auditoria::where('evento', 'rutas.actualizado')->where('auditable_id', $ruta->id)->firstOrFail();
        $this->assertSame('RUTA 1 - REGIÓN 94', $auditoria->antes['nombre']);
        $this->assertSame('RUTA 1 - REGIÓN 94 Y KABAH', $auditoria->despues['nombre']);
    }

    public function test_la_edicion_conserva_un_turno_que_ya_no_aplica_pero_no_deja_elegir_otro(): void
    {
        $ruta = $this->crearRuta();
        $this->enEmpresa(fn () => $this->matutino->forceFill(['activo' => false])->save());
        $vespertino = $this->turno('Vespertino', '15:00', '23:00', activo: false);

        $this->actingAs($this->admin)->put(route('rutas.update', $ruta->id), $this->datos(['nombre' => 'CAMBIO']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('rutas.update', $ruta->id), $this->datos(['turno_id' => $vespertino->id]))->assertSessionHasErrors('turno_id');
    }

    // ------------------------------------------------- Clonar y suspender

    public function test_clonar_copia_horarios_y_paraderos_con_nombre_unico(): void
    {
        $ruta = $this->crearRuta();

        $this->actingAs($this->admin)->post(route('rutas.clonar', $ruta->id))
            ->assertSessionHas('ok', 'Ruta clonada como «RUTA 1 - REGIÓN 94 (COPIA)». Ajusta los horarios de la copia cuando quieras.');
        $this->actingAs($this->admin)->post(route('rutas.clonar', $ruta->id))
            ->assertSessionHas('ok', 'Ruta clonada como «RUTA 1 - REGIÓN 94 (COPIA) 2». Ajusta los horarios de la copia cuando quieras.');

        $copia = $this->rutaEn(fn () => Ruta::where('nombre', 'RUTA 1 - REGIÓN 94 (COPIA)')->with('horarios.paradas')->firstOrFail());
        $this->assertSame([$ruta->sede_id, $ruta->sentido, $ruta->turno_id, $this->admin->id], [$copia->sede_id, $copia->sentido, $copia->turno_id, $copia->creado_por]);
        $this->assertCount(2, $copia->horarios);
        $this->assertSame(3, $copia->horarios->flatMap->paradas->count());
        // El original no cambia
        $this->assertSame(2, $this->rutaEn(fn () => $ruta->horarios()->count()));
        $this->assertSame(2, Auditoria::where('evento', 'rutas.clonado')->count());
    }

    public function test_suspender_y_reactivar_con_auditoria(): void
    {
        $ruta = $this->crearRuta();

        $this->actingAs($this->admin)->patch(route('rutas.estado', $ruta->id), ['activo' => 0])
            ->assertSessionHas('aviso', 'Ruta «RUTA 1 - REGIÓN 94» suspendida. Puedes reactivarla con el mismo botón cuando quieras.');
        $this->assertFalse($this->rutaEn(fn () => $ruta->fresh()->activo));
        $this->actingAs($this->admin)->get(route('rutas.sede', $this->centro->id))->assertSee('SUSPENDIDA')->assertSee('data-estado="0"', false);

        $this->actingAs($this->admin)->patch(route('rutas.estado', $ruta->id), ['activo' => 1])->assertSessionHas('ok', 'Ruta «RUTA 1 - REGIÓN 94» reactivada.');
        $this->assertTrue($this->rutaEn(fn () => $ruta->fresh()->activo));
        $this->assertSame(['rutas.creado', 'rutas.desactivado', 'rutas.reactivado'], Auditoria::where('auditable_type', Ruta::class)->orderBy('id')->pluck('evento')->all());
    }

    // ------------------------------------------------------------- Paraderos

    public function test_paraderos_por_sede_alta_edicion_y_desactivacion(): void
    {
        $ruta = $this->crearRuta();

        $this->actingAs($this->admin)->post(route('rutas.paraderos.store', $this->centro->id), ['nombre' => ' plaza  las américas '])
            ->assertSessionHas('ok', 'Paradero «PLAZA LAS AMÉRICAS» agregado a la sede.');
        $this->actingAs($this->admin)->post(route('rutas.paraderos.store', $this->centro->id), ['nombre' => 'Plaza Las Américas'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe el paradero «PLAZA LAS AMÉRICAS» en esta sede.']);
        // Otra sede tiene su propio catálogo
        $this->actingAs($this->admin)->post(route('rutas.paraderos.store', $this->playa->id), ['nombre' => 'Plaza Las Américas'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('rutas.paraderos.store', $this->centro->id), ['nombre' => '  '])
            ->assertSessionHasErrors(['nombre' => 'Escribe el nombre del paradero (por ejemplo: PLAZA LAS AMÉRICAS).']);

        // Renombrar se refleja en las rutas que lo usan (en SEGCAT se quedaba el nombre viejo)
        $chedraui = $this->rutaEn(fn () => Paradero::where('nombre', 'CHEDRAUI PORTILLO')->firstOrFail());
        $this->actingAs($this->admin)->put(route('rutas.paraderos.update', $chedraui->id), ['nombre' => 'Chedraui Portillo (parada oficial)'])
            ->assertRedirect(route('rutas.sede', ['sede' => $this->centro->id, 'tab' => 'paraderos']).'#paradero-'.$chedraui->id);
        $this->actingAs($this->admin)->get(route('rutas.itinerario', $ruta->id))->assertSee('CHEDRAUI PORTILLO (PARADA OFICIAL)');

        // Desactivar: deja de sugerirse; las rutas no cambian
        $this->actingAs($this->admin)->patch(route('rutas.paraderos.estado', $chedraui->id), ['activo' => 0])->assertSessionHas('aviso');
        $this->actingAs($this->admin)->get(route('rutas.sede', $this->centro->id))->assertDontSee('<option value="CHEDRAUI PORTILLO (PARADA OFICIAL)">', false);
        $this->assertSame(3, $this->rutaEn(fn () => $ruta->horarios()->withCount('paradas')->get()->sum('paradas_count')));
        $this->actingAs($this->admin)->post(route('rutas.paraderos.store', $this->centro->id), ['nombre' => 'chedraui portillo (parada oficial)'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe el paradero «CHEDRAUI PORTILLO (PARADA OFICIAL)» en esta sede (desactivado): reactívalo en lugar de crearlo otra vez.']);

        // Si se escribe en una ruta, se reactiva solo
        $this->actingAs($this->admin)->post('/rutas', $this->datos(['nombre' => 'NUEVA', 'horarios' => [['hora_inicio' => '08:00', 'hora_fin' => '09:00', 'paraderos' => [['nombre' => 'Chedraui Portillo (Parada Oficial)']]]]]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->rutaEn(fn () => $chedraui->fresh()->activo));
        $this->assertSame(['rutas.creado', 'rutas.actualizado', 'rutas.desactivado', 'rutas.reactivado'], Auditoria::where('auditable_type', Paradero::class)->where('auditable_id', $chedraui->id)->orderBy('id')->pluck('evento')->all());
    }

    // ---------------------------------------------------------- Impresiones

    public function test_hoja_del_dia_por_dia_de_la_semana_ordenada_por_hora(): void
    {
        $this->crearRuta(); // L-V 05:45 y S-D 06:15
        $this->crearRuta(['nombre' => 'RUTA 2', 'horarios' => [['dias' => ['LU'], 'hora_inicio' => '04:30', 'hora_fin' => '05:10', 'paraderos' => [['nombre' => 'Kabah', 'hora' => '04:40']]]]]);
        $this->crearRuta(['sentido' => 'salida', 'nombre' => 'NOCTURNA', 'horarios' => [['hora_inicio' => '23:20', 'hora_fin' => '00:30']]]);
        $suspendida = $this->crearRuta(['nombre' => 'SUSPENDIDA X', 'horarios' => [['hora_inicio' => '03:00', 'hora_fin' => '04:00']]]);
        $this->rutaEn(fn () => app(AdministradorRutas::class)->cambiarEstado($this->admin, $suspendida, false));

        // Lunes 5 de octubre de 2026
        $lunes = $this->actingAs($this->admin)->get(route('rutas.dia', ['sede' => $this->centro->id, 'fecha' => '2026-10-05']))->assertOk()
            ->assertSee('Lunes 5 de octubre de 2026')->assertSee('Llegadas del día')->assertSee('Salidas del día')
            ->assertSee('RUTA 2')->assertSee('NOCTURNA')->assertDontSee('SUSPENDIDA X')
            ->assertSee('<sup title="Día siguiente">+1</sup>', false)
            ->assertSee('REGIÓN 94 (CRUCERO)')->assertSee('KABAH')
            ->getContent();
        $this->assertLessThan(strpos($lunes, 'RUTA 1 - REGIÓN 94'), strpos($lunes, '<strong>RUTA 2</strong>'));
        $this->assertStringContainsString('05:45', $lunes);
        $this->assertStringNotContainsString('06:15', $lunes);

        // Domingo: solo el horario de fin de semana
        $this->actingAs($this->admin)->get(route('rutas.dia', ['sede' => $this->centro->id, 'fecha' => '2026-10-11']))->assertOk()
            ->assertSee('Domingo 11 de octubre de 2026')->assertSee('06:15')->assertDontSee('<strong>RUTA 2</strong>', false);

        // Fecha inválida: hoy
        $this->actingAs($this->admin)->get(route('rutas.dia', ['sede' => $this->centro->id, 'fecha' => '2026-02-31']))->assertOk();
    }

    public function test_itinerario_con_todos_sus_horarios(): void
    {
        $ruta = $this->crearRuta();

        $this->actingAs($this->admin)->get(route('rutas.itinerario', $ruta->id))->assertOk()
            ->assertSee('RUTA 1 - REGIÓN 94')->assertSee('Llegada a la Sede')
            ->assertSee('Lunes a viernes')->assertDontSee('Lunes a viernes · Lunes a viernes')->assertSee('Horario 2 · Sábado y domingo')
            ->assertSee('05:45 — 06:40')->assertSee('CHEDRAUI PORTILLO')
            ->assertSee('Transportes Kin-Ha')->assertSee('$250.50')
            ->assertDontSee('qrserver');
    }

    // ------------------------------------------------- Aislamiento y alcance

    public function test_cada_empresa_ve_y_toca_solo_sus_rutas(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeOtra = $this->crearSede($otra, 'OTR');
        $turnoOtra = $this->turno('Matutino', '07:00', '15:00', $otra);
        $provOtra = $this->proveedor('Transportes Otra', $otra);
        $ajena = $this->enEmpresa(fn () => app(AdministradorRutas::class)->crear($this->admin, Sede::find($sedeOtra->id), $this->datos(['turno_id' => $turnoOtra->id, 'proveedor_id' => $provOtra->id], $sedeOtra)), $otra);
        $paraderoAjeno = $this->enEmpresa(fn () => Paradero::firstOrFail(), $otra);

        $this->actingAs($this->admin)->get('/rutas')->assertOk()->assertDontSee('Sede OTR');
        $this->actingAs($this->admin)->get(route('rutas.sede', $sedeOtra->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('rutas.dia', $sedeOtra->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('rutas.itinerario', $ajena->id))->assertNotFound();
        $this->actingAs($this->admin)->put(route('rutas.update', $ajena->id), $this->datos())->assertNotFound();
        $this->actingAs($this->admin)->patch(route('rutas.estado', $ajena->id), ['activo' => 0])->assertNotFound();
        $this->actingAs($this->admin)->post(route('rutas.clonar', $ajena->id))->assertNotFound();
        $this->actingAs($this->admin)->post('/rutas', $this->datos([], $sedeOtra))->assertNotFound();
        $this->actingAs($this->admin)->post(route('rutas.paraderos.store', $sedeOtra->id), ['nombre' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->put(route('rutas.paraderos.update', $paraderoAjeno->id), ['nombre' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->patch(route('rutas.paraderos.estado', $paraderoAjeno->id), ['activo' => 0])->assertNotFound();

        $this->assertTrue($this->enEmpresa(fn () => $ajena->fresh()->activo, $otra));
    }

    public function test_con_alcance_de_sede_solo_ve_y_trabaja_en_su_sede(): void
    {
        $deLaPlaya = $this->crearRuta([], $this->playa);
        $delCentro = $this->crearRuta();
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);

        $this->actingAs($supervisor)->get('/rutas')->assertOk()->assertSee('Sede CEN')->assertDontSee('Sede PLA');
        $this->actingAs($supervisor)->get(route('rutas.sede', $this->playa->id))->assertNotFound();
        $this->actingAs($supervisor)->put(route('rutas.update', $deLaPlaya->id), $this->datos([], $this->playa))->assertNotFound();
        $this->actingAs($supervisor)->post('/rutas', $this->datos(['nombre' => 'X'], $this->playa))->assertNotFound();

        // En su sede sí crea y edita (el Supervisor no suspende: no tiene "eliminar")
        $this->actingAs($supervisor)->post('/rutas', $this->datos(['nombre' => 'DEL SUPERVISOR']))->assertSessionHas('ok');
        $this->actingAs($supervisor)->put(route('rutas.update', $delCentro->id), $this->datos(['nombre' => 'EDITADA']))->assertSessionHas('ok');
        $this->actingAs($supervisor)->patch(route('rutas.estado', $delCentro->id), ['activo' => 0])->assertForbidden();
        $this->actingAs($supervisor)->get(route('rutas.sede', $this->centro->id))->assertOk()
            ->assertSee('Nueva Llegada')->assertDontSee('title="Suspender"', false);
    }

    public function test_alcance_propios_solo_modifica_lo_que_dio_de_alta(): void
    {
        $usuario = $this->usuarioCon(['rutas.ver' => Alcance::Empresa, 'rutas.crear' => Alcance::Empresa, 'rutas.editar' => Alcance::Propios, 'rutas.eliminar' => Alcance::Propios]);
        $ajena = $this->crearRuta();
        $propia = $this->crearRuta(['nombre' => 'MÍA'], actor: $usuario);

        $this->actingAs($usuario)->put(route('rutas.update', $ajena->id), $this->datos(['nombre' => 'X']))->assertForbidden();
        $this->actingAs($usuario)->patch(route('rutas.estado', $ajena->id), ['activo' => 0])->assertForbidden();
        $this->actingAs($usuario)->put(route('rutas.update', $propia->id), $this->datos(['nombre' => 'MÍA EDITADA']))->assertSessionHas('ok');
        $this->actingAs($usuario)->patch(route('rutas.estado', $propia->id), ['activo' => 0])->assertSessionHas('aviso');
    }

    public function test_el_agente_consulta_pero_no_crea_ni_imprime(): void
    {
        $ruta = $this->crearRuta();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/rutas')->assertOk()->assertSee('Sede CEN')->assertDontSee('Sede PLA')
            ->assertDontSee(route('rutas.dia', $this->centro->id));
        $this->actingAs($agente)->get(route('rutas.sede', $this->centro->id))->assertOk()
            ->assertSee('RUTA 1 - REGIÓN 94')
            ->assertDontSee('Nueva Llegada')->assertDontSee('dialogoNuevaRuta')->assertDontSee('data-accion="editar-ruta"', false)
            ->assertDontSee(route('rutas.itinerario', $ruta->id));

        $this->actingAs($agente)->post('/rutas', $this->datos(['nombre' => 'X']))->assertForbidden();
        $this->actingAs($agente)->put(route('rutas.update', $ruta->id), $this->datos())->assertForbidden();
        $this->actingAs($agente)->post(route('rutas.clonar', $ruta->id))->assertForbidden();
        $this->actingAs($agente)->patch(route('rutas.estado', $ruta->id), ['activo' => 0])->assertForbidden();
        $this->actingAs($agente)->post(route('rutas.paraderos.store', $this->centro->id), ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($agente)->get(route('rutas.dia', $this->centro->id))->assertForbidden();
        $this->actingAs($agente)->get(route('rutas.itinerario', $ruta->id))->assertForbidden();
    }

    public function test_superadmin_elige_la_empresa_de_trabajo(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->get('/rutas')->assertOk()->assertSee('empresa de trabajo');
        $this->actingAs($sa)->get(route('rutas.sede', $this->centro->id))->assertNotFound();

        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/rutas')->assertOk()->assertSee('Sede CEN');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->post('/rutas', $this->datos())->assertSessionHas('ok');
        $this->assertSame(1, $this->rutaEn(fn () => Ruta::count()));
    }

    public function test_proximas_salidas_respetan_los_dias_y_la_hora(): void
    {
        $ruta = $this->crearRuta(); // L-V 05:45, S-D 06:15
        $servicio = app(AdministradorRutas::class);
        $horarios = $this->rutaEn(fn () => $ruta->horarios()->get()->each(fn ($h) => $h->setRelation('ruta', $ruta)));

        // Viernes 9 de octubre a las 06:00: la siguiente es el sábado 06:15 y luego el domingo 06:15
        $ahora = CarbonImmutable::parse('2026-10-09 06:00', 'America/Cancun');
        $proximas = array_map(fn ($o) => $servicio->etiquetaProxima($o, $ahora), $servicio->proximas($horarios, $ahora));
        $this->assertSame(['Mañana 06:15 · RUTA 1 - REGIÓN 94', 'Dom 06:15 · RUTA 1 - REGIÓN 94'], $proximas);
    }
}
