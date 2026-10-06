<?php

namespace App\Services\Padrones;

use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de Proveedores / Empresas Externas (SEGCAT: proveedor_proceso.php).
 *
 * Alcance de empresa: administra todo el padrón.
 * Alcance de sede:
 *  - ve los proveedores que operan en sus sedes (o en todas);
 *  - da de alta proveedores solo para sus sedes; si el nombre ya existe en la
 *    empresa no se duplica: se agrega su sede al existente (regla de SEGCAT);
 *  - marca o desmarca únicamente sus sedes en "Sedes donde opera";
 *  - edita los datos y desactiva solo los proveedores que operan
 *    exclusivamente en sus sedes (los compartidos son de toda la empresa).
 *
 * Todo se ejecuta dentro de Tenant::conEmpresa(): las consultas ya llevan el
 * filtro de la empresa.
 */
class AdministradorProveedores
{
    /** RFC: persona moral (3 letras) o física (4), fecha AAMMDD y homoclave. */
    public const RFC = '/^[A-ZÑ&]{3,4}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[A-Z\d]{2}[A\d]$/u';

    /** Teléfono (E.164): "+" opcional y de 7 a 15 dígitos, ya sin espacios ni guiones. */
    public const TELEFONO = '/^\+?\d{7,15}$/';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * Sedes en las que el usuario usa el permiso: null = todas.
     *
     * @return list<int>|null
     */
    public function sedes(User $usuario, string $permiso): ?array
    {
        return $this->autorizador->sedesPermitidas($usuario, $permiso);
    }

    /**
     * Proveedores que el usuario puede ver con el permiso indicado.
     */
    public function consulta(User $usuario, string $permiso = 'proveedores.ver'): Builder
    {
        $sedes = $this->sedes($usuario, $permiso);

        return Proveedor::query()->when($sedes !== null, fn ($q) => $q->operanEn($sedes))
            // Seguridad (AZ-04): "Solo los propios" = los que él dio de alta
            ->when($this->autorizador->soloPropios($usuario, $permiso), fn ($q) => $q->where('proveedores.creado_por', $usuario->id));
    }

    /**
     * Seguridad (AZ-04): con "Solo los propios" solo se modifica lo que él dio de alta.
     */
    public function esPropioSiAplica(User $usuario, Proveedor $proveedor, string $permiso): bool
    {
        return ! $this->autorizador->soloPropios($usuario, $permiso) || (int) $proveedor->creado_por === (int) $usuario->id;
    }

    /**
     * ¿Opera solo en estas sedes? (null = alcance de empresa: siempre sí).
     *
     * @param  list<int>|null  $sedes
     */
    public function esExclusivoDe(Proveedor $proveedor, ?array $sedes): bool
    {
        if ($sedes === null) {
            return true;
        }
        if ($proveedor->todas_las_sedes) {
            return false;
        }
        $suyas = $proveedor->relationLoaded('sedes') ? $proveedor->sedes->pluck('id')->all() : $proveedor->sedes()->pluck('sedes.id')->all();

        return $suyas !== [] && array_diff(array_map('intval', $suyas), $sedes) === [];
    }

    /**
     * Alta. Devuelve el proveedor y qué pasó:
     *  - "creado": proveedor nuevo;
     *  - "sede_agregada": ya existía y se le agregó la sede de quien lo registró;
     *  - "ya_operaba": ya existía y ya operaba en esa sede;
     *  - "ya_existia": ya existía (solo en el alta rápida con alcance de empresa).
     *
     * @return array{0: Proveedor, 1: string}
     */
    public function crear(User $actor, Request $request, bool $rapido = false, string $permiso = 'proveedores.crear'): array
    {
        $datos = $this->validarDatos($request);
        // $permiso: el operativo de la pantalla de origen en un alta por verificar (ADR-0006)
        $mias = $this->sedes($actor, $permiso);

        $existente = $this->buscarPorNombre($datos['nombre']);
        if ($existente !== null) {
            if ($mias !== null) {
                return [$existente, $this->agregarSedes($actor, $existente, $this->sedesPedidas($request, $mias))];
            }
            if ($rapido) {
                return [$existente, 'ya_existia'];
            }
            throw ValidationException::withMessages(['nombre' => "Ya existe «{$existente->nombre}» en esta empresa. Búscalo en la lista para editarlo."]);
        }

        [$todas, $sedes] = $mias === null ? $this->validarSedes($request, true) : [false, $this->sedesPedidas($request, $mias)];
        if (! $todas && $sedes === []) {
            throw ValidationException::withMessages(['sedes' => 'No tienes sedes activas donde registrar al proveedor.']);
        }

        $proveedor = DB::transaction(function () use ($datos, $todas, $sedes) {
            $proveedor = Proveedor::create($datos + ['todas_las_sedes' => $todas]);
            $proveedor->sedes()->sync($sedes);

            return $proveedor;
        });

        $this->auditoria->auditar($actor, 'proveedores.creado', $proveedor, null, $this->foto($proveedor));

        return [$proveedor, 'creado'];
    }

