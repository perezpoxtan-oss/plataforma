<?php

namespace App\Services\Auditoria;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\ConfiguracionPlataforma;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\EnlaceKiosco;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\EtiquetaPlantilla;
use App\Models\EvaluacionCandidato;
use App\Models\FirmaUsuario;
use App\Models\Gafete;
use App\Models\GrupoEspacio;
use App\Models\Importacion;
use App\Models\ImpresionEtiquetas;
use App\Models\Llave;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundReportePerdida;
use App\Models\Modulo;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\Paradero;
use App\Models\PaseSalida;
use App\Models\PaseSalidaPaso;
use App\Models\Persona;
use App\Models\Postulacion;
use App\Models\PrestamoLlave;
use App\Models\Procedimiento;
use App\Models\ProcedimientoAcuse;
use App\Models\ProcedimientoCategoria;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\RecorridoPc;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\ReservaServicio;
use App\Models\ReservaTipoUso;
use App\Models\Responsiva;
use App\Models\Rol;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoEspacio;
use App\Models\TipoGafete;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacante;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Traduce la bitácora técnica (evento "modulo.accion", modelo e id, antes y
 * después en JSON) a algo legible: "Colaboradores · Alta provisional ·
 * Jorge Méndez Tun", con la lista de campos que cambiaron.
 */
