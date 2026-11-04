<?php

namespace App\Services\Relojes\Relojes;

use App\Models\EquipoAcceso;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use App\Support\AjustesEquiposAcceso;
use Illuminate\Support\Collection;

/**
 * Decisión 10: un equipo de acceso que lleva más de X horas sin llamar a la
 * plataforma (Configuración → Equipos de acceso; de fábrica 2 h). Avisa al
 * jefe de seguridad de la sede y, al doble, a su suplente. Corre en proceso
 * con el motor (la campana y la corrida del despliegue): sin cron nuevo.
 */
class EquiposSinConexion extends Reloj
{
    public const REFERENCIA = 'equipo_sin_conexion';

    public function clave(): string
    {
        return 'equipos_sin_conexion';
    }

    public function nombre(): string
    {
        return 'Equipos de acceso sin conexión';
    }

    public function suplenteOmision(): string
    {
        return 'quien aprueba ingresos en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['accesos'];
    }

    public function permiso(): ?string
    {
        return 'accesos.aprobar';
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Configuración → Caseta y accesos → Equipos de acceso';
    }

    public function umbralesPropios($empresa): ?array
    {
        $minutos = AjustesEquiposAcceso::de($empresa)['sin_conexion_horas'] * 60;

        return [$minutos, $minutos * 2];
    }

    public function renglonDef(): ?array
    {
        return ['equipos_sin_conexion', 'Equipos de acceso sin conexión', 'bi-router', route('equipos-acceso.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 equipo de acceso está sin conexión', '%d equipos de acceso están sin conexión', 'Revisa que tenga corriente e internet; mientras tanto, la caseta registra a mano.', 'Ver equipos'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $horas = AjustesEquiposAcceso::de(app(MotorRelojes::class)->empresa($empresaId))['sin_conexion_horas'];
        $limite = now()->subHours($horas);

        return EquipoAcceso::where('activo', true)
            ->where(fn ($q) => $q->where('ultimo_contacto_at', '<', $limite)->orWhere(fn ($w) => $w->whereNull('ultimo_contacto_at')->where('created_at', '<', $limite)))
            ->orderBy('id')->limit(200)->get(['id', 'sede_id', 'nombre', 'puerta', 'ultimo_contacto_at', 'created_at'])
            ->map(fn (EquipoAcceso $e) => new Espera(self::REFERENCIA, (int) $e->id, $e->ultimo_contacto_at ?? $e->created_at ?? now(),
                'Sin conexión: '.$e->nombre.($e->puerta ? ' ('.$e->puerta.')' : ''),
                route('equipos-acceso.index').'#equipo-'.$e->id, (int) $e->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return app(Escalamiento::class)->jefesDeSeguridad((int) $empresaId, (int) $e->sedeId);
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return [$e->titulo, 'No llama a la plataforma desde hace '.MotorRelojes::duracion($minutos).'. Revisa que tenga corriente e internet; mientras tanto, registren en caseta.'];
    }
}
