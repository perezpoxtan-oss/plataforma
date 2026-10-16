<?php

use App\Http\Controllers\Operacion\Reservas\AprobacionReservaController;
use App\Http\Controllers\Operacion\Reservas\ConfiguracionReservasController;
use App\Http\Controllers\Operacion\Reservas\InformeReservasController;
use App\Http\Controllers\Operacion\Reservas\OrdenServicioController;
use App\Http\Controllers\Operacion\Reservas\ReservaController;
use Illuminate\Support\Facades\Route;

/*
| Reservas de áreas (docs/tecnico/reservas.md). Se incluye desde web.php
| dentro del grupo con sesión. Cada pantalla exige su «reservas.accion».
*/
Route::prefix('reservas')->name('reservas.')->group(function () {
    Route::controller(ReservaController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/calendario', 'calendario')->name('calendario');
        Route::get('/mis-reservas', 'mias')->name('mias');
        Route::get('/nueva', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:60,1')->name('store');
        Route::get('/disponibilidad', 'disponibilidad')->middleware('throttle:240,1')->name('disponibilidad');
        Route::get('/{reserva}', 'show')->whereNumber('reserva')->name('show');
        Route::get('/{reserva}/editar', 'edit')->whereNumber('reserva')->name('edit');
        Route::put('/{reserva}', 'update')->whereNumber('reserva')->name('update');
        Route::post('/{reserva}/cancelar', 'cancelar')->whereNumber('reserva')->name('cancelar');
        Route::put('/{reserva}/costo', 'costo')->whereNumber('reserva')->name('costo');
        Route::get('/{reserva}/imprimir', 'imprimir')->whereNumber('reserva')->name('imprimir');
        Route::get('/areas/{area}/foto', 'foto')->whereNumber('area')->name('foto');
    });
    Route::controller(AprobacionReservaController::class)->group(function () {
        Route::get('/por-aprobar', 'index')->name('aprobaciones');
        Route::post('/aprobaciones/{aprobacion}/responder', 'responder')->whereNumber('aprobacion')->name('aprobaciones.responder');
    });
    Route::controller(OrdenServicioController::class)->group(function () {
        Route::get('/ordenes', 'index')->name('ordenes');
        Route::post('/ordenes/{orden}/listo', 'listo')->whereNumber('orden')->name('ordenes.listo');
    });
    Route::controller(InformeReservasController::class)->group(function () {
        Route::get('/informes', 'index')->name('informes');
        Route::get('/informes/exportar', 'exportar')->name('informes.exportar');
    });
    Route::controller(ConfiguracionReservasController::class)->group(function () {
        Route::get('/configuracion', 'index')->name('configuracion');
        Route::post('/configuracion/areas', 'marcar')->name('configuracion.marcar');
        Route::get('/configuracion/areas/{area}', 'area')->whereNumber('area')->name('configuracion.area');
        Route::put('/configuracion/areas/{area}', 'guardarArea')->whereNumber('area')->name('configuracion.area.guardar');
        Route::delete('/configuracion/areas/{area}', 'quitar')->whereNumber('area')->name('configuracion.area.quitar');
        Route::patch('/configuracion/areas/{area}/estado', 'estadoArea')->whereNumber('area')->name('configuracion.area.estado');
        Route::post('/configuracion/catalogo', 'crearCatalogo')->name('configuracion.catalogo.crear');
        Route::put('/configuracion/catalogo/{catalogo}/{id}', 'actualizarCatalogo')->where('catalogo', 'montajes|tipos|servicios')->whereNumber('id')->name('configuracion.catalogo.actualizar');
        Route::patch('/configuracion/catalogo/{catalogo}/{id}/estado', 'estadoCatalogo')->where('catalogo', 'montajes|tipos|servicios')->whereNumber('id')->name('configuracion.catalogo.estado');
        Route::put('/ajustes', 'ajustes')->name('ajustes');
    });
});
