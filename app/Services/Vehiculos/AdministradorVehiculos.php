<?php

namespace App\Services\Vehiculos;

use App\Models\Colaborador;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del Padrón Vehicular (réplica de vehiculo_proceso.php de SEGCAT),
 * compartidas por la pantalla, el registro rápido y la búsqueda.
 *
 * Los vehículos son de la empresa (no de una sede): quien tiene
 * "vehiculos.ver" los ve todos; con alcance "propios" en editar o eliminar
 * solo modifica los que él dio de alta.
 *
 * Todas las consultas corren con la empresa de trabajo ya fijada en el
 * Tenant: el filtro de empresa lo pone el modelo, no el programador.
 */
class AdministradorVehiculos
{
    /** Grupos de las píldoras de la lista: Propios / Flotillas / Taxis. */
    public const GRUPOS = [
        'propio_huesped' => 'propios', 'propio_visitante' => 'propios', 'propio_familiar' => 'propios', 'propio_colaborador' => 'propios',
        'agencia_renta' => 'flotillas', 'empresa_proveedor' => 'flotillas', 'transporte_personal' => 'flotillas',
        'taxi_app' => 'taxis',
    ];

    /** Textos de la lista "Categoría" del formulario, como en SEGCAT. */
    public const OPCIONES_PROPIEDAD = [
        'propio_huesped' => 'Propio Huésped',
        'propio_visitante' => 'Propio Visitante',
        'propio_familiar' => 'Propio Familiar',
        'propio_colaborador' => 'Propio Colaborador',
        'agencia_renta' => 'Agencia (Rentado)',
        'empresa_proveedor' => 'Flotilla (Empresa/Proveedor)',
        'taxi_app' => 'Taxi / Plataforma (Uber/Didi)',
        'transporte_personal' => 'Transporte de Personal',
    ];

    /** Propiedades que se ligan a un proveedor (agencia o empresa propietaria). */
    public const CON_PROVEEDOR = ['agencia_renta', 'empresa_proveedor', 'taxi_app', 'transporte_personal'];

    /** Propiedades donde el proveedor es obligatorio. */
    public const PROVEEDOR_OBLIGATORIO = ['empresa_proveedor'];

    /** La capacidad solo tiene sentido en unidades de transporte. */
    public const TIPOS_CON_CAPACIDAD = ['autobus', 'camion_ligero'];

    public const PROPIEDADES_CON_CAPACIDAD = ['transporte_personal'];

    /** Propiedad sugerida al dar de alta desde la ficha de un proveedor, según su categoría. */
    public const PROPIEDAD_POR_CATEGORIA = [
        'taxi' => 'taxi_app',
        'agencia_autos' => 'agencia_renta',
        'transporte_personal' => 'transporte_personal',
        'transporte_huespedes' => 'transporte_personal',
    ];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Limita una consulta a lo que el actor puede tocar con un permiso:
     * con alcance "propios", solo lo que él dio de alta; con alcance de sede
     * o de empresa, todo (el vehículo no pertenece a una sede).
     *
     * @param  Builder<Vehiculo>  $consulta
     * @return Builder<Vehiculo>
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

        return $efectivo->alcance === Alcance::Propios
            ? $consulta->where('vehiculos.creado_por', $actor->id)
            : $consulta;
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todos.
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

