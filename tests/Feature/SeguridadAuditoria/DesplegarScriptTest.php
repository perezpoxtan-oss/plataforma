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

        // PHP simulado: anota artisan con sus argumentos exactos (uno por corchete).
        // plataforma:limpiar-qa responde con la solicitud de $HOME/solicitud_limpieza
        // y falla si existe $HOME/limpieza_falla (como cuando no se pudo respaldar).
        $falso = <<<'BASH'
            #!/bin/bash
            for a in "$@"; do
              if [ "$a" = artisan ]; then
                { printf '[%s]' "$@"; printf ' PLATAFORMA_CONTRASENA=[%s]\n' "${PLATAFORMA_CONTRASENA-}"; } >> "$HOME/artisan.log"
                case " $* " in
                  *' plataforma:limpiar-qa --solicitud '*) cat "$HOME/solicitud_limpieza" 2>/dev/null ;;
                  *' plataforma:limpiar-qa --confirmar '*)
                    if [ -f "$HOME/limpieza_falla" ]; then echo 'No se limpió la base: no se pudo hacer el respaldo previo.'; exit 1; fi
                    echo 'Base de QA limpiada: 10 renglones de 2 tablas y 0 archivos; se conservaron 1 Super Administrador(es).' ;;
                esac
                exit 0
              fi
            done
            exec PHP_REAL "$@"

            BASH;
        $this->escribir('bin/php', str_replace('PHP_REAL', escapeshellarg(PHP_BINARY), $falso), 0700);
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
        $this->assertStringContainsString('OK: instalada qa-101', $this->leer('despliegue_qa_estado.txt'));
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

    // ------------------------------------------- limpieza única de la base de QA

    public function test_qa_limpia_la_base_una_sola_vez_y_antes_del_demo(): void
    {
        $this->publicar('qa', 701, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $this->escribir('solicitud_limpieza', "2026-11-02\n");
        $this->escribir('apps/plataforma-qa/shared/demo_encendido', '');

        $this->desplegar('qa');

        $log = $this->leer('artisan.log');
        $this->assertSame(1, substr_count($log, '[plataforma:limpiar-qa][--confirmar]'));
        $this->assertLessThan(strpos($log, '[plataforma:demo]'), strpos($log, '[plataforma:limpiar-qa][--confirmar]'), 'La limpieza corre antes del demo');
        $this->assertSame('2026-11-02', trim($this->leer('apps/plataforma-qa/.limpieza_qa_completada')));
        $this->assertStringContainsString('Base de QA limpiada: 10 renglones', $this->leer('despliegue_qa_estado.txt'));

        // Las siguientes revisiones no la repiten y el resultado sigue a la vista
        $this->desplegar('qa');
        $this->desplegar('qa');
        $this->assertSame(1, substr_count($this->leer('artisan.log'), '[plataforma:limpiar-qa][--confirmar]'));
        $this->assertStringContainsString('Limpieza QA:', $this->leer('despliegue_qa_estado.txt'));
        $this->assertStringContainsString('solicitud 2026-11-02', $this->leer('despliegue_qa_estado.txt'));

        // Otra solicitud (otro identificador en el código) vuelve a correrla una vez
        $this->escribir('solicitud_limpieza', "2026-12-01\n");
        $this->desplegar('qa');
        $this->assertSame(2, substr_count($this->leer('artisan.log'), '[plataforma:limpiar-qa][--confirmar]'));
    }

    public function test_si_la_limpieza_falla_no_se_marca_y_se_reintenta_maximo_tres_veces(): void
    {
        $this->publicar('qa', 702, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $this->escribir('solicitud_limpieza', "2026-11-02\n");
        $this->escribir('limpieza_falla', '');

        foreach (range(1, 4) as $_) {
            $this->desplegar('qa');
        }

        $this->assertSame(3, substr_count($this->leer('artisan.log'), '[plataforma:limpiar-qa][--confirmar]'));
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-qa/.limpieza_qa_completada');
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-qa/.limpieza_qa_resumen');
    }

    public function test_la_limpieza_falla_y_lo_dice_el_estado(): void
    {
        $this->publicar('qa', 703, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $this->escribir('solicitud_limpieza', "2026-11-02\n");
        $this->escribir('limpieza_falla', '');

        $this->desplegar('qa');

        $estado = $this->leer('despliegue_qa_estado.txt');
        $this->assertStringContainsString('ERROR: no se pudo limpiar la base de QA (intento 1 de 3', $estado);
        $this->assertStringContainsString('no se pudo hacer el respaldo previo', $estado);
    }

    public function test_version_sin_el_comando_de_limpieza_no_limpia(): void
    {
        $this->publicar('qa', 704, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $this->assertStringNotContainsString('[plataforma:limpiar-qa][--confirmar]', $this->leer('artisan.log'));
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-qa/.limpieza_qa_completada');
    }

    public function test_produccion_nunca_limpia_la_base_ni_siembra_demo(): void
    {
        $this->escribir('env_prod.txt', $this->envProd());
        $this->escribir('desplegar_version.txt', "v1.0.0\n");
        $this->escribir('solicitud_limpieza', "2026-11-02\n");
        $this->escribir('apps/plataforma-produccion/shared/demo_encendido', '');
        $this->publicar('v1.0.0', 705, $this->paquete());

        $this->desplegar('prod');

        $this->assertSame('v1.0.0-705', trim($this->leer('apps/plataforma-produccion/.release_actual')));
        $log = $this->leer('artisan.log');
        $this->assertStringNotContainsString('plataforma:limpiar-qa', $log);
        $this->assertStringNotContainsString('plataforma:demo', $log);
    }

    // ------------------------------------------------ interruptor de datos demo

    public function test_qa_ya_no_siembra_el_demo_salvo_con_el_interruptor(): void
    {
        $this->publicar('qa', 801, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');
        $this->assertStringNotContainsString('[plataforma:demo]', $this->leer('artisan.log'));
        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-qa/.demo_completado');

        // El dueño crea el archivo vacío demo_encendido desde el Administrador de archivos
        $this->escribir('apps/plataforma-qa/shared/demo_encendido', '');
        $this->desplegar('qa');
        $this->assertSame(1, substr_count($this->leer('artisan.log'), '[plataforma:demo]'));
        $this->assertSame('qa-801', trim($this->leer('apps/plataforma-qa/.demo_completado')));
        // Una vez por versión
        $this->desplegar('qa');
        $this->assertSame(1, substr_count($this->leer('artisan.log'), '[plataforma:demo]'));
    }

    public function test_instalacion_nueva_de_qa_crea_el_superadministrador_sin_empresa_demo(): void
    {
        $this->publicar('qa', 802, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $this->escribir('qa_inicial.txt', "CORREO=ana@ejemplo.com\nNOMBRE=Ana\nUSUARIO=ana\nCONTRASENA=Clave.Segura.2026\n");

        $this->desplegar('qa');

        $log = $this->leer('artisan.log');
        $this->assertStringContainsString('[plataforma:superadmin][--nombre=Ana][--usuario=ana][--][ana@ejemplo.com]', $log);
        $this->assertStringNotContainsString('[plataforma:demo]', $log);
        $this->assertStringContainsString('Super Administrador creado (datos demo apagados)', $this->leer('despliegue_qa_estado.txt'));
        $this->assertFileDoesNotExist($this->home.'/qa_inicial.txt');
    }

    public function test_instalacion_nueva_de_qa_con_el_interruptor_tambien_crea_la_empresa_demo(): void
    {
        $this->publicar('qa', 803, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $this->escribir('apps/plataforma-qa/shared/demo_encendido', '');
        $this->escribir('qa_inicial.txt', "CORREO=ana@ejemplo.com\nNOMBRE=Ana\nUSUARIO=ana\nCONTRASENA=Clave.Segura.2026\n");

        $this->desplegar('qa');

        $log = $this->leer('artisan.log');
        $this->assertStringContainsString('[plataforma:superadmin]', $log);
        $this->assertStringContainsString('usuarios de prueba creados (Super Administrador y empresa demo)', $this->leer('despliegue_qa_estado.txt'));
    }

    public function test_produccion_crea_su_superadministrador_inicial_sin_empresa_demo(): void
    {
        // Primera instalacion de Produccion: sin terminal, el primer acceso sale de prod_inicial.txt
        $this->escribir('env_prod.txt', $this->envProd());
        $this->escribir('desplegar_version.txt', "v0.1.0\n");
        $this->publicar('v0.1.0', 901, $this->paquete());
        // Aunque exista el interruptor del demo, en Produccion nunca se siembra
        $this->escribir('apps/plataforma-produccion/shared/demo_encendido', '');
        $this->escribir('prod_inicial.txt', "CORREO=ana@ejemplo.com\r\nNOMBRE=Ana\r\nUSUARIO=ana\r\nCONTRASENA=Clave.Segura.2026\r\n");

        $this->desplegar('prod');

        $log = $this->leer('artisan.log');
        $this->assertStringContainsString('[plataforma:superadmin][--nombre=Ana][--usuario=ana][--][ana@ejemplo.com]', $log);
        $this->assertStringContainsString('PLATAFORMA_CONTRASENA=[Clave.Segura.2026]', $log);
        $this->assertStringNotContainsString('[plataforma:demo]', $log);
        $this->assertStringContainsString('Super Administrador creado o actualizado', $this->leer('despliegue_prod_estado.txt'));
        $this->assertFileDoesNotExist($this->home.'/prod_inicial.txt');
        $this->assertFileDoesNotExist($this->home.'/.config/plataforma/prod_inicial');
    }

    public function test_produccion_rechaza_prod_inicial_con_datos_de_ejemplo(): void
    {
        $this->escribir('env_prod.txt', $this->envProd());
        $this->escribir('desplegar_version.txt', "v0.1.0\n");
        $this->publicar('v0.1.0', 902, $this->paquete());
        $this->escribir('prod_inicial.txt', (string) file_get_contents(dirname(__DIR__, 3).'/despliegue/prod_inicial.txt'));

        $this->desplegar('prod');

        $this->assertStringNotContainsString('[plataforma:superadmin]', $this->leer('artisan.log'));
        $this->assertStringContainsString('prod_inicial.txt todavia tiene los datos de ejemplo', $this->leer('despliegue_prod_estado.txt'));
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

    public function test_conserva_la_version_de_php_que_cpanel_asigna_al_sitio(): void
    {
        // PHP 8.5: MultiPHP Manager escribe la versión en el .htaccess del sitio;
        // antes cada instalación lo reemplazaba y el sitio volvía a la versión por omisión
        $bloque = "# php -- BEGIN cPanel-generated handler, do not edit\n# Set the “ea-php85” package as the default “PHP” programming language.\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php85 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit";
        $this->escribir('qa.vdcp.com.mx/.htaccess', "# viejo\n\n".$bloque."\n", 0644);
        $this->publicar('qa', 801, $this->paquete('nueva'));
        $this->escribir('env_qa.txt', $this->envQa());
        $this->desplegar('qa');

        $this->publicar('qa', 802, $this->paquete('otra'));
        $this->desplegar('qa');

        $htaccess = $this->leer('qa.vdcp.com.mx/.htaccess');
        $this->assertSame('qa-802', trim($this->leer('apps/plataforma-qa/.release_actual')));
        $this->assertStringContainsString('# otra', $htaccess);
        $this->assertStringNotContainsString('# viejo', $htaccess);
        $this->assertStringContainsString($bloque, $htaccess);
        $this->assertSame(1, substr_count($htaccess, 'BEGIN cPanel-generated handler'));
    }

    public function test_php_de_linea_de_comandos_sigue_la_version_del_sitio(): void
    {
        // Sin .php_cli_path válido: misma versión que el sitio (ea-php85); sin bloque de cPanel, 8.4
        $this->escribir('.php_cli_path', $this->home.'/no-existe/php');
        $this->phpFalso('raiz/opt/alt/php84/usr/bin/php', 'alt-php84');
        $this->phpFalso('raiz/opt/cpanel/ea-php85/root/usr/bin/php', 'ea-php85');
        $extra = ['PLATAFORMA_RAIZ_PHP' => $this->home.'/raiz'];

        $this->publicar('qa', 901, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());
        $this->desplegar('qa', extra: $extra);
        $this->assertSame("alt-php84\n", array_unique(file($this->home.'/php_usado.log'))[0]);

        unlink($this->home.'/php_usado.log');
        file_put_contents($this->home.'/qa.vdcp.com.mx/.htaccess', "\n# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler application/x-httpd-ea-php85 .php\n# php -- END cPanel-generated handler, do not edit\n", FILE_APPEND);
        $this->publicar('qa', 902, $this->paquete());
        $this->desplegar('qa', extra: $extra);

        $this->assertSame('qa-902', trim($this->leer('apps/plataforma-qa/.release_actual')));
        $this->assertSame(["ea-php85\n"], array_values(array_unique(file($this->home.'/php_usado.log'))));
    }

    public function test_sin_php_8_4_no_instala(): void
    {
        // PHP que no es 8.4.1+ (p. ej. 8.3) no sirve: las librerías piden 8.4.1
        $this->escribir('bin/php83', "#!/bin/bash\nexit 1\n", 0700);
        $this->escribir('.php_cli_path', $this->home.'/bin/php83');
        $this->publicar('qa', 903, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa', extra: ['PLATAFORMA_RAIZ_PHP' => $this->home.'/raiz']);

        $this->assertFileDoesNotExist($this->home.'/apps/plataforma-qa/.release_actual');
        $this->assertStringContainsString('No se encontro PHP 8.4.1+', $this->leer('despliegue_qa_estado.txt'));
    }

    // ------------------------------------------------- app de Android (Mi cuenta → App para tu celular)

    public function test_la_app_de_android_del_paquete_se_instala_en_storage_privado(): void
    {
        $apk = 'APK-QA-1';
        $this->publicar('qa', 1001, $this->paquete('v', $this->appMovil($apk, 'qa-abc1234')));
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $dir = $this->home.'/apps/plataforma-qa/shared/storage/app/private/app-movil';
        $sha = hash('sha256', $apk);
        $this->assertStringContainsString('OK: instalada qa-1001', $this->leer('despliegue_qa_estado.txt'));
        $this->assertSame($apk, $this->leer('apps/plataforma-qa/shared/storage/app/private/app-movil/smart-centinel-'.$sha.'.apk'));
        $json = json_decode($this->leer('apps/plataforma-qa/shared/storage/app/private/app-movil/app-movil.json'), true);
        $this->assertSame('qa-abc1234', $json['version']);
        $this->assertSame($sha, $json['sha256']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $json['instalado_en']);
        $this->assertSame('750', $this->permisos('apps/plataforma-qa/shared/storage/app/private/app-movil'));
        // No es público: nada de la app en la carpeta del subdominio
        $this->assertSame([], glob($this->home.'/qa.vdcp.com.mx/*.apk') ?: []);
        $this->assertStringContainsString('App de Android instalada: qa-abc1234', $this->leer('apps/plataforma-qa/despliegue.log'));

        // Otra revisión sin cambios no la vuelve a anotar en el historial
        $this->desplegar('qa');
        $this->assertCount(1, file($dir.'/historial.jsonl'));
    }

    public function test_una_version_nueva_reemplaza_la_app_y_sin_app_se_conserva_la_anterior(): void
    {
        $this->escribir('env_qa.txt', $this->envQa());
        $this->publicar('qa', 1011, $this->paquete('v', $this->appMovil('APK-UNO', 'qa-1111111')));
        $this->desplegar('qa');
        $this->publicar('qa', 1012, $this->paquete('v', $this->appMovil('APK-DOS', 'qa-2222222')));
        $this->desplegar('qa');

        $dir = $this->home.'/apps/plataforma-qa/shared/storage/app/private/app-movil';
        $this->assertSame([$dir.'/smart-centinel-'.hash('sha256', 'APK-DOS').'.apk'], glob($dir.'/*.apk'));
        $this->assertCount(2, file($dir.'/historial.jsonl'));

        // Paquete sin app (p. ej. sin la llave de firma): el despliegue sigue y la app anterior se queda
        $this->publicar('qa', 1013, $this->paquete('v'));
        $this->desplegar('qa');
        $this->assertStringContainsString('OK: instalada qa-1013', $this->leer('despliegue_qa_estado.txt'));
        $this->assertSame('qa-2222222', json_decode((string) file_get_contents($dir.'/app-movil.json'), true)['version']);
        $this->assertFileExists($dir.'/smart-centinel-'.hash('sha256', 'APK-DOS').'.apk');
    }

    public function test_sin_app_en_el_paquete_no_se_crea_nada_y_el_despliegue_funciona(): void
    {
        $this->publicar('qa', 1021, $this->paquete());
        $this->escribir('env_qa.txt', $this->envQa());

        $this->desplegar('qa');

        $this->assertStringContainsString('OK: instalada qa-1021', $this->leer('despliegue_qa_estado.txt'));
        $this->assertDirectoryDoesNotExist($this->home.'/apps/plataforma-qa/shared/storage/app/private/app-movil');
    }

    public function test_app_que_no_coincide_con_su_huella_o_json_invalido_no_se_instala(): void
    {
        $this->escribir('env_qa.txt', $this->envQa());
        $app = $this->appMovil('APK-BUENO', 'qa-3333333');
        $app['smart-centinel.apk'] = 'APK-CAMBIADO';
        $this->publicar('qa', 1031, $this->paquete('v', $app));
        $this->desplegar('qa');

        $dir = $this->home.'/apps/plataforma-qa/shared/storage/app/private/app-movil';
        $this->assertStringContainsString('OK: instalada qa-1031', $this->leer('despliegue_qa_estado.txt'));
        $this->assertFileDoesNotExist($dir.'/app-movil.json');
        $this->assertStringContainsString('no coincide con su huella', $this->leer('apps/plataforma-qa/despliegue.log'));

        // Versión que intenta formar rutas o comandos: se rechaza sin ejecutar nada
        $pwn = $this->home.'/pwn';
        $app = $this->appMovil('APK-X', 'qa-4444444');
        $json = json_decode($app['app-movil.json'], true);
        $json['version'] = '$(touch '.$pwn.')/../x';
        $app['app-movil.json'] = json_encode($json);
        $this->publicar('qa', 1032, $this->paquete('v', $app));
        $this->desplegar('qa');
        $this->assertFileDoesNotExist($pwn);
        $this->assertFileDoesNotExist($dir.'/app-movil.json');
        $this->assertStringContainsString('app-movil.json del paquete no es valido', $this->leer('apps/plataforma-qa/despliegue.log'));
    }

    /** @return array<string, string> archivos de app-movil/ como los deja la CI (apk.yml) */
    private function appMovil(string $apk, string $version): array
    {
        return [
            'smart-centinel.apk' => $apk,
            'app-movil.json' => json_encode(['app' => 'mx.smartcentinel.app', 'version' => $version, 'version_code' => 123, 'servidor' => 'qa',
                'commit' => str_repeat('a', 40), 'fecha' => '2026-11-09T15:00:00Z', 'sha256' => hash('sha256', $apk), 'tamano' => strlen($apk),
                'huella_firma' => implode(':', array_fill(0, 32, 'AB')), 'firma' => 'publicacion']),
        ];
    }

    // ---------------------------------------------------------------- auxiliares

    private function desplegar(string $ambiente, ?string $urlSitio = null, array $extra = []): string
    {
        $env = $extra + [
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
     * PHP de línea de comandos simulado en una ruta del servidor: anota su nombre
     * cuando corre artisan y se comporta como bin/php.
     */
    private function phpFalso(string $ruta, string $nombre): void
    {
        $this->escribir($ruta, "#!/bin/bash\ncase \" \$* \" in *' artisan '*) echo ".escapeshellarg($nombre)." >> \"\$HOME/php_usado.log\" ;; esac\nexec \"\$HOME/bin/php\" \"\$@\"\n", 0700);
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

    /** @param array<string, string> $appMovil archivos de app-movil/ (la app de Android), si el paquete la trae */
    private function paquete(string $marca = 'v', array $appMovil = []): string
    {
        $dir = $this->home.'/fuente-'.bin2hex(random_bytes(4));
        mkdir($dir.'/public', 0755, true);
        foreach ($appMovil as $nombre => $contenido) {
            @mkdir($dir.'/app-movil', 0755);
            file_put_contents($dir.'/app-movil/'.$nombre, $contenido);
        }
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
