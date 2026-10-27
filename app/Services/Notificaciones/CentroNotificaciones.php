<?php

namespace App\Services\Notificaciones;

use App\Models\Notificacion;
use App\Models\User;
use App\Support\HoraLocal;
use Illuminate\Support\Collection;

/**
 * Centro de notificaciones (la campana del encabezado). Cada notificación es
 * de un usuario y de una empresa; la campana consulta un JSON pequeño cada
 * 30 s (hosting compartido: sin websockets) y se pausa con la pestaña oculta.
 *
 * Los módulos crean avisos con avisar(); una notificación puede llevar
 * botones de acción (p. ej. [Autorizar ingreso] [Rechazar]) que la lista
 * envía al formulario del módulo: el módulo decide (permiso y alcance).
 * Cuando el asunto se resuelve, resolver() quita los botones a todos.
 */
class CentroNotificaciones
{
    /** Cuántas muestra la campana. */
    public const EN_CAMPANA = 8;

    /**
     * Tipos que esperan una respuesta inmediata (autorizaciones de visita,
     * recepción y escalamiento): el aviso del navegador no se cierra solo.
     */
    public const URGENTES = ['autorizacion_visita', 'autorizacion_candidato', 'autorizacion_recepcion', 'autorizacion_escalada', 'acceso_sin_cobertura'];

