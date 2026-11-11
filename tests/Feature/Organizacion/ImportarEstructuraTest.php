<?php

namespace Tests\Feature\Organizacion;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Importacion;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Services\Importacion\ImportadorEstructura;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\Importacion\LectorArchivo;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;
use ZipArchive;

/**
 * Importar estructura (docs/tecnico/importar-estructura.md): plantilla .xlsx
 * por giro, vista previa renglón por renglón, alta completa en hotel,
 * corporativo y condominio, idempotencia, errores, tenant, permisos,
 * límites, todo o nada y exportar → importar ida y vuelta.
 */
class ImportarEstructuraTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $hotel;

    private User $sa;

    private const ENC = 'Sede,Código de sede,Ciudad,Estado,Zona o edificio,Piso,Habitación,Tipo de habitación,Área de la habitación,Tipo de área,Código';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->hotel = $this->crearEmpresa('Hotel Uno');
        $this->sa = $this->crearSuperadmin();
    }

    // ------------------------------------------------------------------ Apoyo

    private function empresaDeGiro(string $giro, string $nombre): Empresa
    {
        return app(ProvisionarEmpresa::class)->crear(Rubro::where('clave', $giro)->firstOrFail(), ['nombre_comercial' => $nombre]);
    }

    /** El Super Administrador trabajando con esa empresa. */
    private function como(Empresa $empresa, ?User $usuario = null): self
    {
        $usuario ??= $this->sa;

        return $this->actingAs($usuario)->withSession($usuario->es_superadmin ? [EmpresaDeTrabajo::SESION => $empresa->id] : []);
    }

    private function csv(string $contenido, string $nombre = 'estructura.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    private function subir(Empresa $empresa, UploadedFile $archivo, ?User $usuario = null): TestResponse
    {
        return $this->como($empresa, $usuario)->post('/importar/estructura', ['archivo' => $archivo]);
    }

    private function guardar(Empresa $empresa, Importacion $i, ?User $usuario = null): TestResponse
    {
        return $this->como($empresa, $usuario)->post(route('importaciones.aplicar', $i));
    }

    private function ultima(): Importacion
    {
        return Importacion::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** @return array<int, string> renglón => estado */
    private function estados(Importacion $i): array
    {
        return collect($i->aplicada() ? $i->resultado : $i->filas)->mapWithKeys(fn ($f) => [$f['fila'] => $f['estado']])->all();
    }

    private function fila(Importacion $i, int $renglon): array
    {
        return collect($i->aplicada() ? $i->resultado : $i->filas)->firstWhere('fila', $renglon);
    }

    private function en(Empresa $empresa, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($empresa->id, $fn);
    }

    /**
     * Rutas «Sede › Zona › …» de lo que tiene la empresa (para comparar estructuras).
     *
     * @return list<string>
     */
    private function rutas(Empresa $empresa): array
    {
        return $this->en($empresa, function () {
            $nombres = Espacio::pluck('nombre', 'id');
            $sedes = Sede::pluck('nombre', 'id');
            $rutas = Espacio::get()->map(fn (Espacio $e) => $sedes[$e->sede_id].' › '.collect([...$e->idsAncestros(), $e->id])->map(fn ($id) => $nombres[$id])->implode(' › ').' ['.$e->nivel.']')->all();
            foreach (Sede::get() as $s) {
                $rutas[] = $s->nombre.' ['.$s->codigo.']';
            }
            sort($rutas);

            return $rutas;
        });
    }

    private function cuantos(Empresa $empresa, string $nivel): int
    {
        return Espacio::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('nivel', $nivel)->count();
    }

    private function rolCon(Empresa $empresa, string $nombre, array $permisos): Rol
    {
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($this->sa, $empresa->id, ['nombre' => $nombre, 'nivel_jerarquia' => 45]);
        $roles->sincronizarPermisos($this->sa, $rol, $permisos);

        return $rol;
    }

    private function hotelCompleto(): string
    {
        return self::ENC."\n"
            ."Hotel Centro,HCEN,Cancún,Quintana Roo,,,,,,,\n"
            ."Hotel Centro,,,,Torre A,Piso 1,101,Habitación,,,TA-101\n"
            ."Hotel Centro,,,,Torre A,Piso 1,102,Suite,Terraza,Terraza,\n"
            ."Hotel Centro,,,,Torre A,Piso 1,102,,Baño,Baño,\n"
            ."Hotel Centro,,,,Torre A,Piso 2,201,Suite nupcial,,,\n"
            ."Hotel Centro,,,,Zona exterior,,Alberca principal,Alberca,,,\n"
            ."Hotel Playa,HPLA,Tulum,Quintana Roo,Edificio Principal,,Lobby,,Mostrador,Recepción,\n";
    }

    // ----------------------------------------------------------- Plantilla

    public function test_plantilla_xlsx_con_hoja_de_instrucciones_ejemplos_ficticios_y_columnas_segun_el_giro(): void
    {
        $r = $this->como($this->hotel)->get('/importar/estructura/plantilla')->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $r->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', (string) $r->headers->get('Content-Disposition'));
        $ruta = tempnam(sys_get_temp_dir(), 'pl');
        file_put_contents($ruta, $r->streamedContent());

        $hoja = LectorArchivo::xlsx($ruta);
        $this->assertSame(['Sede *', 'Código de sede', 'Ciudad', 'Estado', 'Zona o edificio', 'Piso', 'Habitación', 'Tipo de habitación', 'Área de la habitación', 'Tipo de área', 'Código'], $hoja[1]);
        $this->assertSame('Hotel Centro', $hoja[2][0], 'ejemplos ficticios');
        $zip = new ZipArchive;
        $zip->open($ruta);
        $this->assertStringContainsString('name="Instrucciones"', (string) $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('Todo o nada', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('Suite', (string) $zip->getFromName('xl/worksheets/sheet2.xml'), 'lista los tipos que ya tiene');
        $zip->close();

        // La plantilla tal cual se puede subir: sus ejemplos son válidos
        $this->subir($this->hotel, new UploadedFile($ruta, 'Plantilla.xlsx', null, null, true))->assertRedirect();
        $this->assertSame(0, $this->ultima()->resumen['error']);
        $this->assertSame(0, Sede::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->count(), 'la vista previa no guarda nada');

        // Corporativo: «Oficina»; condominio: «Vivienda» y «Área común»
        $corp = $this->empresaDeGiro('corporativo', 'Corporativo Uno');
        $condo = $this->empresaDeGiro('condominio', 'Condominio Uno');
        foreach ([[$corp, 'Oficina', false], [$condo, 'Vivienda', true]] as [$empresa, $palabra, $comun]) {
            file_put_contents($ruta, $this->como($empresa)->get('/importar/estructura/plantilla')->streamedContent());
            $enc = LectorArchivo::xlsx($ruta)[1];
            $this->assertContains($palabra, $enc);
            $this->assertContains('Tipo de '.mb_strtolower($palabra), $enc);
            $this->assertContains('Área de la '.mb_strtolower($palabra), $enc);
            $this->assertSame($comun, in_array('Área común', $enc, true));
            $this->subir($empresa, new UploadedFile($ruta, 'p.xlsx', null, null, true));
            $this->assertSame(0, $this->ultima()->resumen['error'], "plantilla de {$palabra} válida");
        }
        @unlink($ruta);
    }

    public function test_pantalla_en_sedes_para_super_administrador_con_empresa_de_trabajo_y_administrador(): void
    {
        // Sin empresa de trabajo: el selector, no un error
        $this->actingAs($this->sa)->get('/importar/estructura')->assertOk()->assertSee('Empresa de trabajo')->assertSee('Elige arriba la');
        $this->como($this->hotel)->get('/importar/estructura')->assertOk()
            ->assertSee('Importar estructura')->assertSee('Hotel Uno')->assertSee('Descargar plantilla')->assertSee('Descargar lo que ya hay')->assertSee('5,000');
        $this->como($this->hotel)->get('/sedes')->assertOk()->assertSee(route('importaciones.create', 'estructura'), false);

        // El Administrador de la empresa lo tiene (como su «Crear» de Sedes); el agente no
        $admin = $this->crearUsuario($this->hotel, 'Administrador');
        $this->actingAs($admin)->get('/sedes')->assertOk()->assertSee('Importar estructura');
        $this->actingAs($admin)->get('/importar/estructura')->assertOk();
        $agente = $this->crearUsuario($this->hotel, 'Agente', $this->crearSede($this->hotel, 'CEN'));
        $this->actingAs($agente)->get('/importar/estructura')->assertForbidden();
        $this->actingAs($agente)->get('/importar/estructura/plantilla')->assertForbidden();
        $this->actingAs($agente)->get('/importar/estructura/exportar')->assertForbidden();
        $this->actingAs($agente)->post('/importar/estructura', ['archivo' => $this->csv($this->hotelCompleto())])->assertForbidden();
        // Exportar solo existe para estructura
        $this->como($this->hotel)->get('/importar/zonas/exportar')->assertNotFound();
    }

    // -------------------------------------------- Vista previa y guardado

    public function test_hotel_vista_previa_renglon_por_renglon_sin_guardar_y_luego_crea_todo_con_empresa_y_auditoria(): void
    {
        $this->subir($this->hotel, $this->csv($this->hotelCompleto()))->assertRedirect();
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'nueva', 4 => 'nueva', 5 => 'nueva', 6 => 'nueva', 7 => 'nueva', 8 => 'nueva'], $this->estados($i));
        $this->assertSame('Se crea: Torre A › Piso 1 › 101.', $this->fila($i, 3)['mensaje']);
        $this->assertSame(['sedes' => 2, 'zonas' => 3, 'pisos' => 2, 'especificas' => 5, 'areas' => 3, 'tipos' => 2], $i->resumen['crea']);
        $this->assertSame(0, Espacio::withoutGlobalScopes()->count());
        $this->assertSame(0, Sede::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->count());
        $this->assertSame(0, TipoEspacio::whereNotNull('empresa_id')->count());
        $this->como($this->hotel)->get(route('importaciones.show', $i))->assertOk()
            ->assertSee('Guarda la estructura')->assertSee('2 sedes, 3 zonas o edificios, 2 pisos, 5 habitaciones, 3 áreas y 2 tipos nuevos');

        $this->guardar($this->hotel, $i)->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, '5 habitaciones'));
        $i->refresh();
        $this->assertTrue($i->aplicada());
        $this->assertSame([3, 3], [$this->cuantos($this->hotel, Espacio::EDIFICIO), $this->cuantos($this->hotel, Espacio::SUBAREA)]);
        $this->assertSame(5, $this->cuantos($this->hotel, Espacio::AREA_ESPECIFICA));
        $this->assertSame([
            'Hotel Centro [HCEN]', 'Hotel Centro › Torre A [edificio]', 'Hotel Centro › Torre A › Piso 1 [area]',
            'Hotel Centro › Torre A › Piso 1 › 101 [area_especifica]', 'Hotel Centro › Torre A › Piso 1 › 102 [area_especifica]',
            'Hotel Centro › Torre A › Piso 1 › 102 › Baño [subarea]', 'Hotel Centro › Torre A › Piso 1 › 102 › Terraza [subarea]',
            'Hotel Centro › Torre A › Piso 2 [area]', 'Hotel Centro › Torre A › Piso 2 › 201 [area_especifica]',
            'Hotel Centro › Zona exterior [edificio]', 'Hotel Centro › Zona exterior › Alberca principal [area_especifica]',
            'Hotel Playa [HPLA]', 'Hotel Playa › Edificio Principal [edificio]', 'Hotel Playa › Edificio Principal › Lobby [area_especifica]',
            'Hotel Playa › Edificio Principal › Lobby › Mostrador [subarea]',
        ], $this->rutas($this->hotel));

        $this->en($this->hotel, function () {
            $centro = Sede::where('codigo', 'HCEN')->firstOrFail();
            $this->assertSame(['Cancún', 'Quintana Roo'], [$centro->ciudad, $centro->entidad]);
            $this->assertSame('TA-101', Espacio::where('nombre', '101')->value('codigo'));
            $this->assertSame('Suite', Espacio::where('nombre', '102')->firstOrFail()->tipo->nombre, 'tipo del sistema reutilizado');
            $nupcial = Espacio::where('nombre', '201')->firstOrFail()->tipo;
            $this->assertSame(['Suite nupcial', $this->hotel->id], [$nupcial->nombre, $nupcial->empresa_id], 'tipo nuevo, propio de la empresa');
            $recepcion = Espacio::where('nombre', 'Mostrador')->firstOrFail()->tipo;
            $this->assertSame(['Recepción', Espacio::SUBAREA, $this->hotel->id], [$recepcion->nombre, $recepcion->nivel, $recepcion->empresa_id], 'tipo de área nuevo');
            $this->assertSame([$this->sa->id], Espacio::distinct()->pluck('creado_por')->all());
        });
        $this->assertSame(15 - 2, Espacio::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->count());
        $this->assertSame(0, Espacio::withoutGlobalScopes()->where('empresa_id', '!=', $this->hotel->id)->count());

        $evento = Auditoria::withoutGlobalScopes()->where('evento', 'sedes.importado')->firstOrFail();
        $this->assertSame([7, 7, 0, 2, 5], [$evento->despues['filas'], $evento->despues['creadas'], $evento->despues['con_error'], $evento->despues['crea']['sedes'], $evento->despues['crea']['especificas']]);
        $this->assertSame($this->hotel->id, $evento->empresa_id);
        $this->assertSame(2, Auditoria::withoutGlobalScopes()->where('evento', 'sedes.creada')->count());
        $this->assertSame(13, Auditoria::withoutGlobalScopes()->where('evento', 'espacios.creado')->count());
        $this->como($this->hotel)->get(route('importaciones.show', $i))->assertOk()->assertSee('Se creó:')->assertSee('Ir a Zonas y edificios');
    }

    public function test_corporativo_y_condominio_con_sus_palabras_y_areas_comunes(): void
    {
        $corp = $this->empresaDeGiro('corporativo', 'Corporativo Uno');
        $this->subir($corp, $this->csv("Sede,Ciudad,Estado,Zona o edificio,Piso,Oficina,Tipo de oficina,Área de la oficina\n"
            ."Planta Norte,Monterrey,Nuevo León,Edificio Administrativo,Piso 1,Recursos Humanos,Oficina,Archivo\n"
            ."Planta Norte,,,Nave 1,,Almacén,Bodega,\n"));
        $this->assertSame([2 => 'nueva', 3 => 'nueva'], $this->estados($this->ultima()));
        $this->guardar($corp, $this->ultima())->assertSessionHas('ok', fn ($m) => str_contains($m, '2 oficinas'));
        $this->assertSame(2, $this->cuantos($corp, Espacio::AREA_ESPECIFICA));
        $this->assertSame('PLANTANORT', Sede::withoutGlobalScopes()->where('empresa_id', $corp->id)->value('codigo'), 'código armado con el nombre');

        $condo = $this->empresaDeGiro('condominio', 'Condominio Uno');
        $this->subir($condo, $this->csv("Sede,Ciudad,Estado,Zona o edificio,Piso,Vivienda,Tipo de vivienda,Área común,Área de la vivienda\n"
            ."Residencial Los Pinos,Mérida,Yucatán,Torre 1,Piso 1,101,Departamento,,Terraza\n"
            ."Residencial Los Pinos,,,Áreas comunes,,Alberca,,Sí,\n"
            ."Residencial Los Pinos,,,Áreas comunes,,Salón,,quizá,\n"));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'nueva', 4 => 'error'], $this->estados($i));
        $this->assertStringContainsString('escribe Sí o No', $this->fila($i, 4)['errores']['area_comun']);

        $this->subir($condo, $this->csv("Sede,Ciudad,Estado,Zona o edificio,Piso,Vivienda,Tipo de vivienda,Área común,Área de la vivienda\n"
            ."Residencial Los Pinos,Mérida,Yucatán,Torre 1,Piso 1,101,Departamento,,Terraza\n"
            ."Residencial Los Pinos,,,Áreas comunes,,Alberca,,Sí,\n"));
        $this->guardar($condo, $this->ultima())->assertSessionHas('ok', fn ($m) => str_contains($m, '2 viviendas'));
        $this->en($condo, function () {
            $this->assertTrue(Espacio::where('nombre', 'Alberca')->firstOrFail()->esAreaComun());
            $this->assertFalse(Espacio::where('nombre', '101')->firstOrFail()->esAreaComun());
            $this->assertSame(Espacio::SUBAREA, Espacio::where('nombre', 'Terraza')->value('nivel'));
        });
    }

    // --------------------------------------------------------- Idempotencia

    public function test_subir_el_mismo_archivo_dos_veces_no_crea_nada_y_reconoce_nombres_normalizados(): void
    {
        $this->subir($this->hotel, $this->csv($this->hotelCompleto()));
        $this->guardar($this->hotel, $this->ultima());
        $antes = $this->rutas($this->hotel);
        $tipos = TipoEspacio::count();

        $this->subir($this->hotel, $this->csv($this->hotelCompleto()));
        $i = $this->ultima();
        $this->assertSame(array_fill(2, 7, 'existe'), $this->estados($i));
        $this->assertStringContainsString('Ya existe: Hotel Centro › Torre A › Piso 1 › 101', $this->fila($i, 3)['mensaje']);
        $this->como($this->hotel)->get(route('importaciones.show', $i))->assertSee('no hay nada nuevo que guardar')->assertDontSee('Guarda lo que está bien');
        $this->guardar($this->hotel, $i)->assertRedirect();
        $this->assertSame($antes, $this->rutas($this->hotel));
        $this->assertSame($tipos, TipoEspacio::count());

        // Mayúsculas, acentos y espacios de más: es el mismo lugar; la sede por su código también
        $this->subir($this->hotel, $this->csv(self::ENC."\n"
            ."hotel  centro,,,,TORRE A,piso 1,101,,,,\n"
            ."HCEN,,,,Torre A,Piso 1,102,,baño,,\n"
            ."Hotel Centro,,,,Zona Exterior,,Alberca Principal,,,,\n"));
        $this->assertSame([2 => 'existe', 3 => 'existe', 4 => 'existe'], $this->estados($this->ultima()));
        $this->assertSame(['Hotel Centro › Torre A › Piso 1 › 102 › Baño [subarea]'], array_values(array_filter($this->rutas($this->hotel), fn ($r) => str_contains($r, 'Baño'))));
    }

    // -------------------------------------------------------------- Errores

    public function test_errores_con_renglon_y_explicacion_y_con_errores_no_se_guarda_nada(): void
    {
        $this->subir($this->hotel, $this->csv(self::ENC."\n"
            ."Hotel Centro,HCEN,Cancún,Quintana Roo,Torre A,,,,,,\n"          // 2 bien
            .",,,,Torre B,,,,,,\n"                                              // 3 sin sede
            ."Hotel Centro,,,,,Piso 1,,,,,\n"                                   // 4 piso sin zona
            ."Hotel Centro,,,,Torre A,,,,Terraza,,\n"                           // 5 área sin habitación
            ."Hotel Centro,,,,Torre A,Piso 1,,Suite,,,\n"                       // 6 tipo sin habitación
            .'Hotel Centro,,,,'.str_repeat('x', 101).",,,,,,\n"                 // 7 muy largo
            ."Hotel Nuevo,,,,Torre A,,,,,,\n"                                   // 8 sede nueva sin ciudad ni estado
            ."Hotel Centro,HC-OTRO,,,Torre C,,,,,,\n"                           // 9 código de sede distinto
            ."Hotel Centro,HCEN,Cancún,Quintana Roo,Torre A,,,,,,\n"          // 10 repetido
            ."Hotel Otro,MAL CODIGO,Mérida,Yucatán,,,,,,,\n"                    // 11 código con espacio
            ."Hotel Centro,,,,,,,,,,X-1\n"));                                   // 12 código sin lugar
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error', 4 => 'error', 5 => 'error', 6 => 'error', 7 => 'error', 8 => 'error', 9 => 'error', 10 => 'error', 11 => 'error', 12 => 'error'], $this->estados($i));
        $this->assertSame('Escribe la sede.', $this->fila($i, 3)['errores']['sede']);
        $this->assertStringContainsString('falta la zona o edificio', $this->fila($i, 4)['errores']['piso']);
        $this->assertStringContainsString('falta la habitación', $this->fila($i, 5)['errores']['area']);
        $this->assertStringContainsString('Hay tipo de habitación pero falta la habitación', $this->fila($i, 6)['errores']['tipo_especifica']);
        $this->assertStringContainsString('hasta 100 caracteres y trae 101', $this->fila($i, 7)['errores']['zona']);
        $this->assertStringContainsString('es nueva: escribe su ciudad', $this->fila($i, 8)['errores']['ciudad']);
        $this->assertStringContainsString('es nueva: escribe su estado', $this->fila($i, 8)['errores']['entidad']);
        $this->assertStringContainsString('ya existe con el código «HCEN»', $this->fila($i, 9)['errores']['sede_codigo']);
        $this->assertStringContainsString('Repetido en el archivo', $this->fila($i, 10)['mensaje']);
        $this->assertStringContainsString('igual a la fila 2', $this->fila($i, 10)['mensaje']);
        $this->assertStringContainsString('solo puede tener letras, números y guiones', $this->fila($i, 11)['errores']['sede_codigo']);
        $this->assertStringContainsString('Código de sede', $this->fila($i, 12)['errores']['codigo']);

        $this->como($this->hotel)->get(route('importaciones.show', $i))->assertOk()
            ->assertSee('No se puede guardar: 10 filas tienen error')->assertDontSee('>Guardar</button>', false);
        $this->guardar($this->hotel, $i)->assertSessionHasErrors('archivo');
        $this->assertSame(Importacion::VISTA_PREVIA, $i->fresh()->estado);
        $this->assertSame(0, Sede::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->count(), 'todo o nada: ni la fila buena se guarda');

        // El archivo de resultado dice el renglón y el motivo
        $resultado = LectorArchivo::csv($this->como($this->hotel)->get(route('importaciones.resultado', $i))->streamedContent());
        $this->assertSame('Fila', $resultado[1][0]);
        $this->assertSame('4', $resultado[4][0]);
        $this->assertSame('Con error', $resultado[4][count($resultado[1]) - 2]);
    }

    public function test_lugar_desactivado_o_sede_de_baja_no_se_reactiva_ni_se_duplica(): void
    {
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,HCEN,Cancún,Quintana Roo,Torre A,Piso 1,101,,,,\nHotel Playa,HPLA,Tulum,Quintana Roo,,,,,,,\n"));
        $this->guardar($this->hotel, $this->ultima());
        $torre = Espacio::withoutGlobalScopes()->where('nombre', 'Torre A')->firstOrFail();
        Espacio::withoutGlobalScopes()->where('ruta', 'like', $torre->ruta.'%')->update(['activo' => false]); // como «Dar de baja»: arrastra lo de adentro
        Sede::withoutGlobalScopes()->where('codigo', 'HPLA')->update(['activo' => false]);

        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,,,,Torre A,Piso 1,101,,,,\nHotel Centro,,,,Torre A,Piso 1,102,,,,\nHotel Playa,,,,Torre B,,,,,,\n"));
        $i = $this->ultima();
        $this->assertSame([2 => 'existe', 3 => 'error', 4 => 'error'], $this->estados($i));
        $this->assertStringContainsString('(desactivado)', $this->fila($i, 2)['mensaje']);
        $this->assertStringContainsString('está desactivado', $this->fila($i, 3)['errores']['especifica']);
        $this->assertStringContainsString('está dada de baja', $this->fila($i, 4)['errores']['sede']);
    }

    // ------------------------------------------------------------- Tenant

    public function test_tenant_cada_empresa_crea_y_reutiliza_solo_lo_suyo(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->subir($otra, $this->csv(self::ENC."\nHotel Centro,HCEN,Cancún,Quintana Roo,Torre A,Piso 1,101,,,,\n"));
        $this->guardar($otra, $this->ultima());

        // La empresa 1 tiene una sede con el mismo nombre en la otra: no la reutiliza, la crea en la suya
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,HCEN,Cancún,Quintana Roo,Torre A,Piso 1,101,,,,\n"));
        $i = $this->ultima();
        $this->assertSame($this->hotel->id, $i->empresa_id);
        $this->assertSame([2 => 'nueva'], $this->estados($i));
        $this->guardar($this->hotel, $i);
        $this->assertSame(1, Sede::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->count());
        $this->assertSame(1, Sede::withoutGlobalScopes()->where('empresa_id', $otra->id)->count());
        $this->assertSame(3, $this->cuantos($otra, Espacio::EDIFICIO) + $this->cuantos($otra, Espacio::AREA) + $this->cuantos($otra, Espacio::AREA_ESPECIFICA));
        Espacio::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->get()
            ->each(fn ($e) => $this->assertSame((int) Sede::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->value('id'), (int) $e->sede_id));

        // Lo de una empresa no se ve ni se guarda desde la otra
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->get(route('importaciones.show', $i))->assertNotFound();
        $this->actingAs($adminOtra)->post(route('importaciones.aplicar', $i))->assertNotFound();
        $exportado = $this->actingAs($adminOtra)->get('/importar/estructura/exportar')->assertOk()->streamedContent();
        $ruta = tempnam(sys_get_temp_dir(), 'ex');
        file_put_contents($ruta, $exportado);
        $this->assertCount(4, LectorArchivo::xlsx($ruta), 'encabezado + 3 lugares de su empresa');
        @unlink($ruta);
    }

    // ------------------------------------------------------------ Permisos

    public function test_permisos_sin_crear_sedes_o_lugares_y_con_alcance_de_sede(): void
    {
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,HCEN,Cancún,Quintana Roo,,,,,,,\nHotel Playa,HPLA,Tulum,Quintana Roo,,,,,,,\n"));
        $this->guardar($this->hotel, $this->ultima());
        [$centro, $playa] = [Sede::withoutGlobalScopes()->where('codigo', 'HCEN')->firstOrFail(), Sede::withoutGlobalScopes()->where('codigo', 'HPLA')->firstOrFail()];

        // Importa y crea lugares, pero no sedes
        $this->rolCon($this->hotel, 'Capturista', ['sedes.ver' => Alcance::Empresa, 'sedes.importar' => Alcance::Empresa, 'espacios.ver' => Alcance::Empresa, 'espacios.crear' => Alcance::Empresa]);
        $capturista = $this->crearUsuario($this->hotel, 'Capturista');
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,,,,Torre A,,,,,,\nHotel Nueva,HNUE,León,Guanajuato,Torre A,,,,,,\n"), $capturista);
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error'], $this->estados($i));
        $this->assertStringContainsString('tu rol no puede dar de alta sedes', $this->fila($i, 3)['errores']['sede']);

        // Sin «Crear» de Zonas y edificios
        $this->rolCon($this->hotel, 'Solo sedes', ['sedes.ver' => Alcance::Empresa, 'sedes.importar' => Alcance::Empresa, 'sedes.crear' => Alcance::Empresa]);
        $soloSedes = $this->crearUsuario($this->hotel, 'Solo sedes');
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,,,,Torre A,,,,,,\nHotel Nueva,HNUE,León,Guanajuato,,,,,,,\n"), $soloSedes);
        $i = $this->ultima();
        $this->assertSame([2 => 'error', 3 => 'nueva'], $this->estados($i));
        $this->assertStringContainsString('pide el permiso «Crear» de Zonas y edificios', $this->fila($i, 2)['errores']['zona']);

        // Con alcance de sede solo crea lugares en la suya
        $this->rolCon($this->hotel, 'Jefe Centro', ['sedes.ver' => Alcance::Sede, 'sedes.importar' => Alcance::Sede, 'espacios.ver' => Alcance::Sede, 'espacios.crear' => Alcance::Sede]);
        $jefe = $this->crearUsuario($this->hotel, 'Jefe Centro', $centro);
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,,,,Torre B,,,,,,\nHotel Playa,,,,Torre B,,,,,,\n"), $jefe);
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error'], $this->estados($i));
        $this->assertStringContainsString('solo en tus sedes', $this->fila($i, 3)['errores']['zona']);
        $this->assertSame(0, Espacio::withoutGlobalScopes()->where('sede_id', $playa->id)->count());

        // Sin «Importar» de Sedes, ni la pantalla
        $this->rolCon($this->hotel, 'Sin importar', ['sedes.ver' => Alcance::Empresa, 'sedes.crear' => Alcance::Empresa, 'espacios.crear' => Alcance::Empresa]);
        $sinImportar = $this->crearUsuario($this->hotel, 'Sin importar');
        $this->actingAs($sinImportar)->get('/importar/estructura')->assertForbidden();
        $this->actingAs($sinImportar)->get('/sedes')->assertOk()->assertDontSee(route('importaciones.create', 'estructura'), false);
    }

    // -------------------------------------------------------------- Límites

    public function test_limite_de_sedes_del_plan_cuenta_las_que_crea_el_mismo_archivo(): void
    {
        DB::table('suscripciones')->insert(['empresa_id' => $this->hotel->id, 'sedes_contratadas' => 2, 'estado' => 'activa', 'suspendida' => false,
            'inicio_en' => now()->subMonth()->toDateString(), 'vence_en' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->crearSede($this->hotel, 'EXI');

        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,HCEN,Cancún,Quintana Roo,,,,,,,\nHotel Playa,HPLA,Tulum,Quintana Roo,,,,,,,\n"));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error'], $this->estados($i));
        $this->assertStringContainsString('Tu plan incluye 2 sedes', $this->fila($i, 3)['errores']['sede']);
        $this->assertSame(1, Sede::withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->count());
    }

    public function test_maximo_5000_renglones_y_5_mb(): void
    {
        $this->assertSame(5000, app(ImportadorEstructura::class)->maxFilas());
        $renglones = '';
        for ($n = 1; $n <= 5001; $n++) {
            $renglones .= "Hotel Centro,,,,Torre A,,{$n},,,,\n";
        }
        $this->subir($this->hotel, $this->csv(self::ENC."\n".$renglones))->assertSessionHasErrors(['archivo' => 'El archivo trae 5,001 filas y el máximo es 5,000 por archivo. Divídelo en varios archivos y súbelos uno por uno.']);
        $this->assertSame(0, Importacion::withoutGlobalScopes()->count());

        $this->subir($this->hotel, UploadedFile::fake()->create('grande.csv', 5121))->assertSessionHasErrors('archivo');
        $this->assertSame(0, Importacion::withoutGlobalScopes()->count());
    }

    public function test_limite_de_peticiones_al_subir_y_exportar(): void
    {
        for ($n = 0; $n < 10; $n++) {
            $this->como($this->hotel)->get('/importar/estructura/exportar')->assertOk();
        }
        $this->como($this->hotel)->get('/importar/estructura/exportar')->assertStatus(429);
    }

    public function test_un_archivo_grande_cabe_y_se_guarda_en_una_sola_corrida(): void
    {
        $renglones = "Hotel Centro,HCEN,Cancún,Quintana Roo,,,,,,,\n";
        foreach (['Torre A', 'Torre B'] as $torre) {
            for ($piso = 1; $piso <= 5; $piso++) {
                for ($h = 1; $h <= 30; $h++) {
                    $renglones .= "Hotel Centro,,,,{$torre},Piso {$piso},".($piso * 100 + $h).",Habitación,Baño,Baño,\n";
                }
            }
        }
        $this->subir($this->hotel, $this->csv(self::ENC."\n".$renglones));
        $i = $this->ultima();
        $this->assertSame(301, $i->total_filas);
        $this->assertSame(0, $i->resumen['error']);
        $this->guardar($this->hotel, $i);
        $this->assertSame([300, 300, 10], [$this->cuantos($this->hotel, Espacio::AREA_ESPECIFICA), $this->cuantos($this->hotel, Espacio::SUBAREA), $this->cuantos($this->hotel, Espacio::AREA)]);
    }

    // ---------------------------------------------------- Todo o nada

    public function test_si_algo_falla_al_guardar_no_se_guarda_nada_y_la_vista_previa_muestra_por_que(): void
    {
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,HCEN,Cancún,Quintana Roo,Torre A,,,,,,\n"));
        $this->guardar($this->hotel, $this->ultima());

        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,,,,Torre B,Piso 1,101,,,,\nHotel Centro,,,,Torre A,Piso 1,101,,,,\n"));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'nueva'], $this->estados($i));
        // Entre la vista previa y Guardar alguien desactiva Torre A: la fila 3 ya no se puede
        Espacio::withoutGlobalScopes()->where('nombre', 'Torre A')->update(['activo' => false]);

        $this->guardar($this->hotel, $i)->assertSessionHasErrors(['archivo' => 'No se guardó nada: 1 fila tiene error (algo cambió desde la vista previa). Revísalas abajo, corrígelas en tu archivo y súbelo de nuevo.']);
        $i->refresh();
        $this->assertSame(Importacion::VISTA_PREVIA, $i->estado);
        $this->assertSame([2 => 'nueva', 3 => 'error'], $this->estados($i));
        $this->assertSame(0, Espacio::withoutGlobalScopes()->where('nombre', 'Torre B')->count(), 'la fila 2 tampoco se guardó');
        $this->assertSame(1, Auditoria::withoutGlobalScopes()->where('evento', 'sedes.importado')->count(), 'solo la importación anterior');
    }

    // ------------------------------------------- Exportar → importar

    public function test_exportar_e_importar_ida_y_vuelta(): void
    {
        $this->subir($this->hotel, $this->csv($this->hotelCompleto()));
        $this->guardar($this->hotel, $this->ultima());
        // Lo desactivado no sale
        $this->subir($this->hotel, $this->csv(self::ENC."\nHotel Centro,,,,Bodega vieja,,,,,,\n"));
        $this->guardar($this->hotel, $this->ultima());
        Espacio::withoutGlobalScopes()->where('nombre', 'Bodega vieja')->update(['activo' => false]);

        $r = $this->como($this->hotel)->get('/importar/estructura/exportar')->assertOk();
        $this->assertStringContainsString('Estructura_Hotel_Uno_', (string) $r->headers->get('Content-Disposition'));
        $ruta = tempnam(sys_get_temp_dir(), 'ex').'.xlsx';
        file_put_contents($ruta, $r->streamedContent());
        $hoja = LectorArchivo::xlsx($ruta);
        $this->assertSame('Sede *', $hoja[1][0]);
        $this->assertNotContains('Bodega vieja', array_merge(...array_values($hoja)));
        $this->assertContains('TA-101', $hoja[array_key_last(array_filter($hoja, fn ($r) => in_array('101', $r, true)))]);

        // En la misma empresa: todo «Ya existe», nada nuevo
        $this->subir($this->hotel, new UploadedFile($ruta, 'Estructura.xlsx', null, null, true));
        $i = $this->ultima();
        $this->assertSame(0, $i->resumen['nueva']);
        $this->assertSame(0, $i->resumen['error']);

        // En otra empresa (vacía): queda la misma estructura
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->subir($otra, new UploadedFile($ruta, 'Estructura.xlsx', null, null, true));
        $j = $this->ultima();
        $this->assertSame(0, $j->resumen['error']);
        $this->guardar($otra, $j);
        $sinBodega = array_values(array_filter($this->rutas($this->hotel), fn ($r) => ! str_contains($r, 'Bodega vieja')));
        $this->assertSame($sinBodega, $this->rutas($otra));
        $this->en($otra, function () {
            $this->assertSame('TA-101', Espacio::where('nombre', '101')->value('codigo'));
            $this->assertSame('Suite nupcial', Espacio::where('nombre', '201')->firstOrFail()->tipo->nombre);
            $this->assertSame(['ciudad' => 'Cancún', 'entidad' => 'Quintana Roo'], Sede::where('codigo', 'HCEN')->firstOrFail()->only(['ciudad', 'entidad']));
        });
        @unlink($ruta);
    }
}
