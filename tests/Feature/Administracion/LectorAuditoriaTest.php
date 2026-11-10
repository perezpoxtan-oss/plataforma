<?php

namespace Tests\Feature\Administracion;

use App\Models\Modulo;
use App\Services\Auditoria\LectorAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Bitácora de auditoría: cada evento que el código registra («modulo.accion»)
 * se muestra con su etiqueta en español y con acentos, sin caer en el
 * respaldo automático (la clave con mayúscula inicial).
 */
class LectorAuditoriaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    /**
     * Eventos que se pasan a la bitácora en app/: segundo argumento de
     * auditar(), 'evento' => '…' al escribir la bitácora directo, la tabla
     * AVANCE de pases de salida y los «$evento = match» (vacantes).
     *
     * @return list<string> «modulo.accion» o «.accion» cuando el módulo se arma en tiempo de ejecución
     */
    private function eventosDelCodigo(): array
    {
        $eventos = [];
        $literales = function (string $texto) use (&$eventos): void {
            preg_match_all("/'([a-z_]*\.[a-z_]+)'/", $texto, $l);
            foreach ($l[1] as $e) {
                $eventos[$e] = true;
            }
        };

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $archivo) {
            if (! str_ends_with((string) $archivo, '.php')) {
                continue;
            }
            $codigo = (string) file_get_contents((string) $archivo);
            // auditar($actor, <evento>, … — el evento no lleva comas (literal, ternario o concatenación)
            preg_match_all('/auditar\(\s*[^,]+,\s*((?:[^,;()]|\([^()]*\))+?)\s*,/s', $codigo, $m);
            array_map($literales, $m[1]);
            preg_match_all("/'evento' => ([^,\n]+)/", $codigo, $m);
            array_map($literales, array_filter($m[1], fn ($v) => str_contains($v, "'")));
            preg_match_all('/const AVANCE = \[(.*?)\];/s', $codigo, $m);
            array_map($literales, $m[1]);
            preg_match_all('/\$evento = match \(.*?\{(.*?)\};/s', $codigo, $m);
            array_map($literales, $m[1]);
        }
        ksort($eventos);

        return array_keys($eventos);
    }

    public function test_cada_evento_del_codigo_tiene_etiqueta_en_espanol(): void
    {
        $eventos = $this->eventosDelCodigo();
        $this->assertGreaterThan(150, count($eventos), 'La búsqueda de eventos no encontró los del código');
        // Muestras de cada forma de registrar: literal, ternario, concatenado, AVANCE y match
        foreach (['pases_salida.firmado', 'usuarios.reactivado', '.verificado', '.eliminado_definitivo', 'pases_salida.salida_de_regreso', 'vacantes.reanudada', 'candidatos.postulacion'] as $muestra) {
            $this->assertContains($muestra, $eventos);
        }

        $catalogo = Modulo::pluck('clave')->all();
        $sinEtiqueta = [];
        $sinModulo = [];
        foreach ($eventos as $evento) {
            [$modulo, $accion] = explode('.', $evento, 2);
            if (! isset(LectorAuditoria::ACCIONES[$accion])) {
                $sinEtiqueta[] = $evento;
            }
            if ($modulo !== '' && ! in_array($modulo, $catalogo, true) && ! isset(LectorAuditoria::MODULOS_EXTRA[$modulo])) {
                $sinModulo[] = $evento;
            }
        }

        $this->assertSame([], $sinEtiqueta, 'Acciones sin etiqueta en LectorAuditoria::ACCIONES');
        $this->assertSame([], $sinModulo, 'Módulos sin nombre legible (agrégalos a LectorAuditoria::MODULOS_EXTRA)');
    }

    public function test_etiquetas_con_acentos_y_modulos_fuera_del_catalogo(): void
    {
        $lector = app(LectorAuditoria::class);

        $this->assertSame('Aprobación', $lector->accion('pases_salida.aprobado'));
        $this->assertSame('Firma', $lector->accion('pases_salida.firmado'));
        $this->assertSame('Acuse de recibo', $lector->accion('procedimientos.acuse_firmado'));
        $this->assertSame('Publicación', $lector->accion('vacantes.publicada'));
        $this->assertSame('Eliminación definitiva', $lector->accion('llaves.eliminado_definitivo'));
        $this->assertSame('Pases de salida', $lector->modulo('pases_salida.aprobado'));
        $this->assertSame('Inicio de sesión', $lector->modulo('sesion.inicio'));
        $this->assertSame('Kiosco de candidatos', $lector->modulo('kiosco.autocaptura'));
        // Respaldo para algo desconocido: legible, sin guiones bajos
        $this->assertSame('Algo nuevo', $lector->accion('x.algo_nuevo'));
        $this->assertSame('Otro modulo', $lector->modulo('otro_modulo.creado'));

        // Ninguna etiqueta quedó sin acentos donde el español los lleva
        foreach (LectorAuditoria::ACCIONES as $clave => $texto) {
            $this->assertDoesNotMatchRegularExpression('/(cion|Aprobacion|Edicion|Eliminacion)\b/u', $texto, "La etiqueta de «{$clave}» va sin acento");
        }
    }
}
