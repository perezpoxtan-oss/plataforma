<?php

namespace App\Services\Comercial\Pagos;

use App\Contracts\PasarelaPago;
use App\Mail\SolicitudCompraRecibida;
use App\Models\SolicitudCompra;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\Calculadora;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;

/**
 * Pasarela «Manual»: NO cobra. Deja la solicitud como «nueva» y avisa al
 * Super Administrador por la campana y por correo (y a los correos de
 * Ajustes → «Correos que reciben las solicitudes»). Al interesado le llega un
 * acuse con su folio y su desglose. El pago se acuerda por fuera y el Super
 * Administrador lo anota en la solicitud (estado «Pagada» y referencia).
 */
class PasarelaManual implements PasarelaPago
{
    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly CorreoPlataforma $correo,
        private readonly AjustesComerciales $ajustes,
        private readonly Tenant $tenant,
    ) {}

    public function clave(): string
    {
        return 'manual';
    }

    public function nombre(): string
    {
        return 'Manual (sin cobro en línea)';
    }

    public function crearCobro(SolicitudCompra $solicitud): ResultadoCobro
    {
        $solicitud->forceFill(['pasarela' => $this->clave()])->save();
        $this->avisar($solicitud);

        return new ResultadoCobro('pendiente', mensaje: $solicitud->esPrueba()
            ? 'Recibimos tu solicitud de prueba gratis. La revisamos y te enviamos tu acceso por correo.'
            : 'Recibimos tu solicitud. Te contactaremos para confirmar tu plan y la forma de pago.');
    }

    /** La Manual no recibe avisos de pago. */
    public function confirmar(Request $request): ?SolicitudCompra
    {
        return null;
    }

    private function avisar(SolicitudCompra $s): void
    {
        $superadmins = User::query()->where('es_superadmin', true)->where('activo', true)->get(['id', 'email']);
        $resumen = $this->resumen($s);

        // Campana: avisos de la plataforma (sin empresa)
        $quien = $s->nombre.($s->empresa ? " ({$s->empresa})" : '');
        $titulo = match ($s->tipo) {
            'prueba' => "Prueba gratis por aprobar {$s->folio}: {$quien}",
            'renovacion', 'ampliacion' => "{$s->tipoTexto()}: {$quien}, solicitud {$s->folio}",
            default => "Nueva solicitud {$s->folio}: {$quien}",
        };
        $this->tenant->conEmpresa(null, fn () => $this->centro->avisarPlataforma($superadmins->pluck('id'), 'solicitud_compra', [
            'titulo' => $titulo,
            'texto' => $resumen,
            'url' => route('catalogo-comercial.solicitudes.show', $s->id),
            'referencia_tipo' => 'solicitud_compra',
            'referencia_id' => $s->id,
        ]));

        // Correo: Super Administradores + los correos de Ajustes (sin repetir)
        $destinos = collect($superadmins->pluck('email'))->merge($this->ajustes->get('correos_solicitudes'))
            ->filter(fn ($c) => is_string($c) && filter_var($c, FILTER_VALIDATE_EMAIL))->map(fn ($c) => mb_strtolower($c))->unique()->values()->all();
        if ($destinos !== []) {
            $this->correo->enviar($destinos, new SolicitudCompraRecibida($s, $resumen, route('catalogo-comercial.solicitudes.show', $s->id), interno: true));
        }
        // Acuse al interesado (criterio 1: quien lo pidió sabe que llegó)
        $this->correo->enviar($s->correo, new SolicitudCompraRecibida($s, $resumen, null, interno: false));
    }

    private function resumen(SolicitudCompra $s): string
    {
        $plan = collect($s->detalle['renglones'] ?? [])->firstWhere('tipo', 'plan');
        $partes = [($plan['nombre'] ?? 'Sin plan').', '.$s->sedes.' '.($s->sedes === 1 ? 'sede' : 'sedes')];
        if ($s->esPrueba()) {
            $partes[] = 'prueba gratis de '.$this->ajustes->get('dias_prueba').' días';
        }
        if ($s->requiere_cotizacion) {
            $partes[] = 'pide cotización';
        } elseif ($s->periodo_total > 0) {
            $partes[] = ($s->esPrueba() ? 'después ' : '').Calculadora::formato(Calculadora::centavos($s->periodo_total), $s->moneda).' '.$s->periodoTexto().' con IVA';
        }
        if ($s->codigo_promocion) {
            $partes[] = 'código '.$s->codigo_promocion;
        }
        if ($s->unico_total > 0) {
            $partes[] = Calculadora::formato(Calculadora::centavos($s->unico_total), $s->moneda).' de pago único con IVA';
        }

        return implode(' · ', $partes);
    }
}
