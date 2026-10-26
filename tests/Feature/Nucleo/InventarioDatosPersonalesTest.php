<?php

namespace Tests\Feature\Nucleo;

use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\DatosDePersona;
use App\Services\Privacidad\InventarioDatosPersonales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C-37 (X-01): toda tabla con datos personales está registrada en el
 * inventario de privacidad, con su trato ARCO y su plazo de conservación.
 * Si alguien agrega una tabla (o una columna) cuyo nombre parece un dato
 * personal y no la registra en InventarioDatosPersonales, esta prueba falla
 * y dice cuál y qué hacer (docs/tecnico/privacidad.md, «Inventario»).
 */
class InventarioDatosPersonalesTest extends TestCase
{
    use RefreshDatabase;

    private const TRATOS = ['arco', 'referencia', 'laboral', 'cuenta', 'plataforma', 'empresa', 'sin_datos'];

    /** @return array<string, list<string>> tabla => columnas */
    private function esquema(): array
    {
        $tablas = [];
        foreach (Schema::getTables() as $t) {
            if (! in_array($t['name'], InventarioDatosPersonales::SISTEMA, true)) {
                $tablas[$t['name']] = Schema::getColumnListing($t['name']);
            }
        }

        return $tablas;
    }

    public function test_toda_tabla_con_datos_personales_esta_en_el_inventario(): void
    {
        $faltan = [];
        foreach ($this->esquema() as $tabla => $columnas) {
            $personales = InventarioDatosPersonales::columnasPersonales($columnas);
            if ($personales === []) {
                continue;
            }
            $registradas = InventarioDatosPersonales::TABLAS[$tabla]['columnas'] ?? null;
            if ($registradas === null) {
                $faltan[] = "{$tabla} (".implode(', ', $personales).')';

                continue;
            }
            $sinRegistrar = array_diff($personales, $registradas);
            if ($sinRegistrar !== []) {
                $faltan[] = "{$tabla}: ".implode(', ', $sinRegistrar);
            }
        }

        $this->assertSame([], $faltan, "Tablas o columnas con datos personales sin registrar en App\\Services\\Privacidad\\InventarioDatosPersonales::TABLAS.\n"
            .'Decide si las alcanza ARCO (y agrégalas a DatosDePersona), si son de personal de la empresa, de una cuenta o no son datos personales, y regístralas con su plazo de conservación.');
    }

    public function test_el_inventario_es_coherente_con_la_base_arco_y_la_conservacion(): void
    {
        $esquema = $this->esquema();
        foreach (InventarioDatosPersonales::TABLAS as $tabla => $d) {
            $this->assertArrayHasKey($tabla, $esquema, "La tabla {$tabla} del inventario ya no existe.");
            $this->assertContains($d['trato'], self::TRATOS, $tabla);
            $this->assertSame([], array_values(array_diff($d['columnas'], $esquema[$tabla])), "Columnas que no existen en {$tabla}.");
            $this->assertNotSame('', trim($d['conservacion']), "{$tabla}: falta decir cómo se conserva.");
            if (in_array($d['trato'], ['arco', 'referencia'], true)) {
                $this->assertArrayHasKey($d['tipo'] ?? '', DatosDePersona::TIPOS, "{$tabla}: su tipo ARCO no existe en DatosDePersona::TIPOS.");
            }
            // Si dice que la alcanza un plazo de conservación, el plazo existe
            $clave = strtok($d['conservacion'], ' ');
            if (str_ends_with((string) $clave, '_dias')) {
                $this->assertArrayHasKey($clave, ConservacionDatos::DIAS, "{$tabla}: el plazo {$clave} no existe.");
            }
        }
        // Cada tipo que busca ARCO tiene al menos una tabla en el inventario
        $this->assertSame([], array_values(array_diff(array_keys(DatosDePersona::TIPOS), array_keys(InventarioDatosPersonales::porTipo()))));
        // Los módulos que antes quedaban fuera (X-01) están cubiertos por ARCO
        foreach (['lost_found_reportes_perdida', 'lost_found_entregas', 'novedades', 'accidente_huespedes', 'accidente_dictamenes', 'reservas', 'movimientos_transporte'] as $tabla) {
            $this->assertContains(InventarioDatosPersonales::TABLAS[$tabla]['trato'], ['arco', 'referencia'], $tabla);
        }
    }

    public function test_el_patron_reconoce_una_tabla_nueva_con_datos_personales(): void
    {
        // Así fallaría la prueba si mañana se crea, por ejemplo, una tabla de paquetería sin registrarla
        $this->assertSame(['destinatario_nombre', 'telefono_contacto', 'firma_recibe'],
            InventarioDatosPersonales::columnasPersonales(['id', 'empresa_id', 'destinatario_nombre', 'telefono_contacto', 'firma_recibe', 'guia', 'created_at']));
        $this->assertArrayNotHasKey('paqueteria_entregas', InventarioDatosPersonales::TABLAS);
        $this->assertSame([], InventarioDatosPersonales::columnasPersonales(['id', 'empresa_id', 'sede_id', 'estado', 'monto', 'creado_por']));
    }
}
