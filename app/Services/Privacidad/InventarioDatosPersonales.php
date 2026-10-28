<?php

namespace App\Services\Privacidad;

/**
 * Inventario de las tablas con datos personales (C-37, X-01;
 * docs/tecnico/privacidad.md, «Inventario»). Es la lista que revisa la
 * prueba InventarioDatosPersonalesTest: si una tabla nueva (o una columna
 * nueva) tiene un nombre de columna que suele guardar datos de una persona
 * (PATRON) y no está aquí, la prueba falla. Así nadie agrega datos
 * personales sin decidir cómo los alcanzan las solicitudes ARCO y la
 * conservación.
 *
 * Cada tabla dice:
 *  - trato:
 *      arco       la búsqueda ARCO la encuentra y la exporta; «Eliminar» quita
 *                 los datos de la persona (tipo = clave de DatosDePersona::TIPOS).
 *      referencia sus datos se tratan con los del registro padre (tipo del padre).
 *      laboral    datos del personal de la empresa (relación laboral): se
 *                 consultan y rectifican en su ficha; no se cancelan por ARCO.
 *      cuenta     cuentas de la plataforma y su seguridad (Usuarios, Mi cuenta).
 *      plataforma datos que guarda la plataforma, no la empresa (los atiende el
 *                 Super Administrador desde la bandeja ARCO de la plataforma).
 *      empresa    datos de una empresa o sede (no de una persona física).
 *      sin_datos  la columna se llama como un dato personal pero no lo es
 *                 (el nombre de un catálogo, la ruta de un archivo del sistema).
 *  - columnas: las columnas con datos personales (todas las que cumplen PATRON).
 *  - conservacion: la clave de ConservacionDatos::DIAS que la alcanza, o por qué no.
 */
final class InventarioDatosPersonales
{
    /** Nombres de columna que suelen guardar datos de una persona. */
    public const PATRON = '/^(name|email|ip|ip_address|user_agent|payload)$|(^|_)(nombre|nombres|apellido|apellidos|telefono|correo|curp|rfc|nss|firma|foto|identificacion|domicilio|direccion|nacimiento|edad|sexo|medico|hospital|diagnostico|placas|declaracion|involucrados|huesped|cliente|conductor|chofer|pasajeros?|testigos?|sospechoso|emergencia|habitacion|reporta|reportado|atiende|recibe|visita|autor|contacto|referencias|familiares|explicacion|pais|agencia|observados|incapacidad)(_|$)/';

    /** Tablas con datos personales que PATRON no reconoce por el nombre de sus columnas (A-07): deben estar registradas. */
    public const SIN_PATRON = ['notificaciones', 'solicitudes_cuenta'];

    /** Tablas del sistema que no se revisan. */
    public const SISTEMA = ['migrations', 'sqlite_sequence', 'cache', 'cache_locks'];

    private const LABORAL = 'Personal de la empresa: se consulta y rectifica en su ficha; la relación laboral obliga a conservarlo.';

    private const CATALOGO = 'Nombre de un catálogo o de un archivo del sistema, no de una persona.';