class LectorAuditoria
{
    /**
     * Verbo de cada acción registrada (la parte después del punto del evento
     * "modulo.accion"). Toda acción que se registra en la bitácora debe estar
     * aquí, con acentos (LectorAuditoriaTest lo revisa contra el código).
     */
    public const ACCIONES = [
        // Altas, cambios y bajas
        'creado' => 'Alta', 'creada' => 'Alta', 'actualizado' => 'Edición', 'actualizada' => 'Edición',
        'desactivado' => 'Desactivación', 'desactivada' => 'Desactivación', 'reactivado' => 'Reactivación', 'reactivada' => 'Reactivación',
        'eliminado' => 'Eliminación', 'eliminada' => 'Eliminación', 'eliminado_definitivo' => 'Eliminación definitiva',
        'desbloqueado' => 'Desbloqueo', 'rol_asignado' => 'Asignación de rol',
        'restringido' => 'Acceso restringido', 'restriccion_quitada' => 'Restricción quitada', 'no_regreso' => 'No regresó',
        'excepcion' => 'Préstamo por excepción',
        'rol_actualizado' => 'Cambio de permisos', 'sedes' => 'Cambio de sedes', 'sedes_actualizadas' => 'Cambio de sedes', 'sede_agregada' => 'Alta de sede',
        'lote' => 'Alta por lote', 'pisos_copiados' => 'Copia de pisos', 'movido' => 'Cambio de lugar', 'seccion_creada' => 'Alta de sección',
        'seccion_asignada' => 'Asignación a sección', 'tipo_creado' => 'Alta de tipo', 'clonado' => 'Copia',
        'logo_actualizado' => 'Cambio de logotipo',
        // Catálogo comercial (productos, planes, paquetes, ajustes y solicitudes de la página pública)
        'producto_creado' => 'Alta de producto', 'producto_actualizado' => 'Edición de producto', 'producto_dado_de_baja' => 'Baja de producto', 'producto_reactivado' => 'Reactivación de producto',
        'plan_creado' => 'Alta de plan', 'plan_actualizado' => 'Edición de plan', 'plan_dado_de_baja' => 'Baja de plan', 'plan_reactivado' => 'Reactivación de plan',
        'paquete_creado' => 'Alta de paquete', 'paquete_actualizado' => 'Edición de paquete', 'paquete_dado_de_baja' => 'Baja de paquete', 'paquete_reactivado' => 'Reactivación de paquete',
        'ajustes_actualizados' => 'Cambio de ajustes', 'solicitud_atendida' => 'Solicitud atendida',
        // Suscripciones (clientes, promociones y excepciones)
        'promocion_creada' => 'Alta de promoción', 'promocion_actualizada' => 'Edición de promoción', 'promocion_dada_de_baja' => 'Baja de promoción',
        'promocion_reactivada' => 'Reactivación de promoción', 'activada' => 'Activación', 'plan_cambiado' => 'Cambio de plan',
        'pago_registrado' => 'Pago recibido', 'suspendida' => 'Baja a solo consulta', 'revisada' => 'Revisión',
        'excepcion_agregada' => 'Alta de excepción', 'excepcion_quitada' => 'Baja de excepción', 'promocion_aplicada' => 'Promoción aplicada',
        // Altas provisionales y verificación de padrones
        'provisional' => 'Alta provisional', 'validado' => 'Validación', 'verificado' => 'Verificación', 'fusionado' => 'Unión de duplicado',
        // C-11: la cuenta tomó el nombre y el número de su colaborador
        'sincronizado' => 'Nombre y número tomados de su colaborador',
        // Identificación (QR y etiqueta NFC/RFID) e impresión de etiquetas
        'etiqueta_asignada' => 'Asignación de etiqueta NFC/RFID', 'etiqueta_quitada' => 'Retiro de etiqueta NFC/RFID',
        'impreso' => 'Impresión', 'reimpreso' => 'Reimpresión',
        'plantilla_creada' => 'Alta de plantilla', 'plantilla_actualizada' => 'Edición de plantilla',
        'plantilla_desactivada' => 'Desactivación de plantilla', 'plantilla_reactivada' => 'Reactivación de plantilla',
        // Aprobaciones, firmas y respuestas
        'aprobado' => 'Aprobación', 'aprobada' => 'Aprobación', 'rechazado' => 'Rechazo', 'rechazada' => 'Rechazo',
        'firmado' => 'Firma', 'firmado_papel' => 'Firma en papel', 'acuse' => 'Acuse de recibo', 'acuse_firmado' => 'Acuse de recibo',
        'autorizado' => 'Autorización', 'autorizado_excepcion' => 'Autorización por excepción', 'no_autorizado' => 'Negativa de autorización', 'respondida' => 'Respuesta',
        'cancelado' => 'Cancelación', 'cancelada' => 'Cancelación', 'anulado' => 'Anulación', 'paso_omitido' => 'Omisión de paso',
        'reenviado' => 'Reenvío', 'enviado' => 'Envío a revisión', 'firma_guardada' => 'Registro de firma guardada', 'firma_borrada' => 'Eliminación de firma guardada',
        'circuito_actualizado' => 'Cambio del circuito de aprobación', 'circuito_restablecido' => 'Restablecimiento del circuito de aprobación',
        'ajustes_actualizados' => 'Cambio de firmas en caseta y rechazo',
        'responsables' => 'Cambio de responsables', 'delegacion_creada' => 'Alta de delegación', 'delegacion_cancelada' => 'Cancelación de delegación',
        'recordatorio' => 'Recordatorio por tardanza', 'escalada' => 'Escalamiento al suplente o jefe',
        // Etapa 4 (C-22, C-25): reloj y suplente, caseta de noche
        'suplente' => 'Paso al suplente por tardanza', 'relojes' => 'Cambio de «Reloj y suplente»',
        'supervisor_pedido' => 'Pedido al supervisor desde la caseta', 'autorizado_excepcion_agente' => 'Aprobación por excepción del agente',
        'excepcion_revisada' => 'Revisión de la excepción del agente',
        // Movimientos (accesos, pases de salida, préstamos y resguardos)
        'salida' => 'Salida', 'salida_acompanante' => 'Salida de acompañante', 'salida_temporal' => 'Salida temporal',
        'salida_temporal_acompanante' => 'Salida temporal de acompañante', 'regreso' => 'Regreso', 'regreso_acompanante' => 'Regreso de acompañante',
        'zona_cambiada' => 'Cambio de zona de estacionamiento', 'salida_registrada' => 'Registro de salida', 'recibido_en_destino' => 'Recepción en destino',
        'salida_de_regreso' => 'Salida de regreso', 'regresado' => 'Regreso', 'regreso_parcial' => 'Regreso parcial', 'regreso_reprogramado' => 'Cambio del regreso programado',
        'recibido' => 'Recepción', 'equipo_recibido' => 'Recepción de equipo', 'asignado' => 'Asignación', 'devuelto' => 'Devolución',
        'recuperado' => 'Recuperación', 'reembolsado' => 'Reembolso',
        // Novedades, Lost & Found y recorridos
        'resuelto' => 'Resolución', 'reabierto' => 'Reapertura', 'cerrado' => 'Cierre', 'vinculado' => 'Vinculación',
        'hallazgo_vinculado' => 'Vinculación de hallazgo', 'finalizado' => 'Finalización', 'ticket_generado' => 'Generación de ticket',
        // Procedimientos
        'version_creada' => 'Nueva versión', 'version_descartada' => 'Descarte de versión', 'retirado' => 'Retiro',
        'categoria_creada' => 'Alta de categoría', 'categoria_actualizada' => 'Edición de categoría',
        // Candidatos y vacantes
        'etapa' => 'Cambio de etapa', 'contratado' => 'Contratación', 'documento_agregado' => 'Alta de documento',
        'documento_eliminado' => 'Eliminación de documento', 'enlace_kiosco' => 'Generación de enlace de kiosco',
        'enlace_revocado' => 'Revocación de enlace de kiosco', 'autocaptura' => 'Captura en el kiosco', 'postulacion' => 'Postulación',
        'exportado_con_datos_personales' => 'Exportación con datos personales', 'configurado' => 'Cambio de configuración',
        'publicada' => 'Publicación', 'pausada' => 'Pausa', 'reanudada' => 'Reanudación', 'cerrada' => 'Cierre', 'reabierta' => 'Reapertura',
        'bolsa_configurada' => 'Configuración de la bolsa de trabajo',
        'postulacion_creada' => 'Nueva postulación', 'visita_ligada' => 'Visita ligada a su postulación', 'espera' => 'Petición de espera',
        // Candidatos, fase 2: entrevistas, canalización y elección
        'evaluado_rh' => 'Evaluación de la entrevista de RR. HH.', 'canalizado' => 'Canalización al departamento',
        'reprogramado' => 'Reprogramación de entrevista', 'no_se_presento' => 'No se presentó a su entrevista',
        'evaluado_departamento' => 'Evaluación de la entrevista del departamento', 'evaluacion_corregida' => 'Corrección de la evaluación del departamento',
        'correo_candidato' => 'Correo al candidato (su cita o el enlace de su solicitud)',
        // Candidatos, fase 3: documentos para contratar, contrato, plazos y caducidad
        'documentos_configurados' => 'Cambio de la lista de documentos para contratar', 'plazos_configurados' => 'Cambio de plazos de candidatos',
        'contrato_firmado' => 'Registro del contrato firmado', 'documento_papel' => 'Documento recibido en papel',
        'plazo' => 'Recordatorio por plazo vencido', 'archivada' => 'Archivo por caducidad', 'aviso_borrado' => 'Aviso de borrado por caducidad',
        'borrado_por_caducidad' => 'Borrado por caducidad', 'conservada' => 'Conservación de la ficha',
        // Configuración
        'avisos' => 'Cambio de avisos por correo', 'correo' => 'Cambio del correo de la plataforma', 'correo_prueba' => 'Correo de prueba',
        'respaldo_creado' => 'Creación de respaldo', 'respaldo_descargado' => 'Descarga de respaldo',
        // Respaldos y traslado a otro servidor; exportación de una empresa
        'traslado_generado' => 'Generación del paquete de traslado', 'traslado_descargado' => 'Descarga del paquete de traslado',
        'traslado_verificado' => 'Verificación de paquete', 'traslado_restaurado' => 'Restauración de paquete de traslado',
        'traslado_borrado' => 'Borrado de paquete de traslado', 'traslado_ajustes' => 'Cambio del paquete automático semanal',
        'exportada' => 'Exportación de los datos de la empresa', 'exportacion_descargada' => 'Descarga de la exportación de la empresa',
        'exportacion_borrada' => 'Borrado de la exportación de la empresa',
        // Mejoras activos (ADR-0014)
        'caseta' => 'Cambio de ajustes de caseta y activos',
        // Configuración por secciones: nombres del menú y «Restablecer valor original»
        'menu' => 'Cambio de nombres del menú', 'restablecido' => 'Ajuste regresado a su valor original',
        // Sesión
        'inicio' => 'Inicio de sesión', 'cierre' => 'Cierre de sesión', 'fallido' => 'Intento fallido', 'bloqueado' => 'Bloqueo',
        // Cuenta del usuario (Mi cuenta, Olvidé mi contraseña, bienvenida, baja)
        'contrasena_cambiada' => 'Cambio de contraseña propia', 'recuperacion_solicitada' => 'Solicitud de recuperación de contraseña',
        'recuperacion_completada' => 'Recuperación de contraseña', 'bienvenida_enviada' => 'Envío de enlace de bienvenida',
        'enlace_restablecer_enviado' => 'Envío de enlace para restablecer contraseña', 'desactivado_por_baja' => 'Desactivación por baja del colaborador',
        // Mejoras Incidentes (ADR-0013)
        'turno_avisado' => 'Aviso al turno que recibe el pendiente', 'turno_escalado' => 'Aviso al jefe: pendiente de turno sin atender',
        'objeto_registrado' => 'Registro de objeto encontrado', 'tiempos_espera' => 'Cambio de tiempos de espera de las firmas',
        // Fin Mejoras Incidentes
        // Mejoras estructura (ADR-0016)
        'modulos_actualizados' => 'Cambio de módulos contratados', 'auditoria' => 'Cambio de ajustes de la bitácora',
        // Fin Mejoras estructura
        // Importar desde Excel y «Configura tu empresa»
        'importado' => 'Importación desde archivo', 'configura_tu_empresa' => 'Cambio de «Configura tu empresa»',
        // Reservas de áreas (docs/tecnico/reservas.md)
        'modificada' => 'Modificación', 'confirmada' => 'Confirmación', 'cambio_pedido' => 'Petición de cambio', 'costo_ajustado' => 'Ajuste de costo',
        'orden_lista' => 'Orden de servicio lista', 'area_reservable' => 'Área marcada como reservable', 'area_no_reservable' => 'Área ya no reservable',
        'area_configurada' => 'Cambio de la configuración del área', 'area_modo' => 'Cambio de cómo se reserva la zona', 'area_quitada' => 'Área eliminada de reservas', 'catalogo_creado' => 'Alta en el catálogo', 'catalogo_actualizado' => 'Edición en el catálogo',
        'catalogo_baja' => 'Baja en el catálogo', 'catalogo_reactivado' => 'Reactivación en el catálogo', 'catalogo_eliminado' => 'Eliminación en el catálogo',
        'sugeridos_hotel' => 'Carga de servicios sugeridos de hotel',
        // Verificación en dos pasos (docs/tecnico/dos-pasos.md)
        'dos_pasos' => 'Cambio de a quién se exige la verificación en dos pasos', 'dos_pasos_activada' => 'Verificación en dos pasos activada',
        'dos_pasos_desactivada' => 'Verificación en dos pasos quitada', 'dos_pasos_codigos' => 'Códigos de respaldo nuevos',
        'dos_pasos_fallido' => 'Código de verificación equivocado', 'dos_pasos_respaldo_usado' => 'Entrada con código de respaldo',
        'dos_pasos_restablecida' => 'Verificación en dos pasos restablecida',
        // Privacidad (docs/tecnico/privacidad.md)
        'aviso_publicado' => 'Versión nueva del aviso de privacidad', 'conservacion' => 'Cambio de días de conservación',
        'conservacion_aplicada' => 'Datos quitados por conservación', 'coherencia_reparada' => 'Dato corregido desde su fuente (revisión de coherencia)', 'arco_recibida' => 'Solicitud ARCO recibida',
        'arco_verificada' => 'Solicitud ARCO verificada', 'arco_exportado' => 'Datos exportados (ARCO)', 'arco_descargado' => 'Descarga de datos (ARCO)',
        'arco_eliminado' => 'Datos eliminados (ARCO)', 'arco_atendida' => 'Solicitud ARCO atendida', 'arco_rechazada' => 'Solicitud ARCO rechazada',
        'arco_canalizada' => 'Solicitud ARCO canalizada',
        // C-36: «Pedir a mi administrador» y Super Administradores
        'solicitud_cuenta' => 'Pidió ayuda a su administrador (contraseña o dos pasos)', 'solicitud_cuenta_atendida' => 'Petición de ayuda con la cuenta atendida',
        'solicitud_cuenta_rechazada' => 'Petición de ayuda con la cuenta rechazada', 'superadmin_creado' => 'Alta de Super Administrador',
        'superadmin_dado_de_baja' => 'Baja de Super Administrador', 'superadmin_reactivado' => 'Super Administrador reactivado',
        'superadmin_contrasena' => 'Contraseña de Super Administrador restablecida',
    ];

