<?php

namespace App\Services\Relojes;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use App\Services\Recepcion\Destinatarios;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Un reloj del motor «Reloj y suplente» (C-22): qué espera en un módulo,
 * a quién se le recuerda (quien debe responder) y a quién pasa cuando tarda
 * (el suplente). El motor (MotorRelojes) pone el resto: los umbrales de la
 * empresa, el color, la memoria de lo ya avisado y la campana.
 *
 * Suplente de fábrica: quien tiene el mismo permiso para resolverlo en toda
 * la empresa; si nadie más lo tiene, quien administra la configuración de la
 * empresa; y si tampoco hay otra persona, los mismos responsables reciben el
 * aviso que ya no se puede silenciar. En Configuración se puede elegir un rol.
 */
abstract class Reloj
{
    /** Clave estable (Configuración y memoria del motor). */
    abstract public function clave(): string;

    /** Nombre para Configuración y los avisos: «Altas por verificar». */
    abstract public function nombre(): string;

    /** A quién pasa de fábrica (texto para Configuración). */
    abstract public function suplenteOmision(): string;

    /**
     * Todo lo que espera ahora en la empresa (dentro de su Tenant), a lo mucho 500.
     *
     * @return Collection<int, Espera>
     */
    abstract public function esperas(?int $empresaId): Collection;

    /**
     * Quien debe responder (recibe el recordatorio).
     *
     * @return Collection<int, User>
     */
    abstract public function responsables(Espera $e, ?int $empresaId): Collection;

    /** Módulos del catálogo: el reloj corre si la empresa tiene alguno (vacío = siempre). */
    public function modulos(): array
    {
        return [];
    }

    /** Permiso que resuelve el asunto (suplente de fábrica y roles que se pueden elegir). */
    public function permiso(): ?string
    {
        return null;
    }

    /** ¿Es de la plataforma (Super Administrador, sin empresa)? */
    public function plataforma(): bool
    {
        return false;
    }

    /** false: el módulo ya manda su propio recordatorio y el motor solo pasa al suplente. */
    public function recuerda(): bool
    {
        return true;
    }

    /** Horas para repetir el recordatorio mientras siga esperando (null = una sola vez). */
    public function repetirRecordatorioHoras(): ?int
    {
        return null;
    }

    /**
     * Umbrales propios del módulo en minutos [ámbar y recordatorio, rojo y suplente]
     * (null = los generales de «Reloj y suplente»).
     *
     * @return array{0: int, 1: int}|null
     */
    public function umbralesPropios(?Empresa $empresa): ?array
    {
        return null;
    }

    /** Dónde se cambian sus horas cuando son propias del módulo. */
    public function dondeSeConfigura(): ?string
    {
        return null;
    }

    /**
     * Umbrales de un asunto en particular (p. ej. por categoría del ticket).
     *
     * @param  array{0: int, 1: int}  $base
     * @return array{0: int, 1: int}
     */
    public function umbralesDe(Espera $e, array $base): array
    {
        return $base;
    }

    /**
     * Suplentes: el rol elegido en Configuración o, de fábrica, quien tiene
     * el permiso en toda la empresa (y si nadie más, quien administra la
     * configuración). Nunca los mismos que ya recibieron el recordatorio.
     *
     * @param  Collection<int, User>  $responsables
     * @return Collection<int, User>
     */
    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        $ya = $responsables->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($empresaId === null) {
            // Plataforma: todos los Super Administradores (el aviso al suplente no se puede silenciar)
            return User::where('es_superadmin', true)->where('activo', true)->get();
        }
        if ($rolId !== null && ($rol = Rol::where('empresa_id', $empresaId)->where('activo', true)->find($rolId)) !== null) {
            $deRol = User::where('empresa_id', $empresaId)->where('activo', true)
                ->whereIn('id', DB::table('usuario_roles')->where('rol_id', $rol->id)->select('user_id'))->get(); // el rol elegido, aunque ya tuvieran el recordatorio
            if ($deRol->isNotEmpty()) {
                return $deRol;
            }
        }
        $destinatarios = app(Destinatarios::class);
        if (($permiso = $this->permisoDe($e)) !== null) {
            $empresa = $destinatarios->conPermiso($empresaId, $permiso, null)->reject(fn (User $u) => in_array((int) $u->id, $ya, true))->values();
            if ($empresa->isNotEmpty()) {
                return $empresa;
            }
        }

        $administradores = $destinatarios->conPermiso($empresaId, 'configuracion.editar', null)->reject(fn (User $u) => in_array((int) $u->id, $ya, true))->values();

