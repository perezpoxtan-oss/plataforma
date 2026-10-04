<?php

namespace App\Services\Espacios;

use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del árbol de zonas y áreas:
 *  - cada nivel solo cuelga de los niveles permitidos (Espacio::PADRES);
 *  - padre, sede, tipo y sección pertenecen a la misma empresa y sede;
 *  - no hay dos hermanos con el mismo nombre;
 *  - la ruta materializada se calcula al crear;
 *  - desactivar o reactivar arrastra a todo lo que cuelga del nodo.
 */
class AdministradorEspacios
{
    /** Tope por lote (más suele ser un error de captura). */
    public const MAX_LOTE = 500;

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /**
     * @param  array{nombre: string, codigo?: ?string, tipo_espacio_id?: ?int, grupo_espacio_id?: ?int}  $datos
     */
    public function crear(User $actor, Sede $sede, ?Espacio $padre, string $nivel, array $datos, bool $auditar = true): Espacio
    {
        $this->validarUbicacion($sede, $padre, $nivel);
        $this->validarTipoYGrupo($sede, $nivel, $datos);
        $datos['nombre'] = $this->nombreODelTipo($sede, $padre, $datos);
        $this->exigirNombreLibre($sede, $padre, $datos['nombre']);

        $espacio = DB::transaction(function () use ($sede, $padre, $nivel, $datos) {
            $espacio = new Espacio;
            $espacio->fill([
                'empresa_id' => $sede->empresa_id,
                'sede_id' => $sede->id,
                'padre_id' => $padre?->id,
                'nivel' => $nivel,
                'nombre' => $datos['nombre'],
                'codigo' => $datos['codigo'] ?? null,
                'tipo_espacio_id' => $datos['tipo_espacio_id'] ?? null,
                'grupo_espacio_id' => $datos['grupo_espacio_id'] ?? null,
            ]);
            $espacio->save();

            $espacio->forceFill([
                'ruta' => ($padre?->ruta ?? '/').$espacio->id.'/',
                'profundidad' => $padre === null ? 0 : $padre->profundidad + 1,
            ])->save();

            return $espacio;
        });

        if ($auditar) {
            $this->auditoria->auditar($actor, 'espacios.creado', $espacio, null, $this->foto($espacio));
        }

        return $espacio;
    }

    /**
     * Varias habitaciones (o áreas específicas) de una vez en un piso.
     * Los nombres que ya existen se omiten, no detienen el lote.
     *
     * @param  list<string>  $nombres
     * @return array{creadas: int, omitidas: int}
     */
    public function crearLote(User $actor, Espacio $padre, array $nombres, ?int $grupoId, ?int $tipoId): array
    {
        $nombres = array_slice(array_values(array_unique(array_filter(array_map('trim', $nombres)))), 0, self::MAX_LOTE);
        $sede = $padre->sede;
        $existentes = Espacio::where('padre_id', $padre->id)->pluck('nombre')->map(fn ($n) => mb_strtolower($n))->all();

        $creadas = 0;
        DB::transaction(function () use ($actor, $padre, $sede, $nombres, $existentes, $grupoId, $tipoId, &$creadas) {
            foreach ($nombres as $nombre) {
                if (in_array(mb_strtolower($nombre), $existentes, true)) {
                    continue;
                }
                $this->crear($actor, $sede, $padre, Espacio::AREA_ESPECIFICA, [
                    'nombre' => mb_substr($nombre, 0, 100), 'grupo_espacio_id' => $grupoId, 'tipo_espacio_id' => $tipoId,
                ], false);
                $existentes[] = mb_strtolower($nombre);
                $creadas++;
            }
        });

        $this->auditoria->auditar($actor, 'espacios.lote', $padre, null, ['creadas' => $creadas, 'nombres' => $nombres]);

        return ['creadas' => $creadas, 'omitidas' => count($nombres) - $creadas];
    }

