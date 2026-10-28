<?php

namespace App\Services\Candidatos;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Candidatos, fase 3 (ADR-0011): plazos y recordatorios (criterio 3 de la
 * experiencia: todo lo que espera tiene reloj, color y suplente).
 *
 * Plazos por empresa (empresas.preferencias['recepcion']['plazos'], con los
 * valores de DEFECTO si no se configuran):
 *  - solicitud_dias: solicitud llenada en el kiosco o por internet sin revisar;
 *  - evaluacion_dias: días HÁBILES desde la cita sin que el departamento registre su evaluación;
 *  - documentos_dias: elegido sin completar documentos o sin contrato firmado;
 *  - por_atender_dias: candidato «Por atender» (registrado) o «Con RR. HH.»
 *    (con_rh, ADR-0010) sin movimiento;
 *  - no_se_presento_horas: cita vencida sin registro en caseta → se PROPONE
 *    «Marcar como no se presentó» a RR. HH. (no se marca solo);
 *  - archivar_meses y borrar_meses: caducidad (App\Services\Candidatos\CaducidadCandidatos).
 *
 * Cada plazo genera un recordatorio AL DÍA (campana y correo) al responsable:
 * RR. HH. de la sede o el entrevistador (o su delegado), mientras siga
 * pendiente. El sello aviso_*_en impide repetirlo el mismo día; el aviso
 * anterior de la campana queda leído para no acumularlos.
 * Lo corre la tarea plataforma:candidatos-pendientes --si-toca
 * (en proceso, desde desplegar.sh) y también la lista de Candidatos al
 * abrirse, con candado de caché de 60 s por empresa.
 */
class PlazosCandidatos
{
    public const DEFECTO = [
        'solicitud_dias' => 1, 'evaluacion_dias' => 2, 'documentos_dias' => 5, 'por_atender_dias' => 2,
        'no_se_presento_horas' => 24, 'archivar_meses' => 6, 'borrar_meses' => 12,
    ];

    /** Límites de cada ajuste [mínimo, máximo]. */
    public const LIMITES = [
        'solicitud_dias' => [1, 60], 'evaluacion_dias' => [1, 60], 'documentos_dias' => [1, 90], 'por_atender_dias' => [1, 60],
        'no_se_presento_horas' => [1, 240], 'archivar_meses' => [1, 36], 'borrar_meses' => [1, 60],
    ];

    public const REFERENCIA_CADUCIDAD = 'candidato_caducidad';

    /** Renglones de Mis pendientes que llevan color por plazo. */
    public const RENGLONES = ['solicitudes', 'recepcion', 'sin_atender', 'por_entrevistar', 'elegidos', 'entrevistas'];

    /** Etapas en manos de RR. HH. («Por atender» y «Con RR. HH.», ADR-0010): mismo plazo por_atender_dias. */
    public const ETAPAS_RH = ['registrado', 'con_rh'];

    public const SEGUNDOS_CANDADO = 60;

    public function __construct(
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
        private readonly Tenant $tenant,
        private readonly AdministradorRoles $auditoria,
        private readonly HoraLocal $hora,
    ) {}

    // --------------------------------------------------------------- Ajustes

    /**
     * @return array<string, int>
     */
    public function plazos(Empresa $empresa): array
    {
        $d = $empresa->preferencias['recepcion']['plazos'] ?? [];
        $r = [];
        foreach (self::DEFECTO as $clave => $valor) {
            $v = is_array($d) && isset($d[$clave]) && is_numeric($d[$clave]) ? (int) $d[$clave] : $valor;
            $r[$clave] = max(self::LIMITES[$clave][0], min(self::LIMITES[$clave][1], $v));
        }

        return $r;
    }