    /** Tipos de notificación => [icono, nivel]. */
    public const TIPOS = [
        'candidato_llegada' => ['bi-person-plus-fill', 'info'],
        'candidato_autocaptura' => ['bi-phone', 'info'],
        'autorizacion_visita' => ['bi-door-open-fill', 'alerta'],
        'autorizacion_candidato' => ['bi-person-workspace', 'alerta'],
        'autorizacion_recepcion' => ['bi-person-check-fill', 'alerta'],
        'autorizacion_respuesta' => ['bi-patch-check-fill', 'exito'],
        'autorizacion_escalada' => ['bi-alarm-fill', 'alerta'],
        'autorizacion_excepcion' => ['bi-shield-exclamation', 'alerta'],
        'delegacion' => ['bi-person-gear', 'info'],
        // Etapa 3 (C-08): departamento sin quien autorice y visita que espera al supervisor de la sede
        'cobertura_perdida' => ['bi-person-exclamation', 'alerta'],
        'acceso_sin_cobertura' => ['bi-hourglass-split', 'alerta'],
        // C-11: el colaborador cambió de sede y su cuenta no entra a la nueva
        'cuenta_sede' => ['bi-geo-alt-fill', 'alerta'],
        // Candidatos, fase 2
        'entrevista_asignada' => ['bi-calendar-event', 'alerta'],
        'entrevista_cambio' => ['bi-calendar-x', 'info'],
        'entrevista_llegada' => ['bi-person-check-fill', 'alerta'],
        'evaluacion_departamento' => ['bi-clipboard-check', 'exito'],
        // Candidatos, fase 3: plazos (recordatorio único) y caducidad (aviso 15 días antes de borrar)
        'plazo_candidato' => ['bi-hourglass-split', 'alerta'],
        'caducidad_candidato' => ['bi-archive', 'alerta'],
        // Cuenta del usuario (docs/criterios-ux.md, reglas 1 y 6)
        'cuenta_contrasena' => ['bi-key-fill', 'exito'],
        'cuenta_bloqueada' => ['bi-lock-fill', 'alerta'],
        'cuenta_desactivada' => ['bi-person-x-fill', 'alerta'],
        // C-36: «Pedir a mi administrador» y cambios entre Super Administradores
        'cuenta_solicitud' => ['bi-person-exclamation', 'alerta'],
        'plataforma_superadmin' => ['bi-shield-lock-fill', 'alerta'],
        // Verificación en dos pasos (docs/tecnico/dos-pasos.md): avisos a la propia cuenta
        'cuenta_dos_pasos' => ['bi-shield-lock-fill', 'alerta'],
        // Privacidad (docs/tecnico/privacidad.md): solicitudes ARCO y conservación de datos
        'arco_solicitud' => ['bi-person-vcard-fill', 'alerta'],
        'arco_plazo' => ['bi-hourglass-split', 'alerta'],
        'privacidad_conservacion' => ['bi-shield-check', 'info'],
        // Pases de salida (ADR-0012): firma pendiente, resultado, vencido y «viene a tu sede»
        'pase_por_firmar' => ['bi-pen-fill', 'alerta'],
        'pase_aprobado' => ['bi-check-circle-fill', 'exito'],
        'pase_rechazado' => ['bi-x-octagon-fill', 'alerta'],
        'pase_vencido' => ['bi-exclamation-triangle-fill', 'alerta'],
        'pase_llega' => ['bi-truck', 'info'],
        // Mejoras Incidentes (ADR-0013): canalizar, cerrar, pendiente de turno, hallazgos de recorrido y firmas de pases que tardan
        'novedad_canalizada' => ['bi-headset', 'alerta'],
        'novedad_resuelta' => ['bi-check2-circle', 'exito'],
        'novedad_turno' => ['bi-hourglass-split', 'alerta'],
        'novedad_turno_escalada' => ['bi-alarm-fill', 'alerta'],
        'recorrido_pc_hallazgo' => ['bi-fire', 'alerta'],
        'pase_firma_recordatorio' => ['bi-alarm', 'alerta'],
        'pase_firma_escalada' => ['bi-alarm-fill', 'alerta'],
        // Fin Mejoras Incidentes
        // Mejoras activos (ADR-0014): Vo.Bo. de vales, procedimientos, llaves fuera y equipos de turno
        'vale_taxi_por_autorizar' => ['bi-taxi-front', 'alerta'],
        'procedimiento_revision' => ['bi-journal-check', 'alerta'],
        'procedimiento_aprobado' => ['bi-check-circle-fill', 'exito'],
        'procedimiento_rechazado' => ['bi-arrow-counterclockwise', 'alerta'],
        'procedimiento_publicado' => ['bi-book', 'info'],
        'llave_fuera' => ['bi-key-fill', 'alerta'],
        'equipo_turno' => ['bi-broadcast-pin', 'alerta'],
        // Etapa 2 (C-14, C-15, C-20): lo que no regresó y lo que quedó pendiente al dar de baja
        'activo_no_regreso' => ['bi-exclamation-octagon-fill', 'alerta'],
        'baja_con_pendientes' => ['bi-person-dash-fill', 'alerta'],
        // Fin Mejoras activos
        // Mejoras padrones (ADR-0015): altas por verificar y su resultado a quien la registró
        'alta_por_verificar' => ['bi-patch-question-fill', 'alerta'],
        'alta_verificada' => ['bi-patch-check-fill', 'exito'],
        'alta_unida' => ['bi-union', 'info'],
        'alta_rechazada' => ['bi-x-octagon-fill', 'alerta'],
        // Fin Mejoras padrones
        // Mejoras estructura (ADR-0016): movimiento sensible en la bitácora (si la empresa lo enciende)
        'movimiento_sensible' => ['bi-shield-exclamation', 'alerta'],
        // Catálogo comercial: llegó una solicitud de la página pública (aviso de la plataforma, sin empresa)
        'solicitud_compra' => ['bi-cart-check-fill', 'alerta'],
        // Suscripciones: cliente nuevo que se activó al pagar (Super Administrador) y avisos de vencimiento (cliente y plataforma)
        'cliente_por_revisar' => ['bi-person-check-fill', 'alerta'],
        'suscripcion_aviso' => ['bi-calendar2-check', 'alerta'],
        // Reservas de áreas (docs/tecnico/reservas.md): aprobación, recordatorio, escalamiento, resultado y órdenes de servicio
        'reserva_por_aprobar' => ['bi-calendar-check', 'alerta'],
        'reserva_recordatorio' => ['bi-alarm', 'alerta'],
        'reserva_escalada' => ['bi-alarm-fill', 'alerta'],
        'reserva_confirmada' => ['bi-calendar-check-fill', 'exito'],
        'reserva_rechazada' => ['bi-calendar-x-fill', 'alerta'],
        'reserva_cambio' => ['bi-arrow-repeat', 'alerta'],
        'reserva_cancelada' => ['bi-calendar-x', 'info'],
        'reserva_orden' => ['bi-clipboard-check', 'alerta'],
        'reserva_orden_lista' => ['bi-check2-all', 'exito'],
    ];

