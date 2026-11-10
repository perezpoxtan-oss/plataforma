<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\Llave;
use App\Models\Novedad;
use App\Models\PrestamoLlave;
use App\Models\Procedimiento;
use App\Models\ProcedimientoCategoria;
use App\Models\RecorridoPc;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\User;
use App\Services\Informes\BitacoraDelDia;
use App\Services\Permisos\Autorizador;
use App\Services\Procedimientos\AdministradorProcedimientos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 6 · C-31 (D-09, D-12, D-20, X-05): la Ficha de Hechos dice quién tuvo
 * la llave y quién estuvo en la habitación; la caseta, la Bitácora del día y
 * el Informe ejecutivo ven los eventos de hoy de Reservas; los tickets y los
 * recorridos enlazan su procedimiento («¿Qué hacer?»).
 *
 * Reloj fijo: 2 de noviembre de 2026, 9:00 en la Ciudad de México.
 */
class EtapaSeisDatosQueViajanTest extends PruebaNovedades
{
    private User $agente;

    private User $jefe;

    private Espacio $torre;

    private Espacio $piso;

    private Espacio $hab101;

    private Espacio $hab102;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'));
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $this->piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $this->torre);
        $this->hab101 = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '101', $this->piso);
        $this->hab102 = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '102', $this->piso);
    }

    private function colaborador(string $num, string $nombre): Colaborador
    {
        return $this->enEmpresa(function () use ($num, $nombre) {
            $c = new Colaborador(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => 'Prueba', 'sede_id' => $this->centro->id]);
            $c->save();

            return $c;
        });
    }

    private function llave(string $nomenclatura, string $alcance, ?Espacio $abre = null): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $alcance, $abre) {
            $l = new Llave(['sede_id' => $this->centro->id, 'nomenclatura' => $nomenclatura, 'descripcion' => 'Llave '.$nomenclatura, 'tipo_dispositivo' => 'metalica', 'alcance' => $alcance]);
            $l->forceFill(['activo' => true])->save();
            if ($abre !== null) {
                DB::table('espacio_llave')->insert(['llave_id' => $l->id, 'espacio_id' => $abre->id]);
            }

            return $l;
        });
    }

    private function prestamo(Llave $llave, Colaborador $c, string $desde, ?string $hasta = null, array $extra = []): PrestamoLlave
    {
        return $this->enEmpresa(function () use ($llave, $c, $desde, $hasta, $extra) {
            $p = new PrestamoLlave(['sede_id' => $llave->sede_id, 'llave_id' => $llave->id, 'colaborador_id' => $c->id, 'tipo_garantia' => 'ine']);
            $p->forceFill(array_merge(['estado' => $hasta ? PrestamoLlave::DEVUELTA : PrestamoLlave::EN_USO, 'prestado_en' => $desde, 'devuelto_en' => $hasta,
                'entregado_por' => $this->admin->id], $extra))->save();

            return $p;
        });
    }

    private function ficha(User $quien, ?int $novedad = null): string
    {
        return $this->actingAs($quien)->get(route('novedades.ficha-hechos', array_filter(['habitacion' => $this->hab101->id, 'fecha' => '2026-11-01', 'novedad' => $novedad])))
            ->assertOk()->getContent();
    }

    // ---------------------------------------------------------- Ficha de Hechos

    public function test_robo_en_habitacion_con_prestamo_de_llave_dice_quien_tuvo_la_llave(): void
    {
        $camarista = $this->colaborador('2001', 'Rosa');
        $mozo = $this->colaborador('2002', 'Pedro');
        $gerente = $this->colaborador('2003', 'Laura');
        $ajeno = $this->colaborador('2004', 'Mario');
        $llave101 = $this->llave('HAB-101', 'area', $this->hab101);
        $llavePiso = $this->llave('PISO-1', 'piso', $this->piso);
        $maestra = $this->llave('MAESTRA', 'global');
        $llave102 = $this->llave('HAB-102', 'area', $this->hab102);
        $this->prestamo($llave101, $camarista, '2026-10-31 16:00:00', '2026-10-31 18:30:00');
        $this->prestamo($llavePiso, $mozo, '2026-11-01 14:00:00', null, ['estado' => PrestamoLlave::NO_REGRESO]);
        $this->prestamo($maestra, $gerente, '2026-11-01 20:00:00', '2026-11-01 21:00:00');
        $this->prestamo($llave102, $ajeno, '2026-11-01 15:00:00', '2026-11-01 16:00:00'); // otra habitación: no sale
        $this->prestamo($llave101, $ajeno, '2026-09-01 15:00:00', '2026-09-01 16:00:00'); // fuera de la ventana: no sale
        $this->enEmpresa(fn () => Llave::whereKey($llave101->id)->update(['colaborador_id' => $gerente->id])); // asignada de forma permanente

        $robo = $this->actingAs($this->admin)->post('/novedades', $this->datos(['categoria' => 'robo', 'descripcion' => 'Faltan joyas de la caja fuerte']))->assertSessionHasNoErrors();
        $roboId = $this->enEmpresa(fn () => Novedad::where('categoria', 'robo')->value('id'));

        $html = $this->ficha($this->admin, $roboId);
        $this->assertStringContainsString('Préstamo de llave · abre esta habitación', $html);
        $this->assertStringContainsString('Llave HAB-101 (Llave HAB-101): la tuvo Rosa Prueba', $html);
        $this->assertStringContainsString('Préstamo de llave · abre su piso', $html);
        $this->assertStringContainsString('la tuvo Pedro Prueba', $html);
        $this->assertStringContainsString('no regresó', $html);
        $this->assertStringContainsString('Préstamo de llave · llave maestra', $html);
        $this->assertStringContainsString('la tuvo Laura Prueba', $html);
        $this->assertStringContainsString('asignada de forma permanente a Laura Prueba', $html);
        $this->assertStringNotContainsString('Mario Prueba', $html);

        // Sin «Préstamo de llaves → Ver» la ficha no los muestra (el agente sí ve la ficha de Novedades)
        $this->enEmpresa(fn () => DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')
            ->join('modulos', 'modulos.id', '=', 'modulo_acciones.modulo_id')->where('modulos.clave', 'prestamo_llaves')->select('modulo_acciones.id'))
            ->whereIn('rol_id', DB::table('usuario_roles')->where('user_id', $this->agente->id)->select('rol_id'))->delete());
        app(Autorizador::class)->olvidar();
        $this->assertStringNotContainsString('Préstamo de llave', $this->ficha($this->agente));
    }

    public function test_la_ficha_muestra_los_accesos_de_la_habitacion_y_los_proveedores_de_la_zona(): void
    {
        $base = ['sede_id' => $this->centro->id, 'movimiento' => 'entrada', 'estado' => 'finalizado'];
        $this->enEmpresa(function () use ($base) {
            $huesped = new Acceso($base + ['tipo' => 'huesped', 'nombre' => 'JUAN HUESPED', 'habitacion' => '101', 'espacio_id' => $this->hab101->id]);
            $huesped->forceFill(['entrada_at' => '2026-10-30 18:00:00', 'salida_at' => '2026-11-01 17:00:00'])->save();
            $plomero = new Acceso($base + ['tipo' => 'proveedor', 'nombre' => 'LUIS PLOMERO', 'empresa_procedencia' => 'FUGAS SA', 'area_trabajo' => '102', 'espacio_id' => $this->hab102->id, 'actividad' => 'Cambio de llave de agua']);
            $plomero->forceFill(['entrada_at' => '2026-11-01 15:00:00', 'salida_at' => '2026-11-01 16:00:00'])->save();
            $otroHuesped = new Acceso($base + ['tipo' => 'huesped', 'nombre' => 'ANA VECINA', 'habitacion' => '102', 'espacio_id' => $this->hab102->id]);
            $otroHuesped->forceFill(['entrada_at' => '2026-11-01 10:00:00', 'salida_at' => '2026-11-01 12:00:00'])->save();
            $sinCatalogo = new Acceso($base + ['tipo' => 'huesped', 'nombre' => 'TEXTO LIBRE', 'habitacion' => '101']);
            $sinCatalogo->forceFill(['entrada_at' => '2026-11-01 10:00:00'])->save();
        });

        $html = $this->ficha($this->admin);
        $this->assertStringContainsString('Acceso · Huésped', $html);
        $this->assertStringContainsString('JUAN HUESPED: entró el', $html);
        $this->assertStringContainsString('LUIS PLOMERO (FUGAS SA · en 102 · Cambio de llave de agua)', $html);
        $this->assertStringNotContainsString('ANA VECINA', $html, 'Los huéspedes de otras habitaciones no son contexto');
        $this->assertStringNotContainsString('TEXTO LIBRE', $html, 'Lo que no se ligó al catálogo no se adivina');
    }

    // ---------------------------------------------------------- Eventos de hoy

    private function evento(string $titulo, string $inicioUtc, string $finUtc, string $estado = Reserva::CONFIRMADA, int $asistentes = 100): Reserva
    {
        return $this->enEmpresa(function () use ($titulo, $inicioUtc, $finUtc, $estado, $asistentes) {
            $salon = Espacio::where('nombre', 'Salón Maya')->first() ?? $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, 'Salón Maya', $this->torre);
            $area = ReservaArea::where('espacio_id', $salon->id)->first() ?? ReservaArea::forceCreate(['empresa_id' => $this->empresa->id, 'sede_id' => $this->centro->id, 'espacio_id' => $salon->id]);
            $r = new Reserva(['sede_id' => $this->centro->id, 'reserva_area_id' => $area->id, 'espacio_id' => $salon->id, 'titulo' => $titulo, 'asistentes' => $asistentes,
                'inicio' => $inicioUtc, 'fin' => $finUtc, 'bloqueo_desde' => CarbonImmutable::parse($inicioUtc, 'UTC')->subHour(), 'bloqueo_hasta' => $finUtc, 'estado' => $estado]);
            $r->folio = 'RES-'.random_int(1000, 9999);
            $r->solicitante_id = $this->admin->id;
            $r->save();

            return $r;
        });
    }

    public function test_un_evento_del_dia_se_ve_en_la_caseta_la_bitacora_y_el_informe_ejecutivo(): void
    {
        $this->evento('Congreso de médicos', '2026-11-02 16:00:00', '2026-11-02 22:00:00');     // 10:00–16:00 local
        $this->evento('Boda por aprobar', '2026-11-02 23:00:00', '2026-11-03 04:00:00', Reserva::POR_APROBAR);
        $this->evento('Comida de mañana', '2026-11-03 18:00:00', '2026-11-03 20:00:00');

        // Caseta (el agente no necesita permisos de Reservas): área, horario, personas y montaje
        $caseta = $this->actingAs($this->agente)->get('/accesos')->assertOk()->getContent();
        $this->assertStringContainsString('Eventos de hoy (1)', $caseta);
        $this->assertStringContainsString('<strong>10:00–16:00</strong> · Salón Maya · Congreso de médicos', $caseta);
        $this->assertStringContainsString('100 personas', $caseta);
        $this->assertStringContainsString('montaje desde 09:00', $caseta);
        $this->assertStringNotContainsString('Boda por aprobar', $caseta);
        $this->assertStringNotContainsString('Comida de mañana', $caseta);

        // Bitácora general del día: inicio y fin
        $bitacora = $this->actingAs($this->admin)->get('/informes/bitacora-del-dia')->assertOk()->getContent();
        $this->assertStringContainsString('Inicia evento', $bitacora);
        $this->assertStringContainsString('Termina evento', $bitacora);
        $this->assertStringContainsString('Salón Maya · Congreso de médicos · 100 personas', $bitacora);

        // Informe ejecutivo
        $this->actingAs($this->admin)->get('/informes/ejecutivo?fresco=1')->assertOk()->assertSee('Eventos de hoy')->assertSee('Eventos confirmados hoy')->assertSee('Congreso de médicos');

        // Sin el módulo Reservas no hay tarjeta
        $this->enEmpresa(fn () => DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)
            ->whereIn('modulo_id', DB::table('modulos')->where('clave', 'reservas')->select('id'))->update(['activo' => false]));
        app(Autorizador::class)->olvidar();
        $this->actingAs($this->agente)->get('/accesos')->assertOk()->assertDontSee('Eventos de hoy');
        $this->assertNotContains('reservas', app(BitacoraDelDia::class)->visibles($this->admin->fresh()));
    }

    public function test_sin_eventos_la_caseta_se_ve_como_siempre(): void
    {
        $this->actingAs($this->agente)->get('/accesos')->assertOk()->assertDontSee('Eventos de hoy');
    }

    // ---------------------------------------------------------- ¿Qué hacer?

    private function procedimientoPublicado(string $titulo): Procedimiento
    {
        $categoria = $this->enEmpresa(function () {
            app(AdministradorProcedimientos::class)->categorias();

            return ProcedimientoCategoria::where('nombre', 'Emergencias')->value('id');
        });
        $this->actingAs($this->jefe)->post('/procedimientos', [
            'clave' => 'PRO-'.random_int(100, 999), 'titulo' => $titulo, 'categoria_id' => $categoria, 'objetivo' => 'Atender.', 'aplica' => 'sedes', 'sedes' => [$this->centro->id], 'enviar' => '1',
            'pasos' => [['texto' => 'Primer paso', 'responsable' => 'Agente', 'critico' => '0']],
        ])->assertSessionHasNoErrors();
        $p = $this->enEmpresa(fn () => Procedimiento::with('trabajo')->orderByDesc('id')->firstOrFail());
        $this->actingAs($this->admin)->post("/procedimientos/{$p->id}/aprobar", ['version_id' => $p->trabajo?->id, 'firma_modo' => 'nueva', 'firma' => self::firmaJpeg()])
            ->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => Procedimiento::findOrFail($p->id));
    }

    public function test_que_hacer_enlaza_el_procedimiento_de_la_categoria_y_del_tipo_de_equipo(): void
    {
        $robo = $this->procedimientoPublicado('Robo en habitación');
        $extintor = $this->procedimientoPublicado('Revisión de extintores');
        $this->assertSame(Procedimiento::PUBLICADO, $robo->estado);

        $config = $this->actingAs($this->admin)->get('/configuracion')->assertOk()->getContent();
        $this->assertStringContainsString('procedimiento de cada categoría y tipo de equipo', $config);
        $this->assertStringNotContainsString('name="vinculos[novedad:accidente]"', $config, 'Accidente no se ofrece: su formato no se toca');

        $this->actingAs($this->jefe)->put('/configuracion/novedades/procedimientos', ['vinculos' => ['novedad:robo' => $robo->id]])->assertForbidden();
        $this->actingAs($this->admin)->put('/configuracion/novedades/procedimientos', ['vinculos' => ['novedad:robo' => 999999]])->assertSessionHasErrors('vinculos.novedad:robo');
        $this->actingAs($this->admin)->put('/configuracion/novedades/procedimientos', ['vinculos' => ['novedad:robo' => $robo->id, 'equipo:EXTINTOR' => $extintor->id, 'novedad:accidente' => $robo->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(['novedad:robo' => $robo->id, 'equipo:EXTINTOR' => $extintor->id], $this->empresa->fresh()->preferencias['procedimientos_vinculados']);

        $ticket = $this->novedad(['categoria' => 'robo']);
        $this->actingAs($this->agente)->get('/novedades?abrir='.$ticket->id)->assertOk()
            ->assertSee('¿Qué hacer?')->assertSee('Ver procedimiento «Robo en habitación»', false);
        $general = $this->novedad(['categoria' => 'incidente_general']);
        $this->actingAs($this->agente)->get('/novedades?abrir='.$general->id)->assertOk()->assertDontSee('¿Qué hacer?');

        // El punto del recorrido del extintor
        $equipo = $this->enEmpresa(function () {
            $e = new EquipoPc(['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-09']);
            $e->forceFill(['activo' => true])->save();

            return $e;
        });
        $this->actingAs($this->agente)->post('/recorridos-pc', ['sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => RecorridoPc::orderByDesc('id')->firstOrFail());
        $this->actingAs($this->agente)->get("/recorridos-pc/{$r->id}?equipo={$equipo->id}")->assertOk()->assertSee('Ver procedimiento «Revisión de extintores»', false);

        // Un procedimiento retirado deja de aparecer
        $this->enEmpresa(fn () => Procedimiento::whereKey($robo->id)->update(['estado' => Procedimiento::RETIRADO]));
        $this->actingAs($this->agente)->get('/novedades?abrir='.$ticket->id)->assertOk()->assertDontSee('¿Qué hacer?');
    }
}
