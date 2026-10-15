<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\CatalogoPublico;
use App\Support\Entrada;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Página pública de planes (SIN sesión): «/» para visitantes cuando está
 * publicada, /planes, /contratar y el aviso de privacidad.
 *
 * - No publicada: 404 (igual que una dirección inventada), salvo para el
 *   Super Administrador, que la ve con la franja «Vista previa».
 * - Formulario: token CSRF, campo trampa para robots, límite de envíos por
 *   equipo (ruta), validación en el servidor y aviso de privacidad aceptado.
 * - El precio lo calcula el servidor; del navegador solo se toma lo elegido.
 * - Sin JavaScript en línea: public/js/landing-calculo.js y public/js/landing.js.
 */
class PlanesController extends Controller
{
    /** Campo trampa: invisible para personas; si llega con algo, es un robot. */
    public const TRAMPA = 'sitio_web';

    public function __construct(
        private readonly CatalogoPublico $catalogo,
        private readonly AjustesComerciales $ajustes,
    ) {}

    /** «/» para visitantes (la llama el middleware PortadaPublica). */
    public function inicio(): Response
    {
        return $this->pagina('publico.planes.inicio', $this->catalogo->pagina());
    }

    public function planes(Request $request): Response
    {
        $this->visibleOFallar($request);

        return $this->pagina('publico.planes.planes', $this->catalogo->pagina());
    }

    public function contratar(Request $request): Response
    {
        $this->visibleOFallar($request);
        $datos = $this->catalogo->pagina();
        [$plan, , $calculo] = $this->eleccion($request, $datos);

        return $this->pagina('publico.planes.contratar', $datos + [
            'eleccion' => [
                'plan_id' => $plan?->id,
                'sedes' => $calculo['sedes'],
                'paquetes' => collect($calculo['renglones'])->where('tipo', 'paquete')->pluck('id')->all(),
            ],
            'calculo' => $calculo,
        ], conFormulario: true);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $this->visibleOFallar($request);
        // Robot (llenó el campo trampa): se le contesta igual que a una persona, sin guardar nada
        if (trim(Entrada::texto($request->input(self::TRAMPA))) !== '') {
            return redirect()->route('planes.gracias')->with('solicitud_enviada', ['folio' => null]);
        }

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'empresa' => ['nullable', 'string', 'max:150'],
            'correo' => ['required', 'email:rfc', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\s-]{7,20}$/'],
            'sedes' => ['required', 'integer', 'min:1', 'max:999'],
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'paquetes' => ['nullable', 'array', 'max:20'],
            'paquetes.*' => ['integer', 'min:1'],
            'mensaje' => ['nullable', 'string', 'max:1000'],
            'acepta_privacidad' => ['accepted'],
        ], [
            'nombre.required' => 'Escribe tu nombre.',
            'nombre.min' => 'Escribe tu nombre completo.',
            'correo.required' => 'Escribe tu correo para poder contestarte.',
            'correo.email' => 'Revisa tu correo: parece incompleto.',
            'telefono.regex' => 'El teléfono solo puede tener números, espacios, +, guiones y paréntesis.',
            'sedes.required' => 'Indica cuántas sedes quieres cubrir.',
            'sedes.min' => 'Indica al menos 1 sede.',
            'sedes.max' => 'Para más de 999 sedes, escríbenos en el mensaje.',
            'acepta_privacidad.accepted' => 'Para enviar tu solicitud acepta el aviso de privacidad.',
        ], [
            'empresa' => 'empresa', 'telefono' => 'teléfono', 'mensaje' => 'mensaje',
        ]);

        $limpio = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;
        [$solicitud, $resultado] = $this->catalogo->registrar([
            'nombre' => trim($datos['nombre']),
            'empresa' => $limpio($datos['empresa'] ?? null),
            'correo' => trim($datos['correo']),
            'telefono' => $limpio($datos['telefono'] ?? null),
            'sedes' => (int) $datos['sedes'],
            'mensaje' => $limpio($datos['mensaje'] ?? null),
            'plan_id' => isset($datos['plan_id']) ? (int) $datos['plan_id'] : null,
            'paquetes' => array_values(array_unique(array_map('intval', $datos['paquetes'] ?? []))),
        ], (string) $request->ip());

        // Pasarela en línea (más adelante): enviar al interesado a pagar
        if ($resultado->estado === 'redirigir' && $resultado->urlPago !== null) {
            return redirect()->away($resultado->urlPago);
        }

        return redirect()->route('planes.gracias')->with('solicitud_enviada', [
            'folio' => $solicitud->folio, 'mensaje' => $resultado->mensaje, 'correo' => $solicitud->correo,
        ]);
    }

    public function gracias(Request $request): Response|RedirectResponse
    {
        $this->visibleOFallar($request);
        $enviada = session('solicitud_enviada');
        if (! is_array($enviada)) {
            return redirect()->route('planes.contratar');
        }

        return $this->pagina('publico.planes.gracias', ['ajustes' => $this->ajustes->todos(), 'enviada' => $enviada]);
    }

    public function privacidad(Request $request): Response
    {
        $this->visibleOFallar($request);
        $texto = trim((string) $this->ajustes->get('aviso_privacidad'));
        abort_if($texto === '', 404);

        return $this->pagina('publico.planes.privacidad', ['ajustes' => $this->ajustes->todos(), 'texto' => $texto]);
    }

    // ------------------------------------------------------------------ Ayudas

    /** Publicada, o el Super Administrador revisándola. */
    public static function puedeVer(Request $request): bool
    {
        return app(AjustesComerciales::class)->landingPublicada() || ($request->user()?->es_superadmin ?? false);
    }

    private function visibleOFallar(Request $request): void
    {
        abort_unless(self::puedeVer($request), 404);
    }

    /**
     * Lo elegido en la dirección (?plan=ID|slug&sedes=N&paquetes[]=ID), calculado por el servidor.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: ?Plan, 1: mixed, 2: array<string, mixed>}
     */
    private function eleccion(Request $request, array $datos): array
    {
        $planTexto = mb_substr(Entrada::texto($request->query('plan')), 0, 60);
        $plan = $datos['planes']->first(fn ($p) => (string) $p->id === $planTexto || $p->slug === $planTexto)
            ?? $datos['planes']->firstWhere('destacado', true) ?? $datos['planes']->first();
        $sedes = max(1, min(999, (int) Entrada::texto($request->query('sedes'), '1')));
        $paquetes = collect((array) $request->query('paquetes', []))->filter(fn ($v) => is_scalar($v))->map(fn ($v) => (int) $v)->filter()->unique()->take(20)->values()->all();
        // Los que no se pueden sumar al plan se quitan de la sugerencia (la persona puede elegir otro plan)
        [$p, $q, $calculo] = $this->catalogo->cotizar($plan?->id, $sedes, $paquetes);
        if ($calculo['incompatibles'] !== []) {
            $q = $q->reject(fn ($x) => in_array($x->nombre, $calculo['incompatibles'], true))->values();
            [$p, $q, $calculo] = $this->catalogo->cotizar($plan?->id, $sedes, $q->pluck('id')->all());
        }

        return [$p, $q, $calculo];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function pagina(string $vista, array $datos, bool $conFormulario = false): Response
    {
        $respuesta = response(view($vista, $datos + ['vistaPrevia' => ! $this->ajustes->landingPublicada()]))
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($conFormulario || ! $this->ajustes->landingPublicada()) {
            $respuesta->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $conFormulario ? $respuesta->header('Cache-Control', 'no-store, private') : $respuesta;
    }
}
