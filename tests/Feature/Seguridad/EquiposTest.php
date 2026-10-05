<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use App\Services\Equipos\AdministradorEquipos;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class EquiposTest extends TestCase
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

    private function tipo(string $nombre = 'Radio de Comunicación', ?Empresa $empresa = null, bool $activo = true): TipoEquipo
    {
        return $this->enEmpresa(fn () => TipoEquipo::firstOrCreate(['nombre' => $nombre], ['activo' => $activo]), $empresa);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'sede_id' => $this->centro->id,
            'tipo_equipo_id' => $this->tipo()->id,
            'marca' => 'motorola',
            'modelo' => 'dep  450',
            'numero_serie' => ' 752tsfq504 ',
            'costo' => '4800',
            'observaciones' => 'Radio usado',
        ], $extra);
    }

    private function equipo(string $serie, array $extra = [], ?Empresa $empresa = null, ?Sede $sede = null, ?User $autor = null, string $estado = 'disponible'): Equipo
    {
        $empresa ??= $this->empresa;
        $sede ??= $empresa->is($this->empresa) ? $this->centro : $this->crearSede($empresa, 'X'.random_int(10, 99));
        $tipo = $this->enEmpresa(fn () => TipoEquipo::firstOrCreate(['nombre' => 'Radio de Comunicación']), $empresa);

        return $this->enEmpresa(function () use ($serie, $extra, $sede, $tipo, $autor, $estado) {
            $e = new Equipo(array_merge(['sede_id' => $sede->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA', 'modelo' => 'DEP 450', 'numero_serie' => $serie], $extra));
            $e->forceFill(['estado' => $estado, 'creado_por' => $autor?->id])->save();

            return $e;
        }, $empresa);
    }

    private function buscarSerie(string $serie): ?Equipo
    {
        return $this->enEmpresa(fn () => Equipo::where('numero_serie', $serie)->first());
    }

    // ---------------------------------------------------------------- Lista

    public function test_lista_con_fichas_textos_de_segcat_y_filtros(): void
    {
        $radio = $this->equipo('752TSFQ504', ['costo' => 4800]);
        $this->equipo('LT-0001', [], null, $this->playa, null, 'en_mantenimiento');

        $this->actingAs($this->admin)->get('/equipos')->assertOk()
            ->assertSee('Catálogo de Equipo de Seguridad')
            ->assertSee('Radios, lámparas tácticas, fornituras y demás equipo de guardia — prestable, se asigna a cada colaborador.')
            ->assertSee('Nuevo Equipo')->assertSee('ID / NÚMERO DE SERIE')
            ->assertSee('id="equipo-'.$radio->id.'"', false)
            ->assertSee('752TSFQ504')->assertSee('MOTOROLA DEP 450')->assertSee('Sede CEN')->assertSee('Sede PLA')
            ->assertSee('estado-eq-disponible', false)->assertSee('estado-eq-en_mantenimiento', false)
            ->assertSee('EN MANTENIMIENTO')->assertSee('Baja/Perdido')
            ->assertSee('data-filtro-equipos="sede"', false)->assertSee('data-filtro-estado-equipo="asignado"', false)
            ->assertSee(route('equipos.etiqueta', $radio->id))->assertSee(route('equipos.qr', $radio->id))
            // Etiqueta NFC al dar de alta y responsable de la baja con el lector universal
            ->assertSee('Etiqueta NFC / RFID (opcional)')->assertSee('data-tipos="colaborador"', false)
            // Sugerencias de marca y modelo de la empresa
            ->assertSee('<datalist id="eq-modelos"><option value="DEP 450">', false);
    }

    public function test_sin_equipos_muestra_estado_vacio(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/equipos')->assertOk()->assertSee('Todavía no hay equipos registrados');
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_normaliza_nace_disponible_y_audita(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/equipos', $this->datos());

        $e = $this->buscarSerie('752TSFQ504');
        $this->assertNotNull($e);
        $respuesta->assertRedirect(route('equipos.index').'#equipo-'.$e->id)
            ->assertSessionHas('ok', 'Equipo 752TSFQ504 registrado correctamente. Ya puedes imprimir su etiqueta QR.');
        $this->assertSame(['MOTOROLA', 'DEP 450', 'disponible', '4800.00'], [$e->marca, $e->modelo, $e->estado, $e->costo]);
        $this->assertSame([$this->empresa->id, $this->centro->id, $this->admin->id], [$e->empresa_id, $e->sede_id, $e->creado_por]);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $e->codigo_qr);
        $this->assertSame('752TSFQ504', Auditoria::where('evento', 'equipos.creado')->where('auditable_id', $e->id)->firstOrFail()->despues['numero_serie']);
    }

    public function test_nuevo_tipo_al_vuelo_sin_duplicar_y_validaciones_en_espanol(): void
    {
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['tipo_equipo_id' => AdministradorEquipos::TIPO_NUEVO, 'nombre_tipo_nuevo' => ' Chaleco  antibalas ']))
            ->assertSessionHasNoErrors();
        $tipo = $this->enEmpresa(fn () => TipoEquipo::where('nombre', 'Chaleco antibalas')->firstOrFail());
        $this->assertSame($tipo->id, $this->buscarSerie('752TSFQ504')->tipo_equipo_id);
        $this->assertDatabaseHas('auditoria', ['evento' => 'equipos.tipo_creado', 'auditable_id' => $tipo->id]);

        // El mismo nombre (otras mayúsculas) reutiliza el tipo
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['tipo_equipo_id' => AdministradorEquipos::TIPO_NUEVO, 'nombre_tipo_nuevo' => 'CHALECO ANTIBALAS', 'numero_serie' => 'CA-2']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->enEmpresa(fn () => TipoEquipo::whereRaw('LOWER(nombre) = ?', ['chaleco antibalas'])->count()));

        $this->actingAs($this->admin)->post('/equipos', ['tipo_equipo_id' => AdministradorEquipos::TIPO_NUEVO, '_dialogo' => 'crear'])
            ->assertSessionHasErrors([
                'sede_id' => 'Elige la sede donde está el equipo.',
                'nombre_tipo_nuevo' => 'Escribe el nombre del nuevo tipo de equipo.',
                'numero_serie' => 'El número de serie / ID es obligatorio.',
            ]);
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['numero_serie' => 'Z1', 'costo' => '-5']))
            ->assertSessionHasErrors(['costo' => 'El costo no puede ser negativo.']);

        // Un tipo de otra empresa o desactivado no se puede elegir
        $ajeno = $this->tipo('Radio', $this->crearEmpresa('Hotel Dos'));
        $inactivo = $this->tipo('Fornitura', null, false);
        foreach ([$ajeno->id, $inactivo->id, 'abc'] as $valor) {
            $this->actingAs($this->admin)->post('/equipos', $this->datos(['tipo_equipo_id' => $valor, 'numero_serie' => 'Z2']))
                ->assertSessionHasErrors(['tipo_equipo_id' => 'Elige un tipo de equipo de la lista.']);
        }
    }

    public function test_error_de_validacion_reabre_el_dialogo_con_el_mensaje_dentro(): void
    {
        $this->equipo('752TSFQ504');
        $pagina = $this->actingAs($this->admin)->from('/equipos')->followingRedirects()
            ->post('/equipos', $this->datos(['_dialogo' => 'crear']))
            ->assertOk()
            ->assertSee('ya está registrado en esta empresa')->getContent();
        // El diálogo de alta se vuelve a abrir solo (el aviso se copia dentro) con lo capturado
        $this->assertMatchesRegularExpression('/id="dialogoNuevoEquipo"[^>]*data-abrir-al-cargar/', $pagina);
        $this->assertStringContainsString('value="752tsfq504"', $pagina);
    }

    public function test_serie_unica_por_empresa_y_no_global(): void
    {
        $this->equipo('752TSFQ504');
        $this->actingAs($this->admin)->post('/equipos', $this->datos())
            ->assertSessionHasErrors(['numero_serie' => 'El número de serie «752TSFQ504» ya está registrado en esta empresa (Radio de Comunicación · Sede CEN).']);

        $baja = $this->equipo('B-1', [], null, null, null, 'baja');
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['numero_serie' => 'b-1']))
            ->assertSessionHasErrors(['numero_serie' => 'El número de serie «B-1» ya está registrado en esta empresa (Radio de Comunicación · Sede CEN), dado de baja: reactívalo en lugar de registrarlo otra vez.']);

        // Otra empresa puede tener la misma serie (SEGCAT la bloqueaba en toda la base)
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->equipo('752TSFQ504', [], $otra);
        $this->assertSame(1, $this->enEmpresa(fn () => Equipo::where('numero_serie', '752TSFQ504')->count()));

        // Al editar, la suya propia no cuenta como repetida
        $this->actingAs($this->admin)->put("/equipos/{$baja->id}", $this->datos(['numero_serie' => 'B-1']))->assertSessionHasNoErrors();
    }

    public function test_etiqueta_nfc_normalizada_y_unica_dice_quien_la_tiene(): void
    {
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['etiqueta_nfc' => '04:a2:3b:1c']))->assertSessionHasNoErrors();
        $this->assertSame('04A23B1C', $this->buscarSerie('752TSFQ504')->etiqueta_nfc);

        $this->actingAs($this->admin)->post('/equipos', $this->datos(['numero_serie' => 'R-2', 'etiqueta_nfc' => '04A23B1C']))
            ->assertSessionHasErrors(['etiqueta_nfc' => 'Esa etiqueta NFC / RFID ya está asignada a otro registro: Equipo «752TSFQ504». Usa otra etiqueta o quítasela primero a ese registro.']);

        // También si la tiene otro tipo de registro (un colaborador)
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '7', 'nombre' => 'Eva', 'apellido_paterno' => 'Pérez', 'etiqueta_nfc' => 'AABBCCDD']));
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['numero_serie' => 'R-3', 'etiqueta_nfc' => 'aa:bb:cc:dd']))
            ->assertSessionHasErrors(['etiqueta_nfc' => 'Esa etiqueta NFC / RFID ya está asignada a otro registro: Colaborador «Eva Pérez». Usa otra etiqueta o quítasela primero a ese registro.']);
    }

    // ---------------------------------------------------------------- Edición y estados

    public function test_edicion_solo_elige_disponible_o_mantenimiento(): void
    {
        $e = $this->equipo('752TSFQ504');

        $this->actingAs($this->admin)->put("/equipos/{$e->id}", $this->datos(['estado' => 'en_mantenimiento', 'sede_id' => $this->playa->id, 'costo' => '']))
            ->assertRedirect(route('equipos.index').'#equipo-'.$e->id)->assertSessionHas('ok', 'Equipo 752TSFQ504 actualizado correctamente.');
        $e->refresh();
        $this->assertSame(['en_mantenimiento', $this->playa->id, null], [$e->estado, $e->sede_id, $e->costo]);
        $this->assertSame($this->admin->id, $e->actualizado_por);
        $auditoria = Auditoria::where('evento', 'equipos.actualizado')->where('auditable_id', $e->id)->firstOrFail();
        $this->assertSame(['disponible', 'en_mantenimiento'], [$auditoria->antes['estado'], $auditoria->despues['estado']]);

        // La baja no se hace editando
        $this->actingAs($this->admin)->put("/equipos/{$e->id}", $this->datos(['estado' => 'baja']))->assertSessionHasErrors('estado');
        $this->actingAs($this->admin)->put("/equipos/{$e->id}", $this->datos(['estado' => 'asignado']))->assertSessionHasErrors('estado');

        // ASIGNADO (lo pone Responsivas) se conserva al editar los datos
        $asignado = $this->equipo('A-1', [], null, null, null, 'asignado');
        $this->actingAs($this->admin)->put("/equipos/{$asignado->id}", $this->datos(['numero_serie' => 'A-1', 'estado' => 'disponible']))->assertSessionHasNoErrors();
        $this->assertSame('asignado', $asignado->fresh()->estado);
    }

    public function test_enganche_de_responsivas_asigna_y_devuelve(): void
    {
        $e = $this->equipo('R-1');
        $servicio = app(AdministradorEquipos::class);
        $this->actingAs($this->admin);

        $this->enEmpresa(fn () => $servicio->asignarPorResponsiva($this->admin, $e, true));
        $this->assertSame('asignado', $e->fresh()->estado);
        $this->enEmpresa(fn () => $servicio->asignarPorResponsiva($this->admin, $e, false));
        $this->assertSame('disponible', $e->fresh()->estado);
        $this->assertDatabaseHas('auditoria', ['evento' => 'equipos.asignado', 'auditable_id' => $e->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'equipos.devuelto', 'auditable_id' => $e->id]);
    }

    // ---------------------------------------------------------------- Baja con voucher

    public function test_baja_con_voucher_cobro_costo_sugerido_y_reactivar(): void
    {
        $e = $this->equipo('752TSFQ504', ['costo' => 4800]);
        $eva = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '7', 'nombre' => 'Eva', 'apellido_paterno' => 'Pérez']));

        // El monto sugerido sale del costo del equipo
        $this->actingAs($this->admin)->get('/equipos')->assertSee('&quot;monto&quot;:&quot;4800.00&quot;', false);

        // Con cobro, monto y responsable son obligatorios (y el mensaje vuelve al diálogo de la baja)
        $this->actingAs($this->admin)->post("/equipos/{$e->id}/baja", ['motivo' => 'robado', 'aplica_cobro' => '1', '_dialogo' => 'baja-'.$e->id])
            ->assertSessionHasErrors(['monto' => 'Indica el monto a cobrar.', 'colaborador_id' => 'Elige al responsable al que se le cobrará.']);
        $this->assertSame('disponible', $e->fresh()->estado);

        $this->actingAs($this->admin)->post("/equipos/{$e->id}/baja", [
            'motivo' => 'extraviado', 'descripcion' => 'Se perdió en el rondín', 'aplica_cobro' => '1', 'monto' => '4500', 'colaborador_id' => $eva->id,
        ])->assertRedirect(route('equipos.index').'#equipo-'.$e->id);

        $this->assertSame('baja', $e->fresh()->estado);
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::where('origen_tipo', 'equipo')->where('origen_id', $e->id)->firstOrFail());
        $this->assertSame(['extraviado', true, '4500.00', $eva->id, $this->centro->id], [$voucher->motivo, $voucher->aplica_cobro, $voucher->monto, $voucher->colaborador_id, $voucher->sede_id]);
        $this->assertSame('Radio de Comunicación MOTOROLA DEP 450 · S/N 752TSFQ504', $voucher->origen_descripcion);
        $this->assertDatabaseHas('auditoria', ['evento' => 'equipos.desactivado', 'auditable_id' => $e->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vouchers.creado', 'auditable_id' => $voucher->id]);

        // Ya de baja: no se repite; aparece el botón de reactivar
        $this->actingAs($this->admin)->post("/equipos/{$e->id}/baja", ['motivo' => 'robado'])->assertSessionHasErrors('motivo');
        $this->actingAs($this->admin)->get('/equipos')->assertSee(route('equipos.reactivar', $e->id))->assertSee('BAJA/PERDIDO');

        // El costo cobrado se recuerda para el mismo MARCA|MODELO
        $otro = $this->equipo('752TSFQ505', ['costo' => 4800]);
        $this->assertSame('4500.00', $this->enEmpresa(fn () => app(AdministradorEquipos::class)->costoSugerido($otro)));

        // Reactivar: vuelve a DISPONIBLE y el voucher se conserva
        $this->actingAs($this->admin)->patch("/equipos/{$e->id}/reactivar")
            ->assertSessionHas('ok', 'Equipo 752TSFQ504 reactivado: ya vuelve a estar DISPONIBLE.');
        $this->assertSame('disponible', $e->fresh()->estado);
        $this->assertSame(1, VoucherReposicion::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('auditoria', ['evento' => 'equipos.reactivado', 'auditable_id' => $e->id]);
        $this->actingAs($this->admin)->patch("/equipos/{$e->id}/reactivar")->assertSessionHasErrors('estado');
    }

    public function test_baja_sin_cobro_y_responsable_de_otra_empresa(): void
    {
        $e = $this->equipo('LT-1', ['costo' => null]);
        $ajeno = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '9', 'nombre' => 'Otro', 'apellido_paterno' => 'X']), $this->crearEmpresa('Hotel Dos'));

        $this->actingAs($this->admin)->post("/equipos/{$e->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => '1', 'monto' => '10', 'colaborador_id' => $ajeno->id])
            ->assertSessionHasErrors(['colaborador_id' => 'El responsable no pertenece a esta empresa.']);

        $this->actingAs($this->admin)->post("/equipos/{$e->id}/baja", ['motivo' => 'danado'])->assertSessionHasNoErrors();
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());
        $this->assertSame([false, '0.00'], [$voucher->aplica_cobro, $voucher->monto]);
    }

    // ---------------------------------------------------------------- Etiqueta y lector

    public function test_etiqueta_y_ver_qr_con_qr_generado_localmente(): void
    {
        $e = $this->equipo('752TSFQ504');

        $this->actingAs($this->admin)->get("/equipos/{$e->id}/etiqueta")->assertOk()
            ->assertSee('Radio de Comunicación')->assertSee('752TSFQ504')->assertSee('<svg', false)
            ->assertSee('Imprimir Etiqueta')->assertSee('Sede CEN')
            ->assertDontSee('qrserver')->assertDontSee('api.qrserver.com');

        $qr = $this->actingAs($this->admin)->get("/equipos/{$e->id}/qr")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $qr->getContent());
    }

    public function test_el_lector_encuentra_el_equipo_por_qr_etiqueta_o_serie(): void
    {
        $e = $this->equipo('752TSFQ504', ['etiqueta_nfc' => '00:bc:61:4e']);

        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada='.urlencode(route('lector.ir', $e->codigo_qr)))
            ->assertJsonPath('resultados.0.tipo', 'equipo')->assertJsonPath('resultados.0.id', $e->id);
        // Lector de 125 kHz (decimal) de la misma tarjeta
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=0012345678&tipos=equipo')->assertJsonPath('resultados.0.id', $e->id);
        // Número de serie tecleado (o leído con lector de código de barras)
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=752tsfq504&tipos=equipo')
            ->assertJsonPath('resultados.0.titulo', '752TSFQ504')
            ->assertJsonPath('resultados.0.detalle', 'Radio de Comunicación MOTOROLA DEP 450 · DISPONIBLE · Sede CEN');

        $this->actingAs($this->admin)->get('/e/'.$e->codigo_qr)->assertRedirect(route('equipos.index').'#equipo-'.$e->id);
    }

    // ---------------------------------------------------------------- Aislamiento y alcance

    public function test_cada_empresa_ve_y_toca_solo_sus_equipos(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->equipo('AJENO-1', [], $otra);
        $this->equipo('MIO-1');

        $this->actingAs($this->admin)->get('/equipos')->assertOk()->assertSee('MIO-1')->assertDontSee('AJENO-1');
        $this->actingAs($this->admin)->put("/equipos/{$ajeno->id}", $this->datos())->assertNotFound();
        $this->actingAs($this->admin)->post("/equipos/{$ajeno->id}/baja", ['motivo' => 'robado'])->assertNotFound();
        $this->actingAs($this->admin)->patch("/equipos/{$ajeno->id}/reactivar")->assertNotFound();
        $this->actingAs($this->admin)->get("/equipos/{$ajeno->id}/etiqueta")->assertNotFound();
        $this->actingAs($this->admin)->get("/equipos/{$ajeno->id}/qr")->assertNotFound();
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=AJENO-1')->assertJsonCount(0, 'resultados');

        // Una sede de otra empresa tampoco se acepta
        $sedeAjena = Sede::withoutGlobalScopes()->where('empresa_id', $otra->id)->firstOrFail();
        $this->actingAs($this->admin)->post('/equipos', $this->datos(['sede_id' => $sedeAjena->id]))
            ->assertSessionHasErrors(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
    }

    public function test_alcance_de_sede_solo_ve_y_toca_sus_sedes(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $delCentro = $this->equipo('CEN-1');
        $dePlaya = $this->equipo('PLA-1', [], null, $this->playa);

        $pagina = $this->actingAs($jefe)->get('/equipos')->assertOk()->assertSee('CEN-1')->assertDontSee('PLA-1')->getContent();
        $this->assertStringContainsString(route('equipos.update', $delCentro->id), $pagina);

        // Alta solo en su sede
        $this->actingAs($jefe)->post('/equipos', $this->datos(['numero_serie' => 'N-1', 'sede_id' => $this->playa->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($jefe)->post('/equipos', $this->datos(['numero_serie' => 'N-1']))->assertSessionHasNoErrors();

        // No toca los de otra sede ni los mueve a una sede ajena
        $this->actingAs($jefe)->put("/equipos/{$dePlaya->id}", $this->datos(['numero_serie' => 'PLA-1']))->assertNotFound();
        $this->actingAs($jefe)->post("/equipos/{$dePlaya->id}/baja", ['motivo' => 'robado'])->assertNotFound();
        $this->actingAs($jefe)->get("/equipos/{$dePlaya->id}/etiqueta")->assertNotFound();
        $this->actingAs($jefe)->put("/equipos/{$delCentro->id}", $this->datos(['numero_serie' => 'CEN-1', 'sede_id' => $this->playa->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($jefe)->post("/equipos/{$delCentro->id}/baja", ['motivo' => 'danado'])->assertSessionHasNoErrors();
        $this->assertSame('baja', $delCentro->fresh()->estado);
    }

    public function test_el_agente_consulta_pero_no_registra_ni_da_de_baja(): void
    {
        $e = $this->equipo('752TSFQ504');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/equipos')->assertOk()->assertSee('752TSFQ504')->assertSee(route('equipos.qr', $e->id))
            ->assertDontSee('Nuevo Equipo')->assertDontSee('dialogoEditarEquipo')->assertDontSee('dialogoBajaEquipo')
            ->assertDontSee(route('equipos.etiqueta', $e->id));
        $this->actingAs($agente)->get("/equipos/{$e->id}/qr")->assertOk();
        $this->actingAs($agente)->post('/equipos', $this->datos(['numero_serie' => 'N-1']))->assertForbidden();
        $this->actingAs($agente)->put("/equipos/{$e->id}", $this->datos())->assertForbidden();
        $this->actingAs($agente)->post("/equipos/{$e->id}/baja", ['motivo' => 'robado'])->assertForbidden();
        $this->actingAs($agente)->patch("/equipos/{$e->id}/reactivar")->assertForbidden();
        $this->actingAs($agente)->get("/equipos/{$e->id}/etiqueta")->assertForbidden();

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->get('/equipos')->assertForbidden();
        $this->actingAs($sinPermiso)->get("/equipos/{$e->id}/qr")->assertForbidden();
    }

    public function test_datos_demo_de_equipos_y_estacionamientos_solo_la_primera_vez(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $equipos = $this->enEmpresa(fn () => Equipo::all(), $demo);
        $this->assertCount(12, $equipos);
        // 4 están en resguardos demo de Responsivas (ASIGNADO)
        $this->assertSame(['asignado' => 4, 'baja' => 1, 'disponible' => 6, 'en_mantenimiento' => 1], $equipos->countBy('estado')->sortKeys()->all());
        $this->assertSame(1, $this->enEmpresa(fn () => VoucherReposicion::where('origen_tipo', 'equipo')->count(), $demo));
        $zonas = $this->enEmpresa(fn () => ZonaEstacionamiento::all(), $demo);
        $this->assertCount(6, $zonas);
        $this->assertSame(2, $zonas->where('tipo', 'zona_descarga')->count());
    }

    public function test_menu_enlaza_a_equipos(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('equipos.index'));
    }
}
