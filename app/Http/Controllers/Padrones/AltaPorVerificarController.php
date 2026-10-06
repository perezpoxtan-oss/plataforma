<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Padrones\AdministradorProveedores;
use App\Services\Padrones\AltasPorVerificar;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Altas pendientes de verificar (ADR-0006): sugerencias "¿Es alguno de
 * estos?" para la caseta y las acciones de quien verifica el padrón
 * (Aceptar, Rechazar, Unir con existente) en Vehículos, Empresas externas y
 * Personas. Las reglas viven en App\Services\Padrones\AltasPorVerificar.
 *
 * Las acciones responden JSON al diálogo de la ficha (los errores se
 * muestran dentro del diálogo) y, sin JavaScript, redirigen a la lista.
 */
class AltaPorVerificarController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AltasPorVerificar $altas,
    ) {}

    /**
     * GET ?padron=vehiculos|proveedores|personas y lo capturado (placas,
     * nombre, nombre_completo, folio_identificacion o q), u ?de={id} para
     * comparar un alta pendiente (diálogo Verificar). origen = pantalla de
     * Operación que pregunta (accesos, transporte...).
     */
    public function parecidos(Request $request): JsonResponse
    {
        $actor = $request->user();
        $padron = Entrada::texto($request->query('padron'));
        abort_unless(isset(AltasPorVerificar::PADRONES[$padron]), 403);
        $permiso = AltasPorVerificar::PADRONES[$padron]['permiso'];
        $origen = $this->altas->origenPermitido($actor, $padron, $request->query('origen'));
        $verifica = $request->filled('de');
        abort_unless($verifica ? $actor->can($permiso.'.editar') : ($actor->can($permiso.'.ver') || $origen !== null), 403);

        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }

        $resultados = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $padron, $permiso, $origen, $verifica) {
            $excepto = null;
            if ($verifica) {
                $registro = $this->buscarVerificable($actor, $padron, (int) Entrada::texto($request->query('de')));
                $datos = $registro->only(match ($padron) {
                    'vehiculos' => ['placas'],
                    'proveedores' => ['nombre'],
                    default => ['nombre_completo', 'folio_identificacion'],
                });
                $excepto = $registro->id;
            } else {
                $texto = mb_substr(Entrada::texto($request->query('q')), 0, 150);
                $datos = [
                    'placas' => mb_substr(Entrada::texto($request->query('placas', $texto)), 0, 25),
                    'nombre' => mb_substr(Entrada::texto($request->query('nombre', $texto)), 0, 150),
                    'nombre_completo' => mb_substr(Entrada::texto($request->query('nombre_completo', $texto)), 0, 150),
                    'folio_identificacion' => mb_substr(Entrada::texto($request->query('folio_identificacion')), 0, 40),
                ];
            }
            // Empresas externas: solo las de las sedes donde opera quien pregunta
            $sedes = $padron === 'proveedores'
                ? app(AdministradorProveedores::class)->sedes($actor, $actor->can($permiso.'.ver') || $origen === null ? $permiso.'.ver' : AltasPorVerificar::ORIGENES[$origen]['permiso'])
                : null;

            $lista = $this->altas->parecidos($padron, $datos, $sedes, $excepto, $verifica ? 8 : 5);

            // Para unir solo sirven los activos y ya verificados
            return $verifica ? array_values(array_filter($lista, fn ($r) => $r['usable'] && $r['verificacion'] === 'verificado')) : $lista;
        });

        return response()->json(['resultados' => $resultados]);
    }

    public function aceptarVehiculo(Request $request, int $vehiculo): JsonResponse|RedirectResponse
    {
        return $this->aceptar($request, 'vehiculos', $vehiculo);
    }

    public function rechazarVehiculo(Request $request, int $vehiculo): JsonResponse|RedirectResponse
    {
        return $this->rechazar($request, 'vehiculos', $vehiculo);
    }

    public function unirVehiculo(Request $request, int $vehiculo): JsonResponse|RedirectResponse
    {
        return $this->unir($request, 'vehiculos', $vehiculo);
    }

    public function aceptarProveedor(Request $request, int $proveedor): JsonResponse|RedirectResponse
    {
        return $this->aceptar($request, 'proveedores', $proveedor);
    }

    public function rechazarProveedor(Request $request, int $proveedor): JsonResponse|RedirectResponse
    {
        return $this->rechazar($request, 'proveedores', $proveedor);
    }

    public function unirProveedor(Request $request, int $proveedor): JsonResponse|RedirectResponse
    {
        return $this->unir($request, 'proveedores', $proveedor);
    }

    public function aceptarPersona(Request $request, int $persona): JsonResponse|RedirectResponse
    {
        return $this->aceptar($request, 'personas', $persona);
    }

    public function rechazarPersona(Request $request, int $persona): JsonResponse|RedirectResponse
    {
        return $this->rechazar($request, 'personas', $persona);
    }

    public function unirPersona(Request $request, int $persona): JsonResponse|RedirectResponse
    {
        return $this->unir($request, 'personas', $persona);
    }

    // ------------------------------------------------------------------ Acciones

    private function aceptar(Request $request, string $padron, int $id): JsonResponse|RedirectResponse
    {
        [$actor, $empresaId] = $this->autorizar($request, $padron);

        $registro = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $padron, $id) {
            $registro = $this->buscarVerificable($actor, $padron, $id);
            $datos = [];
            foreach (AltasPorVerificar::CAMPOS_AL_ACEPTAR[$padron] as $campo) {
                if ($request->has($campo)) {
                    $datos[$campo] = Entrada::texto($request->input($campo));
                }
            }

            return $this->altas->aceptar($actor, $padron, $registro, $datos);
        });

        return $this->responder($request, $padron, $registro, '«'.$this->altas->titulo($padron, $registro).'» quedó '.$this->genero($padron, 'verificado').' en el padrón. Gracias por revisarlo.');
    }

    private function rechazar(Request $request, string $padron, int $id): JsonResponse|RedirectResponse
    {
        [$actor, $empresaId] = $this->autorizar($request, $padron);

        $registro = $this->tenant->conEmpresa($empresaId, fn () => $this->altas->rechazar(
            $actor, $padron, $this->buscarVerificable($actor, $padron, $id), $request->input('motivo_rechazo'),
        ));

        return $this->responder($request, $padron, $registro, '«'.$this->altas->titulo($padron, $registro).'» quedó '.$this->genero($padron, 'rechazado').': se conserva como historia y ya no se puede usar en la operación.', 'aviso');
    }

    private function unir(Request $request, string $padron, int $id): JsonResponse|RedirectResponse
    {
        [$actor, $empresaId] = $this->autorizar($request, $padron);

        [$provisional, $destino] = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $padron, $id) {
            // Primero el registro (de otra empresa o fuera de su alcance: 404), luego la captura
            $provisional = $this->buscarVerificable($actor, $padron, $id);
            $request->validate(['destino_id' => ['required', 'integer']], ['destino_id.required' => 'Elige con cuál registro se une.', 'destino_id.integer' => 'Elige con cuál registro se une.']);
            $destino = $this->buscarVisible($actor, $padron, (int) $request->input('destino_id'));

            return [$provisional, $this->altas->unir($actor, $padron, $provisional, $destino)];
        });

        return $this->responder($request, $padron, $destino, '«'.$this->altas->titulo($padron, $provisional).'» se unió con «'.$this->altas->titulo($padron, $destino).'»: todo lo registrado pasó al registro correcto.');
    }

    // ------------------------------------------------------------------- Ayudas

    /**
     * @return array{0: User, 1: int}
     */
    private function autorizar(Request $request, string $padron): array
    {
        Gate::authorize(AltasPorVerificar::PADRONES[$padron]['permiso'].'.editar');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return [$request->user(), $empresaId];
    }

    /**
     * Alta del padrón dentro del alcance de verificación del actor; de otra
     * empresa o fuera de su alcance responde 404.
     */
    private function buscarVerificable(User $actor, string $padron, int $id): Model
    {
        $modelo = AltasPorVerificar::PADRONES[$padron]['modelo'];
        $registro = $this->altas->limitarVerificacion($modelo::query()->whereKey($id), $actor, $padron)->first();
        abort_if($registro === null, 404);

        return $registro;
    }

    /**
     * Registro destino de una unión: visible para el actor en el padrón.
     */
    private function buscarVisible(User $actor, string $padron, int $id): Model
    {
        $registro = $padron === 'proveedores'
            ? app(AdministradorProveedores::class)->consulta($actor, 'proveedores.ver')->find($id)
            : AltasPorVerificar::PADRONES[$padron]['modelo']::query()->find($id);
        abort_if($registro === null, 404);

        return $registro;
    }

    /** "verificado" / "verificada" según el padrón (vehículo / empresa externa, persona). */
    private function genero(string $padron, string $palabra): string
    {
        return AltasPorVerificar::PADRONES[$padron]['genero'] === 'f' ? substr($palabra, 0, -1).'a' : $palabra;
    }

    private function responder(Request $request, string $padron, Model $registro, string $mensaje, string $tipo = 'ok'): JsonResponse|RedirectResponse
    {
        $d = AltasPorVerificar::PADRONES[$padron];
        $destino = route($d['ruta']).'#'.$d['ancla'].$registro->id;
        session()->flash($tipo, $mensaje);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'mensaje' => $mensaje, 'ir' => $destino]);
        }

        return redirect()->to($destino);
    }
}
