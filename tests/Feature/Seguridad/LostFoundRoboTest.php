<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundReportePerdida;
use App\Models\LostFoundUmbral;
use App\Models\Modulo;
use App\Models\Novedad;
use App\Models\NovedadNota;
use App\Models\Persona;
use App\Models\RoboDetalle;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Alcance;
use Illuminate\Support\Facades\Storage;

/**
 * Lost & Found (archivo, Cerrar / Entregar, etiqueta, auditoría, días de
 * resguardo) y Robo — Seguimiento.
 */
class LostFoundRoboTest extends PruebaNovedades
{
    // ------------------------------------------------------------------ Apoyo

    private function articulo(array $extra = [], ?Sede $sede = null, ?Empresa $empresa = null, ?User $autor = null): LostFoundArticulo
    {
        $empresa ??= $this->empresa;
        $sede ??= $empresa->is($this->empresa) ? $this->centro : null;
        $n = $this->novedad(['categoria' => 'lost_found', 'descripcion' => 'Objetos encontrados'] + ($sede ? ['sede_id' => $sede->id] : []), $empresa, $autor);

        return $this->enEmpresa(function () use ($extra, $n, $autor) {
            $a = new LostFoundArticulo(array_merge(['objeto' => 'CARTERA', 'tipo_valor' => 'OTRO', 'marca' => 'GUESS', 'color' => 'NEGRO', 'ubicacion_bodega' => 'ANAQUEL 1'],
                array_diff_key($extra, ['created_at' => 1]), ['novedad_id' => $n->id, 'sede_id' => $n->sede_id]));
            $a->numero = (int) LostFoundArticulo::max('numero') + 1;
            $a->folio = 'LF-'.str_pad((string) $a->numero, 6, '0', STR_PAD_LEFT);
            $a->creado_por = $autor?->id;
            $a->save();
            if (isset($extra['created_at'])) {
                $a->forceFill(['created_at' => $extra['created_at']])->saveQuietly();
            }

            return $a->fresh();
        }, $empresa);
    }

    private function robo(array $detalle = [], array $extra = [], ?Sede $sede = null): Novedad
    {
        $n = $this->novedad(['categoria' => 'robo', 'descripcion' => 'Se llevaron algo', 'ubicacion' => 'HABITACIÓN 202'] + $extra + ($sede ? ['sede_id' => $sede->id] : []));
        $this->enEmpresa(fn () => RoboDetalle::create(['novedad_id' => $n->id] + $detalle));

        return $n;
    }

