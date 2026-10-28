<?php

namespace Tests\Feature\Seguridad;

use App\Models\AccidenteColaborador;
use App\Models\Colaborador;
use App\Models\Espacio;
use App\Models\Turno;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 6 · C-33 (D-14, DD-16): vínculos de Accidentes SIN tocar su formato.
 *  - El turno del colaborador sale de su ficha al guardar (si no se escribió).
 *  - Fecha, hora, habitación y lugar se sugieren del ticket en un caso nuevo.
 *  - La ficha del colaborador tiene la pestaña de solo lectura «Accidentes».
 */
class EtapaSeisAccidentesTest extends PruebaNovedades
{
    private User $agente;

    private User $jefe;

    private User $rh;

    private Colaborador $rosa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->rosa = $this->enEmpresa(function () {
            $turno = Turno::create(['nombre' => 'Vespertino', 'hora_inicio' => '14:00', 'hora_fin' => '22:00']);

            return Colaborador::create(['num_empleado' => '3001', 'nombre' => 'Rosa', 'apellido_paterno' => 'Poot', 'sede_id' => $this->centro->id, 'turno_id' => $turno->id]);
        });
    }

    public function test_el_turno_sale_de_la_ficha_si_no_se_escribio_y_se_respeta_si_se_escribio(): void
    {
        $n = $this->novedad(['categoria' => 'accidente']);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $this->rosa->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame('VESPERTINO', $this->enEmpresa(fn () => AccidenteColaborador::where('novedad_id', $n->id)->value('turno')));
        // Lo que se ve al reabrirlo es lo guardado, en el mismo campo de siempre
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()
            ->assertSee('name="c_turno_colaborador" class="campo text-uppercase" maxlength="50" placeholder="Ej. Matutino" value="VESPERTINO"', false);

        // Si quien atiende escribió otro turno (cubrió a alguien), se respeta
        $otro = $this->novedad(['categoria' => 'accidente']);
        $this->actingAs($this->admin)->put("/novedades/{$otro->id}", $this->expediente($otro, ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $this->rosa->id, 'c_turno_colaborador' => 'Nocturno']))
            ->assertSessionHasNoErrors();
        $this->assertSame('NOCTURNO', $this->enEmpresa(fn () => AccidenteColaborador::where('novedad_id', $otro->id)->value('turno')));
    }

    public function test_fecha_hora_habitacion_y_lugar_se_sugieren_en_un_caso_nuevo_sin_cambiar_el_formato(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $hab = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '204', $torre);
        $n = $this->novedad(['categoria' => 'accidente', 'ubicacion' => 'ALBERCA PRINCIPAL', 'area_especifica_id' => $hab->id, 'ocurrio_en' => '2026-10-01 14:30:00']);
        $zona = $this->centro->zonaHoraria();
        $local = Carbon::parse('2026-10-01 14:30:00', 'UTC')->setTimezone($zona);

        $html = $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->getContent();
        $this->assertStringContainsString('id="h_fecha_accidente" name="h_fecha_accidente" class="campo" value="'.$local->format('Y-m-d').'"', $html);
        $this->assertStringContainsString('id="h_hora_accidente" name="h_hora_accidente" class="campo" value="'.$local->format('H:i').'"', $html);
        $this->assertStringContainsString('id="c_fecha_accidente" name="c_fecha_accidente" class="campo" value="'.$local->format('Y-m-d').'"', $html);
        $this->assertStringContainsString('id="h_hab" name="h_hab" class="campo" maxlength="20" value="204"', $html);
        $this->assertStringContainsString('ALBERCA PRINCIPAL', $html);
        // Los textos del formato no cambian («Huésped» incluido)
        $this->assertStringContainsString('Huésped / Cliente', $html);

        // Ya guardado, se muestra lo guardado (nada se sugiere encima)
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['acc_tipo_afectado' => 'HUESPED', 'h_nombre' => 'Juan', 'h_hab' => '301']))->assertSessionHasNoErrors();
        $html = $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->getContent();
        $this->assertStringContainsString('id="h_hab" name="h_hab" class="campo" maxlength="20" value="301"', $html);
        $this->assertStringContainsString('id="h_fecha_accidente" name="h_fecha_accidente" class="campo" value=""', $html);
    }

    public function test_la_ficha_del_colaborador_muestra_sus_accidentes_con_permiso_propio(): void
    {
        $n = $this->novedad(['categoria' => 'accidente']);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $this->rosa->id,
            'c_fecha_accidente' => '2026-10-02', 'm_herida' => ['Contusa'], 'rh_dias' => 3, 'rh_fecha' => '2026-10-06']))->assertSessionHasNoErrors();
        $folio = $this->buscar($n->id)->folio();
        $url = "/colaboradores/{$this->rosa->id}/expediente";

        foreach ([$this->admin, $this->rh, $this->jefe] as $quien) {
            $this->actingAs($quien)->get($url)->assertOk()->assertSee('data-pestana="accidentes"', false)
                ->assertSee($folio)->assertSee('02/10/2026')->assertSee('VESPERTINO')->assertSee('Contusa')->assertSee('3 días · regresa el 06/10/2026');
        }
        // RR. HH. no ve Novedades: el folio no es enlace
        $this->assertStringNotContainsString('abrir='.$n->id, $this->actingAs($this->rh)->get($url)->getContent());
        $this->assertStringContainsString('abrir='.$n->id, $this->actingAs($this->jefe)->get($url)->getContent());

        // La caseta ve al colaborador pero no la pestaña
        $this->actingAs($this->agente)->get($url)->assertOk()->assertDontSee('data-pestana="accidentes"', false);

        // Sin Novedades contratado, la acción no vale (se vende con Incidentes)
        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)
            ->whereIn('modulo_id', DB::table('modulos')->where('clave', 'novedades')->select('id'))->update(['activo' => false]);
        app(Autorizador::class)->olvidar();
        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('data-pestana="accidentes"', false);
    }

    public function test_el_permiso_de_ver_accidentes_esta_en_el_catalogo_y_en_las_plantillas(): void
    {
        $this->assertTrue(DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', 'colaboradores')->where('a.clave', 'accidentes')->exists());
        $this->assertSame('Ver accidentes', DB::table('acciones')->where('clave', 'accidentes')->value('nombre'));
        $this->assertTrue($this->admin->can('colaboradores.accidentes'));
        $this->assertTrue($this->rh->can('colaboradores.accidentes'));
        $this->assertTrue($this->jefe->can('colaboradores.accidentes'));
        $this->assertFalse($this->agente->can('colaboradores.accidentes'));
    }

    public function test_la_migracion_otorga_el_permiso_solo_a_quien_ya_ve_datos_personales_o_reabre_tickets(): void
    {
        $migracion = require database_path('migrations/2026_11_16_000200_permiso_ver_accidentes_del_colaborador.php');
        $migracion->down();
        $this->assertNull(DB::table('acciones')->where('clave', 'accidentes')->value('id'));
        $migracion->up();
        app(Autorizador::class)->olvidar();
        $this->assertTrue($this->admin->fresh()->can('colaboradores.accidentes'));
        $this->assertTrue($this->rh->fresh()->can('colaboradores.accidentes'));
        $this->assertTrue($this->jefe->fresh()->can('colaboradores.accidentes'));
        $this->assertFalse($this->agente->fresh()->can('colaboradores.accidentes'));
        // Y con el mismo alcance que su «ver» de colaboradores
        $alcance = fn (User $u, string $accion) => DB::table('rol_permisos as rp')->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->whereIn('rp.rol_id', DB::table('usuario_roles')->where('user_id', $u->id)->select('rol_id'))
            ->where('m.clave', 'colaboradores')->where('a.clave', $accion)->value('rp.alcance');
        $this->assertSame($alcance($this->jefe, 'ver'), $alcance($this->jefe, 'accidentes'));
    }
}
