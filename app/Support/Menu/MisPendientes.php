<?php

namespace App\Support\Menu;

use App\Models\Candidato;
use App\Models\DepartamentoResponsable;
use App\Models\Novedad;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\Entrevistas;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Recepcion\PanelRecepcion;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Cache;

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
        if ($empresaId === null) {
            return ['total' => 0, 'items' => []];
        }
        $llave = "mis-pendientes:{$actor->id}:{$empresaId}";

        if (! $reciente && $actor->can('candidatos.editar')) {
            app(PlazosCandidatos::class)->correrSiToca($empresaId);
        }
        if (isset($this->memoria[$llave])) {
            return $this->memoria[$llave];
        }
        if (! $reciente && is_array($guardado = Cache::get($llave))) {
            return $this->memoria[$llave] = $guardado;
        }

        $resultado = $this->tenant->conEmpresa($empresaId, fn () => $this->calcular($actor, $empresaId));
        Cache::put($llave, $resultado, self::SEGUNDOS_CACHE);

        return $this->memoria[$llave] = $resultado;
    }

    /**
     * @return array{total: int, items: list<array{clave: string, titulo: string, icono: string, total: int, url: string}>}
     */
    private function calcular(User $actor, int $empresaId): array
    {
        $items = [];

        // Autorizaciones: solo responsables o delegados de algún departamento
        $autorizaciones = app(Autorizaciones::class);
        if ($actor->can('autorizaciones.responder') && $autorizaciones->departamentosQueAtiende($actor) !== []) {
            $items[] = $this->item('autorizaciones', 'Autorizaciones por responder', 'bi-patch-check',
                $autorizaciones->pendientesPara($actor)->count(), route('autorizaciones.index'));
        }

        // Pases de salida: aprobaciones y pasos de caseta que esperan su firma
        if ($actor->can('pases_salida.ver') && ($actor->can('pases_salida.aprobar') || $actor->can('pases_salida.firmar'))) {
            $items[] = $this->item('pases_salida', 'Firmas de pases de salida', 'bi-pen',
                count(app(AdministradorPasesSalida::class)->idsPorFirmar($actor)), route('pases-salida.pendientes'));
        }

        // Procedimientos por leer y firmar «Leí y entendí» (solo si el usuario es colaborador)
        if ($actor->can('procedimientos.ver') && ($actor->getAttributes()['colaborador_id'] ?? null) !== null) {
            $items[] = $this->item('procedimientos', 'Procedimientos por leer', 'bi-book',
                app(AdministradorProcedimientos::class)->pendientesDe($actor)->count(), route('procedimientos.por-leer'));
        }

        // Altas por verificar (ADR-0006): solo quien puede verificar algún padrón
        $altas = app(AltasPorVerificar::class);
        $verifica = collect(array_keys(AltasPorVerificar::PADRONES))->contains(fn (string $p) => $altas->puedeVerificar($actor, $p));
        if ($verifica) {
            $grupos = $altas->pendientesPorPadron($actor);
            $items[] = $this->item('altas', 'Altas por verificar', 'bi-patch-question',
                (int) array_sum(array_column($grupos, 'total')),
                // Un solo padrón: directo a su lista; varios: Inicio muestra el desglose por padrón
                count($grupos) === 1 ? $grupos[0]['ruta'] : route('panel'));
        }

        // Recursos Humanos (candidatos, fases 1 y 2): solo quien puede editar candidatos, en sus sedes
        if ($actor->can('candidatos.editar')) {
            $suyos = fn () => app(AdministradorCandidatos::class)->limitar(Candidato::query(), $actor, 'candidatos.editar');
            $items[] = $this->item('recepcion', 'Esperando en Recepción', 'bi-person-check',
                app(PanelRecepcion::class)->porAtender($actor), $actor->can('recepcion_rh.ver') ? route('recepcion.index') : route('candidatos.index'));
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
            $pendientes = $entrevistas->pendientes($actor)->get(['postulaciones.id', 'postulaciones.cita_en', 'postulaciones.cita_ahora']);
            $entrevista = $pendientes->isNotEmpty()
                || Postulacion::where('entrevistador_id', $actor->id)->exists()
                || DepartamentoResponsable::where('user_id', $actor->id)->exists();
            if ($entrevista) {
                $proxima = $pendientes->first();
                $items[] = $this->item('entrevistas', 'Entrevistas por evaluar'.($proxima ? ' · próxima: '.$entrevistas->textoCita($proxima) : ''), 'bi-calendar-event',
                    $pendientes->count(), route('entrevistas.index'));
            }
        }

        // Cuentas por revisar (criterio 1): quien edita usuarios, dentro de su alcance
        if ($actor->can('usuarios.editar')) {
            $administrador = app(AdministradorUsuarios::class);
            $cuentas = $administrador->limitarAlcance(User::query()->where('users.empresa_id', $this->empresa->id($actor))->where('users.es_superadmin', false), $actor, 'usuarios.editar')
                ->where(fn ($q) => $q->where('users.bloqueado_hasta', '>', now())
                    ->orWhere(fn ($b) => $b->where('users.activo', false)->where('users.desactivado_por_baja_en', '>=', now()->subDays(7))))
                ->count();
            $items[] = $this->item('cuentas', 'Cuentas por revisar', 'bi-person-lock', $cuentas, route('usuarios.index', ['revisar' => 1]));
        }

        // Mejoras Incidentes (criterio 1): tickets canalizados a mí y pendientes de turno sin dueño de mis sedes
        if ($actor->can('novedades.editar')) {
            $novedades = app(AdministradorNovedades::class);
            $abiertos = fn () => $novedades->limitar(Novedad::query(), $actor, 'editar')->where('estatus', '!=', Novedad::RESUELTO);
            // Solo aparecen cuando hay algo (la caseta no ve renglones vacíos de la bitácora)
            if (($mios = $abiertos()->where('asignado_a', $actor->id)->count()) > 0) {
                $items[] = $this->item('novedades_mias', 'Tickets canalizados a mí', 'bi-headset', $mios, route('novedades.index', ['mios' => 1]));
            }
            if (($sinDueno = $abiertos()->where('estatus', Novedad::PENDIENTE_TURNO)->whereNull('asignado_a')->count()) > 0) {
                $items[] = $this->item('novedades_turno', 'Pendientes de turno sin dueño', 'bi-hourglass-split', $sinDueno, route('novedades.index'));
            }
        }
        // Fin Mejoras Incidentes

        // Fase 3: color por plazo, solo si algún renglón de candidatos tiene pendientes
        $conPendientes = array_filter($items, fn ($i) => $i['total'] > 0 && in_array($i['clave'], PlazosCandidatos::RENGLONES, true));
        if ($conPendientes !== []) {
            $niveles = app(PlazosCandidatos::class)->nivelesPara($actor, $empresaId, array_column($conPendientes, 'clave'));
            foreach ($items as &$item) {
                $item['nivel'] = $item['total'] > 0 ? ($niveles[$item['clave']] ?? null) : null;
            }
            unset($item);
        }

        return ['total' => array_sum(array_column($items, 'total')), 'items' => $items];
    }

    /**
     * @return array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}
     */
    private function item(string $clave, string $titulo, string $icono, int $total, string $url): array
    {
        return compact('clave', 'titulo', 'icono', 'total', 'url') + ['nivel' => null];
    }
}
