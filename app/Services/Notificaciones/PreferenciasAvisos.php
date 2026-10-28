<?php

namespace App\Services\Notificaciones;

use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\Auditoria\MovimientosSensibles;
use App\Services\Novedades\AjustesNovedades;
use App\Support\GruposAvisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preferencias de avisos de cada persona (ADR-0016): qué tipos de aviso de
 * la campana y qué avisos por correo silenció en «Mi cuenta». Se guardan en
 * users.preferencias_avisos = {"campana": [tipos], "correo": [claves]};
 * nulo = recibe todo (como siempre).
 *
 * Silenciar no quita nada de «Mis pendientes»: lo que espera la respuesta de
 * la persona sigue ahí con su número (criterio 1).
 */
class PreferenciasAvisos
{
    /**
     * Avisos que no se pueden silenciar: los de la propia cuenta (seguridad),
     * el aviso al suplente de lo que tardó y la aprobación por excepción del
     * agente (C-22, C-25: llegan a quien quizá no lo tiene en Mis pendientes).
     */
    public const NO_SILENCIABLES = ['cuenta_contrasena', 'cuenta_dos_pasos', 'plataforma_superadmin', 'reloj_escalado', 'excepcion_agente',
        'reserva_orden_sin_quien']; // C-34: es el único lugar donde quien configura se entera de que una orden no tiene quien la atienda

    /**
     * C-22 (DD-10, E-08): avisos que esperan una acción y su renglón de Mis
     * pendientes. Silenciar uno de estos no borra nada: el asunto sigue en Mis
     * pendientes. Un aviso de nivel «alerta» que no esté aquí no se puede
     * silenciar (sería el único lugar donde aparece).
     */
    public const RENGLON_DE_TIPO = [
        'autorizacion_visita' => 'autorizaciones', 'autorizacion_candidato' => 'autorizaciones', 'autorizacion_recepcion' => 'recepcion',
        'autorizacion_escalada' => 'autorizaciones', 'autorizacion_excepcion' => 'autorizaciones', 'cobertura_perdida' => 'cobertura',
        'acceso_sin_cobertura' => 'esperan_supervisor', 'excepcion_pedida' => 'esperan_supervisor', 'cuenta_sede' => 'cuentas',
        'entrevista_asignada' => 'entrevistas', 'entrevista_llegada' => 'entrevistas', 'plazo_candidato' => 'candidatos', 'caducidad_candidato' => 'candidatos',
        'candidato_postulacion' => 'solicitudes', 'vacante_propuesta' => 'vacantes_propuestas', 'vacante_cubierta' => 'vacantes_cubiertas',
        'cuenta_bloqueada' => 'cuentas', 'cuenta_desactivada' => 'cuentas', 'cuenta_solicitud' => 'cuentas',
        'arco_solicitud' => 'arco', 'arco_plazo' => 'arco',
        'pase_por_firmar' => 'pases_salida', 'pase_rechazado' => 'pases_salida', 'pase_vencido' => 'pases_vencidos',
        'pase_firma_recordatorio' => 'pases_salida', 'pase_firma_escalada' => 'pases_salida',
        'novedad_canalizada' => 'novedades_mias', 'novedad_turno' => 'novedades_turno', 'novedad_turno_escalada' => 'novedades_turno',
        'recorrido_pc_hallazgo' => 'hallazgos_recorrido',
        'vale_taxi_por_autorizar' => 'vales_taxi', 'procedimiento_revision' => 'procedimientos_aprobar', 'procedimiento_rechazado' => 'procedimientos_aprobar',
        'llave_fuera' => 'llaves_fuera', 'equipo_turno' => 'equipos_turno', 'activo_no_regreso' => 'activos_no_regreso', 'baja_con_pendientes' => 'activos_no_regreso',
        'faltante_por_recibir' => 'faltantes_responsivas',
        'alta_por_verificar' => 'altas', 'alta_rechazada' => 'altas',
        'solicitud_compra' => 'plataforma_solicitudes', 'cliente_por_revisar' => 'plataforma_clientes', 'suscripcion_aviso' => 'suscripcion',
        'reserva_por_aprobar' => 'reservas', 'reserva_recordatorio' => 'reservas', 'reserva_escalada' => 'reservas', 'reserva_rechazada' => 'reservas',
        'reserva_cambio' => 'reservas', 'reserva_orden' => 'reservas',
        'reloj_recordatorio' => 'el renglón del asunto',
        'novedad_departamento' => 'novedades_departamento', // C-28
    ];

