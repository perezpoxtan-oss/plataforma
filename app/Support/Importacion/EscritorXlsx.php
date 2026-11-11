<?php

namespace App\Support\Importacion;

use ZipArchive;

/**
 * Escribe un libro de Excel (.xlsx) sencillo sin dependencias externas
 * (ZipArchive, igual que LectorArchivo): varias hojas, textos en línea
 * (un «101» o un «0012» se quedan como texto, nunca como número ni
 * fórmula), primer renglón en negritas y fijo, anchos de columna y texto
 * ajustado. Lo usa la plantilla y el respaldo de «Importar estructura».
 *
 * La PRIMERA hoja es la de datos: es la única que lee LectorArchivo.
 */
final class EscritorXlsx
{
    /** Estilos: 0 normal, 1 encabezado, 2 texto ajustado, 3 título de instrucciones. */
    public const NORMAL = 0;

    public const ENCABEZADO = 1;

    public const AJUSTADO = 2;

    public const TITULO = 3;

    /** @var list<array{nombre: string, renglones: list<list<string>>, anchos: list<int>, estilos: array<int, int>, fijarEncabezado: bool}> */
    private array $hojas = [];

    public static function disponible(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @param  list<list<string|int|null>>  $renglones
     * @param  list<int>  $anchos  ancho de cada columna (en caracteres)
     * @param  array<int, int>  $estilos  renglón (base 0) => estilo para todo el renglón
     */
    public function hoja(string $nombre, array $renglones, array $anchos = [], array $estilos = [], bool $fijarEncabezado = true): self
    {
        // Excel: hasta 31 caracteres y sin : \ / ? * [ ]
        $nombre = mb_substr((string) preg_replace('/[:\\\\\/?*\[\]]/u', ' ', $nombre), 0, 31);
        $this->hojas[] = [
            'nombre' => $nombre,
            'renglones' => array_map(fn ($r) => array_map(fn ($c) => (string) ($c ?? ''), array_values($r)), $renglones),
            'anchos' => $anchos,
            'estilos' => $estilos,
            'fijarEncabezado' => $fijarEncabezado,
        ];

        return $this;
    }

    /**
     * Guarda el libro en $ruta.
     */
    public function guardar(string $ruta): void
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el libro de Excel.');
        }

        $hojas = $this->hojas === [] ? [['nombre' => 'Hoja1', 'renglones' => [], 'anchos' => [], 'estilos' => [], 'fijarEncabezado' => false]] : $this->hojas;
        $tipos = '';
        $relaciones = '';
        $lista = '';
        foreach ($hojas as $i => $hoja) {
            $n = $i + 1;
            $tipos .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $relaciones .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $lista .= '<sheet name="'.self::xml($hoja['nombre']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $this->hojaXml($hoja));
        }
        $estilos = count($hojas) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$tipos.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$lista.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relaciones
            .'<Relationship Id="rId'.$estilos.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->close();
    }

    /**
     * @param  array{nombre: string, renglones: list<list<string>>, anchos: list<int>, estilos: array<int, int>, fijarEncabezado: bool}  $hoja
     */
    private function hojaXml(array $hoja): string
    {
        $vista = $hoja['fijarEncabezado']
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        $columnas = '';
        foreach ($hoja['anchos'] as $i => $ancho) {
            $columnas .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.max(4, min(120, $ancho)).'" customWidth="1"/>';
        }

        $filas = '';
        foreach ($hoja['renglones'] as $r => $celdas) {
            $estilo = $hoja['estilos'][$r] ?? ($r === 0 && $hoja['fijarEncabezado'] ? self::ENCABEZADO : self::NORMAL);
            $filas .= '<row r="'.($r + 1).'">';
            foreach ($celdas as $c => $valor) {
                if ($valor === '') {
                    continue;
                }
                $filas .= '<c r="'.self::columna($c).($r + 1).'" t="inlineStr"'.($estilo !== self::NORMAL ? ' s="'.$estilo.'"' : '').'>'
                    .'<is><t xml:space="preserve">'.self::xml($valor).'</t></is></c>';
            }
            $filas .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$vista.'<sheetFormatPr defaultRowHeight="15"/>'.($columnas !== '' ? '<cols>'.$columnas.'</cols>' : '')
            .'<sheetData>'.$filas.'</sheetData></worksheet>';
    }

    private static function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            .'<xf numFmtId="49" fontId="2" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** 0 → A, 25 → Z, 26 → AA */
    private static function columna(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + (($n - 1) % 26)).$letras;
        }

        return $letras;
    }

    /** Texto seguro para XML (sin caracteres de control que Excel rechaza). */
    private static function xml(string $texto): string
    {
        $texto = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $texto);

        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
