<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Revisión Etapa 5 (A-8): cada ruta con límite por minuto lleva su propio
 * limitador con nombre (el nombre de la ruta).
 *
 * Antes se usaba «throttle:60,1» (limitador sin nombre). Laravel cuenta ese
 * límite con UNA clave por usuario (o por IP si no hay sesión) que comparten
 * todas las rutas que lo usan: teclear en un buscador gastaba el límite de
 * «Responder», «Firmar» o «Guardar». Con un nombre por ruta, una acción no
 * gasta el límite de otra. Los números son los mismos de antes.
 *
 * Uso en las rutas: ->middleware('throttle:<nombre de la ruta>'). Una ruta
 * nueva con límite se agrega aquí; LimitadoresTest revisa que ninguna ruta
 * use un límite sin nombre ni comparta el de otra.
 */
final class Limitadores
{
    /** nombre del limitador (= nombre de la ruta) => peticiones por minuto */
    public const POR_MINUTO = [
        'contrasena.solicitar' => 5,
        'contrasena.administrador' => 5,
        'contrasena.guardar' => 10,
        'login.dos-pasos.verificar' => 10,
        'login.dos-pasos.ayuda' => 5,
        'mi-cuenta.contrasena' => 10,
        'configuracion.correo.prueba' => 5,
        'configuracion.respaldos' => 3,
        'traslado.generar' => 3,
        'traslado.verificar' => 5,
        'traslado.continuar' => 30,
        'lugares.buscar' => 120,
        'vehiculos.rapido' => 30,
        'mi-suscripcion.solicitar' => 10,
        'accesos.en-sitio' => 120,
        'accesos.buscar' => 240,
        'receptores.buscar' => 120,
        'novedades.departamento.nota' => 60,
        'novedades.departamento.atender' => 60,
        'novedades.store' => 60,
        'novedades.coincidencias' => 60,
        'pases-salida.verificar' => 60,
        // C-44 (Etapa 8): verificación pública de credenciales (sin sesión, por IP) y la foto
        'credenciales.verificar' => 30,
        'credenciales.foto' => 20,
        'pases-salida.firmar' => 60,
        'lost_found.umbrales.guardar' => 30,
        'lost_found.articulos.cerrar' => 60,
        'lost_found.objetos.store' => 60,
        'recorridos_pc.store' => 60,
        'recorridos_pc.revisiones.store' => 120,
        'borrar.destroy' => 30,
        'colaboradores.homonimos' => 120,
        'configuracion.restablecer' => 30,
        'altas_por_verificar.parecidos' => 120,
        'procedimientos.store' => 30,
        'procedimientos.update' => 30,
        'procedimientos.aprobar' => 30,
        'procedimientos.acuse' => 30,
        'identificacion.etiqueta' => 60,
        'personas.duplicado' => 120,
        'espacios.duplicado' => 120,
        'notificaciones.resumen' => 120,
        'recepcion.datos' => 120,
        'candidatos.enlace.correo' => 60,
        'autorizaciones.responder' => 60,
        'accesos.autorizaciones-estado' => 120,
        'entrevistas.evaluar' => 60,
        'entrevistas.no-se-presento' => 60,
        'manual.imagen' => 600,
        'lector.resolver' => 120,
        'prestamo_llaves.recibir-escaneada' => 60,
        'pagos.webhook' => 60,
        'reservas.store' => 60,
        'reservas.disponibilidad' => 240,
        'proveedores.duplicado' => 120,
        'vehiculos.duplicado' => 120,
        'colaboradores.duplicado' => 120,
        'candidatos.duplicado' => 120,
        'gafetes.duplicado' => 120,
        'equipos.duplicado' => 120,
        'equipos_pc.duplicado' => 120,
        'identificacion.etiqueta-duplicado' => 120,
        'llaves.duplicado' => 120,
        'departamentos.duplicado' => 120,
        'puestos.duplicado' => 120,
        'turnos.duplicado' => 120,
        'usuarios.duplicado' => 120,
        // Importar desde Excel e Importar estructura: archivos de hasta 5,000 renglones
        'importaciones.store' => 10,
        'importaciones.aplicar' => 10,
        'importaciones.exportar' => 10,
    ];

    public static function registrar(): void
    {
        foreach (self::POR_MINUTO as $nombre => $porMinuto) {
            // Con sesión, por cuenta; sin sesión (contraseña olvidada, webhook), por IP
            RateLimiter::for($nombre, fn (Request $r) => Limit::perMinute($porMinuto)
                ->by($nombre.'|'.($r->user()?->getAuthIdentifier() ?? 'ip:'.$r->ip())));
        }
    }
}
