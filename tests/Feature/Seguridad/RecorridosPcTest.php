<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\Modulo;
use App\Models\Novedad;
use App\Models\RecorridoPc;
use App\Models\RevisionRecorridoPc;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class RecorridosPcTest extends TestCase
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
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function espacio(Sede $sede, string $nivel, string $nombre, ?Espacio $padre = null): Espacio
    {
        return $this->enEmpresa(fn () => app(AdministradorEspacios::class)->crear($this->admin, $sede, $padre, $nivel, ['nombre' => $nombre], false));
    }

    private function equipo(string $serie, string $categoria = 'EXTINTOR', ?Sede $sede = null, array $extra = [], ?Empresa $empresa = null): EquipoPc
    {
        $empresa ??= $this->empresa;
        $sede ??= $empresa->is($this->empresa) ? $this->centro : $this->crearSede($empresa, 'X'.random_int(10, 99));

        return $this->enEmpresa(function () use ($serie, $categoria, $sede, $extra) {
            $e = new EquipoPc(array_merge(['sede_id' => $sede->id, 'categoria' => $categoria, 'numero_serie' => $serie], array_diff_key($extra, ['activo' => 1])));
            $e->forceFill(['activo' => $extra['activo'] ?? true])->save();

            return $e;
        }, $empresa);
    }

    private function recorrido(?User $actor = null, ?Sede $sede = null): RecorridoPc
    {
        $actor ??= $this->admin;
        $this->actingAs($actor)->post('/recorridos-pc', ['sede_id' => ($sede ?? $this->centro)->id])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => RecorridoPc::orderByDesc('id')->firstOrFail());
    }

    /** Todos los criterios sanos, menos los indicados. */
    private function criterios(string $categoria, array $malos = []): array
    {
        return array_fill_keys(array_diff(array_keys(EquipoPc::criterios($categoria)), $malos), '1');
    }

    private function punto(RecorridoPc $r, EquipoPc $e, array $malos = [], ?string $obs = null, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->admin)->post("/recorridos-pc/{$r->id}/revisiones", [
            '_punto' => 'equipo', 'equipo_pc_id' => $e->id, 'criterios' => $this->criterios($e->categoria, $malos), 'observaciones' => $obs,
        ]);
    }

    private function fresco(RecorridoPc $r): RecorridoPc
    {
        return $this->enEmpresa(fn () => RecorridoPc::with('revisiones')->findOrFail($r->id));
    }

    // ------------------------------------------------------------ Catálogo

    public function test_alta_edicion_baja_y_reactivacion_del_catalogo(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $torre);
        $cocina = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, 'Cocina', $piso);

        $this->actingAs($this->admin)->post('/equipos-pc', [
            'sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => ' ext  01 ', 'zona_id' => $piso->id,
            'area_especifica_id' => $cocina->id, 'referencia' => 'Junto a la estufa', 'etiqueta_nfc' => '04:a2:3b:1c',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $e = $this->enEmpresa(fn () => EquipoPc::firstOrFail());
        $this->assertSame('EXT 01', $e->numero_serie);
        $this->assertSame($cocina->id, $e->espacio_id);
        $this->assertSame('04A23B1C', $e->etiqueta_nfc);
        $this->assertSame(24, strlen($e->codigo_qr));

        $pagina = $this->actingAs($this->admin)->get('/equipos-pc')->assertOk()
            ->assertSee('Catálogo de Equipos de Protección Civil')->assertSee('EXT 01')->assertSee('Torre A · Piso 1 · Cocina')
            ->assertSee('Junto a la estufa')->assertSee('Nuevo Equipo')->assertSee('Creado por')->getContent();
        $this->assertStringContainsString('"zona_id":'.$piso->id, html_entity_decode($pagina));

        $this->actingAs($this->admin)->put("/equipos-pc/{$e->id}", ['sede_id' => $this->centro->id, 'categoria' => 'HIDRANTE', 'numero_serie' => 'HID-01', 'zona_id' => $torre->id])
            ->assertSessionHasNoErrors();
        $e = $this->enEmpresa(fn () => $e->fresh());
        $this->assertSame(['HIDRANTE', 'HID-01', $torre->id], [$e->categoria, $e->numero_serie, $e->espacio_id]);

        $this->actingAs($this->admin)->patch("/equipos-pc/{$e->id}/desactivar")->assertSessionHas('aviso');
        $this->assertFalse($this->enEmpresa(fn () => $e->fresh()->activo));
        // Transición prohibida: ya está de baja
        $this->actingAs($this->admin)->patch("/equipos-pc/{$e->id}/desactivar")->assertSessionHasErrors('activo');
        $this->actingAs($this->admin)->patch("/equipos-pc/{$e->id}/reactivar")->assertSessionHas('ok');
        $this->assertTrue($this->enEmpresa(fn () => $e->fresh()->activo));
        $this->actingAs($this->admin)->patch("/equipos-pc/{$e->id}/reactivar")->assertSessionHasErrors('activo');

        $eventos = Auditoria::where('auditable_type', EquipoPc::class)->orderBy('id')->pluck('evento')->all();
        $this->assertSame(['equipos_pc.creado', 'equipos_pc.actualizado', 'equipos_pc.desactivado', 'equipos_pc.reactivado'], $eventos);
    }

    public function test_validacion_y_unicidad_por_sede(): void
    {
        $this->equipo('EXT-01');
        $otraTorre = $this->espacio($this->playa, Espacio::EDIFICIO, 'Torre Playa');

        $this->actingAs($this->admin)->post('/equipos-pc', ['_dialogo' => 'crear'])
            ->assertSessionHasErrors(['sede_id' => 'Elige la sede donde está el equipo.', 'categoria' => 'Elige el tipo de equipo.', 'numero_serie']);
        $this->actingAs($this->admin)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'COHETE', 'numero_serie' => 'X'])
            ->assertSessionHasErrors(['categoria' => 'Elige un tipo de equipo de la lista.']);
        $this->actingAs($this->admin)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'ext-01'])
            ->assertSessionHasErrors(['numero_serie' => 'El Núm. de Serie / ID «EXT-01» ya está registrado en esta sede (Extintor).']);
        // Ubicación de otra sede
        $this->actingAs($this->admin)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-02', 'zona_id' => $otraTorre->id])
            ->assertSessionHasErrors('zona_id');
        // La misma etiqueta NFC no puede estar en dos registros
        $this->equipo('EXT-09', 'EXTINTOR', null, ['etiqueta_nfc' => '04AABBCC']);
        $this->actingAs($this->admin)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-03', 'etiqueta_nfc' => '04:aa:bb:cc'])
            ->assertSessionHasErrors(['etiqueta_nfc' => 'Esa etiqueta NFC / RFID ya está asignada a otro registro: Equipo de Protección Civil «EXT-09». Usa otra etiqueta o quítasela primero a ese registro.']);
        // El mismo ID en otra sede sí se permite
        $this->actingAs($this->admin)->post('/equipos-pc', ['sede_id' => $this->playa->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $this->enEmpresa(fn () => EquipoPc::where('numero_serie', 'EXT-01')->count()));
    }

    public function test_registrar_y_capturar_siguiente_conserva_sede_tipo_y_ubicacion(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $this->actingAs($this->admin)->post('/equipos-pc', ['_siguiente' => 1, 'sede_id' => $this->centro->id, 'categoria' => 'DETECTOR_HUMO', 'numero_serie' => 'DH-1', 'zona_id' => $torre->id])
            ->assertSessionHas('capturar_siguiente', ['sede_id' => $this->centro->id, 'categoria' => 'DETECTOR_HUMO', 'zona_id' => (string) $torre->id, 'area_especifica_id' => null]);
        $this->actingAs($this->admin)->get('/equipos-pc')->assertSee('Capturando el siguiente');
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->equipo('EXT-77', 'EXTINTOR', null, [], $otra);
        $otroAdmin = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($otroAdmin)->post('/recorridos-pc', ['sede_id' => $ajeno->sede_id])->assertSessionHasNoErrors();
        $recorridoAjeno = $this->enEmpresa(fn () => RecorridoPc::firstOrFail(), $otra);

        $this->actingAs($this->admin)->get('/equipos-pc')->assertOk()->assertDontSee('EXT-77');
        $this->actingAs($this->admin)->put("/equipos-pc/{$ajeno->id}", ['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'Z'])->assertNotFound();
        $this->actingAs($this->admin)->patch("/equipos-pc/{$ajeno->id}/desactivar")->assertNotFound();
        $this->actingAs($this->admin)->get("/equipos-pc/{$ajeno->id}/etiqueta")->assertNotFound();
        $this->actingAs($this->admin)->get("/equipos-pc/{$ajeno->id}/qr")->assertNotFound();
        $this->actingAs($this->admin)->get("/recorridos-pc/{$recorridoAjeno->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("/recorridos-pc/{$recorridoAjeno->id}/revisiones", ['equipo_pc_id' => $ajeno->id])->assertNotFound();
        $this->actingAs($this->admin)->put("/recorridos-pc/{$recorridoAjeno->id}", ['finalizar' => 1])->assertNotFound();
        // Un recorrido propio no acepta equipos de otra empresa
        $r = $this->recorrido();
        $this->punto($r, $ajeno)->assertSessionHasErrors('equipo_pc_id');
        $this->actingAs($this->admin)->get('/lector/resolver?entrada=EXT-77&tipos=equipo_pc')->assertJson(['resultados' => []]);
    }

    public function test_alcance_de_sede(): void
    {
        $dePlaya = $this->equipo('EXT-P1', 'EXTINTOR', $this->playa);
        $deCentro = $this->equipo('EXT-C1');
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $recorridoCentro = $this->recorrido();

        $this->actingAs($supervisor)->get('/equipos-pc')->assertOk()->assertSee('EXT-C1')->assertDontSee('EXT-P1');
        $this->actingAs($supervisor)->post('/equipos-pc', ['sede_id' => $this->playa->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'NUEVO'])
            ->assertSessionHasErrors('sede_id');
        $this->actingAs($supervisor)->put("/equipos-pc/{$dePlaya->id}", ['sede_id' => $this->playa->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'X'])->assertNotFound();
        $this->actingAs($supervisor)->post('/recorridos-pc', ['sede_id' => $this->playa->id])->assertSessionHasErrors(['sede_id' => 'Elige una de tus sedes activas.']);

        // El agente de Playa no ve ni toca el recorrido de Centro
        $this->actingAs($agentePlaya)->get('/recorridos-pc')->assertOk()->assertDontSee($recorridoCentro->folio());
        $this->actingAs($agentePlaya)->get("/recorridos-pc/{$recorridoCentro->id}")->assertNotFound();
        $this->punto($recorridoCentro, $deCentro, [], null, $agentePlaya)->assertNotFound();
        // Su lector tampoco encuentra equipos de Centro
        $this->actingAs($agentePlaya)->get('/lector/resolver?entrada=EXT-C1&tipos=equipo_pc')->assertJson(['resultados' => []]);
    }

    public function test_el_agente_consulta_el_catalogo_pero_no_da_de_alta_y_si_hace_recorridos(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $e = $this->equipo('EXT-01');

        $this->actingAs($agente)->get('/equipos-pc')->assertOk()->assertSee('EXT-01')
            ->assertDontSee('Nuevo Equipo')->assertDontSee('dialogoEditarEquipoPc')->assertDontSee(route('equipos_pc.etiqueta', $e->id));
        $this->actingAs($agente)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'X'])->assertForbidden();
        $this->actingAs($agente)->put("/equipos-pc/{$e->id}", [])->assertForbidden();
        $this->actingAs($agente)->patch("/equipos-pc/{$e->id}/desactivar")->assertForbidden();
        $this->actingAs($agente)->get("/equipos-pc/{$e->id}/etiqueta")->assertForbidden();
        $this->actingAs($agente)->get("/equipos-pc/{$e->id}/qr")->assertOk();

        // Su sede viene elegida en «Nuevo Recorrido»
        $pagina = $this->actingAs($agente)->get('/recorridos-pc')->assertOk()->assertSee('Nuevo Recorrido')->assertDontSee('Exportar')->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$this->centro->id.'" selected/', $pagina);
        $r = $this->recorrido($agente);
        $this->punto($r, $e, [], null, $agente)->assertSessionHasNoErrors();
        $this->actingAs($agente)->get('/recorridos-pc/exportar')->assertForbidden();
        $this->actingAs($agente)->get('/recorridos-pc/reporte')->assertOk();
    }

    public function test_un_usuario_sin_permiso_no_entra(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($rh)->get('/recorridos-pc')->assertForbidden();
        $this->actingAs($rh)->get('/equipos-pc')->assertForbidden();
        $this->actingAs($rh)->post('/recorridos-pc', ['sede_id' => $this->centro->id])->assertForbidden();
        $this->actingAs($rh)->get('/recorridos-pc/reporte')->assertForbidden();
    }

    // ------------------------------------------------------- Lector universal

    public function test_el_lector_universal_encuentra_el_equipo_y_el_recorrido_lo_usa(): void
    {
        $e = $this->equipo('EXT-01', 'EXTINTOR', null, ['etiqueta_nfc' => '04A23B1C']);
        $this->assertSame(EquipoPc::class, config('lector.tipos.equipo_pc'));

        foreach (['ext-01', '04:a2:3b:1c', route('lector.ir', $e->codigo_qr)] as $lectura) {
            $this->actingAs($this->admin)->get('/lector/resolver?entrada='.urlencode($lectura).'&tipos=equipo_pc')
                ->assertJsonPath('resultados.0.id', $e->id)->assertJsonPath('resultados.0.tipo', 'equipo_pc')->assertJsonPath('resultados.0.titulo', 'EXT-01');
        }

        // La pantalla del recorrido escanea con el componente universal (no reimplementa la cámara ni el NFC)
        $r = $this->recorrido();
        $pagina = $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}")->assertOk()->assertSee('Lector ID / Escáner QR / NFC / RFID')->getContent();
        $this->assertStringContainsString('data-tipos="equipo_pc"', $pagina);
        $this->assertStringContainsString('data-escaner-rpc', $pagina);
        $this->assertStringNotContainsString('html5-qrcode', $pagina);

        // El QR (o la etiqueta NFC con dirección) lleva al recorrido abierto, listo para inspeccionar
        $this->actingAs($this->admin)->get('/e/'.$e->codigo_qr)->assertRedirect(route('equipos_pc.ir', $e->id));
        $this->actingAs($this->admin)->get(route('equipos_pc.ir', $e->id))
            ->assertRedirect(route('recorridos_pc.show', ['recorrido' => $r->id, 'equipo' => $e->id]).'#punto');
        // Sin recorrido abierto, a su ficha del catálogo
        $this->punto($r, $e)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['finalizar' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('equipos_pc.ir', $e->id))->assertRedirect(route('equipos_pc.index').'#equipopc-'.$e->id);
    }

    public function test_la_etiqueta_lleva_qr_local_con_la_direccion_del_lector(): void
    {
        $e = $this->equipo('EXT-01');
        $this->actingAs($this->admin)->get("/equipos-pc/{$e->id}/etiqueta")->assertOk()
            ->assertSee('EXT-01')->assertSee('Extintor')->assertSee('<svg', false)->assertDontSee('qrserver');
        $this->actingAs($this->admin)->get("/equipos-pc/{$e->id}/qr")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    // ------------------------------------------------------------ Recorrido

    public function test_recorrido_completo_sin_hallazgos(): void
    {
        $ext = $this->equipo('EXT-01');
        $bot = $this->equipo('BOT-01', 'BOTIQUIN');
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');

        $this->actingAs($this->admin)->post('/recorridos-pc', ['sede_id' => $this->centro->id, 'espacio_id' => $torre->id, 'observaciones_generales' => 'Ronda nocturna'])
            ->assertRedirect()->assertSessionHas('ok');
        $r = $this->enEmpresa(fn () => RecorridoPc::firstOrFail());
        $this->assertSame([RecorridoPc::EN_PROCESO, 1, $torre->id], [$r->estatus, $r->numero, $r->espacio_id]);

        // Un punto a la vez: el formulario del equipo escaneado
        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}?equipo={$ext->id}")->assertOk()
            ->assertSee('Punto de Inspección #1')->assertSee('Manómetro')->assertSee('Criterios Operativos Universales')->assertSee('Guardar y escanear siguiente');

        $this->punto($r, $ext)->assertRedirect(route('recorridos_pc.show', $r->id).'#escanear')->assertSessionHas('ok', 'EXT-01 revisado: OK. Escanea el siguiente equipo.');
        $this->punto($r, $bot)->assertSessionHasNoErrors();
        // Volver a escanear el mismo equipo avisa
        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}?equipo={$ext->id}")->assertSee('Este equipo ya se revisó en este mismo recorrido');

        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['observaciones_generales' => 'Sin novedad', 'finalizar' => 1])->assertSessionHas('ok');
        $r = $this->fresco($r);
        $this->assertSame(RecorridoPc::COMPLETO, $r->estatus);
        $this->assertSame('Sin novedad', $r->observaciones_generales);
        $this->assertNotNull($r->finalizado_en);
        $this->assertNull($r->novedad_id);
        $this->assertSame(['ok', 'ok'], $r->revisiones->pluck('resultado')->all());
        $this->assertTrue($r->revisiones[0]->criterios['manometro']);
        $this->assertSame(0, $this->enEmpresa(fn () => Novedad::count()));
        $this->assertSame(['recorridos_pc.creado', 'recorridos_pc.finalizado'], Auditoria::where('auditable_type', RecorridoPc::class)->orderBy('id')->pluck('evento')->all());

        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}")->assertOk()->assertSee('Completo')->assertSee('Finalizado por')
            ->assertDontSee('Guardar y Continuar Después')->assertDontSee('data-escaner-rpc', false);
    }

    public function test_el_primer_hallazgo_abre_el_ticket_y_los_siguientes_se_anotan(): void
    {
        $ext = $this->equipo('EXT-01');
        $lam = $this->equipo('LAM-01', 'LAMPARA_EMERGENCIA');
        $det = $this->equipo('DH-01', 'DETECTOR_HUMO');
        $r = $this->recorrido();

        // Una pieza desmarcada (no se envía) cuenta como falla: SEGCAT la perdía
        $this->punto($r, $ext, ['manometro', 'precinto'], 'Manómetro en zona roja')->assertSessionHas('aviso');
        $r = $this->fresco($r);
        $ticket = $this->enEmpresa(fn () => Novedad::with('notas')->findOrFail($r->novedad_id));
        $this->assertSame('proteccion_civil', $ticket->categoria);
        $this->assertSame('VER DETALLE EN RECORRIDO PC '.$r->folio(), $ticket->ubicacion);
        $this->assertStringContainsString('EXT-01 (Extintor): Falla en Manómetro, Precinto. Obs: Manómetro en zona roja', $ticket->descripcion);
        $this->assertSame($this->centro->id, $ticket->sede_id);
        $this->assertCount(1, $ticket->notas);
        $this->assertSame(RecorridoPc::EN_PROCESO, $r->estatus); // sigue abierto: se puede continuar
        $this->assertFalse($r->revisiones[0]->criterios['manometro']);

        // Solo una observación también es hallazgo; se anota en el mismo ticket
        $this->punto($r, $lam, [], 'Faro izquierdo fundido')->assertSessionHasNoErrors();
        $this->punto($r, $det)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->enEmpresa(fn () => Novedad::count()));
        $notas = $this->enEmpresa(fn () => $ticket->notas()->pluck('texto')->all());
        $this->assertCount(2, $notas);
        $this->assertStringContainsString('LAM-01 (Lámpara de Emergencia). Obs: Faro izquierdo fundido', $notas[1]);

        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}")->assertSee('Generó ticket de seguimiento')->assertSee($ticket->folio())
            ->assertSee('2 con hallazgo')->assertSee('Falla en: Manómetro, Precinto');

        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['finalizar' => 1])->assertSessionHas('aviso');
        $this->assertSame(RecorridoPc::CON_HALLAZGOS, $this->fresco($r)->estatus);
        $this->assertTrue(Auditoria::where('evento', 'recorridos_pc.ticket_generado')->exists());
        $this->assertTrue(Auditoria::where('evento', 'novedades.creado')->exists());
    }

    public function test_si_el_ticket_ya_se_resolvio_un_nuevo_hallazgo_abre_otro(): void
    {
        $ext = $this->equipo('EXT-01');
        $r = $this->recorrido();
        $this->punto($r, $ext, ['manguera'])->assertSessionHasNoErrors();
        $primero = $this->fresco($r)->novedad_id;
        $this->enEmpresa(fn () => Novedad::whereKey($primero)->update(['estatus' => Novedad::RESUELTO, 'resolucion' => 'Cambiada']));

        $this->punto($r, $ext, ['boquilla'])->assertSessionHasNoErrors();
        $this->assertNotSame($primero, $this->fresco($r)->novedad_id);
        $this->assertSame(2, $this->enEmpresa(fn () => Novedad::count()));
    }

    public function test_sin_permiso_de_novedades_no_se_pierde_el_hallazgo(): void
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Brigadista', 'nivel_jerarquia' => 70]);
        foreach (Modulo::where('clave', 'recorridos_pc')->firstOrFail()->moduloAcciones()->with('accion')->get() as $ma) {
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Sede]);
        }
        $brigadista = $this->crearUsuario($this->empresa, 'Brigadista', $this->centro);
        $ext = $this->equipo('EXT-01');
        $r = $this->recorrido($brigadista);

        $this->punto($r, $ext, [], null, $brigadista)->assertSessionHasNoErrors();
        $this->punto($r, $ext, ['manguera'], null, $brigadista)->assertSessionHasErrors('observaciones');
        $this->assertSame(1, $this->fresco($r)->revisiones->count()); // el punto con falla no se guardó sin su ticket
    }

    public function test_transiciones_prohibidas_y_validaciones_del_recorrido(): void
    {
        $ext = $this->equipo('EXT-01');
        $baja = $this->equipo('EXT-99', 'EXTINTOR', null, ['activo' => false]);
        $dePlaya = $this->equipo('EXT-P1', 'EXTINTOR', $this->playa);
        $r = $this->recorrido();

        $this->actingAs($this->admin)->post('/recorridos-pc', [])->assertSessionHasErrors(['sede_id' => 'Elige la sede donde harás el recorrido.']);
        $torrePlaya = $this->espacio($this->playa, Espacio::EDIFICIO, 'Torre P');
        $this->actingAs($this->admin)->post('/recorridos-pc', ['sede_id' => $this->centro->id, 'espacio_id' => $torrePlaya->id])->assertSessionHasErrors('espacio_id');

        // Finalizar sin equipos
        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['finalizar' => 1])
            ->assertSessionHasErrors(['finalizar' => 'Agrega al menos un equipo al recorrido antes de finalizarlo.']);
        $this->assertSame(RecorridoPc::EN_PROCESO, $this->fresco($r)->estatus);

        // Equipo de otra sede, dado de baja, o captura incompleta
        $this->punto($r, $dePlaya)->assertSessionHasErrors('equipo_pc_id');
        $this->punto($r, $baja)->assertSessionHasErrors('equipo_pc_id');
        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}?equipo={$dePlaya->id}")->assertSee('Ese equipo no es de');
        $this->actingAs($this->admin)->post("/recorridos-pc/{$r->id}/revisiones", ['_punto' => 'manual'])
            ->assertSessionHasErrors(['identificador' => 'Escanea el equipo o escribe su Núm. de Serie / ID.', 'categoria' => 'Elige la Categoría del Equipo.']);

        // «Guardar y Continuar Después» deja En Proceso
        $this->punto($r, $ext)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['observaciones_generales' => 'Pausa'])
            ->assertRedirect(route('recorridos_pc.index').'#recorrido-'.$r->id);
        $this->assertSame([RecorridoPc::EN_PROCESO, 'Pausa'], [$this->fresco($r)->estatus, $this->fresco($r)->observaciones_generales]);
        $this->actingAs($this->admin)->get('/recorridos-pc')->assertSee('Continuar Recorrido');

        // Finalizado: ya no recibe puntos, ni se vuelve a finalizar o editar
        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['finalizar' => 1])->assertSessionHasNoErrors();
        $this->punto($r, $ext)->assertSessionHasErrors('recorrido');
        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['finalizar' => 1])->assertSessionHasErrors('recorrido');
        $this->actingAs($this->admin)->put("/recorridos-pc/{$r->id}", ['observaciones_generales' => 'Cambio'])->assertSessionHasErrors('recorrido');
        $this->assertSame(1, $this->fresco($r)->revisiones->count());
        $this->assertSame(RecorridoPc::COMPLETO, $this->fresco($r)->estatus);
    }

    public function test_captura_a_mano_y_ligado_automatico_al_catalogo(): void
    {
        $ext = $this->equipo('EXT-01');
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $r = $this->recorrido();
        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}?manual=1")->assertOk()->assertSee('Captura a mano')->assertSee('1. Extintores')->assertSee('13. Lámparas de Emergencia');

        $this->actingAs($this->admin)->post("/recorridos-pc/{$r->id}/revisiones", ['_punto' => 'manual', 'identificador' => 'ext-50', 'categoria' => 'BOTIQUIN',
            'zona_id' => $torre->id, 'criterios' => $this->criterios('BOTIQUIN')])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/recorridos-pc/{$r->id}/revisiones", ['_punto' => 'manual', 'identificador' => 'ext-01', 'categoria' => 'BOTIQUIN',
            'criterios' => $this->criterios('EXTINTOR')])->assertSessionHasNoErrors();
        [$manual, $ligado] = $this->fresco($r)->revisiones->all();
        $this->assertSame(['EXT-50', 'BOTIQUIN', null, 'Torre A', 'ok'], [$manual->identificador, $manual->categoria, $manual->equipo_pc_id, $manual->ubicacion, $manual->resultado]);
        $this->assertSame([$ext->id, 'EXTINTOR', 'ok'], [$ligado->equipo_pc_id, $ligado->categoria, $ligado->resultado]);
    }

    public function test_avance_con_pendientes_de_la_zona(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $torre);
        $a = $this->equipo('EXT-01', 'EXTINTOR', null, ['espacio_id' => $piso->id]);
        $this->equipo('EXT-02', 'EXTINTOR', null, ['espacio_id' => $torre->id]);
        $this->equipo('EXT-03'); // sin ubicación: fuera de la zona
        $this->actingAs($this->admin)->post('/recorridos-pc', ['sede_id' => $this->centro->id, 'espacio_id' => $torre->id]);
        $r = $this->enEmpresa(fn () => RecorridoPc::firstOrFail());
        $this->punto($r, $a);

        $this->actingAs($this->admin)->get("/recorridos-pc/{$r->id}")->assertSee('1 de 2 equipos revisados')->assertSee('Equipos pendientes de revisar (1)')->assertSee('EXT-02');
    }

    // ------------------------------------------------- Reporte y exportación

    public function test_reporte_de_auditoria_y_exportacion(): void
    {
        $ext = $this->equipo('EXT-01');
        $r = $this->recorrido();
        $this->punto($r, $ext, ['manometro'], 'Rojo');
        $otro = $this->recorrido(null, $this->playa);

        $this->actingAs($this->admin)->get('/recorridos-pc/reporte')->assertOk()->assertSee('Reporte de Auditoría — Recorridos de Protección Civil')
            ->assertSee($r->folio())->assertSee($otro->folio())->assertSee('FALLA')->assertSee('Falla en: Manómetro')->assertSee('Exportar Excel');
        $this->actingAs($this->admin)->get('/recorridos-pc/reporte?sede='.$this->playa->id)->assertDontSee('Falla en: Manómetro');
        $this->actingAs($this->admin)->get('/recorridos-pc/reporte?desde=2020-01-01&hasta=2020-01-31')->assertSee('No hay recorridos registrados en este rango de fechas.');

        $csv = $this->actingAs($this->admin)->get('/recorridos-pc/exportar')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('EXT-01', $csv);
        $this->assertStringContainsString('Manómetro', $csv);
        $this->assertStringContainsString('FALLA', $csv);
    }

    public function test_lista_filtros_y_menu(): void
    {
        $this->recorrido();
        $this->actingAs($this->admin)->get('/recorridos-pc')->assertOk()->assertSee('Recorridos de Protección Civil')
            ->assertDontSee('Catálogo de Equipos')->assertSee('Padrones → Equipos de Protección Civil')->assertSee('Reporte de Auditoría')->assertSee('En Proceso')->assertSee('Con Hallazgos')
            ->assertSee('Iniciado por')->assertSee('data-filtro-rpc-estatus', false);
        $this->assertSame('recorridos_pc.index', Modulo::where('clave', 'recorridos_pc')->value('ruta'));
        $this->assertSame(RevisionRecorridoPc::OK, 'ok');
    }
}
