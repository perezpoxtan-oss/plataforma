<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Promocion;
use App\Models\SolicitudCompra;
use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Suscripciones\Aprovisionamiento;
use App\Services\Suscripciones\Limites;
use App\Services\Suscripciones\ModulosEfectivos;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Suscripciones de los clientes (Catálogo comercial → Clientes, Por revisar y
 * Promociones). Solo el Super Administrador: mismas acciones del módulo de
 * plataforma catalogo_comercial (ver / editar). Todo cambio pasa por
 * Aprovisionamiento (un solo punto de entrada) y queda en el historial y en
 * la Bitácora de auditoría.
 */
class SuscripcionesController extends Controller
{
    public function __construct(
        private readonly Aprovisionamiento $aprovisionamiento,
        private readonly AjustesComerciales $ajustes,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Ficha del cliente

    public function show(int $id, Limites $limites, ModulosEfectivos $modulos): View
    {
        Gate::authorize('catalogo_comercial.ver');
        $s = Suscripcion::with(['empresa', 'plan', 'paquetes:id,nombre', 'promocion', 'solicitud:id,folio,nombre,correo,telefono',
            'excepciones' => fn ($q) => $q->with(['modulo:id,nombre', 'autor:id,name'])->orderByDesc('activo')->orderByDesc('id'),
            'historial' => fn ($q) => $q->with('usuario:id,name')->limit(60), 'revisor:id,name'])->findOrFail($id);

        return view('administracion.catalogo-comercial.cliente', [
            's' => $s,
            'estadoHoy' => $s->estadoEfectivo(null, $this->ajustes->diasPorVencer()),
            'limites' => $limites->de((int) $s->empresa_id),
            'modulosActivos' => $s->empresa !== null ? $modulos->nombresActivos($s->empresa) : collect(),
            'planes' => Plan::query()->where('activo', true)->orderBy('orden')->get(['id', 'nombre', 'cotizar', 'sedes_minimas']),
            'paquetes' => Paquete::query()->where('activo', true)->where('modalidad', Paquete::SUSCRIPCION)->orderBy('orden')->get(['id', 'nombre']),
            'modulos' => Modulo::query()->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->whereNull('padre_id')
                ->orderBy('area_id')->orderBy('orden')->get(['id', 'nombre', 'clave']),
            'promociones' => Promocion::activas()->orderBy('nombre')->get(['id', 'nombre', 'codigo', 'tipo', 'valor', 'meses']),
            'puedeEditar' => auth()->user()->can('catalogo_comercial.editar'),
            'ajustes' => $this->ajustes->todos(),
        ]);
    }

    public function plan(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::findOrFail($id);
        $datos = $request->validate([
            'plan_id' => ['nullable', 'integer', Rule::exists('planes', 'id')],
            'sedes' => ['required', 'integer', 'min:1', 'max:999'],
            'periodicidad' => ['required', Rule::in(array_keys(Suscripcion::PERIODICIDADES))],
            'paquetes' => ['nullable', 'array', 'max:20'],
            'paquetes.*' => ['integer', Rule::exists('paquetes', 'id')],
            'motivo' => ['nullable', 'string', 'max:200'],
        ], ['sedes.required' => 'Escribe cuántas sedes contrata.'], ['sedes' => 'sedes', 'periodicidad' => 'periodicidad']);

        try {
            $this->aprovisionamiento->cambiarPlan($s, isset($datos['plan_id']) ? Plan::find($datos['plan_id']) : null, (int) $datos['sedes'],
                array_map('intval', $datos['paquetes'] ?? []), $datos['periodicidad'], $request->user(), trim((string) ($datos['motivo'] ?? '')));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->aFicha($s, 'Plan guardado: el precio se recalculó con los precios de hoy y los módulos del cliente ya están al día (lo quitado solo se oculta; sus datos se conservan).');
    }

    /** «Recibir pago»: renovar un periodo (sale de la prueba, la gracia o solo consulta). */
    public function pago(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::findOrFail($id);
        $datos = $request->validate(['referencia' => ['nullable', 'string', 'max:100']]);
        $s = $this->aprovisionamiento->registrarPago($s, $request->user(), trim((string) ($datos['referencia'] ?? '')) ?: null);

        return $this->aFicha($s, 'Pago recibido: la suscripción vence el '.$s->vence_en->format('d/m/Y').'. Le avisamos al cliente.');
    }

    public function revision(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::findOrFail($id);
        $datos = $request->validate(['notas' => ['nullable', 'string', 'max:5000']]);
        $this->aprovisionamiento->marcarRevisado($s, $request->user(), trim((string) ($datos['notas'] ?? '')) ?: null);

        $siguiente = Suscripcion::whereNull('revisado_en')->whereKeyNot($s->id)->orderBy('created_at')->first();

        return redirect()->route('catalogo-comercial.clientes.show', $s->id)->with('ok', 'Cliente guardado como revisado.')
            ->with('siguientes', $siguiente !== null
                ? [['Revisar el siguiente cliente nuevo', route('catalogo-comercial.clientes.show', $siguiente->id), 'bi-person-check']]
                : [['Volver a los clientes', route('catalogo-comercial.index', ['pestana' => 'clientes']), 'bi-people']]);
    }

    /** Dar de baja (solo consulta a mano) o Reactivar. */
    public function estado(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::findOrFail($id);
        $activo = $request->boolean('activo');
        $datos = $request->validate(['motivo' => [$activo ? 'nullable' : 'required', 'string', 'max:200']],
            ['motivo.required' => 'Escribe el motivo: lo verá el historial del cliente.']);
        try {
            $s = $activo
                ? $this->aprovisionamiento->reactivar($s, $request->user(), trim((string) ($datos['motivo'] ?? '')))
                : $this->aprovisionamiento->suspender($s, $request->user(), trim((string) $datos['motivo']));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->aFicha($s, $activo ? 'Suscripción reactivada: el cliente vuelve a registrar normalmente.'
            : 'Suscripción dada de baja: el cliente queda en solo consulta (ve y exporta, no registra). Puedes reactivarla con el mismo botón.');
    }

    public function excepcion(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::findOrFail($id);
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(SuscripcionExcepcion::TIPOS))],
            'cantidad' => ['nullable', 'integer', 'min:-999', 'max:9999'],
            'modulo_id' => ['nullable', 'integer'],
            'motivo' => ['required', 'string', 'max:255'],
        ], ['motivo.required' => 'Escribe el motivo de la excepción (por ejemplo, lo que se acordó con el cliente).'], ['cantidad' => 'cantidad']);
        try {
            $e = $this->aprovisionamiento->agregarExcepcion($s, $request->user(), $datos['tipo'], isset($datos['cantidad']) ? (int) $datos['cantidad'] : null,
                isset($datos['modulo_id']) ? (int) $datos['modulo_id'] : null, trim($datos['motivo']));
        } catch (DomainException $ex) {
            return back()->withInput()->with('error', $ex->getMessage());
        }

