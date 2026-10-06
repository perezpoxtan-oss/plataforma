<?php

use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\LostFoundArticulo;
use App\Models\Procedimiento;
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
        'llave' => Llave::class,
        'gafete' => Gafete::class,
        'equipo' => Equipo::class,
        'lost_found' => LostFoundArticulo::class,
        'equipo_pc' => EquipoPc::class,
        'procedimiento' => Procedimiento::class,
    ],
];
