<?php

namespace App\Services\Accesos;

use RuntimeException;

/**
 * Un cambio de estado que no aplica al acceso tal como está (por ejemplo,
 * autorizar algo que ya se autorizó o dar salida dos veces por un doble clic).
 * El mensaje es para el guardia.
 */
class MovimientoNoPermitido extends RuntimeException {}
