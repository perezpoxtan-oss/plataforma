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
use App\Http\Controllers\IdentificacionController;
use App\Http\Controllers\LectorController;
use App\Http\Controllers\ModuloPendienteController;
use App\Http\Controllers\Operacion\PrestamoLlaveController;
use App\Http\Controllers\Operacion\ResponsivaController;
use App\Http\Controllers\Organizacion\ColaboradorController;
use App\Http\Controllers\Organizacion\DepartamentoController;
use App\Http\Controllers\Organizacion\EspacioController;
use App\Http\Controllers\Organizacion\PuestoController;
use App\Http\Controllers\Organizacion\TurnoController;
use App\Http\Controllers\Padrones\ProveedorController;
use App\Http\Controllers\Padrones\RutaController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\Publico\EmpleosController;
use App\Http\Controllers\RecursosHumanos\AutorizacionController;
use App\Http\Controllers\RecursosHumanos\CandidatoController;
use App\Http\Controllers\RecursosHumanos\KioscoController;
use App\Http\Controllers\RecursosHumanos\NotificacionController;
use App\Http\Controllers\RecursosHumanos\RecepcionController;
use App\Http\Controllers\RecursosHumanos\VacanteController;
use App\Http\Controllers\Seguridad\AccesoController;
use App\Http\Controllers\Seguridad\EquipoController;
use App\Http\Controllers\Seguridad\EstacionamientoController;
use App\Http\Controllers\Seguridad\GafeteController;
use App\Http\Controllers\Seguridad\LlaveController;
use App\Http\Controllers\Seguridad\PaseSalidaController;
use App\Http\Controllers\Seguridad\PersonaController;
use App\Http\Controllers\Seguridad\ProcedimientoController;
use App\Http\Controllers\Seguridad\TransporteController;
use App\Http\Controllers\Seguridad\VehiculoController;
use App\Http\Controllers\Seguridad\VoucherController;
use Illuminate\Support\Facades\Route;

// Acceso
Route::middleware('guest')->group(function () {
    Route::get('/login', [SesionController::class, 'mostrar'])->name('login');
    Route::post('/login', [SesionController::class, 'iniciar'])->name('login.iniciar');
});