        // Si nadie más puede atenderlo, el aviso que no se puede silenciar llega a los mismos responsables
        return $administradores->isNotEmpty() ? $administradores : $responsables->values();
    }

    /**
     * Ya pasó al suplente: avisos propios del módulo (p. ej. a la caseta).
     *
     * @param  Collection<int, User>  $suplentes
     */
    public function alEscalar(Espera $e, ?int $empresaId, Collection $suplentes): void {}

    // ------------------------------------------------------ Mis pendientes

    /**
     * Renglón propio en Mis pendientes: [clave, título, ícono, ruta]. null = el
     * módulo ya tiene su renglón (MisPendientes le pone el color del motor).
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    public function renglonDef(): ?array
    {
        return null;
    }

    /**
     * ¿Solo cuenta en Mis pendientes lo que ya pasó del recordatorio? (la
     * permanencia o un equipo en reparación no esperan a nadie al empezar)
     */
    public function cuentaDesdeElRecordatorio(): bool
    {
        return false;
    }

    /** [frase en singular, en plural (%d), explicación, botón] para Inicio. */
    public function textosInicio(): ?array
    {
        return null;
    }

    /** Permiso con el que se atiende este asunto (por omisión el del reloj). */
    public function permisoDe(Espera $e): ?string
    {
        return $e->datos['permiso'] ?? $this->permiso();
    }

    /**
     * ¿Puede atenderlo esta persona si se lo delegan? (C-12: el aviso de quien
     * delegó pasa al último de su cadena que cumpla esto). De fábrica, lo
     * mismo que leToca(): el permiso del asunto con alcance en su sede.
     */
    public function puedeAtender(User $delegado, Espera $e): bool
    {
        return $this->leToca($delegado, $e);
    }

    /** ¿Le toca a esta persona? (para contarlo en su Mis pendientes) */
    public function leToca(User $actor, Espera $e): bool
    {
        if ($this->plataforma()) {
            return (bool) $actor->es_superadmin;
        }
        $permiso = $this->permisoDe($e);

        return $permiso !== null && app(Destinatarios::class)->alcanza($actor, $permiso, $e->sedeId);
    }

    /**
     * El renglón de Mis pendientes de esta persona: cuántos le tocan y el
     * color del que más lleva esperando.
     *
     * @return array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}|null
     */
    public function renglon(User $actor, ?int $empresaId): ?array
    {
        $def = $this->renglonDef();
        if ($def === null || ! $this->puedeVer($actor)) {
            return null;
        }
        $motor = app(MotorRelojes::class);
        $mias = $this->esperasDe($actor, $empresaId);
        if ($this->cuentaDesdeElRecordatorio()) {
            $mias = $mias->filter(fn (Espera $e) => $motor->nivel($this->clave(), $e->desde, $empresaId, $e) !== null)->values();
        }
        if ($mias->isEmpty()) {
            return null;
        }
        $niveles = $mias->map(fn (Espera $e) => $motor->nivel($this->clave(), $e->desde, $empresaId, $e))->filter();

        return ['clave' => $def[0], 'titulo' => $def[1], 'icono' => $def[2], 'total' => $mias->count(), 'url' => $mias->count() === 1 ? $mias->first()->url : $def[3],
            'nivel' => $niveles->contains('roja') ? 'roja' : ($niveles->contains('ambar') ? 'ambar' : null)];
    }

    /** Filtro barato antes de consultar: ¿puede llegarle algo de este reloj? */
    public function puedeVer(User $actor): bool
    {
        if ($this->plataforma()) {
            return (bool) $actor->es_superadmin;
        }
        $permiso = $this->permiso();

        return $permiso === null || $actor->can($permiso);
    }

    /**
     * Lo que le toca a esta persona.
     *
     * @return Collection<int, Espera>
     */
    public function esperasDe(User $actor, ?int $empresaId): Collection
    {
        $this->memoEsperas[$empresaId ?? 0] ??= $this->esperas($empresaId);

        return $this->memoEsperas[$empresaId ?? 0]->filter(fn (Espera $e) => $this->leToca($actor, $e))->values();
    }

    /** Color del que más lleva esperando de lo que le toca (para los renglones que ya existían). */
    public function nivelPara(User $actor, ?int $empresaId): ?string
    {
        if (! $this->puedeVer($actor)) {
            return null;
        }
        $motor = app(MotorRelojes::class);
        $niveles = $this->esperasDe($actor, $empresaId)->map(fn (Espera $e) => $motor->nivel($this->clave(), $e->desde, $empresaId, $e))->filter();

        return $niveles->contains('roja') ? 'roja' : ($niveles->contains('ambar') ? 'ambar' : null);
    }

    // ------------------------------------------------------------- Ayudas

    /** @var array<string, Collection<int, User>> */
    private array $conPermiso = [];

    /**
     * Usuarios activos con el permiso en esa sede (memorizado durante una corrida).
     *
     * @return Collection<int, User>
     */
    protected function conPermiso(?int $empresaId, string $permiso, ?int $sedeId): Collection
    {
        if ($empresaId === null) {
            return User::where('es_superadmin', true)->where('activo', true)->get();
        }

        return $this->conPermiso[$empresaId.':'.$permiso.':'.($sedeId ?? 'todas')] ??= app(Destinatarios::class)->conPermiso($empresaId, $permiso, $sedeId);
    }

    /** @var array<int, Collection<int, Espera>> lo que espera, memorizado para Mis pendientes (una consulta por petición) */
    private array $memoEsperas = [];

    /** Se olvida lo memorizado (al empezar cada corrida). */
    public function olvidar(): void
    {
        $this->conPermiso = [];
        $this->memoEsperas = [];
    }

    /** @return array{0: string, 1: string} [título, texto] */
    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return ['Recordatorio: '.$e->titulo, $this->nombre().' · lleva '.MotorRelojes::duracion($minutos).' esperando tu respuesta.'];
    }

    /** @return array{0: string, 1: string} [título, texto] */
    public function textoEscalado(Espera $e, int $minutos): array
    {
        return ['Pasa a ti: '.$e->titulo, $this->nombre().' · lleva '.MotorRelojes::duracion($minutos).' sin respuesta. Te llega como suplente: atiéndelo o dale seguimiento.'];
    }
}
