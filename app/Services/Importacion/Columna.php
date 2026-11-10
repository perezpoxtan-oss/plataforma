<?php

namespace App\Services\Importacion;

/**
 * Una columna de la plantilla de «Importar desde Excel».
 */
final class Columna
{
    /**
     * @param  string  $clave  nombre interno (y el campo del servicio cuando coincide)
     * @param  string  $titulo  encabezado en español de la plantilla
     * @param  string  $ayuda  qué va y qué valores acepta (hoja de instrucciones del manual)
     * @param  list<string>  $alias  otros encabezados que se aceptan
     * @param  list<string>  $campos  campos del servicio cuyos errores se muestran en esta celda
     */
    public function __construct(
        public readonly string $clave,
        public readonly string $titulo,
        public readonly bool $obligatoria = false,
        public readonly string $ejemplo = '',
        public readonly string $ayuda = '',
        public readonly bool $personal = false,
        public readonly array $alias = [],
        public readonly array $campos = [],
    ) {}

    /** Encabezado como va en la plantilla (las obligatorias llevan «*»). */
    public function encabezado(): string
    {
        return $this->titulo.($this->obligatoria ? ' *' : '');
    }

    /** Para comparar encabezados sin acentos, mayúsculas, espacios ni signos. */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/[^a-z0-9]/', '', $texto);
    }
}
