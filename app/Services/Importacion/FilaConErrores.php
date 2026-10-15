<?php

namespace App\Services\Importacion;

/**
 * Uso interno: deshace lo que el servicio alcanzó a hacer con una fila que
 * ya traía errores (ver Importador::guardarOReportar()).
 */
final class FilaConErrores extends \RuntimeException {}