    public function plazo(Empresa $empresa, string $clave): int
    {
        return $this->plazos($empresa)[$clave] ?? self::DEFECTO[$clave];
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): void
    {
        $reglas = [];
        $mensajes = [];
        foreach (self::LIMITES as $clave => [$min, $max]) {
            $reglas[$clave] = ['required', 'integer', "min:{$min}", "max:{$max}"];
            $mensajes["{$clave}.*"] = self::etiqueta($clave)." va de {$min} a {$max}.";
        }
        $d = Validator::make($entrada, $reglas, $mensajes)->validate();
        if ((int) $d['borrar_meses'] <= (int) $d['archivar_meses']) {
            throw ValidationException::withMessages(['borrar_meses' => 'Las fichas se borran después de archivar las postulaciones: pon más meses en «Borrar» que en «Archivar».']);
        }
        $antes = $this->plazos($empresa);
        $nuevos = [];
        foreach (array_keys(self::DEFECTO) as $clave) {
            $nuevos[$clave] = (int) $d[$clave];
        }
        $recepcion = array_merge($empresa->preferencias['recepcion'] ?? [], ['plazos' => $nuevos]);
        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['recepcion' => $recepcion])])->save();
        $this->auditoria->auditar($actor, 'candidatos.plazos_configurados', $empresa, $antes, $nuevos);
    }

    public static function etiqueta(string $clave): string
    {
        return [
            'solicitud_dias' => '«Solicitud sin revisar» (días)', 'evaluacion_dias' => '«Evaluación del departamento sin registrar» (días hábiles)',
            'documentos_dias' => '«Elegido sin documentos» (días)', 'por_atender_dias' => '«Por atender» o «Con RR. HH.» sin movimiento (días)',
            'no_se_presento_horas' => '«No se presentó» (horas después de la cita)', 'archivar_meses' => '«Archivar» (meses)', 'borrar_meses' => '«Borrar» (meses)',
        ][$clave] ?? $clave;
    }

    // --------------------------------------------------------------- Correr

    /**
     * Recorre las empresas activas. Para la tarea programada (en proceso).
     *
     * @return array<string, int>
     */
    public function correr(): array
    {
        $total = ['recordatorios' => 0, 'no_se_presento' => 0, 'archivadas' => 0, 'avisos_borrado' => 0, 'borradas' => 0];
        foreach (Empresa::where('activo', true)->get() as $empresa) {
            foreach ($this->correrEmpresa($empresa) as $clave => $n) {
                $total[$clave] = ($total[$clave] ?? 0) + $n;
            }
        }

        return $total;
    }

    /**
     * Recordatorios, «no se presentó» y caducidad de una empresa. Solo toca
     * lo que ya pasó del plazo y no se avisó: nunca repite un aviso.
     *
     * @return array<string, int>
     */
    public function correrEmpresa(Empresa $empresa): array
    {
        $plazos = $this->plazos($empresa);

        return $this->tenant->conEmpresa($empresa->id, function () use ($empresa, $plazos) {
            $n = ['recordatorios' => 0, 'no_se_presento' => 0];
            $n['recordatorios'] += $this->solicitudesSinRevisar($empresa, $plazos['solicitud_dias']);
            $n['recordatorios'] += $this->evaluacionesSinRegistrar($empresa, $plazos['evaluacion_dias']);
            $n['recordatorios'] += $this->elegidosSinDocumentos($empresa, $plazos['documentos_dias']);
            $n['recordatorios'] += $this->porAtenderSinMovimiento($empresa, $plazos['por_atender_dias']);
            $n['no_se_presento'] += $this->citasVencidas($empresa, $plazos['no_se_presento_horas']);

            return $n + app(CaducidadCandidatos::class)->correrEmpresa($empresa, $plazos);
        });
    }

    /**
     * Al abrir la lista de Candidatos (hosting sin cron confiable): si en esta
     * empresa ya tocaba algo, se hace ahora, a lo mucho una vez por minuto y
     * sin que un correo fallido tire la pantalla. Devuelve si hizo algo.
     */
    public function correrSiToca(int $empresaId): bool
    {
        if (! Cache::add('candidatos-pendientes:'.$empresaId, 1, self::SEGUNDOS_CANDADO)) {
            return false;
        }
        $empresa = Empresa::find($empresaId);
        if ($empresa === null || ! $this->toca($empresa)) {
            return false;
        }
        try {
            $hecho = $this->correrEmpresa($empresa);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return array_sum($hecho) > 0;
    }

    /** ¿Hay algo de la empresa que ya pasó de su plazo y no se avisó? (consultas de existencia) */
    public function toca(Empresa $empresa): bool
    {
        $p = $this->plazos($empresa);

        return $this->tenant->conEmpresa($empresa->id, fn () => $this->solicitudes($p['solicitud_dias'])->exists()
            || $this->evaluaciones($p['evaluacion_dias'])->exists()
            || $this->elegidos($p['documentos_dias'])->exists()
            || $this->porAtender($p['por_atender_dias'])->exists()
            || $this->citas($p['no_se_presento_horas'])->exists()
            || app(CaducidadCandidatos::class)->toca($empresa, $p));
    }

    // ------------------------------------------------------------- Consultas

    /** @return Builder<Candidato> */
    private function solicitudes(int $dias): Builder
    {
        return Candidato::where('autocaptura_pendiente', true)->where(self::sinAvisoHoy('aviso_solicitud_en'))->whereNull('archivada_en')
            ->where(fn ($q) => $q->where('autocaptura_en', '<=', now()->subDays($dias))
                ->orWhere(fn ($r) => $r->whereNull('autocaptura_en')->where('updated_at', '<=', now()->subDays($dias))));
    }

    /** Canalizados cuya cita ya pasó hace N días hábiles sin evaluación. */
    private function evaluaciones(int $diasHabiles): Builder
    {
        $limite = self::restarDiasHabiles(now(), $diasHabiles);

        return Postulacion::where('etapa', 'canalizado')->where(self::sinAvisoHoy('aviso_evaluacion_en'))->whereNotNull('entrevistador_id')
            ->where(fn ($q) => $q->where(fn ($c) => $c->where('cita_ahora', false)->where('cita_en', '<=', $limite))
                ->orWhere(fn ($a) => $a->where('cita_ahora', true)->where('canalizado_en', '<=', $limite)));
    }

    /** @return Builder<Postulacion> */
    private function elegidos(int $dias): Builder
    {
        return Postulacion::where('etapa', 'elegido')->where(self::sinAvisoHoy('aviso_documentos_en'))->where('elegido_en', '<=', now()->subDays($dias));
    }

    /** @return Builder<Postulacion> */
    private function porAtender(int $dias): Builder
    {
        return Postulacion::whereIn('etapa', self::ETAPAS_RH)->where(self::sinAvisoHoy('aviso_por_atender_en'))->whereNull('archivada_en')
            ->where('updated_at', '<=', now()->subDays($dias));
    }

    /** Citas con fecha que ya pasaron hace N horas (se revisa en PHP si hubo registro en caseta). */
    private function citas(int $horas): Builder
    {
        return Postulacion::where('etapa', 'canalizado')->where(self::sinAvisoHoy('aviso_no_se_presento_en'))->where('cita_ahora', false)
            ->whereNotNull('cita_en')->where('cita_en', '<=', now()->subHours($horas));
    }

    /**
     * Sello del recordatorio: nunca se avisó, o se avisó hace más de un día
     * (un recordatorio al día mientras siga pendiente).
     */
    private static function sinAvisoHoy(string $columna): \Closure
    {
        return fn ($q) => $q->whereNull($columna)->orWhere($columna, '<=', now()->subDay());
    }

    /** Resta días hábiles (lunes a viernes) a una fecha. */
    public static function restarDiasHabiles(Carbon $desde, int $dias): Carbon
    {
        $fecha = $desde->copy();
        while ($dias > 0) {
            $fecha->subDay();
            if (! $fecha->isWeekend()) {
                $dias--;
            }
        }

        return $fecha;
    }

    // ---------------------------------------------------------- Recordatorios

    private function solicitudesSinRevisar(Empresa $empresa, int $dias): int
    {
        $n = 0;
        foreach ($this->solicitudes($dias)->with('sede:id,nombre')->orderBy('id')->limit(200)->get() as $c) {
            Candidato::whereKey($c->id)->update(['aviso_solicitud_en' => now(), 'updated_at' => $c->updated_at]); // sin tocar updated_at: no es un movimiento
            $desde = $c->autocaptura_en ?? $c->updated_at;
            $diasEspera = (int) $desde->diffInDays(now());
            $n += $this->avisarRh($empresa, $c, 'solicitud', "Solicitud sin revisar: {$c->nombre_completo} lleva {$diasEspera} día".($diasEspera === 1 ? '' : 's'),
                'Llenó su solicitud '.($c->origen === 'web' ? 'por internet' : 'en el kiosco').' y nadie la ha revisado. Sede: '.($c->sede?->nombre ?? '').'.',
                route('candidatos.show', $c->id), ['dias' => $diasEspera]);
        }

        return $n;
    }

    private function evaluacionesSinRegistrar(Empresa $empresa, int $diasHabiles): int
    {
        $n = 0;
        $entrevistas = app(Entrevistas::class);
        foreach ($this->evaluaciones($diasHabiles)->with(['candidato:id,nombre_completo,sede_id,empresa_id,etapa', 'sede:id,nombre'])->orderBy('id')->limit(200)->get() as $p) {
            Postulacion::whereKey($p->id)->update(['aviso_evaluacion_en' => now(), 'updated_at' => $p->updated_at]); // el sello no es un movimiento
            if ($p->candidato === null) {
                continue;
            }
            $usuarios = $entrevistas->aQuienAvisar((int) $p->entrevistador_id);
            if ($usuarios->isEmpty()) {
                continue;
            }
            $cita = $p->cita_ahora ? 'del '.$this->hora->formatear($p->canalizado_en, 'd/m') : 'del '.$this->hora->formatear($p->cita_en, 'd/m H:i');
            $titulo = "Falta tu evaluación: {$p->candidato->nombre_completo} (entrevista {$cita})";
            $texto = "Pasaron más de {$diasHabiles} día".($diasHabiles === 1 ? '' : 's').' hábil'.($diasHabiles === 1 ? '' : 'es').' desde la entrevista y Recursos Humanos sigue esperando tu evaluación. Sede: '.($p->sede?->nombre ?? '').'.';
            $this->retirarAnterior(Entrevistas::REFERENCIA, (int) $p->id, $titulo);
            $this->notificaciones->avisar((int) $p->empresa_id, $usuarios->pluck('id'), 'plazo_candidato', [
                'titulo' => $titulo, 'texto' => $texto, 'url' => route('entrevistas.show', $p->id),
                'referencia_tipo' => Entrevistas::REFERENCIA, 'referencia_id' => $p->id,
            ]);
            app(AvisosCorreo::class)->recepcion((int) $p->empresa_id, 'plazo_candidato', $usuarios->pluck('email')->filter()->values()->all(),
                new AvisoRecepcion($titulo, [$texto], [['Abrir la entrevista', route('entrevistas.index')]]));
            $this->auditar($p->candidato, 'plazo', ['plazo' => 'evaluacion', 'postulacion_id' => $p->id, 'dias_habiles' => $diasHabiles, 'avisados' => $usuarios->pluck('id')->all()]);
            $n++;
        }

        return $n;
    }

    private function elegidosSinDocumentos(Empresa $empresa, int $dias): int
    {
        $n = 0;
        $documentos = app(DocumentosParaContratar::class);
        foreach ($this->elegidos($dias)->with(['candidato.documentos', 'sede:id,nombre'])->orderBy('id')->limit(200)->get() as $p) {
            Postulacion::whereKey($p->id)->update(['aviso_documentos_en' => now(), 'updated_at' => $p->updated_at]); // el sello no es un movimiento
            if ($p->candidato === null) {
                continue;
            }
            $faltan = $documentos->faltantesParaContratar($p->candidato, $p);
            if ($faltan === []) {
                continue; // Ya tiene todo: no hay nada que recordar
            }
            $diasEspera = (int) $p->elegido_en->diffInDays(now());
            $n += $this->avisarRh($empresa, $p->candidato, 'documentos', "Elegido sin contratar: {$p->candidato->nombre_completo} lleva {$diasEspera} día".($diasEspera === 1 ? '' : 's'),
                'Pendiente: '.implode('; ', array_map(fn ($f) => str_replace('Falta: ', '', $f), $faltan)).'. Sede: '.($p->sede?->nombre ?? '').'.',
                route('candidatos.show', $p->candidato_id), ['postulacion_id' => $p->id, 'dias' => $diasEspera, 'faltan' => count($faltan)]);
        }

        return $n;
    }

    private function porAtenderSinMovimiento(Empresa $empresa, int $dias): int
    {
        $n = 0;
        foreach ($this->porAtender($dias)->with(['candidato:id,nombre_completo,sede_id,empresa_id,etapa', 'sede:id,nombre'])->orderBy('id')->limit(200)->get() as $p) {
            Postulacion::whereKey($p->id)->update(['aviso_por_atender_en' => now(), 'updated_at' => $p->updated_at]); // el sello no es un movimiento
            if ($p->candidato === null) {
                continue;
            }
            $diasEspera = (int) $p->updated_at->diffInDays(now());
            $n += $this->avisarRh($empresa, $p->candidato, 'por_atender', ($p->etapa === 'registrado' ? 'Sin atender' : 'Sin canalizar').": {$p->candidato->nombre_completo} lleva {$diasEspera} día".($diasEspera === 1 ? '' : 's').' en «'.$p->etiquetaEtapa().'»',
                ($p->etapa === 'registrado' ? 'Está «Por atender» y nadie lo ha atendido (Atender, Considerar o Rechazar).' : 'Está «Con RR. HH.» y sigue sin canalizar al departamento (Entrevistar, Canalizar al departamento, Considerar o Rechazar).').' Sede: '.($p->sede?->nombre ?? '').'.',
                route('candidatos.show', $p->candidato_id), ['postulacion_id' => $p->id, 'dias' => $diasEspera]);
        }

        return $n;
    }

    /**
     * Cita vencida hace N horas sin registro en caseta: se propone a RR. HH.
     * «Marcar como no se presentó» (botón en la campana). No se marca solo.
     */
    private function citasVencidas(Empresa $empresa, int $horas): int
    {
        $n = 0;
        foreach ($this->citas($horas)->with(['candidato:id,nombre_completo,sede_id,empresa_id,etapa', 'sede:id,nombre'])->orderBy('id')->limit(200)->get() as $p) {
            Postulacion::whereKey($p->id)->update(['aviso_no_se_presento_en' => now(), 'updated_at' => $p->updated_at]); // el sello no es un movimiento
            if ($p->candidato === null) {
                continue;
            }
            $vino = Acceso::where('postulacion_id', $p->id)->where('entrada_at', '>=', $p->cita_en->copy()->subHours(6))->exists();
            if ($vino) {
                continue;
            }
            $titulo = "¿No se presentó? {$p->candidato->nombre_completo} tenía cita el ".$this->hora->formatear($p->cita_en, 'd/m H:i');
            $texto = "Pasaron más de {$horas} horas de la cita y la caseta no registró su llegada. Si no vino, márcalo; si sí vino, evalúa o reprograma desde su ficha. Sede: ".($p->sede?->nombre ?? '').'.';
            $n += $this->avisarRh($empresa, $p->candidato, 'no_se_presento', $titulo, $texto, route('candidatos.show', $p->candidato_id),
                ['postulacion_id' => $p->id, 'horas' => $horas], [[
                    'etiqueta' => 'Marcar como no se presentó', 'url' => route('candidatos.no-se-presento', $p->candidato_id), 'campos' => [], 'estilo' => 'rechazar',
                ]], Entrevistas::REFERENCIA, $p->id);
        }

        return $n;
    }

    // ------------------------------------------------------- Mis pendientes

    /**
     * Color de cada renglón de Mis pendientes según los plazos de la empresa:
     * ámbar si algo ya pasó del plazo, rojo si pasó del doble. Claves de los
     * renglones: solicitudes, recepcion (por atender), por_entrevistar (RR. HH.),
     * elegidos (documentos), entrevistas (evaluación del departamento).
     *
     * @param  list<string>|null  $solo  renglones a calcular (null = todos)
     * @return array<string, string>
     */
    public function nivelesPara(User $actor, int $empresaId, ?array $solo = null): array
    {
        $empresa = Empresa::find($empresaId);
        if ($empresa === null) {
            return [];
        }
        $p = $this->plazos($empresa);
        $niveles = [];
        $nivel = function (Builder $base, string $columna, \Closure $limite, string $clave) use (&$niveles, $solo): void {
            if ($solo !== null && ! in_array($clave, $solo, true)) {
                return;
            }
            if ((clone $base)->where($columna, '<=', $limite(2))->exists()) {
                $niveles[$clave] = 'roja';
            } elseif ((clone $base)->where($columna, '<=', $limite(1))->exists()) {
                $niveles[$clave] = 'ambar';
            }
        };
        if ($actor->can('candidatos.editar')) {
            $suyos = fn () => app(AdministradorCandidatos::class)->limitar(Candidato::query(), $actor, 'candidatos.editar');
            $nivel($suyos()->where('autocaptura_pendiente', true)->whereNull('archivada_en'), 'autocaptura_en', fn ($x) => now()->subDays($p['solicitud_dias'] * $x), 'solicitudes');
            $ids = fn () => $suyos()->select('candidatos.id');
            $nivel(Postulacion::where('etapa', 'registrado')->whereIn('candidato_id', $ids()), 'updated_at', fn ($x) => now()->subDays($p['por_atender_dias'] * $x), 'recepcion');
            // C-23 (E-07): «Candidatos sin atender» de cualquier día, con el mismo plazo
            $nivel(Postulacion::whereIn('etapa', self::ETAPAS_RH)->whereNull('archivada_en')->whereIn('candidato_id', $ids()), 'updated_at', fn ($x) => now()->subDays($p['por_atender_dias'] * $x), 'sin_atender');
            $nivel(Postulacion::whereIn('etapa', Candidato::POR_ENTREVISTAR)->whereIn('candidato_id', $ids()), 'updated_at', fn ($x) => now()->subDays($p['por_atender_dias'] * $x), 'por_entrevistar');
            $nivel(Postulacion::where('etapa', 'elegido')->whereIn('candidato_id', $ids()), 'elegido_en', fn ($x) => now()->subDays($p['documentos_dias'] * $x), 'elegidos');
        }
        if ($actor->can('candidatos.evaluar')) {
            $pendientes = app(Entrevistas::class)->pendientes($actor)->where('postulaciones.cita_ahora', false);
            $nivel($pendientes, 'postulaciones.cita_en', fn ($x) => self::restarDiasHabiles(now(), $p['evaluacion_dias'] * $x), 'entrevistas');
        }

        return $niveles;
    }

    // -------------------------------------------------------------- Ficha

    /**
     * Reloj de la ficha: cuánto lleva la postulación en su etapa y su color
     * según el plazo de esa etapa (ámbar al pasarlo, rojo al doble). Sin
     * plazo que aplique (considerar, rechazado, contratado…) solo los días.
     *
     * @return array{dias: int, texto: string, nivel: ?string, plazo: ?string}
     */
    public function relojDe(Empresa $empresa, Postulacion $p, Candidato $c): array
    {
        $plazos = $this->plazos($empresa);
        $desde = $p->enEtapaDesde();
        $dias = (int) $desde->diffInDays(now());
        [$limite, $plazo] = match (true) {
            $c->autocaptura_pendiente && $c->autocaptura_en !== null => [$plazos['solicitud_dias'], 'solicitud sin revisar: '.$plazos['solicitud_dias'].' día'.($plazos['solicitud_dias'] === 1 ? '' : 's')],
            in_array($p->etapa, self::ETAPAS_RH, true) => [$plazos['por_atender_dias'], 'con Recursos Humanos: '.$plazos['por_atender_dias'].' día'.($plazos['por_atender_dias'] === 1 ? '' : 's')],
            $p->etapa === 'canalizado' && $p->entrevistador_id !== null => [$plazos['evaluacion_dias'], 'evaluación del departamento: '.$plazos['evaluacion_dias'].' día'.($plazos['evaluacion_dias'] === 1 ? '' : 's').' hábil'.($plazos['evaluacion_dias'] === 1 ? '' : 'es')],
            $p->etapa === 'elegido' => [$plazos['documentos_dias'], 'documentos y contrato: '.$plazos['documentos_dias'].' día'.($plazos['documentos_dias'] === 1 ? '' : 's')],
            default => [null, null],
        };
        if ($c->autocaptura_pendiente && $c->autocaptura_en !== null) {
            $dias = (int) $c->autocaptura_en->diffInDays(now());
        } elseif ($p->etapa === 'canalizado' && $p->entrevistador_id !== null) {
            $cita = $p->cita_ahora ? $p->canalizado_en : $p->cita_en;
            $dias = $cita !== null && $cita->lte(now()) ? (int) $cita->diffInDays(now()) : -1;
        }
        $nivel = null;
        if ($limite !== null && $dias >= 0) {
            if ($p->etapa === 'canalizado') {
                $cita = $p->cita_ahora ? $p->canalizado_en : $p->cita_en;
                $nivel = $cita === null ? null : ($cita->lte(self::restarDiasHabiles(now(), $limite * 2)) ? 'roja' : ($cita->lte(self::restarDiasHabiles(now(), $limite)) ? 'ambar' : null));
            } else {
                $nivel = $dias >= $limite * 2 ? 'roja' : ($dias >= $limite ? 'ambar' : null);
            }
        }
        $texto = $dias < 0 ? 'Cita pendiente' : ($dias === 0 ? 'Hoy' : "Lleva {$dias} día".($dias === 1 ? '' : 's'));

        return ['dias' => max(0, $dias), 'texto' => $texto, 'nivel' => $nivel, 'plazo' => $plazo];
    }

    // ------------------------------------------------------------------ Avisos

    /**
     * Campana y correo a Recursos Humanos de la sede del candidato (un aviso).
     *
     * @param  array<string, mixed>  $detalle  para la auditoría (sin datos personales)
     * @param  list<array<string, mixed>>  $acciones
     */
    private function avisarRh(Empresa $empresa, Candidato $c, string $plazo, string $titulo, string $texto, string $url, array $detalle, array $acciones = [],
        string $referenciaTipo = 'candidato', ?int $referenciaId = null): int
    {
        $usuarios = $this->destinatarios->conPermiso((int) $empresa->id, 'candidatos.editar', (int) $c->sede_id);
        if ($usuarios->isEmpty()) {
            return 0;
        }
        $this->retirarAnterior($referenciaTipo, $referenciaId ?? $c->id, $titulo);
        $this->notificaciones->avisar((int) $empresa->id, $usuarios->pluck('id'), 'plazo_candidato', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'referencia_tipo' => $referenciaTipo, 'referencia_id' => $referenciaId ?? $c->id,
            'acciones' => $acciones,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $empresa->id, 'plazo_candidato', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir su ficha', $url]]));
        $this->auditar($c, 'plazo', ['plazo' => $plazo, 'avisados' => $usuarios->pluck('id')->all()] + $detalle);

        return 1;
    }

    /**
     * El recordatorio de ayer sobre el mismo asunto (misma referencia y mismo
     * inicio de título) queda leído y sin botones: en la campana solo vive el
     * de hoy.
     */
    private function retirarAnterior(string $referenciaTipo, int $referenciaId, string $titulo): void
    {
        $inicio = preg_match('/^[^:?]*[:?]/u', $titulo, $m) ? $m[0] : $titulo; // «Solicitud sin revisar:», «¿No se presentó?»…
        Notificacion::where('tipo', 'plazo_candidato')->where('referencia_tipo', $referenciaTipo)->where('referencia_id', $referenciaId)
            ->where('titulo', 'like', addcslashes($inicio, '%_\\').'%')->whereNull('leida_en')
            ->update(['leida_en' => now(), 'acciones' => null, 'updated_at' => now()]);
    }

    /**
     * Bitácora sin usuario (lo hizo la plataforma) y sin datos personales.
     *
     * @param  array<string, mixed>  $despues
     */
    public function auditar(Candidato $c, string $accion, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $c->empresa_id, 'user_id' => null, 'evento' => 'candidatos.'.$accion,
            'auditable_type' => Candidato::class, 'auditable_id' => $c->id, 'antes' => ['etapa' => $c->etapa, 'sede_id' => $c->sede_id], 'despues' => $despues, 'ip' => null,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    public function rhDe(Empresa $empresa, int $sedeId): Collection
    {
        return $this->destinatarios->conPermiso((int) $empresa->id, 'candidatos.editar', $sedeId);
    }
}
