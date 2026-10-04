<?php

use App\Http\Controllers\Auth\SesionController;
use App\Http\Controllers\ModuloPendienteController;
use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

// Acceso
Route::middleware('guest')->group(function () {
    Route::get('/login', [SesionController::class, 'mostrar'])->name('login');
    Route::post('/login', [SesionController::class, 'iniciar'])->name('login.iniciar');
});

Route::get('/sesion/expirada', [SesionController::class, 'expirada'])->name('sesion.expirada');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SesionController::class, 'cerrar'])->name('logout');
    Route::get('/sesion/latido', [SesionController::class, 'latido'])->name('sesion.latido');

    Route::get('/', PanelController::class)->name('panel');

    // Modulos del menu que aun no se migran
    Route::get('/modulos/{clave}', ModuloPendienteController::class)
        ->where('clave', '[a-z0-9_]+')
        ->name('modulos.pendiente');
});