    /** @var array<string, array{trato: string, tipo?: string, columnas: list<string>, conservacion: string}> */
    public const TABLAS = [
        // ------------------------------------------------ Caseta y padrones
        'personas' => ['trato' => 'arco', 'tipo' => 'persona', 'columnas' => ['nombre_completo', 'tipo_identificacion', 'folio_identificacion', 'telefono', 'motivo_visita'],
            'conservacion' => 'Padrón vigente: la ficha se conserva mientras la persona siga visitando; ARCO la deja anónima y de baja.'],
        'accesos' => ['trato' => 'arco', 'tipo' => 'acceso', 'columnas' => ['nombre', 'motivo_visita', 'visita_colaborador_id', 'persona_visita', 'identificacion', 'identificacion_devuelta', 'placas', 'conductor', 'habitacion', 'tipo_visita', 'tipo_emergencia', 'foto_persona', 'foto_identificacion'],
            'conservacion' => 'visitantes_dias'],
        'acompanantes_acceso' => ['trato' => 'referencia', 'tipo' => 'acceso', 'columnas' => ['nombre', 'identificacion'], 'conservacion' => 'visitantes_dias'],
        'vehiculos' => ['trato' => 'referencia', 'tipo' => 'acceso', 'columnas' => ['placas'],
            'conservacion' => 'Padrón vehicular: las placas solas no identifican a la persona; las de cada visita se quitan con su registro de la bitácora.'],

        // ------------------------------------------------ Recursos Humanos
        'candidatos' => ['trato' => 'arco', 'tipo' => 'candidato', 'columnas' => ['nombre_completo', 'telefono', 'correo', 'fecha_nacimiento', 'referencias', 'nombre', 'apellido_paterno', 'apellido_materno', 'sexo', 'lugar_nacimiento', 'curp', 'rfc', 'nss', 'estado_domicilio', 'telefono_fijo', 'emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono', 'referencias_laborales', 'tiene_familiares', 'familiares_nombre', 'declaracion_aceptada_en', 'firma_ruta', 'firma_en', 'firma_medio', 'firma_capturada_por'],
            'conservacion' => 'Candidatos → Documentos y plazos (borrar a los 12 meses sin movimiento).'],
        'candidato_documentos' => ['trato' => 'referencia', 'tipo' => 'candidato', 'columnas' => ['nombre_original'], 'conservacion' => 'Con su candidato.'],
        'colaboradores' => ['trato' => 'arco', 'tipo' => 'colaborador', 'columnas' => ['nombre', 'apellido_paterno', 'apellido_materno', 'nombre_normalizado', 'telefono', 'fecha_nacimiento', 'lugar_nacimiento', 'curp', 'rfc', 'nss', 'correo_personal', 'direccion_completa'],
            'conservacion' => self::LABORAL],
        'vacantes' => ['trato' => 'laboral', 'columnas' => ['contacto_nombre', 'contacto_telefono', 'contacto_correo'], 'conservacion' => self::LABORAL],

        // ------------------------------------------------ Novedades, Lost & Found y Accidentes
        'novedades' => ['trato' => 'arco', 'tipo' => 'novedad', 'columnas' => ['reportado_por', 'reportado_colaborador_id', 'involucrados'], 'conservacion' => 'incidentes_dias'],
        'novedad_testigos' => ['trato' => 'referencia', 'tipo' => 'novedad', 'columnas' => ['nombre', 'declaracion'], 'conservacion' => 'incidentes_dias'],
        'novedad_notas' => ['trato' => 'referencia', 'tipo' => 'novedad', 'columnas' => ['autor_nombre'],
            'conservacion' => 'El autor es personal de la empresa; si el texto menciona a la persona, ARCO quita su nombre.'],
        // Revisión de la Etapa 3b: «¿Quién o quiénes fueron observados?» del Reporte General guarda nombres de personas
        'novedad_reportes_generales' => ['trato' => 'referencia', 'tipo' => 'novedad', 'columnas' => ['observados'], 'conservacion' => 'incidentes_dias'],
        'robo_detalles' => ['trato' => 'referencia', 'tipo' => 'novedad', 'columnas' => ['hay_sospechoso', 'descripcion_sospechoso'], 'conservacion' => 'incidentes_dias'],
        'valores_vista_detalles' => ['trato' => 'laboral', 'columnas' => ['num_habitacion', 'quien_reporta', 'depto_reporta', 'puesto_reporta', 'actividad_reporta', 'quien_atiende', 'depto_atiende', 'puesto_atiende', 'cliente_llega'],
            'conservacion' => 'Quién reporta y quién atiende es personal de la empresa; la habitación sola no identifica al huésped.'],
        'valores_vista_personas' => ['trato' => 'laboral', 'columnas' => ['nombre'], 'conservacion' => self::LABORAL],
        'lost_found_reportes_perdida' => ['trato' => 'arco', 'tipo' => 'lost_found_reporte', 'columnas' => ['nombre_huesped', 'telefono', 'correo'], 'conservacion' => 'lost_found_dias'],
        'lost_found_entregas' => ['trato' => 'arco', 'tipo' => 'lost_found_entrega', 'columnas' => ['nombre_recibe', 'tipo_identificacion', 'correo_recibe', 'firma_ruta'], 'conservacion' => 'lost_found_dias'],
        'accidente_huespedes' => ['trato' => 'arco', 'tipo' => 'accidente', 'columnas' => ['nombre', 'num_habitacion', 'agencia', 'pais', 'sexo', 'edad', 'explicacion', 'hubo_testigos', 'detalles_testigos'],
            'conservacion' => 'incidentes_dias'],
        'accidente_dictamenes' => ['trato' => 'referencia', 'tipo' => 'accidente', 'columnas' => ['diagnostico', 'nombre_hospital', 'nombre_medico'], 'conservacion' => 'incidentes_dias'],
        'accidente_guardavidas' => ['trato' => 'referencia', 'tipo' => 'accidente', 'columnas' => ['nombre', 'acudio_servicio_medico'],
            'conservacion' => 'incidentes_dias (se quitan las lesiones del huésped; el nombre es del guardavidas, personal de la empresa).'],
        'accidente_firmas' => ['trato' => 'referencia', 'tipo' => 'accidente', 'columnas' => [], 'conservacion' => 'incidentes_dias (la firma del afectado).'],
        'accidente_incapacidades' => ['trato' => 'laboral', 'columnas' => ['dias_incapacidad'], 'conservacion' => self::LABORAL.' Dato de salud: ARCO lo exporta con el accidente.'],
        'accidente_colaboradores' => ['trato' => 'laboral', 'columnas' => ['explicacion_causas'], 'conservacion' => self::LABORAL.' ARCO lo muestra para consultarlo.'],

        // ------------------------------------------------ Reservas y transporte
        'reservas' => ['trato' => 'arco', 'tipo' => 'reserva', 'columnas' => ['para_cliente', 'cliente_nombre', 'cliente_empresa', 'cliente_correo', 'cliente_telefono'], 'conservacion' => 'reservas_dias'],
        'movimientos_transporte' => ['trato' => 'arco', 'tipo' => 'transporte', 'columnas' => ['chofer_id', 'firma_guardia', 'firma_taxista'],
            'conservacion' => 'transporte_dias (firma del taxista; la del guardia es personal de la empresa).'],
        'movimiento_transporte_pasajeros' => ['trato' => 'laboral', 'columnas' => [], 'conservacion' => 'Los pasajeros son colaboradores. '.self::LABORAL],

        // ------------------------------------------------ Activos, pases y procedimientos (personal de la empresa)
        'pases_salida' => ['trato' => 'laboral', 'columnas' => ['destino_direccion', 'destino_telefono'], 'conservacion' => 'Dirección y teléfono del destino del pase (de la empresa o del proveedor), no de una persona.'],
        'pases_salida_aprobaciones' => ['trato' => 'laboral', 'columnas' => ['nombre', 'firma_id'], 'conservacion' => self::LABORAL],
        'pases_salida_bitacora' => ['trato' => 'laboral', 'columnas' => ['usuario_nombre', 'ip'], 'conservacion' => self::LABORAL],
        'pases_salida_firmas' => ['trato' => 'laboral', 'columnas' => ['nombre_firma', 'firma_ruta'], 'conservacion' => self::LABORAL],
        'procedimiento_acuses' => ['trato' => 'laboral', 'columnas' => ['nombre', 'firma_ruta', 'ip'], 'conservacion' => self::LABORAL],
        'procedimiento_eventos' => ['trato' => 'laboral', 'columnas' => ['usuario_nombre', 'ip'], 'conservacion' => self::LABORAL],
        'procedimiento_versiones' => ['trato' => 'laboral', 'columnas' => ['aprobador_nombre', 'firma_ruta'], 'conservacion' => self::LABORAL],
        'firmas_usuarios' => ['trato' => 'laboral', 'columnas' => ['firma_ruta'], 'conservacion' => self::LABORAL],
        // Etapa 2 (C-16): el receptor o el responsable también puede ser una persona del Padrón o su empresa externa
        // (receptor_tipo/_id, responsable_tipo/_id). ARCO los encuentra por ese id; si es un colaborador, es relación laboral.
        'prestamos_llaves' => ['trato' => 'arco', 'tipo' => 'prestamo_llave', 'columnas' => ['folio_garantia', 'receptor_tipo', 'receptor_id'],
            'conservacion' => 'Se conserva como comprobante del préstamo; para una persona externa, ARCO quita la garantía (su nombre se quita en el Padrón). Colaboradores: '.self::LABORAL],
        'responsivas' => ['trato' => 'arco', 'tipo' => 'responsiva', 'columnas' => ['firma_ruta', 'receptor_tipo', 'receptor_id'],
            'conservacion' => 'Se conserva como comprobante del resguardo; para una persona externa, ARCO borra su firma (su nombre se quita en el Padrón). Colaboradores: '.self::LABORAL],
        'vouchers_reposicion' => ['trato' => 'arco', 'tipo' => 'voucher', 'columnas' => ['firma_modo', 'firma_seguridad', 'firma_responsable', 'responsable_tipo', 'responsable_id'],
            'conservacion' => 'Se conserva como comprobante del cobro; para una persona externa, ARCO borra su firma y quita su nombre de «¿Cómo pasó?». Colaboradores: '.self::LABORAL],
        // Etapa 4 (C-24): quién de la caseta reportó un equipo faltante que no podía dar de baja
        'equipos_responsiva' => ['trato' => 'laboral', 'columnas' => ['faltante_reportado_en', 'faltante_reportado_por', 'faltante_nota'],
            'conservacion' => 'Cuenta del personal que reportó el faltante y su nota: se conserva con la responsiva. '.self::LABORAL],
        'importaciones' => ['trato' => 'laboral', 'columnas' => ['archivo_nombre'],
            'conservacion' => 'Archivo de importación de colaboradores y padrones: se conserva para revisar lo importado; lo importado vive en su padrón, que sí alcanza ARCO.'],

        // ------------------------------------------------ Cuentas y seguridad
        'users' => ['trato' => 'cuenta', 'columnas' => ['name', 'email'], 'conservacion' => 'Cuenta vigente: se da de baja en Usuarios.'],
        'sessions' => ['trato' => 'cuenta', 'columnas' => ['ip_address', 'user_agent', 'payload'], 'conservacion' => 'Sesiones: expiran solas.'],
        'password_reset_tokens' => ['trato' => 'cuenta', 'columnas' => ['email'], 'conservacion' => 'Enlaces de 60 minutos.'],
        'auditoria' => ['trato' => 'cuenta', 'columnas' => ['ip'], 'conservacion' => 'Bitácora de auditoría: evidencia de seguridad; guarda la red, no datos de visitantes.'],
        'jobs' => ['trato' => 'cuenta', 'columnas' => ['payload'], 'conservacion' => 'Tareas en cola: se borran al terminar.'],
        'failed_jobs' => ['trato' => 'cuenta', 'columnas' => ['payload'], 'conservacion' => 'Tareas fallidas: se revisan y se borran.'],
        // A-07: no los reconoce PATRON por su nombre, pero guardan datos de personas
        'notificaciones' => ['trato' => 'cuenta', 'columnas' => ['titulo', 'texto'],
            'conservacion' => 'Avisos internos de la campana para las cuentas de la empresa. Su título o texto puede nombrar a una persona (por ejemplo, la visita que espera autorización). Son del personal que los recibe; ARCO no los busca y no se borran solos (pendiente para el abogado, ver privacidad.md).'],
        'solicitudes_cuenta' => ['trato' => 'cuenta', 'columnas' => ['origen'],
            'conservacion' => 'Peticiones «Pedir a mi administrador»: guardan la red recortada desde donde se pidió (sin el último bloque). Historial de seguridad de la cuenta; se borran con la cuenta.'],

        // ------------------------------------------------ Plataforma
        'solicitudes_arco' => ['trato' => 'plataforma', 'columnas' => ['nombre', 'correo', 'telefono', 'ip'], 'conservacion' => 'Evidencia de que se atendió el derecho ARCO.'],
        'solicitudes_compra' => ['trato' => 'plataforma', 'columnas' => ['nombre', 'correo', 'telefono', 'ip'], 'conservacion' => 'Prospectos de la página de planes: los atiende el Super Administrador.'],

        // ------------------------------------------------ Empresas y sedes
        'empresas' => ['trato' => 'empresa', 'columnas' => ['nombre_comercial', 'rfc'], 'conservacion' => 'Datos de la empresa.'],
        'sedes' => ['trato' => 'empresa', 'columnas' => ['nombre', 'direccion', 'telefono'], 'conservacion' => 'Datos de la sede.'],
        'proveedores' => ['trato' => 'empresa', 'columnas' => ['nombre', 'rfc', 'telefono', 'direccion'], 'conservacion' => 'Proveedor (empresa); si es persona física, se rectifica en su ficha.'],

        // ------------------------------------------------ Catálogos (sin datos personales)
        'acciones' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'areas' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'departamentos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'documentos_para_contratar' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'empresa_modulos' => ['trato' => 'sin_datos', 'columnas' => ['nombre_visible'], 'conservacion' => self::CATALOGO],
        'espacios' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'etiquetas_plantillas' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'grupos_espacio' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'horarios_llave' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'impresiones_etiquetas' => ['trato' => 'sin_datos', 'columnas' => ['plantilla_nombre'], 'conservacion' => self::CATALOGO],
        'job_batches' => ['trato' => 'sin_datos', 'columnas' => ['name'], 'conservacion' => self::CATALOGO],
        'menus' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'modulos' => ['trato' => 'sin_datos', 'columnas' => ['nombre', 'nombre_menu'], 'conservacion' => self::CATALOGO],
        'paquetes' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'paraderos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'pases_salida_pasos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'planes' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'procedimiento_adjuntos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'procedimiento_categorias' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'productos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'promociones' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'puestos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'reserva_areas' => ['trato' => 'sin_datos', 'columnas' => ['foto'], 'conservacion' => 'Foto del área, no de una persona.'],
        'reserva_montajes' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'reserva_servicio_items' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'reserva_servicios' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'reserva_tipos_uso' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'roles' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'rubros' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'ruta_horarios' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'rutas' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'tipos_equipo' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'tipos_espacio' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'tipos_gafete' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'turnos' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
        'zonas_estacionamiento' => ['trato' => 'sin_datos', 'columnas' => ['nombre'], 'conservacion' => self::CATALOGO],
    ];

    /**
     * Columnas que por su nombre parecen datos personales.
     *
     * @param  list<string>  $columnas
     * @return list<string>
     */
    public static function columnasPersonales(array $columnas): array
    {
        return array_values(array_filter($columnas, fn (string $c) => preg_match(self::PATRON, $c) === 1));
    }

    /**
     * Tablas que alcanza ARCO (directa o por referencia), por tipo de DatosDePersona.
     *
     * @return array<string, list<string>>
     */
    public static function porTipo(): array
    {
        $r = [];
        foreach (self::TABLAS as $tabla => $d) {
            if (isset($d['tipo'])) {
                $r[$d['tipo']][] = $tabla;
            }
        }

        return $r;
    }
}
