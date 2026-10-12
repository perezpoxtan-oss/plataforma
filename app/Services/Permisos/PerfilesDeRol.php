<?php

namespace App\Services\Permisos;

use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\User;
use App\Support\Menu\ConstructorMenu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Ayudas para crear y configurar roles sin tocar números ni la tabla
 * completa de permisos (ADR-0016, criterio 7 «lo complejo queda en avanzado»):
 *
 *  - nivelPara(): el nivel jerárquico a partir de «debajo de / arriba de» otro rol;
 *  - copiar(): los permisos de otro rol o de un perfil (plantilla de la
 *    plataforma), solo lo que quien lo hace puede otorgar;
 *  - menus(): los menús que verá una persona con ese conjunto de permisos.
 *
 * Las reglas contra escalamiento siguen en AdministradorRoles: aquí solo se
 * arma la lista de permisos que después se guarda con sincronizarPermisos().
 */
class PerfilesDeRol
{
    /** Espacio que se deja al poner un rol al final (o al principio) de la escala. */
    public const SALTO = 10;

    public const MAXIMO = 999;

    public function __construct(
        private readonly AdministradorRoles $roles,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * Nivel para un rol que va «debajo de» o «arriba de» $referencia, a la
     * mitad entre ese rol y su vecino (p. ej. debajo de 30 con el siguiente en
     * 40 → 35). Si no hay lugar entre los dos, lo dice.
     *
     * @param  Collection<int, Rol>  $roles  roles de la misma empresa (o plantillas)
     *
     * @throws \DomainException
     */
    public function nivelPara(Collection $roles, string $relacion, Rol $referencia, int $nivelPropio, ?int $excluirId = null): int
    {
        $niveles = $roles->reject(fn (Rol $r) => $excluirId !== null && (int) $r->id === $excluirId)
            ->pluck('nivel_jerarquia')->map(fn ($n) => (int) $n)->sort()->values();
        $nivel = (int) $referencia->nivel_jerarquia;

        if ($relacion === 'debajo') {
            $siguiente = $niveles->first(fn (int $n) => $n > $nivel);
            if ($siguiente === null) {
                $propuesto = min(self::MAXIMO, $nivel + self::SALTO);

                return $propuesto > $nivel ? $propuesto : throw new \DomainException("No hay lugar debajo de «{$referencia->nombre}»: es el último nivel posible.");
            }
            if ($siguiente - $nivel >= 2) {
                return intdiv($nivel + $siguiente, 2);
            }
            $vecino = $roles->firstWhere('nivel_jerarquia', $siguiente);

            throw new \DomainException("No hay lugar entre «{$referencia->nombre}» y «".($vecino?->nombre ?? $siguiente).'»: elige otra posición o escribe el número en «Avanzado».');
        }

        // Arriba de: entre el rol anterior (o tu propio nivel) y la referencia
        $anterior = $niveles->filter(fn (int $n) => $n < $nivel)->last();
        $limite = max($anterior ?? 0, $nivelPropio);
        if ($nivel - $limite >= 2) {
            return $anterior === null && $nivelPropio === 0 && $nivel > self::SALTO ? $nivel - self::SALTO : intdiv($limite + $nivel, 2);
        }

        throw new \DomainException($limite === $nivelPropio && $nivelPropio > 0
            ? "No puedes poner un rol arriba de «{$referencia->nombre}»: quedaría a tu nivel o por encima de ti."
            : "No hay lugar arriba de «{$referencia->nombre}»: elige otra posición o escribe el número en «Avanzado».");
    }

    /**
     * Copia a $destino los permisos de $origen (otro rol o un perfil). Quien no
     * es Super Administrador solo otorga lo que tiene, con su alcance como
     * máximo; lo que el rol ya tenía y él no puede tocar se conserva.
     *
     * @return array{copiados: int, omitidos: int, conservados: int}
     */
    public function copiar(User $actor, Rol $origen, Rol $destino): array
    {
        $fuente = $this->clavesDe($origen);
        $antes = $this->clavesDe($destino);
        $propios = $actor->es_superadmin ? null : $this->autorizador->permisosEfectivos($actor);

        $nuevos = [];
        $omitidos = 0;
        foreach ($fuente as $clave => $alcance) {
            if ($propios === null) {
                $nuevos[$clave] = $alcance;

                continue;
            }
            $mio = $propios[$clave] ?? null;
            if ($mio === null) {
                $omitidos++;

                continue;
            }
            $nuevos[$clave] = $mio->alcance->cubre($alcance) ? $alcance : $mio->alcance;
        }

        // Lo que el rol ya tenía y quien copia no puede quitar ni cambiar se queda igual
        $conservados = 0;
        if ($propios !== null) {
            foreach ($antes as $clave => $alcance) {
                $mio = $propios[$clave] ?? null;
                if ($mio === null || ! $mio->alcance->cubre($alcance)) {
                    $conservados += (int) (($nuevos[$clave] ?? null) !== $alcance);
                    $nuevos[$clave] = $alcance;
                }
            }
        }

        $this->roles->sincronizarPermisos($actor, $destino, $nuevos);

        return ['copiados' => count($nuevos) - $conservados, 'omitidos' => $omitidos, 'conservados' => $conservados];
    }

    /**
     * Perfiles para el asistente «¿Qué hace este rol?»: las plantillas de la
     * plataforma (lo que recibe cada empresa nueva), con lo que puede hacer.
     *
     * @return Collection<int, array{id: int, nombre: string, descripcion: ?string, menus: list<string>, total: int}>
     */
    public function perfiles(?int $empresaId): Collection
    {
        $plantillas = Rol::plantillas()->where('activo', true)->orderBy('nivel_jerarquia')->get();

        return $plantillas->map(function (Rol $p) use ($empresaId) {
            $claves = array_keys($this->clavesDe($p));
            $menus = collect($this->menus($claves, $empresaId))->map(fn ($m) => $m['nombre'])->all();

            return ['id' => (int) $p->id, 'nombre' => $p->nombre, 'descripcion' => $p->descripcion, 'menus' => $menus, 'total' => count($claves)];
        })->values();
    }

    /**
     * Menús que vería una persona con estos permisos ("modulo.accion"): un
     * módulo aparece si tiene pantalla, la empresa lo tiene contratado y el
     * permiso incluye «ver» (como ConstructorMenu).
     *
     * @param  list<string>  $claves
     * @return list<array{clave: string, nombre: string, icono: ?string, secciones: array<string, list<string>>}>
     */
    public function menus(array $claves, ?int $empresaId): array
    {
        $ver = array_flip(array_filter($claves, fn ($c) => str_ends_with($c, '.ver')));
        $resultado = [];
        foreach ($this->entradas($empresaId) as $e) {
            $personal = $e['clave'] === 'entrevistas' && in_array('candidatos.evaluar', $claves, true);
            if (! $personal && ! isset($ver[$e['clave'].'.ver'])) {
                continue;
            }
            $resultado[$e['menu_clave']] ??= ['clave' => $e['menu_clave'], 'nombre' => $e['menu'], 'icono' => $e['icono'], 'secciones' => []];
            $resultado[$e['menu_clave']]['secciones'][$e['seccion']][] = $e['nombre'];
        }

        return array_values($resultado);
    }

    /**
     * Módulo => [menú, sección, nombre] para la vista previa en vivo de la matriz.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function mapaMenus(?int $empresaId): array
    {
        $mapa = [];
        foreach ($this->entradas($empresaId) as $e) {
            $mapa[$e['clave']] = [$e['menu'], $e['seccion'], $e['nombre']];
        }

        return $mapa;
    }

    /**
     * Todos los renglones de menú posibles para la empresa, en orden.
     *
     * @return list<array{clave: string, menu_clave: string, menu: string, icono: ?string, seccion: string, nombre: string}>
     */
    private function entradas(?int $empresaId): array
    {
        $contratados = $empresaId === null ? null : array_flip(DB::table('empresa_modulos')
            ->where('empresa_id', $empresaId)->where('activo', true)->pluck('modulo_id')->all());
        $nombresEmpresa = $empresaId === null ? [] : DB::table('empresa_modulos')
            ->where('empresa_id', $empresaId)->whereNotNull('nombre_visible')->pluck('nombre_visible', 'modulo_id')->all();
        $terminologia = $empresaId === null ? [] : (Empresa::with('rubro')->find($empresaId)?->rubro?->terminologia ?? []);

        $modulos = Modulo::query()->whereNotNull('menu_id')->where('activo', true)->orderBy('orden_menu')
            ->get(['id', 'menu_id', 'seccion_menu', 'nombre_menu', 'clave', 'nombre', 'ruta', 'padre_id'])->groupBy('menu_id');

        $entradas = [];
        foreach (Menu::where('activo', true)->orderBy('orden')->get() as $menu) {
            foreach ($modulos->get($menu->id, collect()) as $m) {
                if ($m->ruta === null || ! Route::has($m->ruta)) {
                    continue;
                }
                if ($contratados !== null && (! isset($contratados[$m->id]) || ($m->padre_id !== null && ! isset($contratados[$m->padre_id])))) {
                    continue;
                }
                $entradas[] = ['clave' => $m->clave, 'menu_clave' => $menu->clave, 'menu' => $menu->nombre, 'icono' => $menu->icono,
                    'seccion' => (string) ($m->seccion_menu ?? ''),
                    'nombre' => $nombresEmpresa[$m->id] ?? ($m->clave === 'sedes' ? ($terminologia['sedes'] ?? null) : null) ?? $m->nombre_menu ?? $m->nombre];
            }
            // Pantalla personal de Recursos Humanos (ConstructorMenu::conPantallasPersonales)
            if ($menu->clave === 'recursos_humanos' && Route::has('entrevistas.index')) {
                $entradas[] = ['clave' => 'entrevistas', 'menu_clave' => $menu->clave, 'menu' => $menu->nombre, 'icono' => $menu->icono,
                    'seccion' => ConstructorMenu::SECCION_PERSONAL, 'nombre' => 'Mis entrevistas'];
            }
        }

        return $entradas;
    }

    /**
     * @return array<string, Alcance>
     */
    public function clavesDe(Rol $rol): array
    {
        return RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')
            ->where('rol_id', $rol->id)
            ->get()
            ->mapWithKeys(fn (RolPermiso $p) => [$p->moduloAccion->clave() => $p->alcance])
            ->all();
    }
}
