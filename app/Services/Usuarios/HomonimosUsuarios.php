<?php

namespace App\Services\Usuarios;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use Illuminate\Support\Collection;

/**
 * Homónimos (personas con el mismo nombre completo) al dar de alta o editar
 * un usuario. Petición del responsable: se crearon dos usuarios «Daniela
 * Canul May» (uno vinculado al colaborador #1008 y otro no) sin ningún aviso.
 *
 *  - El nombre se compara sin importar mayúsculas, acentos ni espacios dobles.
 *  - Un homónimo NO impide guardar (hay personas con el mismo nombre), pero
 *    se pide confirmar «Sí, es otra persona con el mismo nombre».
 *  - Si existe un colaborador con ese nombre que aún no tiene cuenta, se
 *    sugiere vincularlo.
 *  - El nombre de usuario y el correo siguen siendo únicos (eso no cambia).
 */
class HomonimosUsuarios
{
    private const SIN_ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u',
    ];

    /** Vocal sin acento => con acento (para acotar en la base de datos por la inicial). */
    private const CON_ACENTO = ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú'];

    public function __construct(private readonly AdministradorColaboradores $colaboradores) {}

    /**
     * Clave para comparar nombres: minúsculas, sin acentos (la ñ se conserva)
     * y con un solo espacio entre palabras. «  DANIELA  Canúl may » → «daniela canul may».
     */
    public static function clave(?string $nombre): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', strtr((string) $nombre, self::SIN_ACENTOS))));
    }

    /**
     * Usuarios de la empresa con el mismo nombre (sin el Super Administrador
     * y sin la cuenta que se está editando).
     *
     * @return Collection<int, User>
     */
    public function usuarios(int $empresaId, string $nombre, ?int $excluir = null): Collection
    {
        $buscado = self::clave($nombre);
        if (mb_strlen($buscado) < 3) {
            return collect();
        }

        return User::query()
            ->where('empresa_id', $empresaId)->where('es_superadmin', false)
            ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))
            ->with(['roles' => fn ($q) => $q->select('roles.id', 'roles.nombre'), 'colaborador:id,num_empleado'])
            ->orderBy('id')
            ->get(['id', 'name', 'username', 'activo', 'colaborador_id'])
            ->filter(fn (User $u) => self::clave($u->name) === $buscado)
            ->values();
    }

    /**
     * ¿Hay que pedir «Sí, es otra persona con el mismo nombre»? Al dar de
     * alta, si existe otro usuario con ese nombre; al editar, solo si el
     * nombre cambió y el nuevo ya lo tiene otro usuario.
     */
    public function requiereConfirmacion(int $empresaId, string $nombre, ?User $usuario = null): bool
    {
        if ($usuario !== null && self::clave($usuario->name) === self::clave($nombre)) {
            return false;
        }

        return $this->usuarios($empresaId, $nombre, $usuario?->id)->isNotEmpty();
    }

    /**
     * Colaboradores activos con ese nombre (completo, o nombre y apellido
     * paterno) que todavía no tienen cuenta de usuario, dentro de las sedes
     * indicadas (null = todas). Se debe llamar dentro del tenant de la empresa.
     *
     * @param  list<int>|null  $sedes
     * @return list<array<string, mixed>>
     */
    public function colaboradoresSinCuenta(string $nombre, ?array $sedes, ?int $excluirUsuario = null): array
    {
        $buscado = self::clave($nombre);
        $palabras = array_values(array_filter(explode(' ', $buscado)));
        if (count($palabras) < 2) {
            return [];
        }

        return Colaborador::query()
            ->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')
            ->when($sedes !== null, fn ($q) => $q->enSedes($sedes))
            ->whereDoesntHave('usuario', fn ($u) => $u->when($excluirUsuario !== null, fn ($x) => $x->where('users.id', '!=', $excluirUsuario)))
            // Se acota por la inicial de alguna palabra en el apellido paterno; la comparación exacta va en PHP
            ->where(function ($q) use ($palabras) {
                foreach ($palabras as $palabra) {
                    $inicial = mb_substr($palabra, 0, 1);
                    foreach (array_unique([$inicial, self::CON_ACENTO[$inicial] ?? $inicial]) as $letra) {
                        $q->orWhereRaw('LOWER(colaboradores.apellido_paterno) LIKE ?', [$letra.'%']);
                    }
                }
            })
            ->limit(500)
            ->get(['colaboradores.id', 'colaboradores.num_empleado', 'colaboradores.nombre', 'colaboradores.apellido_paterno', 'colaboradores.apellido_materno',
                'colaboradores.sede_id', 'colaboradores.puesto_id', 'colaboradores.departamento_id', 'colaboradores.provisional'])
            ->filter(fn (Colaborador $c) => self::clave($c->nombreCompleto()) === $buscado || self::clave($c->nombre.' '.$c->apellido_paterno) === $buscado)
            ->take(5)
            ->map(fn (Colaborador $c) => $this->colaboradores->resumen($c))
            ->values()->all();
    }

    /**
     * «Ya existe un usuario con ese nombre: @dcanul (Agente). ¿Es la misma persona?»
     *
     * @param  Collection<int, User>  $visibles  los que el actor puede ver
     */
    public static function mensaje(Collection $visibles, int $otros): string
    {
        $cuentas = $visibles->map(fn (User $u) => '@'.$u->username.' ('.($u->roles->first()?->nombre ?? 'sin rol').')'
            .($u->activo ? '' : ', inactivo'))->all();
        if ($otros > 0) {
            $cuentas[] = $otros === 1 ? 'otro usuario de una sede que no tienes a cargo' : "{$otros} usuarios de sedes que no tienes a cargo";
        }
        $plural = count($cuentas) > 1 || $otros > 1;

        return ($plural ? 'Ya existen usuarios con ese nombre: ' : 'Ya existe un usuario con ese nombre: ').implode(', ', $cuentas).'. ¿Es la misma persona?';
    }
}
