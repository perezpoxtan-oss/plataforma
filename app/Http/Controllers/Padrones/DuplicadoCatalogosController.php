<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Llave;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Services\Padrones\AvisoDuplicado;
use App\Services\Permisos\Autorizador;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Services\Usuarios\HomonimosUsuarios;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Ronda 7 (parte A): los últimos padrones pasan al aviso de duplicado único
 * (<input data-duplicado="URL"> + App\Services\Padrones\AvisoDuplicado; ver
 * docs/tecnico/avisos-duplicado.md): Llaves (nombre por sede), Departamentos,
 * Puestos, Turnos y Usuarios (usuario, correo y homónimos por nombre).
 *
 * Todas: GET ?campo=…&valor=…[&excluir=ID][&…], con la empresa de trabajo y
 * el permiso de crear o editar del módulo. Departamentos, Puestos y Turnos
 * son catálogos de toda la empresa: solo responde a quien los puede
 * modificar (alcance de empresa). El servidor vuelve a revisar al guardar.
 */
class DuplicadoCatalogosController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AvisoDuplicado $avisos,
        private readonly Autorizador $autorizador,
    ) {}

    // ----------------------------------------------------------------- Llaves

    /** Nombre (nomenclatura) único EN SU SEDE: igual = existe; igual sin guiones ni espacios = parecido. */
    public function llaves(Request $request): JsonResponse
    {
        return $this->responder($request, 'llaves', function (User $actor, string $valor, ?int $excluir) use ($request) {
            $nombre = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $valor)));
            $sedeId = ctype_digit(Entrada::texto($request->query('sede_id'))) ? (int) $request->query('sede_id') : null;
            $sedes = $this->sedesUnion($actor, ['llaves.crear', 'llaves.editar']);
            if (mb_strlen($nombre) < 2 || $sedeId === null || ($sedes !== null && ! in_array($sedeId, $sedes, true))) {
                return $this->avisos->nada();
            }
            $sede = Sede::find($sedeId);
            if ($sede === null) {
                return $this->avisos->nada();
            }
            $puedeReactivar = $actor->can('llaves.eliminar') && $this->enSedes($sedeId, $this->autorizador->sedesPermitidas($actor, 'llaves.eliminar'));
            $describir = fn (Llave $l) => $this->avisos->coincidencia($l->nomenclatura,
                implode(' · ', array_filter([$l->descripcion, $l->etiquetaTipo(), $l->activo ? null : 'Dada de baja'])),
                ! $l->activo, $puedeReactivar ? route('llaves.reactivar', $l->id) : null);

            $igual = Llave::where('sede_id', $sedeId)->where('nomenclatura', $nombre)->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
            if ($igual !== null) {
                return $this->avisos->existe($igual->activo
                    ? "Ya existe una llave «{$nombre}» en {$sede->nombre}. No se puede repetir en la misma sede."
                    : "Ya existe la llave «{$nombre}» en {$sede->nombre}, pero está dada de baja. ¿La reactivas en lugar de registrarla otra vez?", [$describir($igual)]);
            }

            $parecidas = Llave::where('sede_id', $sedeId)->whereRaw($this->sinSignos('nomenclatura').' = ?', [$this->avisos->clave($nombre)])
                ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->limit(3)->get();
            if ($parecidas->isEmpty()) {
                return $this->avisos->libre("Nombre disponible en {$sede->nombre}.");
            }

            return $this->avisos->parecido("Se parece a una llave que ya está en {$sede->nombre} (los guiones y espacios no cuentan). Revisa que no sea la misma:",
                $parecidas->map($describir)->all());
        });
    }

    // ------------------------------------------- Departamentos, Puestos y Turnos

    public function departamentos(Request $request): JsonResponse
    {
        return $this->catalogo($request, 'departamentos', Departamento::class, ['un departamento', 'el departamento', 'lo reactivas'],
            fn (Departamento $d) => $d->todas_las_sedes ? 'Todas las sedes' : $d->sedes->pluck('nombre')->join(', '), ['sedes:id,nombre']);
    }

    public function puestos(Request $request): JsonResponse
    {
        return $this->catalogo($request, 'puestos', Puesto::class, ['un puesto', 'el puesto', 'lo reactivas'],
            fn (Puesto $p) => $p->departamentos->pluck('nombre')->join(', ') ?: 'Sin departamento', ['departamentos:id,nombre']);
    }

    public function turnos(Request $request): JsonResponse
    {
        return $this->catalogo($request, 'turnos', Turno::class, ['un turno', 'el turno', 'lo reactivas'],
            fn (Turno $t) => $t->inicio().' a '.$t->fin());
    }

    // --------------------------------------------------------------- Usuarios

    /**
     * Usuarios: «username» y «email» son únicos en TODA la plataforma (existe);
     * «name» avisa de homónimos (parecido) y sugiere vincular al colaborador
     * con ese nombre que aún no tiene cuenta. Lo de otra empresa, de sedes que
     * no tiene a cargo o del Super Administrador se dice sin datos.
     */
    public function usuarios(Request $request): JsonResponse
    {
        return $this->responder($request, 'usuarios', function (User $actor, string $valor, ?int $excluir) use ($request) {
            $empresaId = (int) $this->empresa->id($actor);
            $administrador = app(AdministradorUsuarios::class);
            $visibles = fn () => $administrador->limitarAlcance(User::query()->where('users.empresa_id', $empresaId)->where('users.es_superadmin', false), $actor, 'usuarios.ver');
            // La cuenta que se edita debe estar a su alcance; si no, no se excluye nada
            $excluir = $excluir !== null && $visibles()->whereKey($excluir)->exists() ? $excluir : null;
            $puedeReactivar = $actor->can('usuarios.eliminar');
            $describir = fn (User $u) => $this->avisos->coincidencia('@'.$u->username,
                implode(' · ', array_filter([$u->name, $u->roles->first()?->nombre ?? 'Sin rol',
                    $u->colaborador?->num_empleado ? 'Colaborador #'.$u->colaborador->num_empleado : null, $u->activo ? null : 'Inactivo'])),
                ! $u->activo, $puedeReactivar ? route('usuarios.estado', $u->id) : null);

            $campo = Entrada::texto($request->query('campo'));
            if ($campo === 'username' || $campo === 'email') {
                $texto = mb_strtolower(trim($valor));
                if (($campo === 'username' && ! preg_match('/^[a-z0-9._-]{3,60}$/', $texto)) || ($campo === 'email' && ! str_contains($texto, '@'))) {
                    return $this->avisos->nada();
                }
                $etiqueta = $campo === 'username' ? 'nombre de usuario' : 'correo';
                $otro = User::query()->whereRaw("LOWER({$campo}) = ?", [$texto])->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
                if ($otro !== null) {
                    $mio = (int) $otro->empresa_id === $empresaId && ! $otro->es_superadmin && $visibles()->whereKey($otro->id)->exists();
                    if (! $mio) {
                        return $this->avisos->existe("Ese {$etiqueta} ya lo usa otra cuenta. Elige otro.");
                    }
                    $otro->load('roles:id,nombre', 'colaborador:id,num_empleado');

                    return $this->avisos->existe($otro->activo
                        ? "Ese {$etiqueta} ya lo usa la cuenta @{$otro->username}. No se puede repetir."
                        : "Ese {$etiqueta} ya lo usa la cuenta @{$otro->username}, que está inactiva. ¿La reactivas en lugar de crear otra?", [$describir($otro)]);
                }
                if ($campo === 'username') {
                    // «d.canul» y «dcanul» se confunden: solo aviso, dentro de la empresa
                    $clave = str_replace(['.', '_', '-'], '', $texto);
                    $parecidos = $visibles()->with('roles:id,nombre', 'colaborador:id,num_empleado')->whereRaw("REPLACE(REPLACE(REPLACE(LOWER(username), '.', ''), '_', ''), '-', '') = ?", [$clave])
                        ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->limit(3)->get();
                    if ($parecidos->isNotEmpty()) {
                        return $this->avisos->parecido('Se parece a una cuenta que ya existe (los puntos y guiones no cuentan). Revisa que no sea la misma persona:', $parecidos->map($describir)->all());
                    }
                }

                return $this->avisos->libre($campo === 'username' ? 'Nombre de usuario disponible.' : 'Correo disponible.');
            }

            // Nombre completo: homónimos (no impiden guardar, pero se confirma)
            $nombre = trim((string) preg_replace('/\s+/u', ' ', $valor));
            if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
                return $this->avisos->nada();
            }
            $homonimos = app(HomonimosUsuarios::class);
            $iguales = $homonimos->usuarios($empresaId, $nombre, $excluir);
            $idsVisibles = $iguales->isEmpty() ? [] : $visibles()->whereIn('users.id', $iguales->pluck('id'))->pluck('users.id')->all();
            $mios = $iguales->whereIn('id', $idsVisibles)->values();
            $otros = $iguales->count() - $mios->count();

            $vinculado = ctype_digit(Entrada::texto($request->query('colaborador_id'))) ? (int) $request->query('colaborador_id') : null;
            $permiso = $actor->can('usuarios.crear') ? 'usuarios.crear' : 'usuarios.editar';
            $colaboradores = collect($homonimos->colaboradoresSinCuenta($nombre, $this->autorizador->sedesPermitidas($actor, $permiso), $excluir))
                ->reject(fn (array $c) => (int) $c['id'] === $vinculado)->values();

            if ($iguales->isEmpty() && $colaboradores->isEmpty()) {
                return $this->avisos->nada();
            }
            $editado = $excluir !== null ? User::find($excluir) : null;
            $requiereConfirmacion = $iguales->isNotEmpty() && ($editado === null || HomonimosUsuarios::clave($editado->name) !== HomonimosUsuarios::clave($nombre));

            $coincidencias = $mios->map($describir)->all();
            foreach ($colaboradores as $c) {
                $coincidencias[] = $this->avisos->coincidencia(
                    ($c['num_empleado'] ? 'Colaborador #'.$c['num_empleado'] : 'Colaborador provisional').' · '.$c['nombre_completo'],
                    implode(' · ', array_filter([$c['puesto'] ?? null, $c['sede'] ?? null, 'Aún no tiene cuenta'])),
                ) + ['vincular' => ['id' => $c['id'], 'num_empleado' => $c['num_empleado'], 'nombre_completo' => $c['nombre_completo']]];
            }
            $mensaje = $iguales->isNotEmpty()
                ? HomonimosUsuarios::mensaje($mios, $otros).($requiereConfirmacion ? ' Si es otra persona, marca «Sí, es otra persona con el mismo nombre».' : '')
                : ($colaboradores->count() === 1
                    ? 'Hay un colaborador con ese nombre que aún no tiene cuenta. Si es la misma persona, toca «Vincular» para unir la cuenta con su ficha.'
                    : 'Hay colaboradores con ese nombre que aún no tienen cuenta. Si es uno de ellos, toca «Vincular».');

            $respuesta = $this->avisos->parecido($mensaje, $coincidencias);

            return $respuesta->setData($respuesta->getData(true) + ['requiere_confirmacion' => $requiereConfirmacion]);
        });
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Nombre de un catálogo de toda la empresa: igual sin mayúsculas = existe
     * (desactivado: con «Reactivar»); parecido (acentos, una letra, orden) = parecido.
     *
     * @param  class-string<Model>  $clase
     * @param  array{0: string, 1: string, 2: string}  $textos
     * @param  Closure(Model): string  $detalle
     * @param  list<string>  $cargar
     */
    private function catalogo(Request $request, string $modulo, string $clase, array $textos, Closure $detalle, array $cargar = []): JsonResponse
    {
        return $this->responder($request, $modulo, function (User $actor, string $valor, ?int $excluir) use ($modulo, $clase, $textos, $detalle, $cargar) {
            // Catálogo de toda la empresa: solo avisa a quien lo puede modificar
            abort_unless(collect([$modulo.'.crear', $modulo.'.editar'])->contains(fn ($p) => $actor->can($p) && $this->autorizador->alcanceDeEmpresa($actor, $p)), 403);
            $nombre = trim((string) preg_replace('/\s+/u', ' ', $valor));
            if (mb_strlen($nombre) < 2) {
                return $this->avisos->nada();
            }
            [$uno, $el, $reactivas] = $textos;
            $puedeReactivar = $actor->can($modulo.'.eliminar') && $this->autorizador->alcanceDeEmpresa($actor, $modulo.'.eliminar');
            $describir = fn (Model $m) => $this->avisos->coincidencia((string) $m->getAttribute('nombre'),
                trim($detalle($m).($m->getAttribute('activo') ? '' : ' · Desactivado'), ' ·'),
                ! $m->getAttribute('activo'), $puedeReactivar ? route($modulo.'.estado', $m->getKey()) : null);

            /** @var Collection<int, Model> $todos */
            $todos = $clase::query()->with($cargar)->when($excluir !== null, fn (Builder $q) => $q->whereKeyNot($excluir))->orderBy('nombre')->get();
            $igual = $todos->first(fn (Model $m) => mb_strtolower((string) $m->getAttribute('nombre')) === mb_strtolower($nombre));
            if ($igual !== null) {
                return $this->avisos->existe($igual->getAttribute('activo')
                    ? "Ya existe {$uno} «{$igual->getAttribute('nombre')}» en esta empresa. No se puede repetir."
                    : "Ya existe {$el} «{$igual->getAttribute('nombre')}», pero está desactivado. ¿{$this->mayuscula($reactivas)} en lugar de crearlo otra vez?", [$describir($igual)]);
            }

            $parecidos = $todos->filter(fn (Model $m) => $this->avisos->seParecen($nombre, (string) $m->getAttribute('nombre')))->take(3);
            if ($parecidos->isEmpty()) {
                return $this->avisos->libre('Nombre disponible.');
            }

            return $this->avisos->parecido('Se parece a '.($parecidos->count() === 1 ? 'uno que ya existe' : 'otros que ya existen').' (sin contar acentos, mayúsculas ni signos). Revisa que no sea el mismo:',
                $parecidos->map($describir)->values()->all());
        });
    }

    /**
     * Permiso (crear o editar del módulo), empresa de trabajo y lectura de
     * valor / excluir comunes a todos los avisos.
     *
     * @param  Closure(User, string, ?int): JsonResponse  $revisar
     */
    private function responder(Request $request, string $modulo, Closure $revisar): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can($modulo.'.crear') || $actor->can($modulo.'.editar'), 403);
        $empresaId = $this->empresa->id($actor);
        $valor = mb_substr(trim(Entrada::texto($request->query('valor'))), 0, 150);
        $excluir = ctype_digit(Entrada::texto($request->query('excluir'))) ? (int) $request->query('excluir') : null;
        if ($empresaId === null || $valor === '') {
            return $this->avisos->nada();
        }

        return $this->tenant->conEmpresa($empresaId, fn () => $revisar($actor, $valor, $excluir));
    }

    /**
     * Sedes donde el usuario usa ALGUNO de los permisos (null = todas).
     *
     * @param  list<string>  $permisos
     * @return list<int>|null
     */
    private function sedesUnion(User $actor, array $permisos): ?array
    {
        $todas = [];
        foreach ($permisos as $permiso) {
            if (! $actor->can($permiso)) {
                continue;
            }
            $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
            if ($sedes === null) {
                return null;
            }
            $todas = [...$todas, ...$sedes];
        }

        return array_values(array_unique(array_map('intval', $todas)));
    }

    /** @param  list<int>|null  $sedes */
    private function enSedes(?int $sedeId, ?array $sedes): bool
    {
        return $sedes === null || $sedeId === null || in_array($sedeId, array_map('intval', $sedes), true);
    }

    /** Columna en mayúsculas y sin espacios, guiones, puntos ni diagonales (SQLite y MariaDB). */
    private function sinSignos(string $columna): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER({$columna}), ' ', ''), '-', ''), '.', ''), '/', '')";
    }

    private function mayuscula(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }
}