    /**
     * Nombre legible de los eventos cuyo módulo no está en el catálogo (sesión,
     * kiosco y bolsa pública…). Los demás toman el nombre del módulo.
     */
    public const MODULOS_EXTRA = [
        'sesion' => 'Inicio de sesión',
        'auth' => 'Inicio de sesión',
        'plataforma' => 'Plataforma',
        'kiosco' => 'Kiosco de candidatos',
        'empleos' => 'Bolsa de trabajo',
        'etiquetas' => 'Etiquetas QR',
        'borrado' => 'Eliminar definitivamente',
        'suscripciones' => 'Suscripciones',
        'cuenta' => 'Mi cuenta',
        'privacidad' => 'Privacidad',
        'relojes' => 'Reloj y suplente',
    ];

    /** Qué es cada registro y cómo se llama. */
    private const REGISTROS = [
        Empresa::class => ['Empresa', 'nombre_comercial'],
        Sede::class => ['Sede', 'nombre'],
        User::class => ['Usuario', 'name'],
        Rol::class => ['Rol', 'nombre'],
        Espacio::class => ['Espacio', 'nombre'],
        GrupoEspacio::class => ['Sección', 'nombre'],
        TipoEspacio::class => ['Tipo de espacio', 'nombre'],
        Departamento::class => ['Departamento', 'nombre'],
        Puesto::class => ['Puesto', 'nombre'],
        Turno::class => ['Turno', 'nombre'],
        Colaborador::class => ['Colaborador', null],
        Proveedor::class => ['Proveedor', 'nombre'],
        Persona::class => ['Persona', 'nombre_completo'],
        Vehiculo::class => ['Vehículo', 'placas'],
        VoucherReposicion::class => ['Voucher', 'folio'],
        Llave::class => ['Llave', 'nomenclatura'],
        Gafete::class => ['Gafete', 'nomenclatura'],
        TipoGafete::class => ['Tipo de gafete', 'nombre'],
        Equipo::class => ['Equipo', 'numero_serie'],
        TipoEquipo::class => ['Tipo de equipo', 'nombre'],
        ZonaEstacionamiento::class => ['Zona de estacionamiento', 'nombre'],
        Ruta::class => ['Ruta de transporte', 'nombre'],
        Paradero::class => ['Paradero', 'nombre'],
        Acceso::class => ['Acceso', 'nombre'],
        AcompananteAcceso::class => ['Acompañante', 'nombre'],
        PrestamoLlave::class => ['Préstamo de llave', null],
        Responsiva::class => ['Resguardo', 'folio'],
        PaseSalida::class => ['Pase de salida', 'folio'],
        MovimientoTransporte::class => ['Movimiento de transporte', null],
        LostFoundArticulo::class => ['Artículo de Lost & Found', 'folio'],
        EquipoPc::class => ['Equipo de Protección Civil', 'numero_serie'],
        RecorridoPc::class => ['Recorrido de Protección Civil', 'numero'],
        Procedimiento::class => ['Procedimiento', 'clave'],
        ProcedimientoCategoria::class => ['Categoría de procedimientos', 'nombre'],
        ProcedimientoAcuse::class => ['Acuse de procedimiento', 'nombre'],
        Candidato::class => ['Candidato', 'nombre_completo'],
        Postulacion::class => ['Postulación', 'vacante'],
        EvaluacionCandidato::class => ['Evaluación de entrevista', null],
        Autorizacion::class => ['Autorización departamental', null],
        Delegacion::class => ['Delegación de autorizaciones', null],
        EtiquetaPlantilla::class => ['Plantilla de etiquetas QR', 'nombre'],
        ImpresionEtiquetas::class => ['Impresión de etiquetas QR', 'plantilla_nombre'],
        Vacante::class => ['Vacante', 'titulo'],
        CandidatoDocumento::class => ['Documento de candidato', 'nombre_original'],
        EnlaceKiosco::class => ['Enlace de kiosco', null],
        Novedad::class => ['Novedad', null],
        LostFoundReportePerdida::class => ['Reporte de pérdida', null],
        DepartamentoResponsable::class => ['Responsable de departamento', null],
        PaseSalidaPaso::class => ['Circuito de pases de salida', 'nombre'],
        FirmaUsuario::class => ['Firma guardada', null],
        ConfiguracionPlataforma::class => ['Configuración de la plataforma', 'clave'],
        Importacion::class => ['Importación desde archivo', 'archivo_nombre'],
        // Reservas de áreas
        Reserva::class => ['Reserva de área', 'folio'],
        ReservaArea::class => ['Área reservable', null],
        ReservaMontaje::class => ['Montaje de reservas', 'nombre'],
        ReservaTipoUso::class => ['Tipo de uso de reservas', 'nombre'],
        ReservaServicio::class => ['Servicio de reservas', 'nombre'],
    ];

