<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Llave;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class LlavesTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'sede_id' => $this->centro->id,
            'nomenclatura' => ' ll-cat-sit-01 ',
            'descripcion' => 'Site central de TI',
            'tipo_dispositivo' => 'electronica_rfid',
            'alcance' => 'global',
        ], $extra);
    }

    private function llave(string $nomenclatura, array $extra = [], ?Empresa $empresa = null, ?Sede $sede = null): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $extra, $sede) {
            $l = new Llave(array_merge(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nomenclatura,
                'descripcion' => 'Acceso de prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global'], $extra));
            $l->save();

            return $l;
        }, $empresa);
    }

    private function buscar(string $nomenclatura): ?Llave
    {
        return $this->enEmpresa(fn () => Llave::with(['espacios', 'grupos', 'horarios'])->where('nomenclatura', $nomenclatura)->first());
    }

    private function espacio(Sede $sede, string $nivel, string $nombre, ?Espacio $padre = null): Espacio
    {
        return $this->enEmpresa(fn () => Espacio::create(['sede_id' => $sede->id, 'nivel' => $nivel, 'nombre' => $nombre, 'padre_id' => $padre?->id]));
    }

    // ---------------------------------------------------------------- Lista

    public function test_lista_con_fichas_horarios_caducidad_y_filtros(): void
    {
        $vencida = $this->llave('HDC-101', ['fecha_caducidad' => now()->subDays(5)->format('Y-m-d')]);
        $pronto = $this->llave('HDC-P1', ['fecha_caducidad' => now()->addDays(10)->format('Y-m-d'), 'tipo_dispositivo' => 'electronica_rfid']);
        $this->enEmpresa(fn () => $pronto->horarios()->create(['nombre' => 'Turno Limpieza', 'hora_inicio' => '08:00:00', 'hora_fin' => '16:00:00']));
        $this->llave('HDP-01', [], null, $this->playa);

        $this->actingAs($this->admin)->get('/llaves')->assertOk()
            ->assertSee('Catálogo de Llaves')->assertSee('Inventario maestro de accesos físicos y magnéticos.')
            ->assertSee('Nueva Llave')->assertSee('Imprimir Etiquetas')->assertSee('Exportar')->assertSee('Buscar llave o área...')
            ->assertSee('id="llave-'.$vencida->id.'"', false)
            ->assertSee('data-caducidad="vencida"', false)->assertSee('data-caducidad="pronto"', false)->assertSee('data-caducidad="sin"', false)
            ->assertSee('(VENCIDA)')->assertSee('(Vence pronto)')->assertSee('Turno Limpieza 08:00-16:00')->assertSee('24 horas (todos)')
            ->assertSee('Todas las sedes')->assertSee('Sede PLA')->assertSee('Alcance: Global (Master Key)')
            ->assertSee('data-filtro-llaves="tipo"', false)->assertSee('data-filtro-llaves="caducidad"', false)
            ->assertDontSee('onclick');
    }

    public function test_sin_llaves_muestra_el_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get('/llaves')->assertOk()->assertSee('Todavía no hay llaves registradas.');
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_normaliza_guarda_horarios_y_lugares_y_audita(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $piso1 = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $torre);
        $piso2 = $this->espacio($this->centro, Espacio::AREA, 'Piso 2', $torre);

        $this->actingAs($this->admin)->post('/llaves', $this->datos([
            'alcance' => 'piso', 'espacios' => [$piso1->id, $piso2->id],
            'id_externo' => ' vc-187 ', 'plataforma_externa' => 'VingCard', 'fecha_caducidad' => '2027-01-31',
            'etiqueta_nfc' => '04:a2:3b:1c',
            'horario_nombre' => ['Turno Limpieza', '', 'Nocturno'], 'horario_inicio' => ['08:00', '', '23:00'], 'horario_fin' => ['16:00', '', '07:00'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('ok', 'Llave LL-CAT-SIT-01 registrada correctamente. Ya puedes imprimir su etiqueta.');

        $l = $this->buscar('LL-CAT-SIT-01');
        $this->assertNotNull($l);
        $this->assertSame([$this->empresa->id, $this->centro->id, 'VC-187', '04A23B1C'], [$l->empresa_id, $l->sede_id, $l->id_externo, $l->etiqueta_nfc]);
        $this->assertSame('2027-01-31', $l->fecha_caducidad->format('Y-m-d'));
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $l->codigo_qr);
        $this->assertEqualsCanonicalizing([$piso1->id, $piso2->id], $l->espacios->pluck('id')->all());
        $this->assertSame(['Nocturno 23:00-07:00', 'Turno Limpieza 08:00-16:00'], $l->horarios->map(fn ($h) => $h->nombre.' '.$h->inicio().'-'.$h->fin())->sortBy(fn ($t) => $t)->values()->all());
        $this->assertTrue($l->horarios->firstWhere('nombre', 'Nocturno')->cruzaMedianoche());
        $this->assertSame($this->admin->id, $l->creado_por);

        $auditoria = Auditoria::where('evento', 'llaves.creado')->where('auditable_id', $l->id)->firstOrFail();
        $this->assertSame('LL-CAT-SIT-01', $auditoria->despues['nomenclatura']);
        $this->assertCount(2, $auditoria->despues['espacios']);

        $this->actingAs($this->admin)->get('/llaves')->assertSee('Piso 1 (Torre A)')->assertSee('ID Externo:')->assertSee('Creada por');
    }

    public function test_validaciones_con_mensajes_claros(): void
    {
        $this->actingAs($this->admin)->post('/llaves', ['_dialogo' => 'crear'])
            ->assertSessionHasErrors(['sede_id' => 'Elige la sede de la llave.', 'nomenclatura' => 'Escribe el nombre (nomenclatura) de la llave.',
                'descripcion' => 'Describe qué abre la llave (por ejemplo: Site central de TI).']);
        // El alta se reabre sola con los errores, para corregir dentro de la ventana
        $this->assertMatchesRegularExpression('/id="dialogoNuevaLlave"[^>]*data-abrir-al-cargar/', $this->actingAs($this->admin)->get('/llaves')->getContent());

        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'otra']))
            ->assertSessionHasErrors(['alcance_otro' => 'Escribe qué espacio abre (por ejemplo: Cuarto de máquinas).']);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'zona']))
            ->assertSessionHasErrors(['espacios' => 'Marca al menos una zona o edificio.']);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'seccion']))
            ->assertSessionHasErrors(['grupos' => 'Marca al menos una sección.']);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['tipo_dispositivo' => 'magia']))->assertSessionHasErrors('tipo_dispositivo');

        // Horario incompleto: se avisa (SEGCAT lo descartaba en silencio)
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['horario_nombre' => ['Limpieza'], 'horario_inicio' => ['08:00'], 'horario_fin' => ['']]))
            ->assertSessionHasErrors(['horario_nombre' => 'Completa el horario «Limpieza»: nombre, hora de inicio y hora de fin (o quítalo).']);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['horario_nombre' => ['X'], 'horario_inicio' => ['08:00'], 'horario_fin' => ['08:00']]))
            ->assertSessionHasErrors('horario_fin');

        // El "otra" se guarda solo con ese alcance; el ID externo solo en dispositivos programables
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['tipo_dispositivo' => 'metalica', 'id_externo' => 'X1', 'alcance_otro' => 'Algo']))->assertSessionHasNoErrors();
        $l = $this->buscar('LL-CAT-SIT-01');
        $this->assertNull($l->id_externo);
        $this->assertNull($l->alcance_otro);
        $this->assertSame(0, Llave::withoutGlobalScopes()->where('nomenclatura', '')->count());
    }

    public function test_nomenclatura_unica_por_sede_y_aviso_en_vivo(): void
    {
        // Ronda 5 (LL-03): el nombre no se repite en la misma sede
        $this->llave('LL-CAT-SIT-01');
        $this->actingAs($this->admin)->post('/llaves', $this->datos())
            ->assertSessionHasErrors(['nomenclatura' => 'Ya existe una llave con el nombre «LL-CAT-SIT-01» en la sede Sede CEN.']);

        $this->llave('BAJA-1', ['activo' => false]);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['nomenclatura' => 'baja-1']))
            ->assertSessionHasErrors(['nomenclatura' => 'Ya existe una llave con el nombre «BAJA-1» en la sede Sede CEN (dada de baja: reactívala en lugar de registrarla otra vez).']);

        // Otra empresa puede usar el mismo nombre
        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeOtra = $this->crearSede($otra, 'X1');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->post('/llaves', $this->datos(['sede_id' => $sedeOtra->id]))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/llaves')->assertSee('data-nombres-existentes', false)->assertSee('ll-cat-sit-01');
    }

    public function test_id_externo_unico_por_plataforma_y_sede_y_etiqueta_nfc_unica(): void
    {
        $this->llave('HDC-1', ['tipo_dispositivo' => 'electronica_rfid', 'id_externo' => 'VC-1', 'plataforma_externa' => 'VingCard', 'etiqueta_nfc' => 'AABBCCDD']);

        $this->actingAs($this->admin)->post('/llaves', $this->datos(['id_externo' => 'vc-1', 'plataforma_externa' => 'vingcard']))
            ->assertSessionHasErrors(['id_externo' => 'El ID externo «VC-1» de vingcard ya está asignado a la llave «HDC-1» en esta sede.']);
        // Otra plataforma u otra sede: permitido
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['nomenclatura' => 'A', 'id_externo' => 'VC-1', 'plataforma_externa' => 'Salto']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['nomenclatura' => 'B', 'sede_id' => $this->playa->id, 'id_externo' => 'VC-1', 'plataforma_externa' => 'VingCard']))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/llaves', $this->datos(['nomenclatura' => 'C', 'etiqueta_nfc' => 'aa:bb:cc:dd']))
            ->assertSessionHasErrors(['etiqueta_nfc' => 'Esa tarjeta o etiqueta NFC/RFID ya está asignada a la llave «HDC-1».']);
    }

    public function test_lugares_departamento_puesto_y_responsable_se_revalidan_contra_la_sede_y_la_empresa(): void
    {
        $torreCentro = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $torrePlaya = $this->espacio($this->playa, Espacio::EDIFICIO, 'Villas');
        $piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $torreCentro);
        $seccionPlaya = $this->enEmpresa(fn () => GrupoEspacio::create(['sede_id' => $this->playa->id, 'nombre' => 'Vista al mar']));

        // Un lugar de otra sede, o de otro nivel, se rechaza
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'zona', 'espacios' => [$torreCentro->id, $torrePlaya->id]]))
            ->assertSessionHasErrors(['espacios' => 'Algunos lugares marcados no pertenecen a la sede elegida o están desactivados. Revisa la lista.']);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'zona', 'espacios' => [$piso->id]]))->assertSessionHasErrors('espacios');
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'seccion', 'grupos' => [$seccionPlaya->id]]))->assertSessionHasErrors('grupos');

        // Departamento que solo aplica en playa
        $club = $this->enEmpresa(function () {
            $d = Departamento::create(['nombre' => 'Club de Playa', 'todas_las_sedes' => false]);
            $d->sedes()->sync([$this->playa->id]);

            return $d;
        });
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['departamento_id' => $club->id]))
            ->assertSessionHasErrors(['departamento_id' => 'El departamento no existe, está desactivado o no aplica en esa sede.']);

        // Puesto ligado a otro departamento
        [$seguridad, $camarista] = $this->enEmpresa(function () {
            $seg = Departamento::create(['nombre' => 'Seguridad']);
            $ama = Departamento::create(['nombre' => 'Ama de Llaves']);
            $p = Puesto::create(['nombre' => 'Camarista']);
            $p->departamentos()->sync([$ama->id]);

            return [$seg, $p];
        });
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['departamento_id' => $seguridad->id, 'puesto_id' => $camarista->id]))
            ->assertSessionHasErrors(['puesto_id' => 'El puesto «Camarista» no corresponde al departamento elegido.']);

        // Responsable de otra empresa
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '9', 'nombre' => 'Otro', 'apellido_paterno' => 'X']), $otra);
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['colaborador_id' => $ajeno->id]))
            ->assertSessionHasErrors(['colaborador_id' => 'El responsable no existe en esta empresa o está dado de baja.']);

        $carlos = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1006', 'nombre' => 'Carlos', 'apellido_paterno' => 'Pérez']));
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['colaborador_id' => $carlos->id, 'departamento_id' => $seguridad->id]))->assertSessionHasNoErrors();
        $this->assertSame($carlos->id, $this->buscar('LL-CAT-SIT-01')->colaborador_id);
        $this->actingAs($this->admin)->get('/llaves')->assertSee('Responsable: Carlos Pérez · Núm. 1006');

        // "Unir duplicados" de Colaboradores mueve también al responsable de la llave
        $this->assertSame('colaborador_id', AdministradorColaboradores::REFERENCIAS['llaves']);
    }

    // ------------------------------------------------------- Edición y estado

    public function test_edicion_reemplaza_lugares_y_horarios_y_audita(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $villas = $this->espacio($this->playa, Espacio::EDIFICIO, 'Villas');
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['alcance' => 'zona', 'espacios' => [$torre->id],
            'horario_nombre' => ['Mañana'], 'horario_inicio' => ['07:00'], 'horario_fin' => ['15:00']]))->assertSessionHasNoErrors();
        $l = $this->buscar('LL-CAT-SIT-01');

        // Cambia de sede: los lugares de la sede anterior ya no valen
        $this->actingAs($this->admin)->put("/llaves/{$l->id}", $this->datos(['sede_id' => $this->playa->id, 'alcance' => 'zona', 'espacios' => [$torre->id]]))
            ->assertSessionHasErrors('espacios');
        $this->actingAs($this->admin)->put("/llaves/{$l->id}", $this->datos(['sede_id' => $this->playa->id, 'alcance' => 'zona', 'espacios' => [$villas->id], 'descripcion' => 'Villas completas']))
            ->assertRedirect(route('llaves.index').'#llave-'.$l->id)->assertSessionHas('ok', 'Llave LL-CAT-SIT-01 actualizada correctamente.');

        $l = $this->buscar('LL-CAT-SIT-01');
        $this->assertSame([$this->playa->id, 'Villas completas'], [$l->sede_id, $l->descripcion]);
        $this->assertSame([$villas->id], $l->espacios->pluck('id')->all());
        $this->assertCount(0, $l->horarios);

        $auditoria = Auditoria::where('evento', 'llaves.actualizado')->firstOrFail();
        $this->assertSame([[$torre->id], [$villas->id]], [$auditoria->antes['espacios'], $auditoria->despues['espacios']]);
        $this->assertSame(['Mañana 07:00-15:00'], $auditoria->antes['horarios']);

        // Su propia nomenclatura no choca; la de otra llave de la MISMA sede sí (Ronda 5: el nombre es único por sede)
        $this->llave('OTRA', [], null, $this->playa);
        $this->actingAs($this->admin)->put("/llaves/{$l->id}", $this->datos(['sede_id' => $this->playa->id, 'nomenclatura' => 'otra']))->assertSessionHasErrors('nomenclatura');
        // El estado no se cambia desde la edición (solo con baja y voucher)
        $this->actingAs($this->admin)->put("/llaves/{$l->id}", $this->datos(['sede_id' => $this->playa->id, 'activo' => 0]))->assertSessionHasNoErrors();
        $this->assertTrue($this->buscar('LL-CAT-SIT-01')->activo);
    }

    public function test_baja_con_voucher_costo_sugerido_y_reactivacion(): void
    {
        $l = $this->llave('HDC-BOD-01');
        $javier = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1011', 'nombre' => 'Javier', 'apellido_paterno' => 'Ramírez']));

        // Con cobro: monto y responsable obligatorios
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['_dialogo' => 'baja-'.$l->id, 'motivo' => 'extraviado', 'aplica_cobro' => 1])
            ->assertSessionHasErrors(['monto' => 'Indica el monto a cobrar.', 'colaborador_id' => 'Elige al responsable al que se le cobrará.']);
        $this->assertTrue($l->fresh()->activo);
        $this->assertSame(0, VoucherReposicion::withoutGlobalScopes()->count());

        $respuesta = $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", [
            'motivo' => 'extraviado', 'descripcion' => 'Se perdió en la ronda', 'aplica_cobro' => 1, 'monto' => '350', 'colaborador_id' => $javier->id,
        ]);
        $voucher = VoucherReposicion::withoutGlobalScopes()->firstOrFail();
        $respuesta->assertRedirect(route('llaves.index').'#llave-'.$l->id)
            ->assertSessionHas('aviso', "Llave HDC-BOD-01 dada de baja. Se generó el voucher de reposición {$voucher->folio} con cobro de \$350.00. Si aparece, puedes reactivarla con un clic.");
        $this->assertFalse($l->fresh()->activo);
        $this->assertSame(['llave', $l->id, 'HDC-BOD-01', $this->centro->id, $javier->id, '350.00'],
            [$voucher->origen_tipo, $voucher->origen_id, $voucher->origen_descripcion, $voucher->sede_id, $voucher->colaborador_id, $voucher->monto]);
        $this->assertMatchesRegularExpression('/^VR-\d{4}-\d{5}$/', $voucher->folio);
        $this->assertSame($voucher->folio, Auditoria::where('evento', 'llaves.desactivado')->firstOrFail()->despues['voucher']);

        // El costo queda sugerido para la siguiente llave del mismo tipo
        $pagina = $this->actingAs($this->admin)->get('/llaves')->assertSee('BAJA')->getContent();
        $this->assertStringContainsString('&quot;metalica&quot;:&quot;350.00&quot;', $pagina);

        // Dos veces no
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'robado'])->assertSessionHasErrors('motivo');

        $this->actingAs($this->admin)->patch("/llaves/{$l->id}/reactivar")
            ->assertSessionHas('ok', 'Llave HDC-BOD-01 reactivada correctamente. Revisa su código QR: puedes reimprimir la etiqueta o asignarle otra tarjeta NFC/RFID.')
            ->assertSessionHas('identificacion', $l->id);
        $this->assertTrue($l->fresh()->activo);
        $this->assertSame(1, VoucherReposicion::withoutGlobalScopes()->count());
        $this->assertSame(1, Auditoria::where('evento', 'llaves.reactivado')->count());
        $this->assertSame(1, Auditoria::where('evento', 'vouchers.creado')->count());
    }

    // --------------------------------------------- Etiquetas, lector y exportación

    public function test_etiquetas_con_qr_generado_localmente(): void
    {
        $a = $this->llave('HDC-MASTER-01');
        $b = $this->llave('HDC-101', ['activo' => false]);

        $respuesta = $this->actingAs($this->admin)->get(route('llaves.imprimir', ['llaves' => [$a->id, $b->id]]))->assertOk()
            ->assertSee('Etiquetas de Llaveros Listas')->assertSee('HDC-MASTER-01')->assertSee('HDC-101')->assertSee('DADA DE BAJA')
            ->assertSee('Hotel Uno')->assertSee('<svg', false)->assertSee(trim(chunk_split($a->codigo_qr, 4, ' ')))
            ->assertDontSee('qrserver');
        $html = $respuesta->getContent();
        $this->assertStringNotContainsString('<?xml', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src="https?:/i', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertSame(2, substr_count($html, 'class="etiqueta-llave"'));

        // Sin selección: regresa con aviso; de otra empresa: no existe
        $this->actingAs($this->admin)->get(route('llaves.imprimir'))->assertRedirect(route('llaves.index'))->assertSessionHas('aviso');
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajena = $this->llave('AJENA', [], $otra, $this->crearSede($otra, 'Z1'));
        $this->actingAs($this->admin)->get(route('llaves.imprimir', ['llaves' => [$ajena->id]]))->assertNotFound();
    }

    public function test_el_lector_encuentra_la_llave_por_qr_etiqueta_o_nomenclatura(): void
    {
        $l = $this->llave('LL-CAT-SIT-01', ['etiqueta_nfc' => '00:bc:61:4e']);

        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada='.urlencode(route('lector.ir', $l->codigo_qr)))->assertOk()
            ->assertJsonPath('resultados.0.tipo', 'llave')->assertJsonPath('resultados.0.id', $l->id)->assertJsonPath('resultados.0.titulo', 'LL-CAT-SIT-01')
            ->assertJsonPath('resultados.0.sede_id', $this->centro->id);
        // Lector de 125 kHz (decimal) y nomenclatura tecleada
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=0012345678&tipos=llave')->assertJsonPath('resultados.0.id', $l->id);
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=ll-cat-sit-01&tipos=llave')->assertJsonPath('resultados.0.id', $l->id);

        $this->actingAs($this->admin)->get('/e/'.$l->codigo_qr)->assertRedirect(route('llaves.index').'#llave-'.$l->id);

        $otra = $this->crearEmpresa('Hotel Dos');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->get('/e/'.$l->codigo_qr)->assertNotFound();
    }

    public function test_exportar_csv_con_los_filtros(): void
    {
        $this->llave('HDC-MASTER-01', ['tipo_dispositivo' => 'electronica_rfid']);
        $this->llave('HDC-BOD-01');
        $this->llave('HDC-VIEJA', ['activo' => false]);

        $respuesta = $this->actingAs($this->admin)->get('/llaves/exportar?tipo=metalica&estado=1')->assertOk();
        $this->assertStringContainsString('text/csv', $respuesta->headers->get('Content-Type'));
        $csv = $respuesta->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Nombre de la llave', $csv);
        $this->assertStringContainsString('HDC-BOD-01', $csv);
        $this->assertStringNotContainsString('HDC-MASTER-01', $csv);
        $this->assertStringNotContainsString('HDC-VIEJA', $csv);

        $todo = $this->actingAs($this->admin)->get('/llaves/exportar?q=bod-01')->streamedContent();
        $this->assertStringContainsString('HDC-BOD-01', $todo);
        $this->assertStringNotContainsString('HDC-MASTER-01', $todo);
    }

    // ------------------------------------------------- Permisos y aislamiento

    public function test_cada_empresa_ve_y_toca_solo_sus_llaves(): void
    {
        $this->llave('MIA-1');
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajena = $this->llave('AJENA-1', [], $otra, $this->crearSede($otra, 'Z1'));

        $this->actingAs($this->admin)->get('/llaves')->assertSee('MIA-1')->assertDontSee('AJENA-1');
        $this->actingAs($this->admin)->put("/llaves/{$ajena->id}", $this->datos())->assertNotFound();
        $this->actingAs($this->admin)->post("/llaves/{$ajena->id}/baja", ['motivo' => 'robado'])->assertNotFound();
        $this->actingAs($this->admin)->patch("/llaves/{$ajena->id}/reactivar")->assertNotFound();
        $this->assertTrue(Llave::withoutGlobalScopes()->findOrFail($ajena->id)->activo);

        // Ni registrarla en una sede de otra empresa
        $this->actingAs($this->admin)->post('/llaves', $this->datos(['sede_id' => $ajena->sede_id]))
            ->assertSessionHasErrors(['sede_id' => 'Elige una de tus sedes activas.']);
        $this->assertStringNotContainsString('AJENA-1', $this->actingAs($this->admin)->get('/llaves/exportar')->streamedContent());
    }

    public function test_alcance_de_sede_solo_ve_y_registra_en_sus_sedes(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $deCentro = $this->llave('HDC-1');
        $dePlaya = $this->llave('HDP-1', [], null, $this->playa);

        $pagina = $this->actingAs($jefe)->get('/llaves')->assertOk()->assertSee('HDC-1')->assertDontSee('HDP-1')->getContent();
        $this->assertStringContainsString(route('llaves.update', $deCentro->id), $pagina);

        $this->actingAs($jefe)->post('/llaves', $this->datos(['sede_id' => $this->playa->id]))->assertSessionHasErrors('sede_id');
        $this->actingAs($jefe)->post('/llaves', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($jefe)->put("/llaves/{$dePlaya->id}", $this->datos(['nomenclatura' => 'HDP-1']))->assertNotFound();
        $this->actingAs($jefe)->post("/llaves/{$dePlaya->id}/baja", ['motivo' => 'robado'])->assertNotFound();
        $this->actingAs($jefe)->get(route('llaves.imprimir', ['llaves' => [$dePlaya->id]]))->assertNotFound();
        // El lector universal tampoco la encuentra fuera de sus sedes
        $this->actingAs($jefe)->getJson('/lector/resolver?entrada=hdp-1&tipos=llave')->assertJsonCount(0, 'resultados');
        $this->actingAs($jefe)->getJson('/lector/resolver?entrada=hdc-1&tipos=llave')->assertJsonPath('resultados.0.id', $deCentro->id);
    }

    public function test_el_agente_consulta_pero_no_registra_ni_imprime(): void
    {
        $l = $this->llave('HDC-1');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->actingAs($agente)->get('/llaves')->assertOk()->assertSee('HDC-1')
            ->assertDontSee('Nueva Llave')->assertDontSee('dialogoEditarLlave')->assertDontSee('dialogoBajaLlave')
            ->assertDontSee('Imprimir Etiquetas')->assertDontSee(route('llaves.exportar'));
        $this->actingAs($agente)->post('/llaves', $this->datos())->assertForbidden();
        $this->actingAs($agente)->put("/llaves/{$l->id}", $this->datos())->assertForbidden();
        $this->actingAs($agente)->post("/llaves/{$l->id}/baja", ['motivo' => 'robado'])->assertForbidden();
        $this->actingAs($agente)->patch("/llaves/{$l->id}/reactivar")->assertForbidden();
        $this->actingAs($agente)->get(route('llaves.imprimir', ['llaves' => [$l->id]]))->assertForbidden();
        $this->actingAs($agente)->get('/llaves/exportar')->assertForbidden();
        $this->actingAs($agente)->getJson('/lector/resolver?entrada=hdc-1&tipos=llave')->assertJsonPath('resultados.0.id', $l->id);

        $this->actingAs($this->crearUsuario($this->empresa))->get('/llaves')->assertForbidden();
    }

    public function test_menu_enlaza_al_catalogo_de_llaves(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('llaves.index'));
    }
}
