<?php

namespace Tests\Feature\Direccion;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\Respaldos\Respaldos;
use App\Services\Traslado\ExportacionEmpresa;
use App\Services\Traslado\ImportadorSql;
use App\Services\Traslado\Paquetes;
use App\Services\Traslado\VerificadorPaquete;
use App\Services\Traslado\ZipPorPartes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;
use ZipArchive;

/**
 * Respaldos y traslado a otro servidor + exportación de una empresa
 * (docs/tecnico/traslado.md). Cada prueba usa un storage propio en una
 * carpeta temporal: nada toca el storage real.
 */
class TrasladoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private string $temporal;

    private Empresa $empresa;

    private Empresa $otra;

    private Sede $centro;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporal = sys_get_temp_dir().'/traslado_prueba_'.bin2hex(random_bytes(5));
        $this->usarStorage($this->temporal.'/origen');

        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Centro');
        $this->otra = $this->crearEmpresa('Hotel Playa');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->crearSede($this->otra, 'PLA');
        $this->sa = $this->crearSuperadmin();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporal);
        @unlink($this->temporal.'_destino.sqlite');
        parent::tearDown();
    }

    // ------------------------------------------------------------ de punta a punta

    public function test_genera_el_paquete_y_lo_restaura_en_base_y_storage_vacios(): void
    {
        $archivos = $this->sembrarDatosYArchivos();
        DB::table('sessions')->insert(['id' => 'SESION-SECRETA-1', 'user_id' => $this->sa->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($this->sa)->post('/configuracion/traslado')->assertRedirect('/configuracion/traslado');
        $paquete = $this->terminarYTomar();

        // Contenido: manifest, LEEME, base y todos los archivos (sin respaldos ni paquetes)
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($paquete['ruta']) === true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('traslado', $manifest['tipo']);
        $this->assertSame(Paquetes::FORMATO, $manifest['formato']);
        $this->assertNotEmpty($manifest['plataforma']['ultima_migracion']);
        $this->assertSame(DB::table('migrations')->count(), $manifest['plataforma']['migraciones']);
        $this->assertSame(DB::table('users')->count(), $manifest['base']['tablas']['users']);
        $this->assertSame(0, $manifest['base']['tablas']['sessions']);
        $this->assertNotFalse($zip->getFromName('LEEME.txt'));
        $this->assertStringNotContainsString('SESION-SECRETA-1', gzdecode($zip->getFromName('base/base.sql.gz')));
        $rutas = array_column($manifest['archivos'], 'ruta');
        foreach ($archivos as $relativa => $contenido) {
            $this->assertContains("archivos/{$relativa}", $rutas);
            $this->assertSame($contenido, $zip->getFromName("archivos/{$relativa}"));
            $entrada = collect($manifest['archivos'])->firstWhere('ruta', "archivos/{$relativa}");
            $this->assertSame(hash('sha256', $contenido), $entrada['sha256']);
        }
        foreach ($rutas as $r) {
            $this->assertStringNotContainsString('respaldos/', $r);
            $this->assertStringNotContainsString('traslados/', $r);
        }
        $zip->close();
        // El respaldo de la base no se duplica en Respaldos
        $this->assertSame([], glob(storage_path('app/private/respaldos/*traslado*')) ?: []);
        $this->assertTrue(Auditoria::where('evento', 'configuracion.traslado_generado')->where('user_id', $this->sa->id)->exists());
        $this->assertSame('600', substr(sprintf('%o', fileperms($paquete['ruta'])), -3));

        // Verificación sin restaurar
        $this->assertTrue(app(VerificadorPaquete::class)->verificar($paquete['ruta'])['valido']);

        // Restauración: storage vacío y base vacía
        $conteosOrigen = collect($manifest['base']['tablas']);
        $textoRaro = DB::table('sedes')->where('id', $this->centro->id)->value('nombre');
        $copia = $this->temporal.'/paquete.zip';
        copy($paquete['ruta'], $copia);
        $this->usarStorage($this->temporal.'/destino');
        $this->conexionDestino();

        $codigo = Artisan::call('plataforma:restaurar-traslado', ['paquete' => $copia, '--importar-base' => true, '--forzar' => true, '--conexion' => 'destino']);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('todas las huellas coinciden', $salida);
        $this->assertStringContainsString('mismos registros', $salida);

        $destino = DB::connection('destino');
        foreach ($conteosOrigen as $tabla => $n) {
            $esperado = $tabla === 'auditoria' ? $n + 1 : $n; // + la restauración, que queda en la bitácora
            $this->assertSame($esperado, $destino->table($tabla)->count(), "Tabla {$tabla}");
        }
        $this->assertSame($textoRaro, $destino->table('sedes')->where('id', $this->centro->id)->value('nombre'));
        $this->assertTrue($destino->table('auditoria')->where('evento', 'configuracion.traslado_restaurado')->exists());
        foreach ($archivos as $relativa => $contenido) {
            [$disco, $ruta] = explode('/', $relativa, 2);
            $this->assertSame($contenido, file_get_contents(storage_path("app/{$disco}/{$ruta}")), $relativa);
        }
        // El .sql.gz extraído se borra al importar
        $this->assertSame([], glob(storage_path('app/private/traslados/restaurar/*.sql.gz')) ?: []);
    }

    public function test_las_columnas_generadas_no_se_vuelcan_y_la_base_restaurada_las_recalcula(): void
    {
        // Todas las columnas generadas del esquema (si aparece otra, esta prueba avisa)
        $generadas = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
            foreach (Respaldos::columnasGeneradas($t->name) as $c) {
                $generadas[] = "{$t->name}.{$c}";
            }
        }
        $this->assertEqualsCanonicalizing(['prestamos_llaves.llave_en_uso', 'equipos_responsiva.equipo_en_campo'], $generadas);

        $ahora = now();
        $base = ['empresa_id' => $this->empresa->id, 'created_at' => $ahora, 'updated_at' => $ahora];
        $colaborador = DB::table('colaboradores')->insertGetId($base + ['nombre' => 'Raúl', 'apellido_paterno' => 'Can']);
        $llaves = [];
        foreach ([1, 2] as $i) {
            $llaves[$i] = DB::table('llaves')->insertGetId($base + ['sede_id' => $this->centro->id, 'nomenclatura' => "LL-{$i}", 'descripcion' => "Llave {$i}",
                'tipo_dispositivo' => 'llave', 'alcance' => 'zona', 'codigo_qr' => "qrgenerada{$i}"]);
        }
        $prestamo = ['sede_id' => $this->centro->id, 'colaborador_id' => $colaborador, 'tipo_garantia' => 'ninguna', 'prestado_en' => $ahora];
        DB::table('prestamos_llaves')->insert([
            $base + $prestamo + ['llave_id' => $llaves[1], 'estado' => 'en_uso', 'devuelto_en' => null],
            $base + $prestamo + ['llave_id' => $llaves[2], 'estado' => 'devuelta', 'devuelto_en' => $ahora],
        ]);
        $tipo = DB::table('tipos_equipo')->insertGetId($base + ['nombre' => 'Radio']);
        $equipo = DB::table('equipos')->insertGetId($base + ['sede_id' => $this->centro->id, 'tipo_equipo_id' => $tipo, 'numero_serie' => 'R-1', 'codigo_qr' => 'qrgeneradaeq']);
        $responsiva = DB::table('responsivas')->insertGetId($base + ['sede_id' => $this->centro->id, 'colaborador_id' => $colaborador, 'numero' => 1,
            'folio' => 'CENRES-000001', 'firma_ruta' => 'firmas/r.png', 'entregado_en' => $ahora]);
        DB::table('equipos_responsiva')->insert($base + ['responsiva_id' => $responsiva, 'equipo_id' => $equipo, 'modalidad' => 'prestado']);

        // El respaldo no escribe las columnas generadas en los INSERT
        $respaldo = app(Respaldos::class)->crear('manual');
        $sql = gzdecode(file_get_contents($respaldo['ruta']));
        foreach (['prestamos_llaves' => 'llave_en_uso', 'equipos_responsiva' => 'equipo_en_campo'] as $tabla => $columna) {
            $this->assertSame(1, preg_match_all('/INSERT INTO "'.$tabla.'" \(([^)]*)\)/', $sql, $m), "{$tabla} debe tener sus filas");
            $this->assertStringNotContainsString($columna, $m[1][0], "{$tabla}.{$columna} no debe ir en el INSERT");
        }

        // Paquete de traslado → restauración en base vacía: importa y la base recalcula
        $this->actingAs($this->sa)->post('/configuracion/traslado');
        $paquete = $this->terminarYTomar();
        $copia = $this->temporal.'/paquete.zip';
        copy($paquete['ruta'], $copia);
        $this->usarStorage($this->temporal.'/destino');
        $this->conexionDestino();

        $codigo = Artisan::call('plataforma:restaurar-traslado', ['paquete' => $copia, '--importar-base' => true, '--forzar' => true, '--conexion' => 'destino']);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('mismos registros', $salida);
        $destino = DB::connection('destino');
        $this->assertSame([$llaves[1]], $destino->table('prestamos_llaves')->whereNotNull('llave_en_uso')->pluck('llave_en_uso')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($equipo, (int) $destino->table('equipos_responsiva')->value('equipo_en_campo'));
        // La regla «un préstamo vigente por llave» sigue viva en la base restaurada
        $this->expectException(QueryException::class);
        $destino->table('prestamos_llaves')->insert($base + $prestamo + ['llave_id' => $llaves[1], 'estado' => 'en_uso']);
    }

    public function test_un_respaldo_anterior_con_columnas_generadas_tambien_se_importa(): void
    {
        $this->conexionDestino();
        $sql = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n"
            .'CREATE TABLE "prueba_gen" ("id" integer primary key autoincrement not null, "estado" varchar not null, "nota" varchar, "llave_id" integer, '
            ."\"llave_en_uso\" integer as (CASE WHEN estado = 'en_uso' THEN llave_id ELSE NULL END) stored);\n"
            .'INSERT INTO "prueba_gen" ("id", "estado", "nota", "llave_id", "llave_en_uso") VALUES'."\n"
            ."(1, 'en_uso', 'dice, (algo) ''raro''', 7, 7),\n(2, 'devuelta', NULL, 8, NULL);\nCOMMIT;\n";
        File::ensureDirectoryExists($this->temporal);
        $ruta = $this->temporal.'/anterior.sql.gz';
        file_put_contents($ruta, gzencode($sql));

        $n = (new ImportadorSql(7))->importar($ruta, DB::connection('destino'));

        $this->assertSame(5, $n);
        $filas = DB::connection('destino')->table('prueba_gen')->orderBy('id')->get();
        $this->assertSame("dice, (algo) 'raro'", $filas[0]->nota);
        $this->assertSame(7, (int) $filas[0]->llave_en_uso);
        $this->assertNull($filas[1]->llave_en_uso);
    }

    public function test_restaurar_sin_importar_explica_como_y_no_toca_una_base_con_datos(): void
    {
        $this->sembrarDatosYArchivos();
        $t = app(Paquetes::class)->iniciarTraslado($this->sa);
        $paquete = app(Paquetes::class)->completar($t['id'])['paquete'];
        $ruta = app(Paquetes::class)->ruta($paquete);

        // Sobre la base actual (con datos) no importa
        $codigo = Artisan::call('plataforma:restaurar-traslado', ['paquete' => $ruta, '--importar-base' => true, '--forzar' => true]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('NO está vacía', Artisan::output());

        // Sin --importar-base: copia archivos y explica cómo importar
        $this->usarStorage($this->temporal.'/otro');
        $codigo = Artisan::call('plataforma:restaurar-traslado', ['paquete' => $ruta]);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('--importar-base', $salida);
        $this->assertCount(1, glob(storage_path('app/private/traslados/restaurar/*.sql.gz')));
        $this->assertFileExists(storage_path('app/public/identidad/logo_centro.png'));

        // Un archivo distinto que ya existe no se pisa sin --sobrescribir
        file_put_contents(storage_path('app/public/identidad/logo_centro.png'), 'otro');
        Artisan::call('plataforma:restaurar-traslado', ['paquete' => $ruta]);
        $this->assertStringContainsString('NO se tocaron', Artisan::output());
        $this->assertSame('otro', file_get_contents(storage_path('app/public/identidad/logo_centro.png')));
        Artisan::call('plataforma:restaurar-traslado', ['paquete' => $ruta, '--sobrescribir' => true]);
        $this->assertSame('PNG-CENTRO', file_get_contents(storage_path('app/public/identidad/logo_centro.png')));
    }

    public function test_el_comando_rechaza_un_paquete_alterado_y_uno_de_empresa(): void
    {
        $this->sembrarDatosYArchivos();
        $p = app(Paquetes::class);
        $ruta = $p->ruta($p->completar($p->iniciarTraslado($this->sa)['id'])['paquete']);
        $alterado = $this->alterar($ruta);

        $this->assertSame(1, Artisan::call('plataforma:restaurar-traslado', ['paquete' => $alterado]));
        $this->assertStringContainsString('no coincide con su huella', Artisan::output());
        $this->assertFileDoesNotExist($this->temporal.'/restaurado-no');

        $empresa = $p->ruta($p->completar($p->iniciarEmpresa($this->sa, $this->empresa)['id'])['paquete']);
        $this->assertSame(1, Artisan::call('plataforma:restaurar-traslado', ['paquete' => $empresa]));
        $this->assertStringContainsString('exportación de una empresa', Artisan::output());
    }

    // ------------------------------------------------------------ por partes

    public function test_el_paquete_se_arma_por_partes_y_reanuda_si_una_parte_se_corta(): void
    {
        $this->sembrarDatosYArchivos();
        for ($i = 0; $i < 25; $i++) {
            $this->escribir("private/evidencias/{$i}/foto_{$i}.jpg", str_repeat("dato {$i} ", 500));
        }
        $p = app(Paquetes::class);
        $t = $p->iniciarTraslado($this->sa);

        $partes = 0;
        do {
            $r = $p->avanzar($t['id'], 0.000001);
            $partes++;
            if ($partes === 5) {
                // Simula una petición que el hospedaje cortó a la mitad: basura después del último punto confirmado
                $estado = $p->trabajo($t['id']);
                file_put_contents($p->carpeta().'/'.$estado['parcial'], str_repeat('X', 4096), FILE_APPEND);
                file_put_contents($p->carpeta('trabajos')."/{$t['id']}.json.paso", '9');
            }
            $this->assertLessThan(200, $partes);
        } while ($r['estado'] === 'en_proceso');

        $this->assertSame('listo', $r['estado'], (string) $r['error']);
        $this->assertGreaterThan(20, $partes);
        $ruta = $p->ruta($r['paquete']);
        $v = app(VerificadorPaquete::class)->verificar($ruta);
        $this->assertTrue($v['valido'], implode(' | ', $v['problemas']));
        $this->assertGreaterThanOrEqual(28, $v['revisados']);
        // El servicio de ZIP de PHP también lo abre completo
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta, ZipArchive::CHECKCONS) === true);
        $zip->close();
    }

    public function test_desde_la_pantalla_una_parte_que_se_corta_varias_veces_queda_para_la_tarea_automatica(): void
    {
        $p = app(Paquetes::class);
        $t = $p->iniciarTraslado($this->sa);
        $marca = $p->carpeta('trabajos')."/{$t['id']}.json.paso";
        // Simula partes que el hospedaje cortó: la marca queda y el intento se cuenta en cuanto se detecta
        for ($i = 1; $i <= Paquetes::INTENTOS_WEB; $i++) {
            $estado = json_decode(file_get_contents($p->carpeta('trabajos')."/{$t['id']}.json"), true);
            $this->assertSame($i - 1, $estado['intentos']);
            file_put_contents($marca, '0');
            $estado['intentos'] = $i;
            file_put_contents($p->carpeta('trabajos')."/{$t['id']}.json", json_encode($estado));
        }
        file_put_contents($marca, '0');
        $r = $p->avanzar($t['id'], 0.000001);
        $this->assertSame('en_proceso', $r['estado']);
        $this->assertStringContainsString('tarea automática', (string) $r['aviso']);

        // La tarea programada (consola) sí la termina
        $this->assertSame(0, Artisan::call('plataforma:traslado', ['--si-toca' => true]));
        $this->assertSame('listo', $p->trabajo($t['id'])['estado']);
    }

    public function test_la_pantalla_pide_la_siguiente_parte_en_json(): void
    {
        $p = app(Paquetes::class);
        $t = $p->iniciarTraslado($this->sa);

        $this->actingAs($this->sa)->postJson("/configuracion/traslado/trabajos/{$t['id']}")
            ->assertOk()->assertJsonStructure(['estado', 'avance', 'paso', 'tamano']);
        $this->actingAs($this->sa)->get('/configuracion/traslado')->assertOk()->assertSee('Generar paquete de traslado', false);

        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->postJson("/configuracion/traslado/trabajos/{$t['id']}")->assertForbidden();
        $this->actingAs($this->sa)->postJson('/configuracion/traslado/trabajos/0123456789abcdef')->assertNotFound();
    }

    // ------------------------------------------------------------ seguridad

    public function test_solo_el_superadmin_entra_genera_y_descarga_con_enlace_firmado_y_temporal(): void
    {
        $p = app(Paquetes::class);
        $archivo = $p->completar($p->iniciarTraslado($this->sa)['id'])['paquete'];
        $firmado = URL::temporarySignedRoute('traslado.descargar', now()->addMinutes(30), ['archivo' => $archivo]);

        $this->get('/configuracion/traslado')->assertRedirect('/login');
        $this->get($firmado)->assertRedirect('/login');
        foreach (['Administrador', 'Director', 'Agente'] as $rol) {
            $u = $this->crearUsuario($this->empresa, $rol, $rol === 'Agente' ? $this->centro : null);
            $this->actingAs($u)->get('/configuracion/traslado')->assertForbidden();
            $this->actingAs($u)->post('/configuracion/traslado')->assertForbidden();
            $this->actingAs($u)->get($firmado)->assertForbidden();
            $this->actingAs($u)->delete("/configuracion/traslado/{$archivo}")->assertForbidden();
        }

        // Sin firma, con firma vencida o con nombre manipulado: no
        $this->actingAs($this->sa)->get("/configuracion/traslado/descargar/{$archivo}")->assertForbidden();
        $vencido = URL::temporarySignedRoute('traslado.descargar', now()->subMinute(), ['archivo' => $archivo]);
        $this->actingAs($this->sa)->get($vencido)->assertForbidden();
        foreach (['..%2F..%2F.env', '.env', 'traslado_x_20260101_000000.zip%00.txt'] as $malo) {
            $this->actingAs($this->sa)->get(URL::temporarySignedRoute('traslado.descargar', now()->addMinutes(5), ['archivo' => $malo]))->assertNotFound();
        }

        $this->actingAs($this->sa)->get(URL::temporarySignedRoute('traslado.descargar', now()->addMinutes(5), ['archivo' => $archivo]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue(Auditoria::where('evento', 'configuracion.traslado_descargado')->where('user_id', $this->sa->id)->exists());

        // Nunca en la carpeta pública ni servido por /storage
        $this->assertStringStartsWith(storage_path('app/private/'), $p->ruta($archivo));
        $this->actingAs($this->sa)->get("/storage/traslados/{$archivo}")->assertNotFound();
    }

    public function test_se_conservan_los_tres_mas_recientes_y_se_pueden_borrar(): void
    {
        $p = app(Paquetes::class);
        for ($i = 0; $i < 4; $i++) {
            $p->completar($p->iniciarTraslado($this->sa)['id']);
            $this->travel(1)->minutes();
        }
        $lista = $p->listar();
        $this->assertCount(Paquetes::CONSERVAR, $lista);

        $archivo = $lista->last()['archivo'];
        $this->actingAs($this->sa)->delete("/configuracion/traslado/{$archivo}")->assertRedirect('/configuracion/traslado');
        $this->assertNull($p->ruta($archivo));
        $this->assertFileDoesNotExist($p->carpeta()."/{$archivo}.json");
        $this->assertTrue(Auditoria::where('evento', 'configuracion.traslado_borrado')->exists());
    }

    // ------------------------------------------------------------ verificar

    public function test_verificar_paquete_subido_o_de_la_lista_sin_restaurar(): void
    {
        $this->sembrarDatosYArchivos();
        $p = app(Paquetes::class);
        $archivo = $p->completar($p->iniciarTraslado($this->sa)['id'])['paquete'];
        $ruta = $p->ruta($archivo);

        // De la lista
        $this->actingAs($this->sa)->post('/configuracion/traslado/verificar', ['archivo' => $archivo])
            ->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, 'está completo'));
        $this->assertFileExists($ruta); // el de la lista no se borra

        // Subido y alterado: se detecta y el archivo subido se borra
        $alterado = $this->alterar($ruta);
        $this->actingAs($this->sa)->post('/configuracion/traslado/verificar', ['paquete' => new UploadedFile($alterado, 'copia.zip', 'application/zip', null, true)])
            ->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, 'tiene problemas'));
        $this->assertSame([], glob($p->carpeta('verificar').'/*') ?: []);
        $this->actingAs($this->sa)->get('/configuracion/traslado')->assertOk()->assertSee('no coincide con su huella', false);

        // Un ZIP cualquiera no es un paquete
        $ajeno = $this->temporal.'/ajeno.zip';
        $z = new ZipArchive;
        $z->open($ajeno, ZipArchive::CREATE);
        $z->addFromString('hola.txt', 'hola');
        $z->close();
        $this->actingAs($this->sa)->post('/configuracion/traslado/verificar', ['paquete' => new UploadedFile($ajeno, 'ajeno.zip', 'application/zip', null, true)]);
        $this->actingAs($this->sa)->get('/configuracion/traslado')->assertSee('no trae manifest.json', false);

        $this->assertSame(3, Auditoria::where('evento', 'configuracion.traslado_verificado')->count());
        $this->assertSame(1, Auditoria::where('evento', 'configuracion.traslado_verificado')->get()->filter(fn ($a) => $a->despues['valido'] === true)->count());

        // Solo el Super Administrador
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->post('/configuracion/traslado/verificar', ['archivo' => $archivo])->assertForbidden();
    }

    public function test_nombres_peligrosos_dentro_de_un_paquete_se_rechazan(): void
    {
        $this->assertFalse(ZipPorPartes::nombreSeguro('../.env'));
        $this->assertFalse(ZipPorPartes::nombreSeguro('archivos/private/../../.env'));
        $this->assertFalse(ZipPorPartes::nombreSeguro('/etc/passwd'));
        $this->assertFalse(ZipPorPartes::nombreSeguro('a\\b'));
        $this->assertTrue(ZipPorPartes::nombreSeguro('archivos/private/firmas/1/firma.png'));

        $ruta = $this->temporal.'/malo.zip';
        File::ensureDirectoryExists($this->temporal);
        $z = new ZipArchive;
        $z->open($ruta, ZipArchive::CREATE);
        $z->addFromString('manifest.json', json_encode(['formato' => Paquetes::FORMATO, 'version_formato' => 1, 'tipo' => 'traslado',
            'archivos' => [['ruta' => '../../fuera.txt', 'bytes' => 1, 'sha256' => hash('sha256', 'x')]]]));
        $z->addFromString('../../fuera.txt', 'x');
        $z->close();
        $r = app(VerificadorPaquete::class)->verificar($ruta);
        $this->assertFalse($r['valido']);
        $this->assertSame(1, Artisan::call('plataforma:restaurar-traslado', ['paquete' => $ruta]));
        $this->assertFileDoesNotExist(dirname(storage_path()).'/fuera.txt');
    }

    // ------------------------------------------------------------ semanal

    public function test_paquete_semanal_automatico_solo_si_esta_encendido(): void
    {
        $this->travelTo(now()->setTimezone('America/Mexico_City')->setTime(4, 0)->utc());
        $this->assertSame(0, Artisan::call('plataforma:traslado', ['--si-toca' => true]));
        $this->assertCount(0, app(Paquetes::class)->listar());

        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->put('/configuracion/traslado/ajustes', ['semanal' => '1'])->assertForbidden();
        $this->actingAs($this->sa)->put('/configuracion/traslado/ajustes', ['semanal' => '1'])->assertRedirect();
        $this->assertTrue(app(Paquetes::class)->ajustes()['semanal']);
        $this->assertSame(0, Artisan::call('plataforma:traslado', ['--si-toca' => true]));
        $lista = app(Paquetes::class)->listar();
        $this->assertCount(1, $lista);
        $this->assertSame('semanal', $lista->first()['datos']['motivo']);
        $this->assertTrue(Auditoria::where('evento', 'configuracion.traslado_generado')->whereNull('user_id')->exists());

        // Al día siguiente no toca; a los 7 días sí
        $this->travel(1)->days();
        Artisan::call('plataforma:traslado', ['--si-toca' => true]);
        $this->assertCount(1, app(Paquetes::class)->listar());
        $this->travel(7)->days();
        Artisan::call('plataforma:traslado', ['--si-toca' => true]);
        $this->assertCount(2, app(Paquetes::class)->listar());

    }

    // ------------------------------------------------------------ exportar una empresa

    public function test_exporta_todo_lo_de_una_empresa_sin_contrasenas_ni_datos_de_otra(): void
    {
        $archivos = $this->sembrarDatosYArchivos();
        $this->actingAs($this->sa)->post("/empresas/{$this->empresa->id}/exportar")->assertRedirect("/empresas/{$this->empresa->id}/exportaciones");
        $p = app(Paquetes::class);
        foreach ($p->trabajos('empresa', $this->empresa->id)->where('estado', 'en_proceso') as $t) {
            $p->completar($t['id']);
        }
        $paquete = $p->listar($this->empresa->id)->first();
        $this->assertNotNull($paquete);
        $this->assertCount(0, $p->listar()); // no se mezcla con los de traslado

        $zip = new ZipArchive;
        $zip->open($paquete['ruta']);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('empresa', $manifest['tipo']);
        $this->assertSame('Hotel Centro', $manifest['empresa']['nombre_comercial']);

        $empresas = $this->leerJson($zip, 'datos/empresas.json');
        $this->assertCount(1, $empresas);
        $this->assertSame($this->empresa->id, $empresas[0]['id']);

        $usuarios = $this->leerJson($zip, 'datos/users.json');
        $this->assertNotEmpty($usuarios);
        foreach ($usuarios as $u) {
            $this->assertSame($this->empresa->id, $u['empresa_id']);
            $this->assertArrayNotHasKey('password', $u);
            $this->assertArrayNotHasKey('remember_token', $u);
        }
        $this->assertSame(['CEN'], array_column($this->leerJson($zip, 'datos/sedes.json'), 'codigo'));
        $roles = array_column($this->leerJson($zip, 'datos/roles.json'), 'id');
        foreach ($this->leerJson($zip, 'datos/rol_permisos.json') as $rp) {
            $this->assertContains($rp['rol_id'], $roles);
        }
        $this->assertNotEmpty($this->leerJson($zip, 'datos/rol_permisos.json'));
        foreach ($this->leerJson($zip, 'datos/usuario_roles.json') as $ur) {
            $this->assertContains($ur['user_id'], array_column($usuarios, 'id'));
        }
        // La bitácora de la empresa viaja completa (menos el aviso de esta misma exportación, que se escribe al terminar)
        $this->assertSame(DB::table('auditoria')->where('empresa_id', $this->empresa->id)->where('evento', '!=', 'empresas.exportada')->count(), $manifest['tablas']['auditoria']);
        $this->assertFalse($zip->getFromName('datos/enlaces_kiosco.json'));
        $this->assertFalse($zip->getFromName('datos/sessions.json'));
        $this->assertFalse($zip->getFromName('datos/migrations.json'));

        // Sus archivos sí; los de la otra empresa no
        $this->assertSame($archivos['private/firmas/centro/firma.png'], $zip->getFromName('archivos/private/firmas/centro/firma.png'));
        $this->assertSame($archivos['public/identidad/logo_centro.png'], $zip->getFromName('archivos/public/identidad/logo_centro.png'));
        $this->assertFalse($zip->getFromName('archivos/private/firmas/playa/firma.png'));
        $this->assertFalse($zip->getFromName('archivos/public/identidad/logo_playa.png'));

        // Hojas legibles
        foreach (['sedes', 'colaboradores', 'usuarios', 'padron_personas', 'padron_vehiculos', 'padron_empresas_externas', 'configuracion'] as $hoja) {
            $this->assertNotFalse($zip->getFromName("hojas/{$hoja}.csv"), $hoja);
        }
        $usuariosCsv = $zip->getFromName('hojas/usuarios.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $usuariosCsv);
        $this->assertStringNotContainsString('password', $usuariosCsv);
        $this->assertStringNotContainsString('Hotel Playa', $zip->getFromName('hojas/padron_personas.csv'));
        $this->assertStringContainsString("'=HYPERLINK", $zip->getFromName('hojas/padron_personas.csv'));
        $this->assertStringContainsString('empresa.nombre_comercial', $zip->getFromName('hojas/configuracion.csv'));
        $zip->close();

        // Cada archivo con su huella
        $this->assertTrue(app(VerificadorPaquete::class)->verificar($paquete['ruta'])['valido']);
        $this->assertTrue(Auditoria::where('evento', 'empresas.exportada')->where('auditable_id', $this->empresa->id)->exists());
    }

    public function test_el_administrador_exporta_solo_su_empresa_si_tiene_el_permiso(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $director = $this->crearUsuario($this->empresa, 'Director');
        $adminOtra = $this->crearUsuario($this->otra, 'Administrador');

        // El Administrador lo tiene de origen; el Director no
        $this->actingAs($admin)->get('/empresas')->assertOk()->assertSee("/empresas/{$this->empresa->id}/exportaciones", false);
        $this->actingAs($director)->get('/empresas')->assertOk()->assertDontSee("/empresas/{$this->empresa->id}/exportaciones", false);
        $this->actingAs($director)->get("/empresas/{$this->empresa->id}/exportaciones")->assertForbidden();
        $this->actingAs($director)->post("/empresas/{$this->empresa->id}/exportar")->assertForbidden();

        $this->actingAs($admin)->get("/empresas/{$this->empresa->id}/exportaciones")->assertOk()->assertSee('Exportar datos', false);
        $this->actingAs($admin)->get("/empresas/{$this->otra->id}/exportaciones")->assertNotFound();
        $this->actingAs($admin)->post("/empresas/{$this->otra->id}/exportar")->assertNotFound();
        $this->actingAs($admin)->post("/empresas/{$this->empresa->id}/exportar")->assertRedirect();

        $p = app(Paquetes::class);
        foreach ($p->trabajos('empresa')->where('estado', 'en_proceso') as $t) {
            $p->completar($t['id']);
        }
        $archivo = $p->listar($this->empresa->id)->first()['archivo'];
        $enlace = URL::temporarySignedRoute('traslado.descargar', now()->addMinutes(5), ['archivo' => $archivo]);
        $this->actingAs($adminOtra)->get($enlace)->assertNotFound();
        $this->actingAs($director)->get($enlace)->assertForbidden();
        $this->actingAs($admin)->get($enlace)->assertOk();
        $this->assertTrue(Auditoria::where('evento', 'empresas.exportacion_descargada')->where('user_id', $admin->id)->exists());
        // Pero no la pantalla de traslado de la plataforma
        $this->actingAs($admin)->get('/configuracion/traslado')->assertForbidden();

        // Si le quitan el permiso, ya no
        $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'empresas'))->whereHas('accion', fn ($q) => $q->where('clave', 'exportar'))->firstOrFail();
        RolPermiso::where('modulo_accion_id', $ma->id)->where('rol_id', Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Administrador')->value('id'))->delete();
        $this->app->forgetInstance(Autorizador::class);
        $this->actingAs($admin->fresh())->get("/empresas/{$this->empresa->id}/exportaciones")->assertForbidden();

        $this->actingAs($admin->fresh())->delete("/configuracion/traslado/{$archivo}")->assertForbidden();
        $this->actingAs($this->sa)->delete("/configuracion/traslado/{$archivo}")->assertRedirect("/empresas/{$this->empresa->id}/exportaciones");
        $this->assertTrue(Auditoria::where('evento', 'empresas.exportacion_borrada')->exists());
    }

    public function test_migracion_del_permiso_exportar_empresa(): void
    {
        $migracion = require database_path('migrations/2026_11_02_000400_permiso_exportar_empresa.php');
        $clave = fn () => ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'empresas'))->whereHas('accion', fn ($q) => $q->where('clave', 'exportar'))->first();
        $this->assertNotNull($clave());

        $migracion->down();
        $this->assertNull($clave());

        $migracion->up();
        $ma = $clave();
        $this->assertNotNull($ma);
        $conPermiso = RolPermiso::where('modulo_accion_id', $ma->id)->with('rol')->get()->pluck('rol.nombre')->unique()->values()->all();
        $this->assertSame(['Administrador'], $conPermiso);
        $this->assertSame(['empresa'], RolPermiso::where('modulo_accion_id', $ma->id)->pluck('alcance')->map(fn ($a) => $a instanceof \BackedEnum ? $a->value : $a)->unique()->values()->all());

        // Idempotente
        $migracion->up();
        $this->assertSame(1, ModuloAccion::where('id', $ma->id)->count());
    }

    public function test_celdas_que_parecen_formula_se_neutralizan(): void
    {
        $this->assertSame("'=1+1", ExportacionEmpresa::celda('=1+1'));
        $this->assertSame("'@SUM(A1)", ExportacionEmpresa::celda('@SUM(A1)'));
        $this->assertSame('-5', ExportacionEmpresa::celda('-5'));
        $this->assertSame('Juan', ExportacionEmpresa::celda('Juan'));
    }

    // ------------------------------------------------------------ apoyo

    private function usarStorage(string $ruta): void
    {
        File::ensureDirectoryExists($ruta.'/app/private');
        File::ensureDirectoryExists($ruta.'/app/public');
        $this->app->useStoragePath($ruta);
        config(['filesystems.disks.local.root' => $ruta.'/app/private', 'filesystems.disks.public.root' => $ruta.'/app/public']);
        $this->app->forgetInstance('filesystem');
    }

    private function conexionDestino(): void
    {
        $archivo = $this->temporal.'_destino.sqlite';
        @unlink($archivo);
        touch($archivo);
        config(['database.connections.destino' => ['driver' => 'sqlite', 'database' => $archivo, 'prefix' => '', 'foreign_key_constraints' => true]]);
    }

    private function escribir(string $relativa, string $contenido): void
    {
        $ruta = storage_path('app/'.$relativa);
        File::ensureDirectoryExists(dirname($ruta));
        file_put_contents($ruta, $contenido);
    }

    /**
     * Datos y archivos de ejemplo en las dos empresas.
     *
     * @return array<string, string> ruta relativa a storage/app => contenido
     */
    private function sembrarDatosYArchivos(): array
    {
        $archivos = [
            'private/firmas/centro/firma.png' => 'PNG-FIRMA-CENTRO',
            'private/firmas/playa/firma.png' => 'PNG-FIRMA-PLAYA',
            'private/candidatos/'.$this->empresa->id.'/ine frente.pdf' => "%PDF-1.4\n".str_repeat('a', 3000),
            'public/identidad/logo_centro.png' => 'PNG-CENTRO',
            'public/identidad/logo_playa.png' => 'PNG-PLAYA',
        ];
        foreach ($archivos as $ruta => $contenido) {
            $this->escribir($ruta, $contenido);
        }
        // Lo que no debe viajar
        $this->escribir('private/respaldos/respaldo_viejo.sql.gz', 'NO');
        $this->escribir('private/traslados/otro.zip', 'NO');

        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $playa = $this->crearUsuario($this->otra, 'Agente');
        DB::table('firmas_usuarios')->insert([
            ['empresa_id' => $this->empresa->id, 'user_id' => $agente->id, 'firma_ruta' => 'firmas/centro/firma.png', 'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => $this->otra->id, 'user_id' => $playa->id, 'firma_ruta' => 'firmas/playa/firma.png', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('empresas')->where('id', $this->empresa->id)->update(['logo_ruta' => 'storage/identidad/logo_centro.png']);
        DB::table('empresas')->where('id', $this->otra->id)->update(['logo_ruta' => 'storage/identidad/logo_playa.png']);
        DB::table('personas')->insert([
            ['empresa_id' => $this->empresa->id, 'nombre_completo' => '=HYPERLINK("http://x")', 'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => $this->otra->id, 'nombre_completo' => 'Visitante de Hotel Playa', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Un texto con ; saltos de línea, comillas, diagonales y un «--» no debe romper la restauración
        DB::table('sedes')->where('id', $this->centro->id)->update(['nombre' => "Sede «Centro»; piso 2\n-- O'Higgins \\ \"Norte\";\n"]);

        return $archivos;
    }

    /**
     * @return array<string, mixed>
     */
    private function terminarYTomar(): array
    {
        $p = app(Paquetes::class);
        foreach ($p->trabajos('traslado')->where('estado', 'en_proceso') as $t) {
            $p->completar($t['id']);
        }
        $paquete = $p->listar()->first();
        $this->assertNotNull($paquete, (string) ($p->trabajos('traslado')->first()['error'] ?? ''));

        return $paquete;
    }

    /**
     * Copia el paquete y cambia el contenido de un archivo sin tocar el manifest.
     */
    private function alterar(string $ruta): string
    {
        $copia = $this->temporal.'/alterado_'.bin2hex(random_bytes(3)).'.zip';
        copy($ruta, $copia);
        $zip = new ZipArchive;
        $zip->open($copia);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $victima = collect($manifest['archivos'])->first(fn ($a) => str_starts_with($a['ruta'], 'archivos/'))
            ?? collect($manifest['archivos'])->first();
        $zip->addFromString($victima['ruta'], 'CONTENIDO CAMBIADO');
        $zip->close();

        return $copia;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leerJson(ZipArchive $zip, string $nombre): array
    {
        $texto = $zip->getFromName($nombre);
        $this->assertNotFalse($texto, "Falta {$nombre}");

        return json_decode($texto, true);
    }
}