    /**
     * Datos generales (no las sedes: tienen su propio diálogo).
     */
    public function actualizar(User $actor, Proveedor $proveedor, Request $request): Proveedor
    {
        app(AltasPorVerificar::class)->exigirNoRechazado($proveedor, 'nombre');
        $datos = $this->validarDatos($request);
        $otro = $this->buscarPorNombre($datos['nombre']);
        if ($otro !== null && $otro->id !== $proveedor->id) {
            throw ValidationException::withMessages(['nombre' => "Ya existe «{$otro->nombre}» en esta empresa."]);
        }

        $antes = $this->foto($proveedor);
        $proveedor->fill($datos)->save();
        $despues = $this->foto($proveedor);
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'proveedores.actualizado', $proveedor, $antes, $despues);
        }

        return $proveedor;
    }

    /**
     * "Sedes donde opera". Alcance de empresa: libre (puede quedar sin sedes,
     * como en SEGCAT). Alcance de sede: solo cambia la casilla de sus sedes.
     */
    public function actualizarSedes(User $actor, Proveedor $proveedor, Request $request): void
    {
        $mias = $this->sedes($actor, 'proveedores.editar');
        $antes = $this->foto($proveedor);
        [$todas, $sedes] = $mias === null ? $this->validarSedes($request, false, $proveedor) : $this->soloMisSedes($request, $proveedor, $mias);

        DB::transaction(function () use ($proveedor, $todas, $sedes) {
            $proveedor->forceFill(['todas_las_sedes' => $todas])->save();
            $proveedor->sedes()->sync($sedes);
        });
        $proveedor->unsetRelation('sedes');

        $despues = $this->foto($proveedor);
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'proveedores.sedes_actualizadas', $proveedor, $antes, $despues);
        }
    }

    public function cambiarEstado(User $actor, Proveedor $proveedor, bool $activo): void
    {
        if ($proveedor->activo === $activo) {
            return;
        }
        if ($activo) {
            app(AltasPorVerificar::class)->exigirNoRechazado($proveedor);
        }
        $proveedor->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'proveedores.reactivado' : 'proveedores.desactivado', $proveedor, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * Búsqueda para autocompletar (Pases de salida, Accesos...): mínimo 2
     * letras, máximo 15, solo activos y de las sedes del usuario.
     *
     * @param  list<int>|null  $sedes
     * @return list<array{id: int, nombre: string, categoria: string, categoria_etiqueta: string, verificacion: ?string}>
     */
    public function buscar(string $texto, ?array $sedes): array
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) < 2) {
            return [];
        }
        $patron = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($texto)).'%';

        return Proveedor::where('activo', true)
            ->when($sedes !== null, fn ($q) => $q->operanEn($sedes))
            ->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$patron])->orWhereRaw('LOWER(rfc) LIKE ?', [$patron]))
            ->orderBy('nombre')
            ->limit(15)
            ->get(['id', 'nombre', 'categoria', 'verificacion'])
            ->map(fn (Proveedor $p) => $this->resumen($p))
            ->all();
    }

    /**
     * @return array{id: int, nombre: string, categoria: string, categoria_etiqueta: string, verificacion: ?string}
     */
    public function resumen(Proveedor $p): array
    {
        return [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'categoria' => $p->categoria,
            'categoria_etiqueta' => Proveedor::CATEGORIAS[$p->categoria] ?? $p->categoria,
            'verificacion' => $p->verificacion,
        ];
    }

    /**
     * Mismo nombre sin importar mayúsculas ni espacios dobles.
     */
    public function buscarPorNombre(string $nombre): ?Proveedor
    {
        return Proveedor::whereRaw('LOWER(nombre) = ?', [mb_strtolower(self::normalizarNombre($nombre))])->first();
    }

    public static function normalizarNombre(?string $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));
    }

    /**
     * @return array{nombre: string, categoria: string, rfc: ?string, telefono: ?string, direccion: ?string}
     */
    private function validarDatos(Request $request): array
    {
        $rfc = mb_strtoupper((string) preg_replace('/[\s\-.]+/u', '', Entrada::texto($request->input('rfc'))));
        $telefono = (string) preg_replace('/[\s\-.()]+/', '', Entrada::texto($request->input('telefono')));
        $direccion = self::normalizarNombre(Entrada::texto($request->input('direccion')));
        $request->merge([
            'nombre' => self::normalizarNombre(Entrada::texto($request->input('nombre'))),
            'rfc' => $rfc === '' ? null : $rfc,
            'telefono' => $telefono === '' ? null : $telefono,
            'direccion' => $direccion === '' ? null : $direccion,
        ]);

        return $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'categoria' => ['required', 'string', Rule::in(array_keys(Proveedor::CATEGORIAS))],
            'rfc' => ['nullable', 'string', 'regex:'.self::RFC],
            'telefono' => ['nullable', 'string', 'regex:'.self::TELEFONO],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [
            'nombre.required' => 'La razón social o nombre comercial es obligatorio.',
            'categoria.in' => 'Elige una categoría de la lista.',
            'rfc.regex' => 'El RFC no tiene un formato válido: 12 caracteres (persona moral) o 13 (persona física), con fecha AAMMDD.',
            'telefono.regex' => 'El teléfono no tiene un formato válido: solo números (7 a 15 dígitos), opcionalmente con "+" y código de país.',
        ], ['nombre' => 'razón social', 'categoria' => 'categoría', 'direccion' => 'dirección']);
    }

    /**
     * Alcance de empresa: "todas las sedes" o la lista elegida (solo sedes
     * activas de esta empresa). Las sedes desactivadas no salen en la lista:
     * se conservan sus ligas.
     *
     * @return array{0: bool, 1: list<int>}
     */
    private function validarSedes(Request $request, bool $exigirUna, ?Proveedor $proveedor = null): array
    {
        $request->validate(['todas_las_sedes' => ['nullable', 'boolean'], 'sedes' => ['nullable', 'array'], 'sedes.*' => ['integer']]);
        if ($request->boolean('todas_las_sedes', $exigirUna)) {
            return [true, []];
        }

        $sedes = Sede::where('activo', true)->whereIn('id', array_map('intval', (array) $request->input('sedes', [])))->pluck('id')->all();
        if ($exigirUna && $sedes === []) {
            throw ValidationException::withMessages(['sedes' => 'Marca al menos una sede o elige «Todas las sedes».']);
        }
        if ($proveedor !== null) {
            $sedes = [...$sedes, ...$proveedor->sedes()->where('sedes.activo', false)->pluck('sedes.id')->all()];
        }

        return [false, array_values(array_unique(array_map('intval', $sedes)))];
    }

    /**
     * Sedes que pide quien tiene alcance de sede (solo las suyas y activas);
     * si no marcó ninguna, todas las suyas.
     *
     * @param  list<int>  $mias
     * @return list<int>
     */
    private function sedesPedidas(Request $request, array $mias): array
    {
        $request->validate(['sedes' => ['nullable', 'array'], 'sedes.*' => ['integer']]);
        $mias = Sede::where('activo', true)->whereIn('id', $mias)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pedidas = array_values(array_intersect($mias, array_map('intval', (array) $request->input('sedes', []))));

        return $pedidas === [] ? $mias : $pedidas;
    }

    /**
     * Regla de SEGCAT: el proveedor ya existía; se agrega la sede de quien lo
     * intentó registrar (nunca se quita ninguna).
     *
     * @param  list<int>  $sedes
     */
    private function agregarSedes(User $actor, Proveedor $proveedor, array $sedes): string
    {
        if ($proveedor->todas_las_sedes) {
            return 'ya_operaba';
        }
        $actuales = $proveedor->sedes()->pluck('sedes.id')->map(fn ($id) => (int) $id)->all();
        $nuevas = array_values(array_diff($sedes, $actuales));
        if ($nuevas === []) {
            return 'ya_operaba';
        }

        $antes = $this->foto($proveedor);
        $proveedor->sedes()->syncWithoutDetaching($nuevas);
        $this->auditoria->auditar($actor, 'proveedores.sede_agregada', $proveedor, $antes, $this->foto($proveedor));

        return 'sede_agregada';
    }

    /**
     * Alcance de sede: solo cambia la casilla de sus propias sedes; las de
     * las demás quedan como estaban. Si era de "todas las sedes" y quita la
     * suya, pasa a lista explícita con el resto.
     *
     * @param  list<int>  $mias
     * @return array{0: bool, 1: list<int>}
     */
    private function soloMisSedes(Request $request, Proveedor $proveedor, array $mias): array
    {
        $request->validate(['sedes' => ['nullable', 'array'], 'sedes.*' => ['integer']]);
        $mias = Sede::where('activo', true)->whereIn('id', $mias)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $marcadas = array_values(array_intersect($mias, array_map('intval', (array) $request->input('sedes', []))));
        $desmarcadas = array_values(array_diff($mias, $marcadas));

        if ($proveedor->todas_las_sedes) {
            if ($desmarcadas === []) {
                return [true, []];
            }
            $base = Sede::pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $base = [...$proveedor->sedes()->pluck('sedes.id')->map(fn ($id) => (int) $id)->all(), ...$marcadas];
        }

        return [false, array_values(array_unique(array_diff($base, $desmarcadas)))];
    }

    /**
     * @return array<string, mixed>
     */
    public function foto(Proveedor $p): array
    {
        return $p->only(['nombre', 'categoria', 'rfc', 'telefono', 'direccion', 'todas_las_sedes', 'activo'])
            + ['sedes' => $p->sedes()->orderBy('sedes.id')->pluck('sedes.id')->map(fn ($id) => (int) $id)->all()];
    }
}
