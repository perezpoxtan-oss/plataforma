<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Borrado\BorradoSeguro;
use App\Services\Borrado\RegistroBorrado;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * "Eliminar definitivamente" de catálogos y padrones (ver RegistroBorrado y
 * docs/tecnico/borrado.md). Una sola pantalla de confirmación para todos:
 *
 *  GET    /borrar/{registro}/{id}  qué es, qué hay que teclear y qué lo usa (JSON)
 *  DELETE /borrar/{registro}/{id}  lo borra si nada depende de él
 *
 * Orden de las revisiones: permiso "<modulo>.borrar" (403), empresa de
 * trabajo y registro (404: de otra empresa o inexistente), alcance (de otra
 * sede: 404; un catálogo de toda la empresa sin alcance de empresa: 403).
 */
class BorradoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizador,
        private readonly BorradoSeguro $borrado,
    ) {}

    public function revisar(Request $request, string $registro, int $id): JsonResponse
    {
        [$definicion, $modelo, $empresaId] = $this->resolver($request, $registro, $id);
        $actor = $request->user();

        $datos = $this->tenant->conEmpresa($empresaId, function () use ($definicion, $modelo, $actor) {
            $dependencias = $this->borrado->dependencias($definicion, $modelo, $actor);

            return [$dependencias, $this->borrado->mensaje($definicion, $dependencias)];
        });
        [$dependencias, $mensaje] = $datos;

        return response()->json([
            'nombre' => RegistroBorrado::nombre($definicion, $modelo),
            'tipo' => RegistroBorrado::conArticulo($definicion),
            'confirmar' => RegistroBorrado::confirmacion($definicion, $modelo),
            'puede_eliminar' => $mensaje === null,
            'mensaje' => $mensaje,
            'baja' => $mensaje === null ? null : $this->baja($definicion, $modelo, $actor),
            'url' => route('borrar.destroy', ['registro' => $registro, 'id' => $modelo->getKey()]),
        ]);
    }

    public function destroy(Request $request, string $registro, int $id): JsonResponse|RedirectResponse
    {
        [$definicion, $modelo, $empresaId] = $this->resolver($request, $registro, $id);
        $nombre = RegistroBorrado::nombre($definicion, $modelo);
        $esperado = RegistroBorrado::confirmacion($definicion, $modelo);

        if ($this->normalizar(Entrada::texto($request->input('confirmacion', ''))) !== $this->normalizar($esperado)) {
            return $this->rechazo($request, "Para confirmar escribe exactamente «{$esperado}».", 422);
        }

        try {
            $this->tenant->conEmpresa($empresaId, fn () => $this->borrado->eliminar($request->user(), $definicion, $modelo));
        } catch (\DomainException $e) {
            return $this->rechazo($request, $e->getMessage(), 409);
        }

        $destino = $definicion['volver'] instanceof \Closure ? ($definicion['volver'])($modelo) : route($definicion['volver']);
        $texto = ucfirst(RegistroBorrado::conArticulo($definicion))." «{$nombre}» se eliminó definitivamente.";
        session()->flash('ok', $texto);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'mensaje' => $texto, 'redirect' => $destino])
            : redirect()->to($destino);
    }

    /**
     * @return array{0: array<string, mixed>, 1: Model, 2: int}
     */
    private function resolver(Request $request, string $registro, int $id): array
    {
        $definicion = RegistroBorrado::de($registro);
        abort_if($definicion === null, 404);

        $permiso = $definicion['modulo'].'.borrar';
        Gate::authorize($permiso);

        /** @var User $actor */
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, fn () => RegistroBorrado::consulta($definicion, $empresaId)->find($id));
        abort_if($modelo === null, 404);

        if (! $actor->es_superadmin) {
            $definicion['alcance'] === 'sede'
                ? $this->exigirSede($actor, $permiso, $definicion, $modelo)
                : $this->exigirEmpresa($actor, $permiso, $modelo);
        }

        return [$definicion, $modelo, $empresaId];
    }

    /**
     * Registro de una sede: fuera de las sedes del permiso responde 404 (como
     * en las demás pantallas). Si además está en otras sedes (colaborador con
     * sedes adicionales), todas deben estar en su alcance.
     */
    private function exigirSede(User $actor, string $permiso, array $definicion, Model $modelo): void
    {
        abort_unless($this->autorizador->puede($actor, $permiso, $modelo), 404);

        $permitidas = $this->autorizador->sedesPermitidas($actor, $permiso);
        if ($permitidas !== null && isset($definicion['sedes_adicionales'])) {
            $columna = str_replace('_sede', '', $definicion['sedes_adicionales']).'_id';
            $otras = DB::table($definicion['sedes_adicionales'])->where($columna, $modelo->getKey())
                ->whereNotIn('sede_id', $permitidas)->exists();
            abort_if($otras, 403, 'También está en otras sedes: solo lo elimina quien tiene alcance de empresa.');
        }
    }

    /**
     * Catálogo de toda la empresa: alcance de empresa, o "Solo los propios" si él lo dio de alta.
     */
    private function exigirEmpresa(User $actor, string $permiso, Model $modelo): void
    {
        $propio = $this->autorizador->soloPropios($actor, $permiso)
            && (int) ($modelo->getAttributes()['creado_por'] ?? 0) === (int) $actor->id;

        abort_unless(
            $propio || $this->autorizador->alcanceDeEmpresa($actor, $permiso),
            403,
            'Es de toda la empresa: eliminarlo definitivamente requiere el permiso con alcance de empresa.',
        );
    }

    /**
     * Qué ofrecer en lugar de eliminar.
     *
     * @return array<string, mixed>|null
     */
    private function baja(array $definicion, Model $modelo, User $actor): ?array
    {
        $activo = $modelo->getAttributes()['activo'] ?? null;
        $la = $definicion['tipo'][1] === 'f' ? 'dada' : 'dado';
        if ($activo !== null && ! (bool) $activo) {
            return ['texto' => "Ya está {$la} de baja: así puede quedarse; su historial se conserva."];
        }

        $baja = $definicion['baja'] ?? null;
        if (is_string($baja)) {
            return ['texto' => $baja];
        }
        if (! is_array($baja) || ! Route::has($baja['ruta']) || ! $actor->can($baja['permiso'])) {
            return null;
        }

        return [
            'url' => route($baja['ruta'], $modelo->getKey()),
            'metodo' => $baja['metodo'],
            'campos' => $baja['campos'],
        ];
    }

    private function rechazo(Request $request, string $mensaje, int $estado): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => false, 'mensaje' => $mensaje], $estado)
            : back()->with('error', $mensaje);
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto)));
    }
}
