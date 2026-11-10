<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md): el detalle de un
 * registro con valor legal ya no desaparece en silencio si alguien borra su
 * padre directo (un acceso, una novedad, un pase, una reserva…). Pasa de
 * cascade a restrict.
 *
 * Los flujos oficiales no borran esos padres (se anulan, se cancelan o se
 * cierran). Los que sí borran a propósito siguen funcionando:
 * - «Eliminar definitivamente» (BorradoSeguro) ya cuenta cualquier fila que
 *   apunte al registro como dependencia, sin importar la acción de la llave.
 * - Lost & Found solo quita del ticket un artículo en resguardo sin entregas.
 * - Quitar un área de reservas con historial la desactiva (no la borra).
 * - Los candidatos (ARCO, caducidad) siguen borrando en cascada sus
 *   postulaciones, evaluaciones, eventos y documentos: no están aquí.
 *
 * Rápida e idempotente (ver 2026_11_09_000100). down() regresa a cascade.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** tabla => [columna => tabla padre] */
    public const FORANEAS = [
        'acompanantes_acceso' => ['acceso_id' => 'accesos'],
        'autorizaciones' => ['acceso_id' => 'accesos'],
        'accidente_colaboradores' => ['novedad_id' => 'novedades'],
        'accidente_dictamenes' => ['novedad_id' => 'novedades'],
        'accidente_firmas' => ['novedad_id' => 'novedades'],
        'accidente_guardavidas' => ['novedad_id' => 'novedades'],
        'accidente_huespedes' => ['novedad_id' => 'novedades'],
        'accidente_incapacidades' => ['novedad_id' => 'novedades'],
        'lost_found_articulos' => ['novedad_id' => 'novedades'],
        'lost_found_detalles' => ['novedad_id' => 'novedades'],
        'lost_found_reportes_perdida' => ['novedad_id' => 'novedades'],
        'lost_found_entregas' => ['articulo_id' => 'lost_found_articulos'],
        'novedad_notas' => ['novedad_id' => 'novedades'],
        'novedad_recorrido_puntos' => ['novedad_id' => 'novedades'],
        'novedad_reportes_generales' => ['novedad_id' => 'novedades'],
        'novedad_testigos' => ['novedad_id' => 'novedades'],
        'robo_detalles' => ['novedad_id' => 'novedades'],
        'siniestro_danos' => ['novedad_id' => 'novedades'],
        'siniestro_detalles' => ['novedad_id' => 'novedades'],
        'siniestro_equipos' => ['novedad_id' => 'novedades'],
        'siniestro_servicios' => ['novedad_id' => 'novedades'],
        'valores_vista_aperturas' => ['novedad_id' => 'novedades'],
        'valores_vista_detalles' => ['novedad_id' => 'novedades'],
        'valores_vista_personas' => ['novedad_id' => 'novedades'],
        'valores_vista_zonas' => ['novedad_id' => 'novedades'],
        'pases_salida_aprobaciones' => ['pase_salida_id' => 'pases_salida'],
        'pases_salida_articulos' => ['pase_salida_id' => 'pases_salida'],
        'pases_salida_bitacora' => ['pase_salida_id' => 'pases_salida'],
        'pases_salida_firmas' => ['pase_salida_id' => 'pases_salida'],
        'equipos_responsiva' => ['responsiva_id' => 'responsivas'],
        'movimiento_transporte_pasajeros' => ['movimiento_transporte_id' => 'movimientos_transporte'],
        'recorrido_pc_revisiones' => ['recorrido_pc_id' => 'recorridos_pc'],
        'reservas' => ['reserva_area_id' => 'reserva_areas', 'espacio_id' => 'espacios', 'sede_id' => 'sedes'],
        'reserva_aprobaciones' => ['reserva_id' => 'reservas'],
        'reserva_eventos' => ['reserva_id' => 'reservas'],
        'reserva_ordenes' => ['reserva_id' => 'reservas', 'departamento_id' => 'departamentos'],
        'reserva_servicio_items' => ['reserva_id' => 'reservas'],
        'procedimiento_acuses' => ['version_id' => 'procedimiento_versiones', 'user_id' => 'users'],
    ];

    public function up(): void
    {
        foreach (self::FORANEAS as $tabla => $columnas) {
            Restricciones::cambiarAccionAlBorrar($tabla, $columnas, 'restrict');
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::FORANEAS, true) as $tabla => $columnas) {
            Restricciones::cambiarAccionAlBorrar($tabla, $columnas, 'cascade');
        }
    }
};