    public function __construct(private readonly HoraLocal $hora) {}

    /**
     * Crea la misma notificación para varios usuarios (sin repetir).
     *
     * @param  iterable<int>  $usuarios  ids de usuario
     * @param  array{titulo: string, texto?: ?string, url?: ?string, referencia_tipo?: ?string, referencia_id?: ?int, acciones?: ?array}  $datos
     * @return list<int> ids de usuario avisados
     */
    public function avisar(int $empresaId, iterable $usuarios, string $tipo, array $datos): array
    {
        [$icono, $nivel] = self::TIPOS[$tipo] ?? ['bi-bell', 'info'];
        $avisados = [];
        // Mejoras estructura (ADR-0016): sin quienes silenciaron este tipo en «Mi cuenta» (Mis pendientes no cambia)
        foreach (PreferenciasAvisos::quienesLoReciben($usuarios, $tipo) as $userId) {
            $n = new Notificacion([
                'user_id' => $userId,
                'tipo' => $tipo,
                'titulo' => mb_substr($datos['titulo'], 0, 150),
                'texto' => isset($datos['texto']) ? mb_substr((string) $datos['texto'], 0, 500) : null,
                'url' => $datos['url'] ?? null,
                'icono' => $icono,
                'nivel' => $nivel,
                'referencia_tipo' => $datos['referencia_tipo'] ?? null,
                'referencia_id' => $datos['referencia_id'] ?? null,
                'acciones' => $datos['acciones'] ?? null,
            ]);
            $n->forceFill(['empresa_id' => $empresaId])->save();
            $avisados[] = $userId;
        }

        return $avisados;
    }

