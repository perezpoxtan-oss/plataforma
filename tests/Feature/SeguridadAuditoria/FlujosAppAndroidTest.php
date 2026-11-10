<?php

namespace Tests\Feature\SeguridadAuditoria;

use PHPUnit\Framework\TestCase;

/**
 * Compilación automática de la app de Android (docs/tecnico/app-movil.md,
 * «Compilación automática»): los flujos de GitHub Actions son YAML válido, las
 * acciones van fijadas por SHA, los secretos de firma solo llegan por variables
 * de entorno (nunca dentro de un comando ni impresos), la llave se borra del
 * runner y la app no detiene el paquete del servidor.
 */
class FlujosAppAndroidTest extends TestCase
{
    private const FLUJOS = ['apk.yml', 'paquete.yml', 'ci.yml'];

    private const SECRETOS = ['ANDROID_KEYSTORE_BASE64', 'ANDROID_KEYSTORE_PASSWORD', 'ANDROID_KEY_ALIAS', 'ANDROID_KEY_PASSWORD'];

    private function flujo(string $nombre): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/'.$nombre);
    }

    public function test_los_flujos_son_yaml_valido(): void
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        $ruby = trim((string) shell_exec('command -v ruby 2>/dev/null'));
        foreach (self::FLUJOS as $nombre) {
            $ruta = dirname(__DIR__, 3).'/.github/workflows/'.$nombre;
            if ($python !== '' && trim((string) shell_exec(escapeshellarg($python).' -c "import yaml" 2>&1; echo $?')) === '0') {
                exec(escapeshellarg($python).' -c '.escapeshellarg('import sys, yaml; yaml.safe_load(open(sys.argv[1]))').' '.escapeshellarg($ruta).' 2>&1', $salida, $rc);
            } elseif ($ruby !== '') {
                exec(escapeshellarg($ruby).' -ryaml -e '.escapeshellarg('YAML.safe_load(File.read(ARGV[0]), aliases: true)').' '.escapeshellarg($ruta).' 2>&1', $salida, $rc);
            } else {
                $this->markTestSkipped('No hay python3 con PyYAML ni ruby para leer YAML');
            }
            $this->assertSame(0, $rc, "{$nombre} no es YAML válido: ".implode("\n", $salida ?? []));
            // Sin tabuladores (YAML no los acepta como sangría)
            $this->assertStringNotContainsString("\t", $this->flujo($nombre), "{$nombre} tiene tabuladores");
        }
    }

    public function test_las_acciones_externas_van_fijadas_por_sha(): void
    {
        foreach (self::FLUJOS as $nombre) {
            preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)/m', $this->flujo($nombre), $m);
            foreach ($m[1] as $uso) {
                if (str_starts_with($uso, './')) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/', $uso, "{$nombre}: {$uso} no está fijada por SHA");
            }
        }
    }

    public function test_los_secretos_solo_llegan_por_variables_y_no_se_imprimen(): void
    {
        foreach (self::FLUJOS as $nombre) {
            foreach (explode("\n", $this->flujo($nombre)) as $i => $renglon) {
                if (! str_contains($renglon, 'secrets.')) {
                    continue;
                }
                // Solo «NOMBRE: ${{ secrets.NOMBRE }}» (env: o secrets: de un flujo llamado): nunca dentro de un run
                $this->assertMatchesRegularExpression('/^\s*[A-Z0-9_]+:\s*\$\{\{\s*secrets\.[A-Z0-9_]+\s*\}\}\s*$/', $renglon, "{$nombre}:".($i + 1).' usa un secreto fuera de una variable');
            }
        }

        $apk = $this->flujo('apk.yml');
        $this->assertStringNotContainsString('set -x', $apk);
        $this->assertDoesNotMatchRegularExpression('/(echo|printf|cat)[^\n]*\$\{?(KS_PASS|KS_KEYPASS|ANDROID_KEYSTORE_PASSWORD|ANDROID_KEY_PASSWORD)/', $apk, 'Una contraseña de la llave se imprime');
        $this->assertDoesNotMatchRegularExpression('/(echo|cat)[^\n]*firma\.jks/', $apk);
        $this->assertStringNotContainsString('GITHUB_ENV', $apk, 'Los secretos no se pasan a todos los pasos por GITHUB_ENV');
        // La contraseña va a keytool por variable de entorno (-storepass:env), no como argumento visible
        $this->assertStringContainsString('-storepass:env KS_PASS', $apk);
        // La llave se borra siempre y nunca se sube como artefacto
        $this->assertMatchesRegularExpression('/if: always\(\)\s*\n\s*run: rm -f "\$RUNNER_TEMP\/firma\.jks"/', $apk);
        preg_match_all('/^\s*path:\s*(.+)$/m', $apk, $rutas);
        foreach ($rutas[1] as $ruta) {
            $this->assertStringContainsString('/app-movil/', $ruta);
            $this->assertStringNotContainsString('jks', $ruta);
        }
        foreach (self::SECRETOS as $secreto) {
            $this->assertStringContainsString("{$secreto}:\n        required: false", $apk, "{$secreto} debe ser opcional en apk.yml");
        }
    }

    public function test_sin_secretos_compila_sin_publicar_y_avisa(): void
    {
        $apk = $this->flujo('apk.yml');

        $this->assertStringContainsString('echo "firmada=no"', $apk);
        $this->assertStringContainsString('::warning title=App de Android sin firma de publicacion::', $apk);
        $this->assertStringContainsString("if: inputs.publicar && steps.firma.outputs.firmada == 'si'", $apk);
        $this->assertStringContainsString('name: app-movil-prueba', $apk);
        // Versiones: Producción solo desde vMAYOR.MENOR.PARCHE; versionCode que siempre crece
        $this->assertStringContainsString('^v[0-9]+\.[0-9]+\.[0-9]+$', $apk);
        $this->assertStringContainsString('NOMBRE="qa-${SHA:0:7}"', $apk);
        $this->assertStringContainsString('CODIGO=$(( ($(date -u +%s) - 1767225600) / 10 ))', $apk);
        $this->assertStringContainsString('SERVIDOR="$DESTINO" npx cap sync android', $apk);
    }

    public function test_el_paquete_lleva_la_app_sin_depender_de_ella(): void
    {
        $paquete = $this->flujo('paquete.yml');

        $this->assertStringContainsString('uses: ./.github/workflows/apk.yml', $paquete);
        $this->assertStringContainsString("destino: \${{ github.ref_type == 'tag' && 'produccion' || 'qa' }}", $paquete);
        $this->assertMatchesRegularExpression('/needs: apk\s*\n\s*if: \$\{\{ !cancelled\(\) \}\}/', $paquete);
        $this->assertMatchesRegularExpression('/continue-on-error: true\s*\n\s*uses: actions\/download-artifact@/', $paquete);
        $this->assertStringContainsString('::warning title=Paquete sin app de Android::', $paquete);
        // El tar no excluye app-movil/ (sí movil/, el código de la app)
        $this->assertStringNotContainsString('--exclude=./app-movil', $paquete);
        $this->assertStringContainsString('--exclude=./movil', $paquete);

        $ci = $this->flujo('ci.yml');
        $this->assertStringContainsString('app-android:', $ci);
        $this->assertStringContainsString("if: github.event_name == 'pull_request' || github.event_name == 'workflow_dispatch'", $ci);
        foreach (self::SECRETOS as $secreto) {
            $this->assertSame(2, substr_count($ci, "{$secreto}: \${{ secrets.{$secreto} }}"), "ci.yml debe pasar {$secreto} al paquete y a la app de prueba");
        }
    }

    public function test_la_llave_nunca_va_en_el_repositorio(): void
    {
        $raiz = dirname(__DIR__, 3);
        $this->assertStringContainsString("*.jks\n*.keystore", (string) file_get_contents($raiz.'/.gitignore'));
        exec('git -C '.escapeshellarg($raiz).' ls-files 2>/dev/null', $archivos, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('Sin git para revisar los archivos del repositorio');
        }
        foreach ($archivos as $archivo) {
            $this->assertDoesNotMatchRegularExpression('/\.(jks|keystore|p12|apk|aab)$|keystore\.properties$|google-services\.json$/i', $archivo, "{$archivo} no debe estar en el repositorio");
        }
        // Gradle firma solo con las variables de entorno
        $gradle = (string) file_get_contents($raiz.'/movil/android/app/build.gradle');
        $this->assertStringContainsString("System.getenv('ANDROID_KEYSTORE_FILE')", $gradle);
        $this->assertStringContainsString("System.getenv('ANDROID_KEYSTORE_PASSWORD')", $gradle);
        $this->assertStringContainsString('versionCode codigoVersion', $gradle);
    }
}