// Seguridad: el cierre por inactividad es POST (con token); el GET solo muestra el aviso
Route::get('/sesion/expirada', [SesionController::class, 'avisoExpirada'])->name('sesion.expirada');
Route::post('/sesion/expirada', [SesionController::class, 'expirada'])->name('sesion.expirada.cerrar');

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

    // Padrones: Llaves (catálogo; el préstamo vive en Operación)
    Route::get('/llaves', [LlaveController::class, 'index'])->name('llaves.index');
    Route::post('/llaves', [LlaveController::class, 'store'])->name('llaves.store');
    Route::get('/llaves/exportar', [LlaveController::class, 'exportar'])->name('llaves.exportar');
    Route::get('/llaves/etiquetas', [LlaveController::class, 'imprimir'])->name('llaves.imprimir');
    Route::put('/llaves/{llave}', [LlaveController::class, 'update'])->whereNumber('llave')->name('llaves.update');
    Route::post('/llaves/{llave}/baja', [LlaveController::class, 'baja'])->whereNumber('llave')->name('llaves.baja');
    Route::patch('/llaves/{llave}/reactivar', [LlaveController::class, 'reactivar'])->whereNumber('llave')->name('llaves.reactivar');
    // Fin Padrones: Llaves
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
    // Padrones: Equipos de seguridad y Estacionamientos
    Route::get('/equipos', [EquipoController::class, 'index'])->name('equipos.index');
    Route::post('/equipos', [EquipoController::class, 'store'])->name('equipos.store');
    Route::put('/equipos/{equipo}', [EquipoController::class, 'update'])->whereNumber('equipo')->name('equipos.update');
    Route::post('/equipos/{equipo}/baja', [EquipoController::class, 'baja'])->whereNumber('equipo')->name('equipos.baja');
    Route::patch('/equipos/{equipo}/reactivar', [EquipoController::class, 'reactivar'])->whereNumber('equipo')->name('equipos.reactivar');
    Route::get('/equipos/{equipo}/etiqueta', [EquipoController::class, 'etiqueta'])->whereNumber('equipo')->name('equipos.etiqueta');
    Route::get('/equipos/{equipo}/qr', [EquipoController::class, 'qr'])->whereNumber('equipo')->name('equipos.qr');
    Route::get('/estacionamientos', [EstacionamientoController::class, 'index'])->name('estacionamientos.index');
    Route::post('/estacionamientos', [EstacionamientoController::class, 'store'])->name('estacionamientos.store');
    Route::put('/estacionamientos/{zona}', [EstacionamientoController::class, 'update'])->whereNumber('zona')->name('estacionamientos.update');
    Route::patch('/estacionamientos/{zona}/estado', [EstacionamientoController::class, 'estado'])->whereNumber('zona')->name('estacionamientos.estado');
    // Fin Padrones: Equipos de seguridad y Estacionamientos
    // Padrones: Rutas de transporte (fichas por sede, pestañas Llegadas / Salidas / Paraderos, hoja del día e itinerario)
    Route::get('/rutas', [RutaController::class, 'index'])->name('rutas.index');
    Route::post('/rutas', [RutaController::class, 'store'])->name('rutas.store');
    Route::get('/rutas/sede/{sede}', [RutaController::class, 'sede'])->whereNumber('sede')->name('rutas.sede');
    Route::get('/rutas/sede/{sede}/dia', [RutaController::class, 'dia'])->whereNumber('sede')->name('rutas.dia');
    // Ronda 8 (RT-07/RT-08): hoja de horarios de la semana (consulta de caseta)
    Route::get('/rutas/sede/{sede}/semana', [RutaController::class, 'semana'])->whereNumber('sede')->name('rutas.semana');
    Route::post('/rutas/sede/{sede}/paraderos', [RutaController::class, 'guardarParadero'])->whereNumber('sede')->name('rutas.paraderos.store');
    Route::put('/rutas/paraderos/{paradero}', [RutaController::class, 'actualizarParadero'])->whereNumber('paradero')->name('rutas.paraderos.update');
    Route::patch('/rutas/paraderos/{paradero}/estado', [RutaController::class, 'estadoParadero'])->whereNumber('paradero')->name('rutas.paraderos.estado');
    Route::put('/rutas/{ruta}', [RutaController::class, 'update'])->whereNumber('ruta')->name('rutas.update');
    Route::patch('/rutas/{ruta}/estado', [RutaController::class, 'estado'])->whereNumber('ruta')->name('rutas.estado');
    Route::post('/rutas/{ruta}/clonar', [RutaController::class, 'clonar'])->whereNumber('ruta')->name('rutas.clonar');
    Route::get('/rutas/{ruta}/itinerario', [RutaController::class, 'itinerario'])->whereNumber('ruta')->name('rutas.itinerario');
    // Fin Padrones: Rutas de transporte

    // Padrones: Bitácora de accesos (Gente en Sitio, Pendientes de Autorización, Historial, Registro Inteligente de Ingreso)
    Route::get('/accesos', [AccesoController::class, 'index'])->name('accesos.index');
    Route::post('/accesos', [AccesoController::class, 'store'])->name('accesos.store');
    Route::get('/accesos/exportar', [AccesoController::class, 'exportar'])->name('accesos.exportar');
    Route::get('/accesos/en-sitio', [AccesoController::class, 'enSitio'])->middleware('throttle:120,1')->name('accesos.en-sitio');
    Route::get('/accesos/buscar', [AccesoController::class, 'buscar'])->middleware('throttle:240,1')->name('accesos.buscar');
    Route::get('/accesos/gafetes', [AccesoController::class, 'gafetes'])->name('accesos.gafetes');
    Route::patch('/accesos/{acceso}/autorizar', [AccesoController::class, 'autorizar'])->whereNumber('acceso')->name('accesos.autorizar');
    Route::patch('/accesos/{acceso}/salida', [AccesoController::class, 'salida'])->whereNumber('acceso')->name('accesos.salida');
    Route::patch('/accesos/{acceso}/zona', [AccesoController::class, 'zona'])->whereNumber('acceso')->name('accesos.zona');
    Route::post('/accesos/{acceso}/salida-temporal', [AccesoController::class, 'salidaTemporal'])->whereNumber('acceso')->name('accesos.salida-temporal');
    Route::patch('/accesos/{acceso}/regreso', [AccesoController::class, 'regreso'])->whereNumber('acceso')->name('accesos.regreso');
    Route::patch('/accesos/acompanantes/{acompanante}/salida', [AccesoController::class, 'acompananteSalida'])->whereNumber('acompanante')->name('accesos.acompanantes.salida');
    Route::patch('/accesos/acompanantes/{acompanante}/salida-temporal', [AccesoController::class, 'acompananteSalidaTemporal'])->whereNumber('acompanante')->name('accesos.acompanantes.salida-temporal');
    Route::patch('/accesos/acompanantes/{acompanante}/regreso', [AccesoController::class, 'acompananteRegreso'])->whereNumber('acompanante')->name('accesos.acompanantes.regreso');
    // Fin Padrones: Bitácora de accesos
    // Padrones: Préstamo de llaves y Responsivas (menú Operación; ver docs/tecnico/prestamo-llaves.md y responsivas.md)
    Route::get('/prestamo-llaves', [PrestamoLlaveController::class, 'index'])->name('prestamo_llaves.index');
    Route::post('/prestamo-llaves', [PrestamoLlaveController::class, 'store'])->name('prestamo_llaves.store');
    Route::get('/prestamo-llaves/exportar', [PrestamoLlaveController::class, 'exportar'])->name('prestamo_llaves.exportar');
    Route::get('/prestamo-llaves/llaves/{llave}/historial', [PrestamoLlaveController::class, 'historial'])->whereNumber('llave')->name('prestamo_llaves.historial');
    Route::patch('/prestamo-llaves/{prestamo}/recibir', [PrestamoLlaveController::class, 'recibir'])->whereNumber('prestamo')->name('prestamo_llaves.recibir');
    Route::patch('/prestamo-llaves/{prestamo}/anular', [PrestamoLlaveController::class, 'anular'])->whereNumber('prestamo')->name('prestamo_llaves.anular');
    Route::patch('/prestamo-llaves/{prestamo}/reactivar', [PrestamoLlaveController::class, 'reactivar'])->whereNumber('prestamo')->name('prestamo_llaves.reactivar');
    Route::get('/responsivas', [ResponsivaController::class, 'index'])->name('responsivas.index');
    Route::post('/responsivas', [ResponsivaController::class, 'store'])->name('responsivas.store');
    Route::get('/responsivas/equipos/{equipo}/historial', [ResponsivaController::class, 'historial'])->whereNumber('equipo')->name('responsivas.historial');
    Route::patch('/responsivas/{responsiva}/recibir', [ResponsivaController::class, 'recibir'])->whereNumber('responsiva')->name('responsivas.recibir');
    // Ronda 8 (RS-04): devolución parcial, un equipo a la vez
    Route::patch('/responsivas/{responsiva}/equipos/{equipo}/recibir', [ResponsivaController::class, 'recibirEquipo'])->whereNumber(['responsiva', 'equipo'])->name('responsivas.recibir-equipo');
    Route::get('/responsivas/{responsiva}/firma', [ResponsivaController::class, 'firma'])->whereNumber('responsiva')->name('responsivas.firma');
    Route::get('/responsivas/{responsiva}/hoja', [ResponsivaController::class, 'hoja'])->whereNumber('responsiva')->name('responsivas.hoja');
    // Fin Padrones: Préstamo de llaves y Responsivas
    // Padrones: Bitácora de Novedades (despacho de tickets con expediente por categoría; Lost & Found trabaja solo sus tickets)
    Route::controller('App\Http\Controllers\Seguridad\NovedadController')->group(function () {
        Route::get('/novedades', 'index')->name('novedades.index');
        Route::get('/novedades/lost-found', 'index')->name('lost_found.index');
        Route::post('/novedades', 'store')->middleware('throttle:60,1')->name('novedades.store');
        Route::get('/novedades/exportar', 'exportar')->name('novedades.exportar');
        Route::get('/novedades/ficha-hechos', 'fichaHechos')->name('novedades.ficha-hechos');
        Route::get('/novedades/coincidencias', 'coincidencias')->middleware('throttle:60,1')->name('novedades.coincidencias');
        Route::post('/novedades/perdidas/{reporte}/vincular', 'vincularPerdida')->whereNumber('reporte')->name('novedades.perdidas.vincular');
        Route::put('/novedades/{novedad}', 'update')->whereNumber('novedad')->name('novedades.update');
        // Ronda 8 (NV-03): con GET la dirección del expediente lo abre (antes: 405)
        Route::get('/novedades/{novedad}', 'mostrar')->whereNumber('novedad')->name('novedades.mostrar');
        Route::post('/novedades/{novedad}/reabrir', 'reabrir')->whereNumber('novedad')->name('novedades.reabrir');
        Route::post('/novedades/{novedad}/vincular-hallazgo', 'vincularRobo')->whereNumber('novedad')->name('novedades.robo.vincular');
        Route::get('/novedades/{novedad}/imprimir', 'imprimir')->whereNumber('novedad')->name('novedades.imprimir');
        Route::get('/novedades/{novedad}/acuse', 'acuse')->whereNumber('novedad')->name('novedades.acuse');
        Route::get('/novedades/{novedad}/firmas/{rol}', 'firma')->whereNumber('novedad')->where('rol', '[a-z]+')->name('novedades.firma');
    });
    // Fin Padrones: Bitácora de Novedades

    // Padrones: Pases de salida (Operación: circuito de aprobación configurable, bandeja de firmas, pasos de caseta, hoja con QR)
    Route::get('/pases-salida', [PaseSalidaController::class, 'index'])->name('pases-salida.index');
    Route::post('/pases-salida', [PaseSalidaController::class, 'store'])->name('pases-salida.store');
    Route::get('/pases-salida/mis-pendientes', [PaseSalidaController::class, 'pendientes'])->name('pases-salida.pendientes');
    Route::get('/pases-salida/circuito', ['App\Http\Controllers\Seguridad\CircuitoPasesSalidaController', 'edit'])->name('pases-salida.circuito');
    Route::put('/pases-salida/circuito', ['App\Http\Controllers\Seguridad\CircuitoPasesSalidaController', 'update'])->name('pases-salida.circuito.update');
    Route::delete('/pases-salida/circuito', ['App\Http\Controllers\Seguridad\CircuitoPasesSalidaController', 'destroy'])->name('pases-salida.circuito.destroy');
    Route::get('/pases-salida/mi-firma', [PaseSalidaController::class, 'miFirma'])->name('pases-salida.mi-firma');
    Route::delete('/pases-salida/mi-firma', [PaseSalidaController::class, 'borrarMiFirma'])->name('pases-salida.mi-firma.destroy');
    Route::get('/pases-salida/verificar/{codigo}', [PaseSalidaController::class, 'verificar'])->where('codigo', '[A-Za-z0-9]{8,32}')->middleware('throttle:60,1')->name('pases-salida.verificar');
    Route::get('/pases-salida/equipos/{equipo}', [PaseSalidaController::class, 'equipo'])->whereNumber('equipo')->name('pases-salida.equipo');
    Route::get('/pases-salida/{pase}', [PaseSalidaController::class, 'show'])->whereNumber('pase')->name('pases-salida.show');
    Route::put('/pases-salida/{pase}', [PaseSalidaController::class, 'update'])->whereNumber('pase')->name('pases-salida.update');
    Route::post('/pases-salida/{pase}/firmas', [PaseSalidaController::class, 'firmar'])->whereNumber('pase')->middleware('throttle:60,1')->name('pases-salida.firmar');
    Route::get('/pases-salida/{pase}/firmas/{firma}', [PaseSalidaController::class, 'firma'])->whereNumber(['pase', 'firma'])->name('pases-salida.firma');
    Route::post('/pases-salida/{pase}/rechazar', [PaseSalidaController::class, 'rechazar'])->whereNumber('pase')->name('pases-salida.rechazar');
    Route::post('/pases-salida/{pase}/omitir', [PaseSalidaController::class, 'omitir'])->whereNumber('pase')->name('pases-salida.omitir');
    Route::post('/pases-salida/{pase}/cancelar', [PaseSalidaController::class, 'cancelar'])->whereNumber('pase')->name('pases-salida.cancelar');
    Route::get('/pases-salida/{pase}/imprimir', [PaseSalidaController::class, 'imprimir'])->whereNumber('pase')->name('pases-salida.imprimir');
    // Fin Padrones: Pases de salida
    // Padrones: Bitácora de transporte (Operación: llegadas, salidas y vales de taxi)
    Route::get('/transporte', [TransporteController::class, 'index'])->name('transporte.index');
    Route::post('/transporte', [TransporteController::class, 'store'])->name('transporte.store');
    Route::get('/transporte/reportes', [TransporteController::class, 'reportes'])->name('transporte.reportes');
    Route::get('/transporte/exportar', [TransporteController::class, 'exportar'])->name('transporte.exportar');
    Route::get('/transporte/{movimiento}', [TransporteController::class, 'show'])->whereNumber('movimiento')->name('transporte.show');
    Route::put('/transporte/{movimiento}', [TransporteController::class, 'update'])->whereNumber('movimiento')->name('transporte.update');
    Route::patch('/transporte/{movimiento}/anular', [TransporteController::class, 'anular'])->whereNumber('movimiento')->name('transporte.anular');
    Route::patch('/transporte/{movimiento}/reactivar', [TransporteController::class, 'reactivar'])->whereNumber('movimiento')->name('transporte.reactivar');
    Route::patch('/transporte/{movimiento}/autorizar', [TransporteController::class, 'autorizar'])->whereNumber('movimiento')->name('transporte.autorizar');
    Route::get('/transporte/{movimiento}/vale', [TransporteController::class, 'vale'])->whereNumber('movimiento')->name('transporte.vale');
    Route::get('/transporte/{movimiento}/firma/{cual}', [TransporteController::class, 'firma'])->whereNumber('movimiento')->whereIn('cual', ['guardia', 'taxista'])->name('transporte.firma');
    // Fin Padrones: Bitácora de transporte

    // Padrones: Lost & Found (archivo) y Robo — Seguimiento (menú Operación; ver docs/tecnico/lost-found-y-robo.md)
    Route::controller('App\Http\Controllers\Seguridad\LostFoundController')->group(function () {
        Route::get('/lost-found', 'index')->name('lost_found.archivo');
        Route::get('/lost-found/auditoria', 'auditoria')->name('lost_found.auditoria');
        // Los días de resguardo se configuran en Estructura → Configuración; la dirección anterior redirige (301)
        Route::get('/lost-found/dias-resguardo', 'umbrales')->name('lost_found.umbrales');
        Route::get('/lost-found/articulos/{articulo}', 'show')->whereNumber('articulo')->name('lost_found.articulos.show');
        Route::post('/lost-found/articulos/{articulo}/cerrar', 'cerrar')->whereNumber('articulo')->middleware('throttle:60,1')->name('lost_found.articulos.cerrar');
        Route::get('/lost-found/articulos/{articulo}/etiqueta', 'etiqueta')->whereNumber('articulo')->name('lost_found.articulos.etiqueta');
        Route::get('/lost-found/entregas/{entrega}/firma', 'firma')->whereNumber('entrega')->name('lost_found.entregas.firma');
    });
    Route::get('/robos', ['App\Http\Controllers\Seguridad\RoboController', 'index'])->name('robo.index');
    Route::put('/robos/{novedad}', ['App\Http\Controllers\Seguridad\RoboController', 'update'])->whereNumber('novedad')->name('robo.update');
    // Fin Padrones: Lost & Found (archivo) y Robo — Seguimiento
    // Padrones: Recorridos de Protección Civil (Operación: recorrido en curso un punto a la vez y Reporte de Auditoría)
    Route::controller('App\Http\Controllers\Seguridad\RecorridoPcController')->group(function () {
        Route::get('/recorridos-pc', 'index')->name('recorridos_pc.index');
        Route::post('/recorridos-pc', 'store')->middleware('throttle:60,1')->name('recorridos_pc.store');
        Route::get('/recorridos-pc/reporte', 'reporte')->name('recorridos_pc.reporte');
        Route::get('/recorridos-pc/exportar', 'exportar')->name('recorridos_pc.exportar');
        Route::get('/recorridos-pc/{recorrido}', 'show')->whereNumber('recorrido')->name('recorridos_pc.show');
        Route::put('/recorridos-pc/{recorrido}', 'update')->whereNumber('recorrido')->name('recorridos_pc.update');
        Route::post('/recorridos-pc/{recorrido}/revisiones', 'registrarPunto')->whereNumber('recorrido')->middleware('throttle:120,1')->name('recorridos_pc.revisiones.store');
    });
    // Fin Padrones: Recorridos de Protección Civil
    // Padrones: Eliminar definitivamente (borrado físico controlado de catálogos y padrones; ver docs/tecnico/borrado.md)
    Route::controller('App\Http\Controllers\BorradoController')->group(function () {
        // {registro}: clave de App\Services\Borrado\RegistroBorrado (una desconocida responde 404)
        Route::get('/borrar/{registro}/{id}', 'revisar')->where('registro', '[a-z_]+')->whereNumber('id')->name('borrar.revisar');
        Route::delete('/borrar/{registro}/{id}', 'destroy')->where('registro', '[a-z_]+')->whereNumber('id')->middleware('throttle:30,1')->name('borrar.destroy');
    });
    // Fin Padrones: Eliminar definitivamente

    // Padrones: Equipos de Protección Civil (Padrones → Inventarios de Seguridad; ver docs/tecnico/equipos-pc.md)
    Route::controller('App\Http\Controllers\Seguridad\EquipoPcController')->group(function () {
        Route::get('/equipos-pc', 'index')->name('equipos_pc.index');
        Route::post('/equipos-pc', 'store')->name('equipos_pc.store');
        Route::put('/equipos-pc/{equipo}', 'update')->whereNumber('equipo')->name('equipos_pc.update');
        Route::patch('/equipos-pc/{equipo}/desactivar', 'desactivar')->whereNumber('equipo')->name('equipos_pc.desactivar');
        Route::patch('/equipos-pc/{equipo}/reactivar', 'reactivar')->whereNumber('equipo')->name('equipos_pc.reactivar');
        Route::get('/equipos-pc/{equipo}/etiqueta', 'etiqueta')->whereNumber('equipo')->name('equipos_pc.etiqueta');
        Route::get('/equipos-pc/{equipo}/qr', 'qr')->whereNumber('equipo')->name('equipos_pc.qr');
        Route::get('/equipos-pc/{equipo}/ir', 'ir')->whereNumber('equipo')->name('equipos_pc.ir');
        // Direcciones anteriores (dentro de Recorridos PC): redirección permanente (301) a las nuevas
        Route::get('/recorridos-pc/equipos', 'anterior')->name('equipos_pc.anterior');
        Route::get('/recorridos-pc/equipos/{equipo}/{pantalla}', 'anteriorEquipo')->whereNumber('equipo')->whereIn('pantalla', ['etiqueta', 'qr', 'ir'])->name('equipos_pc.anterior.equipo');
    });
    // Fin Padrones: Equipos de Protección Civil
    // Padrones: Ajustes QA 4 (homónimos en Usuarios y Colaboradores; días de resguardo de Lost & Found en Configuración)
    Route::get('/colaboradores/homonimos', [ColaboradorController::class, 'homonimos'])->middleware('throttle:120,1')->name('colaboradores.homonimos');
    Route::put('/configuracion/lost-found', [ConfiguracionController::class, 'lostFound'])->name('configuracion.lost_found');
    // Fin Padrones: Ajustes QA 4
    // Padrones: Altas por verificar (vehículos, empresas externas y personas registrados desde Operación; ver docs/tecnico/altas-por-verificar.md)
    Route::controller('App\Http\Controllers\Padrones\AltaPorVerificarController')->group(function () {
        Route::get('/altas-por-verificar/parecidos', 'parecidos')->middleware('throttle:120,1')->name('altas_por_verificar.parecidos');
        Route::put('/vehiculos/{vehiculo}/aceptar', 'aceptarVehiculo')->whereNumber('vehiculo')->name('vehiculos.aceptar');
        Route::put('/vehiculos/{vehiculo}/rechazar', 'rechazarVehiculo')->whereNumber('vehiculo')->name('vehiculos.rechazar');
        Route::put('/vehiculos/{vehiculo}/unir', 'unirVehiculo')->whereNumber('vehiculo')->name('vehiculos.unir');
        Route::put('/proveedores/{proveedor}/aceptar', 'aceptarProveedor')->whereNumber('proveedor')->name('proveedores.aceptar');
        Route::put('/proveedores/{proveedor}/rechazar', 'rechazarProveedor')->whereNumber('proveedor')->name('proveedores.rechazar');
        Route::put('/proveedores/{proveedor}/unir', 'unirProveedor')->whereNumber('proveedor')->name('proveedores.unir');
        Route::put('/personas/{persona}/aceptar', 'aceptarPersona')->whereNumber('persona')->name('personas.aceptar');
        Route::put('/personas/{persona}/rechazar', 'rechazarPersona')->whereNumber('persona')->name('personas.rechazar');
        Route::put('/personas/{persona}/unir', 'unirPersona')->whereNumber('persona')->name('personas.unir');
    });
    // Fin Padrones: Altas por verificar
    // Padrones: Procedimientos (Operación → Consulta: manual operativo con versiones, aprobación con firma y acuse «Leí y entendí»; ver docs/tecnico/procedimientos.md)
    Route::controller(ProcedimientoController::class)->group(function () {
        Route::get('/procedimientos', 'index')->name('procedimientos.index');
        Route::post('/procedimientos', 'store')->middleware('throttle:30,1')->name('procedimientos.store');
        Route::get('/procedimientos/por-leer', 'porLeer')->name('procedimientos.por-leer');
        Route::get('/procedimientos/mi-firma', 'miFirma')->name('procedimientos.mi-firma');
        Route::post('/procedimientos/categorias', 'guardarCategoria')->name('procedimientos.categorias.store');
        Route::put('/procedimientos/categorias/{categoria}', 'actualizarCategoria')->whereNumber('categoria')->name('procedimientos.categorias.update');
        Route::get('/procedimientos/{procedimiento}', 'show')->whereNumber('procedimiento')->name('procedimientos.show');
        Route::put('/procedimientos/{procedimiento}', 'update')->whereNumber('procedimiento')->middleware('throttle:30,1')->name('procedimientos.update');
        Route::get('/procedimientos/{procedimiento}/leer', 'leer')->whereNumber('procedimiento')->name('procedimientos.leer');
        Route::get('/procedimientos/{procedimiento}/imprimir', 'imprimir')->whereNumber('procedimiento')->name('procedimientos.imprimir');
        Route::post('/procedimientos/{procedimiento}/nueva-version', 'nuevaVersion')->whereNumber('procedimiento')->name('procedimientos.nueva-version');
        Route::post('/procedimientos/{procedimiento}/enviar', 'enviar')->whereNumber('procedimiento')->name('procedimientos.enviar');
        Route::post('/procedimientos/{procedimiento}/aprobar', 'aprobar')->whereNumber('procedimiento')->middleware('throttle:30,1')->name('procedimientos.aprobar');
        Route::post('/procedimientos/{procedimiento}/rechazar', 'rechazar')->whereNumber('procedimiento')->name('procedimientos.rechazar');
        Route::post('/procedimientos/{procedimiento}/descartar', 'descartar')->whereNumber('procedimiento')->name('procedimientos.descartar');
        Route::post('/procedimientos/{procedimiento}/retirar', 'retirar')->whereNumber('procedimiento')->name('procedimientos.retirar');
        Route::post('/procedimientos/{procedimiento}/reactivar', 'reactivar')->whereNumber('procedimiento')->name('procedimientos.reactivar');
        Route::post('/procedimientos/{procedimiento}/acuse', 'acusar')->whereNumber('procedimiento')->middleware('throttle:30,1')->name('procedimientos.acuse');
        Route::get('/procedimientos/{procedimiento}/acuses/exportar', 'exportarAcuses')->whereNumber('procedimiento')->name('procedimientos.acuses.exportar');
        Route::get('/procedimientos/{procedimiento}/acuses/{acuse}/firma', 'firmaAcuse')->whereNumber(['procedimiento', 'acuse'])->name('procedimientos.acuses.firma');
        Route::get('/procedimientos/{procedimiento}/versiones/{version}/firma', 'firmaAprobacion')->whereNumber(['procedimiento', 'version'])->name('procedimientos.versiones.firma');
        Route::get('/procedimientos/{procedimiento}/adjuntos/{adjunto}', 'adjunto')->whereNumber(['procedimiento', 'adjunto'])->name('procedimientos.adjunto');
    });
    // Fin Padrones: Procedimientos

    // Padrones: Ajustes Ronda 5 (código e identificación de las fichas; firmas de vouchers)
    Route::get('/identificacion/{tipo}/{id}/qr', [IdentificacionController::class, 'qr'])
        ->where('tipo', '[a-z_]+')->whereNumber('id')->name('identificacion.qr');
    Route::put('/identificacion/{tipo}/{id}/etiqueta', [IdentificacionController::class, 'etiqueta'])
        ->where('tipo', '[a-z_]+')->whereNumber('id')->middleware('throttle:60,1')->name('identificacion.etiqueta');
    Route::get('/vouchers/{voucher}/firma/{parte}', [VoucherController::class, 'firma'])
        ->whereNumber('voucher')->whereIn('parte', ['seguridad', 'responsable', 'hoja'])->name('vouchers.firma');
    Route::post('/vouchers/{voucher}/papel', [VoucherController::class, 'papel'])->whereNumber('voucher')->name('vouchers.papel');
    // Fin Padrones: Ajustes Ronda 5
    // Padrones: Ajustes Ronda 5 (parte 2): avisos de duplicado en vivo (ver docs/tecnico/avisos-duplicado.md)
    Route::get('/personas/duplicado', [PersonaController::class, 'duplicado'])->middleware('throttle:120,1')->name('personas.duplicado');
    Route::get('/espacios/duplicado', [EspacioController::class, 'duplicado'])->middleware('throttle:120,1')->name('espacios.duplicado');
    // Fin Padrones: Ajustes Ronda 5 (parte 2)
    // Padrones: Ajustes Ronda 6 (avisos de duplicado de los demás padrones y de la etiqueta NFC; Etiquetas QR; voucher recuperado)
    Route::controller('App\Http\Controllers\Padrones\DuplicadoController')->middleware('throttle:120,1')->group(function () {
        Route::get('/proveedores/duplicado', 'proveedores')->name('proveedores.duplicado');
        Route::get('/vehiculos/duplicado', 'vehiculos')->name('vehiculos.duplicado');
        Route::get('/colaboradores/duplicado', 'colaboradores')->name('colaboradores.duplicado');
        Route::get('/gafetes/duplicado', 'gafetes')->name('gafetes.duplicado');
        Route::get('/equipos/duplicado', 'equipos')->name('equipos.duplicado');
        Route::get('/equipos-pc/duplicado', 'equiposPc')->name('equipos_pc.duplicado');
        Route::get('/identificacion/{tipo}/{id}/etiqueta-duplicado', 'etiqueta')->where('tipo', '[a-z_]+')->whereNumber('id')->name('identificacion.etiqueta-duplicado');
    });
    Route::controller('App\Http\Controllers\Padrones\EtiquetaController')->group(function () {
        Route::get('/etiquetas', 'index')->name('etiquetas.index');
        Route::post('/etiquetas/imprimir', 'imprimir')->name('etiquetas.imprimir'); // Ronda 7: POST (registra la impresión en el historial)
    });
    Route::post('/vouchers/{voucher}/recuperado', [VoucherController::class, 'recuperado'])->whereNumber('voucher')->name('vouchers.recuperado');
    Route::post('/vouchers/{voucher}/reembolso', [VoucherController::class, 'reembolso'])->whereNumber('voucher')->name('vouchers.reembolso');
    // Fin Padrones: Ajustes Ronda 6

    // Padrones: Recepción de candidatos y autorizaciones departamentales (Recursos Humanos; ver docs/tecnico/recepcion-y-autorizaciones.md)
    Route::controller(NotificacionController::class)->group(function () {
        Route::get('/notificaciones', 'index')->name('notificaciones.index');
        Route::get('/notificaciones/resumen', 'resumen')->middleware('throttle:120,1')->name('notificaciones.resumen');
        Route::post('/notificaciones/leer-todas', 'leerTodas')->name('notificaciones.leer-todas');
        Route::post('/notificaciones/{notificacion}/abrir', 'abrir')->whereNumber('notificacion')->name('notificaciones.abrir');
    });
    Route::controller(RecepcionController::class)->group(function () {
        Route::get('/rh/recepcion', 'index')->name('recepcion.index');
        Route::get('/rh/recepcion/datos', 'datos')->middleware('throttle:120,1')->name('recepcion.datos');
        Route::get('/rh/recepcion/metricas', 'metricas')->name('recepcion.metricas');
        Route::get('/rh/recepcion/kiosco', 'kiosco')->name('recepcion.kiosco');
        Route::post('/rh/recepcion/kiosco/{candidato}', 'generarEnlace')->whereNumber('candidato')->name('recepcion.kiosco.generar');
        Route::get('/rh/recepcion/ajustes', 'ajustes')->name('recepcion.ajustes');
        Route::put('/rh/recepcion/ajustes', 'guardarAjustes')->name('recepcion.ajustes.guardar');
        Route::get('/accesos/{acceso}/foto-persona', 'fotoPersona')->whereNumber('acceso')->name('accesos.foto-persona');
        Route::get('/accesos/{acceso}/foto-identificacion', 'fotoIdentificacion')->whereNumber('acceso')->name('accesos.foto-identificacion');
    });
    Route::controller(CandidatoController::class)->group(function () {
        Route::get('/candidatos', 'index')->name('candidatos.index');
        Route::post('/candidatos', 'store')->name('candidatos.store');
        Route::get('/candidatos/exportar', 'exportar')->name('candidatos.exportar');
        Route::get('/candidatos/{candidato}', 'show')->whereNumber('candidato')->name('candidatos.show');
        Route::put('/candidatos/{candidato}', 'update')->whereNumber('candidato')->name('candidatos.update');
        Route::delete('/candidatos/{candidato}', 'destroy')->whereNumber('candidato')->name('candidatos.destroy');
        Route::patch('/candidatos/{candidato}/etapa', 'etapa')->whereNumber('candidato')->name('candidatos.etapa');
        Route::post('/candidatos/{candidato}/revisado', 'revisado')->whereNumber('candidato')->name('candidatos.revisado');
        Route::post('/candidatos/{candidato}/contratar', 'contratar')->whereNumber('candidato')->name('candidatos.contratar');
        Route::post('/candidatos/{candidato}/enlace/revocar', 'revocarEnlace')->whereNumber('candidato')->name('candidatos.enlace.revocar');
        Route::post('/candidatos/{candidato}/documentos', 'subirDocumento')->whereNumber('candidato')->name('candidatos.documentos.store');
        Route::get('/candidatos/{candidato}/documentos/{documento}', 'documento')->whereNumber(['candidato', 'documento'])->name('candidatos.documento');
        Route::delete('/candidatos/{candidato}/documentos/{documento}', 'borrarDocumento')->whereNumber(['candidato', 'documento'])->name('candidatos.documentos.destroy');
    });
    Route::controller(AutorizacionController::class)->group(function () {
        Route::get('/autorizaciones', 'index')->name('autorizaciones.index');
        Route::get('/autorizaciones/responsables', 'responsables')->name('autorizaciones.responsables');
        Route::put('/autorizaciones/responsables/{departamento}', 'guardarResponsables')->whereNumber('departamento')->name('autorizaciones.responsables.guardar');
        Route::post('/autorizaciones/delegaciones', 'delegar')->name('autorizaciones.delegar');
        Route::patch('/autorizaciones/delegaciones/{delegacion}/cancelar', 'cancelarDelegacion')->whereNumber('delegacion')->name('autorizaciones.delegaciones.cancelar');
        Route::get('/autorizaciones/{autorizacion}', 'show')->whereNumber('autorizacion')->name('autorizaciones.show');
        Route::get('/autorizaciones/{autorizacion}/confirmar', 'confirmar')->whereNumber('autorizacion')->name('autorizaciones.confirmar');
        Route::post('/autorizaciones/{autorizacion}/responder', 'responder')->whereNumber('autorizacion')->middleware('throttle:60,1')->name('autorizaciones.responder');
        Route::get('/accesos/autorizaciones-estado', 'estadoCaseta')->middleware('throttle:120,1')->name('accesos.autorizaciones-estado');
    });
    // Fin Padrones: Recepción de candidatos y autorizaciones departamentales
    // Padrones: Ajustes Ronda 7 (avisos de duplicado de Llaves, catálogos de RH y Usuarios; gestor de impresión QR)
    Route::controller('App\Http\Controllers\Padrones\DuplicadoCatalogosController')->middleware('throttle:120,1')->group(function () {
        Route::get('/llaves/duplicado', 'llaves')->name('llaves.duplicado');
        Route::get('/departamentos/duplicado', 'departamentos')->name('departamentos.duplicado');
        Route::get('/puestos/duplicado', 'puestos')->name('puestos.duplicado');
        Route::get('/turnos/duplicado', 'turnos')->name('turnos.duplicado');
        Route::get('/usuarios/duplicado', 'usuarios')->name('usuarios.duplicado');
    });
    Route::controller('App\Http\Controllers\Padrones\EtiquetaController')->group(function () {
        Route::get('/etiquetas/historial', 'historial')->name('etiquetas.historial');
        Route::get('/etiquetas/impresiones/{impresion}', 'impresion')->whereNumber('impresion')->name('etiquetas.impresion');
        Route::post('/etiquetas/impresiones/{impresion}/reimprimir', 'reimprimir')->whereNumber('impresion')->name('etiquetas.reimprimir');
    });
    Route::controller('App\Http\Controllers\Padrones\EtiquetaPlantillaController')->group(function () {
        Route::get('/etiquetas/plantillas', 'index')->name('etiquetas.plantillas');
        Route::get('/etiquetas/plantillas/nueva', 'create')->name('etiquetas.plantillas.create');
        Route::post('/etiquetas/plantillas', 'store')->name('etiquetas.plantillas.store');
        Route::get('/etiquetas/plantillas/{plantilla}/editar', 'edit')->whereNumber('plantilla')->name('etiquetas.plantillas.edit');
        Route::put('/etiquetas/plantillas/{plantilla}', 'update')->whereNumber('plantilla')->name('etiquetas.plantillas.update');
        Route::patch('/etiquetas/plantillas/{plantilla}/estado', 'estado')->whereNumber('plantilla')->name('etiquetas.plantillas.estado');
        Route::get('/etiquetas/plantillas/{plantilla}/prueba', 'prueba')->whereNumber('plantilla')->name('etiquetas.plantillas.prueba');
    });
    // Fin Padrones: Ajustes Ronda 7

    // Padrones: Solicitud de empleo y Vacantes (Recursos Humanos; ver docs/tecnico/vacantes.md)
    Route::controller(CandidatoController::class)->group(function () {
        Route::get('/candidatos/{candidato}/solicitud', 'solicitud')->whereNumber('candidato')->name('candidatos.solicitud');
        Route::get('/candidatos/{candidato}/firma', 'firma')->whereNumber('candidato')->name('candidatos.firma');
    });
    Route::controller(VacanteController::class)->group(function () {
        Route::get('/vacantes', 'index')->name('vacantes.index');
        Route::post('/vacantes', 'store')->name('vacantes.store');
        Route::put('/vacantes/bolsa', 'ajustes')->name('vacantes.bolsa');
        Route::put('/vacantes/{vacante}', 'update')->whereNumber('vacante')->name('vacantes.update');
        Route::patch('/vacantes/{vacante}/estado', 'estado')->whereNumber('vacante')->name('vacantes.estado');
        Route::delete('/vacantes/{vacante}', 'destroy')->whereNumber('vacante')->name('vacantes.destroy');
        Route::get('/vacantes/{vacante}/cartel', 'cartel')->whereNumber('vacante')->name('vacantes.cartel');
    });
    // Fin Padrones: Solicitud de empleo y Vacantes

    // Lector universal: QR, NFC, RFID y código de barras (ver docs/tecnico/lector.md)
    Route::get('/lector/resolver', [LectorController::class, 'resolver'])->middleware('throttle:120,1')->name('lector.resolver');
    Route::get('/e/{codigo}', [LectorController::class, 'ir'])->where('codigo', '[A-Za-z0-9]{8,32}')->name('lector.ir');
    // Fin Lector universal
});

