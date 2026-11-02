<?php

namespace App\Services\Espacios;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Services\Borrado\BorradoSeguro;
use App\Services\Borrado\RegistroBorrado;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Espacios\Etiquetas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del árbol de zonas y áreas:
 *  - cada nivel solo cuelga de los niveles permitidos (Espacio::PADRES);
 *  - padre, sede, tipo y sección pertenecen a la misma empresa y sede;
 *  - no hay dos hermanos con el mismo nombre;
 *  - la ruta materializada se calcula al crear;
 *  - desactivar o reactivar arrastra a todo lo que cuelga del nodo;
 *  - mover cambia el lugar (padre, ruta y profundidad) sin cambiar el id,
 *    así llaves, equipos, recorridos e historial siguen apuntando a él;
 *  - eliminar varias usa el mismo borrado seguro que el botón individual.
 */
class AdministradorEspacios
{
    /** Tope por lote (más suele ser un error de captura). */
    public const MAX_LOTE = 500;

    /** Tope de "Eliminar definitivamente las marcadas" por envío. */
    public const MAX_ELIMINAR = 200;

    /** Tope de lugares que se ofrecen en "Mover a…". */
    public const MAX_DESTINOS = 3000;

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly BorradoSeguro $borrado,
    ) {}

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
            // Revisión Etapa 5: vivienda o área común (solo el nivel «área específica»)
            if ($nivel === Espacio::AREA_ESPECIFICA && array_key_exists('area_comun', $datos)) {
                $espacio->forceFill(['uso' => filter_var($datos['area_comun'], FILTER_VALIDATE_BOOL) ? Espacio::USO_AREA_COMUN : null]);
            }
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
        ]);
        // Revisión Etapa 5: vivienda o área común (sin el campo, se conserva)
        if ($espacio->nivel === Espacio::AREA_ESPECIFICA && array_key_exists('area_comun', $datos)) {
            $espacio->forceFill(['uso' => filter_var($datos['area_comun'], FILTER_VALIDATE_BOOL) ? Espacio::USO_AREA_COMUN : null]);
        }
        $espacio->save();

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

    /**
     * Lugares a los que se puede mover el espacio: activos, de su misma sede,
     * del nivel permitido (Espacio::PADRES), sin él, sin lo que tiene dentro y
     * sin su lugar actual. Cada uno con su ruta legible ("Torre A › Piso 2").
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function destinos(Espacio $espacio): array
    {
        $niveles = array_values(array_filter(Espacio::PADRES[$espacio->nivel] ?? []));
        if ($espacio->padre_id === null || $niveles === []) {
            return [];
        }

        $candidatos = Espacio::where('sede_id', $espacio->sede_id)
            ->whereIn('nivel', $niveles)
            ->where('activo', true)
            ->whereKeyNot($espacio->id)
            ->whereKeyNot($espacio->padre_id)
            ->where('ruta', 'not like', $espacio->ruta.'%')
            ->limit(self::MAX_DESTINOS)
            ->get(['id', 'nombre', 'ruta']);

        $nombres = $this->nombresDeAncestros($candidatos);

        return $candidatos
            ->map(fn (Espacio $e) => ['id' => (int) $e->id, 'nombre' => $this->rutaLegible($e, $nombres)])
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Mover un espacio (con todo lo que tiene dentro) a otro lugar de su misma sede.
     */
    public function mover(User $actor, Espacio $espacio, Espacio $destino): Espacio
    {
        $etiquetas = Etiquetas::para(Empresa::with('rubro')->find($espacio->empresa_id));

        if ($espacio->padre_id === null) {
            throw ValidationException::withMessages(['destino_id' => 'Una zona o edificio no se mueve: depende directamente de la sede.']);
        }
        if ((int) $destino->sede_id !== (int) $espacio->sede_id || (int) $destino->empresa_id !== (int) $espacio->empresa_id) {
            throw ValidationException::withMessages(['destino_id' => 'Solo se puede mover dentro de la misma sede.']);
        }
        if ((int) $destino->id === (int) $espacio->id || str_starts_with((string) $destino->ruta, (string) $espacio->ruta)) {
            throw ValidationException::withMessages(['destino_id' => "No se puede mover «{$espacio->nombre}» dentro de sí mismo ni dentro de lo que tiene adentro."]);
        }
        if (! in_array($destino->nivel, Espacio::PADRES[$espacio->nivel] ?? [], true)) {
            $permitidos = collect(Espacio::PADRES[$espacio->nivel] ?? [])->filter()
                ->map(fn ($n) => mb_strtolower($etiquetas[$n]['singular']))->implode(' o ');
            throw ValidationException::withMessages(['destino_id' => "«{$espacio->nombre}» solo puede ir dentro de: {$permitidos}."]);
        }
        if (! $destino->activo) {
            throw ValidationException::withMessages(['destino_id' => "«{$destino->nombre}» está desactivado: reactívalo o elige otro lugar."]);
        }
        if ((int) $destino->id === (int) $espacio->padre_id) {
            throw ValidationException::withMessages(['destino_id' => "«{$espacio->nombre}» ya está en «{$destino->nombre}»."]);
        }

        $donde = $this->demostrativo($etiquetas[$destino->nivel]['singular']);
        $hermanos = Espacio::where('sede_id', $destino->sede_id)->where('padre_id', $destino->id)->get(['id', 'nombre', 'codigo', 'nivel']);
        if ($hermanos->contains(fn ($h) => mb_strtolower(trim($h->nombre)) === mb_strtolower(trim($espacio->nombre)))) {
            throw ValidationException::withMessages(['destino_id' => "Ya existe «{$espacio->nombre}» en {$donde}."]);
        }
        if ($espacio->codigo !== null && $hermanos->contains(fn ($h) => $h->nivel === $espacio->nivel && $h->codigo !== null
            && mb_strtoupper($h->codigo) === mb_strtoupper($espacio->codigo))) {
            throw ValidationException::withMessages(['destino_id' => "Ya existe el código «{$espacio->codigo}» en {$donde}."]);
        }

        $antes = ['padre_id' => $espacio->padre_id, 'lugar' => $this->lugar($espacio)];
        $rutaVieja = (string) $espacio->ruta;
        $rutaNueva = $destino->ruta.$espacio->id.'/';
        $diferencia = ($destino->profundidad + 1) - $espacio->profundidad;

        DB::transaction(function () use ($actor, $espacio, $destino, $rutaVieja, $rutaNueva, $diferencia) {
            Espacio::where('ruta', 'like', $rutaVieja.'%')->whereKeyNot($espacio->id)->orderBy('id')
                ->get(['id', 'ruta', 'profundidad'])
                ->each(fn (Espacio $d) => Espacio::whereKey($d->id)->update([
                    'ruta' => $rutaNueva.substr((string) $d->ruta, strlen($rutaVieja)),
                    'profundidad' => $d->profundidad + $diferencia,
                ]));

            $espacio->forceFill([
                'padre_id' => $destino->id,
                'ruta' => $rutaNueva,
                'profundidad' => $destino->profundidad + 1,
                'actualizado_por' => $actor->id,
            ])->save();
        });

        $espacio->unsetRelation('padre');
        $this->auditoria->auditar($actor, 'espacios.movido', $espacio, $antes, ['padre_id' => $destino->id, 'lugar' => $this->lugar($espacio)]);

        return $espacio;
    }

    /**
     * "Eliminar definitivamente" varios espacios que cuelgan de $padre. Cada
     * uno se borra con todo lo que tiene dentro (de lo más profundo hacia
     * arriba) usando BorradoSeguro, con su copia en la auditoría; si él o algo
     * de lo que tiene dentro está en uso, no se borra nada de ese espacio y se
     * dice por qué.
     *
     * @param  list<int>  $ids
     * @return array{eliminadas: list<string>, fallidas: list<string>}
     */
    public function eliminarVarios(User $actor, Espacio $padre, array $ids): array
    {
        $definicion = RegistroBorrado::de('espacios');
        $espacios = Espacio::whereIn('id', array_slice(array_values(array_unique($ids)), 0, self::MAX_ELIMINAR))
            ->where('padre_id', $padre->id)->orderBy('nombre')->get();

        $resultado = ['eliminadas' => [], 'fallidas' => []];
        foreach ($espacios as $espacio) {
            if (! $actor->can('espacios.borrar', $espacio)) {
                $resultado['fallidas'][] = "{$espacio->nombre}: está fuera de tu alcance";

                continue;
            }

            $actual = $espacio;
            try {
                DB::transaction(function () use ($actor, $definicion, $espacio, &$actual) {
                    $dentro = Espacio::where('ruta', 'like', $espacio->ruta.'%')->whereKeyNot($espacio->id)
                        ->orderByDesc('profundidad')->orderBy('id')->get();
                    foreach ([...$dentro->all(), $espacio] as $nodo) {
                        $actual = $nodo;
                        $this->borrado->eliminar($actor, $definicion, $nodo);
                    }
                });
                $resultado['eliminadas'][] = $espacio->nombre;
            } catch (\DomainException $e) {
                $resultado['fallidas'][] = $this->motivo($espacio, $actual, $e->getMessage());
            }
        }

        return $resultado;
    }

    /**
     * "101: tiene 1 llave que la abre (HDC-101)" o, si lo que se usa está
     * dentro, "101: dentro, «Baño» tiene 1 llave que la abre (HDC-101)".
     */
    private function motivo(Espacio $espacio, Espacio $usado, string $mensaje): string
    {
        $razon = preg_replace('/: no se puede eliminar.*$/u', '', $mensaje);
        $razon = mb_strtolower(mb_substr($razon, 0, 1)).mb_substr($razon, 1);

        $llaves = DB::table('espacio_llave')->join('llaves', 'llaves.id', '=', 'espacio_llave.llave_id')
            ->where('espacio_llave.espacio_id', $usado->id)->orderBy('llaves.nomenclatura')->limit(3)->pluck('llaves.nomenclatura');
        if ($llaves->isNotEmpty()) {
            $razon .= ' ('.$llaves->implode(', ').')';
        }

        return $usado->is($espacio)
            ? "{$espacio->nombre}: {$razon}"
            : "{$espacio->nombre}: dentro, «{$usado->nombre}» {$razon}";
    }

    /**
     * "Torre A › Piso 2 › 101" (sin la sede).
     */
    private function lugar(Espacio $espacio): string
    {
        $padre = $espacio->padre_id === null ? null : Espacio::find($espacio->padre_id, ['id', 'nombre', 'ruta']);
        if ($padre === null) {
            return '';
        }

        return $this->rutaLegible($padre, $this->nombresDeAncestros(collect([$padre])));
    }

    /**
     * @param  Collection<int, Espacio>  $espacios
     * @return array<int, string>
     */
    private function nombresDeAncestros($espacios): array
    {
        $ids = $espacios->flatMap(fn (Espacio $e) => $e->idsAncestros())->unique()->values()->all();

        return $ids === [] ? [] : Espacio::whereIn('id', $ids)->pluck('nombre', 'id')->all();
    }

    /**
     * @param  array<int, string>  $nombres
     */
    private function rutaLegible(Espacio $espacio, array $nombres): string
    {
        $partes = array_map(fn ($id) => $nombres[$id] ?? '…', $espacio->idsAncestros());
        $partes[] = $espacio->nombre;

        return implode(' › ', $partes);
    }

    /**
     * "ese piso", "esa habitación", "esa zona / edificio".
     */
    private function demostrativo(string $singular): string
    {
        $palabra = mb_strtolower(explode(' ', $singular)[0]);
        $femenino = preg_match('/(a|ón|ad)$/u', $palabra) === 1 || $palabra === 'área';

        return ($femenino ? 'esa ' : 'ese ').mb_strtolower($singular);
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
            ->first(['id', 'activo']);

        if ($duplicado !== null) {
            // Ronda 5 (Z-02): si está desactivado, se dice para reactivarlo en lugar de duplicarlo
            throw ValidationException::withMessages(['nombre' => $duplicado->activo
                ? "Ya existe «{$nombre}» en este mismo lugar."
                : "Ya existe «{$nombre}» en este mismo lugar, pero está desactivado: reactívalo (flecha verde) en lugar de crearlo de nuevo."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(Espacio $espacio): array
    {
        return $espacio->only(['sede_id', 'padre_id', 'nivel', 'uso', 'nombre', 'codigo', 'tipo_espacio_id', 'grupo_espacio_id', 'activo']);
    }
}
