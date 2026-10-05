<?php

use App\Http\Controllers\Administracion\AuditoriaController;
use App\Http\Controllers\Administracion\ConfiguracionController;
use App\Http\Controllers\Administracion\EmpresaActivaController;
use App\Http\Controllers\Administracion\EmpresaController;
use App\Http\Controllers\Administracion\IdentidadController;
use App\Http\Controllers\Administracion\PermisoController;
use App\Http\Controllers\Administracion\RolController;
use App\Http\Controllers\Administracion\SedeController;
use App\Http\Controllers\Administracion\UsuarioController;
use App\Http\Controllers\Auth\SesionController;
use App\Http\Controllers\LectorController;
use App\Http\Controllers\ModuloPendienteController;
use App\Http\Controllers\Organizacion\ColaboradorController;
use App\Http\Controllers\Organizacion\DepartamentoController;
use App\Http\Controllers\Organizacion\EspacioController;
use App\Http\Controllers\Organizacion\PuestoController;
use App\Http\Controllers\Organizacion\TurnoController;
use App\Http\Controllers\Padrones\ProveedorController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\Seguridad\GafeteController;
use App\Http\Controllers\Seguridad\PersonaController;
use App\Http\Controllers\Seguridad\VehiculoController;
use App\Http\Controllers\Seguridad\VoucherController;
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
    // Configuración: correo y respaldos (plataforma) y avisos (empresa)
    Route::get('/configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
    Route::put('/configuracion/correo', [ConfiguracionController::class, 'correo'])->name('configuracion.correo');
    Route::post('/configuracion/correo/prueba', [ConfiguracionController::class, 'probarCorreo'])->middleware('throttle:5,1')->name('configuracion.correo.prueba');
    Route::put('/configuracion/avisos', [ConfiguracionController::class, 'avisos'])->name('configuracion.avisos');
    Route::post('/configuracion/respaldos', [ConfiguracionController::class, 'respaldar'])->middleware('throttle:3,1')->name('configuracion.respaldos');
    Route::get('/configuracion/respaldos/{archivo}', [ConfiguracionController::class, 'descargar'])->where('archivo', '[A-Za-z0-9_.-]+')->name('configuracion.respaldos.descargar');
    Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
    Route::get('/auditoria/exportar', [AuditoriaController::class, 'exportar'])->name('auditoria.exportar');
    Route::get('/sedes', [SedeController::class, 'index'])->name('sedes.index');
    Route::post('/sedes', [SedeController::class, 'store'])->name('sedes.store');
    Route::put('/sedes/{sede}', [SedeController::class, 'update'])->whereNumber('sede')->name('sedes.update');
    Route::patch('/sedes/{sede}/estado', [SedeController::class, 'estado'])->whereNumber('sede')->name('sedes.estado');
    // Departamentos y Puestos
    Route::get('/departamentos', [DepartamentoController::class, 'index'])->name('departamentos.index');
    Route::post('/departamentos', [DepartamentoController::class, 'store'])->name('departamentos.store');
    Route::put('/departamentos/{departamento}', [DepartamentoController::class, 'update'])->whereNumber('departamento')->name('departamentos.update');
    Route::patch('/departamentos/{departamento}/estado', [DepartamentoController::class, 'estado'])->whereNumber('departamento')->name('departamentos.estado');
    Route::get('/puestos', [PuestoController::class, 'index'])->name('puestos.index');
    Route::post('/puestos', [PuestoController::class, 'store'])->name('puestos.store');
    Route::put('/puestos/{puesto}', [PuestoController::class, 'update'])->whereNumber('puesto')->name('puestos.update');
    Route::patch('/puestos/{puesto}/estado', [PuestoController::class, 'estado'])->whereNumber('puesto')->name('puestos.estado');
    Route::get('/turnos', [TurnoController::class, 'index'])->name('turnos.index');
    Route::post('/turnos', [TurnoController::class, 'store'])->name('turnos.store');
    Route::put('/turnos/{turno}', [TurnoController::class, 'update'])->whereNumber('turno')->name('turnos.update');
    Route::put('/turnos/{turno}/sedes', [TurnoController::class, 'sedes'])->whereNumber('turno')->name('turnos.sedes');
    Route::patch('/turnos/{turno}/estado', [TurnoController::class, 'estado'])->whereNumber('turno')->name('turnos.estado');
    // Colaboradores (y el registro rápido y la búsqueda que usan otros módulos)
    Route::get('/colaboradores', [ColaboradorController::class, 'index'])->name('colaboradores.index');
    Route::post('/colaboradores', [ColaboradorController::class, 'store'])->name('colaboradores.store');
    Route::get('/colaboradores/buscar', [ColaboradorController::class, 'buscar'])->name('colaboradores.buscar');
    Route::post('/colaboradores/rapido', [ColaboradorController::class, 'rapido'])->name('colaboradores.rapido');
    Route::put('/colaboradores/{colaborador}', [ColaboradorController::class, 'update'])->whereNumber('colaborador')->name('colaboradores.update');
    Route::patch('/colaboradores/{colaborador}/estado', [ColaboradorController::class, 'estado'])->whereNumber('colaborador')->name('colaboradores.estado');
    Route::put('/colaboradores/{colaborador}/validar', [ColaboradorController::class, 'validar'])->whereNumber('colaborador')->name('colaboradores.validar');
    Route::put('/colaboradores/{colaborador}/fusionar', [ColaboradorController::class, 'fusionar'])->whereNumber('colaborador')->name('colaboradores.fusionar');
    Route::put('/colaboradores/{colaborador}/sedes', [ColaboradorController::class, 'sedes'])->whereNumber('colaborador')->name('colaboradores.sedes');
    Route::get('/colaboradores/{colaborador}/datos-personales', [ColaboradorController::class, 'datosPersonales'])->whereNumber('colaborador')->name('colaboradores.datos-personales');

    // Padrones: Proveedores (y la búsqueda y el alta rápida que usan otros módulos)
    Route::get('/proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
    Route::post('/proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
    Route::get('/proveedores/buscar', [ProveedorController::class, 'buscar'])->name('proveedores.buscar');
    Route::post('/proveedores/rapido', [ProveedorController::class, 'rapido'])->name('proveedores.rapido');
    Route::get('/proveedores/{proveedor}', [ProveedorController::class, 'show'])->whereNumber('proveedor')->name('proveedores.show');
    Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])->whereNumber('proveedor')->name('proveedores.update');
    Route::put('/proveedores/{proveedor}/sedes', [ProveedorController::class, 'sedes'])->whereNumber('proveedor')->name('proveedores.sedes');
    Route::patch('/proveedores/{proveedor}/estado', [ProveedorController::class, 'estado'])->whereNumber('proveedor')->name('proveedores.estado');
    // Fin Padrones: Proveedores
    // Padrones: Personas (y la búsqueda y el registro rápido de la Bitácora de accesos)
    Route::get('/personas', [PersonaController::class, 'index'])->name('personas.index');
    Route::post('/personas', [PersonaController::class, 'store'])->name('personas.store');
    Route::get('/personas/buscar', [PersonaController::class, 'buscar'])->name('personas.buscar');
    Route::post('/personas/rapido', [PersonaController::class, 'rapido'])->name('personas.rapido');
    Route::put('/personas/{persona}', [PersonaController::class, 'update'])->whereNumber('persona')->name('personas.update');
    Route::patch('/personas/{persona}/estado', [PersonaController::class, 'estado'])->whereNumber('persona')->name('personas.estado');
    // Padrones: Vehículos (y la búsqueda y el registro rápido que usarán Accesos y Estacionamientos)
    Route::get('/vehiculos', [VehiculoController::class, 'index'])->name('vehiculos.index');
    Route::post('/vehiculos', [VehiculoController::class, 'store'])->name('vehiculos.store');
    Route::get('/vehiculos/buscar', [VehiculoController::class, 'buscar'])->name('vehiculos.buscar');
    Route::post('/vehiculos/rapido', [VehiculoController::class, 'rapido'])->middleware('throttle:30,1')->name('vehiculos.rapido');
    Route::get('/vehiculos/qr/{codigo}', [VehiculoController::class, 'qr'])->where('codigo', '[A-Za-z0-9]{8,32}')->name('vehiculos.qr');
    Route::put('/vehiculos/{vehiculo}', [VehiculoController::class, 'update'])->whereNumber('vehiculo')->name('vehiculos.update');
    Route::patch('/vehiculos/{vehiculo}/estado', [VehiculoController::class, 'estado'])->whereNumber('vehiculo')->name('vehiculos.estado');
    Route::get('/vehiculos/{vehiculo}/calcomania', [VehiculoController::class, 'calcomania'])->whereNumber('vehiculo')->name('vehiculos.calcomania');

    // Zonas y áreas (árbol de espacios)
    Route::get('/espacios', [EspacioController::class, 'index'])->name('espacios.index');
    Route::post('/espacios', [EspacioController::class, 'store'])->name('espacios.store');
    Route::post('/espacios/tipos', [EspacioController::class, 'tipo'])->name('espacios.tipo');
    Route::post('/espacios/secciones', [EspacioController::class, 'seccion'])->name('espacios.seccion');
    Route::put('/espacios/secciones/{grupo}', [EspacioController::class, 'asignarSeccion'])->whereNumber('grupo')->name('espacios.seccion.asignar');
    Route::get('/espacios/{espacio}', [EspacioController::class, 'show'])->whereNumber('espacio')->name('espacios.show');
    Route::put('/espacios/{espacio}', [EspacioController::class, 'update'])->whereNumber('espacio')->name('espacios.update');
    Route::patch('/espacios/{espacio}/estado', [EspacioController::class, 'estado'])->whereNumber('espacio')->name('espacios.estado');
    Route::post('/espacios/{espacio}/lote', [EspacioController::class, 'lote'])->whereNumber('espacio')->name('espacios.lote');
    Route::post('/espacios/{espacio}/copiar-pisos', [EspacioController::class, 'copiarPisos'])->whereNumber('espacio')->name('espacios.copiar');

    Route::get('/identidad', [IdentidadController::class, 'edit'])->name('identidad.edit');
    Route::put('/identidad', [IdentidadController::class, 'update'])->name('identidad.update');
    Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    Route::patch('/usuarios/{usuario}/estado', [UsuarioController::class, 'estado'])->name('usuarios.estado');
    Route::patch('/usuarios/{usuario}/desbloquear', [UsuarioController::class, 'desbloquear'])->name('usuarios.desbloquear');

    // Modulos del menu que aun no se migran
    Route::get('/modulos/{clave}', ModuloPendienteController::class)
        ->where('clave', '[a-z0-9_]+')
        ->name('modulos.pendiente');

    // Padrones: Gafetes y Vouchers de reposición
    Route::get('/gafetes', [GafeteController::class, 'index'])->name('gafetes.index');
    Route::post('/gafetes/lote', [GafeteController::class, 'lote'])->name('gafetes.lote');
    Route::match(['get', 'post'], '/gafetes/imprimir', [GafeteController::class, 'imprimir'])->name('gafetes.imprimir');
    Route::put('/gafetes/{gafete}', [GafeteController::class, 'update'])->whereNumber('gafete')->name('gafetes.update');
    Route::post('/gafetes/{gafete}/baja', [GafeteController::class, 'baja'])->whereNumber('gafete')->name('gafetes.baja');
    Route::patch('/gafetes/{gafete}/reactivar', [GafeteController::class, 'reactivar'])->whereNumber('gafete')->name('gafetes.reactivar');
    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('/vouchers/{voucher}/imprimir', [VoucherController::class, 'imprimir'])->whereNumber('voucher')->name('vouchers.imprimir');
    // Fin Padrones: Gafetes y Vouchers de reposición

    // Lector universal: QR, NFC, RFID y código de barras (ver docs/tecnico/lector.md)
    Route::get('/lector/resolver', [LectorController::class, 'resolver'])->middleware('throttle:120,1')->name('lector.resolver');
    Route::get('/e/{codigo}', [LectorController::class, 'ir'])->where('codigo', '[A-Za-z0-9]{8,32}')->name('lector.ir');
    // Fin Lector universal
});
