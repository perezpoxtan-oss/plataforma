<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Gafete;
use App\Models\HorarioLlave;
use App\Models\Llave;
use App\Models\Notificacion;
use App\Models\Persona;
use App\Models\PrestamoLlave;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\Responsiva;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use App\Services\Activos\CustodiaActivos;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 2 · Bajas y ciclo de vida de los activos: C-14 (estados únicos y
 * candados), C-16 (receptor general), C-17 (reglas de la llave) y C-20
 * («Antes de dar de baja»), con sus vínculos (§9 del plan): préstamo de
 * llaves, responsivas, vouchers, catálogos, Bitácora del día, tablero,
 * lector y Mis pendientes.
 */
class EtapaDosActivosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $agente;

    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function llave(string $nomenclatura, array $extra = []): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $extra) {
            $l = new Llave(['sede_id' => $this->centro->id, 'nomenclatura' => $nomenclatura, 'descripcion' => 'Prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
            $l->forceFill($extra)->save();

            return $l;
        });
    }

    private function colaborador(string $num, array $extra = []): Colaborador
    {
        return $this->enEmpresa(function () use ($num, $extra) {
            $c = new Colaborador(['num_empleado' => $num, 'nombre' => 'Persona', 'apellido_paterno' => 'Num'.$num, 'sede_id' => $this->centro->id]);
            $c->forceFill($extra)->save();

            return $c;
        });
    }

    private function equipo(string $serie, string $estado = 'disponible'): Equipo
    {
        return $this->enEmpresa(function () use ($serie, $estado) {
            $e = new Equipo(['sede_id' => $this->centro->id, 'tipo_equipo_id' => TipoEquipo::firstOrCreate(['nombre' => 'Radio'])->id, 'numero_serie' => $serie]);
            $e->forceFill(['estado' => $estado])->save();

            return $e;
        });
    }

    private function persona(string $nombre, ?Proveedor $proveedor = null): Persona
    {
        return $this->enEmpresa(fn () => Persona::create(['tipo' => $proveedor ? 'proveedor' : 'visitante', 'nombre_completo' => $nombre, 'proveedor_id' => $proveedor?->id]));
    }

    private function proveedor(string $nombre): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::create(['nombre' => $nombre, 'categoria' => 'contratista', 'todas_las_sedes' => true]));
    }

    private function prestar(Llave $llave, array $receptor, array $extra = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)->postJson('/prestamo-llaves', $receptor + $extra + [
            'sede_id' => $this->centro->id, 'llave_id' => $llave->id, 'tipo_garantia' => 'ine',
        ]);
    }

    private function firma(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    private function avisos(User $u, string $tipo): array
    {
        return Notificacion::withoutGlobalScopes()->where('user_id', $u->id)->where('tipo', $tipo)->get()->all();
    }

    // ============================================================ C-14

    public function test_estado_unico_de_llaves_y_gafetes_sigue_a_activo(): void
    {
        $l = $this->llave('HDC-1');
        $this->assertSame(EstadoActivo::DISPONIBLE, $l->estado);
        $this->enEmpresa(fn () => $l->forceFill(['activo' => false])->save());
        $this->assertSame(EstadoActivo::BAJA, $l->fresh()->estado);
        $this->enEmpresa(fn () => $l->forceFill(['activo' => true])->save());
        $this->assertSame(EstadoActivo::DISPONIBLE, $l->fresh()->estado);
        $this->enEmpresa(fn () => $l->forceFill(['estado' => EstadoActivo::BAJA])->save());
        $this->assertFalse($l->fresh()->activo);
    }

    /** Prueba de GC: baja de una llave prestada (se rechaza y manda a Préstamo de llaves). */
    public function test_no_se_da_de_baja_una_llave_prestada_y_no_regreso_la_deja_fuera_de_la_caseta(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007');
        $this->prestar($l, ['colaborador_id' => $c->id])->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());

        // Catálogo: rechazada, con el módulo que la tiene
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'extraviado'])
            ->assertSessionHasErrors(['motivo' => 'La llave HDC-101 está prestada a Persona Num1007: recíbela o márcala «No regresó» en Préstamo de llaves.']);
        $this->assertTrue($l->fresh()->activo);
        $this->actingAs($this->admin)->get('/llaves')->assertSee(route('bajas.revisar', ['tipo' => 'llaves', 'id' => $l->id]), false);

        // La caseta (Agente: no da de baja llaves) marca «No regresó»: se avisa al jefe con «Dar de baja»
        $this->actingAs($this->agente)->patch("/prestamo-llaves/{$p->id}/no-regreso")->assertRedirect(route('prestamo_llaves.index'))->assertSessionHas('aviso');
        $this->assertSame([PrestamoLlave::NO_REGRESO, EstadoActivo::NO_REGRESO], [$this->enEmpresa(fn () => $p->fresh()->estado), $l->fresh()->estado]);
        $aviso = $this->avisos($this->jefe, CustodiaActivos::TIPO_AVISO);
        $this->assertCount(1, $aviso);
        $this->assertSame('No regresó: llave HDC-101', $aviso[0]->titulo);
        $this->assertStringContainsString('baja='.$l->id, $aviso[0]->url);
        $this->assertSame([], $this->avisos($this->agente, CustodiaActivos::TIPO_AVISO));
        $this->assertTrue(Auditoria::where('evento', 'prestamo_llaves.no_regreso')->exists());

        // Ya no se presta; en la lista sigue a la vista con «Recibir»
        $this->prestar($l, ['colaborador_id' => $c->id])->assertStatus(422)
            ->assertJsonPath('errores.llave_id.0', 'La llave HDC-101 está marcada «No regresó»: si ya apareció, recíbela en Préstamo de llaves antes de prestarla.');
        $this->actingAs($this->agente)->get('/prestamo-llaves')->assertSee('NO REGRESÓ')->assertSee('Llaves en Uso (<span data-conteo-en-uso>1</span>)', false);

        // El jefe la da de baja desde el catálogo: el voucher propone a quien la tenía
        $this->actingAs($this->jefe)->get('/llaves?baja='.$l->id)->assertSee('data-baja-auto="'.$l->id.'"', false)
            ->assertSee('&quot;titulo&quot;:&quot;Persona Num1007&quot;', false);
        $this->actingAs($this->jefe)->post("/llaves/{$l->id}/baja", ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => 150, 'colaborador_id' => $c->id])->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::BAJA, $l->fresh()->estado);
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());
        $this->assertSame(['colaborador', $c->id, $c->id], [$voucher->responsable_tipo, $voucher->responsable_id, $voucher->colaborador_id]);
        $this->assertNull($this->avisos($this->jefe, CustodiaActivos::TIPO_AVISO)[0]->acciones, 'El aviso pierde su botón al darla de baja.');
    }

    public function test_quien_puede_dar_de_baja_pasa_directo_al_voucher_y_si_aparece_se_recibe(): void
    {
        $l = $this->llave('HDC-7');
        $this->prestar($l, ['colaborador_id' => $this->colaborador('1')->id])->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/no-regreso")
            ->assertRedirect(route('llaves.index', ['baja' => $l->id]).'#llave-'.$l->id);

        // Apareció: «Recibir» (también escaneándola)
        $this->actingAs($this->agente)->post('/prestamo-llaves/recibir', ['lectura' => 'HDC-7'])->assertSessionHas('ok');
        $this->assertSame([PrestamoLlave::DEVUELTA, EstadoActivo::DISPONIBLE], [$this->enEmpresa(fn () => $p->fresh()->estado), $l->fresh()->estado]);
        $this->prestar($l, ['colaborador_id' => $this->colaborador('2')->id])->assertCreated();
    }

    /** Prueba de GC: baja de un gafete EN SITIO (se rechaza y manda a la Bitácora de accesos). */
    public function test_no_se_da_de_baja_un_gafete_en_sitio(): void
    {
        $g = $this->enEmpresa(fn () => Gafete::create(['sede_id' => $this->centro->id, 'tipo_gafete_id' => TipoGafete::firstOrCreate(['nombre' => 'Visitante'])->id, 'nomenclatura' => 'HOT-CEN-VIS-001', 'consecutivo' => 1]));
        $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'motivo_visita' => 'general', 'persona_visita' => 'X',
            'nombre' => 'ANA SOSA', 'estado' => 'en_sitio', 'gafete_id' => $g->id, 'gafete_texto' => $g->nomenclatura, 'entrada_at' => now()]));

        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'extraviado'])
            ->assertSessionHasErrors(['motivo' => 'El gafete HOT-CEN-VIS-001 está EN SITIO con ANA SOSA: dale salida en la Bitácora de accesos (ahí se marca si no lo devolvió).']);
        $this->assertTrue($g->fresh()->activo);
        $this->actingAs($this->admin)->get('/gafetes')->assertSee('EN USO por <strong>ANA SOSA</strong>', false);
        $this->assertSame(0, $this->enEmpresa(fn () => VoucherReposicion::count()));
    }

    /** Prueba de GC: equipo ASIGNADO (y EN USO o FUERA) no se da de baja desde el catálogo. */
    public function test_no_se_da_de_baja_un_equipo_asignado_y_antes_de_baja_lo_marca_no_regreso(): void
    {
        $c = $this->colaborador('1005');
        $radio = $this->equipo('RAD-1');
        $chaleco = $this->equipo('CH-1');
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->centro->id, 'colaborador_id' => $c->id,
            'equipos' => [$radio->id, $chaleco->id], 'modalidades' => ['prestado', 'asignado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::EN_USO, EstadoActivo::ASIGNADO], [$radio->fresh()->estado, $chaleco->fresh()->estado]);

        $this->actingAs($this->admin)->post("/equipos/{$chaleco->id}/baja", ['motivo' => 'extraviado'])
            ->assertSessionHasErrors(['motivo' => 'El equipo CH-1 está ASIGNADO a cargo de Persona Num1005 (Responsiva CENRES-000001): recíbelo en Responsivas (si no regresó, «Faltante»).']);
        $this->assertSame(0, $this->enEmpresa(fn () => VoucherReposicion::count()));

        // «Antes de dar de baja» → «Dar de baja igual»: queda NO REGRESÓ y la baja con voucher ya procede (propone al resguardante)
        $this->actingAs($this->admin)->get("/antes-de-dar-de-baja/equipos/{$chaleco->id}")->assertOk()->assertSee('Está ASIGNADO a cargo de Persona Num1005');
        $this->actingAs($this->admin)->post("/antes-de-dar-de-baja/equipos/{$chaleco->id}/no-regreso")->assertRedirect(route('equipos.index', ['baja' => $chaleco->id]).'#equipo-'.$chaleco->id);
        $this->assertSame(EstadoActivo::NO_REGRESO, $chaleco->fresh()->estado);
        $this->actingAs($this->admin)->get('/equipos')->assertSee('&quot;titulo&quot;:&quot;Persona Num1005&quot;', false);
        $this->actingAs($this->admin)->post("/equipos/{$chaleco->id}/baja", ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => 300, 'colaborador_id' => $c->id])->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::BAJA, $chaleco->fresh()->estado);
        // La responsiva lo registra como «ya estaba de baja» al recibir el lote
        $r = $this->enEmpresa(fn () => Responsiva::firstOrFail());
        $this->actingAs($this->admin)->patch("/responsivas/{$r->id}/recibir")->assertSessionHasNoErrors();
        $this->assertSame(['ok', 'baja'], $this->enEmpresa(fn () => EquipoResponsiva::orderBy('id')->pluck('estado_devolucion')->all()));
        $this->assertSame(EstadoActivo::DISPONIBLE, $radio->fresh()->estado);
    }

    public function test_la_ficha_dice_quien_lo_tiene_y_el_tablero_cuenta_fuera_y_no_regreso(): void
    {
        $this->equipo('F-1', EstadoActivo::FUERA);
        $this->equipo('N-1', EstadoActivo::NO_REGRESO);
        $this->equipo('U-1', EstadoActivo::EN_USO);
        $this->actingAs($this->admin)->get('/equipos')->assertSee('Fuera <span', false)->assertSee('No regresó <span', false)->assertSee('En uso <span', false);
        $this->actingAs($this->admin)->get('/informes/tablero')->assertOk()->assertSee('1 fuera en pases')->assertSee('1 no regresaron');
    }

    public function test_mis_pendientes_y_la_campana_cuentan_lo_que_no_regreso(): void
    {
        $l = $this->llave('HDC-9');
        $this->prestar($l, ['colaborador_id' => $this->colaborador('9')->id])->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());
        $this->actingAs($this->agente)->patch("/prestamo-llaves/{$p->id}/no-regreso");
        $this->actingAs($this->jefe)->get('/prestamo-llaves')->assertOk()->assertSee('Llaves, gafetes o equipos que no regresaron');
    }

    // ============================================================ C-16

    public function test_se_presta_y_se_cobra_a_una_persona_externa_o_a_su_empresa(): void
    {
        $otis = $this->proveedor('Elevadores del Sur');
        $tecnico = $this->persona('Juan Pérez', $otis);
        $l = $this->llave('CUARTO-MAQ');

        $this->prestar($l, ['receptor_tipo' => 'persona', 'receptor_id' => $tecnico->id])->assertCreated()
            ->assertJsonPath('mensaje', 'Llave «CUARTO-MAQ» entregada a Juan Pérez (Elevadores del Sur).');
        $p = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());
        $this->assertSame(['persona', $tecnico->id, null], [$p->receptor_tipo, $p->receptor_id, $p->colaborador_id]);
        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertSee('Juan Pérez (Elevadores del Sur)')->assertSee('Persona externa');
        $this->actingAs($this->admin)->get('/llaves')->assertSee('EN USO por: Juan Pérez (Elevadores del Sur)');
        $this->actingAs($this->admin)->get('/informes/bitacora-del-dia')->assertOk();

        // No regresó → el voucher se carga a la empresa externa
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/no-regreso");
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => 500,
            'responsable_tipo' => 'proveedor', 'responsable_id' => $otis->id])->assertSessionHasNoErrors();
        $v = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());
        $this->assertSame(['proveedor', $otis->id, null], [$v->responsable_tipo, $v->responsable_id, $v->colaborador_id]);
        $this->actingAs($this->admin)->get('/vouchers')->assertSee('Elevadores del Sur');
        $this->actingAs($this->admin)->get('/vouchers?q=Elevadores')->assertSee($v->folio);
        $this->actingAs($this->admin)->get("/vouchers/{$v->id}/imprimir")->assertOk()->assertSee('Elevadores del Sur');
    }

    public function test_responsiva_a_cargo_de_una_persona_externa_y_unir_duplicados_mueve_al_receptor(): void
    {
        $tecnico = $this->persona('Pedro Can');
        $radio = $this->equipo('RAD-9');
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->centro->id, 'receptor_tipo' => 'persona', 'receptor_id' => $tecnico->id,
            'equipos' => [$radio->id], 'modalidades' => ['prestado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => Responsiva::firstOrFail());
        $this->assertSame(['persona', $tecnico->id], [$r->receptor_tipo, $r->receptor_id]);
        $this->actingAs($this->admin)->get('/responsivas')->assertSee('Pedro Can');
        $this->actingAs($this->admin)->get('/equipos')->assertSee('A cargo de: <strong>Pedro Can</strong>', false);
    }

    public function test_no_se_entrega_a_una_persona_restringida_ni_de_baja_y_la_busqueda_respeta_permisos(): void
    {
        $vetada = $this->persona('Ricardo Ortega');
        $this->enEmpresa(fn () => $vetada->forceFill(['restringido_en' => now(), 'restringido_motivo' => 'Robo'])->save());
        $baja = $this->persona('Lucía Baja');
        $this->enEmpresa(fn () => $baja->forceFill(['activo' => false])->save());
        $l = $this->llave('HDC-55');

        $this->prestar($l, ['receptor_tipo' => 'persona', 'receptor_id' => $vetada->id])->assertStatus(422)
            ->assertJsonPath('errores.colaborador_id.0', '«Ricardo Ortega» tiene el acceso restringido: no se le puede entregar nada. Llama a tu supervisor.');
        $this->prestar($l, ['receptor_tipo' => 'persona', 'receptor_id' => $baja->id])->assertStatus(422);
        $this->prestar($l, [])->assertStatus(422)->assertJsonPath('errores.colaborador_id.0', 'Escanea el gafete o busca al colaborador que se lleva la llave.');

        $this->actingAs($this->agente)->getJson('/receptores/buscar?tipo=persona&q=ricardo')->assertOk()
            ->assertJsonPath('resultados.0.restringido', true)->assertJsonPath('resultados.0.detalle', 'Acceso restringido: no se le puede entregar nada');
        $this->actingAs($this->agente)->getJson('/receptores/buscar?tipo=persona&q=lucia')->assertJsonCount(0, 'resultados');
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->getJson('/receptores/buscar?tipo=persona&q=ricardo')->assertForbidden();
    }

    // ============================================================ C-17

    /** Prueba de GC: llave vencida (pide motivo; con motivo queda registrado). */
    public function test_llave_vencida_fuera_de_horario_o_de_otro_puesto_pide_motivo(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00', 'America/Cancun'));
        $c = $this->colaborador('1007');
        $vencida = $this->llave('HDC-VEN', ['fecha_caducidad' => '2026-10-10']);

        $this->prestar($vencida, ['colaborador_id' => $c->id])->assertStatus(422)
            ->assertJsonPath('errores.excepcion_motivo.0', 'La llave HDC-VEN no cumple sus reglas: está VENCIDA desde el 10/10/2026. Si de todos modos se presta, escribe el motivo (queda registrado).');
        $this->assertSame(0, $this->enEmpresa(fn () => PrestamoLlave::count()));
        $this->prestar($vencida, ['colaborador_id' => $c->id, 'excepcion_motivo' => 'Lo autorizó el gerente de turno'])->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());
        $this->assertSame('Lo autorizó el gerente de turno', $p->excepcion_motivo);
        $auditoria = Auditoria::where('evento', 'prestamo_llaves.excepcion')->firstOrFail();
        $this->assertSame(['está VENCIDA desde el 10/10/2026.'], $auditoria->despues['no_cumplia']);
        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertSee('Prestada por excepción: Lo autorizó el gerente de turno');

        // Horario: de 03:00 a 03:01 (son las 10:00)
        $horario = $this->llave('HDC-HOR');
        $this->enEmpresa(fn () => HorarioLlave::create(['llave_id' => $horario->id, 'nombre' => 'Madrugada', 'hora_inicio' => '03:00', 'hora_fin' => '03:01']));
        $this->prestar($horario, ['colaborador_id' => $c->id])->assertStatus(422)->assertJsonPath('errores.excepcion_motivo.0', fn ($m) => str_contains($m, 'está fuera de su horario (03:00–03:01).'));
        // Cruza la medianoche: 22:00 a 06:00 tampoco cubre las 10:00; 08:00 a 18:00 sí
        $this->enEmpresa(fn () => HorarioLlave::create(['llave_id' => $horario->id, 'nombre' => 'Día', 'hora_inicio' => '08:00', 'hora_fin' => '18:00']));
        $this->prestar($horario, ['colaborador_id' => $c->id])->assertCreated();

        // Puesto objetivo y responsable permanente
        $puesto = $this->enEmpresa(fn () => Puesto::create(['nombre' => 'Ama de llaves']));
        $dePuesto = $this->llave('HDC-PUE', ['puesto_id' => $puesto->id]);
        $this->prestar($dePuesto, ['colaborador_id' => $c->id])->assertStatus(422)->assertJsonPath('errores.excepcion_motivo.0', fn ($m) => str_contains($m, 'es para el puesto «Ama de llaves».'));
        $ama = $this->colaborador('2000', ['puesto_id' => $puesto->id]);
        $this->prestar($dePuesto, ['colaborador_id' => $ama->id])->assertCreated();

        $asignada = $this->llave('HDC-ASG', ['colaborador_id' => $ama->id]);
        $this->prestar($asignada, ['colaborador_id' => $c->id])->assertStatus(422)->assertJsonPath('errores.excepcion_motivo.0', fn ($m) => str_contains($m, 'está ASIGNADA a Persona Num2000 (responsable permanente).'));
        $this->enEmpresa(fn () => PrestamoLlave::where('llave_id', $dePuesto->id)->update(['estado' => 'devuelta', 'devuelto_en' => now()]));
        $this->prestar($asignada, ['colaborador_id' => $ama->id])->assertCreated();
    }

    // ============================================================ C-20

    public function test_antes_de_dar_de_baja_a_un_colaborador_con_pendientes(): void
    {
        $julieta = $this->colaborador('3000');
        $cuenta = $this->crearUsuario($this->empresa, 'Jefe de departamento');
        $cuenta->forceFill(['colaborador_id' => $julieta->id])->save();
        $depto = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Mantenimiento']));
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $depto->id, 'user_id' => $cuenta->id]));
        $l = $this->llave('HDC-TA-ZONA');
        $this->prestar($l, ['colaborador_id' => $julieta->id])->assertCreated();
        $permanente = $this->llave('HDC-JEFA', ['colaborador_id' => $julieta->id]);
        $radio = $this->equipo('RAD-77');
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->centro->id, 'colaborador_id' => $julieta->id,
            'equipos' => [$radio->id], 'modalidades' => ['asignado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        $auto = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'JUL123', 'tipo' => 'sedan', 'propiedad' => 'colaborador', 'colaborador_id' => $julieta->id]));
        $this->enEmpresa(fn () => $julieta->forceFill(['etiqueta_nfc' => 'A1B2C3D4'])->save());

        // El botón de siempre manda a «Antes de dar de baja» (nada se cambia todavía)
        $this->actingAs($this->admin)->patch("/colaboradores/{$julieta->id}/estado", ['activo' => 0])
            ->assertRedirect(route('bajas.revisar', ['tipo' => 'colaboradores', 'id' => $julieta->id]));
        $this->assertTrue($julieta->fresh()->activo);
        $this->actingAs($this->admin)->get(route('bajas.revisar', ['tipo' => 'colaboradores', 'id' => $julieta->id]))->assertOk()
            ->assertSee('Antes de dar de baja')->assertSee('Tiene 1 llave prestada')->assertSee('Tiene 1 responsiva con equipo a su cargo')
            ->assertSee('Es responsable permanente de 1 llave')->assertSee('Es el único responsable de Mantenimiento')
            ->assertSee('Dar de baja sus vehículos (1)')->assertSee('Tiene una etiqueta NFC en su credencial')->assertSee('name="revisado" value="1"', false);

        // Sin reemplazo no
        $this->actingAs($this->admin)->from(route('bajas.revisar', ['tipo' => 'colaboradores', 'id' => $julieta->id]))
            ->patch("/colaboradores/{$julieta->id}/estado", ['activo' => 0, 'revisado' => 1, 'bajar_vehiculos' => 1])->assertSessionHasErrors('reemplazo_id');
        $this->assertTrue($julieta->fresh()->activo);

        $this->actingAs($this->admin)->patch("/colaboradores/{$julieta->id}/estado", ['activo' => 0, 'revisado' => 1, 'bajar_vehiculos' => 1, 'reemplazo_id' => $this->jefe->id])
            ->assertRedirect(route('colaboradores.index'))->assertSessionHas('aviso', fn ($m) => str_contains($m, 'quedó como responsable en su lugar'));
        $this->assertFalse($julieta->fresh()->activo);
        $this->assertSame(EstadoActivo::NO_REGRESO, $l->fresh()->estado);
        $this->assertSame(EstadoActivo::NO_REGRESO, $radio->fresh()->estado);
        $this->assertNull($permanente->fresh()->colaborador_id);
        $this->assertFalse($auto->fresh()->activo);
        $this->assertNull($julieta->fresh()->etiqueta_nfc);
        $this->assertSame($this->jefe->id, $this->enEmpresa(fn () => DepartamentoResponsable::where('departamento_id', $depto->id)->value('user_id')));
        $this->assertNotEmpty($this->avisos($this->jefe, 'baja_con_pendientes'));
        $this->assertCount(2, $this->avisos($this->jefe, CustodiaActivos::TIPO_AVISO));
    }

    public function test_sin_pendientes_la_baja_es_como_siempre_y_dejar_sus_vehiculos(): void
    {
        $c = $this->colaborador('4000');
        $this->actingAs($this->admin)->patch("/colaboradores/{$c->id}/estado", ['activo' => 0])->assertRedirect(route('colaboradores.index'));
        $this->assertFalse($c->fresh()->activo);

        $d = $this->colaborador('4001');
        $auto = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'DEJA11', 'tipo' => 'sedan', 'propiedad' => 'colaborador', 'colaborador_id' => $d->id]));
        $this->actingAs($this->admin)->patch("/colaboradores/{$d->id}/estado", ['activo' => 0, 'revisado' => 1, 'bajar_vehiculos' => 0])->assertRedirect(route('colaboradores.index'));
        $this->assertTrue($auto->fresh()->activo);
    }

    /** DD-06: solo se bloquean la sede con gente en sitio y la zona con autos dentro. */
    public function test_se_bloquea_la_sede_con_gente_en_sitio_y_la_zona_con_autos_dentro(): void
    {
        $zona = $this->enEmpresa(fn () => ZonaEstacionamiento::create(['sede_id' => $this->playa->id, 'nombre' => 'Sótano', 'tipo' => 'estacionamiento', 'cupo_total' => 10]));
        $acceso = $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->playa->id, 'tipo' => 'visitante', 'motivo_visita' => 'general', 'persona_visita' => 'X',
            'nombre' => 'EN SITIO', 'estado' => 'en_sitio', 'placas' => 'AAA111', 'zona_estacionamiento_id' => $zona->id, 'entrada_at' => now()]));

        $this->actingAs($this->admin)->patch("/sedes/{$this->playa->id}/estado", ['activo' => 0])->assertRedirect(route('bajas.revisar', ['tipo' => 'sedes', 'id' => $this->playa->id]));
        $this->actingAs($this->admin)->get(route('bajas.revisar', ['tipo' => 'sedes', 'id' => $this->playa->id]))->assertSee('Todavía no se puede dar de baja')->assertDontSee('name="revisado"', false);
        $this->actingAs($this->admin)->patch("/sedes/{$this->playa->id}/estado", ['activo' => 0, 'revisado' => 1])->assertSessionHasErrors('baja');
        $this->assertTrue($this->playa->fresh()->activo);

        $this->actingAs($this->admin)->patch("/estacionamientos/{$zona->id}/estado", ['activo' => 0, 'revisado' => 1])->assertSessionHasErrors('baja');
        $this->assertTrue($zona->fresh()->activo);

        // Liberado el lugar y con salida, ya se puede
        $this->actingAs($this->admin)->patch("/estacionamientos/{$zona->id}/liberar/{$acceso->id}")->assertSessionHas('ok');
        $this->actingAs($this->admin)->patch("/estacionamientos/{$zona->id}/estado", ['activo' => 0])->assertRedirect();
        $this->assertFalse($zona->fresh()->activo);
    }

    public function test_dar_de_baja_a_una_persona_o_empresa_con_algo_prestado(): void
    {
        $empresa = $this->proveedor('Fumigaciones Sur');
        $l = $this->llave('BODEGA-2');
        $this->prestar($l, ['receptor_tipo' => 'proveedor', 'receptor_id' => $empresa->id])->assertCreated();
        $this->actingAs($this->admin)->patch("/proveedores/{$empresa->id}/estado", ['activo' => 0])->assertRedirect(route('bajas.revisar', ['tipo' => 'proveedores', 'id' => $empresa->id]));
        $this->actingAs($this->admin)->patch("/proveedores/{$empresa->id}/estado", ['activo' => 0, 'revisado' => 1])->assertRedirect();
        $this->assertFalse($empresa->fresh()->activo);
        $this->assertSame(EstadoActivo::NO_REGRESO, $l->fresh()->estado);

        $p = $this->persona('Mario Ruiz');
        $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'motivo_visita' => 'general', 'persona_visita' => 'X',
            'nombre' => 'MARIO RUIZ', 'persona_id' => $p->id, 'estado' => 'en_sitio', 'entrada_at' => now()]));
        $this->actingAs($this->admin)->get(route('bajas.revisar', ['tipo' => 'personas', 'id' => $p->id]))->assertSee('Está en sitio o esperando autorización');
        $this->actingAs($this->agente)->get(route('bajas.revisar', ['tipo' => 'personas', 'id' => $p->id]))->assertForbidden();
    }

    // ===================================================== Revisión Etapa 2 (A-4, A-5, A-6)

    /** A-4: reactivar un préstamo anulado revisa el estado actual de la llave (No regresó o de baja). */
    public function test_reactivar_un_prestamo_anulado_revisa_el_estado_de_la_llave(): void
    {
        $l = $this->llave('HDC-201');
        $c = $this->colaborador('1101');
        $d = $this->colaborador('1102');
        $this->prestar($l, ['colaborador_id' => $c->id])->assertCreated();
        $anulado = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$anulado->id}/anular")->assertSessionHasNoErrors();
        // Mientras tanto se prestó a otro y no regresó
        $this->prestar($l, ['colaborador_id' => $d->id])->assertCreated();
        $segundo = $this->enEmpresa(fn () => PrestamoLlave::whereKeyNot($anulado->id)->firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$segundo->id}/no-regreso");
        $this->assertSame(EstadoActivo::NO_REGRESO, $l->fresh()->estado);

        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$anulado->id}/reactivar")
            ->assertSessionHasErrors(['prestamo' => 'No se puede reactivar — la llave HDC-201 ahora está NO REGRESÓ. Si ya regresó, recíbela primero.']);
        $this->assertTrue((bool) $this->enEmpresa(fn () => $anulado->fresh()->anulado));
    }

    /** A-5 y A-6: el expediente muestra lo que no regresó y la ficha de la llave tiene un solo estado. */
    public function test_expediente_muestra_lo_que_no_regreso_y_la_ficha_un_solo_estado(): void
    {
        $l = $this->llave('HDC-301');
        $c = $this->colaborador('1201');
        $radio = $this->equipo('RAD-301');
        $this->prestar($l, ['colaborador_id' => $c->id])->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::firstOrFail());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/no-regreso");
        $this->actingAs($this->admin)->post('/responsivas', ['sede_id' => $this->centro->id, 'colaborador_id' => $c->id,
            'equipos' => [$radio->id], 'modalidades' => ['asignado'], 'firma' => $this->firma()])->assertSessionHasNoErrors();
        // Se da de baja al colaborador: el equipo queda NO REGRESÓ
        $this->actingAs($this->admin)->patch("/colaboradores/{$c->id}/estado", ['activo' => 0, 'revisado' => 1])->assertSessionHasNoErrors();
        $this->assertSame(EstadoActivo::NO_REGRESO, $radio->fresh()->estado);

        // A-5: el expediente lo muestra
        $this->flushSession();
        $this->actingAs($this->admin)->get("/colaboradores/{$c->id}/expediente")->assertOk()
            ->assertSee('Llaves a su cargo (1)')->assertSee('HDC-301')
            ->assertSee('No regresaron (1)')->assertSee('Equipo RAD-301');

        // A-6: un solo estado en la ficha de la llave
        $html = $this->actingAs($this->admin)->get('/llaves')->assertOk()->getContent();
        $ficha = substr($html, (int) strpos($html, 'id="llave-'.$l->id.'"'), 2500);
        $this->assertStringContainsString('NO REGRESÓ', $ficha);
        $this->assertStringNotContainsString('>ACTIVA<', $ficha);
    }
}
