<?php

namespace App\Services\Candidatos;

use RuntimeException;

/**
 * Un cambio de etapa o una respuesta que ya no aplica (otra persona lo hizo
 * antes, doble clic, etapa que no lleva a esa). Se avisa en rojo sin tocar nada.
 */
class CambioNoPermitido extends RuntimeException {}