        return $this->aFicha($s, 'Excepción guardada: '.$e->texto().'.');
    }

    public function bajaExcepcion(Request $request, int $id, int $excepcion): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::findOrFail($id);
        $e = SuscripcionExcepcion::where('suscripcion_id', $s->id)->findOrFail($excepcion);
        $this->aprovisionamiento->quitarExcepcion($s, $e, $request->user());

        return $this->aFicha($s, 'Excepción dada de baja: '.$e->texto().'. Queda en el historial.');
    }

    public function promocion(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $s = Suscripcion::with('plan')->findOrFail($id);
        $datos = $request->validate(['promocion_id' => ['required', 'integer', Rule::exists('promociones', 'id')]], ['promocion_id.required' => 'Elige la promoción.']);
        try {
            $s = $this->aprovisionamiento->aplicarPromocion($s, Promocion::findOrFail($datos['promocion_id']), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->aFicha($s, 'Promoción guardada: el precio pactado ya la refleja.');
    }

    // ------------------------------------------------------------------ Prueba gratis por aprobar

    public function aprobarPrueba(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $solicitud = SolicitudCompra::findOrFail($id);
        try {
            $s = $this->aprovisionamiento->activarPrueba($solicitud, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->aFicha($s, "Prueba aprobada: «{$s->empresa?->nombre_comercial}» ya puede entrar hasta el ".$s->vence_en->format('d/m/Y')
            .'. Al Administrador le llegó el correo de bienvenida para crear su contraseña.');
    }

    // ------------------------------------------------------------------ Promociones

    public function crearPromocion(): View
    {
        Gate::authorize('catalogo_comercial.editar');

        return $this->formularioPromocion(new Promocion);
    }

    public function editarPromocion(int $id): View
    {
        Gate::authorize('catalogo_comercial.editar');

        return $this->formularioPromocion(Promocion::findOrFail($id));
    }

    public function guardarPromocion(Request $request, ?int $id = null): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $p = $id === null ? new Promocion : Promocion::findOrFail($id);
        $request->merge(['codigo' => Promocion::normalizarCodigo($request->input('codigo')) ?: null]);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'codigo' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promociones', 'codigo')->ignore($p->id)],
            'tipo' => ['required', Rule::in(array_keys(Promocion::TIPOS))],
            'valor' => ['required', 'numeric', 'min:0.01', $request->input('tipo') === Promocion::PORCENTAJE ? 'max:100' : 'max:9999999'],
            'periodicidad' => ['required', Rule::in(array_keys(Promocion::PERIODICIDADES))],
            'meses' => ['nullable', 'integer', 'min:1', 'max:120'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'usos_maximos' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'planes' => ['nullable', 'array', 'max:50'],
            'planes.*' => ['integer', Rule::exists('planes', 'id')],
        ], [
            'nombre.required' => 'Escribe el nombre de la promoción.',
            'codigo.regex' => 'El código solo lleva letras, números, guion y guion bajo (por ejemplo BIENVENIDA15).',
            'codigo.unique' => 'Ya existe otra promoción con ese código.',
            'valor.max' => 'Un porcentaje no puede pasar de 100.',
            'vigente_hasta.after_or_equal' => 'La fecha final no puede ser antes de la inicial.',
        ], ['valor' => 'descuento', 'meses' => 'primeros meses', 'usos_maximos' => 'límite de usos', 'vigente_desde' => 'desde', 'vigente_hasta' => 'hasta']);
        $nueva = ! $p->exists;
        $antes = $nueva ? null : $p->only(['nombre', 'codigo', 'tipo', 'valor', 'periodicidad', 'meses', 'vigente_desde', 'vigente_hasta', 'usos_maximos']);
        $p->fill(collect($datos)->except('planes')->all())->save();
        $p->planes()->sync(array_map('intval', $datos['planes'] ?? []));
        $this->auditoria->auditar($request->user(), $nueva ? 'catalogo_comercial.promocion_creada' : 'catalogo_comercial.promocion_actualizada', $p, $antes,
            $p->only(['nombre', 'codigo', 'tipo', 'valor', 'periodicidad', 'meses', 'usos_maximos']) + ['planes' => $p->planes()->pluck('nombre')->all()]);

        return redirect()->route('catalogo-comercial.index', ['pestana' => 'promociones'])
            ->with('ok', "Promoción «{$p->nombre}» guardada".($p->codigo ? ": el código {$p->codigo} ya se puede usar en la página de planes." : '; sin código, solo se aplica a mano desde la ficha de un cliente.'))
            ->with('siguientes', $nueva ? [['Dar de alta otra promoción', route('catalogo-comercial.promociones.crear'), 'bi-plus-circle']] : []);
    }

    public function estadoPromocion(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('catalogo_comercial.editar');
        $p = Promocion::findOrFail($id);
        $activo = $request->boolean('activo');
        $p->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($request->user(), $activo ? 'catalogo_comercial.promocion_reactivada' : 'catalogo_comercial.promocion_dada_de_baja', $p, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('catalogo-comercial.index', ['pestana' => 'promociones'])
            ->with('ok', "Promoción «{$p->nombre}» ".($activo ? 'reactivada.' : 'dada de baja: el código ya no se acepta. Los clientes que ya la tienen la conservan.'));
    }

    private function formularioPromocion(Promocion $p): View
    {
        return view('administracion.catalogo-comercial.promocion', [
            'p' => $p,
            'planes' => Plan::query()->orderBy('orden')->get(['id', 'nombre', 'activo']),
            'marcados' => $p->exists ? $p->planes()->pluck('planes.id')->all() : [],
        ]);
    }

    private function aFicha(Suscripcion $s, string $mensaje): RedirectResponse
    {
        return redirect()->route('catalogo-comercial.clientes.show', $s->id)->with('ok', $mensaje);
    }
}