    /** Alertas que solo informan (no esperan una respuesta): se pueden silenciar. */
    public const SOLO_INFORMAN = ['movimiento_sensible', 'delegacion_terminada']; // al terminar la delegación, lo suyo ya vuelve a su Mis pendientes

    /** C-22: ¿este tipo de aviso NO se puede silenciar en «Mi cuenta»? */
    public static function noSilenciable(string $tipo): bool
    {
        if (in_array($tipo, self::NO_SILENCIABLES, true)) {
            return true;
        }
        $nivel = CentroNotificaciones::TIPOS[$tipo][1] ?? 'info';

        return $nivel === 'alerta' && ! array_key_exists($tipo, self::RENGLON_DE_TIPO) && ! in_array($tipo, self::SOLO_INFORMAN, true);
    }

    /** Tipo de la campana => [módulo para agrupar, qué avisa, permisos de quien lo recibe]. */
    public const CAMPANA = [
        'autorizacion_visita' => ['Autorizaciones', 'Una visita a tu departamento espera tu autorización', ['autorizaciones.responder']],
        'autorizacion_candidato' => ['Autorizaciones', 'Un candidato espera tu autorización', ['autorizaciones.responder']],
        'autorizacion_escalada' => ['Autorizaciones', 'Una autorización pasó al suplente o al jefe por tardanza', ['autorizaciones.responder', 'accesos.ver']],
        'autorizacion_excepcion' => ['Autorizaciones', 'La caseta dejó pasar por excepción a alguien que esperaba tu autorización', ['autorizaciones.responder']],
        'delegacion' => ['Autorizaciones', 'Alguien te delegó sus aprobaciones (visitas, pases, reservas o entrevistas)', ['autorizaciones.responder', 'pases_salida.aprobar', 'reservas.aprobar', 'candidatos.evaluar']],
        'delegacion_terminada' => ['Autorizaciones', 'Tu delegación terminó porque la persona fue dada de baja', ['autorizaciones.responder', 'pases_salida.aprobar', 'reservas.aprobar', 'candidatos.evaluar']],
        // Etapa 3 (C-08)
        'cobertura_perdida' => ['Autorizaciones', 'Un departamento se quedó sin quien autorice en una sede', ['autorizaciones.configurar']],
        'acceso_sin_cobertura' => ['Bitácora de accesos', 'Una visita espera tu autorización porque su departamento no tiene quien autorice en la sede', ['accesos.aprobar']],
        // Etapa 4 (C-22, C-25)
        'excepcion_pedida' => ['Bitácora de accesos', 'La caseta te pide autorizar a alguien que espera', ['accesos.aprobar']],
        'excepcion_agente' => ['Bitácora de accesos', 'El agente aprobó por excepción porque nadie respondió (siempre llega)', ['accesos.aprobar']],
        'reloj_recordatorio' => ['Reloj y suplente', 'Recordatorio de algo que lleva tiempo esperando tu respuesta', []],
        'reloj_escalado' => ['Reloj y suplente', 'Algo tardó y pasó a ti como suplente (siempre llega)', []],
        'autorizacion_respuesta' => ['Bitácora de accesos', 'Respondieron una autorización que pidió la caseta', ['accesos.ver']],
        'autorizacion_recepcion' => ['Bitácora de accesos', 'Recursos Humanos dice «Que pase» a quien espera en caseta', ['accesos.ver']],
        'candidato_llegada' => ['Recepción y candidatos', 'Llega un candidato a Recepción', ['candidatos.editar']],
        'candidato_autocaptura' => ['Recepción y candidatos', 'Un candidato llenó su solicitud en el kiosco', ['candidatos.editar']],
        'evaluacion_departamento' => ['Recepción y candidatos', 'El departamento evaluó o eligió a un candidato', ['candidatos.editar']],
        'plazo_candidato' => ['Recepción y candidatos', 'Algo de candidatos pasó de su plazo', ['candidatos.editar']],
        'caducidad_candidato' => ['Recepción y candidatos', 'Una ficha de candidato se borrará por caducidad', ['candidatos.editar']],
        'candidato_postulacion' => ['Recepción y candidatos', 'Alguien se postuló por internet', ['candidatos.editar']],
        'vacante_propuesta' => ['Recepción y candidatos', 'Un jefe propuso una vacante', ['vacantes.editar']],
        'vacante_cubierta' => ['Recepción y candidatos', 'Una vacante quedó cubierta', ['vacantes.editar']],
        'vacante_resultado' => ['Recepción y candidatos', 'Se publicó, cerró o eliminó una vacante que pediste', ['vacantes.crear']],
        'entrevista_asignada' => ['Mis entrevistas', 'Te canalizaron una entrevista', ['candidatos.evaluar']],
        'entrevista_cambio' => ['Mis entrevistas', 'Cambió o se canceló una entrevista', ['candidatos.evaluar']],
        'entrevista_llegada' => ['Mis entrevistas', 'Llegó el candidato a su entrevista', ['candidatos.evaluar']],
        'pase_por_firmar' => ['Pases de salida', 'Un pase de salida espera tu firma', ['pases_salida.aprobar', 'pases_salida.firmar']],
        'pase_aprobado' => ['Pases de salida', 'Tu pase de salida se aprobó', ['pases_salida.crear']],
        'pase_rechazado' => ['Pases de salida', 'Tu pase de salida se rechazó', ['pases_salida.crear']],
        'pase_vencido' => ['Pases de salida', 'Un pase de salida venció y no ha regresado', ['pases_salida.ver']],
        'pase_llega' => ['Pases de salida', 'Un pase de salida viene a tu sede', ['pases_salida.ver']],
        'cuenta_bloqueada' => ['Cuentas y seguridad', 'Una cuenta se bloqueó por intentos fallidos', ['usuarios.editar', 'usuarios.desbloquear']],
        'cuenta_desactivada' => ['Cuentas y seguridad', 'Una cuenta se dio de baja junto con su colaborador', ['usuarios.editar']],
        'cuenta_solicitud' => ['Cuentas y seguridad', 'Alguien pide que restablezcas su contraseña o su verificación en dos pasos', ['usuarios.editar', 'usuarios.desbloquear']],
        'cuenta_sede' => ['Cuentas y seguridad', 'Un colaborador cambió de sede y su cuenta no entra a la nueva', ['usuarios.editar']],
        // Privacidad (docs/tecnico/privacidad.md)
        'arco_solicitud' => ['Privacidad', 'Llegó una solicitud ARCO (acceso, rectificación, cancelación u oposición)', ['configuracion.datos_personales']],
        'arco_plazo' => ['Privacidad', 'Una solicitud ARCO está por vencer o venció', ['configuracion.datos_personales']],
        'privacidad_conservacion' => ['Privacidad', 'La revisión de cada noche quitó datos personales vencidos', ['configuracion.datos_personales']],
        MovimientosSensibles::TIPO => ['Cuentas y seguridad', 'Alguien hizo un movimiento sensible (roles, permisos, eliminaciones, exportaciones)', ['auditoria.ver']],
        // Mejoras Incidentes (ADR-0013)
        'novedad_canalizada' => ['Bitácora de novedades', 'Te canalizaron un ticket de la bitácora', ['novedades.editar']],
        // Etapa 5 (C-28, C-09)
        'novedad_departamento' => ['Bitácora de novedades', 'Canalizaron un ticket a tu departamento', ['autorizaciones.responder']],
        'novedad_departamento_atendida' => ['Bitácora de novedades', 'Un departamento atendió su parte de un ticket que canalizaste', ['novedades.editar']],
        'visita_llego' => ['Bitácora de accesos', 'Llegó a la caseta alguien que viene contigo', []],
        'novedad_resuelta' => ['Bitácora de novedades', 'Se cerró un ticket que levantaste', ['novedades.crear']],
        'novedad_turno' => ['Bitácora de novedades', 'Un ticket «Pendiente de turno» espera a tu turno', ['novedades.editar']],
        'novedad_turno_escalada' => ['Bitácora de novedades', 'Un pendiente de turno terminó sin atenderse', ['novedades.editar']],
        'recorrido_pc_hallazgo' => ['Recorridos de Protección Civil', 'Un recorrido de Protección Civil encontró una falla', ['recorridos_pc.ver', 'equipos_pc.editar']],
        'pase_firma_recordatorio' => ['Pases de salida', 'Una firma de pase de salida que te toca lleva tiempo esperando', ['pases_salida.aprobar', 'pases_salida.firmar']],
        'pase_firma_escalada' => ['Pases de salida', 'Una firma de pase de salida tardó y pasó al siguiente nivel', ['pases_salida.aprobar', 'pases_salida.configurar']],
        // Mejoras activos (ADR-0014)
        'vale_taxi_por_autorizar' => ['Bitácora de transporte', 'Un vale de taxi espera tu Vo.Bo.', ['transporte.aprobar']],
        'vale_resultado' => ['Bitácora de transporte', 'Se aprobó o rechazó un vale de taxi que registraste', ['transporte.crear']],
        'faltante_por_recibir' => ['Responsivas', 'La caseta reporta un equipo faltante que debes recibir', ['equipos.eliminar']],
        'procedimiento_revision' => ['Procedimientos', 'Un procedimiento se envió a revisión y espera tu aprobación', ['procedimientos.aprobar']],
        'procedimiento_aprobado' => ['Procedimientos', 'Se aprobó un procedimiento que enviaste a revisión', ['procedimientos.editar']],
        'procedimiento_rechazado' => ['Procedimientos', 'Pidieron cambios a un procedimiento que enviaste a revisión', ['procedimientos.editar']],
        'procedimiento_publicado' => ['Procedimientos', 'Se publicó un procedimiento que debes leer y firmar', ['procedimientos.ver']],
        'llave_fuera' => ['Préstamo de llaves', 'Una llave prestada lleva demasiado tiempo fuera', ['prestamo_llaves.editar']],
        'equipo_turno' => ['Responsivas', 'Equipos de turno que no regresaron a su hora', ['responsivas.editar']],
        // Etapa 2 (C-14, C-15, C-20)
        'activo_no_regreso' => ['Caseta y activos', 'Una llave, un gafete o un equipo no regresó: recíbelo o dalo de baja', ['llaves.eliminar', 'gafetes.eliminar', 'equipos.eliminar']],
        'baja_con_pendientes' => ['Caseta y activos', 'Se dio de baja a alguien o algo que tenía cosas pendientes', ['llaves.eliminar', 'gafetes.eliminar', 'equipos.eliminar', 'colaboradores.eliminar']],
        // Mejoras padrones (ADR-0015)
        'alta_por_verificar' => ['Padrones: altas por verificar', 'La caseta registró un alta que debes verificar', ['vehiculos.editar', 'proveedores.editar', 'visitantes.editar', 'colaboradores.aprobar']],
        'alta_verificada' => ['Padrones: altas por verificar', 'Se verificó un alta que registraste', ['accesos.crear']],
        'alta_unida' => ['Padrones: altas por verificar', 'Un alta que registraste ya existía y se unió con su registro', ['accesos.crear']],
        'alta_rechazada' => ['Padrones: altas por verificar', 'Se rechazó un alta que registraste', ['accesos.crear']],
        // Catálogo comercial (solo Super Administrador)
        'solicitud_compra' => ['Catálogo comercial', 'Llegó una solicitud desde la página de planes', ['catalogo_comercial.ver']],
        'cliente_por_revisar' => ['Catálogo comercial', 'Un cliente nuevo se activó al pagar y espera tu revisión', ['catalogo_comercial.ver']],
        'suscripcion_aviso' => ['Suscripción', 'Tu suscripción está por vencer, venció o pasó a solo consulta', ['empresas.editar', 'catalogo_comercial.ver']],
        // Reservas de áreas (docs/tecnico/reservas.md)
        'reserva_por_aprobar' => ['Reservas de áreas', 'Una reserva espera tu aprobación (área o servicios de tu departamento)', ['reservas.ver', 'reservas.crear', 'reservas.aprobar']],
        'reserva_recordatorio' => ['Reservas de áreas', 'Una reserva que te toca aprobar lleva tiempo esperando', ['reservas.ver', 'reservas.crear', 'reservas.aprobar']],
        'reserva_escalada' => ['Reservas de áreas', 'Una aprobación de reserva tardó y pasó al responsable general', ['reservas.aprobar', 'reservas.configurar']],
        'reserva_confirmada' => ['Reservas de áreas', 'Tu reserva quedó confirmada', ['reservas.crear']],
        'reserva_rechazada' => ['Reservas de áreas', 'Tu reserva se rechazó', ['reservas.crear']],
        'reserva_cambio' => ['Reservas de áreas', 'Piden un cambio en tu reserva', ['reservas.crear']],
        'reserva_cancelada' => ['Reservas de áreas', 'Se canceló una reserva que te tocaba aprobar o atender', ['reservas.ver', 'reservas.crear']],
        'reserva_orden' => ['Reservas de áreas', 'Llegó, cambió o se canceló una orden de servicio de tu departamento', ['reservas.ver', 'reservas.crear']],
        'reserva_orden_sin_quien' => ['Reservas de áreas', 'Una orden de servicio no tiene quien la atienda (siempre llega)', ['reservas.configurar']],
        'reserva_orden_lista' => ['Reservas de áreas', 'Un departamento tiene listo su servicio para una reserva', ['reservas.crear']],
    ];