        return $this->limitar(Vehiculo::query(), $actor, $permiso)->pluck('vehiculos.id')->map(fn ($id) => (int) $id)->all();
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, int $empresaId, array $entrada): Vehiculo
    {
        $datos = $this->validar($entrada, $empresaId, null);

        $vehiculo = DB::transaction(fn () => Vehiculo::create($datos + ['empresa_id' => $empresaId]));

        $this->auditoria->auditar($actor, 'vehiculos.creado', $vehiculo, null, $this->foto($vehiculo));

        return $vehiculo;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, int $empresaId, Vehiculo $vehiculo, array $entrada): Vehiculo
    {
        app(AltasPorVerificar::class)->exigirNoRechazado($vehiculo, 'placas');
        $antes = $this->foto($vehiculo);
        $datos = $this->validar($entrada, $empresaId, $vehiculo);

        DB::transaction(fn () => $vehiculo->fill($datos)->save());

        $this->auditoria->auditar($actor, 'vehiculos.actualizado', $vehiculo, $antes, $this->foto($vehiculo));

        return $vehiculo;
    }

    /**
     * Baja lógica y reactivación (SEGCAT: estatus 0/1, nunca se borra: las
     * bitácoras de accesos y estacionamientos dependen del vehículo).
     */
    public function cambiarEstado(User $actor, Vehiculo $vehiculo, bool $activo): void
    {
        if ($activo) {
            app(AltasPorVerificar::class)->exigirNoRechazado($vehiculo);
        }
        $vehiculo->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'vehiculos.reactivado' : 'vehiculos.desactivado', $vehiculo, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * Vehículo (de cualquier estado) que ya tiene esas placas en la empresa.
     */
    public function conPlacas(?string $placas, ?int $excepto = null): ?Vehiculo
    {
        $normalizadas = Vehiculo::normalizarPlacas($placas);
        if ($normalizadas === '') {
            return null;
        }

        return Vehiculo::where('placas', $normalizadas)->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))->first();
    }

    // --------------------------------------------------------------- Validación

    /**
     * Valida y deja listos los datos. Los campos que no aplican a la
     * propiedad o al tipo elegidos se guardan vacíos, aunque lleguen
     * forzados (como hacía SEGCAT con el número económico).
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function validar(array $entrada, int $empresaId, ?Vehiculo $actual): array
    {
        $entrada = $this->normalizar($entrada);

        $validados = Validator::make($entrada, [
            'placas' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[A-Z0-9Ñ]+$/u'],
            'propiedad' => ['required', 'string', Rule::in(array_keys(Vehiculo::PROPIEDADES))],
            'tipo' => ['required', 'string', Rule::in(array_keys(Vehiculo::TIPOS))],
            'descripcion_otro' => ['nullable', 'required_if:tipo,otro', 'string', 'max:150'],
            'marca' => ['required', 'string', 'max:50'],
            'modelo' => ['nullable', 'string', 'max:60'],
            'color' => ['required', 'string', 'max:30'],
            'capacidad' => ['nullable', 'integer', 'min:1', 'max:99'],
            'numero_economico' => ['nullable', 'string', 'max:30'],
            'proveedor_id' => ['nullable', Rule::requiredIf(in_array($entrada['propiedad'] ?? null, self::PROVEEDOR_OBLIGATORIO, true)), 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
        ], [
            'placas.required' => 'Las placas son obligatorias.',
            'placas.regex' => 'Las placas solo llevan letras y números (los espacios y guiones se quitan solos).',
            'placas.min' => 'Revisa las placas: son muy cortas.',
            'placas.max' => 'Revisa las placas: son muy largas (máximo 20 caracteres).',
            'propiedad.in' => 'Elige la categoría del vehículo de la lista.',
            'tipo.in' => 'Elige el tipo de vehículo de la lista.',
            'descripcion_otro.required_if' => 'Describe el tipo de vehículo (por ejemplo: montacargas, carrito de golf).',
            'capacidad.min' => 'La capacidad debe ser de 1 a 99 personas.',
            'capacidad.max' => 'La capacidad debe ser de 1 a 99 personas.',
            'proveedor_id.required' => 'Elige la empresa propietaria de la flotilla.',
        ], [
            'propiedad' => 'categoría',
            'tipo' => 'tipo de vehículo',
            'descripcion_otro' => 'descripción del tipo',
            'numero_economico' => 'número económico',
            'proveedor_id' => 'agencia o empresa propietaria',
            'colaborador_id' => 'colaborador',
        ])->validate();

        $propiedad = $validados['propiedad'];
        $tipo = $validados['tipo'];

        $existente = $this->conPlacas($validados['placas'], $actual?->id);
        if ($existente !== null) {
            $detalle = trim(implode(' ', array_filter([$existente->marca, $existente->modelo])).($existente->color ? ' · '.$existente->color : ''));
            throw ValidationException::withMessages(['placas' => "Las placas «{$validados['placas']}» ya están registradas en esta empresa"
                .($detalle !== '' ? " ({$detalle})" : '').($existente->activo ? '.' : ', dado de baja: reactívalo en lugar de registrarlo otra vez.')]);
        }

        $conCapacidad = in_array($tipo, self::TIPOS_CON_CAPACIDAD, true) || in_array($propiedad, self::PROPIEDADES_CON_CAPACIDAD, true);

        return [
            'placas' => $validados['placas'],
            'propiedad' => $propiedad,
            'tipo' => $tipo,
            'descripcion_otro' => $tipo === 'otro' ? $validados['descripcion_otro'] : null,
            'marca' => $validados['marca'],
            'modelo' => $validados['modelo'] ?? null,
            'color' => $validados['color'],
            'capacidad' => $conCapacidad ? ($validados['capacidad'] ?? null) : null,
            'numero_economico' => in_array($propiedad, Vehiculo::CON_NUMERO_ECONOMICO, true) ? ($validados['numero_economico'] ?? null) : null,
            'proveedor_id' => in_array($propiedad, self::CON_PROVEEDOR, true) ? $this->proveedorValido($validados['proveedor_id'] ?? null, $actual) : null,
            'colaborador_id' => $propiedad === 'propio_colaborador' ? $this->colaboradorValido($validados['colaborador_id'] ?? null, $actual) : null,
        ];
    }

    /**
     * Placas normalizadas; marca, modelo, color y número económico en
     * mayúsculas (como SEGCAT); espacios dobles fuera y vacíos como nulos.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada): array
    {
        $entrada = array_intersect_key($entrada, array_flip([
            'placas', 'propiedad', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id',
        ]));

        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $entrada[$campo] = trim((string) preg_replace('/\s+/u', ' ', $valor));
            }
        }
        if (isset($entrada['placas']) && is_string($entrada['placas'])) {
            $entrada['placas'] = Vehiculo::normalizarPlacas($entrada['placas']);
        }
        foreach (['marca', 'modelo', 'color', 'numero_economico'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = mb_strtoupper($entrada[$campo]);
            }
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $entrada);
    }

    private function proveedorValido(mixed $id, ?Vehiculo $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        // Con el Tenant fijado, un proveedor de otra empresa simplemente no existe
        $proveedor = Proveedor::find($id);
        if ($proveedor === null || (! $proveedor->activo && $id !== (int) $actual?->proveedor_id)) {
            throw ValidationException::withMessages(['proveedor_id' => 'La empresa propietaria no existe en esta empresa o está desactivada.']);
        }

        return $id;
    }

    private function colaboradorValido(mixed $id, ?Vehiculo $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $colaborador = Colaborador::find($id);
        if ($colaborador === null || (! $colaborador->activo && $id !== (int) $actual?->colaborador_id)) {
            throw ValidationException::withMessages(['colaborador_id' => 'El colaborador no existe en esta empresa o está dado de baja.']);
        }

        return $id;
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Búsqueda para otros módulos (Bitácora de accesos, Estacionamientos):
     * por placas (desde el inicio, sin importar guiones ni espacios) o por
     * marca, modelo o color; mínimo 2 caracteres, máximo 15, solo activos.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $texto): array
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) < 2) {
            return [];
        }

        $placas = Vehiculo::normalizarPlacas($texto);
        $escapar = fn (string $t) => addcslashes($t, '%_\\');

        return Vehiculo::query()
            ->where('vehiculos.activo', true)
            ->where(function ($q) use ($texto, $placas, $escapar) {
                if ($placas !== '') {
                    $q->where('vehiculos.placas', 'like', $escapar($placas).'%');
                }
                $q->orWhere(function ($palabras) use ($texto, $escapar) {
                    foreach (explode(' ', mb_strtoupper($texto)) as $palabra) {
                        $comodin = '%'.$escapar($palabra).'%';
                        $palabras->where(fn ($p) => $p->where('vehiculos.marca', 'like', $comodin)
                            ->orWhere('vehiculos.modelo', 'like', $comodin)
                            ->orWhere('vehiculos.color', 'like', $comodin));
                    }
                });
            })
            ->with(['proveedor:id,nombre', 'colaborador:id,nombre,apellido_paterno,apellido_materno'])
            ->orderBy('vehiculos.placas')
            ->limit(15)
            ->get()
            ->map(fn (Vehiculo $v) => $this->resumen($v))
            ->all();
    }

    /**
     * Lo que se devuelve en JSON. "empresa" es la agencia o empresa
     * propietaria (proveedor), si la hay.
     *
     * @return array<string, mixed>
     */
    public function resumen(Vehiculo $v): array
    {
        $v->loadMissing(['proveedor:id,nombre', 'colaborador:id,nombre,apellido_paterno,apellido_materno']);

        return [
            'id' => $v->id,
            'placas' => $v->placas,
            'descripcion' => $this->descripcion($v),
            'propiedad' => $v->propiedad,
            'propiedad_etiqueta' => Vehiculo::PROPIEDADES[$v->propiedad] ?? $v->propiedad,
            'numero_economico' => $v->numero_economico,
            'empresa' => $v->proveedor?->nombre,
            'colaborador' => $v->colaborador?->nombreCompleto(),
            'codigo_qr' => $v->codigo_qr,
            'activo' => (bool) $v->activo,
            'verificacion' => $v->verificacion,
        ];
    }

    /**
     * "Marca Modelo · Color".
     */
    public function descripcion(Vehiculo $v): string
    {
        $auto = trim(implode(' ', array_filter([$v->marca, $v->modelo])));

        return trim($auto.($v->color ? ' · '.$v->color : ''), ' ·');
    }

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(Vehiculo $v): array
    {
        return $v->only(['placas', 'propiedad', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id', 'activo']);
    }
}
