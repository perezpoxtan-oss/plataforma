<?php

namespace App\Support\Menu;

use App\Models\Acceso;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Novedad;
use App\Models\Postulacion;
use App\Models\SolicitudArco;
use App\Models\User;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Activos\PendientesActivos;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\CadenaDelegaciones;
use App\Services\Autorizaciones\Cobertura;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\Entrevistas;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\SolicitudesArco;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Recepcion\PanelRecepcion;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\PendientesPorPersona;
use App\Services\Reservas\PendientesReservas;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

/**
 * «Mis pendientes» del encabezado (junto a la campana): lo que el usuario
 * puede resolver él mismo, con su número y su enlace. Usa los mismos
 * contadores que los avisos de Inicio (PanelController); solo aparecen los
 * renglones que le aplican (por permiso y por su papel: responsable,
 * aprobador, colaborador con procedimientos, verificador de padrones,
 * Recursos Humanos: «Esperando en Recepción», «Solicitudes por revisar»,
 * «Por entrevistar (RR. HH.)», «Evaluaciones del departamento» y «Elegidos
 * por contratar»; quien entrevista: «Entrevistas por evaluar» con su cita; y
 * quien edita usuarios: «Cuentas por revisar», bloqueadas hoy o desactivadas
 * por una baja en los últimos 7 días).
 *
 * Candidatos, fase 3: cada renglón de candidatos con pendientes lleva su
 * `nivel` (ámbar si algo pasó del plazo de la empresa, roja si pasó del
 * doble) y, en la consulta periódica de la campana de quien edita
 * candidatos, si en esa empresa ya tocaba recordar, archivar o borrar algo,
 * se hace ahí (con candado de un minuto), por si el cron del hosting no
 * corre. El pintado de cada pantalla no lo dispara (no suma consultas).
 *
 * La pantalla lo calcula fresco al pintarse; la consulta periódica de la
 * campana (cada 30 s) reutiliza el resultado durante unos segundos.
 */
#[Scoped]
class MisPendientes
{
    /** Segundos que la consulta periódica reutiliza el último cálculo. */
    public const SEGUNDOS_CACHE = 25;

    /** @var array<string, array{total: int, items: list<array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}>}> */
    private array $memoria = [];

    private ?int $peticion = null;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array{total: int, items: list<array{clave: string, titulo: string, icono: string, total: int, url: string}>}
     */
    public function para(User $actor, bool $reciente = true): array
    {
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null && ! $actor->es_superadmin) {
            return ['total' => 0, 'items' => []];
        }
        $llave = "mis-pendientes:{$actor->id}:".($empresaId ?? 'plataforma');

        // C-22: el motor «Reloj y suplente» corre en proceso con la consulta de la campana (candado de un minuto por empresa)
        // Revisión Etapa 4: corre DESPUÉS de mandar la respuesta (defer), así la campana no espera al motor;
        // el candado de un minuto por empresa sigue evitando que corra en cada consulta
        if (! $reciente) {
            foreach (array_unique(array_filter([$empresaId ?? false, $actor->es_superadmin ? 'plataforma' : false], fn ($v) => $v !== false)) as $quien) {
                $id = $quien === 'plataforma' ? null : (int) $quien;
                if (MotorRelojes::yaCorrio($id)) {
                    continue; // corrió hace menos de un minuto: ni siquiera se agenda
                }
                defer(fn () => $this->tenant->conEmpresa($id, fn () => app(MotorRelojes::class)->correrSiToca($id)), 'relojes:'.($id ?? 'plataforma'));
            }
        }
        // Lo memorizado vale para una petición (Inicio y el encabezado comparten el cálculo; D-04)
        if ($this->peticion !== ($peticion = spl_object_id(app('request')))) {
            [$this->peticion, $this->memoria] = [$peticion, []];
        }
        if (isset($this->memoria[$llave])) {
            return $this->memoria[$llave];
        }
        if (! $reciente && is_array($guardado = Cache::get($llave))) {
            return $this->memoria[$llave] = $guardado;
        }