    /**
     * Copia los pisos activos de otro edificio de la misma sede (o empresa),
     * sin duplicar los que el destino ya tenga.
     */
    public function copiarPisos(User $actor, Espacio $origen, Espacio $destino): int
    {
        if ($origen->nivel !== Espacio::EDIFICIO || $destino->nivel !== Espacio::EDIFICIO || $origen->empresa_id !== $destino->empresa_id) {
            throw ValidationException::withMessages(['origen' => 'Solo se pueden copiar pisos entre edificios de la misma empresa.']);
        }

        $existentes = Espacio::where('padre_id', $destino->id)->pluck('nombre')->map(fn ($n) => mb_strtolower($n))->all();
        $pisos = Espacio::where('padre_id', $origen->id)->where('nivel', Espacio::AREA)->where('activo', true)->orderBy('orden')->orderBy('id')->get();
        $sede = $destino->sede;

        $copiados = 0;
        DB::transaction(function () use ($actor, $pisos, $existentes, $sede, $destino, &$copiados) {
            foreach ($pisos as $piso) {
                if (in_array(mb_strtolower($piso->nombre), $existentes, true)) {
                    continue;
                }
                $this->crear($actor, $sede, $destino, Espacio::AREA, ['nombre' => $piso->nombre, 'tipo_espacio_id' => $piso->tipo_espacio_id], false);
                $copiados++;
            }
        });

        $this->auditoria->auditar($actor, 'espacios.pisos_copiados', $destino, null, ['desde' => $origen->id, 'copiados' => $copiados]);

        return $copiados;
    }

    /**
     * @param  array{nombre: string, codigo?: ?string, tipo_espacio_id?: ?int, grupo_espacio_id?: ?int}  $datos
     */
    public function actualizar(User $actor, Espacio $espacio, array $datos): Espacio
    {
        $sede = $espacio->sede;
        $this->validarTipoYGrupo($sede, $espacio->nivel, $datos);
        $datos['nombre'] = $this->nombreODelTipo($sede, $espacio->padre, $datos, $espacio->id);
        $this->exigirNombreLibre($sede, $espacio->padre, $datos['nombre'], $espacio->id);

        $antes = $this->foto($espacio);
        $espacio->fill([
            'nombre' => $datos['nombre'],
            'codigo' => $datos['codigo'] ?? null,
            'tipo_espacio_id' => $datos['tipo_espacio_id'] ?? null,
            'grupo_espacio_id' => $espacio->nivel === Espacio::AREA_ESPECIFICA ? ($datos['grupo_espacio_id'] ?? null) : null,
        ])->save();

        $this->auditoria->auditar($actor, 'espacios.actualizado', $espacio, $antes, $this->foto($espacio));

        return $espacio;
    }

    /**
     * Desactivar o reactivar un nodo y todo lo que cuelga de él.
     */
    public function cambiarEstado(User $actor, Espacio $espacio, bool $activo): int
    {
        if ($activo && $espacio->padre_id !== null && ! $espacio->padre->activo) {
            throw ValidationException::withMessages(['activo' => "Primero reactiva «{$espacio->padre->nombre}», del que depende."]);
        }

        $afectados = Espacio::where('ruta', 'like', $espacio->ruta.'%')->update(['activo' => $activo, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
        $this->auditoria->auditar($actor, $activo ? 'espacios.reactivado' : 'espacios.desactivado', $espacio, ['activo' => ! $activo], ['activo' => $activo, 'afectados' => $afectados]);

        return $afectados;
    }

    public function crearTipo(User $actor, int $empresaId, string $nivel, string $nombre): TipoEspacio
    {
        $nombre = trim($nombre);
        if (! array_key_exists($nivel, Espacio::PADRES) || $nombre === '') {
            throw ValidationException::withMessages(['nombre' => 'Indica un nombre y un nivel válidos.']);
        }

        $existente = TipoEspacio::where('nivel', $nivel)
            ->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresaId))
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $tipo = TipoEspacio::create(['empresa_id' => $empresaId, 'nivel' => $nivel, 'nombre' => mb_substr($nombre, 0, 60)]);
        $this->auditoria->auditar($actor, 'espacios.tipo_creado', $tipo, null, $tipo->only(['nivel', 'nombre']));

        return $tipo;
    }

    public function crearGrupo(User $actor, Sede $sede, string $nombre): GrupoEspacio
    {
        $nombre = trim($nombre);
        $duplicado = GrupoEspacio::withoutGlobalScopes()->where('sede_id', $sede->id)->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists();
        if ($nombre === '' || $duplicado) {
            throw ValidationException::withMessages(['nombre' => $nombre === '' ? 'Escribe el nombre de la sección.' : 'Ya existe una sección con ese nombre en esta sede.']);
        }

        $grupo = new GrupoEspacio;
        $grupo->forceFill(['empresa_id' => $sede->empresa_id, 'sede_id' => $sede->id, 'nombre' => mb_substr($nombre, 0, 50)])->save();
        $this->auditoria->auditar($actor, 'espacios.seccion_creada', $grupo, null, $grupo->only(['sede_id', 'nombre']));

        return $grupo;
    }

