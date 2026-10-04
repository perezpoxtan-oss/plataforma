<?php

use App\Http\Controllers\Administracion\EmpresaActivaController;
use App\Http\Controllers\Administracion\EmpresaController;
use App\Http\Controllers\Administracion\IdentidadController;
use App\Http\Controllers\Administracion\PermisoController;
use App\Http\Controllers\Administracion\RolController;
use App\Http\Controllers\Administracion\SedeController;
use App\Http\Controllers\Administracion\UsuarioController;
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

    // Administración: roles y permisos (cada pantalla exige su "modulo.accion")
    Route::post('/empresa-activa', EmpresaActivaController::class)->name('empresa-activa');
    Route::get('/roles', [RolController::class, 'index'])->name('roles.index');
    Route::post('/roles', [RolController::class, 'store'])->name('roles.store');
    Route::put('/roles/{rol}', [RolController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');
    Route::get('/permisos', [PermisoController::class, 'index'])->name('permisos.index');
    Route::put('/permisos/{rol}', [PermisoController::class, 'update'])->name('permisos.update');
    Route::get('/empresas', [EmpresaController::class, 'index'])->name('empresas.index');
    Route::post('/empresas', [EmpresaController::class, 'store'])->name('empresas.store');
    Route::put('/empresas/{empresa}', [EmpresaController::class, 'update'])->name('empresas.update');
    Route::patch('/empresas/{empresa}/estado', [EmpresaController::class, 'estado'])->name('empresas.estado');
    Route::get('/sedes', [SedeController::class, 'index'])->name('sedes.index');
    Route::post('/sedes', [SedeController::class, 'store'])->name('sedes.store');
    Route::put('/sedes/{sede}', [SedeController::class, 'update'])->whereNumber('sede')->name('sedes.update');
    Route::patch('/sedes/{sede}/estado', [SedeController::class, 'estado'])->whereNumber('sede')->name('sedes.estado');
    Route::get('/identidad', [IdentidadController::class, 'edit'])->name('identidad.edit');
    Route::put('/identidad', [IdentidadController::class, 'update'])->name('identidad.update');
    Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    Route::patch('/usuarios/{usuario}/estado', [UsuarioController::class, 'estado'])->name('usuarios.estado');

    // Modulos del menu que aun no se migran
    Route::get('/modulos/{clave}', ModuloPendienteController::class)
        ->where('clave', '[a-z0-9_]+')
        ->name('modulos.pendiente');
});