    /**
     * @return array{campana: list<string>, correo: list<string>}
     */
    public static function silenciados(User $usuario): array
    {
        $datos = $usuario->getAttributes()['preferencias_avisos'] ?? null;
        $datos = is_string($datos) ? json_decode($datos, true) : $datos;

        return [
            'campana' => array_values(array_filter((array) ($datos['campana'] ?? []), 'is_string')),
            'correo' => array_values(array_filter((array) ($datos['correo'] ?? []), 'is_string')),
        ];
    }

    /**
     * Quita a quienes silenciaron este tipo de aviso de la campana.
     *
     * @param  iterable<int>  $usuarios
     * @return list<int>
     */
    public static function quienesLoReciben(iterable $usuarios, string $tipo): array
    {
        $ids = collect($usuarios)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || self::noSilenciable($tipo)) {
            return $ids->all();
        }

        $silenciaron = DB::table('users')->whereIn('id', $ids)->whereNotNull('preferencias_avisos')->get(['id', 'preferencias_avisos'])
            ->filter(fn ($u) => in_array($tipo, (array) (json_decode((string) $u->preferencias_avisos, true)['campana'] ?? []), true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $ids->reject(fn ($id) => in_array($id, $silenciaron, true))->values()->all();
    }

    /**
     * Quita de una lista de correos a las personas de la empresa que silenciaron ese aviso por correo.
     *
     * @param  list<string>  $correos
     * @return list<string>
     */
    public static function correosQueLoReciben(int $empresaId, string $clave, array $correos): array
    {
        if ($correos === []) {
            return $correos;
        }

        $silenciaron = DB::table('users')->where('empresa_id', $empresaId)->whereIn('email', $correos)->whereNotNull('preferencias_avisos')
            ->get(['email', 'preferencias_avisos'])
            ->filter(fn ($u) => in_array($clave, (array) (json_decode((string) $u->preferencias_avisos, true)['correo'] ?? []), true))
            ->pluck('email')->map(fn ($c) => mb_strtolower((string) $c))->all();

        return array_values(array_filter($correos, fn ($c) => ! in_array(mb_strtolower($c), $silenciaron, true)));
    }

    /**
     * Lo que se le muestra a la persona en «Mi cuenta → Mis avisos»: los tipos
     * de campana que le pueden llegar (por permiso o porque ya le llegaron) y
     * los avisos por correo de los módulos que ve.
     *
     * @return array{campana: array<string, array<string, string>>, correo: array<string, array<string, string>>}
     */
    public function opciones(User $usuario): array
    {
        $recibidos = Notificacion::query()->withoutGlobalScopes()->where('user_id', $usuario->id)->distinct()->pluck('tipo')->all();

        $campana = [];
        // El grupo de la bitácora lleva el nombre que la empresa le dio al módulo
        $bitacora = $usuario->empresa_id !== null ? app(AjustesNovedades::class)->bitacora((int) $usuario->empresa_id) : null;
        foreach (self::CAMPANA as $tipo => [$grupo, $etiqueta, $permisos]) {
            $grupo = $grupo === 'Bitácora de novedades' && $bitacora !== null ? $bitacora : $grupo;
            if (in_array($tipo, $recibidos, true) || collect($permisos)->contains(fn ($p) => $usuario->can($p))) {
                $campana[$grupo][$tipo] = $etiqueta;
            }
        }
        // Tipos nuevos de otros módulos que ya le llegaron
        foreach (array_diff($recibidos, array_keys(self::CAMPANA), self::NO_SILENCIABLES) as $tipo) {
            $campana['Otros avisos'][$tipo] = Str::ucfirst(str_replace('_', ' ', (string) $tipo));
        }

        $correo = [];
        foreach (GruposAvisos::agrupados($usuario->empresa_id !== null ? (int) $usuario->empresa_id : null) as $grupo => $def) {
            if (! $this->veGrupo($usuario, $grupo, $def['modulo'])) {
                continue;
            }
            foreach ($def['avisos'] as $clave => [$etiqueta]) {
                if ($clave !== MovimientosSensibles::AVISO) { // ese llega solo a la campana
                    $correo[$def['nombre']][$clave] = $etiqueta;
                }
            }
        }

        return ['campana' => $campana, 'correo' => array_filter($correo)];
    }

    /**
     * @param  array<string, mixed>  $marcados  ['campana' => [tipo => 1], 'correo' => [clave => 1]] (marcado = recibir)
     */
    public function guardar(User $usuario, array $marcados): array
    {
        $opciones = $this->opciones($usuario);
        $antes = self::silenciados($usuario);
        $nuevos = [];
        foreach (['campana', 'correo'] as $canal) {
            $mostrados = collect($opciones[$canal])->flatMap(fn ($g) => array_keys($g))->all();
            $recibir = array_keys(array_filter((array) ($marcados[$canal] ?? [])));
            $nuevos[$canal] = array_values(array_unique([
                ...array_diff($antes[$canal], $mostrados),
                ...array_values(array_filter(array_diff($mostrados, $recibir), fn ($t) => $canal !== 'campana' || ! self::noSilenciable((string) $t))),
            ]));
        }
        $usuario->forceFill(['preferencias_avisos' => $nuevos['campana'] === [] && $nuevos['correo'] === [] ? null : json_encode($nuevos)])->save();

        return $nuevos;
    }

    private function veGrupo(User $usuario, string $grupo, ?string $modulo): bool
    {
        return match ($grupo) {
            'cuentas' => $usuario->can('usuarios.editar') || $usuario->can('auditoria.ver'),
            'padrones' => $usuario->can('vehiculos.editar') || $usuario->can('proveedores.editar') || $usuario->can('visitantes.editar'),
            'candidatos' => $usuario->can('candidatos.editar') || $usuario->can('candidatos.evaluar') || $usuario->can('autorizaciones.responder'),
            'pases_salida' => $usuario->can('pases_salida.ver'),
            default => $modulo !== null ? $usuario->can($modulo.'.ver') : true,
        };
    }

    /**
     * ¿La empresa tiene encendido el aviso? (para avisar en «Mis avisos» que está apagado para todos)
     */
    public static function apagadoEnEmpresa(?Empresa $empresa, string $clave): bool
    {
        return $empresa !== null && ! $empresa->aviso($clave);
    }
}