        $resultado = $empresaId === null
            ? $this->tenant->conEmpresa(null, fn () => $this->plataforma($actor, []))
            : $this->tenant->conEmpresa($empresaId, fn () => $this->calcular($actor, $empresaId));
        Cache::put($llave, $resultado, self::SEGUNDOS_CACHE);

        return $this->memoria[$llave] = $resultado;
    }

    /**
     * @return array{total: int, items: list<array{clave: string, titulo: string, icono: string, total: int, url: string}>}
     */
    private function calcular(User $actor, int $empresaId): array
    {
        $items = [];
        // La empresa ya viene cargada con el usuario (EstablecerEmpresa): los relojes no la vuelven a leer
        if ((int) $actor->empresa_id === $empresaId && $actor->relationLoaded('empresa') && $actor->empresa !== null) {
            app(MotorRelojes::class)->recordarEmpresa($actor->empresa);
        }

        // Autorizaciones: solo responsables o delegados de algún departamento
        $autorizaciones = app(Autorizaciones::class);
        if ($actor->can('autorizaciones.responder') && $autorizaciones->departamentosQueAtiende($actor) !== []) {
            $porResponder = $autorizaciones->pendientesPara($actor);
            // C-12 «No molestar»: lo que mi delegado puede responder no me espera a mí
            // (directa o en cadena: basta con que alguien de mi cadena pueda responderla)
            $cadena = $autorizaciones->cadena()->cadena((int) $actor->id);
            if ($porResponder->isNotEmpty() && $cadena !== []) {
                $porResponder = $porResponder->reject(fn ($a) => collect($cadena)
                    ->contains(fn (int $id) => $autorizaciones->puedeResponder($autorizaciones->cadena()->usuario($id), $a)));
            }
            $items[] = $this->item('autorizaciones', 'Autorizaciones por responder', 'bi-patch-check',
                $porResponder->count(), route('autorizaciones.index'));
        }

        // C-08 / DD-11: visitas que esperan al supervisor porque su departamento no tiene quien autorice en la sede
        if ($actor->can('accesos.aprobar')) {
            $esperan = app(ConsultaAccesos::class)->limitar(Acceso::query(), $actor, 'accesos.aprobar')
                ->where('accesos.estado', 'pendiente')
                ->where(fn ($q) => $q->where('accesos.autorizacion', Acceso::SIN_RESPONSABLE)->orWhereNotNull('accesos.supervisor_pedido_en'))
                ->toBase()->selectRaw('COUNT(*) AS total, MIN(accesos.entrada_at) AS desde')->first();
            // Revisión Etapa 4: con delegaciones de por medio, la misma regla que el aviso del motor (CadenaDelegaciones::destino())
            $cadena = $autorizaciones->cadena();
            if ((int) ($esperan->total ?? 0) > 0 && $cadena->mapa() !== [] && (isset($cadena->mapa()[(int) $actor->id]) || $cadena->titularesDe((int) $actor->id) !== [])) {
                $mias = app(MotorRelojes::class)->reloj('caseta_supervisor')->esperasDe($actor, $empresaId);
                $esperan = (object) ['total' => $mias->count(), 'desde' => $mias->min(fn ($e) => $e->desde->getTimestamp())];
                $esperan->desde = $esperan->desde === null ? null : Carbon::createFromTimestamp($esperan->desde, 'UTC')->format('Y-m-d H:i:s');
            }
            if (($total = (int) ($esperan->total ?? 0)) > 0) {
                $empresa = app(MotorRelojes::class)->empresa($empresaId); // una lectura por petición
                [$ambar, $roja] = app(Escalamiento::class)->umbrales($empresa);
                $minutos = $esperan->desde ? (int) max(0, now()->diffInMinutes(Carbon::parse($esperan->desde, 'UTC'))) : 0;
                $items[] = ['nivel' => $minutos >= $roja ? 'roja' : ($minutos >= $ambar ? 'ambar' : null)]
                    + $this->item('esperan_supervisor', 'Esperan tu autorización en caseta', 'bi-hourglass-split', $total, route('accesos.index', ['pestana' => 'pendientes']));
            }
        }

        // C-08: departamentos que se quedaron sin quien autorice (baja de su responsable, cambio de rol o de sede)
        $autorizador = app(Autorizador::class);
        if ($actor->can('autorizaciones.configurar') && $autorizador->alcanceDeEmpresa($actor, 'autorizaciones.configurar')) {
            $perdidas = app(Cobertura::class)->sinCobertura(soloPerdidas: true);
            if ($perdidas !== []) {
                $deptos = collect($perdidas)->groupBy('departamento_id');
                $items[] = $this->item('cobertura', 'Sin quien autorice', 'bi-person-exclamation', $deptos->count(),
                    route('autorizaciones.responsables').'#depto-'.$deptos->keys()->first())
                    + ['detalle' => $deptos->count() > 1 ? $deptos->map(fn ($f, $id) => ['titulo' => $f->first()['departamento'].' ('.$f->pluck('sede')->join(', ').')',
                        'total' => $f->count(), 'url' => route('autorizaciones.responsables').'#depto-'.$id, 'icono' => 'bi-diagram-2'])->values()->all() : [],
                        'frase' => Cobertura::frase($perdidas)];
            }
        }

        // Pases de salida: aprobaciones y pasos de caseta que esperan su firma
        if ($actor->can('pases_salida.ver') && ($actor->can('pases_salida.aprobar') || $actor->can('pases_salida.firmar'))) {
            $pases = app(AdministradorPasesSalida::class);
            $porFirmar = $pases->idsPorFirmar($actor);
            // C-12 «No molestar»: las aprobaciones que mi delegado puede firmar no me esperan a mí
            if ($porFirmar !== [] && ($delegado = $this->delegadoDe($actor, 'pases_salida.aprobar')) !== null) {
                $deEl = array_intersect($pases->idsPorAprobar($actor), $pases->idsPorAprobar($delegado));
                $porFirmar = array_values(array_diff($porFirmar, $deEl));
            }
            $items[] = $this->item('pases_salida', 'Firmas de pases de salida', 'bi-pen', count($porFirmar), route('pases-salida.pendientes'));
        }

        // Mejoras estructura (ADR-0016): versiones de procedimientos que esperan la aprobación de quien entra
        // D-02 (revisión Etapa 4): el mismo conjunto que el recordatorio del motor y la delegación en cadena de la Etapa 3
        $porPersona = app(PendientesPorPersona::class);
        $motor = app(MotorRelojes::class);
        $llavesDeRenglon = [];
        if ($actor->can('procedimientos.aprobar')) {
            $llavesDeRenglon['procedimientos_aprobar'] = $motor->conCadenaLlaves($actor, fn (User $u) => $porPersona->procedimientosPorAprobar($u));
            $items[] = $this->item('procedimientos_aprobar', 'Procedimientos por aprobar', 'bi-journal-check',
                count($llavesDeRenglon['procedimientos_aprobar']), route('procedimientos.index', ['filtro' => 'por_aprobar']));
        }
        // Fin Mejoras estructura

        // Procedimientos por leer y firmar «Leí y entendí» (solo si el usuario es colaborador)
        if ($actor->can('procedimientos.ver') && ($actor->getAttributes()['colaborador_id'] ?? null) !== null) {
            $items[] = $this->item('procedimientos', 'Procedimientos por leer', 'bi-book',
                app(AdministradorProcedimientos::class)->pendientesDe($actor)->count(), route('procedimientos.por-leer'));
        }

        // Altas por verificar (ADR-0006) — C-27: una sola bandeja con Personas, Vehículos, Empresas y Colaboradores
        $altas = app(AltasPorVerificar::class);
        $verifica = collect(array_keys(AltasPorVerificar::PADRONES))->contains(fn (string $p) => $altas->puedeVerificar($actor, $p));
        if ($verifica || $actor->can('colaboradores.aprobar')) {
            // D-02: lo que puede verificar con su alcance, con la delegación en cadena (mismo conjunto que el motor)
            $llavesDeRenglon['altas'] = $motor->conCadenaLlaves($actor, fn (User $u) => $porPersona->altas($u)['llaves']);
            $porPadron = [];
            foreach ($llavesDeRenglon['altas'] as $llave) {
                $padron = substr(strstr($llave, ':', true), 5); // «alta_personas:12» → personas
                $porPadron[$padron] = ($porPadron[$padron] ?? 0) + 1;
            }
            $definiciones = AltasPorVerificar::PADRONES + ['colaboradores' => ['nombre' => 'Colaboradores', 'icono' => 'bi-person-exclamation']];
            $grupos = [];
            foreach ($definiciones as $padron => $d) {
                if (($porPadron[$padron] ?? 0) > 0) {
                    $grupos[] = ['padron' => $padron, 'nombre' => $d['nombre'], 'icono' => $d['icono'], 'total' => $porPadron[$padron],
                        'ruta' => route('altas_por_verificar.index', ['pestana' => $padron])];
                }
            }
            $items[] = $this->item('altas', count($grupos) === 1 ? 'Altas por verificar · '.$grupos[0]['nombre'] : 'Altas por verificar', 'bi-patch-question',
                (int) array_sum(array_column($grupos, 'total')),
                $grupos[0]['ruta'] ?? route('altas_por_verificar.index'))
                + ['detalle' => count($grupos) > 1 ? array_map(fn ($g) => ['titulo' => $g['nombre'], 'total' => $g['total'], 'url' => $g['ruta'], 'icono' => $g['icono']], $grupos) : []];
        }

        // Recursos Humanos (candidatos, fases 1 y 2): solo quien puede editar candidatos, en sus sedes
        if ($actor->can('candidatos.editar')) {
            $suyos = fn () => app(AdministradorCandidatos::class)->limitar(Candidato::query(), $actor, 'candidatos.editar');
            $items[] = $this->item('recepcion', 'Esperando en Recepción', 'bi-person-check',
                app(PanelRecepcion::class)->porAtender($actor), $actor->can('recepcion_rh.ver') ? route('recepcion.index') : route('candidatos.index'));
            // C-23 (E-07): «Por atender» y «Con RR. HH.» de días anteriores (los de hoy van en «Esperando en Recepción»)
            $inicioHoy = now()->setTimezone(app(HoraLocal::class)->zona())->startOfDay()->utc();
            $items[] = $this->item('sin_atender', 'Candidatos sin atender', 'bi-person-exclamation',
                Postulacion::whereIn('etapa', PlazosCandidatos::ETAPAS_RH)->whereNull('archivada_en')->whereIn('candidato_id', $suyos()->select('candidatos.id'))
                    ->where(fn ($q) => $q->where('etapa', 'con_rh')->orWhereHas('candidato', fn ($c) => $c->where(fn ($x) => $x->where('llegada_en', '<', $inicioHoy)
                        ->orWhere(fn ($y) => $y->whereNull('llegada_en')->where('created_at', '<', $inicioHoy)))))->count(),
                route('candidatos.index', ['etapa' => 'registrado']));
            $items[] = $this->item('solicitudes', 'Solicitudes por revisar', 'bi-phone',
                $suyos()->where('autocaptura_pendiente', true)->count(), route('candidatos.index', ['revisar' => 1]));
            $items[] = $this->item('por_entrevistar', 'Por entrevistar (RR. HH.)', 'bi-chat-square-text',
                $suyos()->whereIn('etapa', Candidato::POR_ENTREVISTAR)->count(), route('candidatos.index', ['etapa' => 'por_entrevistar']));
            $items[] = $this->item('evaluaciones_departamento', 'Evaluaciones del departamento', 'bi-clipboard-check',
                $suyos()->where('etapa', 'evaluado')->count(), route('candidatos.index', ['etapa' => 'evaluado']));
            $items[] = $this->item('elegidos', 'Elegidos por contratar', 'bi-person-check-fill',
                $suyos()->where('etapa', 'elegido')->count(), route('candidatos.index', ['etapa' => 'elegido']));
        }

        // Quien entrevista (candidatos, fase 2): lo que Recursos Humanos le canalizó, con la cita más próxima
        if ($actor->can('candidatos.evaluar')) {
            $entrevistas = app(Entrevistas::class);
            $pendientes = $entrevistas->pendientes($actor)->get(['postulaciones.id', 'postulaciones.cita_en', 'postulaciones.cita_ahora', 'postulaciones.entrevistador_id']);
            // C-12 «No molestar»: las mías las atiende mi delegado (si puede entrevistar)
            if ($pendientes->contains(fn ($p) => (int) $p->entrevistador_id === (int) $actor->id)
                && $this->delegadoDe($actor, 'candidatos.evaluar') !== null) {
                $pendientes = $pendientes->reject(fn ($p) => (int) $p->entrevistador_id === (int) $actor->id)->values();
            }
            $entrevista = $pendientes->isNotEmpty()
                || Postulacion::where('entrevistador_id', $actor->id)->exists()
                || DepartamentoResponsable::where('user_id', $actor->id)->exists();
            if ($entrevista) {
                // La cita más próxima que sí tenga fecha (o «Ahora»); sin ninguna, no se dice «próxima»
                $proxima = $pendientes->first(fn (Postulacion $p) => $entrevistas->textoCita($p) !== '');
                $items[] = $this->item('entrevistas', 'Entrevistas por evaluar'.($proxima ? ' · próxima: '.$entrevistas->textoCita($proxima) : ''), 'bi-calendar-event',
                    $pendientes->count(), route('entrevistas.index'));
            }
        }

        // Cuentas por revisar (criterio 1): el número es el de los renglones que esta persona puede atender en Usuarios (A-03):
        // las cuentas por revisar (usuarios.editar) más las peticiones de «Pedir a mi administrador» que le tocan
        // (contraseña con usuarios.editar, dos pasos con usuarios.desbloquear; alcance y jerarquía). Si no hay ninguna
        // petición abierta en la empresa, no se calcula nada más (una consulta).
        // D-02: el mismo conjunto que el recordatorio del motor (PendientesPorPersona), con la delegación en cadena
        $propias = $porPersona->cuentas($actor, $empresaId);
        if ($propias['mostrar']) {
            $llavesDeRenglon['cuentas'] = $motor->conCadenaLlaves($actor, fn (User $u) => $porPersona->cuentas($u, $empresaId)['llaves']);
            $total = $llavesDeRenglon['cuentas'] === $propias['llaves'] ? $propias['total'] : count($llavesDeRenglon['cuentas']);
            $items[] = $this->item('cuentas', 'Cuentas por revisar', 'bi-person-lock', $total, route('usuarios.index', ['revisar' => 1]));
        }

        // Privacidad (criterios 1 y 3): solicitudes ARCO abiertas, con el color de su plazo legal
        if ($actor->can(SolicitudesArco::PERMISO) && app(Autorizador::class)->alcanceDeEmpresa($actor, SolicitudesArco::PERMISO)) {
            $abiertas = SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)
                ->whereIn('estado', [SolicitudArco::POR_VERIFICAR, SolicitudArco::PENDIENTE])->get(['id', 'estado', 'vence_en']);
            if ($abiertas->isNotEmpty()) {
                $empresa = app(MotorRelojes::class)->empresa($empresaId); // una lectura por petición
                $recordar = $empresa ? app(ConservacionDatos::class)->ajustes($empresa)['arco_recordar_dias'] : ConservacionDatos::RECORDAR_ARCO;
                $niveles = $abiertas->map(fn ($x) => SolicitudesArco::nivel($x, $recordar))->filter();
                $nivel = $niveles->contains('roja') ? 'roja' : ($niveles->contains('ambar') ? 'ambar' : null);
                $items[] = ['nivel' => $nivel] + $this->item('arco', 'Solicitudes ARCO por atender', 'bi-person-vcard', $abiertas->count(), route('arco.index'));
            }
        }

        // Mejoras Incidentes (criterio 1): tickets canalizados a mí y pendientes de turno sin dueño de mis sedes
        if ($actor->can('novedades.editar')) {
            $novedades = app(AdministradorNovedades::class);
            // Una sola consulta para los dos renglones (sin N+1)
            $conteo = $novedades->limitar(Novedad::query(), $actor, 'editar')
                ->where('estatus', '!=', Novedad::RESUELTO)
                ->where(fn ($q) => $q->where('asignado_a', $actor->id)
                    ->orWhere(fn ($x) => $x->where('estatus', Novedad::PENDIENTE_TURNO)->whereNull('asignado_a')))
                ->toBase()
                ->selectRaw('SUM(CASE WHEN asignado_a = ? THEN 1 ELSE 0 END) AS mios', [$actor->id])
                ->selectRaw('SUM(CASE WHEN asignado_a IS NULL AND estatus = ? THEN 1 ELSE 0 END) AS sin_dueno', [Novedad::PENDIENTE_TURNO])
                ->first();
            // Solo aparecen cuando hay algo (la caseta no ve renglones vacíos de la bitácora)
            if (($mios = (int) ($conteo->mios ?? 0)) > 0) {
                $items[] = $this->item('novedades_mias', 'Tickets canalizados a mí', 'bi-headset', $mios, route('novedades.index', ['mios' => 1]));
            }
            if (($sinDueno = (int) ($conteo->sin_dueno ?? 0)) > 0) {
                $items[] = $this->item('novedades_turno', 'Pendientes de turno sin dueño', 'bi-hourglass-split', $sinDueno, route('novedades.index'));
            }
        }
        // Fin Mejoras Incidentes
        // Mejoras activos (ADR-0014): Vo.Bo. de vales, procedimientos por aprobar, llaves y equipos de turno fuera de tiempo
        foreach (app(PendientesActivos::class)->para($actor, $empresaId) as $pendiente) {
            $items[] = $pendiente;
        }
        // Fin Mejoras activos
        // Reservas de áreas (docs/tecnico/reservas.md): por aprobar, órdenes de servicio y cambios pedidos
        // Revisión Etapa 1 (A-5): sin el complemento «Reservas y eventos» no se le muestran ni a los responsables de departamento
        if (app(Autorizador::class)->moduloActivo($empresaId, 'reservas')
            && ($actor->can('reservas.ver') || $actor->can('reservas.crear') || DepartamentoResponsable::where('user_id', $actor->id)->exists())) {
            foreach (app(PendientesReservas::class)->para($actor, $empresaId) as $pendiente) {
                $items[] = $pendiente;
            }
        }
        // Fin Reservas de áreas

        // C-22 a C-26: renglones nuevos de lo que espera (vacantes, pases vencidos, Lost & Found, caseta, vouchers…)
        // y el color del motor en los renglones que ya existían
        $activos = $motor->relojesDe($empresaId);
        foreach ($activos as $reloj) {
            if (($renglon = $reloj->renglon($actor, $empresaId)) !== null) {
                $items[] = $renglon;
            }
            // D-01: lo que le pasaron como suplente y su renglón de siempre no le cuenta
            if (($renglon = $reloj->renglonSuplente($actor, $empresaId)) !== null) {
                $items[] = $renglon;
            }
        }
        $colores = ['altas' => 'altas', 'cuentas' => 'cuentas', 'novedades_mias' => 'novedades_canalizadas', 'procedimientos_aprobar' => 'procedimientos_aprobar'];
        foreach ($items as &$item) {
            if (isset($colores[$item['clave']]) && $item['total'] > 0 && ($item['nivel'] ?? null) === null && isset($activos[$colores[$item['clave']]])) {
                $reloj = $motor->reloj($colores[$item['clave']]);
                // D-02: el color sale de las mismas llaves que el número
                $item['nivel'] = isset($llavesDeRenglon[$item['clave']]) ? $reloj->nivelDeLlaves($llavesDeRenglon[$item['clave']], $empresaId) : $reloj->nivelPara($actor, $empresaId);
            }
        }
        unset($item);
        // C-08 / C-25: «Esperan tu autorización en caseta» también cuenta lo que la caseta le pidió al supervisor
        if ($actor->es_superadmin) {
            $items = $this->plataforma($actor, $items)['items'];
        }

        // Fase 3: color por plazo, solo si algún renglón de candidatos tiene pendientes
        $conPendientes = array_filter($items, fn ($i) => $i['total'] > 0 && in_array($i['clave'], PlazosCandidatos::RENGLONES, true));
        if ($conPendientes !== []) {
            $niveles = app(PlazosCandidatos::class)->nivelesPara($actor, $empresaId, array_column($conPendientes, 'clave'));
            foreach ($items as &$item) {
                $item['nivel'] = $item['total'] > 0 ? ($niveles[$item['clave']] ?? $item['nivel']) : null; // Mejoras activos: conserva el color de llaves y equipos de turno
            }
            unset($item);
        }

        return ['total' => array_sum(array_column($items, 'total')), 'items' => $items] + ($items === [] ? [] : ['vista' => self::vista($items)]);
    }

    /**
     * C-26 (E-05): lo que espera al Super Administrador (con o sin empresa de
     * trabajo): solicitudes, pruebas por aprobar, clientes por revisar y en gracia.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{total: int, items: list<array<string, mixed>>, vista?: list<array<string, mixed>>}
     */
    private function plataforma(User $actor, array $items): array
    {
        $this->tenant->conEmpresa(null, function () use ($actor, &$items) {
            foreach (app(MotorRelojes::class)->relojesDe(null) as $reloj) {
                if (($renglon = $reloj->renglon($actor, null)) !== null) {
                    $items[] = $renglon;
                }
                if (($renglon = $reloj->renglonSuplente($actor, null)) !== null) {
                    $items[] = $renglon;
                }
            }
        });

        return ['total' => array_sum(array_column($items, 'total')), 'items' => $items] + ($items === [] ? [] : ['vista' => self::vista($items)]);
    }

    /**
     * C-12: a quién le llega lo que yo apruebo con ese permiso: el último de mi
     * cadena de delegaciones (A→B→C) que lo tenga; null si me queda a mí.
     */
    private function delegadoDe(User $actor, string $permiso): ?User
    {
        $this->cadena ??= app(Autorizaciones::class)->cadena();
        $destino = $this->cadena->destino((int) $actor->id, fn (User $d) => $d->can($permiso));

        return $destino === (int) $actor->id ? null : $this->cadena->usuario($destino);
    }

    private ?CadenaDelegaciones $cadena = null;

    // Mejoras estructura (ADR-0016): lo que se pinta, sin renglones en cero y con candidatos en un solo renglón

    /** Renglones de Recursos Humanos que se agrupan en «Candidatos». */
    public const GRUPO_CANDIDATOS = ['recepcion', 'sin_atender', 'solicitudes', 'por_entrevistar', 'evaluaciones_departamento', 'elegidos'];

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function vista(array $items): array
    {
        $vista = [];
        $candidatos = null;
        foreach ($items as $item) {
            if ($item['total'] <= 0) {
                continue;
            }
            if (in_array($item['clave'], self::GRUPO_CANDIDATOS, true)) {
                if ($candidatos === null) {
                    $candidatos = count($vista);
                    $vista[] = ['clave' => 'candidatos', 'titulo' => 'Candidatos', 'icono' => 'bi-people', 'total' => 0, 'url' => $item['url'], 'nivel' => null, 'detalle' => []];
                }
                $vista[$candidatos]['total'] += $item['total'];
                $vista[$candidatos]['detalle'][] = ['titulo' => $item['titulo'], 'total' => $item['total'], 'url' => $item['url'], 'icono' => $item['icono'], 'nivel' => $item['nivel'] ?? null];
                $nivel = $item['nivel'] ?? null;
                if ($nivel === 'roja' || ($nivel === 'ambar' && $vista[$candidatos]['nivel'] === null)) {
                    $vista[$candidatos]['nivel'] = $nivel;
                }

                continue;
            }
            $vista[] = $item + ['detalle' => []];
        }

        return $vista;
    }
    // Fin Mejoras estructura

    /**
     * @return array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}
     */
    private function item(string $clave, string $titulo, string $icono, int $total, string $url): array
    {
        return compact('clave', 'titulo', 'icono', 'total', 'url') + ['nivel' => null];
    }
}
