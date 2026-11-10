<?php

namespace App\Http\Controllers\Cuenta;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Suscripcion;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\ModulosEfectivos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * «Empresas → Tu suscripción»: el Administrador del cliente pide renovar o
 * ampliar su plan. Se registra como una solicitud del Catálogo comercial (con
 * el desglose calculado y congelado por el servidor) ligada a su empresa; al
 * marcarla pagada se aplica sola. Funciona aunque la empresa esté en solo
 * consulta (es la forma de salir de ella).
 */
class MiSuscripcionController extends Controller
{
    /**
     * Enciende o apaga un módulo que el plan incluye apagado de fábrica
     * (Asistencia en Corporativo, decisión 13). Apagarlo no borra datos.
     */
    public function modulos(Request $request, ModulosEfectivos $modulos, Autorizador $autorizador, AdministradorRoles $auditoria): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor->es_superadmin || $actor->empresa_id === null, 404);
        Gate::authorize('empresas.editar');
        abort_unless($autorizador->alcanceDeEmpresa($actor, 'empresas.editar'), 403, 'Solo quien administra los datos de la empresa puede cambiar lo que usa de su plan.');
        $suscripcion = Suscripcion::where('empresa_id', $actor->empresa_id)->firstOrFail();

        $detalle = $modulos->detalle($suscripcion);
        $encendibles = Modulo::query()->whereIn('id', $detalle['encendibles'])->pluck('nombre', 'clave');
        $datos = $request->validate([
            'modulo' => ['required', 'string', Rule::in($encendibles->keys()->all())],
            'encendido' => ['nullable', 'boolean'],
        ], ['modulo.in' => 'Ese módulo no viene incluido en tu plan.']);
        $clave = $datos['modulo'];
        $encendido = (bool) ($datos['encendido'] ?? false);
        $antes = ModulosEfectivos::encendidosPor((int) $actor->empresa_id);
        $modulos->encender($suscripcion, $clave, $encendido);
        $auditoria->auditar($actor, 'empresas.modulos_encendidos', Empresa::findOrFail($actor->empresa_id),
            ['modulos_encendidos' => $antes], ['modulos_encendidos' => ModulosEfectivos::encendidosPor((int) $actor->empresa_id)]);

        $nombre = $encendibles[$clave];
        $menu = Modulo::where('clave', $clave)->with('menu:id,nombre')->first()?->menu?->nombre;

        return redirect()->to(route('empresas.index').'#tu-suscripcion')->with('ok', $encendido
            ? "{$nombre} quedó encendido. Ábrelo en el menú ".($menu ? "{$menu} → " : '')."{$nombre} y revisa en la Matriz de permisos qué roles lo usan."
            : "{$nombre} quedó apagado: deja de verse en el menú; lo registrado se conserva y vuelve a verse si lo enciendes.");
    }

    public function solicitar(Request $request, CatalogoPublico $catalogo, Autorizador $autorizador): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor->es_superadmin || $actor->empresa_id === null, 404);
        Gate::authorize('empresas.editar');
        abort_unless($autorizador->alcanceDeEmpresa($actor, 'empresas.editar'), 403, 'Solo quien administra los datos de la empresa puede pedir cambios a la suscripción.');
        $suscripcion = Suscripcion::where('empresa_id', $actor->empresa_id)->firstOrFail();

        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['renovacion', 'ampliacion'])],
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'sedes' => ['required', 'integer', 'min:1', 'max:999'],
            'periodicidad' => ['required', Rule::in(array_keys(Suscripcion::PERIODICIDADES))],
            'rubro_id' => ['nullable', 'integer', Rule::exists('rubros', 'id')->where('activo', true)],
            'paquetes' => ['nullable', 'array', 'max:20'],
            'paquetes.*' => ['integer', 'min:1'],
            'codigo_promocion' => ['nullable', 'string', 'max:40'],
            'mensaje' => ['nullable', 'string', 'max:1000'],
        ], ['sedes.required' => 'Indica cuántas sedes necesitas.'], ['sedes' => 'sedes', 'codigo_promocion' => 'código de promoción']);

        $empresa = Empresa::findOrFail($actor->empresa_id);
        [$solicitud] = $catalogo->registrar([
            'tipo' => $datos['tipo'],
            'nombre' => $actor->name,
            'empresa' => $empresa->nombre_comercial,
            'correo' => (string) $actor->email,
            'sedes' => (int) $datos['sedes'],
            'periodicidad' => $datos['periodicidad'],
            'plan_id' => isset($datos['plan_id']) ? (int) $datos['plan_id'] : $suscripcion->plan_id,
            'paquetes' => array_values(array_unique(array_map('intval', $datos['paquetes'] ?? []))),
            'codigo_promocion' => $datos['codigo_promocion'] ?? null,
            'mensaje' => trim((string) ($datos['mensaje'] ?? '')) ?: null,
            'empresa_id' => $empresa->id,
            // C-05: giro que indica (si es otro, el Super Administrador lo revisa con el cliente; no se cambia solo)
            'rubro_id' => isset($datos['rubro_id']) ? (int) $datos['rubro_id'] : $empresa->rubro_id,
        ], (string) $request->ip());

        return redirect()->to(route('empresas.index').'#tu-suscripcion')->with('ok',
            "Solicitud {$solicitud->folio} guardada: te contactaremos para confirmar el pago. En cuanto se registre, tu suscripción se actualiza sola y te avisamos por la campana.");
    }
}