    private function colaborador(string $num = '700', string $nombre = 'Eva', ?Empresa $empresa = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => $nombre, 'apellido_paterno' => 'Pérez', 'sede_id' => $this->centro->id]), $empresa);
    }

    private function cerrar(User $actor, LostFoundArticulo $a, array $datos)
    {
        return $this->actingAs($actor)->post("/lost-found/articulos/{$a->id}/cerrar", $datos + ['_dialogo' => 'cerrar-'.$a->id, 'firma' => self::firmaJpeg()]);
    }

    private function fresco(LostFoundArticulo $a): LostFoundArticulo
    {
        return $this->enEmpresa(fn () => LostFoundArticulo::with('entrega')->findOrFail($a->id), Empresa::find($a->empresa_id));
    }

    private function rolCon(string $nombre, array $permisos, Alcance $alcance = Alcance::Sede, ?Sede $sede = null): User
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre, 'nivel_jerarquia' => 75]);
        foreach ($permisos as $permiso) {
            [$modulo, $accion] = explode('.', $permiso);
            $ma = Modulo::where('clave', $modulo)->firstOrFail()->moduloAcciones()->whereHas('accion', fn ($q) => $q->where('clave', $accion))->firstOrFail();
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => $alcance]);
        }

        return $this->crearUsuario($this->empresa, $nombre, $sede);
    }

    // ------------------------------------------------------- Archivo de Lost & Found

    public function test_archivo_con_textos_filtros_y_semaforo_de_segcat(): void
    {
        $vencida = $this->articulo(['objeto' => 'BUFANDA', 'tipo_valor' => 'ROPA', 'created_at' => now()->subDays(40)]);
        $porVencer = $this->articulo(['objeto' => 'GORRA', 'tipo_valor' => 'ROPA', 'created_at' => now()->subDays(22)]);
        $enTiempo = $this->articulo(['objeto' => 'TELÉFONO', 'tipo_valor' => 'ELECTRONICO', 'marca' => 'SAMSUNG']);
        $playa = $this->articulo(['objeto' => 'TOALLA', 'ubicacion_bodega' => 'CAJA PLAYA'], $this->playa);
        $this->novedad(['categoria' => 'lost_found', 'ubicacion' => 'LOBBY', 'descripcion' => 'Bolsa sin capturar']);

        $this->actingAs($this->admin)->get('/lost-found')->assertOk()
            ->assertSee('Lost &amp; Found', false)->assertSee('Todos los artículos, con su estado y ubicación — acciones directo desde aquí.')
            ->assertSee('Solo urgentes / vencidos')->assertSee('Sin artículos capturados aún (1)')->assertSee('Completar')
            ->assertSee($vencida->folio)->assertSee($porVencer->folio)->assertSee($enTiempo->folio)->assertSee($playa->folio)
            ->assertSee('40 día(s) en resguardo')->assertSee('fila-articulo rojo', false)->assertSee('fila-articulo amarillo', false)
            ->assertSee('Cerrar / Entregar')->assertSee('Configurar Días')->assertSee('Auditoría');

        // Urgentes: solo lo que está en amarillo o rojo, primero lo vencido
        $urgentes = $this->actingAs($this->admin)->get('/lost-found?filtro=urgentes')->assertOk()
            ->assertSee($vencida->folio)->assertSee($porVencer->folio)->assertDontSee($enTiempo->folio)->getContent();
        $this->assertLessThan(strpos($urgentes, $porVencer->folio), strpos($urgentes, $vencida->folio));

        // Búsqueda por folio, objeto, bodega; sede y tipo de valor
        $this->actingAs($this->admin)->get('/lost-found?q='.$enTiempo->folio)->assertSee($enTiempo->folio)->assertDontSee($vencida->folio);
        $this->actingAs($this->admin)->get('/lost-found?q=samsung')->assertSee($enTiempo->folio)->assertDontSee($porVencer->folio);
        $this->actingAs($this->admin)->get('/lost-found?q=caja playa')->assertSee($playa->folio)->assertDontSee($enTiempo->folio);
        $this->actingAs($this->admin)->get('/lost-found?sede='.$this->playa->id)->assertSee($playa->folio)->assertDontSee($vencida->folio);
        $this->actingAs($this->admin)->get('/lost-found?tipo=ROPA')->assertSee($vencida->folio)->assertDontSee($enTiempo->folio);
        $this->actingAs($this->admin)->get('/lost-found?q=nada-que-ver')->assertSee('No se encontraron artículos para «nada-que-ver».');
        $this->actingAs($this->admin)->get('/lost-found?filtro=devueltos')->assertSee('No hay artículos con este filtro.');
        $this->actingAs($this->admin)->get('/lost-found?filtro=inventado')->assertSessionHasErrors('filtro');
    }

    public function test_sin_articulos_muestra_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get('/lost-found')->assertOk()
            ->assertSee('Todavía no hay artículos en Lost &amp; Found.', false)->assertDontSee('Sin artículos capturados aún');
    }

    // ------------------------------------------------------------ Cerrar / Entregar

    public function test_entregar_en_persona_guarda_firma_privada_anota_y_audita(): void
    {
        Storage::fake('local');
        $a = $this->articulo();

        $this->cerrar($this->admin, $a, ['tipo_cierre' => 'PERSONA', 'recibe_es' => 'externo', 'nombre_recibe' => ' laura  gómez ', 'tipo_identificacion' => 'INE',
            'correo_recibe' => 'laura@example.com', 'observaciones' => 'Mostró su INE.', 'volver' => 'archivo'])
            ->assertSessionHasNoErrors()->assertRedirect(route('lost_found.archivo').'#articulo-'.$a->id)
            ->assertSessionHas('ok', "Cierre registrado correctamente: {$a->folio} quedó como «Devuelto al Huésped».");

        $a = $this->fresco($a);
        $this->assertSame('DEVUELTO', $a->estatus);
        $this->assertNotNull($a->cerrado_en);
        $this->assertSame($this->admin->id, $a->cerrado_por);
        $this->assertSame(['PERSONA', 'LAURA GÓMEZ', 'INE', 'laura@example.com'], [$a->entrega->tipo_cierre, $a->entrega->nombre_recibe, $a->entrega->tipo_identificacion, $a->entrega->correo_recibe]);
        $this->assertStringStartsWith("firmas/{$this->empresa->id}/lost-found/", $a->entrega->firma_ruta);
        Storage::disk('local')->assertExists($a->entrega->firma_ruta);
        $this->assertFileDoesNotExist(public_path($a->entrega->firma_ruta));

        $nota = $this->enEmpresa(fn () => NovedadNota::where('novedad_id', $a->novedad_id)->latest('id')->value('texto'));
        $this->assertSame("Cerró el artículo {$a->folio} (CARTERA): Devuelto en persona — recibe LAURA GÓMEZ.", $nota);
        $auditoria = Auditoria::where('evento', 'lost_found.cerrado')->firstOrFail();
        $this->assertSame(['estatus' => 'EN_RESGUARDO'], $auditoria->antes);
        $this->assertSame('DEVUELTO', $auditoria->despues['estatus']);

        // Desde la ficha regresa a la ficha
        $b = $this->articulo();
        $this->cerrar($this->admin, $b, ['tipo_cierre' => 'DESTRUIDO', 'volver' => 'ficha'])->assertRedirect(route('lost_found.articulos.show', $b->id));
    }

    public function test_cada_forma_de_cierre_deja_su_estatus_y_un_articulo_cerrado_no_se_vuelve_a_cerrar(): void
    {
        Storage::fake('local');
        $eva = $this->colaborador();
        $casos = [
            'DEVUELTO' => ['tipo_cierre' => 'PAQUETERIA', 'nombre_recibe' => 'Laura Gómez', 'paqueteria' => 'DHL', 'numero_guia' => 'abc123'],
            'DONADO' => ['tipo_cierre' => 'DONADO', 'colaborador_id' => $eva->id],
            'DESTRUIDO' => ['tipo_cierre' => 'DESTRUIDO'],
            'ENTREGADO_BENEFICENCIA' => ['tipo_cierre' => 'BENEFICENCIA', 'nombre_recibe' => 'Casa hogar'],
        ];
        foreach ($casos as $estatus => $datos) {
            $a = $this->articulo();
            $this->cerrar($this->admin, $a, $datos)->assertSessionHasNoErrors();
            $this->assertSame($estatus, $this->fresco($a)->estatus, $datos['tipo_cierre']);
        }
        $paqueteria = $this->enEmpresa(fn () => LostFoundEntrega::where('tipo_cierre', 'PAQUETERIA')->firstOrFail());
        $this->assertSame(['DHL', 'ABC123', 'LAURA GÓMEZ'], [$paqueteria->paqueteria, $paqueteria->numero_guia, $paqueteria->nombre_recibe]);
        $donado = $this->enEmpresa(fn () => LostFoundEntrega::where('tipo_cierre', 'DONADO')->firstOrFail());
        $this->assertSame([$eva->id, mb_strtoupper($eva->nombreCompleto())], [$donado->colaborador_id, $donado->nombre_recibe]);

        // Transición prohibida: un artículo ya cerrado no se vuelve a cerrar
        $this->cerrar($this->admin, $a, ['tipo_cierre' => 'DESTRUIDO'])
            ->assertSessionHasErrors(['tipo_cierre' => "El artículo {$a->folio} ya se cerró (Entregado a Beneficencia). Un artículo cerrado no se vuelve a cerrar."]);
        $this->assertSame(4, $this->enEmpresa(fn () => LostFoundEntrega::count()));
        // y el archivo ya no le ofrece "Cerrar / Entregar"
        $this->actingAs($this->admin)->get('/lost-found')->assertDontSee('data-accion="lf-cerrar"', false);
    }

    public function test_validaciones_del_cierre_en_espanol_dentro_del_dialogo(): void
    {
        Storage::fake('local');
        $a = $this->articulo();
        $intentar = fn (array $datos) => $this->from('/lost-found')->actingAs($this->admin)
            ->post("/lost-found/articulos/{$a->id}/cerrar", $datos + ['_dialogo' => 'cerrar-'.$a->id]);

        $intentar(['firma' => self::firmaJpeg()])->assertSessionHasErrors(['tipo_cierre' => 'Elige cómo se cierra el artículo.']);
        $intentar(['tipo_cierre' => 'PERSONA', 'recibe_es' => 'externo', 'firma' => self::firmaJpeg()])
            ->assertSessionHasErrors(['nombre_recibe' => 'Escribe el nombre de quien recibe.']);
        $intentar(['tipo_cierre' => 'PERSONA', 'nombre_recibe' => 'Laura', 'correo_recibe' => 'no-es-correo', 'firma' => self::firmaJpeg()])
            ->assertSessionHasErrors(['correo_recibe' => 'Revisa el correo electrónico (ejemplo: nombre@correo.com).']);
        $intentar(['tipo_cierre' => 'PAQUETERIA', 'nombre_recibe' => 'Laura', 'firma' => self::firmaJpeg()])
            ->assertSessionHasErrors(['paqueteria' => 'Elige la paquetería.', 'numero_guia' => 'Escribe el número de guía del envío.']);
        $intentar(['tipo_cierre' => 'BENEFICENCIA', 'firma' => self::firmaJpeg()])
            ->assertSessionHasErrors(['nombre_recibe' => 'Escribe la institución o persona que recibe la donación.']);
        $intentar(['tipo_cierre' => 'DONADO', 'firma' => self::firmaJpeg()])
            ->assertSessionHasErrors(['colaborador_id' => 'Escanea el gafete o busca al colaborador que recibe la donación.']);
        $intentar(['tipo_cierre' => 'DESTRUIDO'])
            ->assertSessionHasErrors(['firma' => 'Falta firma de quien autoriza la destrucción: firma en el recuadro antes de guardar.']);

        $this->assertSame('EN_RESGUARDO', $this->fresco($a)->estatus);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Tras un error, el diálogo se vuelve a abrir con lo capturado y los errores adentro
        $this->actingAs($this->admin)->from('/lost-found')->followingRedirects()
            ->post("/lost-found/articulos/{$a->id}/cerrar", ['_dialogo' => 'cerrar-'.$a->id, 'tipo_cierre' => 'PAQUETERIA', 'nombre_recibe' => 'Laura', 'paqueteria' => 'DHL'])
            ->assertOk()->assertSee('data-abrir-al-cargar', false)->assertSee(route('lost_found.articulos.cerrar', $a->id))
            ->assertSee('value="PAQUETERIA" required checked', false)->assertSee('Escribe el número de guía del envío.')->assertSee('value="Laura"', false);
    }

    public function test_quien_recibe_puede_ser_colaborador_o_persona_del_padron_de_la_empresa(): void
    {
        Storage::fake('local');
        $eva = $this->colaborador();
        $persona = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'MARK JOHNSON', 'tipo_identificacion' => 'pasaporte', 'folio_identificacion' => 'G1234567']));
        $otraEmpresa = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->colaborador('900', 'Ajeno', $otraEmpresa);

        $a = $this->articulo();
        $this->cerrar($this->admin, $a, ['tipo_cierre' => 'PERSONA', 'recibe_es' => 'colaborador', 'colaborador_id' => $ajeno->id])
            ->assertSessionHasErrors(['colaborador_id' => 'Escanea el gafete o busca al colaborador que recibe el artículo.']);
        $this->cerrar($this->admin, $a, ['tipo_cierre' => 'PERSONA', 'recibe_es' => 'colaborador', 'colaborador_id' => $eva->id, 'nombre_recibe' => 'otro nombre'])->assertSessionHasNoErrors();
        $this->assertSame([$eva->id, mb_strtoupper($eva->nombreCompleto())], [$this->fresco($a)->entrega->colaborador_id, $this->fresco($a)->entrega->nombre_recibe]);

        $b = $this->articulo();
        $this->cerrar($this->admin, $b, ['tipo_cierre' => 'PERSONA', 'recibe_es' => 'externo', 'nombre_recibe' => 'Mark Johnson', 'persona_id' => 999999])
            ->assertSessionHasErrors(['persona_id' => 'La persona elegida no está en el Padrón de personas de esta empresa o está desactivada.']);
        $this->cerrar($this->admin, $b, ['tipo_cierre' => 'PERSONA', 'recibe_es' => 'externo', 'nombre_recibe' => 'Mark Johnson', 'persona_id' => $persona->id])->assertSessionHasNoErrors();
        $entrega = $this->fresco($b)->entrega;
        $this->assertSame([$persona->id, 'PASAPORTE', null], [$entrega->persona_id, $entrega->tipo_identificacion, $entrega->colaborador_id]);
    }

    public function test_la_firma_de_la_entrega_se_sirve_solo_con_permiso_y_alcance(): void
    {
        Storage::fake('local');
        $a = $this->articulo();
        $this->cerrar($this->admin, $a, ['tipo_cierre' => 'DESTRUIDO'])->assertSessionHasNoErrors();
        $entrega = $this->fresco($a)->entrega;

        $this->actingAs($this->admin)->get("/lost-found/entregas/{$entrega->id}/firma")->assertOk();
        $this->actingAs($this->admin)->get("/lost-found/articulos/{$a->id}")->assertOk()->assertSee(route('lost_found.entregas.firma', $entrega->id))
            ->assertSee('Firma de quien autoriza la destrucción');
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->playa))->get("/lost-found/entregas/{$entrega->id}/firma")->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Recursos Humanos'))->get("/lost-found/entregas/{$entrega->id}/firma")->assertForbidden();
        $this->actingAs($this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador'))->get("/lost-found/entregas/{$entrega->id}/firma")->assertNotFound();
    }

    // ----------------------------------------------------- Aislamiento y alcance

    public function test_cada_empresa_ve_y_toca_solo_sus_articulos(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->articulo(['objeto' => 'PARAGUAS AJENO'], null, $otra);
        $propio = $this->articulo(['objeto' => 'PARAGUAS PROPIO']);

        $this->actingAs($this->admin)->get('/lost-found')->assertSee('PARAGUAS PROPIO')->assertDontSee('PARAGUAS AJENO');
        foreach (["/lost-found/articulos/{$ajeno->id}", "/lost-found/articulos/{$ajeno->id}/etiqueta"] as $url) {
            $this->actingAs($this->admin)->get($url)->assertNotFound();
        }
        $this->cerrar($this->admin, $ajeno, ['tipo_cierre' => 'DESTRUIDO'])->assertNotFound();
        $this->assertSame('EN_RESGUARDO', $this->fresco($ajeno)->estatus);
        $this->actingAs($this->admin)->get("/lost-found/articulos/{$propio->id}")->assertOk()->assertSee('PARAGUAS PROPIO');
    }

    public function test_alcance_de_sede_solo_ve_y_entrega_lo_de_sus_sedes(): void
    {
        Storage::fake('local');
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $centro = $this->articulo(['objeto' => 'RELOJ DE CENTRO']);
        $playa = $this->articulo(['objeto' => 'LENTES DE PLAYA'], $this->playa);

        $this->actingAs($agentePlaya)->get('/lost-found')->assertOk()->assertSee('LENTES DE PLAYA')->assertDontSee('RELOJ DE CENTRO')
            ->assertDontSee('Todas las sedes');
        $this->actingAs($agentePlaya)->get("/lost-found/articulos/{$centro->id}")->assertNotFound();
        $this->actingAs($agentePlaya)->get('/lost-found/auditoria')->assertOk()->assertSee('LENTES DE PLAYA')->assertDontSee('RELOJ DE CENTRO');
        $this->cerrar($agentePlaya, $centro, ['tipo_cierre' => 'DESTRUIDO'])->assertNotFound();
        $this->cerrar($agentePlaya, $playa, ['tipo_cierre' => 'DESTRUIDO'])->assertSessionHasNoErrors();
        $this->assertSame('DESTRUIDO', $this->fresco($playa)->estatus);
    }

    public function test_el_agente_consulta_y_entrega_pero_no_configura_dias_y_el_director_solo_consulta(): void
    {
        Storage::fake('local');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $director = $this->crearUsuario($this->empresa, 'Director');
        $a = $this->articulo();

        $this->actingAs($agente)->get('/lost-found')->assertOk()->assertSee('Cerrar / Entregar')->assertSee('Días de Resguardo')->assertDontSee('Configurar Días');
        $this->actingAs($agente)->get('/lost-found/dias-resguardo')->assertOk()->assertSee('Tu rol solo puede consultar esta configuración, no modificarla.')
            ->assertDontSee('Guardar Cambios');
        $this->actingAs($agente)->put('/lost-found/dias-resguardo', ['dias' => ['ROPA' => 5]])->assertForbidden();

        $this->actingAs($director)->get('/lost-found')->assertOk()->assertSee($a->folio)->assertDontSee('data-accion="lf-cerrar"', false);
        $this->actingAs($director)->get("/lost-found/articulos/{$a->id}")->assertOk()->assertDontSee('dialogoCerrarArticulo');
        $this->cerrar($director, $a, ['tipo_cierre' => 'DESTRUIDO'])->assertForbidden();
        $this->actingAs($director)->get("/lost-found/articulos/{$a->id}/etiqueta")->assertOk();

        $this->cerrar($agente, $a, ['tipo_cierre' => 'DESTRUIDO'])->assertSessionHasNoErrors();
        $this->actingAs($this->crearUsuario($this->empresa, 'Recursos Humanos'))->get('/lost-found')->assertForbidden();
    }

    public function test_dias_de_resguardo_solo_con_configurar_en_toda_la_empresa(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->put('/lost-found/dias-resguardo', ['dias' => ['ROPA' => 5]])->assertForbidden();

        $dias = ['OTRO' => 60, 'ALTO_VALOR' => 365, 'ELECTRONICO' => 120, 'ROPA' => 15, 'PERECEDERO' => 1];
        $this->actingAs($this->admin)->get('/lost-found/dias-resguardo')->assertOk()->assertSee('Días de Resguardo — Lost &amp; Found', false)->assertSee('Guardar Cambios');
        $this->actingAs($this->admin)->put('/lost-found/dias-resguardo', ['dias' => ['ROPA' => 0] + $dias])
            ->assertSessionHasErrors(['dias.ROPA' => 'Los días de «Ropa» deben ser al menos 1: un artículo no puede vencer el mismo día que se encuentra.']);
        $this->actingAs($this->admin)->put('/lost-found/dias-resguardo', ['dias' => ['OTRO' => 'mucho'] + $dias])
            ->assertSessionHasErrors(['dias.OTRO' => 'Los días de «Otro» deben ser un número entero.']);
        $this->actingAs($this->admin)->put('/lost-found/dias-resguardo', ['dias' => $dias])
            ->assertRedirect(route('lost_found.umbrales'))->assertSessionHas('ok', 'Los umbrales se guardaron correctamente.');

        $this->assertEquals($dias, $this->enEmpresa(fn () => LostFoundUmbral::vigentes()));
        $this->assertSame(15, Auditoria::where('evento', 'lost_found.configurado')->firstOrFail()->despues['ROPA']);
        // El semáforo usa los días nuevos: 16 días de ropa ya es vencido
        $a = $this->articulo(['tipo_valor' => 'ROPA', 'created_at' => now()->subDays(16)]);
        $this->actingAs($this->admin)->get('/lost-found?filtro=urgentes')->assertSee($a->folio)->assertSee('16 día(s) en resguardo');
        // Otra empresa conserva los suyos
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->assertSame(LostFoundUmbral::POR_OMISION['ROPA'], $this->enEmpresa(fn () => LostFoundUmbral::vigentes()['ROPA'], $otra));
    }

    // -------------------------------------------- Etiqueta, lector y auditoría

    public function test_la_etiqueta_lleva_qr_local_y_el_lector_universal_abre_la_ficha(): void
    {
        $a = $this->articulo(['objeto' => 'MOCHILA']);
        $this->assertSame(24, strlen($a->codigo_qr));

        $etiqueta = $this->actingAs($this->admin)->get("/lost-found/articulos/{$a->id}/etiqueta")->assertOk()
            ->assertSee($a->folio)->assertSee('MOCHILA')->assertSee('Bodega: ANAQUEL 1')->assertSee('Imprimir Etiqueta')->getContent();
        $this->assertStringContainsString('<svg', $etiqueta);
        $this->assertStringNotContainsString('qrserver', $etiqueta);

        // Escanear la etiqueta (QR con la dirección) o teclear el folio encuentra el artículo
        foreach ([route('lector.ir', $a->codigo_qr), $a->folio] as $lectura) {
            $this->actingAs($this->admin)->getJson('/lector/resolver?tipos=lost_found&entrada='.urlencode($lectura))->assertOk()
                ->assertJsonPath('resultados.0.titulo', $a->folio)->assertJsonPath('resultados.0.url', route('lost_found.articulos.show', $a->id));
        }
        $this->actingAs($this->admin)->get('/e/'.$a->codigo_qr)->assertRedirect(route('lost_found.articulos.show', $a->id));
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->playa))->get('/e/'.$a->codigo_qr)->assertNotFound();
        // El archivo ofrece escanear la etiqueta con el lector universal
        $this->actingAs($this->admin)->get('/lost-found')->assertSee('data-tipos="lost_found"', false)->assertSee('Escanear etiqueta de la bolsa');
    }

    public function test_auditoria_de_inventario_solo_lo_que_sigue_en_resguardo_por_sede_y_fechas(): void
    {
        Storage::fake('local');
        $viejo = $this->articulo(['objeto' => 'MALETA VIEJA', 'created_at' => now()->subDays(60)]);
        $nuevo = $this->articulo(['objeto' => 'LIBRO NUEVO']);
        $playa = $this->articulo(['objeto' => 'SANDALIAS'], $this->playa);
        $entregado = $this->articulo(['objeto' => 'COLLAR ENTREGADO']);
        $this->cerrar($this->admin, $entregado, ['tipo_cierre' => 'DESTRUIDO'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/lost-found/auditoria')->assertOk()
            ->assertSee('Auditoría de Inventario — Lost &amp; Found', false)->assertSee('Discrepancias encontradas:')->assertSee('Cotejó / Gerente de Seguridad')
            ->assertSee('MALETA VIEJA')->assertSee('LIBRO NUEVO')->assertSee('SANDALIAS')->assertDontSee('COLLAR ENTREGADO');
        $this->actingAs($this->admin)->get('/lost-found/auditoria?sede='.$this->playa->id)->assertSee('SANDALIAS')->assertDontSee('LIBRO NUEVO');
        $this->actingAs($this->admin)->get('/lost-found/auditoria?desde='.now()->subDays(70)->format('Y-m-d').'&hasta='.now()->subDays(30)->format('Y-m-d'))
            ->assertSee('MALETA VIEJA')->assertDontSee('LIBRO NUEVO');
        $this->actingAs($this->admin)->get('/lost-found/auditoria?desde=2026-10-05&hasta=2026-10-01')->assertSessionHasErrors(['hasta' => '«Hasta» no puede ser antes que «Desde».']);
        $this->actingAs($this->rolCon('Solo consulta L&F', ['lost_found.ver']))->get('/lost-found/auditoria')->assertForbidden();
        $this->actingAs($this->rolCon('Solo consulta L&F 2', ['lost_found.ver']))->get("/lost-found/articulos/{$nuevo->id}/etiqueta")->assertForbidden();
        $this->assertNotNull($viejo);
    }

    // -------------------------------------------------------------- Robo

    public function test_robo_archivo_con_filtros_busqueda_y_conteos_de_segcat(): void
    {
        $conPolicia = $this->robo(['objetos_descripcion' => 'Laptop Dell gris', 'parte_policia' => true, 'folio_policial' => 'FGE-1', 'canalizado_legal' => true]);
        $sospechoso = $this->robo(['objetos_descripcion' => 'Cartera café', 'hay_sospechoso' => true, 'lugar_exacto' => 'CAMASTRO 5', 'valor_estimado' => 2500]);
        $resuelto = $this->robo(['objetos_descripcion' => 'Reloj dorado', 'parte_policia' => true], ['estatus' => Novedad::RESUELTO, 'resolucion' => 'Apareció']);
        $this->novedad(['categoria' => 'incidente_general', 'descripcion' => 'No es robo']);

        $this->actingAs($this->admin)->get('/robos')->assertOk()
            ->assertSee('Robo — Seguimiento')->assertSee('Todos los casos, con su estatus de investigación, en un solo lugar.')
            ->assertSee('Laptop Dell gris')->assertSee('Cartera café')->assertSee('Reloj dorado')->assertDontSee('No es robo')
            ->assertSee('Con parte policial')->assertSee('Sin parte a policía')->assertSee('Con sospechoso')->assertSee('En Legal')
            ->assertSee('Valor estimado: $2,500.00')->assertSee('Abrir Expediente');

        $this->actingAs($this->admin)->get('/robos?filtro=abiertos')->assertSee('Laptop Dell gris')->assertDontSee('Reloj dorado');
        $this->actingAs($this->admin)->get('/robos?filtro=sin_policia')->assertSee('Cartera café')->assertDontSee('Laptop Dell gris');
        $this->actingAs($this->admin)->get('/robos?filtro=con_sospechoso')->assertSee('Cartera café')->assertDontSee('Reloj dorado');
        $this->actingAs($this->admin)->get('/robos?q=laptop')->assertSee('Laptop Dell gris')->assertDontSee('Cartera café');
        $this->actingAs($this->admin)->get('/robos?q=camastro')->assertSee('Cartera café')->assertDontSee('Laptop Dell gris');
        $this->actingAs($this->admin)->get('/robos?q='.urlencode($resuelto->folio()))->assertSee('Reloj dorado')->assertDontSee('Cartera café');
        $this->actingAs($this->admin)->get('/robos?q=nada')->assertSee('No se encontraron casos para «nada».');
        $this->assertNotNull($conPolicia);
        $this->assertNotNull($sospechoso);
    }

    public function test_robo_expediente_guarda_con_las_reglas_de_novedades(): void
    {
        $robo = $this->robo(['objetos_descripcion' => 'Laptop']);
        $this->actingAs($this->admin)->get('/robos?abrir='.$robo->id)->assertOk()
            ->assertSee('Robo '.$robo->folio())->assertSee('1. Circunstancias')->assertSee('2. Sospechoso')->assertSee('3. Testigos')->assertSee('4. Canalización')
            ->assertSee('Buscar Coincidencias en Lost &amp; Found', false)->assertSee(route('novedades.robo.vincular', $robo->id))->assertSee('Guardar Cambios');

        $datos = ['_dialogo' => 'robo-'.$robo->id, 'estatus' => 'abierto', 'nueva_nota' => 'Se revisaron cámaras.', 'robo_hora_aproximada' => '15:30',
            'robo_lugar_exacto' => 'escritorio', 'robo_objetos_descripcion' => 'Laptop Dell gris', 'robo_valor_estimado' => '18000', 'robo_hay_sospechoso' => '1',
            'robo_descripcion_sospechoso' => 'Hombre con gorra', 'robo_se_dio_parte_policia' => '1', 'robo_folio_policial' => 'fge-77', 'robo_canalizado_gerencia' => '1',
            'robo_testigos' => [['nombre' => 'daniela canul', 'departamento' => 'recepción', 'declaracion' => 'Vio a alguien salir.']],
            // Lo que no es del seguimiento de Robo no se toca desde aquí
            'categoria' => 'accidente', 'ubicacion' => 'OTRA', 'descripcion' => 'Cambiada'];
        $this->actingAs($this->admin)->put("/robos/{$robo->id}", $datos)->assertSessionHasNoErrors()
            ->assertRedirect(route('robo.index').'#robo-'.$robo->id)->assertSessionHas('ok', "Expediente Robo {$robo->folio()} guardado correctamente.");

        $n = $this->enEmpresa(fn () => Novedad::with(['robo', 'testigos', 'notas'])->findOrFail($robo->id));
        $this->assertSame(['robo', 'HABITACIÓN 202', 'Se llevaron algo'], [$n->categoria, $n->ubicacion, $n->descripcion]);
        $this->assertSame(['ESCRITORIO', 'FGE-77', true, true, true], [$n->robo->lugar_exacto, $n->robo->folio_policial, $n->robo->hay_sospechoso, $n->robo->parte_policia, $n->robo->canalizado_gerencia]);
        $this->assertSame(['DANIELA CANUL'], $n->testigos->pluck('nombre')->all());
        $this->assertSame('Se revisaron cámaras.', $n->notas->last()->texto);
        $this->assertTrue(Auditoria::where('evento', 'novedades.actualizado')->where('auditable_id', $robo->id)->exists());

        // Resuelto exige resolución; resuelto ya no se edita
        $this->actingAs($this->admin)->put("/robos/{$robo->id}", ['_dialogo' => 'robo-'.$robo->id, 'estatus' => 'resuelto'])
            ->assertSessionHasErrors(['resolucion' => 'Para marcar el caso como Resuelto, primero escribe cómo se concluyó en «Estatus Final / Resolución».']);
        $this->actingAs($this->admin)->put("/robos/{$robo->id}", ['estatus' => 'resuelto', 'resolucion' => 'Apareció en recepción.'])->assertSessionHasNoErrors();
        $this->assertSame(Novedad::RESUELTO, $this->buscar($robo->id)->estatus);
        $this->actingAs($this->admin)->put("/robos/{$robo->id}", ['estatus' => 'abierto', 'nueva_nota' => 'Otra'])
            ->assertSessionHasErrors(['estatus' => 'Este caso ya está Resuelto. Para editarlo usa «Reabrir Caso para Editar» y escribe el motivo.']);
        $this->actingAs($this->admin)->get('/robos?abrir='.$robo->id)->assertSee('Este caso está <strong>Resuelto</strong>', false)
            ->assertSee('Reabrir Caso para Editar')->assertDontSee('Guardar Cambios');

        // Un ticket que no es Robo no se abre ni se guarda aquí
        $otro = $this->novedad(['categoria' => 'incidente_general']);
        $this->actingAs($this->admin)->put("/robos/{$otro->id}", ['estatus' => 'abierto'])->assertNotFound();
    }

    public function test_robo_alcance_de_sede_aislamiento_y_permisos(): void
    {
        $centro = $this->robo(['objetos_descripcion' => 'Robo en centro']);
        $playa = $this->robo(['objetos_descripcion' => 'Robo en playa'], [], $this->playa);
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);

        $this->actingAs($agentePlaya)->get('/robos')->assertOk()->assertSee('Robo en playa')->assertDontSee('Robo en centro');
        $this->actingAs($agentePlaya)->get('/robos?abrir='.$centro->id)->assertOk()->assertDontSee('dialogoRobo');
        $this->actingAs($agentePlaya)->put("/robos/{$centro->id}", ['estatus' => 'abierto'])->assertNotFound();
        $this->actingAs($agentePlaya)->put("/robos/{$playa->id}", ['estatus' => 'abierto', 'nueva_nota' => 'Visto'])->assertSessionHasNoErrors();

        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->get('/robos?abrir='.$centro->id)->assertOk()->assertSee('Tu rol solo puede consultar este caso, no editarlo.')->assertDontSee('Guardar Cambios');
        $this->actingAs($director)->put("/robos/{$centro->id}", ['estatus' => 'abierto'])->assertForbidden();

        $this->actingAs($this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador'))->put("/robos/{$centro->id}", ['estatus' => 'abierto'])->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Recursos Humanos'))->get('/robos')->assertForbidden();
    }

    public function test_quien_solo_tiene_robo_seguimiento_atiende_sus_casos(): void
    {
        $usuario = $this->rolCon('Investigador', ['robo.ver', 'robo.editar'], Alcance::Sede, $this->centro);
        $robo = $this->robo(['objetos_descripcion' => 'Laptop']);

        $this->actingAs($usuario)->get('/novedades')->assertForbidden();
        $this->actingAs($usuario)->get('/robos')->assertOk()->assertSee('Laptop')->assertDontSee(route('novedades.index').'"', false);
        $this->actingAs($usuario)->put("/robos/{$robo->id}", ['estatus' => 'pendiente_turno', 'robo_hay_sospechoso' => '0', 'nueva_nota' => 'Queda para el turno de noche.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Novedad::PENDIENTE_TURNO, $this->buscar($robo->id)->estatus);
    }

    public function test_vincular_hallazgo_y_su_entrega_queda_en_el_caso_de_robo(): void
    {
        Storage::fake('local');
        $robo = $this->robo(['objetos_descripcion' => 'Laptop']);
        $laptop = $this->articulo(['objeto' => 'LAPTOP', 'tipo_valor' => 'ELECTRONICO']);

        $this->actingAs($this->admin)->postJson(route('novedades.robo.vincular', $robo->id), ['articulo_id' => $laptop->id])->assertOk();
        $this->actingAs($this->admin)->get('/robos')->assertSee('Vinculado con el hallazgo '.$laptop->folio.' — LAPTOP');
        $this->actingAs($this->admin)->get("/lost-found/articulos/{$laptop->id}")->assertSee('Vinculado con el caso de Robo');

        $this->cerrar($this->admin, $laptop, ['tipo_cierre' => 'PERSONA', 'nombre_recibe' => 'Laura Gómez'])->assertSessionHasNoErrors();
        $notas = $this->enEmpresa(fn () => NovedadNota::where('novedad_id', $robo->id)->pluck('texto')->all());
        $this->assertContains("El hallazgo vinculado Cerró el artículo {$laptop->folio} (LAPTOP): Devuelto en persona — recibe LAURA GÓMEZ.", $notas);
    }

    public function test_reporte_de_perdida_vinculado_propone_a_quien_recibe(): void
    {
        $a = $this->articulo(['objeto' => 'TELÉFONO']);
        $this->enEmpresa(function () use ($a) {
            $r = new LostFoundReportePerdida(['novedad_id' => $a->novedad_id, 'sede_id' => $a->sede_id, 'objeto' => 'TELÉFONO', 'nombre_huesped' => 'LAURA GÓMEZ', 'correo' => 'laura@example.com']);
            $r->numero = 1;
            $r->folio = 'RP-000001';
            $r->save();
            $r->forceFill(['estatus' => LostFoundReportePerdida::VINCULADO, 'articulo_vinculado_id' => $a->id])->save();
        });

        $this->actingAs($this->admin)->get('/lost-found')->assertSee('Reporte de pérdida RP-000001 — LAURA GÓMEZ')
            ->assertSee('data-recibe="LAURA GÓMEZ"', false)->assertSee('data-correo="laura@example.com"', false);
        $this->actingAs($this->admin)->get("/lost-found/articulos/{$a->id}")->assertSee('value="LAURA GÓMEZ"', false);
    }

    // ------------------------------------------------------------- Menú y demo

    public function test_el_menu_lleva_al_archivo_de_lost_found_y_a_robo(): void
    {
        $this->assertSame('lost_found.archivo', Modulo::where('clave', 'lost_found')->value('ruta'));
        $this->assertSame('robo.index', Modulo::where('clave', 'robo')->value('ruta'));
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get('/lost-found')->assertOk()
            ->assertSee(route('lost_found.archivo'))->assertSee(route('robo.index'));
    }

    public function test_datos_demo_de_lost_found_y_robo_solo_la_primera_vez(): void
    {
        Storage::fake('local');
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();

        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $this->enEmpresa(function () {
            $this->assertEqualsCanonicalizing(['PERSONA', 'DONADO'], LostFoundEntrega::pluck('tipo_cierre')->all());
            $this->assertSame(LostFoundReportePerdida::VINCULADO, LostFoundReportePerdida::where('nombre_huesped', 'LAURA GÓMEZ')->value('estatus'));
            $this->assertTrue(RoboDetalle::where('hay_sospechoso', true)->where('parte_policia', false)->exists());
            $this->assertTrue(Novedad::where('categoria', 'lost_found')->whereDoesntHave('articulos')->exists());
            foreach (LostFoundEntrega::pluck('firma_ruta') as $ruta) {
                Storage::disk('local')->assertExists($ruta);
            }
        }, $demo);
    }
}
