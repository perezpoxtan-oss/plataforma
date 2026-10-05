<?php

namespace App\Services\Personas;

use App\Models\Colaborador;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del Padrón de personas (réplica de visitante_proceso.php de SEGCAT),
 * compartidas por la pantalla, el registro rápido y la búsqueda.
 *
 * Las personas son de la empresa, no de una sede: quien tiene
 * "visitantes.ver" consulta todo el padrón de su empresa. El alcance solo
 * distingue "propios" (edita o da de baja solo lo que él registró) del
 * resto (sede o empresa = todo el padrón).
 *
 * Todas las consultas corren con la empresa de trabajo fijada en el Tenant.
 */
class AdministradorPersonas
{
    /** Folio normalizado: letras y números (INE, pasaporte, licencia, CURP...). */
    public const FOLIO = '/^[A-ZÑ0-9]{4,30}$/u';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Limita una consulta al alcance del actor en un permiso. Solo "propios"
     * acota (a lo que él registró); sede y empresa ven todo el padrón.
     *
     * @param  Builder<Persona>  $consulta
     * @return Builder<Persona>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }
        if ($efectivo->alcance === Alcance::Propios) {
            $consulta->where('personas.creado_por', $actor->id);
        }

        return $consulta;
    }

    /**
     * Ids dentro del alcance de un permiso; null = todos.
     *
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin || $this->autorizador->permisosEfectivos($actor)[$permiso]->alcance !== Alcance::Propios) {
            return null;
        }

        return Persona::where('personas.creado_por', $actor->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ----------------------------------------------------------------- Escritura

    public function crear(User $actor, Request $request): Persona
    {
        $datos = $this->validar($request, $actor, null);

        $persona = $this->guardar(fn () => Persona::create($datos));

        $this->auditoria->auditar($actor, 'visitantes.creado', $persona, null, $this->foto($persona));

        return $persona;
    }

    public function actualizar(User $actor, Persona $persona, Request $request): Persona
    {
        $antes = $this->foto($persona);
        $datos = $this->validar($request, $actor, $persona);

        $this->guardar(fn () => $persona->fill($datos)->save());

        $this->auditoria->auditar($actor, 'visitantes.actualizado', $persona, $antes, $this->foto($persona));

        return $persona;
    }

    /**
     * Dos altas simultáneas con el mismo folio: el índice único detiene la
     * segunda y se responde como error de captura, no como error del sistema.
     */
    private function guardar(\Closure $accion): mixed
    {
        try {
            return DB::transaction($accion);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['folio_identificacion' => 'Ya existe otra persona registrada en esta empresa con ese folio de identificación.']);
        }
    }

    public function cambiarEstado(User $actor, Persona $persona, bool $activo): void
    {
        $persona->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'visitantes.reactivado' : 'visitantes.desactivado', $persona, ['activo' => ! $activo], ['activo' => $activo]);
    }

    // --------------------------------------------------------------- Validación

    /**
     * @return array<string, mixed> columnas listas para guardar
     */
    private function validar(Request $request, User $actor, ?Persona $actual): array
    {
        $entrada = $this->normalizar($request->only([
            'tipo', 'categoria', 'nombre_completo', 'proveedor_id', 'empresa_procedencia',
            'tipo_identificacion', 'folio_identificacion', 'telefono', 'motivo_visita',
        ]));

        $validados = Validator::make($entrada, [
            'tipo' => ['required', Rule::in(array_keys(Persona::TIPOS))],
            'categoria' => ['nullable', Rule::in(array_keys(Persona::CATEGORIAS))],
            'nombre_completo' => ['required', 'string', 'min:3', 'max:150'],
            'proveedor_id' => ['nullable', 'integer'],
            'empresa_procedencia' => ['nullable', 'string', 'max:100'],
            'tipo_identificacion' => ['nullable', 'required_with:folio_identificacion', Rule::in(array_keys(Persona::IDENTIFICACIONES))],
            'folio_identificacion' => ['nullable', 'string', 'regex:'.self::FOLIO],
            'telefono' => ['nullable', 'string', 'regex:/^\d{10,15}$/'],
            'motivo_visita' => ['nullable', 'string', 'max:500'],
        ], [
            'tipo.required' => 'Elige el tipo de persona.',
            'tipo.in' => 'Elige un tipo de la lista (Visitante, Proveedor o Contratista).',
            'categoria.in' => 'Elige una categoría de la lista.',
            'nombre_completo.required' => 'El nombre completo es obligatorio.',
            'tipo_identificacion.required_with' => 'Elige el tipo de identificación del folio.',
            'tipo_identificacion.in' => 'Elige un tipo de identificación de la lista.',
            'folio_identificacion.regex' => 'El folio debe tener de 4 a 30 letras o números (los espacios y guiones se quitan solos).',
            'telefono.regex' => 'El teléfono debe tener de 10 a 15 dígitos.',
        ], [
            'nombre_completo' => 'nombre completo',
            'empresa_procedencia' => 'empresa de procedencia',
            'motivo_visita' => 'motivo de la visita',
        ])->validate();

        $tipo = $validados['tipo'];
        // La categoría (prospecto, familiar) solo distingue a los visitantes
        $categoria = $tipo === 'visitante' ? ($validados['categoria'] ?? 'general') : 'general';
        // Un visitante no representa a un proveedor registrado (SEGCAT lo limpiaba igual)
        $proveedorId = $tipo === 'visitante' ? null : $this->proveedorValido($validados['proveedor_id'] ?? null, $actual);

        $folio = $validados['folio_identificacion'] ?? null;
        if ($folio !== null) {
            $this->folioDisponible($folio, $actor, $actual);
        }

        return [
            'tipo' => $tipo,
            'categoria' => $categoria,
            'nombre_completo' => $validados['nombre_completo'],
            'proveedor_id' => $proveedorId,
            // Si viene de un proveedor registrado, el texto libre sobra
            'empresa_procedencia' => $proveedorId === null ? ($validados['empresa_procedencia'] ?? null) : null,
            'tipo_identificacion' => $folio === null ? null : $validados['tipo_identificacion'],
            'folio_identificacion' => $folio,
            'telefono' => $validados['telefono'] ?? null,
            'motivo_visita' => $validados['motivo_visita'] ?? null,
        ];
    }

    /**
     * Folio único por empresa (ya normalizado, así "abc-123" y "ABC 123" chocan).
     * El mensaje dice quién lo tiene: todo el que registra personas ve el padrón.
     */
    private function folioDisponible(string $folio, User $actor, ?Persona $actual): void
    {
        $otra = Persona::where('folio_identificacion', $folio)
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))
            ->first();

        if ($otra === null) {
            return;
        }

        $mensaje = 'Ya existe otra persona registrada en esta empresa con ese folio de identificación';
        $mensaje .= $actor->can('visitantes.ver')
            ? ": «{$otra->nombre_completo}» (".Persona::TIPOS[$otra->tipo].($otra->activo ? '' : ', dada de baja').').'
            : '.';

        throw FolioDuplicado::de($otra, $mensaje);
    }

    private function proveedorValido(mixed $id, ?Persona $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        // Filtro de empresa automático: uno de otra empresa "no existe"
        $proveedor = Proveedor::find($id);
        if ($proveedor === null || (! $proveedor->activo && $id !== (int) $actual?->proveedor_id)) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor no existe en esta empresa o está desactivado.']);
        }

        return $id;
    }

    /**
     * Espacios dobles fuera, folio normalizado, teléfono solo con dígitos y
     * vacíos como nulos.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada): array
    {
        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $entrada[$campo] = $campo === 'motivo_visita'
                    ? trim($valor)
                    : trim((string) preg_replace('/\s+/u', ' ', $valor));
            }
        }
        if (isset($entrada['folio_identificacion']) && is_string($entrada['folio_identificacion'])) {
            $entrada['folio_identificacion'] = Persona::normalizarFolio($entrada['folio_identificacion']);
        }
        if (isset($entrada['telefono']) && is_string($entrada['telefono'])) {
            $entrada['telefono'] = preg_replace('/[\s\-().+]/', '', $entrada['telefono']);
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $entrada);
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Búsqueda para la Bitácora de accesos: por nombre (todas las palabras)
     * o por inicio del folio. Solo activos, máximo 15.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $texto): array
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) < 2) {
            return [];
        }

        $comodin = fn (string $t) => '%'.addcslashes(mb_strtolower($t), '%_\\').'%';
        $folio = Persona::normalizarFolio($texto);

        return Persona::query()
            ->with('proveedor:id,nombre')
            ->where('personas.activo', true)
            ->where(function ($q) use ($texto, $comodin, $folio) {
                $q->where(function ($nombre) use ($texto, $comodin) {
                    foreach (explode(' ', $texto) as $palabra) {
                        $nombre->whereRaw('LOWER(personas.nombre_completo) LIKE ?', [$comodin($palabra)]);
                    }
                });
                if ($folio !== null) {
                    $q->orWhere('personas.folio_identificacion', 'like', addcslashes($folio, '%_\\').'%');
                }
            })
            ->orderBy('personas.nombre_completo')
            ->limit(15)
            ->get()
            ->map(fn (Persona $p) => $this->resumen($p))
            ->all();
    }

    /**
     * Lo que se devuelve en JSON: el folio siempre enmascarado.
     *
     * @return array<string, mixed>
     */
    public function resumen(Persona $p): array
    {
        $p->loadMissing('proveedor:id,nombre');

        return [
            'id' => $p->id,
            'nombre_completo' => $p->nombre_completo,
            'tipo' => $p->tipo,
            'tipo_etiqueta' => Persona::TIPOS[$p->tipo] ?? $p->tipo,
            'categoria' => $p->categoria,
            'empresa' => $p->empresaQueRepresenta(),
            'proveedor_id' => $p->proveedor_id,
            'folio' => $p->folioEnmascarado(),
            'activo' => (bool) $p->activo,
        ];
    }

    /**
     * Foto para la bitácora: folio y teléfono enmascarados (últimos 4).
     *
     * @return array<string, mixed>
     */
    public function foto(Persona $p): array
    {
        return $p->only(['tipo', 'categoria', 'nombre_completo', 'proveedor_id', 'empresa_procedencia', 'tipo_identificacion', 'motivo_visita', 'activo']) + [
            'folio_identificacion' => Colaborador::enmascarar($p->folio_identificacion),
            'telefono' => Colaborador::enmascarar($p->telefono),
        ];
    }
}
