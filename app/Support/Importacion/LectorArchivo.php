<?php

namespace App\Support\Importacion;

use ZipArchive;

/**
 * Lee el archivo de «Importar desde Excel» sin dependencias externas:
 *
 *  - CSV en UTF-8 (con o sin BOM) o Windows-1252 (lo que guarda Excel en
 *    español como «CSV (delimitado por comas)»); el separador «,», «;» o
 *    tabulador se detecta en el renglón de encabezados.
 *  - XLSX con un lector mínimo (ZipArchive + SimpleXML): solo la PRIMERA hoja,
 *    textos compartidos y en línea, números, VERDADERO/FALSO y fechas con
 *    formato de fecha (de serie de Excel a AAAA-MM-DD). Las fórmulas toman
 *    el último valor que Excel guardó; celdas combinadas, varias hojas,
 *    imágenes y comentarios se ignoran.
 *
 * Devuelve el encabezado (renglón 1) y las filas con su número de renglón
 * real, sin los renglones vacíos.
 */
final class LectorArchivo
{
    /** Tope de lo que puede ocupar el XLSX ya descomprimido (contra archivos «bomba»). */
    public const MAX_DESCOMPRIMIDO = 40 * 1024 * 1024;

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Formatos de número que Excel trae de fábrica y son fechas. */
    private const FORMATOS_FECHA = [14, 15, 16, 17, 22, 27, 28, 29, 30, 31, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /**
     * @return array{encabezados: list<string>, filas: list<array{fila: int, celdas: list<string>}>}
     */
    public static function leer(string $ruta, string $formato): array
    {
        $renglones = $formato === 'xlsx' ? self::xlsx($ruta) : self::csv((string) file_get_contents($ruta));

        if ($renglones === []) {
            throw new ArchivoNoValido('El archivo está vacío: no tiene ni el renglón de encabezados.');
        }
        ksort($renglones);
        $primero = array_key_first($renglones);
        $encabezados = array_map(fn ($c) => trim((string) $c), $renglones[$primero]);
        unset($renglones[$primero]);

        $filas = [];
        foreach ($renglones as $numero => $celdas) {
            $celdas = array_map(fn ($c) => trim((string) preg_replace('/[\x{00A0}\s]+/u', ' ', (string) $c)), $celdas);
            if (implode('', $celdas) === '') {
                continue;
            }
            $filas[] = ['fila' => (int) $numero, 'celdas' => array_values($celdas)];
        }

        return ['encabezados' => $encabezados, 'filas' => $filas];
    }

    // -------------------------------------------------------------------- CSV

    /**
     * @return array<int, list<string>> renglón => celdas
     */
    public static function csv(string $contenido): array
    {
        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        } elseif (! mb_check_encoding($contenido, 'UTF-8')) {
            // Excel en español guarda «CSV (delimitado por comas)» en Windows-1252
            $contenido = (string) mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        // Saltos de línea de Mac antiguos (\r solo)
        $contenido = (string) preg_replace("/\r(?!\n)/", "\n", $contenido);
        if (trim($contenido) === '') {
            return [];
        }

        $separador = self::separador($contenido);
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $renglones = [];
        $numero = 0;
        while (($celdas = fgetcsv($flujo, null, $separador, '"', '')) !== false) {
            $numero++;
            if ($celdas === [null]) {
                continue;
            }
            $renglones[$numero] = array_map(fn ($c) => (string) $c, $celdas);
        }
        fclose($flujo);

        return $renglones;
    }

    /**
     * El separador que más aparece en el renglón de encabezados (fuera de comillas).
     */
    private static function separador(string $contenido): string
    {
        $primera = strtok($contenido, "\n");
        $sinComillas = (string) preg_replace('/"[^"]*"/', '', (string) $primera);
        $conteos = [',' => substr_count($sinComillas, ','), ';' => substr_count($sinComillas, ';'), "\t" => substr_count($sinComillas, "\t")];
        arsort($conteos);

        return reset($conteos) > 0 ? (string) array_key_first($conteos) : ',';
    }

    // ------------------------------------------------------------------- XLSX

    /**
     * @return array<int, list<string>> renglón => celdas
     */
    public static function xlsx(string $ruta): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ArchivoNoValido('Este servidor no puede leer archivos de Excel (.xlsx). Guárdalo como «CSV UTF-8» y súbelo de nuevo.');
        }
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new ArchivoNoValido('El archivo no es un libro de Excel (.xlsx) válido. Ábrelo en Excel y guárdalo de nuevo, o guárdalo como «CSV UTF-8».');
        }

        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            if ($zip->numFiles > 2000 || $total > self::MAX_DESCOMPRIMIDO) {
                throw new ArchivoNoValido('El libro de Excel es demasiado grande. Deja solo la hoja con los datos o divídelo en varios archivos.');
            }

            $hoja = self::rutaPrimeraHoja($zip);
            $xmlHoja = $zip->getFromName($hoja);
            if ($xmlHoja === false) {
                throw new ArchivoNoValido('No encontramos la primera hoja del libro de Excel. Guárdalo de nuevo o como «CSV UTF-8».');
            }
            $textos = self::textosCompartidos($zip);
            $fechas = self::estilosDeFecha($zip);

            return self::celdasDeHoja($xmlHoja, $textos, $fechas);
        } finally {
            $zip->close();
        }
    }

    private static function xml(string $contenido): \SimpleXMLElement
    {
        $xml = @simplexml_load_string($contenido, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($xml === false) {
            throw new ArchivoNoValido('El libro de Excel está dañado. Ábrelo en Excel y guárdalo de nuevo, o guárdalo como «CSV UTF-8».');
        }

        return $xml;
    }

    private static function rutaPrimeraHoja(ZipArchive $zip): string
    {
        $porOmision = 'xl/worksheets/sheet1.xml';
        $libro = $zip->getFromName('xl/workbook.xml');
        $relaciones = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($libro === false || $relaciones === false) {
            return $porOmision;
        }

        $hojas = self::xml($libro)->children(self::NS)->sheets?->children(self::NS)->sheet;
        if ($hojas === null || count($hojas) === 0) {
            return $porOmision;
        }
        $id = (string) $hojas[0]->attributes(self::NS_REL)['id'];

        foreach (self::xml($relaciones)->children() as $relacion) {
            if (self::atributo($relacion, 'Id') === $id) {
                $destino = ltrim(self::atributo($relacion, 'Target'), '/');

                return str_starts_with($destino, 'xl/') ? $destino : 'xl/'.$destino;
            }
        }

        return $porOmision;
    }

    /**
     * @return list<string>
     */
    private static function textosCompartidos(ZipArchive $zip): array
    {
        $contenido = $zip->getFromName('xl/sharedStrings.xml');
        if ($contenido === false) {
            return [];
        }

        $textos = [];
        foreach (self::xml($contenido)->children(self::NS)->si as $si) {
            $textos[] = self::textoDe($si);
        }

        return $textos;
    }

    /** Texto de un <si> o <is>: <t> directo o la suma de sus partes con formato (<r><t>). */
    private static function textoDe(\SimpleXMLElement $nodo): string
    {
        $hijos = $nodo->children(self::NS);
        if (isset($hijos->t)) {
            return (string) $hijos->t;
        }
        $texto = '';
        foreach ($hijos->r as $parte) {
            $texto .= (string) $parte->children(self::NS)->t;
        }

        return $texto;
    }

    /**
     * Índices de estilo de celda (atributo s) que muestran una fecha.
     *
     * @return array<int, bool>
     */
    private static function estilosDeFecha(ZipArchive $zip): array
    {
        $contenido = $zip->getFromName('xl/styles.xml');
        if ($contenido === false) {
            return [];
        }
        $estilos = self::xml($contenido)->children(self::NS);

        $propios = [];
        if (isset($estilos->numFmts)) {
            foreach ($estilos->numFmts->children(self::NS)->numFmt as $formato) {
                $codigo = self::atributo($formato, 'formatCode');
                // Sin textos entre comillas ni [colores]: ¿lleva día o año?
                $limpio = (string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $codigo);
                $propios[(int) self::atributo($formato, 'numFmtId')] = preg_match('/[dy]/i', $limpio) === 1;
            }
        }

        $fechas = [];
        if (isset($estilos->cellXfs)) {
            $i = 0;
            foreach ($estilos->cellXfs->children(self::NS)->xf as $xf) {
                $id = (int) self::atributo($xf, 'numFmtId');
                if (in_array($id, self::FORMATOS_FECHA, true) || ($propios[$id] ?? false)) {
                    $fechas[$i] = true;
                }
                $i++;
            }
        }

        return $fechas;
    }

    /**
     * @param  list<string>  $textos
     * @param  array<int, bool>  $fechas
     * @return array<int, list<string>>
     */
    private static function celdasDeHoja(string $contenido, array $textos, array $fechas): array
    {
        $datos = self::xml($contenido)->children(self::NS)->sheetData;
        if ($datos === null) {
            return [];
        }

        $renglones = [];
        $siguiente = 1;
        foreach ($datos->children(self::NS)->row as $row) {
            $numero = self::atributo($row, 'r') !== '' ? (int) self::atributo($row, 'r') : $siguiente;
            $siguiente = $numero + 1;
            $celdas = [];
            $columna = 0;
            foreach ($row->children(self::NS)->c as $c) {
                $columna = self::atributo($c, 'r') !== '' ? self::indiceColumna(self::atributo($c, 'r')) : $columna + 1;
                if ($columna < 1 || $columna > 200) {
                    continue;
                }
                $celdas[$columna] = self::valorCelda($c, $textos, $fechas);
            }
            if ($celdas === []) {
                continue;
            }
            $fila = array_fill(0, max(array_keys($celdas)), '');
            foreach ($celdas as $col => $valor) {
                $fila[$col - 1] = $valor;
            }
            $renglones[$numero] = $fila;
        }

        return $renglones;
    }

    /**
     * Atributo sin espacio de nombres (con children(NS), $nodo['x'] buscaría
     * el atributo en el espacio de nombres del libro y no lo encontraría).
     */
    private static function atributo(\SimpleXMLElement $nodo, string $nombre): string
    {
        $atributos = $nodo->attributes();

        return $atributos !== null && isset($atributos[$nombre]) ? (string) $atributos[$nombre] : '';
    }

    /** «C7» → 3 */
    private static function indiceColumna(string $referencia): int
    {
        $letras = (string) preg_replace('/[^A-Z]/', '', strtoupper($referencia));
        $indice = 0;
        foreach (str_split($letras) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice;
    }

    /**
     * @param  list<string>  $textos
     * @param  array<int, bool>  $fechas
     */
    private static function valorCelda(\SimpleXMLElement $c, array $textos, array $fechas): string
    {
        $tipo = self::atributo($c, 't') ?: 'n';
        $hijos = $c->children(self::NS);
        $valor = isset($hijos->v) ? (string) $hijos->v : '';

        return match ($tipo) {
            's' => $textos[(int) $valor] ?? '',
            'inlineStr' => isset($hijos->is) ? self::textoDe($hijos->is) : '',
            'str' => $valor,
            'b' => $valor === '1' ? 'VERDADERO' : 'FALSO',
            'e' => '',
            default => self::numero($valor, self::atributo($c, 's') !== '' && ($fechas[(int) self::atributo($c, 's')] ?? false)),
        };
    }

    /**
     * Números sin notación científica ni «.0» (un teléfono 9981234567 se
     * queda igual); las fechas de serie de Excel pasan a AAAA-MM-DD.
     */
    private static function numero(string $valor, bool $esFecha): string
    {
        if ($valor === '' || ! is_numeric($valor)) {
            return $valor;
        }
        $numero = (float) $valor;
        if ($esFecha && $numero >= 1 && $numero < 2958466) {
            // Serie de Excel (sistema 1900): el día 1 es 1900-01-01 y cuenta el 29/02/1900 que no existió
            $dias = (int) floor($numero);
            $fecha = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify("+{$dias} days");
            $segundos = (int) round(($numero - $dias) * 86400);

            return $segundos > 0 ? $fecha->modify("+{$segundos} seconds")->format('Y-m-d H:i') : $fecha->format('Y-m-d');
        }
        if (floor($numero) === $numero && abs($numero) < 1e15) {
            return sprintf('%.0f', $numero);
        }

        return rtrim(rtrim(sprintf('%.10F', $numero), '0'), '.');
    }
}
