<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Novedad;
use App\Models\Sede;
use App\Models\User;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Robo — Seguimiento (réplica de modules/bitacora/robo_archivo.php y
 * robo_modal_editar.php). El caso nace como ticket de Robo en la Bitácora de
 * Novedades; aquí se consulta y se le da seguimiento (1. Circunstancias,
 * 2. Sospechoso, 3. Testigos, 4. Canalización) sin buscarlo entre las demás
 * categorías.
 *
 * Permisos del submódulo "robo": ver (lista y expediente) y editar
 * (Guardar Cambios). Guardar usa las mismas reglas del expediente de
 * Novedades (AdministradorNovedades::actualizar y el formato Robo): no se
 * repiten aquí. "Buscar Coincidencias", "Vincular", "Ficha de Hechos",
 * "Imprimir" y "Reabrir" son los de la Bitácora de Novedades.
 */
class RoboController extends Controller
{
    public const FILTROS = ['todos', 'abiertos', 'sin_policia', 'con_sospechoso'];

    public const POR_PAGINA = 40;

    /** Campos que se aceptan desde esta pantalla (lo demás del ticket no se toca). */
    private const CAMPOS = [
        'estatus', 'resolucion', 'nueva_nota', 'robo_hora_aproximada', 'robo_lugar_exacto', 'robo_objetos_descripcion', 'robo_valor_estimado',
        'robo_hay_sospechoso', 'robo_descripcion_sospechoso', 'robo_testigos', 'robo_se_dio_parte_policia', 'robo_folio_policial',
        'robo_canalizado_gerencia', 'robo_canalizado_legal', 'robo_observaciones_investigacion',
    ];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorNovedades $novedades,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('robo.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('seguridad.robo.index', ['sinEmpresa' => true]);
        }
        $f = $request->validate([
            'filtro' => ['nullable', 'string', 'in:'.implode(',', self::FILTROS)],
            'q' => ['nullable', 'string', 'max:100'],
            'sede' => ['nullable', 'integer'],
        ]);
        $f['filtro'] ??= 'todos';

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId, $f) {
            $base = fn () => $this->filtrarSede($this->novedades->limitarRobo(Novedad::query(), $actor, 'ver'), $f);
            $conteos = [];
            foreach (self::FILTROS as $filtro) {
                $conteos[$filtro] = $this->aplicarFiltro($base(), $filtro)->count();
            }

            $casos = $this->buscarTexto($this->aplicarFiltro($base(), $f['filtro']), $f['q'] ?? null)
                ->with(['sede:id,nombre', 'creador:id,name', 'editor:id,name', 'cerrador:id,name', 'robo.articuloVinculado:id,folio,objeto'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(self::POR_PAGINA)->withQueryString();

            $sedesVer = $this->autorizador->sedesPermitidas($actor, 'robo.ver');
            $sedesFiltro = Sede::orderBy('nombre')->get(['id', 'nombre'])->filter(fn ($s) => $sedesVer === null || in_array($s->id, $sedesVer, true))->values();

            $expediente = null;
            if ($request->filled('abrir') && ctype_digit((string) $request->query('abrir'))) {
                $expediente = $this->expediente($actor, (int) $request->query('abrir'));
            }

            return view('seguridad.robo.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'casos' => $casos,
                'conteos' => $conteos,
                'filtros' => $f,
                'sedesFiltro' => $sedesFiltro,
                'variasSedes' => $sedesFiltro->count() > 1,
                'editables' => collect($casos->items())->filter(fn (Novedad $n) => ! $n->resuelto() && $this->novedades->permiteRobo($actor, 'editar', $n))->pluck('id')->all(),
                'expediente' => $expediente,
                'puede' => ['tickets' => $this->novedades->puedeModulo($actor, 'ver')],
            ]);
        });
    }

    /**
     * Guardar Cambios del expediente de Robo (SEGCAT: novedades_proceso.php?accion=actualizar, categoría ROBO).
     */
    public function update(Request $request, int $novedad): RedirectResponse
    {
        Gate::authorize('robo.editar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $novedad) {
            $modelo = $this->novedades->limitarRobo(Novedad::query(), $actor, 'editar')->find($novedad);
            abort_if($modelo === null, 404);

            return $this->novedades->actualizar($actor, $modelo, ['categoria' => 'robo'] + $request->only(self::CAMPOS));
        });

        return redirect()->to(route('robo.index').'#robo-'.$modelo->id)->with('ok', "Expediente Robo {$modelo->folio()} guardado correctamente.");
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Texto con el que se busca un caso: folio, ubicación, lo que se llevaron, lugar y descripción.
     *
     * @param  Builder<Novedad>  $consulta
     * @return Builder<Novedad>
     */
    private function buscarTexto(Builder $consulta, ?string $texto): Builder
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return $consulta;
        }
        $numero = ltrim($texto, '#0');
        $como = fn (string $t) => '%'.addcslashes($t, '%_\\').'%';

        return $consulta->where(function (Builder $q) use ($texto, $numero, $como) {
            $q->where('ubicacion', 'like', $como(mb_strtoupper($texto)))
                ->orWhere('descripcion', 'like', $como($texto))
                ->orWhereHas('robo', fn ($r) => $r->where('objetos_descripcion', 'like', $como($texto))->orWhere('lugar_exacto', 'like', $como(mb_strtoupper($texto))));
            if ($numero !== '' && ctype_digit($numero)) {
                $q->orWhere('numero', (int) $numero);
            }
        });
    }

    /**
     * @param  Builder<Novedad>  $consulta
     * @return Builder<Novedad>
     */
    private function aplicarFiltro(Builder $consulta, string $filtro): Builder
    {
        return match ($filtro) {
            'abiertos' => $consulta->where('estatus', '!=', Novedad::RESUELTO),
            'sin_policia' => $consulta->whereDoesntHave('robo', fn ($r) => $r->where('parte_policia', true)),
            'con_sospechoso' => $consulta->whereHas('robo', fn ($r) => $r->where('hay_sospechoso', true)),
            default => $consulta,
        };
    }

    /**
     * @param  Builder<Novedad>  $consulta
     * @param  array<string, mixed>  $f
     * @return Builder<Novedad>
     */
    private function filtrarSede(Builder $consulta, array $f): Builder
    {
        return $consulta->when(! empty($f['sede']), fn ($q) => $q->where('sede_id', (int) $f['sede']));
    }

    /**
     * El expediente que se abre (?abrir=ID) con los valores del formulario: los
     * guardados o, si regresó con errores, los que se capturaron.
     *
     * @return array<string, mixed>|null
     */
    private function expediente(User $actor, int $id): ?array
    {
        $novedad = $this->novedades->limitarRobo(Novedad::query(), $actor, 'ver')->find($id);
        if ($novedad === null) {
            return null;
        }
        $formato = $this->novedades->formato('robo');
        $novedad->load(array_merge([
            'sede:id,nombre,zona_horaria,empresa_id', 'creador:id,name', 'editor:id,name', 'cerrador:id,name', 'areaEspecifica:id,nombre', 'notas',
        ], $formato->relaciones()));

        $trasError = old('_dialogo') === 'robo-'.$novedad->id;
        $v = ['estatus' => $novedad->estatus, 'resolucion' => $novedad->resolucion, 'nueva_nota' => null] + $formato->valores($novedad);
        if ($trasError) {
            $v = array_replace($v, array_intersect_key(old(), $v));
            foreach (['robo_canalizado_gerencia', 'robo_canalizado_legal'] as $casilla) {
                $v[$casilla] = (bool) old($casilla);
            }
        }

        $sedes = [(int) $novedad->sede_id];
        $colaboradores = Colaborador::with(['departamento:id,nombre', 'puesto:id,nombre'])
            ->where('activo', true)->whereNull('fusionado_en_id')
            ->where(fn ($q) => $q->whereNull('sede_id')->orWhere(fn ($x) => $x->enSedes($sedes)))
            ->orderBy('nombre')->orderBy('apellido_paterno')->limit(3000)
            ->get(['id', 'sede_id', 'nombre', 'apellido_paterno', 'apellido_materno', 'departamento_id', 'puesto_id'])
            ->map(fn (Colaborador $c) => ['n' => mb_strtoupper($c->nombreCompleto()), 'd' => $c->departamento?->nombre, 'p' => $c->puesto?->nombre, 'id' => $c->id, 's' => 'todas'])
            ->values();

        return [
            'novedad' => $novedad,
            'v' => $v,
            'editable' => ! $novedad->resuelto() && $this->novedades->permiteRobo($actor, 'editar', $novedad),
            'puedeImprimir' => $this->novedades->permite($actor, 'imprimir', $novedad),
            'puedeReabrir' => $novedad->resuelto() && $actor->can('novedades.reabrir') && $this->novedades->permite($actor, 'reabrir', $novedad),
            'colaboradores' => $colaboradores,
        ];
    }
}
