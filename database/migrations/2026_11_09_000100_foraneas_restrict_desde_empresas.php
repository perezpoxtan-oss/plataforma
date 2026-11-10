<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md): las tablas con
 * valor legal o histórico ya no se borran «en cascada» si alguien borra la
 * empresa. Su llave empresa_id pasa de cascade a restrict: la base se niega
 * a borrar una empresa que tiene historia.
 *
 * Ningún flujo borra empresas (la baja es Empresa.activo = false y el modelo
 * usa SoftDeletes); los respaldos y el traslado restauran con la revisión de
 * llaves apagada. down() regresa a cascade.
 *
 * Rápida en MariaDB: solo cambia la acción de la llave, con la revisión de
 * llaves apagada (InnoDB no copia la tabla). Idempotente: si se corta, la
 * siguiente corrida termina lo que faltó.
 */
return new class extends Migration
{
    /** La edición del esquema de SQLite y los ALTER de MariaDB no van en una transacción. */
    public $withinTransaction = false;

    public const TABLAS = [
        // Bitácora de accesos
        'accesos', 'acompanantes_acceso', 'autorizaciones',
        // Novedades y sus formatos (Lost & Found, Robo, Accidentes, Siniestros, Valores a la vista)
        'novedades', 'novedad_notas', 'novedad_recorrido_puntos', 'novedad_reportes_generales', 'novedad_testigos',
        'accidente_colaboradores', 'accidente_dictamenes', 'accidente_firmas', 'accidente_guardavidas', 'accidente_huespedes', 'accidente_incapacidades',
        'lost_found_articulos', 'lost_found_detalles', 'lost_found_entregas', 'lost_found_reportes_perdida', 'robo_detalles',
        'siniestro_danos', 'siniestro_detalles', 'siniestro_equipos', 'siniestro_servicios',
        'valores_vista_aperturas', 'valores_vista_detalles', 'valores_vista_personas', 'valores_vista_zonas',
        // Pases de salida
        'pases_salida', 'pases_salida_aprobaciones', 'pases_salida_articulos', 'pases_salida_bitacora', 'pases_salida_firmas',
        // Préstamos, responsivas y vouchers
        'prestamos_llaves', 'responsivas', 'equipos_responsiva', 'vouchers_reposicion',
        // Transporte y recorridos de Protección Civil
        'movimientos_transporte', 'recorridos_pc', 'recorrido_pc_revisiones',
        // Reservas de áreas
        'reservas', 'reserva_aprobaciones', 'reserva_eventos', 'reserva_ordenes', 'reserva_servicio_items',
        // Candidatos (su historia; el borrado propio de un candidato sigue en cascada desde la ficha)
        'candidatos', 'postulaciones', 'evaluaciones_candidato', 'candidato_eventos', 'candidato_documentos',
        // Procedimientos: acuses «Leí y entendí» e historial
        'procedimiento_acuses', 'procedimiento_eventos',
        // Firmas registradas y solicitudes ARCO (plazo legal)
        'firmas_usuarios', 'solicitudes_arco',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Restricciones::cambiarAccionAlBorrar($tabla, ['empresa_id' => 'empresas'], 'restrict');
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            Restricciones::cambiarAccionAlBorrar($tabla, ['empresa_id' => 'empresas'], 'cascade');
        }
    }
};
