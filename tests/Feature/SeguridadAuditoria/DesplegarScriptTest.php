<?php

namespace Tests\Feature\SeguridadAuditoria;

use PHPUnit\Framework\TestCase;

/**
 * Auditoría de seguridad 2026-10-06 — Infraestructura (INF-xx).
 *
 * Corre despliegue/desplegar.sh de verdad, en una carpeta $HOME desechable:
 *  - GitHub se simula con archivos (PLATAFORMA_API=file://...);
 *  - el sitio (/up) es un `php -S` que siempre responde 200;
 *  - "php" es un envoltorio que anota cada llamada a artisan (con sus
 *    argumentos exactos) y deja pasar lo demás al PHP real;
 *  - "curl" es un envoltorio que anota sus argumentos (para comprobar que el
 *    token no viaja en la línea de comandos).
 */
class DesplegarScriptTest extends TestCase
{
    private const TOKEN = 'github_pat_PRUEBA0123456789secreto';

    private static $servidor;

    private static int $puerto;

    private string $home;

    public static function setUpBeforeClass(): void
    {
        foreach (['bash', 'curl', 'tar', 'sha256sum', 'awk'] as $programa) {
            if (trim((string) shell_exec('command -v '.$programa)) === '') {
                self::markTestSkipped("Falta {$programa} para probar desplegar.sh");
            }
        }

        $router = sys_get_temp_dir().'/plataforma-up-'.getmypid().'.php';
        file_put_contents($router, '<?php echo "OK";');
        for ($i = 0; $i < 20 && ! isset(self::$servidor); $i++) {
            $puerto = random_int(20000, 60000);
            $proceso = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$puerto}", $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $tuberias);
            for ($t = 0; $t < 50; $t++) {
                if (@fsockopen('127.0.0.1', $puerto, $errno, $errstr, 0.1)) {
                    self::$servidor = $proceso;
                    self::$puerto = $puerto;
                    break;
                }
                usleep(100000);
            }
            if (! isset(self::$servidor)) {
                proc_terminate($proceso);
            }
        }
        if (! isset(self::$servidor)) {
            self::markTestSkipped('No se pudo levantar el servidor simulado de /up');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$servidor)) {
            proc_terminate(self::$servidor);
        }
    }

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/plataforma-despliegue-'.bin2hex(random_bytes(6));
        mkdir($this->home.'/bin', 0700, true);

        // PHP simulado: anota artisan con sus argumentos exactos (uno por corchete)
        $this->escribir('bin/php', "#!/bin/bash\nfor a in \"\$@\"; do\n  if [ \"\$a\" = artisan ]; then\n    { printf '[%s]' \"\$@\"; printf ' PLATAFORMA_CONTRASENA=[%s]\\n' \"\${PLATAFORMA_CONTRASENA-}\"; } >> \"\$HOME/artisan.log\"\n    exit 0\n  fi\ndone\nexec ".escapeshellarg(PHP_BINARY)." \"\$@\"\n", 0700);
        $this->escribir('bin/curl', "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> \"\$HOME/curl_args.log\"\nexec ".escapeshellarg(trim((string) shell_exec('command -v curl')))." \"\$@\"\n", 0700);
        $this->escribir('.php_cli_path', $this->home.'/bin/php');
        $this->escribir('github_token.txt', self::TOKEN."\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->home)) {
            exec('chmod -R u+rwX '.escapeshellarg($this->home).' && rm -rf '.escapeshellarg($this->home));
        }
    }

    // ------------------------------------------------------------------ pruebas

    public function test_instalacion_normal_y_el_token_no_viaja_como_argumento(): void
    {
        $this->publicar('qa', 101, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $this->assertSame('qa-101', trim($this->leer('apps/plataforma-qa/.release_actual')));
        $this->assertStringContainsString('OK: qa-101', $this->leer('despliegue_qa_estado.txt'));
        // INF-03: el token no aparece en la línea de comandos de curl (visible con ps)
        $this->assertStringNotContainsString(self::TOKEN, $this->leer('curl_args.log'));
        $this->assertStringContainsString('-K -', $this->leer('curl_args.log'));
        // INF-08: .env privado
        $this->assertSame('600', $this->permisos('apps/plataforma-qa/shared/.env'));
        $this->assertSame('700', $this->permisos('.config/plataforma'));
        $this->assertFileDoesNotExist($this->home.'/github_token.txt');
    }

    public function test_sin_huella_sha256_no_se_instala(): void
    {
        // INF-02: antes, sin "digest" en la respuesta se instalaba sin verificar nada
        $this->publicar('qa', 102, $this->paquete(), digest: false);
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-qa/.release_actual');
        $this->assertDirectoryDoesNotExist($this->home.'/apps/plataforma-qa/releases/qa-102');
        $this->assertStringContainsString('huella sha256', $this->leer('despliegue_qa_estado.txt'));
    }

    public function test_paquete_alterado_no_se_instala(): void
    {
        $this->publicar('qa', 103, $this->paquete(), digestFalso: 'sha256:'.str_repeat('a', 64));
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $this->assertDirectoryDoesNotExist($this->home.'/apps/plataforma-qa/releases/qa-103');
        $this->assertStringContainsString('no coincide con su huella', $this->leer('despliegue_qa_estado.txt'));
    }

    public function test_identificador_de_paquete_con_ruta_no_se_usa(): void
    {
        // INF-02: el id forma la carpeta releases/<etiqueta>-<id> que luego se borra con rm -rf
        $this->publicar('qa', '../../../victima', $this->paquete());
        mkdir($this->home.'/victima');
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $this->assertDirectoryExists($this->home.'/victima');
        $this->assertStringContainsString('identificador de paquete no valido', $this->leer('despliegue_qa_estado.txt'));
    }

    public function test_produccion_no_acepta_un_paquete_reemplazado_en_la_misma_version(): void
    {
        // INF-01: quien pueda escribir en GitHub reemplaza paquete.tar.gz de v1.0.0
        // (cambia el id del archivo) y Produccion lo instalaba sin nueva autorizacion
        $this->escribir('env_prod.txt', $this->envProd());
        $this->escribir('desplegar_version.txt', "v1.0.0\n");
        $this->publicar('v1.0.0', 201, $this->paquete('original'));
        $this->desplegar('prod');
        $this->assertSame('v1.0.0-201', trim($this->leer('apps/plataforma-produccion/.release_actual')));

        $this->publicar('v1.0.0', 202, $this->paquete('reemplazado'));
        $this->desplegar('prod');

        $this->assertSame('v1.0.0-201', trim($this->leer('apps/plataforma-produccion/.release_actual')));
        $this->assertDirectoryDoesNotExist($this->home.'/apps/plataforma-produccion/releases/v1.0.0-202');
        $this->assertStringContainsString('cambio en GitHub', $this->leer('despliegue_prod_estado.txt'));
    }

    public function test_produccion_respeta_la_huella_escrita_en_desplegar_version(): void
    {
        $paquete = $this->paquete();
        $this->escribir('env_prod.txt', $this->envProd());
        $this->escribir('desplegar_version.txt', 'v1.0.1 sha256:'.str_repeat('b', 64)."\n");
        $this->publicar('v1.0.1', 301, $paquete);

        $this->desplegar('prod');
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-produccion/.release_actual');
        $this->assertStringContainsString('no coincide con la huella escrita', $this->leer('despliegue_prod_estado.txt'));

        $this->escribir('desplegar_version.txt', 'v1.0.1 sha256:'.hash_file('sha256', $paquete)."\n");
        $this->desplegar('prod');
        $this->assertSame('v1.0.1-301', trim($this->leer('apps/plataforma-produccion/.release_actual')));
    }

    public function test_produccion_rechaza_configuracion_insegura(): void
    {
        // INF-06: APP_DEBUG=true o APP_ENV distinto de production en Produccion
        $this->escribir('desplegar_version.txt', "v1.0.0\n");
        $this->publicar('v1.0.0', 401, $this->paquete());

        $this->escribir('env_prod.txt', str_replace('APP_DEBUG=false', 'APP_DEBUG=true', $this->envProd()));
        $this->desplegar('prod');
        $this->assertStringContainsString('APP_DEBUG=false', $this->leer('despliegue_prod_estado.txt'));
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-produccion/.release_actual');

        $this->escribir('env_prod.txt', str_replace('APP_ENV=production', 'APP_ENV=prod', $this->envProd()));
        $this->desplegar('prod');
        $this->assertStringContainsString('APP_ENV=production', $this->leer('despliegue_prod_estado.txt'));
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-produccion/.release_actual');
    }

    public function test_candado_de_una_corrida_viva_no_se_roba_aunque_sea_viejo(): void
    {
        // INF-07: antes, un candado de mas de 30 minutos se quitaba aunque la corrida siguiera
        $this->publicar('qa', 501, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $candado = $this->home.'/apps/plataforma-qa/.desplegando';
        mkdir($candado, 0755, true);
        file_put_contents($candado.'/pid', (string) getmypid()); // este proceso sigue vivo
        touch($candado, time() - 3600);

        $this->desplegar('qa');

        $this->assertFileExists($this->home.'/env_qa.txt', 'La segunda corrida no debió hacer nada');
        $this->assertFileDoesNotExist($this->home.'/despliegue_qa_estado.txt');
        $this->assertSame((string) getmypid(), file_get_contents($candado.'/pid'));
    }

    public function test_candado_de_una_corrida_muerta_se_recupera(): void
    {
        $this->publicar('qa', 502, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $candado = $this->home.'/apps/plataforma-qa/.desplegando';
        mkdir($candado, 0755, true);
        file_put_contents($candado.'/pid', '999999999');

        $this->desplegar('qa');

        $this->assertSame('qa-502', trim($this->leer('apps/plataforma-qa/.release_actual')));
        $this->assertDirectoryDoesNotExist($candado, 'Al terminar se quita el candado propio');
    }

    public function test_datos_de_qa_inicial_no_se_ejecutan_como_comandos(): void
    {
        // INF-09 (sin hallazgo, se deja la prueba): $(...), comillas y espacios llegan literales
        $this->publicar('qa', 601, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $pwn = $this->home.'/pwn';
        $this->escribir('qa_inicial.txt', implode("\r\n", [
            'CORREO=ana@ejemplo.com',
            'NOMBRE=Ana $(touch '.$pwn.'1) `touch '.$pwn.'2`',
            'USUARIO=ana',
            'CONTRASENA=Cl@ve"; touch '.$pwn.'3; echo "$(touch '.$pwn.'4) x=y',
        ])."\r\n");

        $this->desplegar('qa');

        foreach ([1, 2, 3, 4] as $n) {
            $this->assertFileDoesNotExist($pwn.$n);
        }
        $log = $this->leer('artisan.log');
        $this->assertStringContainsString('[plataforma:superadmin][--nombre=Ana $(touch '.$pwn.'1) `touch '.$pwn.'2`][--usuario=ana][--][ana@ejemplo.com]', $log);
        $this->assertStringContainsString('PLATAFORMA_CONTRASENA=[Cl@ve"; touch '.$pwn.'3; echo "$(touch '.$pwn.'4) x=y]', $log);
        // El archivo con la contraseña no queda en ningún lado
        $this->assertFileDoesNotExist($this->home.'/qa_inicial.txt');
        $this->assertFileDoesNotExist($this->home.'/.config/plataforma/qa_inicial');
    }

    public function test_un_regreso_tambien_regresa_los_archivos_publicos(): void
    {
        // INF-10: si la version nueva falla, el .htaccess/css/js nuevos se quedaban
        $this->publicar('qa', 701, $this->paquete('buena'));
        $this->escribir('env_qa.txt', $this->envQa());
        $this->desplegar('qa');
        $this->assertStringContainsString('buena', $this->leer('qa.vdcp.com.mx/.htaccess'));

        $this->publicar('qa', 702, $this->paquete('rota'));
        $this->desplegar('qa', urlSitio: 'http://127.0.0.1:1'); // el sitio "no responde"

        $this->assertSame('qa-701', trim($this->leer('apps/plataforma-qa/.release_actual')));
        $this->assertStringContainsString('buena', $this->leer('qa.vdcp.com.mx/.htaccess'));
        $this->assertStringContainsString("readlink('".$this->home.'/apps/plataforma-qa/actual', $this->leer('qa.vdcp.com.mx/index.php'));
    }

    // ---------------------------------------------------------------- auxiliares

    private function desplegar(string $ambiente, ?string $urlSitio = null): string
    {
        $env = [
            'HOME' => $this->home,
            'PATH' => $this->home.'/bin:'.getenv('PATH'),
            'PLATAFORMA_API' => 'file://'.$this->home.'/api',
            'PLATAFORMA_URL' => $urlSitio ?? 'http://127.0.0.1:'.self::$puerto,
            'LANG' => 'C',
        ];
        $script = dirname(__DIR__, 3).'/despliegue/desplegar.sh';
        $proceso = proc_open(['bash', $script, $ambiente], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $tuberias, $this->home, $env);
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);
        proc_close($proceso);

        return $salida;
    }

    /**
     * Simula la respuesta de GitHub para la versión y el archivo del paquete.
     */
    private function publicar(string $etiqueta, int|string $id, string $paquete, bool $digest = true, ?string $digestFalso = null): void
    {
        $base = 'api/repos/perezpoxtan-oss/plataforma/releases';
        $activo = ['id' => $id, 'name' => 'paquete.tar.gz'];
        if ($digest) {
            $activo['digest'] = $digestFalso ?? 'sha256:'.hash_file('sha256', $paquete);
        }
        $this->escribir("{$base}/tags/{$etiqueta}", json_encode(['tag_name' => $etiqueta, 'assets' => [$activo]]));
        if (is_int($id)) {
            @mkdir($this->home."/{$base}/assets", 0700, true);
            copy($paquete, $this->home."/{$base}/assets/{$id}");
        }
    }

    private function paquete(string $marca = 'v'): string
    {
        $dir = $this->home.'/fuente-'.bin2hex(random_bytes(4));
        mkdir($dir.'/public', 0755, true);
        file_put_contents($dir.'/public/.htaccess', "# {$marca}\n");
        file_put_contents($dir.'/public/index.php', "<?php // {$marca}\n");
        file_put_contents($dir.'/VERSION', "{$marca}\n");
        file_put_contents($dir.'/artisan', "<?php\n");
        $archivo = $dir.'.tar.gz';
        exec('tar -czf '.escapeshellarg($archivo).' -C '.escapeshellarg($dir).' .');

        return $archivo;
    }

    private function envQa(): string
    {
        return "APP_ENV=qa\nAPP_DEBUG=false\nAPP_URL=https://qa.vdcp.com.mx\nDB_PASSWORD=secreta\n";
    }

    private function envProd(): string
    {
        return "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://app.vdcp.com.mx\nDB_PASSWORD=secreta\n";
    }

    private function escribir(string $ruta, string $contenido, int $modo = 0600): void
    {
        $completa = $this->home.'/'.$ruta;
        @mkdir(dirname($completa), 0700, true);
        file_put_contents($completa, $contenido);
        chmod($completa, $modo);
    }

    private function leer(string $ruta): string
    {
        $completa = $this->home.'/'.$ruta;

        return is_file($completa) ? (string) file_get_contents($completa) : '';
    }

    private function permisos(string $ruta): string
    {
        clearstatcache();

        return substr(sprintf('%o', fileperms($this->home.'/'.$ruta)), -3);
    }
}
