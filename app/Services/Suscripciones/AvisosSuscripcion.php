<?php

namespace App\Services\Suscripciones;

use App\Mail\AvisoSuscripcion;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Autorizador;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;

/**
 * Avisos de las suscripciones por campana y correo (criterio 1):
 *  - al Administrador del cliente: quienes pueden editar los datos de su
 *    empresa (permiso empresas.editar), con enlace a «Empresas → Tu suscripción»;
 *  - al Super Administrador: aviso de la plataforma (sin empresa) con enlace
 *    a la ficha del cliente.
 * Si el correo de la plataforma no está configurado, solo queda la campana.
 */
class AvisosSuscripcion
{
    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly CorreoPlataforma $correo,
        private readonly Autorizador $autorizador,
        private readonly Tenant $tenant,
    ) {}

    /** @return Collection<int, User> */
    public function administradores(int $empresaId): Collection
    {
        return User::query()->where('empresa_id', $empresaId)->where('activo', true)->where('es_superadmin', false)->get()
            ->filter(fn (User $u) => $this->autorizador->puede($u, 'empresas.editar'))->values();
    }

    /** @return Collection<int, User> */
    public function superadmins(): Collection
    {
        return User::query()->where('es_superadmin', true)->where('activo', true)->get(['id', 'email', 'name']);
    }

    public function alCliente(Suscripcion $s, string $titulo, string $texto): void
    {
        $admins = $this->administradores((int) $s->empresa_id);
        if ($admins->isEmpty()) {
            return;
        }
        $url = route('empresas.index').'#tu-suscripcion';
        $this->tenant->conEmpresa((int) $s->empresa_id, fn () => $this->centro->avisar((int) $s->empresa_id, $admins->pluck('id'), 'suscripcion_aviso', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'referencia_tipo' => 'suscripcion', 'referencia_id' => $s->id,
        ]));
        $correos = $admins->pluck('email')->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
        if ($correos !== []) {
            $this->correo->enviar($correos, new AvisoSuscripcion($titulo, $texto, $url, 'Ver mi suscripción'));
        }
    }

    public function alSuperadmin(Suscripcion $s, string $tipo, string $titulo, string $texto): void
    {
        $superadmins = $this->superadmins();
        if ($superadmins->isEmpty()) {
            return;
        }
        $url = route('catalogo-comercial.clientes.show', $s->id);
        $this->tenant->conEmpresa(null, fn () => $this->centro->avisarPlataforma($superadmins->pluck('id'), $tipo, [
            'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'referencia_tipo' => $tipo === 'cliente_por_revisar' ? 'suscripcion_revision' : 'suscripcion', 'referencia_id' => $s->id,
        ]));
        $correos = $superadmins->pluck('email')->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
        if ($correos !== []) {
            $this->correo->enviar($correos, new AvisoSuscripcion($titulo, $texto, $url, 'Abrir la ficha del cliente'));
        }
    }
}