    // Mejoras estructura (ADR-0016): «Ver historial» desde la ficha de cada registro

    /**
     * Nombre corto del tipo de registro para la dirección (p. ej. «rol», «sede»).
     */
    public static function alias(?string $tipo): ?string
    {
        return $tipo === null || ! isset(self::REGISTROS[$tipo]) ? null : Str::snake(class_basename($tipo));
    }

    /**
     * Clase del registro a partir de su nombre corto (solo los conocidos).
     */
    public static function claseDeAlias(?string $alias): ?string
    {
        foreach (array_keys(self::REGISTROS) as $tipo) {
            if (self::alias($tipo) === $alias) {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * Qué es un registro y cómo se llama («Rol · Agente»), para el título del historial.
     */
    public function nombreDe(string $tipo, int $id): string
    {
        $fila = new Auditoria(['auditable_type' => $tipo, 'auditable_id' => $id]);

        return $this->registros(collect([$fila]))[$tipo.'#'.$id] ?? (self::REGISTROS[$tipo][0] ?? 'Registro').' #'.$id;
    }
    // Fin Mejoras estructura

    /** Módulos que registran algo en la bitácora, para el filtro. */
    public function modulos(Builder $consulta): Collection
    {
        $claves = (clone $consulta)->reorder()->selectRaw('DISTINCT evento')->pluck('evento')
            ->map(fn ($e) => explode('.', $e)[0])->unique()->values();
        $nombres = Modulo::whereIn('clave', $claves)->pluck('nombre', 'clave');

        return $claves->mapWithKeys(fn ($c) => [$c => $nombres[$c] ?? self::MODULOS_EXTRA[$c] ?? ucfirst(str_replace('_', ' ', $c))])->sort();
    }

    public function modulo(string $evento): string
    {
        static $nombres = null;
        $nombres ??= Modulo::pluck('nombre', 'clave');
        $clave = explode('.', $evento)[0];

        return $nombres[$clave] ?? self::MODULOS_EXTRA[$clave] ?? ucfirst(str_replace('_', ' ', $clave));
    }

    public function accion(string $evento): string
    {
        $accion = explode('.', $evento, 2)[1] ?? $evento;

        return self::ACCIONES[$accion] ?? ucfirst(str_replace('_', ' ', $accion));
    }

    /**
     * Nombre legible de los registros de una página, con pocas consultas.
     *
     * @param  Collection<int, Auditoria>  $filas
     * @return array<string, string> "Clase#id" => "Colaborador · Jorge Méndez"
     */
    public function registros(Collection $filas): array
    {
        $resultado = [];
        foreach ($filas->groupBy('auditable_type') as $tipo => $grupo) {
            [$etiqueta, $columna] = self::REGISTROS[$tipo] ?? [class_basename((string) $tipo), null];
            if (! class_exists((string) $tipo)) {
                continue;
            }
            $modelos = $tipo::withoutGlobalScopes()->whereIn('id', $grupo->pluck('auditable_id')->filter()->unique())->get()->keyBy('id');
            foreach ($grupo as $fila) {
                $m = $modelos[$fila->auditable_id] ?? null;
                $nombre = $m === null ? '#'.$fila->auditable_id.' (ya no existe)'
                    : ($m instanceof Colaborador ? $m->nombreCompleto()
                        : ($m instanceof Novedad ? $m->folio()
                            : ($m instanceof EvaluacionCandidato ? $m->etiquetaTipo().' · '.$m->etiquetaResultado()
                                : (string) ($columna ? $m->{$columna} : '#'.$m->getKey()))));
                $resultado[$tipo.'#'.$fila->auditable_id] = $etiqueta.' · '.$nombre;
            }
        }

        return $resultado;
    }

    /**
     * Campos del antes y el después, marcando los que cambiaron.
     *
     * @return list<array{campo: string, antes: string, despues: string, cambio: bool}>
     */
    public function diferencias(?array $antes, ?array $despues): array
    {
        $antes ??= [];
        $despues ??= [];
        $filas = [];
        foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $campo) {
            $a = $this->texto($antes[$campo] ?? null);
            $d = $this->texto($despues[$campo] ?? null);
            $filas[] = ['campo' => str_replace('_', ' ', (string) $campo), 'antes' => array_key_exists($campo, $antes) ? $a : '—', 'despues' => array_key_exists($campo, $despues) ? $d : '—', 'cambio' => $a !== $d];
        }

        return $filas;
    }

    private function texto(mixed $valor): string
    {
        return match (true) {
            $valor === null => 'vacío',
            is_bool($valor) => $valor ? 'sí' : 'no',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $valor,
        };
    }
}