    /**
     * Deja en la sección exactamente las habitaciones indicadas (de su misma sede).
     *
     * @param  list<int>  $ids
     */
    public function asignarGrupo(User $actor, GrupoEspacio $grupo, array $ids): int
    {
        $antes = Espacio::where('grupo_espacio_id', $grupo->id)->pluck('id')->all();
        $validos = Espacio::whereIn('id', $ids)->where('sede_id', $grupo->sede_id)->where('nivel', Espacio::AREA_ESPECIFICA)->pluck('id')->all();

        DB::transaction(function () use ($grupo, $validos, $actor) {
            Espacio::where('grupo_espacio_id', $grupo->id)->whereNotIn('id', $validos)
                ->update(['grupo_espacio_id' => null, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
            Espacio::whereIn('id', $validos)->update(['grupo_espacio_id' => $grupo->id, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
        });

        $this->auditoria->auditar($actor, 'espacios.seccion_asignada', $grupo, ['espacios' => $antes], ['espacios' => $validos]);

        return count($validos);
    }

    private function validarUbicacion(Sede $sede, ?Espacio $padre, string $nivel): void
    {
        if (! array_key_exists($nivel, Espacio::PADRES)) {
            throw ValidationException::withMessages(['nivel' => 'Nivel de espacio no válido.']);
        }

        if (! in_array($padre?->nivel, Espacio::PADRES[$nivel], true)) {
            throw ValidationException::withMessages(['nivel' => 'Ese tipo de espacio no puede ir dentro de este nivel.']);
        }

        if ($padre !== null && ((int) $padre->sede_id !== (int) $sede->id || (int) $padre->empresa_id !== (int) $sede->empresa_id)) {
            throw ValidationException::withMessages(['padre_id' => 'El espacio superior pertenece a otra sede.']);
        }

        if ($padre !== null && ! $padre->activo) {
            throw ValidationException::withMessages(['padre_id' => "«{$padre->nombre}» está desactivado: reactívalo antes de agregarle espacios."]);
        }
    }

    private function validarTipoYGrupo(Sede $sede, string $nivel, array $datos): void
    {
        if (! empty($datos['tipo_espacio_id'])) {
            $valido = TipoEspacio::whereKey($datos['tipo_espacio_id'])->where('nivel', $nivel)
                ->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $sede->empresa_id))->exists();
            if (! $valido) {
                throw ValidationException::withMessages(['tipo_espacio_id' => 'El tipo elegido no corresponde a este nivel.']);
            }
        }

        if (! empty($datos['grupo_espacio_id'])) {
            $valido = $nivel === Espacio::AREA_ESPECIFICA
                && GrupoEspacio::withoutGlobalScopes()->whereKey($datos['grupo_espacio_id'])->where('sede_id', $sede->id)->exists();
            if (! $valido) {
                throw ValidationException::withMessages(['grupo_espacio_id' => 'La sección elegida no pertenece a esta sede.']);
            }
        }
    }

    /**
     * Si no se escribe nombre se toma el del tipo y se numera cuando ya
     * hay uno igual en el mismo lugar: "Cama", "Cama 2", "Cama 3"…
     */
    private function nombreODelTipo(Sede $sede, ?Espacio $padre, array $datos, ?int $excepto = null): string
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre !== '') {
            return $nombre;
        }

        $base = empty($datos['tipo_espacio_id']) ? null : TipoEspacio::whereKey($datos['tipo_espacio_id'])->value('nombre');
        if ($base === null) {
            throw ValidationException::withMessages(['nombre' => 'Escribe un nombre o elige un tipo.']);
        }

        $usados = Espacio::withoutGlobalScopes()->where('sede_id', $sede->id)
            ->when($padre === null, fn ($q) => $q->whereNull('padre_id'), fn ($q) => $q->where('padre_id', $padre->id))
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))
            ->pluck('nombre')->map(fn ($n) => mb_strtolower($n))->all();

        $candidato = $base;
        for ($n = 2; in_array(mb_strtolower($candidato), $usados, true); $n++) {
            $candidato = $base.' '.$n;
        }

        return $candidato;
    }

    private function exigirNombreLibre(Sede $sede, ?Espacio $padre, string $nombre, ?int $excepto = null): void
    {
        $duplicado = Espacio::withoutGlobalScopes()
            ->where('sede_id', $sede->id)
            ->when($padre === null, fn ($q) => $q->whereNull('padre_id'), fn ($q) => $q->where('padre_id', $padre->id))
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($nombre))])
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))
            ->exists();

        if ($duplicado) {
            throw ValidationException::withMessages(['nombre' => "Ya existe «{$nombre}» en este mismo lugar."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(Espacio $espacio): array
    {
        return $espacio->only(['sede_id', 'padre_id', 'nivel', 'nombre', 'codigo', 'tipo_espacio_id', 'grupo_espacio_id', 'activo']);
    }
}
