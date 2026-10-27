<?php

namespace App\Support;

use App\Models\Modulo;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\EstadoCliente;
use Illuminate\Http\Request;

/**
 * «No viene en tu plan» (plan de correcciones C-03, A-02): cuando el motor
 * de permisos niega una acción porque el MÓDULO no está contratado (y no
 * porque al rol le falte el permiso), la página 403 lo dice y lleva a «Tu
 * suscripción», en lugar de mandar a la Matriz de permisos, que no lo arregla.
 *
 * El Gate anota la última negación de la petición; la página 403 la lee.
 */
final class NoVieneEnTuPlan
{
    private const ATRIBUTO = 'permiso_negado';

    /** Anota por qué se negó «modulo.accion» (solo si fue por el plan). */
    public static function anotar(User $usuario, string $habilidad): void
    {
        if ($usuario->es_superadmin || $usuario->empresa_id === null || ! app()->bound('request')) {
            return;
        }
        $modulo = substr($habilidad, 0, (int) strrpos($habilidad, '.'));
        $accion = substr($habilidad, (int) strrpos($habilidad, '.') + 1);
        $autorizador = app(Autorizador::class);
        $empresaId = (int) $usuario->empresa_id;
        $requerido = DependenciasModulos::ACCIONES_CON_MODULO[$habilidad] ?? null;
        $motivo = null;
        $accionLectura = in_array($accion, Autorizador::ACCIONES_LECTURA, true) || in_array($habilidad, Autorizador::PERMITIDAS_EN_SOLO_CONSULTA, true);
        if (! $accionLectura && $autorizador->empresaEnSoloConsulta($usuario)) {
            // A-17: en solo consulta se ocultan las acciones de alta y edición
            $motivo = ['tipo' => 'suscripcion', 'modulo' => $modulo];
        } elseif ($requerido !== null && ! $autorizador->moduloActivo($empresaId, $requerido)) {
            $motivo = ['tipo' => 'plan', 'modulo' => $requerido];
        } elseif ($modulo !== '' && $autorizador->esModulo($modulo) && ! $autorizador->moduloActivo($empresaId, $modulo)) {
            $motivo = $autorizador->moduloEnSoloLectura($empresaId, $modulo) && ! in_array($accion, Autorizador::ACCIONES_LECTURA, true)
                ? ['tipo' => 'solo_lectura', 'modulo' => $modulo]
                : ['tipo' => 'plan', 'modulo' => $modulo];
        }
        request()->attributes->set(self::ATRIBUTO, $motivo);
    }

    /**
     * Datos para la página 403 (null = es falta de permiso del rol).
     *
     * @return array{titulo: string, mensaje: string, url: ?string, boton: ?string}|null
     */
    public static function datos(Request $request): ?array
    {
        $motivo = $request->attributes->get(self::ATRIBUTO);
        $usuario = $request->user();
        if (! is_array($motivo) || $usuario === null) {
            return null;
        }
        if ($motivo['tipo'] === 'suscripcion') {
            $s = app(EstadoCliente::class)->suscripcionDe($usuario);

            return ['titulo' => 'Tu empresa está en solo consulta',
                'mensaje' => $s !== null ? app(EstadoCliente::class)->mensajeSoloConsulta($s) : 'Tu empresa tiene acceso de solo consulta: puedes ver y exportar, pero no registrar ni cambiar nada.',
                'url' => $usuario->can('empresas.ver') ? route('empresas.index').'#tu-suscripcion' : null, 'boton' => 'Ver mi suscripción'];
        }
        $nombre = Modulo::where('clave', $motivo['modulo'])->value('nombre') ?? $motivo['modulo'];
        $suscripcion = Suscripcion::with('plan:id,nombre')->where('empresa_id', $usuario->empresa_id)->first();
        $plan = $suscripcion?->plan?->nombre;
        $url = $suscripcion !== null && $usuario->can('empresas.ver') ? route('empresas.index').'#tu-suscripcion' : null;

        if ($motivo['tipo'] === 'solo_lectura') {
            return ['titulo' => 'Solo consulta: tu plan ya no incluye este módulo',
                'mensaje' => "Puedes ver, exportar e imprimir lo que ya está en {$nombre}, pero no registrar ni cambiar nada. Para volver a usarlo, cambia de plan o agrega el complemento.",
                'url' => $url, 'boton' => $url ? 'Ver mi suscripción' : null];
        }

        return ['titulo' => 'Esto no viene en tu plan'.($plan ? " ({$plan})" : ''),
            'mensaje' => $suscripcion !== null
                ? "«{$nombre}» no está incluido en tu plan. No es un problema de permisos de tu rol: para usarlo, cambia de plan o agrega el complemento que lo trae."
                    .($url ? '' : ' Pide a tu administrador que lo revise en «Tu suscripción».')
                : "«{$nombre}» no está activo para tu empresa. Pide al administrador de la plataforma que lo active.",
            'url' => $url, 'boton' => $url ? 'Ver mi suscripción' : null];
    }
}
