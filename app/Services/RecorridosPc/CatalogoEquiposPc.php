<?php

namespace App\Services\RecorridosPc;

use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de Equipos de Protección Civil (SEGCAT: pc_equipos_proceso.php).
 *
 * Permisos: se consulta con «recorridos_pc.ver» (la guardia lo necesita para
 * su recorrido) y se administra con los de Equipos de seguridad (Padrones):
 * «equipos.crear», «equipos.editar», «equipos.eliminar» (desactivar y
 * reactivar) y «equipos.imprimir» (etiqueta). Así el Agente lo consulta pero
 * no da de alta equipos, como en el resto de los padrones.
 *
 * Alcance: el equipo pertenece a una sede; con alcance de sede solo se ven y
 * se tocan los de sus sedes; con «propios», solo los que el usuario registró.
 * Núm. de Serie / ID único por sede (SEGCAT).
 */
class CatalogoEquiposPc
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Ubicaciones $ubicaciones,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * @param  Builder<EquipoPc>  $consulta
     * @return Builder<EquipoPc>
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
        if ($efectivo->alcance === Alcance::Empresa) {
            return $consulta;
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->whereIn('equipos_pc.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('equipos_pc.creado_por', $actor->id));
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todos los que ve.
     *
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin) {
            return null;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso];
        if ($efectivo->alcance === Alcance::Empresa || ($this->autorizador->sedesPermitidas($actor, $permiso) === null && $efectivo->alcance !== Alcance::Propios)) {
            return null;
        }

        return $this->limitar(EquipoPc::query(), $actor, $permiso)->pluck('equipos_pc.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Sedes activas donde el actor puede usar el permiso.
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permiso): Collection
    {
        if (! $actor->can($permiso)) {
            return collect();
        }
        $permitidas = $this->autorizador->sedesPermitidas($actor, $permiso);

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre']);
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): EquipoPc
    {
        return DB::transaction(function () use ($actor, $entrada) {
            $equipo = new EquipoPc($this->validar($actor, $entrada, null));
            $equipo->save();
            $this->auditoria->auditar($actor, 'recorridos_pc.creado', $equipo, null, $this->foto($equipo));

            return $equipo;
        });
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, EquipoPc $equipo, array $entrada): EquipoPc
    {
        return DB::transaction(function () use ($actor, $equipo, $entrada) {
            $antes = $this->foto($equipo);
            $equipo->fill($this->validar($actor, $entrada, $equipo))->save();
            $this->auditoria->auditar($actor, 'recorridos_pc.actualizado', $equipo, $antes, $this->foto($equipo));

            return $equipo;
        });
    }

    /** DE BAJA (SEGCAT: estatus 0): ya no aparece como pendiente ni se puede inspeccionar. */
    public function desactivar(User $actor, EquipoPc $equipo): void
    {
        if (! $equipo->activo) {
            throw ValidationException::withMessages(['activo' => 'Este equipo ya está dado de baja.']);
        }
        $equipo->forceFill(['activo' => false])->save();
        $this->auditoria->auditar($actor, 'recorridos_pc.desactivado', $equipo, ['activo' => true], ['activo' => false]);
    }

    public function reactivar(User $actor, EquipoPc $equipo): void
    {
        if ($equipo->activo) {
            throw ValidationException::withMessages(['activo' => 'Este equipo ya está activo.']);
        }
        $duplicado = EquipoPc::where('sede_id', $equipo->sede_id)->where('numero_serie', $equipo->numero_serie)->whereKeyNot($equipo->id)->exists();
        if ($duplicado) {
            throw ValidationException::withMessages(['activo' => "Ya hay otro equipo con el ID «{$equipo->numero_serie}» en esta sede."]);
        }
        $equipo->forceFill(['activo' => true])->save();
        $this->auditoria->auditar($actor, 'recorridos_pc.reactivado', $equipo, ['activo' => false], ['activo' => true]);
    }

    // --------------------------------------------------------------- Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function validar(User $actor, array $entrada, ?EquipoPc $actual): array
    {
        $entrada = $this->normalizar($entrada);

        $datos = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'categoria' => ['required', 'string', 'in:'.implode(',', array_keys(EquipoPc::CATEGORIAS))],
            'numero_serie' => ['required', 'string', 'max:100'],
            'zona_id' => ['nullable', 'integer'],
            'area_especifica_id' => ['nullable', 'integer'],
            'referencia' => ['nullable', 'string', 'max:150'],
            'etiqueta_nfc' => ['nullable', 'string', 'max:64'],
        ], [
            'sede_id.required' => 'Elige la sede donde está el equipo.',
            'categoria.required' => 'Elige el tipo de equipo.',
            'categoria.in' => 'Elige un tipo de equipo de la lista.',
            'numero_serie.required' => 'El Núm. de Serie / ID es obligatorio (es lo que va en la etiqueta, por ejemplo EXT-01).',
            'numero_serie.max' => 'El Núm. de Serie / ID admite máximo 100 caracteres.',
            'referencia.max' => 'La referencia admite máximo 150 caracteres.',
            'etiqueta_nfc.max' => 'La etiqueta NFC / RFID admite máximo 64 caracteres.',
        ])->validate();

        $sedeId = $this->sedeValida((int) $datos['sede_id'], $actor, $actual);
        $serie = $datos['numero_serie'];

        $existente = EquipoPc::where('sede_id', $sedeId)->where('numero_serie', $serie)
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->first();
        if ($existente !== null) {
            throw ValidationException::withMessages(['numero_serie' => "El Núm. de Serie / ID «{$serie}» ya está registrado en esta sede ({$existente->etiquetaCategoria()})"
                .($existente->activo ? '.' : ', dado de baja: reactívalo en lugar de registrarlo otra vez.')]);
        }

        $etiqueta = $datos['etiqueta_nfc'] ?? null;
        if ($etiqueta !== null) {
            $this->etiquetaLibre($etiqueta, $actual);
        }

        return [
            'sede_id' => $sedeId,
            'categoria' => $datos['categoria'],
            'numero_serie' => $serie,
            'espacio_id' => $this->espacioValido($sedeId, $datos['zona_id'] ?? null, $datos['area_especifica_id'] ?? null, $actual),
            'referencia' => $datos['referencia'] ?? null,
            'etiqueta_nfc' => $etiqueta,
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada): array
    {
        $entrada = array_intersect_key($entrada, array_flip(['sede_id', 'categoria', 'numero_serie', 'zona_id', 'area_especifica_id', 'referencia', 'etiqueta_nfc']));
        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $entrada[$campo] = trim((string) preg_replace('/\s+/u', ' ', $valor));
            }
        }
        if (isset($entrada['numero_serie']) && is_string($entrada['numero_serie'])) {
            $entrada['numero_serie'] = EquipoPc::normalizarSerie($entrada['numero_serie']);
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $entrada);
    }

    /**
     * La sede debe ser activa y de su alcance. Al editar se conserva la actual.
     */
    private function sedeValida(int $sedeId, User $actor, ?EquipoPc $actual): int
    {
        if ($actual !== null && $sedeId === (int) $actual->sede_id) {
            return $sedeId;
        }
        $permiso = $actual === null ? 'equipos.crear' : 'equipos.editar';
        if (! $this->sedesParaElegir($actor, $permiso)->contains('id', $sedeId)) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
        }

        return $sedeId;
    }

    /**
     * Ubicación (SEGCAT: edificio → sección → área específica): la zona o
     * piso y, opcional, el área específica que cuelga de ella; todo de la
     * misma sede y activo. Lo que ya tenía el equipo se conserva aunque hoy
     * esté desactivado.
     */
    private function espacioValido(int $sedeId, mixed $zonaId, mixed $areaId, ?EquipoPc $actual): ?int
    {
        $zonaId = $zonaId === null ? null : (int) $zonaId;
        $areaId = $areaId === null ? null : (int) $areaId;
        $id = $areaId ?? $zonaId;
        if ($id === null) {
            return null;
        }
        if ($actual !== null && $id === (int) $actual->espacio_id && $sedeId === (int) $actual->sede_id) {
            return $id;
        }

        $nodo = Espacio::where('sede_id', $sedeId)->where('activo', true)->whereIn('nivel', Ubicaciones::NIVELES)->find($id);
        $valido = $nodo !== null && ($areaId === null
            ? in_array($nodo->nivel, [Espacio::EDIFICIO, Espacio::AREA], true)
            : $nodo->nivel === Espacio::AREA_ESPECIFICA && ($zonaId === null || in_array($zonaId, $nodo->idsAncestros(), true)));
        if (! $valido) {
            throw ValidationException::withMessages(['zona_id' => 'La ubicación elegida (zona, piso o área) no pertenece a la sede o está desactivada.']);
        }

        return $nodo->id;
    }

    /**
     * La etiqueta NFC/RFID no puede estar en otro registro de la empresa
     * (equipo, llave, gafete, colaborador…): el lector no sabría cuál es.
     */
    private function etiquetaLibre(string $etiqueta, ?EquipoPc $actual): void
    {
        foreach (config('lector.tipos', []) as $tipo => $clase) {
            if (! is_subclass_of($clase, Identificable::class) || ! method_exists($clase, 'etiquetaOcupada')) {
                continue;
            }
            $ocupada = $clase::etiquetaOcupada($etiqueta, $clase === EquipoPc::class ? $actual?->id : null);
            if ($ocupada !== null) {
                $quien = $ocupada->resumenLector()['titulo'];
                $que = ['vehiculo' => 'Vehículo', 'equipo_pc' => 'Equipo de Protección Civil'][$tipo] ?? ucfirst(str_replace('_', ' ', (string) $tipo));
                throw ValidationException::withMessages(['etiqueta_nfc' => "Esa etiqueta NFC / RFID ya está asignada a otro registro: {$que} «{$quien}». Usa otra etiqueta o quítasela primero a ese registro."]);
            }
        }
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Valores para llenar el diálogo de edición.
     *
     * @param  Collection<int, array<string, mixed>>  $nodos  Ubicaciones::nodos()
     * @return array<string, mixed>
     */
    public function valoresEdicion(EquipoPc $e, Collection $nodos): array
    {
        $nodo = $e->espacio_id !== null ? $nodos->get($e->espacio_id) : null;
        $esArea = $nodo !== null && $nodo['nivel'] === Espacio::AREA_ESPECIFICA;

        return [
            'sede_id' => $e->sede_id, 'categoria' => $e->categoria, 'numero_serie' => $e->numero_serie,
            'zona_id' => $esArea ? (end($nodo['ancestros']) ?: null) : $e->espacio_id,
            'area_especifica_id' => $esArea ? $e->espacio_id : null,
            'referencia' => $e->referencia, 'etiqueta_nfc' => $e->etiqueta_nfc,
        ];
    }

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(EquipoPc $e): array
    {
        return $e->only(['sede_id', 'categoria', 'numero_serie', 'espacio_id', 'referencia', 'etiqueta_nfc', 'activo']);
    }
}