    /**
     * Aviso de la PLATAFORMA (sin empresa) para el Super Administrador, por
     * ejemplo una solicitud de la página pública de planes. Su campana lo ve
     * con o sin empresa de trabajo elegida (Notificacion::scopeDe).
     *
     * @param  iterable<int>  $usuarios  ids de Super Administradores
     * @param  array{titulo: string, texto?: ?string, url?: ?string, referencia_tipo?: ?string, referencia_id?: ?int}  $datos
     * @return list<int>
     */
    public function avisarPlataforma(iterable $usuarios, string $tipo, array $datos): array
    {
        [$icono, $nivel] = self::TIPOS[$tipo] ?? ['bi-bell', 'info'];
        $superadmins = User::query()->whereIn('id', collect($usuarios)->all())->where('es_superadmin', true)->pluck('id');
        $avisados = [];
        foreach (PreferenciasAvisos::quienesLoReciben($superadmins, $tipo) as $userId) {
            Notificacion::query()->withoutGlobalScopes()->insert([
                'empresa_id' => null,
                'user_id' => $userId,
                'tipo' => $tipo,
                'titulo' => mb_substr($datos['titulo'], 0, 150),
                'texto' => isset($datos['texto']) ? mb_substr((string) $datos['texto'], 0, 500) : null,
                'url' => $datos['url'] ?? null,
                'icono' => $icono,
                'nivel' => $nivel,
                'referencia_tipo' => $datos['referencia_tipo'] ?? null,
                'referencia_id' => $datos['referencia_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $avisados[] = $userId;
        }

        return $avisados;
    }

    /**
     * El asunto ya se resolvió (alguien respondió): las notificaciones de esa
     * referencia pierden sus botones y quedan leídas para todos.
     */
    public function resolver(string $referenciaTipo, int $referenciaId): void
    {
        Notificacion::where('referencia_tipo', $referenciaTipo)->where('referencia_id', $referenciaId)
            ->update(['acciones' => null, 'leida_en' => now(), 'updated_at' => now()]);
    }

    public function noLeidas(User $usuario): int
    {
        return Notificacion::de($usuario)->noLeidas()->count();
    }

    /**
     * Resumen para la campana (JSON).
     *
     * @return array{no_leidas: int, lista: list<array<string, mixed>>}
     */
    public function resumen(User $usuario): array
    {
        return [
            'no_leidas' => $this->noLeidas($usuario),
            'lista' => $this->recientes($usuario)->map(fn (Notificacion $n) => $this->paraLista($n))->values()->all(),
        ];
    }

    /** @return Collection<int, Notificacion> */
    public function recientes(User $usuario, int $cuantas = self::EN_CAMPANA): Collection
    {
        return Notificacion::de($usuario)->orderByRaw('leida_en IS NULL DESC')->orderByDesc('id')->limit($cuantas)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function paraLista(Notificacion $n): array
    {
        return [
            'id' => $n->id,
            'titulo' => $n->titulo,
            'texto' => $n->texto,
            'icono' => $n->icono,
            'nivel' => $n->nivel,
            'leida' => $n->leida_en !== null,
            'hace' => $this->hace($n->created_at),
            'fecha' => $this->hora->formatear($n->created_at),
            'abrir' => route('notificaciones.abrir', $n->id),
            'acciones' => $this->acciones($n),
            // Avisos del navegador: los urgentes se quedan en pantalla hasta que se tocan
            'urgente' => in_array($n->tipo, self::URGENTES, true),
        ];
    }

    /**
     * Botones de la notificación: cada uno es un formulario POST a la ruta del módulo.
     * Un botón {etiqueta, abrir: true} solo abre el asunto (como tocar la
     * notificación): sirve cuando responder pide más que un toque (p. ej. una firma).
     *
     * @return list<array{etiqueta: string, url: string, campos: array<string, string>, estilo: string}>
     */
    public function acciones(Notificacion $n): array
    {
        $lista = [];
        foreach ((array) $n->acciones as $a) {
            if (is_array($a) && ! empty($a['abrir']) && ! empty($a['etiqueta'])) {
                $lista[] = ['etiqueta' => (string) $a['etiqueta'], 'url' => route('notificaciones.abrir', $n->id), 'campos' => [], 'estilo' => 'normal'];

                continue;
            }
            if (! is_array($a) || empty($a['url']) || empty($a['etiqueta'])) {
                continue;
            }
            $lista[] = [
                'etiqueta' => (string) $a['etiqueta'],
                'url' => (string) $a['url'],
                'campos' => array_map('strval', (array) ($a['campos'] ?? [])),
                'estilo' => (string) ($a['estilo'] ?? 'normal'),
            ];
        }

        return $lista;
    }

    public function marcarLeida(Notificacion $n): void
    {
        if ($n->leida_en === null) {
            // Sin pasar por el guardado del modelo: un aviso de la plataforma (sin empresa) no toma la empresa de trabajo
            Notificacion::query()->withoutGlobalScopes()->whereKey($n->id)->update(['leida_en' => now(), 'updated_at' => now()]);
            $n->leida_en = now();
        }
    }

    public function marcarTodas(User $usuario): int
    {
        return Notificacion::de($usuario)->noLeidas()->update(['leida_en' => now(), 'updated_at' => now()]);
    }

    public function hace(?\DateTimeInterface $fecha): string
    {
        if ($fecha === null) {
            return '';
        }
        $minutos = (int) max(0, now()->diffInMinutes($fecha, true));

        return match (true) {
            $minutos < 1 => 'Hace un momento',
            $minutos < 60 => "Hace {$minutos} min",
            $minutos < 1440 => 'Hace '.intdiv($minutos, 60).' h',
            default => $this->hora->formatear($fecha, 'd/m H:i'),
        };
    }
}
