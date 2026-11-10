<?php

use App\Http\Controllers\Publico\EquiposAcceso\HikvisionController;
use App\Http\Controllers\Publico\EquiposAcceso\ZktecoController;
use Illuminate\Support\Facades\Route;

/*
| Decisión 10: llamadas de los equipos de acceso (sin sesión, sin cookies, sin
| CSRF). Se registran fuera del grupo «web» (bootstrap/app.php): el equipo no
| maneja sesión. Cada uno se autentica (ZKTeco: número de serie registrado y
| su IP si se configuró; Hikvision: token del equipo) y tiene su límite con
| nombre por equipo (AppServiceProvider::limitadoresEquipos).
| Ver docs/tecnico/equipos-acceso.md.
*/

// ZKTeco · protocolo PUSH («ADMS»): rutas fijas que el equipo trae de fábrica
Route::match(['GET', 'POST'], '/iclock/cdata', [ZktecoController::class, 'cdata'])->middleware('throttle:equipos.zkteco.cdata')->name('equipos.zkteco.cdata');
Route::get('/iclock/getrequest', [ZktecoController::class, 'getrequest'])->middleware('throttle:equipos.zkteco.getrequest')->name('equipos.zkteco.getrequest');
Route::post('/iclock/devicecmd', [ZktecoController::class, 'devicecmd'])->middleware('throttle:equipos.zkteco.devicecmd')->name('equipos.zkteco.devicecmd');

// Hikvision · notificación de eventos ISAPI («Escucha HTTP»): el token del equipo en la ruta o en X-Equipo-Token
Route::post('/equipos-acceso/hikvision/{token?}', HikvisionController::class)->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:equipos.hikvision.eventos')->name('equipos.hikvision.eventos');
