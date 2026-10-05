<?php

use App\Models\Colaborador;
use App\Models\Gafete;
use App\Models\Vehiculo;

/*
|--------------------------------------------------------------------------
| Lector universal (QR, NFC, RFID, código de barras)
|--------------------------------------------------------------------------
| Tipos de registro que se pueden encontrar leyendo una etiqueta. Cada clase
| implementa App\Support\Lector\Identificable. Cada módulo agrega aquí su
| línea al crearse (llave, gafete, equipo…).
*/

return [
    'tipos' => [
        'colaborador' => Colaborador::class,
        'vehiculo' => Vehiculo::class,
        'gafete' => Gafete::class,
    ],
];
