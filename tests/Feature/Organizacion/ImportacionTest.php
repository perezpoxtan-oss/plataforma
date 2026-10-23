<?php

namespace Tests\Feature\Organizacion;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Importacion;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Importacion\AdministradorImportaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Importacion\LectorArchivo;
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
 * «Importar desde Excel» (docs/tecnico/importacion.md): plantilla, CSV con
 * coma, punto y coma y Windows-1252, XLSX real, vista previa sin guardar,
 * guardar (crea, actualiza u omite), errores por celda, permisos, otra
 * empresa y otra sede, solo consulta, auditoría, archivo guardado y máximo
 * de filas, en los cinco módulos.
 */
class ImportacionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private const ENCABEZADOS = 'Número de empleado *,Nombre(s) *,Apellido paterno *,Apellido materno,Teléfono,Sede,Departamento,Puesto,Turno,CURP,RFC,NSS,Fecha de nacimiento';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    // ------------------------------------------------------------------ Apoyo

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    /**
     * @param  array<string, Alcance>  $permisos
     */
    private function rolCon(string $nombre, array $permisos, int $nivel = 45): Rol
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => $nombre, 'nivel_jerarquia' => $nivel]);
        $roles->sincronizarPermisos($sa, $rol, $permisos);

        return $rol;
    }

    private function usuarioCon(array $permisos, ?Sede $sede = null, string $rol = 'Capturista'): User
    {
        $this->rolCon($rol, $permisos);

        return $this->crearUsuario($this->empresa, $rol, $sede);
    }

    private function csv(string $nombre, string $contenido): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    private function subir(User $usuario, string $tipo, UploadedFile $archivo): TestResponse
    {
        return $this->actingAs($usuario)->post("/importar/{$tipo}", ['archivo' => $archivo]);
    }

    private function ultima(): Importacion
    {
        return Importacion::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** Estado de cada fila de la vista previa (renglón => estado). */
    private function estados(Importacion $i): array
    {
        return collect($i->aplicada() ? $i->resultado : $i->filas)->mapWithKeys(fn ($f) => [$f['fila'] => $f['estado']])->all();
    }

    private function filaDe(Importacion $i, int $renglon): array
    {
        return collect($i->aplicada() ? $i->resultado : $i->filas)->firstWhere('fila', $renglon);
    }

    private function colaborador(string $num): ?Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::where('num_empleado', $num)->first());
    }

    /**
     * Libro de Excel mínimo hecho a mano con ZipArchive: textos compartidos,
     * un texto en línea, un número largo y una fecha con formato de fecha.
     *
     * @param  list<list<array{0: string, 1: string|int|float}>>  $renglones  [tipo (s|n|fecha|inline), valor]
     */
    public static function xlsx(string $ruta, array $renglones): void
    {
        $compartidos = [];
        $xmlFilas = '';
        foreach ($renglones as $r => $celdas) {
            $xmlFilas .= '<row r="'.($r + 1).'">';
            foreach ($celdas as $c => [$tipo, $valor]) {
                $ref = chr(65 + $c).($r + 1);
                $xmlFilas .= match ($tipo) {
                    's' => '<c r="'.$ref.'" t="s"><v>'.(array_push($compartidos, $valor) - 1).'</v></c>',
                    'inline' => '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $valor).'</t></is></c>',
                    'fecha' => '<c r="'.$ref.'" s="1"><v>'.$valor.'</v></c>',
                    default => '<c r="'.$ref.'"><v>'.$valor.'</v></c>',
                };
            }
            $xmlFilas .= '</row>';
        }
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/hoja1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Datos" sheetId="1" r:id="rId7"/></sheets></workbook>');
        // La hoja no se llama sheet1.xml: se encuentra por la relación del libro
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/hoja1.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet '.$ns.'><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $si = implode('', array_map(fn ($t) => '<si><t>'.htmlspecialchars((string) $t).'</t></si>', $compartidos));
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst '.$ns.' count="'.count($compartidos).'">'.$si.'</sst>');
        $zip->addFromString('xl/worksheets/hoja1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet '.$ns.'><sheetData>'.$xmlFilas.'</sheetData></worksheet>');
        $zip->close();
    }

    // ---------------------------------------------------------------- Plantilla

    public function test_plantilla_con_encabezados_en_espanol_y_fila_de_ejemplo_segun_permisos(): void
    {
        $r = $this->actingAs($this->admin)->get('/importar/colaboradores/plantilla')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $r->headers->get('Content-Type'));
        $contenido = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $renglones = LectorArchivo::csv($contenido);
        $this->assertSame(['Número de empleado *', 'Nombre(s) *', 'Apellido paterno *'], array_slice($renglones[1], 0, 3));
        $this->assertContains('CURP', $renglones[1]);
        $this->assertSame('E-1001', $renglones[2][0]);
        $this->assertContains('Sede CEN', $renglones[2], 'el ejemplo usa una sede real');

        // Sin «datos personales» la plantilla no lleva CURP, RFC ni NSS
        $sinDatos = $this->usuarioCon(['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa, 'colaboradores.importar' => Alcance::Empresa]);
        $renglones = LectorArchivo::csv($this->actingAs($sinDatos)->get('/importar/colaboradores/plantilla')->streamedContent());
        $this->assertNotContains('CURP', $renglones[1]);

        foreach (['personas', 'proveedores', 'vehiculos', 'zonas'] as $tipo) {
            $this->actingAs($this->admin)->get("/importar/{$tipo}/plantilla")->assertOk();
            $this->actingAs($this->admin)->get("/importar/{$tipo}")->assertOk()->assertSee('Descargar plantilla')->assertSee('Revisar archivo');
        }
        $this->actingAs($this->admin)->get('/importar/llaves')->assertNotFound();
    }

    public function test_el_boton_importar_sale_en_cada_lista_solo_con_el_permiso(): void
    {
        foreach (['/colaboradores' => 'colaboradores', '/personas' => 'personas', '/proveedores' => 'proveedores', '/vehiculos' => 'vehiculos', '/espacios' => 'zonas'] as $lista => $tipo) {
            $this->actingAs($this->admin)->get($lista)->assertOk()->assertSee(route('importaciones.create', $tipo), false);
        }
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/vehiculos')->assertOk()->assertDontSee(route('importaciones.create', 'vehiculos'), false);
        $this->actingAs($agente)->get('/importar/vehiculos')->assertForbidden();
    }

    // -------------------------------------------- Vista previa, CSV y XLSX

    public function test_csv_con_comas_vista_previa_marca_nuevas_existentes_y_errores_por_celda_sin_guardar_nada(): void
    {
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'E-1', 'nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'apellido_materno' => 'Pech', 'sede_id' => $this->centro->id]));
        $antes = DB::table('colaboradores')->count();
        $auditorias = Auditoria::count();

        $csv = self::ENCABEZADOS."\n"
            ."E-1,Rosa,Uc,,9981112233,Sede CEN,,,,,,,\n"
            ."E-2,Juan,Pérez,López,998 123 4567,CEN,,,,PELJ900101HQRRPN09,,,31/01/1990\n"
            ."E-3,Ana,Díaz,,,Sede Luna,,,,ABC,,,\n"
            ."e-2,Otra,Vez,,,CEN,,,,,,,\n"
            .",,,,,,,,,,,,\n";
        $r = $this->subir($this->admin, 'colaboradores', $this->csv('personal.csv', $csv));
        $i = $this->ultima();
        $r->assertRedirect(route('importaciones.show', $i));

        $this->assertSame(Importacion::VISTA_PREVIA, $i->estado);
        $this->assertSame(4, $i->total_filas, 'el renglón vacío no cuenta');
        $this->assertSame([2 => 'existe', 3 => 'nueva', 4 => 'error', 5 => 'error'], $this->estados($i));
        $this->assertStringContainsString('No existe la sede «Sede Luna»', $this->filaDe($i, 4)['errores']['sede']);
        $this->assertStringContainsString('El CURP no tiene el formato correcto', $this->filaDe($i, 4)['errores']['curp']);
        $this->assertStringContainsString('Repetido en el archivo', $this->filaDe($i, 5)['mensaje']);
        $this->assertTrue($this->filaDe($i, 2)['actualizable']);
        $this->assertSame(['nueva' => 1, 'existe' => 1, 'error' => 2], array_intersect_key($i->resumen, ['nueva' => 0, 'existe' => 0, 'error' => 0]));

        // Nada se guardó todavía: ni colaboradores ni su auditoría
        $this->assertSame($antes, DB::table('colaboradores')->count());
        $this->assertNull($this->colaborador('E-2'));
        $this->assertSame($auditorias, Auditoria::count());
        Storage::disk('local')->assertExists($i->archivo_ruta);

        $this->actingAs($this->admin)->get(route('importaciones.show', $i))->assertOk()
            ->assertSee('Vista previa')->assertSee('Ya existe: Rosa Uc Pech')->assertSee('No existe la sede «Sede Luna»')
            ->assertSee('PELJ900101HQRRPN09')->assertSee('Guardar');
        $this->actingAs($this->admin)->get(route('importaciones.show', ['importacion' => $i, 'ver' => 'errores']))->assertOk()
            ->assertSee('Sede Luna')->assertDontSee('PELJ900101HQRRPN09');
    }

    public function test_csv_con_punto_y_coma_en_windows_1252_respeta_los_acentos(): void
    {
        $csv = mb_convert_encoding("Número de empleado;Nombre(s);Apellido paterno;Apellido materno;Sede\r\nE-10;José;Núñez;Peña;Sede PLA\r\n", 'Windows-1252', 'UTF-8');
        $this->assertFalse(mb_check_encoding($csv, 'UTF-8'));

        $this->subir($this->admin, 'colaboradores', $this->csv('excel.csv', $csv))->assertRedirect();
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva'], $this->estados($i));

        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i))->assertRedirect(route('importaciones.show', $i))
            ->assertSessionHas('ok', fn ($m) => str_contains($m, '1 nuevo'));
        $c = $this->colaborador('E-10');
        $this->assertSame(['José', 'Núñez', 'Peña', $this->playa->id], [$c->nombre, $c->apellido_paterno, $c->apellido_materno, $c->sede_id]);
    }

    public function test_xlsx_real_lee_textos_numeros_y_fechas_de_la_primera_hoja(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        self::xlsx($ruta, [
            [['s', 'Número de empleado'], ['s', 'Nombre(s)'], ['s', 'Apellido paterno'], ['s', 'Teléfono'], ['s', 'Sede'], ['s', 'Fecha de nacimiento'], ['s', 'Columna extra']],
            [['inline', 'E-20'], ['s', 'Lucía'], ['s', 'Canché'], ['n', '9981234567'], ['s', 'Sede CEN'], ['fecha', 32874], ['s', 'x']],
            [['n', 2021], ['s', 'Mario'], ['s', 'Ek'], ['n', '9.98765432E9'], ['s', 'CEN'], ['n', ''], ['s', '']],
        ]);
        $leido = LectorArchivo::leer($ruta, 'xlsx');
        $this->assertSame(['E-20', 'Lucía', 'Canché', '9981234567', 'Sede CEN', '1990-01-01', 'x'], $leido['filas'][0]['celdas']);
        $this->assertSame('9987654320', $leido['filas'][1]['celdas'][3]);

        $archivo = new UploadedFile($ruta, 'personal.xlsx', null, null, true);
        $this->subir($this->admin, 'colaboradores', $archivo)->assertRedirect();
        $i = $this->ultima();
        $this->assertSame('xlsx', $i->formato);
        $this->assertSame([2 => 'nueva', 3 => 'nueva'], $this->estados($i));
        $this->assertSame(['Columna extra'], $i->resumen['ignoradas']);
        $this->actingAs($this->admin)->get(route('importaciones.show', $i))->assertOk()->assertSee('«Columna extra»');

        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i))->assertRedirect();
        $c = $this->colaborador('E-20');
        $this->assertSame(['9981234567', '1990-01-01'], [$c->telefono, $c->fecha_nacimiento?->format('Y-m-d')]);
        $this->assertNotNull($this->colaborador('2021'));
    }

    public function test_archivos_que_no_se_pueden_leer_dicen_por_que_y_no_dejan_nada(): void
    {
        $this->subir($this->admin, 'colaboradores', $this->csv('mal.csv', "Nombre,Teléfono\nAna,1\n"))
            ->assertSessionHasErrors(['archivo' => 'Faltan las columnas «Número de empleado», «Apellido paterno». El renglón 1 debe llevar los encabezados de la plantilla: descárgala y copia tus datos en ella.']);
        $this->subir($this->admin, 'colaboradores', $this->csv('vacio.csv', "Número de empleado,Nombre,Apellido paterno\n"))
            ->assertSessionHasErrors('archivo');
        $this->subir($this->admin, 'colaboradores', $this->csv('roto.xlsx', 'no soy un zip'))
            ->assertSessionHasErrors(['archivo' => 'El archivo no es un libro de Excel (.xlsx) válido. Ábrelo en Excel y guárdalo de nuevo, o guárdalo como «CSV UTF-8».']);
        $this->subir($this->admin, 'colaboradores', UploadedFile::fake()->create('foto.png', 10, 'image/png'))
            ->assertSessionHasErrors('archivo');
        $this->assertSame(0, Importacion::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_maximo_de_filas_por_archivo(): void
    {
        $filas = ['Número de empleado,Nombre,Apellido paterno'];
        for ($n = 1; $n <= AdministradorImportaciones::MAX_FILAS + 1; $n++) {
            $filas[] = "N{$n},Persona,{$n}";
        }
        $this->subir($this->admin, 'colaboradores', $this->csv('mucho.csv', implode("\n", $filas)))
            ->assertSessionHasErrors(['archivo' => 'El archivo trae 2,001 filas y el máximo es 2,000 por archivo. Divídelo en varios archivos y súbelos uno por uno.']);
        $this->assertSame(0, Importacion::withoutGlobalScopes()->count());
    }

    // --------------------------------------------------------------- Guardar

    public function test_guardar_crea_omite_o_actualiza_lo_que_ya_existe_y_no_borra_con_celdas_vacias(): void
    {
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'E-1', 'nombre' => 'Rosa', 'apellido_paterno' => 'Uc', 'apellido_materno' => 'Pech', 'telefono' => '9980000000', 'sede_id' => $this->centro->id]));
        $csv = "Número de empleado,Nombre(s),Apellido paterno,Apellido materno,Teléfono,Sede\nE-1,Rosa María,Uc,,9981112233,\nE-2,Juan,Pérez,,,CEN\nE-3,Sin,Sede,,,Nada\n";

        // Omitir (lo que viene marcado): E-1 queda igual
        $this->subir($this->admin, 'colaboradores', $this->csv('a.csv', $csv));
        $i = $this->ultima();
        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i), ['existentes' => 'omitir'])
            ->assertSessionHas('ok', 'Listo: 1 nuevo, 1 sin cambios (ya existían). 1 fila tiene error y no se guardaron: descarga el resultado, corrígelas y súbelas de nuevo.')
            ->assertSessionHas('siguientes');
        $i->refresh();
        $this->assertSame([2 => 'omitida', 3 => 'creada', 4 => 'error'], $this->estados($i));
        $this->assertSame('Rosa', $this->colaborador('E-1')->nombre);
        $this->assertNotNull($this->colaborador('E-2'));

        // Guardar otra vez la misma importación no hace nada
        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i))->assertSessionHasErrors('archivo');

        // Actualizar: cambia lo que trae el archivo, conserva lo vacío
        $this->subir($this->admin, 'colaboradores', $this->csv('b.csv', $csv));
        $j = $this->ultima();
        $this->assertSame([2 => 'existe', 3 => 'existe', 4 => 'error'], $this->estados($j));
        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $j), ['existentes' => 'actualizar'])->assertSessionHas('ok', fn ($m) => str_contains($m, '2 actualizados'));
        $c = $this->colaborador('E-1');
        $this->assertSame(['Rosa María', 'Pech', '9981112233', $this->centro->id], [$c->nombre, $c->apellido_materno, $c->telefono, $c->sede_id]);

        // La pantalla del resultado y su archivo
        $this->actingAs($this->admin)->get(route('importaciones.show', $j))->assertOk()->assertSee('Así quedó')->assertSee('Actualizada')->assertSee('Descargar resultado');
        $resultado = LectorArchivo::csv($this->actingAs($this->admin)->get(route('importaciones.resultado', $j))->assertOk()->streamedContent());
        $this->assertSame(['Fila', 'Número de empleado'], array_slice($resultado[1], 0, 2));
        $this->assertSame('resultado', $resultado[1][count($resultado[1]) - 2]);
        $this->assertSame('Actualizada', $resultado[2][count($resultado[2]) - 2]);
        $this->assertSame('Con error', $resultado[4][count($resultado[4]) - 2]);
    }

    public function test_un_evento_de_auditoria_por_importacion_y_el_archivo_original_se_descarga_despues(): void
    {
        $csv = "Número de empleado,Nombre(s),Apellido paterno,CURP\nE-5,Ana,Poot,POAA900101MQRTNN01\nE-6,Luis,Can,\n";
        $this->subir($this->admin, 'colaboradores', $this->csv('Plantilla del hotel.csv', $csv));
        $i = $this->ultima();
        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i));

        $evento = Auditoria::where('evento', 'colaboradores.importado')->sole();
        $this->assertSame(Importacion::class, $evento->auditable_type);
        $this->assertSame($i->id, (int) $evento->auditable_id);
        $this->assertSame(['archivo' => 'Plantilla del hotel.csv', 'filas' => 2, 'creadas' => 2, 'actualizadas' => 0, 'omitidas' => 0, 'con_error' => 0, 'ya_existentes' => 'omitir'], $evento->despues);
        // Cada alta deja además la suya, como en la pantalla
        $this->assertSame(2, Auditoria::where('evento', 'colaboradores.creado')->count());
        $this->actingAs($this->admin)->get('/auditoria')->assertOk()->assertSee('Importación desde archivo');

        Storage::disk('local')->assertExists($i->archivo_ruta);
        $this->assertStringStartsWith("importaciones/{$this->empresa->id}/", $i->archivo_ruta);
        $r = $this->actingAs($this->admin)->get(route('importaciones.original', $i))->assertOk();
        $this->assertSame($csv, file_get_contents($r->baseResponse->getFile()->getPathname()));
        $this->actingAs($this->admin)->get(route('importaciones.create', 'colaboradores'))->assertSee('Plantilla del hotel.csv')->assertSee('2 nuevos');

        // Sin datos personales: no ve CURP en la vista previa, no baja el original y el resultado sale enmascarado
        $sinDatos = $this->usuarioCon(['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa, 'colaboradores.importar' => Alcance::Empresa]);
        $this->actingAs($sinDatos)->get(route('importaciones.show', $i))->assertOk()->assertDontSee('POAA900101MQRTNN01')->assertSee('Tu rol no ve datos personales');
        $this->actingAs($sinDatos)->get(route('importaciones.original', $i))->assertForbidden();
        $this->assertStringNotContainsString('POAA900101MQRTNN01', $this->actingAs($sinDatos)->get(route('importaciones.resultado', $i))->streamedContent());
    }

    public function test_sin_datos_personales_las_columnas_de_curp_se_ignoran_al_guardar(): void
    {
        $sinDatos = $this->usuarioCon(['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa, 'colaboradores.importar' => Alcance::Empresa]);
        $this->subir($sinDatos, 'colaboradores', $this->csv('a.csv', "Número de empleado,Nombre,Apellido paterno,CURP\nE-7,Eva,Chan,MAL\n"));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva'], $this->estados($i));
        $this->actingAs($sinDatos)->post(route('importaciones.aplicar', $i));
        $this->assertNull($this->colaborador('E-7')->curp);
    }

    // ------------------------------------------------------- Permisos y alcance

    public function test_permisos_importar_crear_y_editar(): void
    {
        // Sin «importar» no entra a nada
        $soloCrear = $this->usuarioCon(['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa], rol: 'Solo crear');
        $this->actingAs($soloCrear)->get('/importar/colaboradores')->assertForbidden();
        $this->actingAs($soloCrear)->get('/importar/colaboradores/plantilla')->assertForbidden();
        $this->subir($soloCrear, 'colaboradores', $this->csv('a.csv', "Número de empleado,Nombre,Apellido paterno\nE-1,A,B\n"))->assertForbidden();

        $this->subir($this->admin, 'colaboradores', $this->csv('a.csv', "Número de empleado,Nombre,Apellido paterno\nE-1,A,B\n"));
        $i = $this->ultima();
        $this->actingAs($soloCrear)->get(route('importaciones.show', $i))->assertForbidden();
        $this->actingAs($soloCrear)->post(route('importaciones.aplicar', $i))->assertForbidden();
        $this->actingAs($soloCrear)->get(route('importaciones.resultado', $i))->assertForbidden();
        $this->assertNull($this->colaborador('E-1'));

        // Importar sin crear: lo nuevo sale con error; importar sin editar: lo que existe solo se omite
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'E-9', 'nombre' => 'Ya', 'apellido_paterno' => 'Estaba']));
        $sinCrear = $this->usuarioCon(['colaboradores.ver' => Alcance::Empresa, 'colaboradores.editar' => Alcance::Empresa, 'colaboradores.importar' => Alcance::Empresa], rol: 'Sin crear');
        $this->subir($sinCrear, 'colaboradores', $this->csv('b.csv', "Número de empleado,Nombre,Apellido paterno\nE-8,A,B\nE-9,Nuevo,Nombre\n"));
        $j = $this->ultima();
        $this->assertSame('No tienes permiso para dar de alta colaboradores: pide a tu administrador el permiso «Crear».', $this->filaDe($j, 2)['mensaje']);
        $this->assertTrue($this->filaDe($j, 3)['actualizable']);

        $sinEditar = $this->usuarioCon(['colaboradores.ver' => Alcance::Empresa, 'colaboradores.crear' => Alcance::Empresa, 'colaboradores.importar' => Alcance::Empresa], rol: 'Sin editar');
        $this->subir($sinEditar, 'colaboradores', $this->csv('c.csv', "Número de empleado,Nombre,Apellido paterno\nE-9,Nuevo,Nombre\n"));
        $k = $this->ultima();
        $this->assertSame('No tienes permiso para editar: se dejará como está.', $this->filaDe($k, 2)['no_actualiza']);
        $this->actingAs($sinEditar)->get(route('importaciones.show', $k))->assertSee('tu rol no puede editar');
        $this->actingAs($sinEditar)->post(route('importaciones.aplicar', $k), ['existentes' => 'actualizar']);
        $this->assertSame('Ya', $this->colaborador('E-9')->nombre);
        $this->assertSame([2 => 'omitida'], $this->estados($k->fresh()));
    }

    public function test_con_alcance_de_sede_solo_importa_en_sus_sedes(): void
    {
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'P-1', 'nombre' => 'De', 'apellido_paterno' => 'Playa', 'sede_id' => $this->playa->id]));
        $jefe = $this->usuarioCon([
            'colaboradores.ver' => Alcance::Sede, 'colaboradores.crear' => Alcance::Sede, 'colaboradores.editar' => Alcance::Sede, 'colaboradores.importar' => Alcance::Sede,
            'espacios.ver' => Alcance::Sede, 'espacios.crear' => Alcance::Sede, 'espacios.importar' => Alcance::Sede,
        ], $this->centro, 'Capturista de sede');

        $this->subir($jefe, 'colaboradores', $this->csv('a.csv', "Número de empleado,Nombre,Apellido paterno,Sede\nC-1,Uno,Centro,CEN\nC-2,Dos,Playa,PLA\nP-1,De,Playa,\n"));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error', 4 => 'existe'], $this->estados($i));
        $this->assertSame('Elige una de tus sedes: solo puedes registrar colaboradores en ellas.', $this->filaDe($i, 3)['errores']['sede']);
        $this->assertStringContainsString('fuera de tus sedes', $this->filaDe($i, 4)['no_actualiza']);

        $this->subir($jefe, 'zonas', $this->csv('z.csv', "Sede,Zona o edificio,Piso,Habitación\nCEN,Torre A,Piso 1,101\nPLA,Villas,,V1\n"));
        $z = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error'], $this->estados($z));
        $this->assertSame('No puedes crear zonas en «Sede PLA»: solo en tus sedes.', $this->filaDe($z, 3)['errores']['sede']);
    }

    public function test_otra_empresa_no_ve_ni_guarda_importaciones_ajenas(): void
    {
        $this->subir($this->admin, 'colaboradores', $this->csv('a.csv', "Número de empleado,Nombre,Apellido paterno\nE-1,A,B\n"));
        $i = $this->ultima();

        $otra = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($otra, 'DOS');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        foreach (['show', 'original', 'resultado'] as $accion) {
            $this->actingAs($adminOtra)->get(route('importaciones.'.$accion, $i))->assertNotFound();
        }
        $this->actingAs($adminOtra)->post(route('importaciones.aplicar', $i))->assertNotFound();
        $this->actingAs($adminOtra)->get('/importar/colaboradores')->assertOk()->assertDontSee('a.csv');
        $this->assertSame(Importacion::VISTA_PREVIA, $i->fresh()->estado);

        // Sus importaciones se guardan en su empresa, aunque el número de empleado se repita
        $this->subir($adminOtra, 'colaboradores', $this->csv('b.csv', "Número de empleado,Nombre,Apellido paterno\nE-1,Otra,Persona\n"));
        $j = $this->ultima();
        $this->assertSame($otra->id, $j->empresa_id);
        $this->assertSame([2 => 'nueva'], $this->estados($j));
        $this->actingAs($adminOtra)->post(route('importaciones.aplicar', $j));
        $this->assertSame('Otra', $this->enEmpresa(fn () => Colaborador::where('num_empleado', 'E-1')->value('nombre'), $otra));
        $this->assertNull($this->colaborador('E-1'));
    }

    public function test_suscripcion_en_solo_consulta_no_importa_y_no_se_crean_sedes(): void
    {
        DB::table('suscripciones')->insert(['empresa_id' => $this->empresa->id, 'sedes_contratadas' => 2, 'estado' => 'activa', 'suspendida' => true,
            'inicio_en' => now()->subMonth()->toDateString(), 'vence_en' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->get('/importar/colaboradores')->assertOk();
        $this->subir($this->admin, 'colaboradores', $this->csv('a.csv', "Número de empleado,Nombre,Apellido paterno\nE-1,A,B\n"))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'solo consulta'));
        $this->assertSame(0, Importacion::withoutGlobalScopes()->count());

        // Con la suscripción al corriente: una sede que no existe es error de la fila, nunca una sede nueva
        DB::table('suscripciones')->update(['suspendida' => false]);
        $this->flushSession();
        $this->subir($this->admin, 'zonas', $this->csv('z.csv', "Sede,Zona o edificio\nSede Tres,Torre\n"));
        $this->assertSame([2 => 'error'], $this->estados($this->ultima()));
        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $this->ultima()));
        $this->assertSame(2, Sede::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
    }

    // ------------------------------------------------------- Los demás módulos

    public function test_padron_de_personas_por_folio_o_nombre(): void
    {
        $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Carlos Mena', 'tipo_identificacion' => 'ine', 'folio_identificacion' => 'INE123456']));
        $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Limpieza Total', 'categoria' => 'contratista']));
        // QA PER-04: otra parecida (con «S.A. de C.V.») no le gana a la que coincide exacto
        $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Limpieza Total S.A. de C.V.', 'categoria' => 'contratista']));
        $csv = "Tipo,Nombre completo,Empresa que representa,Tipo de identificación,Folio de identificación,Teléfono,Categoría\n"
            ."Visitante,Carlos Mena Uc,,INE,ine-123456,9981234567,\n"
            ."contratista,Pedro Tun,limpieza total,Pasaporte,G1234567,,\n"
            ."Turista,Nadie,,,,,\n"
            .'visitante,Laura Paz,,,,,familiar'."\n";
        $this->subir($this->admin, 'personas', $this->csv('p.csv', $csv));
        $i = $this->ultima();
        $this->assertSame([2 => 'existe', 3 => 'nueva', 4 => 'error', 5 => 'nueva'], $this->estados($i));
        $this->assertStringContainsString('«Turista» no es un tipo de persona válido', $this->filaDe($i, 4)['errores']['tipo']);

        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i), ['existentes' => 'actualizar']);
        $this->enEmpresa(function () {
            $this->assertSame('Carlos Mena Uc', Persona::where('folio_identificacion', 'INE123456')->value('nombre_completo'));
            $pedro = Persona::where('nombre_completo', 'Pedro Tun')->firstOrFail();
            $this->assertSame(['contratista', Proveedor::where('nombre', 'Limpieza Total')->value('id')], [$pedro->tipo, $pedro->proveedor_id]);
            $this->assertSame('familiar', Persona::where('nombre_completo', 'Laura Paz')->value('categoria'));
        });
    }

    public function test_empresas_externas_con_sedes_donde_operan(): void
    {
        $csv = "Razón social o nombre comercial,Categoría,RFC,Teléfono,Sedes donde opera\n"
            ."Transportes del Caribe,Transporte de personal,,9981112233,Sede PLA\n"
            ."Taxis Unidos,taxi,,,Todas\n"
            ."Agencia X,Aerolínea,,,\n"
            ."Hielo Sur,Proveedor,XYZ,,\n";
        $this->subir($this->admin, 'proveedores', $this->csv('e.csv', $csv));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'nueva', 4 => 'error', 5 => 'error'], $this->estados($i));
        $this->assertArrayHasKey('categoria', $this->filaDe($i, 4)['errores']);
        $this->assertStringContainsString('El RFC no tiene un formato válido', $this->filaDe($i, 5)['errores']['rfc']);

        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i));
        $this->enEmpresa(function () {
            $caribe = Proveedor::where('nombre', 'Transportes del Caribe')->firstOrFail();
            $this->assertFalse($caribe->todas_las_sedes);
            $this->assertSame([$this->playa->id], $caribe->sedes()->pluck('sedes.id')->all());
            $this->assertTrue(Proveedor::where('nombre', 'Taxis Unidos')->value('todas_las_sedes'));
        });
    }

    public function test_padron_vehicular_detecta_placas_repetidas_y_liga_empresa_y_dueno(): void
    {
        $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Renta Fácil', 'categoria' => 'agencia_autos']));
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'E-50', 'nombre' => 'Dueño', 'apellido_paterno' => 'Del Auto']));
        $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'YYY111', 'tipo' => 'sedan', 'marca' => 'VW', 'color' => 'ROJO', 'propiedad' => 'particular']));
        $csv = "Placas,Categoría,Tipo,Marca,Modelo,Color,Número económico,Empresa propietaria,Número de empleado del dueño\n"
            ."abc-123,Particular,Sedán,Nissan,Versa,Blanco,,,e-50\n"
            ."ABC 123,Particular,Sedán,Nissan,Versa,Gris,,,\n"
            ."FLT1,De una empresa,Camioneta,Toyota,Hiace,Blanco,U-07,Renta Fácil,\n"
            ."FLT2,De una empresa,SUV,Kia,,Negro,,Nadie SA,\n"
            ."yyy-111,Particular,Sedán,VW,Jetta,Azul,,,\n";
        $this->subir($this->admin, 'vehiculos', $this->csv('v.csv', $csv));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'error', 4 => 'nueva', 5 => 'error', 6 => 'existe'], $this->estados($i));
        $this->assertSame('Repetido en el archivo: es el mismo vehículo de la fila 2.', $this->filaDe($i, 3)['errores']['placas']);
        $this->assertStringContainsString('No existe la empresa «Nadie SA» en Empresas externas', $this->filaDe($i, 5)['errores']['empresa']);
        $this->assertArrayHasKey('numero_economico', $this->filaDe($i, 5)['errores']);

        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i), ['existentes' => 'actualizar']);
        $this->enEmpresa(function () {
            $this->assertSame(Colaborador::where('num_empleado', 'E-50')->value('id'), Vehiculo::where('placas', 'ABC123')->value('colaborador_id'));
            $this->assertSame(['empresa', 'suv', 'U-07'], array_values(Vehiculo::where('placas', 'FLT1')->firstOrFail()->only(['propiedad', 'tipo', 'numero_economico'])));
            $this->assertSame(['JETTA', 'AZUL'], array_values(Vehiculo::where('placas', 'YYY111')->firstOrFail()->only(['modelo', 'color'])));
        });
    }

    public function test_zonas_crea_zona_piso_y_habitacion_reutilizando_lo_que_ya_existe(): void
    {
        $csv = "Sede,Zona o edificio,Piso,Habitación\n"
            ."Sede CEN,Torre A,Piso 1,101\n"
            ."CEN,torre a,piso 1,102\n"
            ."CEN,Torre A,Piso 2,\n"
            ."CEN,Jardín,,Palapa\n"
            ."Sede CEN,Torre A,Piso 1,101\n"
            .",Sin sede,,\n";
        $this->subir($this->admin, 'zonas', $this->csv('z.csv', $csv));
        $i = $this->ultima();
        $this->assertSame([2 => 'nueva', 3 => 'nueva', 4 => 'nueva', 5 => 'nueva', 6 => 'error', 7 => 'error'], $this->estados($i));
        $this->assertSame(0, $this->enEmpresa(fn () => Espacio::count()), 'la vista previa no crea espacios');

        $this->actingAs($this->admin)->post(route('importaciones.aplicar', $i));
        $this->enEmpresa(function () {
            $torre = Espacio::where('nivel', Espacio::EDIFICIO)->where('nombre', 'Torre A')->sole();
            $piso1 = Espacio::where('padre_id', $torre->id)->where('nombre', 'Piso 1')->sole();
            $this->assertSame(['101', '102'], Espacio::where('padre_id', $piso1->id)->orderBy('nombre')->pluck('nombre')->all());
            $this->assertSame(2, Espacio::where('padre_id', $torre->id)->count());
            $jardin = Espacio::where('nombre', 'Jardín')->sole();
            $this->assertSame(Espacio::AREA_ESPECIFICA, Espacio::where('padre_id', $jardin->id)->value('nivel'));
            $h101 = Espacio::where('nombre', '101')->sole();
            $this->assertSame("/{$torre->id}/{$piso1->id}/{$h101->id}/", $h101->ruta);
        });

        // Lo que ya existe completo se omite
        $this->subir($this->admin, 'zonas', $this->csv('z2.csv', "Sede,Zona o edificio,Piso,Habitación\nCEN,Torre A,Piso 1,101\nCEN,Torre A,Piso 1,103\n"));
        $j = $this->ultima();
        $this->assertSame([2 => 'existe', 3 => 'nueva'], $this->estados($j));
        $this->assertSame('Ya existe: Torre A › Piso 1 › 101.', $this->filaDe($j, 2)['mensaje']);
        $this->actingAs($this->admin)->get(route('importaciones.show', $j))->assertOk()->assertDontSee('Actualizar sus datos');
        $this->assertSame(Auditoria::where('evento', 'espacios.importado')->count(), 1);
    }
}
