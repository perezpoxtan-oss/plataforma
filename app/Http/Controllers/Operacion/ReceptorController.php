<?php

namespace App\Http\Controllers\Operacion;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Support\Entrada;
use App\Support\Receptor;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * C-16: búsqueda del selector «¿A quién se le entrega?» (componentes.receptor)
 * en el Padrón de personas y en Empresas externas. La usan quienes prestan
 * llaves, entregan responsivas o dan de baja con voucher, aunque no
 * administren esos padrones: solo devuelve nombre y empresa, nunca la
 * identificación ni el teléfono. Lo restringido sale marcado y no se puede elegir.
 */
class ReceptorController extends Controller
{
    /** Acciones de las pantallas que muestran el selector. */
    public const PERMISOS = ['prestamo_llaves.crear', 'responsivas.crear', 'responsivas.editar', 'llaves.eliminar', 'gafetes.eliminar', 'equipos.eliminar'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
    ) {}

    public function buscar(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(collect(self::PERMISOS)->contains(fn (string $p) => $actor->can($p)), 403);
        $empresaId = $this->empresa->id($actor);
        $tipo = Entrada::texto($request->query('tipo'));
        $texto = trim((string) preg_replace('/\s+/u', ' ', mb_substr(Entrada::texto($request->query('q')), 0, 100)));
        if ($empresaId === null || mb_strlen($texto) < 2 || ! in_array($tipo, [Receptor::PERSONA, Receptor::PROVEEDOR], true)) {
            return response()->json(['resultados' => []]);
        }
        $comodin = fn (string $t) => '%'.addcslashes(mb_strtolower($t), '%_\\').'%';

        $resultados = $this->tenant->conEmpresa($empresaId, function () use ($tipo, $texto, $comodin) {
            if ($tipo === Receptor::PERSONA) {
                return Persona::query()->with('proveedor:id,nombre')->where('activo', true)->whereNull('fusionado_en_id')
                    ->where(function ($q) use ($texto, $comodin) {
                        foreach (explode(' ', $texto) as $palabra) {
                            $q->whereRaw('LOWER(nombre_completo) LIKE ?', [$comodin($palabra)]);
                        }
                    })->orderBy('nombre_completo')->limit(15)->get()
                    ->map(fn (Persona $p) => $this->fila($p, $p->nombre_completo, trim((Persona::TIPOS[$p->tipo] ?? '').' · '.($p->empresaQueRepresenta() ?? ''), ' ·')));
            }

            return Proveedor::query()->where('activo', true)->whereNull('fusionado_en_id')
                ->whereRaw('LOWER(nombre) LIKE ?', [$comodin($texto)])->orderBy('nombre')->limit(15)->get()
                ->map(fn (Proveedor $p) => $this->fila($p, $p->nombre, Proveedor::CATEGORIAS[$p->categoria] ?? null));
        });

        return response()->json(['resultados' => $resultados->values()->all()]);
    }

    /**
     * @return array{id: int, titulo: string, detalle: ?string, nombre: string, restringido: bool}
     */
    private function fila(Persona|Proveedor $registro, string $titulo, ?string $detalle): array
    {
        $restringido = method_exists($registro, 'restringido') && $registro->restringido();

        return [
            'id' => (int) $registro->id,
            'titulo' => $titulo,
            'detalle' => $restringido ? 'Acceso restringido: no se le puede entregar nada' : $detalle,
            'nombre' => Receptor::nombreDe($registro),
            'restringido' => $restringido,
        ];
    }
}