// Público: Kiosco de auto-registro de candidatos (SIN sesión; enlace temporal de un solo candidato; ver docs/tecnico/candidatos.md)
Route::controller(KioscoController::class)->group(function () {
    Route::get('/k', 'codigo')->middleware('throttle:120,1')->name('kiosco.codigo');
    Route::post('/k', 'canjear')->middleware('throttle:10,1')->name('kiosco.canjear');
    Route::get('/k/{token}', 'mostrar')->where('token', '[A-Za-z0-9]{1,64}')->middleware('throttle:120,1')->name('kiosco.mostrar');
    Route::post('/k/{token}', 'guardar')->where('token', '[A-Za-z0-9]{1,64}')->middleware('throttle:10,1')->name('kiosco.guardar');
});
// Fin Público: Kiosco de auto-registro de candidatos

// Público: Bolsa de trabajo (SIN sesión; solo empresas que la encienden; ver docs/tecnico/vacantes.md)
Route::controller(EmpleosController::class)->group(function () {
    Route::get('/empleos/{empresa}', 'index')->where('empresa', '[a-z0-9-]{3,90}')->middleware('throttle:120,1,empleos')->name('empleos.index');
    Route::get('/empleos/{empresa}/gracias', 'gracias')->where('empresa', '[a-z0-9-]{3,90}')->middleware('throttle:120,1,empleos')->name('empleos.gracias');
    Route::get('/empleos/{empresa}/{vacante}', 'show')->where(['empresa' => '[a-z0-9-]{3,90}', 'vacante' => '[a-z0-9]{10}'])->middleware('throttle:120,1,empleos')->name('empleos.show');
    Route::get('/empleos/{empresa}/{vacante}/postular', 'formulario')->where(['empresa' => '[a-z0-9-]{3,90}', 'vacante' => '[a-z0-9]{10}'])->middleware('throttle:120,1,empleos')->name('empleos.postular');
    Route::post('/empleos/{empresa}/{vacante}/postular', 'postular')->where(['empresa' => '[a-z0-9-]{3,90}', 'vacante' => '[a-z0-9]{10}'])->middleware('throttle:6,1,empleos-postular')->name('empleos.guardar');
});
// Fin Público: Bolsa de trabajo
