<?php

namespace App\Http\Controllers;

use App\Services\Espacios\Lugares;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * C-30: sugerencias de lugares del árbol de Zonas y edificios para los
 * formularios que capturan un lugar (caseta, incidentes, reservas). Solo
 * para quien captura en alguno de esos módulos y dentro de sus sedes.
 */
class LugarController extends Controller
{
    /** Permisos con los que se captura un lugar (la búsqueda se limita a las sedes de ese permiso). */
    public const PERMISOS = ['accesos.crear', 'novedades.crear', 'novedades.editar', 'reservas.crear', 'colaboradores.crear', 'colaboradores.editar'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizador,
    ) {}

    public function buscar(Request $request, Lugares $lugares): JsonResponse
    {
        $actor = $request->user();
        $permisos = array_values(array_filter(self::PERMISOS, fn ($p) => $actor->can($p)));
        abort_if($permisos === [], 403);
        $datos = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sede' => ['nullable', 'integer'],
            'niveles' => ['nullable', Rule::in(['todos', 'especificos'])],
        ]);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }
        // Las sedes más amplias entre los permisos que tiene (null = todas)
        $sedes = [];
        foreach ($permisos as $permiso) {
            $permitidas = $this->autorizador->sedesPermitidas($actor, $permiso);
            if ($permitidas === null) {
                $sedes = null;
                break;
            }
            $sedes = array_values(array_unique([...$sedes, ...$permitidas]));
        }
        $sede = isset($datos['sede']) ? (int) $datos['sede'] : null;
        $enSede = $sede !== null && ($sedes === null || in_array($sede, $sedes, true)) ? [$sede] : $sedes;

        $resultados = $this->tenant->conEmpresa($empresaId, fn () => $lugares->buscar((string) ($datos['q'] ?? ''), $enSede,
            ($datos['niveles'] ?? 'todos') === 'especificos' ? Lugares::ESPECIFICOS : Lugares::TODOS, 8)->all());

        return response()->json(['resultados' => $resultados]);
    }
}
