<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\Paradero;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Ronda 6 de ajustes de QA: avisos de duplicado en vivo en los demás padrones
 * (Proveedores, Vehículos, Colaboradores, Gafetes, Equipos, Equipos PC) y de
 * la etiqueta NFC (GV-03); Etiquetas QR en bloque (LL-06); filtros por
 * sesión (LL-08); zonas de descarga con capacidad (ES-02); voucher
 * «Recuperado» (GV-04); rutas (RT-02); estado de equipos (EQ-04) y límite
 * del lote de gafetes (GV-02).
 */
class AjustesRonda6Test extends TestCase
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

    private function proveedor(string $nombre, array $extra = [], ?Empresa $empresa = null): Proveedor
    {
        return $this->enEmpresa(function () use ($nombre, $extra) {
            $p = Proveedor::create(['nombre' => $nombre, 'categoria' => 'proveedor']);
            $p->forceFill($extra)->save();

            return $p;
        }, $empresa);
    }

    private function vehiculo(string $placas, array $extra = []): Vehiculo
    {
        return $this->enEmpresa(function () use ($placas, $extra) {
            $v = new Vehiculo(['placas' => $placas, 'propiedad' => 'propio_huesped', 'tipo' => 'sedan', 'marca' => 'NISSAN', 'modelo' => 'VERSA', 'color' => 'BLANCO']);
            $v->forceFill($extra)->save();

            return $v;
        });
    }

    private function colaborador(string $num, string $nombre, string $paterno, ?Sede $sede = null, ?string $materno = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => $paterno,
            'apellido_materno' => $materno, 'sede_id' => ($sede ?? $this->centro)->id]));
    }

    private function gafete(string $nomenclatura, ?Sede $sede = null, array $extra = []): Gafete
    {
        return $this->enEmpresa(function () use ($nomenclatura, $sede, $extra) {
            $tipo = TipoGafete::firstOrCreate(['nombre' => 'Visitante']);
            $g = new Gafete(['sede_id' => ($sede ?? $this->centro)->id, 'tipo_gafete_id' => $tipo->id, 'nomenclatura' => $nomenclatura, 'consecutivo' => random_int(1, 999)]);
            $g->forceFill($extra)->save();

            return $g;
        });
    }

    private function llave(string $nomenclatura, ?Sede $sede = null, array $extra = []): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $sede, $extra) {
            $l = new Llave(array_merge(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nomenclatura,
                'descripcion' => 'Acceso de prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global'], $extra));
            $l->save();

            return $l;
        });
    }

    private function equipo(string $serie, ?Sede $sede = null, string $estado = 'disponible', array $extra = []): Equipo
    {
        return $this->enEmpresa(function () use ($serie, $sede, $estado, $extra) {
            $tipo = TipoEquipo::firstOrCreate(['nombre' => 'Radio de Comunicación']);
            $e = new Equipo(array_merge(['sede_id' => ($sede ?? $this->centro)->id, 'tipo_equipo_id' => $tipo->id, 'marca' => 'MOTOROLA', 'modelo' => 'SL500E', 'numero_serie' => $serie], $extra));
            $e->forceFill(['estado' => $estado])->save();

            return $e;
        });
    }

    private function voucher(string $tipo, int $origenId, ?Sede $sede, array $extra = []): VoucherReposicion
    {
        return $this->enEmpresa(function () use ($tipo, $origenId, $sede, $extra) {
            $v = new VoucherReposicion(array_merge(['sede_id' => $sede?->id, 'folio' => 'VR-T-'.random_int(10000, 99999), 'origen_tipo' => $tipo,
                'origen_id' => $origenId, 'origen_descripcion' => 'Artículo de prueba', 'motivo' => 'extraviado'], $extra));
            $v->forceFill(['creado_por' => $this->admin->id])->save();

            return $v;
        });
    }

    // ============================================== 1. Avisos de duplicado en vivo

    public function test_proveedores_avisa_igual_parecido_sin_sociedad_e_inactivo_con_reactivar(): void
    {
        $abarrotes = $this->proveedor('Abarrotes del Caribe');
        $this->proveedor('Ajeno del Caribe', [], $this->crearEmpresa('Hotel Dos'));

        $this->actingAs($this->admin)->getJson('/proveedores/duplicado?campo=nombre&valor='.urlencode('ABARROTES  del caribe'))
            ->assertOk()->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', 'Abarrotes del Caribe');
        // «S.A. de C.V.» y similares no cuentan
        $this->actingAs($this->admin)->getJson('/proveedores/duplicado?campo=nombre&valor='.urlencode('Abarrotes del Caribe S.A. de C.V.'))
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'Abarrotes del Caribe');
        $this->actingAs($this->admin)->getJson('/proveedores/duplicado?campo=nombre&valor='.urlencode('Ferretería Zazil'))->assertJsonPath('estado', 'libre');
        // Edición: la propia no cuenta; otra empresa no existe aquí
        $this->actingAs($this->admin)->getJson("/proveedores/duplicado?campo=nombre&valor=Abarrotes del Caribe&excluir={$abarrotes->id}")->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson('/proveedores/duplicado?campo=nombre&valor=Ajeno del Caribe')->assertJsonPath('estado', 'libre');

        // Desactivada: se ofrece reactivarla
        $abarrotes->forceFill(['activo' => false])->save();
        $this->actingAs($this->admin)->getJson('/proveedores/duplicado?campo=nombre&valor=Abarrotes del Caribe')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.reactivar', route('proveedores.estado', $abarrotes->id));

        // Con alcance de sede no se rechaza: se agrega a su sede
        $abarrotes->forceFill(['activo' => true])->save();
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->getJson('/proveedores/duplicado?campo=nombre&valor=Abarrotes del Caribe')
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('mensaje', 'Ya existe «Abarrotes del Caribe»: al guardar no se crea otra, solo se agrega a tu sede.');

        // El agente solo consulta: no pregunta
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/proveedores/duplicado?valor=Abarrotes')->assertForbidden();

        // Un solo mecanismo en la pantalla
        $this->actingAs($this->admin)->get('/proveedores')->assertOk()
            ->assertSee('data-duplicado="'.route('proveedores.duplicado').'"', false)->assertDontSee('data-nombres-existentes', false);
    }

    public function test_vehiculos_avisa_placas_sin_guiones_y_parecidas_con_o_y_cero(): void
    {
        $versa = $this->vehiculo('ABC123A');
        $this->actingAs($this->admin)->getJson('/vehiculos/duplicado?campo=placas&valor='.urlencode('abc-123 a'))
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', 'ABC123A');
        $this->actingAs($this->admin)->getJson('/vehiculos/duplicado?campo=placas&valor=ABCI23A')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->admin)->getJson('/vehiculos/duplicado?campo=placas&valor=ZZZ999Q')
            ->assertJsonPath('estado', 'libre')->assertJsonPath('mensaje', 'Se guardarán como ZZZ999Q.');
        $this->actingAs($this->admin)->getJson("/vehiculos/duplicado?campo=placas&valor=ABC123A&excluir={$versa->id}")->assertJsonPath('estado', 'libre');

        $versa->forceFill(['activo' => false])->save();
        $this->actingAs($this->admin)->getJson('/vehiculos/duplicado?campo=placas&valor=ABC123A')
            ->assertJsonPath('coincidencias.0.inactivo', true)->assertJsonPath('coincidencias.0.reactivar', route('vehiculos.estado', $versa->id));

        $this->actingAs($this->admin)->get('/vehiculos')->assertOk()
            ->assertSee('data-duplicado="'.route('vehiculos.duplicado').'"', false)->assertDontSee('data-placas-existentes', false);
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/vehiculos/duplicado?valor=ABC123A')->assertForbidden();
    }

    public function test_colaboradores_avisa_numero_y_nombre_sin_revelar_otras_sedes(): void
    {
        $this->colaborador('1008', 'Daniela', 'Canul', $this->playa, 'May');
        $this->colaborador('1005', 'Roberto', 'Hernández', $this->centro, 'Cruz');

        $this->actingAs($this->admin)->getJson('/colaboradores/duplicado?campo=num_empleado&valor=1008')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', 'Daniela Canul May');
        $this->actingAs($this->admin)->getJson('/colaboradores/duplicado?campo=num_empleado&valor=2000')->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson('/colaboradores/duplicado?campo=nombre&valor=roberto&apellido_paterno=HERNANDEZ&apellido_materno=cruz')
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'Roberto Hernández Cruz');

        // RH de Centro: lo de Playa se cuenta, sin datos
        $sa = $this->crearSuperadmin();
        $rhCentro = $this->usuarioConSede('colaboradores', ['ver', 'crear', 'editar'], $this->centro, $sa);
        $r = $this->actingAs($rhCentro)->getJson('/colaboradores/duplicado?campo=num_empleado&valor=1008')->assertJsonPath('estado', 'existe');
        $this->assertSame([], $r->json('coincidencias'));
        $this->assertStringContainsString('en una sede que no tienes a cargo', $r->json('mensaje'));
        $r = $this->actingAs($rhCentro)->getJson('/colaboradores/duplicado?campo=nombre&valor=Daniela&apellido_paterno=Canul&apellido_materno=May');
        $this->assertSame([], $r->json('coincidencias'));
        $this->assertStringContainsString('(uno en una sede que no tienes a cargo)', $r->json('mensaje'));

        // El agente (solo alta provisional) no pregunta
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/colaboradores/duplicado?campo=num_empleado&valor=1008')->assertForbidden();
        $this->actingAs($this->admin)->get('/colaboradores')->assertOk()->assertDontSee('data-numeros-existentes', false)
            ->assertSee('data-duplicado="'.route('colaboradores.duplicado').'" data-duplicado-min="1"', false);
    }

    public function test_gafetes_equipos_y_equipos_pc_avisan_folio_y_serie(): void
    {
        $gafete = $this->gafete('HOT-CEN-VIS-001');
        $this->gafete('HOT-PLA-VIS-001', $this->playa);
        $this->actingAs($this->admin)->getJson('/gafetes/duplicado?campo=nomenclatura&valor=hot-cen-vis-001')->assertJsonPath('estado', 'existe');
        $this->actingAs($this->admin)->getJson('/gafetes/duplicado?campo=nomenclatura&valor=HOTCENVIS001')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->admin)->getJson("/gafetes/duplicado?campo=nomenclatura&valor=HOT-CEN-VIS-001&excluir={$gafete->id}")->assertJsonPath('estado', 'libre');
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $r = $this->actingAs($jefe)->getJson('/gafetes/duplicado?campo=nomenclatura&valor=HOT-PLA-VIS-001')->assertJsonPath('estado', 'existe');
        $this->assertSame([], $r->json('coincidencias'));

        $radio = $this->equipo('130TXP1568', null, 'baja');
        $this->actingAs($this->admin)->getJson('/equipos/duplicado?campo=numero_serie&valor=130txp1568')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('coincidencias.0.titulo', 'Serie: 130TXP1568')
            ->assertJsonPath('coincidencias.0.reactivar', route('equipos.reactivar', $radio->id));
        $this->actingAs($this->admin)->getJson('/equipos/duplicado?campo=numero_serie&valor=130-TXP-1568')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->admin)->get('/equipos')->assertOk()->assertDontSee('data-series-existentes', false)
            ->assertSee('data-duplicado="'.route('equipos.duplicado').'"', false);

        $this->enEmpresa(fn () => EquipoPc::create(['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'EXT-01']));
        $this->actingAs($this->admin)->getJson("/equipos-pc/duplicado?campo=numero_serie&valor=ext-01&sede_id={$this->centro->id}")->assertJsonPath('estado', 'existe');
        // Único por sede: en Playa está libre; sin sede no se revisa
        $this->actingAs($this->admin)->getJson("/equipos-pc/duplicado?campo=numero_serie&valor=EXT-01&sede_id={$this->playa->id}")->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson('/equipos-pc/duplicado?campo=numero_serie&valor=EXT-01')->assertJsonPath('estado', 'nada');
    }

    public function test_gv03_la_etiqueta_nfc_avisa_en_vivo_quien_la_tiene(): void
    {
        $llave = $this->llave('HDC-101', null, ['etiqueta_nfc' => '04A1B2C3D4']);
        $gafete = $this->gafete('HOT-CEN-VIS-002');
        $this->llave('HDP-201', $this->playa, ['etiqueta_nfc' => '04FFEE0011']);

        // Igual sin importar dos puntos ni minúsculas
        $this->actingAs($this->admin)->getJson("/identificacion/gafete/{$gafete->id}/etiqueta-duplicado?valor=04:a1:b2:c3:d4")
            ->assertJsonPath('estado', 'existe')->assertJsonPath('mensaje', 'Esa tarjeta o etiqueta ya la tiene la llave «HDC-101». Quítasela primero ahí o usa otra.');
        // La propia llave no cuenta
        $this->actingAs($this->admin)->getJson("/identificacion/llave/{$llave->id}/etiqueta-duplicado?valor=04A1B2C3D4")->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson('/identificacion/equipo/0/etiqueta-duplicado?valor=0499887766')->assertJsonPath('estado', 'libre');

        // Jefe de Centro: la de Playa existe, pero sin decir cuál
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $r = $this->actingAs($jefe)->getJson('/identificacion/gafete/0/etiqueta-duplicado?valor=04FFEE0011')->assertJsonPath('estado', 'existe');
        $this->assertStringNotContainsString('HDP-201', $r->getContent());
        // Un registro de otra sede: 404 en la dirección; como ?excluir= (edición genérica) no se responde nada
        $gafetePlaya = $this->gafete('HOT-PLA-VIS-009', $this->playa);
        $this->actingAs($jefe)->getJson("/identificacion/gafete/{$gafetePlaya->id}/etiqueta-duplicado?valor=04A1B2C3D4")->assertNotFound();
        $this->actingAs($jefe)->getJson("/identificacion/gafete/0/etiqueta-duplicado?valor=04A1B2C3D4&excluir={$gafetePlaya->id}")->assertJsonPath('estado', 'nada');
        // Otra empresa: 404
        $this->actingAs($this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador'))->getJson("/identificacion/llave/{$llave->id}/etiqueta-duplicado?valor=04A1B2C3D4")->assertNotFound();
        // Sin permiso de editar el módulo
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->getJson('/identificacion/llave/0/etiqueta-duplicado?valor=04A1B2C3D4')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/identificacion/inventado/0/etiqueta-duplicado?valor=04A1B2C3D4')->assertNotFound();

        // Edición (lector en modo capturar) y diálogo «Código e identificación»
        $this->actingAs($this->admin)->get('/gafetes')->assertOk()
            ->assertSee('data-duplicado="'.route('identificacion.etiqueta-duplicado', ['gafete', 0]).'"', false)
            ->assertSee('duplicadoUrl', false);
    }

    // ============================================================ 2. Etiquetas QR

    public function test_ll06_etiquetas_qr_por_tipo_con_permisos_y_sedes(): void
    {
        $this->llave('HDC-101');
        $this->llave('HDP-201', $this->playa);
        $this->llave('HDC-BAJA', null, ['activo' => false]);
        $this->vehiculo('ABC123A');
        $this->equipo('130TXP1568');

        // Menú Padrones → Etiquetas QR
        $this->actingAs($this->admin)->get(route('panel'))->assertSee(route('etiquetas.index'), false);

        $pagina = $this->actingAs($this->admin)->get('/etiquetas')->assertOk()
            ->assertSee('Etiquetas QR')->assertSee('HDC-101')->assertSee('HDP-201')->assertSee('ABC123A')->assertSee('Serie: 130TXP1568')
            ->assertDontSee('HDC-BAJA')->assertSee('Llavero pequeño')->assertSee('Calcomanía vehicular')->getContent();
        $this->assertStringContainsString('name="sel[]"', $pagina);
        // Activos y de baja; por tipo; búsqueda; por sede (los vehículos son de toda la empresa)
        $this->actingAs($this->admin)->get('/etiquetas?estado=todos')->assertSee('HDC-BAJA');
        $this->actingAs($this->admin)->get('/etiquetas?tipo=vehiculo')->assertSee('ABC123A')->assertDontSee('HDC-101');
        $this->actingAs($this->admin)->get('/etiquetas?q=hdp')->assertSee('HDP-201')->assertDontSee('HDC-101');
        $this->actingAs($this->admin)->get("/etiquetas?sede={$this->centro->id}")->assertSee('HDC-101')->assertDontSee('HDP-201')->assertDontSee('ABC123A');

        // Jefe de Centro: solo lo de su sede
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->get('/etiquetas')->assertOk()->assertSee('HDC-101')->assertDontSee('HDP-201');

        // Agente: entra, pero Llaves, Gafetes, Equipos y Vehículos piden «imprimir», que no tiene
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/etiquetas')->assertOk()->assertDontSee('HDC-101')->assertDontSee('ABC123A');
    }

    public function test_ll06_hoja_de_impresion_solo_con_lo_permitido(): void
    {
        $centro = $this->llave('HDC-101');
        $playa = $this->llave('HDP-201', $this->playa);
        $vehiculo = $this->vehiculo('ABC123A');
        $ajena = $this->enEmpresa(function () {
            $sede = Sede::create(['codigo' => 'OTR', 'nombre' => 'Sede OTR']);

            return Llave::create(['sede_id' => $sede->id, 'nomenclatura' => 'AJENA-1', 'descripcion' => 'x', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
        }, $otra = $this->crearEmpresa('Hotel Dos'));
        $this->assertNotNull($otra);

        // Ronda 7: «Imprimir» es un POST que registra la impresión y abre su hoja
        $html = $this->actingAs($this->admin)->followingRedirects()->post('/etiquetas/imprimir', ['sel' => ['llave-'.$centro->id, 'vehiculo-'.$vehiculo->id, 'llave-'.$ajena->id], 'tamano' => 'calcomania'])
            ->assertOk()->assertSee('HDC-101')->assertSee('ABC123A')->assertDontSee('AJENA-1')
            ->assertSee('Calcomanía vehicular')->assertSee('<svg', false)->getContent();
        $this->assertStringContainsString(trim(chunk_split($centro->codigo_qr, 4, ' ')), $html);

        // Jefe de Centro no imprime lo de Playa; solo ajeno = 404; sin marcar = regresa con aviso
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$playa->id]])->assertNotFound();
        $this->actingAs($this->admin)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$ajena->id]])->assertNotFound();
        $this->actingAs($this->admin)->post('/etiquetas/imprimir')->assertRedirect(route('etiquetas.index'))
            ->assertSessionHas('aviso', 'Marca al menos un registro para imprimir sus etiquetas.');
        // Tamaño desconocido: la última plantilla que usó (se recuerda por usuario)
        $this->actingAs($this->admin)->followingRedirects()->post('/etiquetas/imprimir', ['sel' => ['llave-'.$centro->id], 'tamano' => 'xx'])->assertSee('plantilla «Calcomanía vehicular»', false);
        // Sin el permiso del menú: 403
        $this->actingAs($this->crearUsuario($this->empresa))->get('/etiquetas')->assertForbidden();
    }

    // ===================================================== 3. Filtros por sesión

    public function test_ll08_la_marca_de_filtros_cambia_en_cada_inicio_de_sesion_y_por_usuario(): void
    {
        $this->admin->forceFill(['username' => 'admin.prueba', 'password' => bcrypt('Clave1234!')])->save();
        $this->post('/login', ['username' => 'admin.prueba', 'password' => 'Clave1234!'])->assertRedirect();
        $primera = session('marca_filtros');
        $html = $this->get(route('panel'))->assertOk()->getContent();
        $this->assertStringContainsString('data-usuario-filtros="'.$this->admin->id.'" data-sesion-filtros="'.$primera.'"', $html);

        $this->post('/logout');
        $this->post('/login', ['username' => 'admin.prueba', 'password' => 'Clave1234!'])->assertRedirect();
        $this->assertNotSame($primera, session('marca_filtros'));
    }

    // =============================================== 4. Zonas de descarga (ES-02)

    public function test_es02_zona_de_descarga_con_capacidad_opcional_ocupacion_y_lleno(): void
    {
        $this->actingAs($this->admin)->post('/estacionamientos', ['sede_id' => $this->centro->id, 'nombre' => 'Patio de Maniobras', 'tipo' => 'zona_descarga', 'cupo_total' => '4'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/estacionamientos', ['sede_id' => $this->centro->id, 'nombre' => 'Andén 2', 'tipo' => 'zona_descarga', 'cupo_total' => ''])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/estacionamientos', ['sede_id' => $this->centro->id, 'nombre' => 'Andén 3', 'tipo' => 'zona_descarga', 'cupo_total' => '0'])
            ->assertSessionHasErrors(['cupo_total' => 'La capacidad debe ser de al menos 1 vehículo (o déjala vacía si no tiene límite).']);
        [$patio, $anden] = $this->enEmpresa(fn () => [ZonaEstacionamiento::where('nombre', 'Patio de Maniobras')->firstOrFail(), ZonaEstacionamiento::where('nombre', 'Andén 2')->firstOrFail()]);
        $this->assertSame([4, null], [$patio->cupo_total, $anden->cupo_total]);

        $this->app->bind(OcupacionEstacionamientos::class, fn () => new class([$patio->id => 4, $anden->id => 2]) implements OcupacionEstacionamientos
        {
            public function __construct(private array $conteo) {}

            public function ocupados(array $zonaIds): array
            {
                return array_intersect_key($this->conteo, array_flip($zonaIds));
            }
        });

        $this->actingAs($this->admin)->get('/estacionamientos')->assertOk()
            ->assertSee('4 / 4 vehículos')->assertSee('LLENO')->assertSee('vehículos usando el andén ahora')
            ->assertSee('Capacidad máxima de vehículos')
            // «Nueva Zona» con la misma tarjeta de alta que los demás padrones
            ->assertSee('<button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaZona">', false)
            ->assertDontSee('btn-nueva-zona', false);

        // Accesos: la de descarga con capacidad también avisa «LLENO»
        $zonas = collect($this->enEmpresa(fn () => app(ConsultaAccesos::class)->zonas([$this->centro->id])))->keyBy('nombre');
        $this->assertSame(['Patio de Maniobras (zona de descarga 4/4) — LLENO', true], [$zonas['Patio de Maniobras']['texto'], $zonas['Patio de Maniobras']['lleno']]);
        $this->assertSame(['Andén 2 (zona de descarga)', false], [$zonas['Andén 2']['texto'], $zonas['Andén 2']['lleno']]);
    }

    // ================================================ 5. Voucher recuperado (GV-04)

    public function test_gv04_recuperado_reactiva_y_cancela_o_deja_reembolso_pendiente(): void
    {
        // Sin cobro: llave de baja → cancelado por recuperación y la llave vuelve a estar activa
        $llave = $this->llave('HDC-101', null, ['activo' => false]);
        $sinCobro = $this->voucher('llave', $llave->id, $this->centro);
        $this->actingAs($this->admin)->post("/vouchers/{$sinCobro->id}/recuperado", ['comentario' => 'Apareció en Ama de Llaves', '_dialogo' => 'recuperar-'.$sinCobro->id])
            ->assertRedirect(route('vouchers.index').'#voucher-'.$sinCobro->id)
            ->assertSessionHas('ok', "Voucher {$sinCobro->folio}: artículo recuperado y reactivado. El voucher queda «Cancelado por recuperación».");
        $sinCobro->refresh();
        $this->assertSame(['cancelado_recuperacion', $this->admin->id, 'Apareció en Ama de Llaves'], [$sinCobro->estado, $sinCobro->recuperado_por, $sinCobro->recuperacion_comentario]);
        $this->assertNotNull($sinCobro->recuperado_en);
        $this->assertTrue($llave->refresh()->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vouchers.recuperado', 'auditable_id' => $sinCobro->id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'llaves.reactivado', 'auditable_id' => $llave->id]);

        // Prohibido: recuperarlo otra vez o «reembolsar» uno cancelado
        $this->actingAs($this->admin)->post("/vouchers/{$sinCobro->id}/recuperado", [])->assertSessionHasErrors('comentario');
        $this->actingAs($this->admin)->post("/vouchers/{$sinCobro->id}/reembolso", [])->assertSessionHasErrors('comentario');

        // Con cobro sin pagar: el cobro se cancela
        $gafete = $this->gafete('HOT-CEN-VIS-010', null, ['activo' => false]);
        $cobroSinPagar = $this->voucher('gafete', $gafete->id, $this->centro, ['aplica_cobro' => true, 'monto' => 150]);
        $this->actingAs($this->admin)->post("/vouchers/{$cobroSinPagar->id}/recuperado", [])
            ->assertSessionHas('ok', "Voucher {$cobroSinPagar->folio}: artículo recuperado y reactivado. El voucher queda «Cancelado por recuperación» y el cobro se canceló.");
        $this->assertSame('cancelado_recuperacion', $cobroSinPagar->refresh()->estado);
        $this->assertTrue($gafete->refresh()->activo);

        // Con cobro ya pagado: reembolso pendiente (pide el comentario) → reembolsado
        $equipo = $this->equipo('LT-0002', null, 'baja');
        $pagado = $this->voucher('equipo', $equipo->id, $this->centro, ['aplica_cobro' => true, 'monto' => 1650]);
        $this->actingAs($this->admin)->post("/vouchers/{$pagado->id}/reembolso", [])->assertSessionHasErrors('comentario'); // aún vigente
        $this->actingAs($this->admin)->post("/vouchers/{$pagado->id}/recuperado", ['cobro_pagado' => '1'])
            ->assertSessionHasErrors(['comentario' => 'Escribe cómo se va a devolver el dinero (por ejemplo: «se reembolsa en nómina»).']);
        $this->assertSame('vigente', $pagado->refresh()->estado);
        $this->assertSame('baja', $equipo->refresh()->estado);
        $this->actingAs($this->admin)->post("/vouchers/{$pagado->id}/recuperado", ['cobro_pagado' => '1', 'comentario' => 'Se reembolsa en nómina']);
        $this->assertSame(['reembolso_pendiente', 'disponible'], [$pagado->refresh()->estado, $equipo->refresh()->estado]);
        $this->actingAs($this->admin)->post("/vouchers/{$pagado->id}/reembolso", ['comentario' => 'Nómina del 15'])
            ->assertSessionHas('ok', "Voucher {$pagado->folio}: reembolso entregado.");
        $pagado->refresh();
        $this->assertSame(['reembolsado', $this->admin->id, 'Nómina del 15'], [$pagado->estado, $pagado->reembolsado_por, $pagado->reembolso_comentario]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'vouchers.reembolsado', 'auditable_id' => $pagado->id]);
        $this->actingAs($this->admin)->post("/vouchers/{$pagado->id}/reembolso", [])->assertSessionHasErrors('comentario');
        $this->actingAs($this->admin)->post("/vouchers/{$pagado->id}/recuperado", [])->assertSessionHasErrors('comentario');

        // Lista: estados, filtro y trazas; impresión con el sello
        $this->actingAs($this->admin)->get('/vouchers')->assertOk()->assertSee('Cancelado por recuperación')->assertSee('Reembolsado')
            ->assertSee('COBRO CANCELADO')->assertSee('Recuperado por');
        $this->actingAs($this->admin)->get('/vouchers?estado=reembolsado')->assertSee($pagado->folio)->assertDontSee($sinCobro->folio);
        $this->actingAs($this->admin)->get("/vouchers/{$cobroSinPagar->id}/imprimir")->assertOk()->assertSee('CANCELADO POR RECUPERACIÓN')->assertSee('Cobro cancelado');
    }

    public function test_gv04_permisos_sede_y_voucher_mas_reciente(): void
    {
        $llave = $this->llave('HDP-201', $this->playa, ['activo' => false]);
        $voucher = $this->voucher('llave', $llave->id, $this->playa);

        // Agente: ve vouchers pero no marca recuperado (y no ve el botón)
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($agente)->post("/vouchers/{$voucher->id}/recuperado", [])->assertForbidden();
        // Jefe de Centro: el de Playa no existe para él
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->post("/vouchers/{$voucher->id}/recuperado", [])->assertNotFound();
        // Otra empresa
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->post("/vouchers/{$voucher->id}/recuperado", [])->assertNotFound();
        $this->assertSame('vigente', $voucher->refresh()->estado);

        // Se volvió a dar de baja después: el voucher viejo no la reactiva
        $nuevo = $this->voucher('llave', $llave->id, $this->playa);
        $this->actingAs($this->admin)->post("/vouchers/{$voucher->id}/recuperado", [])->assertSessionHasErrors('comentario');
        $this->actingAs($this->admin)->get('/vouchers')->assertSee('data-accion="voucher-recuperado"', false);
        $this->actingAs($this->admin)->post("/vouchers/{$nuevo->id}/recuperado", [])->assertSessionHasNoErrors();
        $this->assertTrue($llave->refresh()->activo);
    }

    // ============================================== 6, 7 y 8. Rutas, equipos y gafetes

    public function test_eq04_equipo_con_rotulo_de_serie_y_explicacion_del_estado(): void
    {
        $radio = $this->equipo('130TXP1568', null, 'en_mantenimiento');
        $lampara = $this->equipo('LT-0002', null, 'baja');
        $this->voucher('equipo', $lampara->id, $this->centro, ['folio' => 'VR-2610-00001']);

        $html = $this->actingAs($this->admin)->get('/equipos')->assertOk()
            ->assertSee('data-explicacion-estado', false)
            ->assertSee('Radio de Comunicación · Serie: 130TXP1568')->getContent();
        $this->assertStringContainsString('&quot;voucher&quot;:&quot;VR-2610-00001&quot;', $html);
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=130TXP1568&tipos=equipo')->assertJsonPath('resultados.0.titulo', 'Serie: 130TXP1568');

        // En mantenimiento solo pasa a Disponible (o se queda)
        $this->actingAs($this->admin)->put("/equipos/{$radio->id}", ['sede_id' => $this->centro->id, 'tipo_equipo_id' => $radio->tipo_equipo_id, 'numero_serie' => '130TXP1568', 'estado' => 'asignado'])
            ->assertSessionHasErrors('estado');
        $this->actingAs($this->admin)->put("/equipos/{$radio->id}", ['sede_id' => $this->centro->id, 'tipo_equipo_id' => $radio->tipo_equipo_id, 'numero_serie' => '130TXP1568', 'estado' => 'disponible'])
            ->assertSessionHasNoErrors();
        $this->assertSame('disponible', $radio->refresh()->estado);
    }

    public function test_rt02_y_gv02_textos_del_dialogo(): void
    {
        // GV-02: el límite del lote se ve en el campo y en el error
        $this->actingAs($this->admin)->get('/gafetes')->assertOk()
            ->assertSee('Máximo 50 por lote.')->assertSee('data-mensaje-max="Máximo 50 gafetes por lote."', false);

        // RT-02: el diálogo de rutas sigue usando sus plantillas (el JS copia los paraderos por su nombre, no por su tipo)
        $js = (string) file_get_contents(public_path('js/plataforma.js'));
        $this->assertStringContainsString("f.querySelector('input[name\$=\"[hora]\"]').value", $js);
        $this->assertStringContainsString('.rutas-dialogo[open] { display: flex;', (string) file_get_contents(public_path('css/plataforma.css')));
    }

    /**
     * Usuario con un rol a la medida (alcance de sede).
     *
     * @param  list<string>  $acciones
     */
    private function usuarioConSede(string $modulo, array $acciones, Sede $sede, User $sa): User
    {
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Rol '.$modulo.' '.uniqid(), 'nivel_jerarquia' => 45]);
        $roles->sincronizarPermisos($sa, $rol, collect($acciones)->mapWithKeys(fn ($a) => [$modulo.'.'.$a => Alcance::Sede])->all());
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $sede->id]);

        return $usuario;
    }

    public function test_rt01_y_datos_demo_de_la_ronda_6_solo_la_primera_vez(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();

        $this->enEmpresa(function () {
            $centro = Sede::where('codigo', 'CEN')->firstOrFail();
            // RT-01: 6 paraderos activos en Centro; el de la práctica de Eliminar definitivamente, desactivado
            $this->assertSame(6, Paradero::where('sede_id', $centro->id)->where('activo', true)->count());
            $this->assertFalse((bool) Paradero::where('nombre', 'PARADERO DE PRUEBA')->value('activo'));
            $this->assertSame(4, ZonaEstacionamiento::where('nombre', 'Patio de Maniobras')->value('cupo_total'));
            $this->assertSame('HDC-101', Llave::where('etiqueta_nfc', '04A1B2C3D4')->value('nomenclatura'));
            $llave = Llave::where('nomenclatura', 'HDP-MANT-02')->firstOrFail();
            $this->assertTrue($llave->activo);
            $this->assertSame(['reembolso_pendiente'], VoucherReposicion::where('origen_tipo', 'llave')->where('origen_id', $llave->id)->pluck('estado')->all());
            $this->assertSame(1, ZonaEstacionamiento::where('nombre', 'Patio de Maniobras')->count());
        }, $demo);
    }

    public function test_auditoria_de_la_recuperacion_guarda_antes_y_despues(): void
    {
        $llave = $this->llave('HDC-102', null, ['activo' => false]);
        $v = $this->voucher('llave', $llave->id, $this->centro);
        $this->actingAs($this->admin)->post("/vouchers/{$v->id}/recuperado", ['comentario' => 'Apareció']);
        $a = Auditoria::where('evento', 'vouchers.recuperado')->firstOrFail();
        $this->assertSame(['vigente', 'cancelado_recuperacion', 'sin cobro', true], [$a->antes['estado'], $a->despues['estado'], $a->despues['cobro'], $a->despues['articulo_reactivado']]);
    }
}
