<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\User;
use App\Services\Auditoria\LectorAuditoria;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de auditoría: quién hizo qué, cuándo, desde qué IP y qué cambió.
 * Solo consulta (nadie la edita ni la borra desde la plataforma).
 *
 *  - Con alcance de empresa se ve todo lo de la empresa de trabajo.
 *  - Con alcance de sede o "solo los propios" se ven solo los movimientos propios.
 *  - El Super Administrador sin empresa elegida ve los de la plataforma
 *    (plantillas, identidad, alta de empresas).
 */
class AuditoriaController extends Controller
{
    private const POR_PAGINA = 50;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Autorizador $autorizador,
        private readonly LectorAuditoria $lector,
        private readonly HoraLocal $hora,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('auditoria.ver');
        $actor = $request->user();
        $base = $this->alcance($actor);
        $filtros = $this->filtros($request);

        $pagina = $this->filtrar(clone $base, $filtros)
            ->orderByDesc('creado_en')->orderByDesc('id')
            ->paginate(self::POR_PAGINA)->withQueryString();

        $usuarios = User::whereIn('id', (clone $base)->reorder()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']);

        return view('administracion.auditoria.index', [
            'pagina' => $pagina,
            'registros' => $this->lector->registros($pagina->getCollection()),
            'nombres' => $usuarios->pluck('name', 'id'),
            'usuarios' => $usuarios,
            'modulos' => $this->lector->modulos($base),
            'filtros' => $filtros,
            'lector' => $this->lector,
            'zona' => $this->hora->etiqueta(),
            'soloPropios' => ! $actor->es_superadmin && $this->autorizador->sedesPermitidas($actor, 'auditoria.ver') !== null,
            'puedeExportar' => $actor->can('auditoria.exportar'),
        ]);
    }

    /**
     * Los mismos movimientos filtrados, en CSV para Excel (máximo 10,000).
     */
    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('auditoria.exportar');
        $filas = $this->filtrar($this->alcance($request->user()), $this->filtros($request))
            ->orderByDesc('creado_en')->orderByDesc('id')->limit(10000)->get();
        $registros = $this->lector->registros($filas);
        $nombres = User::whereIn('id', $filas->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return response()->streamDownload(function () use ($filas, $registros, $nombres) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            fputcsv($salida, ['Fecha', 'Usuario', 'Módulo', 'Acción', 'Registro', 'IP', 'Campos que cambiaron']);
            foreach ($filas as $f) {
                $cambios = collect($this->lector->diferencias($f->antes, $f->despues))->where('cambio', true)->pluck('campo')->join(', ');
                fputcsv($salida, [
                    $this->hora->formatear($f->creado_en), $nombres[$f->user_id] ?? 'Sistema', $this->lector->modulo($f->evento),
                    $this->lector->accion($f->evento), $registros[$f->auditable_type.'#'.$f->auditable_id] ?? '', $f->ip, $cambios,
                ]);
            }
            fclose($salida);
        }, 'bitacora-auditoria-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return Builder<Auditoria>
     */
    private function alcance(User $actor): Builder
    {
        $empresaId = $this->empresa->id($actor);
        $consulta = Auditoria::query()->when($empresaId === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $empresaId));

        if (! $actor->es_superadmin && $this->autorizador->sedesPermitidas($actor, 'auditoria.ver') !== null) {
            $consulta->where('user_id', $actor->id);
        }

        return $consulta;
    }

    /**
     * @return array{desde: ?string, hasta: ?string, usuario: ?int, modulo: ?string, texto: ?string}
     */
    private function filtros(Request $request): array
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'usuario' => ['nullable', 'integer'],
            'modulo' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'texto' => ['nullable', 'string', 'max:60'],
        ]);

        return [
            'desde' => $datos['desde'] ?? null,
            'hasta' => $datos['hasta'] ?? null,
            'usuario' => isset($datos['usuario']) ? (int) $datos['usuario'] : null,
            'modulo' => $datos['modulo'] ?? null,
            'texto' => isset($datos['texto']) ? trim($datos['texto']) : null,
        ];
    }

    /**
     * Las fechas del filtro son días locales (de la zona de quien consulta).
     *
     * @param  Builder<Auditoria>  $consulta
     * @return Builder<Auditoria>
     */
    private function filtrar(Builder $consulta, array $f): Builder
    {
        $zona = $this->hora->zona();

        return $consulta
            ->when($f['desde'], fn ($q, $d) => $q->where('creado_en', '>=', Carbon::parse($d, $zona)->startOfDay()->utc()))
            ->when($f['hasta'], fn ($q, $d) => $q->where('creado_en', '<=', Carbon::parse($d, $zona)->endOfDay()->utc()))
            ->when($f['usuario'], fn ($q, $u) => $q->where('user_id', $u))
            ->when($f['modulo'], fn ($q, $m) => $q->where('evento', 'like', $m.'.%'))
            ->when($f['texto'], fn ($q, $t) => $q->where(fn ($w) => $w->where('ip', 'like', '%'.addcslashes($t, '%_\\').'%')
                ->orWhere('antes', 'like', '%'.addcslashes($t, '%_\\').'%')
                ->orWhere('despues', 'like', '%'.addcslashes($t, '%_\\').'%')));
    }
}
