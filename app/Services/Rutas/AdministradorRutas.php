<?php

namespace App\Services\Rutas;

use App\Models\Paradero;
use App\Models\Proveedor;
use App\Models\Ruta;
use App\Models\RutaHorario;
use App\Models\RutaParada;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de Rutas de transporte (SEGCAT: ruta_proceso.php y paradero_proceso.php).
 *
 * Todo es POR SEDE: la ruta, su turno (uno que se use en esa sede), su
 * transportista (un proveedor activo que opere en esa sede) y sus paraderos
 * (catálogo propio de la sede). El alcance de sede del usuario decide en qué
 * sedes ve, crea, edita o desactiva; con alcance "propios", solo las rutas y
 * paraderos que él dio de alta (Autorizador::puede con el registro).
 *
 * Todas las consultas corren con la empresa de trabajo fijada en el Tenant.
 */
class AdministradorRutas
{
    public const HORA = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    public const MAX_HORARIOS = 20;

    public const MAX_PARADEROS = 40;

    /** Proveedores que normalmente dan el servicio: van primero en la lista. */
    public const CATEGORIAS_TRANSPORTE = ['transporte_personal', 'transporte_huespedes', 'transportadora', 'taxi'];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Sedes en las que el usuario usa el permiso: null = todas.
     *
     * @return list<int>|null
     */
    public function sedesPermitidas(User $usuario, string $permiso): ?array
    {
        return $this->autorizador->sedesPermitidas($usuario, $permiso);
    }

    /**
     * Sedes activas que el usuario ve en Rutas.
     *
     * @return Collection<int, Sede>
     */
    public function sedesVisibles(User $usuario): Collection
    {
        $permitidas = $this->sedesPermitidas($usuario, 'rutas.ver');

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->with('empresa:id,zona_horaria')
            ->orderBy('nombre')
            ->get(['id', 'empresa_id', 'nombre', 'codigo', 'zona_horaria']);
    }

    public function puedeEnSede(User $usuario, string $permiso, int $sedeId): bool
    {
        if (! $usuario->can($permiso)) {
            return false;
        }
        $permitidas = $this->sedesPermitidas($usuario, $permiso);

        return $permitidas === null || in_array($sedeId, $permitidas, true);
    }

    /**
     * ¿Puede tocar este registro? Revisa sede y, con alcance "propios", autor.
     */
    public function puede(User $usuario, string $permiso, Ruta|Paradero $registro): bool
    {
        return $this->autorizador->puede($usuario, $permiso, $registro);
    }

    // ------------------------------------------------------------- Opciones

    /**
     * Turnos que se pueden elegir en la sede (activos y que se usan ahí), más
     * los que ya tienen sus rutas aunque hoy no cumplan (para no perderlos al editar).
     *
     * @param  list<int>  $enUso
     * @return Collection<int, Turno>
     */
    public function turnosPara(Sede $sede, array $enUso = []): Collection
    {
        $elegibles = Turno::where('activo', true)->aplicanEn([$sede->id])->pluck('id')->map(fn ($id) => (int) $id)->all();

        // "elegible": se puede escoger en un alta; los demás solo conservan el valor de una edición
        return Turno::whereIn('id', [...$elegibles, ...$enUso])
            ->orderBy('hora_inicio')->orderBy('nombre')
            ->get(['id', 'nombre', 'hora_inicio', 'hora_fin', 'activo'])
            ->each(fn (Turno $t) => $t->setAttribute('elegible', in_array($t->id, $elegibles, true)));
    }

    /**
     * Transportistas: proveedores activos que operan en la sede, más los que
     * ya tienen sus rutas. Primero los de transporte.
     *
     * @param  list<int>  $enUso
     * @return Collection<int, Proveedor>
     */
    public function proveedoresPara(Sede $sede, array $enUso = []): Collection
    {
        $elegibles = Proveedor::where('activo', true)->operanEn([$sede->id])->pluck('proveedores.id')->map(fn ($id) => (int) $id)->all();

        return Proveedor::whereIn('id', [...$elegibles, ...$enUso])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'activo'])
            ->each(fn (Proveedor $p) => $p->setAttribute('elegible', in_array($p->id, $elegibles, true)))
            ->sortBy(fn ($p) => in_array($p->categoria, self::CATEGORIAS_TRANSPORTE, true) ? 0 : 1)
            ->values();
    }

    // ----------------------------------------------------------------- Rutas

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, Sede $sede, array $entrada): Ruta
    {
        [$datos, $horarios] = $this->validar($entrada, $sede, null);

        $ruta = DB::transaction(function () use ($actor, $sede, $datos, $horarios) {
            $ruta = new Ruta($datos + ['sede_id' => $sede->id]);
            $ruta->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id]);
            $this->guardar($actor, $sede, $ruta, $horarios);

            return $ruta;
        });

        $this->auditoria->auditar($actor, 'rutas.creado', $ruta, null, $this->foto($ruta));

        return $ruta;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Ruta $ruta, array $entrada): Ruta
    {
        $sede = $ruta->sede;
        $antes = $this->foto($ruta);
        [$datos, $horarios] = $this->validar($entrada, $sede, $ruta);

        DB::transaction(function () use ($actor, $sede, $ruta, $datos, $horarios) {
            $ruta->fill($datos);
            $this->guardar($actor, $sede, $ruta, $horarios);
        });

        $this->auditoria->auditar($actor, 'rutas.actualizado', $ruta, $antes, $this->foto($ruta->refresh()));

        return $ruta;
    }

    /**
     * Suspender y reactivar (SEGCAT: "SUSPENDIDA"); nunca se borra.
     */
    public function cambiarEstado(User $actor, Ruta $ruta, bool $activo): void
    {
        $ruta->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'rutas.reactivado' : 'rutas.desactivado', $ruta, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * Copia con todos sus horarios y paraderos: «<nombre> (COPIA)» y, si ya
     * existe en la sede y el sentido, «(COPIA) 2», «(COPIA) 3»…
     */
    public function clonar(User $actor, Ruta $original): Ruta
    {
        $original->loadMissing('horarios.paradas');

        $copia = DB::transaction(function () use ($actor, $original) {
            $copia = $original->replicate(['creado_por', 'actualizado_por', 'created_at', 'updated_at']);
            $copia->forceFill(['nombre' => $this->nombreCopia($original), 'activo' => true, 'creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();

            foreach ($original->horarios as $horario) {
                $nuevo = $horario->replicate(['creado_por', 'actualizado_por', 'created_at', 'updated_at']);
                $nuevo->ruta_id = $copia->id;
                $nuevo->save();
                foreach ($horario->paradas as $parada) {
                    $p = $parada->replicate(['creado_por', 'actualizado_por', 'created_at', 'updated_at']);
                    $p->ruta_horario_id = $nuevo->id;
                    $p->save();
                }
            }

            return $copia;
        });

        $this->auditoria->auditar($actor, 'rutas.clonado', $copia, ['copia_de' => $original->nombre], $this->foto($copia));

        return $copia;
    }

    public function nombreCopia(Ruta $original): string
    {
        $base = mb_substr($original->nombre, 0, 140).' (COPIA)';
        $nombre = $base;
        for ($n = 2; $this->nombreOcupado($original->sede_id, $original->sentido, $nombre, null) !== null; $n++) {
            $nombre = $base.' '.$n;
        }

        return $nombre;
    }

    public function nombreOcupado(int $sedeId, string $sentido, string $nombre, ?int $excepto): ?Ruta
    {
        return Ruta::where('sede_id', $sedeId)->where('sentido', $sentido)
            ->whereRaw('UPPER(nombre) = ?', [mb_strtoupper($nombre)])
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))
            ->first();
    }

    /**
     * Guarda la ruta, su resumen y sus horarios. Los horarios que ya existían
     * conservan su id (Bitácora de transporte los usará); sus paraderos se
     * vuelven a escribir en el orden capturado.
     *
     * @param  list<array<string, mixed>>  $horarios
     */
    private function guardar(User $actor, Sede $sede, Ruta $ruta, array $horarios): void
    {
        $ruta->fill($this->resumen($horarios))->save();

        $existentes = $ruta->horarios()->get()->keyBy('id');
        $conservar = [];
        $catalogo = [];

        foreach ($horarios as $orden => $h) {
            $modelo = $h['id'] !== null && $existentes->has($h['id']) ? $existentes[$h['id']] : new RutaHorario(['ruta_id' => $ruta->id]);
            $modelo->fill([
                'nombre' => $h['nombre'], 'dias' => $h['dias'],
                'hora_inicio' => $h['hora_inicio'], 'hora_fin' => $h['hora_fin'], 'orden' => $orden + 1,
            ])->save();
            $conservar[] = $modelo->id;

            RutaParada::where('ruta_horario_id', $modelo->id)->delete();
            foreach ($h['paradas'] as $i => $parada) {
                $catalogo[$parada['nombre']] ??= $this->paraderoParaRuta($actor, $sede, $parada['nombre']);
                RutaParada::create([
                    'ruta_horario_id' => $modelo->id,
                    'paradero_id' => $catalogo[$parada['nombre']]->id,
                    'hora' => $parada['hora'],
                    'orden' => $i + 1,
                ]);
            }
        }

        $quitar = $existentes->keys()->diff($conservar)->values()->all();
        if ($quitar !== []) {
            RutaParada::whereIn('ruta_horario_id', $quitar)->delete();
            RutaHorario::whereIn('id', $quitar)->delete();
        }
    }

    /**
     * Resumen de la ruta: el horario más temprano y la unión de días.
     *
     * @param  list<array<string, mixed>>  $horarios
     * @return array{hora_inicio: string, hora_fin: string, dias: string}
     */
    private function resumen(array $horarios): array
    {
        $primero = collect($horarios)->sortBy('hora_inicio')->first();
        $dias = collect($horarios)->flatMap(fn ($h) => Ruta::separarDias($h['dias']))->unique()->all();

        return [
            'hora_inicio' => $primero['hora_inicio'],
            'hora_fin' => $primero['hora_fin'],
            'dias' => implode(',', array_values(array_intersect(array_keys(Ruta::DIAS), $dias))),
        ];
    }

    // ------------------------------------------------------------ Validación

    /**
     * Valida todo contra la sede y la empresa (el servidor nunca confía en
     * los ids del formulario). Devuelve [datos de la ruta, horarios limpios].
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public function validar(array $entrada, Sede $sede, ?Ruta $actual): array
    {
        $entrada['nombre'] = Paradero::normalizarNombre(is_string($entrada['nombre'] ?? null) ? $entrada['nombre'] : '');
        $costo = is_string($entrada['costo_maximo_taxi'] ?? null) ? str_replace([',', '$', ' '], '', $entrada['costo_maximo_taxi']) : ($entrada['costo_maximo_taxi'] ?? null);
        $entrada['costo_maximo_taxi'] = $costo === '' ? null : $costo;

        $validados = Validator::make($entrada, [
            'sentido' => ['required', 'string', Rule::in(array_keys(Ruta::SENTIDOS))],
            'nombre' => ['required', 'string', 'max:150'],
            'turno_id' => ['required', 'integer'],
            'proveedor_id' => ['required', 'integer'],
            'costo_maximo_taxi' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'horarios' => ['required', 'array', 'max:'.self::MAX_HORARIOS],
        ], [
            'sentido.required' => 'Elige si la ruta es de llegada o de salida.',
            'sentido.in' => 'Elige si la ruta es de llegada o de salida.',
            'nombre.required' => 'Escribe el nombre de la ruta (por ejemplo: RUTA 1 - CENTRO).',
            'nombre.max' => 'El nombre de la ruta es muy largo (máximo 150 caracteres).',
            'turno_id.required' => 'Elige el turno de la ruta.',
            'turno_id.integer' => 'Elige el turno de la ruta.',
            'proveedor_id.required' => 'Elige la empresa transportista.',
            'proveedor_id.integer' => 'Elige la empresa transportista.',
            'costo_maximo_taxi.numeric' => 'El costo máximo por taxi debe ser un número, por ejemplo 250 o 250.50.',
            'costo_maximo_taxi.min' => 'El costo máximo por taxi no puede ser negativo.',
            'costo_maximo_taxi.max' => 'Revisa el costo máximo por taxi: es demasiado alto.',
            'horarios.required' => 'Toda ruta necesita al menos un horario con su hora de inicio y su hora de llegada.',
            'horarios.array' => 'Toda ruta necesita al menos un horario con su hora de inicio y su hora de llegada.',
            'horarios.max' => 'Una ruta puede tener máximo '.self::MAX_HORARIOS.' horarios.',
        ])->validate();

        $repetida = $this->nombreOcupado($sede->id, $validados['sentido'], $validados['nombre'], $actual?->id);
        if ($repetida !== null) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe una ruta de '.mb_strtolower(Ruta::SENTIDOS[$validados['sentido']])
                ." llamada «{$repetida->nombre}» en esta sede".($repetida->activo ? '.' : ' (suspendida): reactívala o usa otro nombre.')]);
        }

        $turno = Turno::find((int) $validados['turno_id']);
        $turnoActual = (int) $actual?->turno_id === (int) $validados['turno_id'];
        if ($turno === null || (! $turnoActual && (! $turno->activo || ! Turno::whereKey($turno->id)->aplicanEn([$sede->id])->exists()))) {
            throw ValidationException::withMessages(['turno_id' => 'El turno elegido no existe, está desactivado o no se usa en esta sede. Elige otro de la lista.']);
        }

        $proveedor = Proveedor::find((int) $validados['proveedor_id']);
        $proveedorActual = (int) $actual?->proveedor_id === (int) $validados['proveedor_id'];
        if ($proveedor === null || (! $proveedorActual && (! $proveedor->activo || ! Proveedor::whereKey($proveedor->id)->operanEn([$sede->id])->exists()))) {
            throw ValidationException::withMessages(['proveedor_id' => 'La empresa transportista no existe, está dada de baja o no opera en esta sede. Elige otra de la lista.']);
        }

        $datos = [
            'sentido' => $validados['sentido'],
            'nombre' => $validados['nombre'],
            'turno_id' => $turno->id,
            'proveedor_id' => $proveedor->id,
            'costo_maximo_taxi' => $validados['costo_maximo_taxi'] === null ? null : round((float) $validados['costo_maximo_taxi'], 2),
        ];

        return [$datos, $this->leerHorarios($validados['horarios'], $actual)];
    }

    /**
     * Horarios del formulario. Un bloque totalmente vacío se ignora; uno a
     * medias es un error claro ("Horario 2: falta la hora de inicio").
     *
     * @param  array<mixed>  $bloques
     * @return list<array<string, mixed>>
     */
    private function leerHorarios(array $bloques, ?Ruta $actual): array
    {
        $idsActuales = $actual ? $actual->horarios()->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $horarios = [];
        $errores = [];
        $n = 0;

        foreach ($bloques as $bloque) {
            if (! is_array($bloque)) {
                continue;
            }
            $texto = fn (string $campo) => is_string($bloque[$campo] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $bloque[$campo])) : '';
            $nombre = $texto('nombre');
            $inicio = $texto('hora_inicio');
            $fin = $texto('hora_fin');
            $dias = is_array($bloque['dias'] ?? null) ? array_values(array_intersect(array_keys(Ruta::DIAS), array_filter($bloque['dias'], 'is_string'))) : [];
            [$paradas, $erroresParadas] = $this->leerParadas(is_array($bloque['paraderos'] ?? null) ? $bloque['paraderos'] : []);

            if ($nombre === '' && $inicio === '' && $fin === '' && $dias === [] && $paradas === [] && $erroresParadas === []) {
                continue;
            }
            $n++;
            $etiqueta = "Horario {$n}: ";

            if ($inicio === '') {
                $errores[] = $etiqueta.'falta la hora de inicio del recorrido.';
            } elseif (! preg_match(self::HORA, $inicio)) {
                $errores[] = $etiqueta.'escribe la hora de inicio como HH:MM (24 horas).';
            }
            if ($fin === '') {
                $errores[] = $etiqueta.'falta la hora de llegada a destino.';
            } elseif (! preg_match(self::HORA, $fin)) {
                $errores[] = $etiqueta.'escribe la hora de llegada como HH:MM (24 horas).';
            }
            if ($inicio !== '' && $fin !== '' && substr($inicio, 0, 5) === substr($fin, 0, 5)) {
                $errores[] = $etiqueta.'la hora de llegada debe ser distinta a la de inicio.';
            }
            if (mb_strlen($nombre) > 60) {
                $errores[] = $etiqueta.'el nombre es muy largo (máximo 60 caracteres).';
            }
            foreach ($erroresParadas as $error) {
                $errores[] = $etiqueta.$error;
            }

            $id = isset($bloque['id']) && is_numeric($bloque['id']) ? (int) $bloque['id'] : null;
            $horarios[] = [
                // Un id que no es de esta ruta se trata como horario nuevo
                'id' => $id !== null && in_array($id, $idsActuales, true) ? $id : null,
                'nombre' => $nombre !== '' ? mb_substr($nombre, 0, 60) : "Horario {$n}",
                // Ningún día marcado = opera todos los días (como en SEGCAT), pero se guarda explícito
                'dias' => implode(',', $dias === [] ? array_keys(Ruta::DIAS) : $dias),
                'hora_inicio' => substr($inicio, 0, 5).':00',
                'hora_fin' => substr($fin, 0, 5).':00',
                'paradas' => $paradas,
            ];
        }

        if ($horarios === [] && $errores === []) {
            $errores[] = 'Toda ruta necesita al menos un horario con su hora de inicio y su hora de llegada.';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages(['horarios' => $errores]);
        }

        return $horarios;
    }

    /**
     * @param  array<mixed>  $filas
     * @return array{0: list<array{nombre: string, hora: ?string}>, 1: list<string>}
     */
    private function leerParadas(array $filas): array
    {
        $paradas = [];
        $errores = [];
        $vistos = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $nombre = Paradero::normalizarNombre(is_string($fila['nombre'] ?? null) ? $fila['nombre'] : '');
            $hora = is_string($fila['hora'] ?? null) ? trim($fila['hora']) : '';

            if ($nombre === '') {
                if ($hora !== '') {
                    $errores[] = "hay un paradero con hora ({$hora}) pero sin nombre: escríbelo o quita la fila.";
                }

                continue;
            }
            if (mb_strlen($nombre) > 150) {
                $errores[] = 'el nombre de un paradero es muy largo (máximo 150 caracteres).';

                continue;
            }
            if (isset($vistos[$nombre])) {
                $errores[] = "el paradero «{$nombre}» está repetido.";

                continue;
            }
            if ($hora !== '' && ! preg_match(self::HORA, $hora)) {
                $errores[] = "escribe la hora del paradero «{$nombre}» como HH:MM (24 horas).";

                continue;
            }
            $vistos[$nombre] = true;
            $paradas[] = ['nombre' => $nombre, 'hora' => $hora === '' ? null : substr($hora, 0, 5).':00'];
        }

        if (count($paradas) > self::MAX_PARADEROS) {
            $errores[] = 'un horario puede tener máximo '.self::MAX_PARADEROS.' paraderos.';
        }

        return [$paradas, $errores];
    }

    // ------------------------------------------------------------- Paraderos

    public function crearParadero(User $actor, Sede $sede, mixed $nombre): Paradero
    {
        $nombre = $this->validarNombreParadero($sede, $nombre, null);
        $paradero = $this->nuevoParadero($actor, $sede, $nombre);
        $this->auditoria->auditar($actor, 'rutas.creado', $paradero, null, $this->fotoParadero($paradero));

        return $paradero;
    }

    public function actualizarParadero(User $actor, Paradero $paradero, mixed $nombre): Paradero
    {
        $antes = $this->fotoParadero($paradero);
        $paradero->forceFill(['nombre' => $this->validarNombreParadero($paradero->sede, $nombre, $paradero)])->save();
        $this->auditoria->auditar($actor, 'rutas.actualizado', $paradero, $antes, $this->fotoParadero($paradero));

        return $paradero;
    }

    /**
     * Desactivar no altera las rutas que ya lo usan (como en SEGCAT): solo
     * deja de sugerirse al capturar.
     */
    public function cambiarEstadoParadero(User $actor, Paradero $paradero, bool $activo): void
    {
        $paradero->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'rutas.reactivado' : 'rutas.desactivado', $paradero, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * El paradero escrito en una ruta: el del catálogo de la sede con ese
     * nombre o uno nuevo. Si estaba desactivado se reactiva (se está usando).
     */
    private function paraderoParaRuta(User $actor, Sede $sede, string $nombre): Paradero
    {
        $paradero = Paradero::where('sede_id', $sede->id)->whereRaw('UPPER(nombre) = ?', [$nombre])->first();
        if ($paradero === null) {
            $paradero = $this->nuevoParadero($actor, $sede, $nombre);
            $this->auditoria->auditar($actor, 'rutas.creado', $paradero, null, $this->fotoParadero($paradero));
        } elseif (! $paradero->activo) {
            $this->cambiarEstadoParadero($actor, $paradero, true);
        }

        return $paradero;
    }

    private function nuevoParadero(User $actor, Sede $sede, string $nombre): Paradero
    {
        $paradero = new Paradero(['sede_id' => $sede->id, 'nombre' => $nombre]);
        $paradero->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();

        return $paradero;
    }

    private function validarNombreParadero(Sede $sede, mixed $nombre, ?Paradero $actual): string
    {
        $nombre = Paradero::normalizarNombre(is_string($nombre) ? $nombre : '');
        if ($nombre === '') {
            throw ValidationException::withMessages(['nombre' => 'Escribe el nombre del paradero (por ejemplo: PLAZA LAS AMÉRICAS).']);
        }
        if (mb_strlen($nombre) > 150) {
            throw ValidationException::withMessages(['nombre' => 'El nombre del paradero es muy largo (máximo 150 caracteres).']);
        }
        $existente = Paradero::where('sede_id', $sede->id)->whereRaw('UPPER(nombre) = ?', [$nombre])
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->first();
        if ($existente !== null) {
            throw ValidationException::withMessages(['nombre' => "Ya existe el paradero «{$existente->nombre}» en esta sede"
                .($existente->activo ? '.' : ' (desactivado): reactívalo en lugar de crearlo otra vez.')]);
        }

        return $nombre;
    }

    // --------------------------------------------------------------- Lectura

    /**
     * Las próximas salidas de un conjunto de horarios (rutas activas) a partir
     * de "ahora", buscando hasta 14 días adelante y respetando los días de cada
     * horario. Cada elemento: ['cuando' => CarbonImmutable, 'horario' => RutaHorario].
     *
     * @param  iterable<RutaHorario>  $horarios  con su ruta cargada
     * @return list<array{cuando: CarbonImmutable, horario: RutaHorario}>
     */
    public function proximas(iterable $horarios, CarbonImmutable $ahora, int $cantidad = 2): array
    {
        $candidatos = [];
        foreach ($horarios as $horario) {
            if (! $horario->ruta->activo) {
                continue;
            }
            $encontrados = 0;
            for ($dia = 0; $dia <= 14 && $encontrados < $cantidad; $dia++) {
                $fecha = $ahora->startOfDay()->addDays($dia);
                if (! $horario->aplicaEn($fecha->dayOfWeekIso)) {
                    continue;
                }
                $cuando = $fecha->setTimeFromTimeString($horario->inicio());
                if ($cuando->lt($ahora)) {
                    continue;
                }
                $candidatos[] = ['cuando' => $cuando, 'horario' => $horario];
                $encontrados++;
            }
        }
        usort($candidatos, fn ($a, $b) => $a['cuando'] <=> $b['cuando']);

        return array_slice($candidatos, 0, $cantidad);
    }

    /** "Hoy 14:30 · RUTA 1", "Mañana 07:00 · RUTA 2", "Mar 09:00 · RUTA 3". */
    public function etiquetaProxima(array $ocurrencia, CarbonImmutable $ahora): string
    {
        $cuando = $ocurrencia['cuando'];
        $prefijo = match (true) {
            $cuando->isSameDay($ahora) => 'Hoy',
            $cuando->isSameDay($ahora->addDay()) => 'Mañana',
            default => Ruta::DIAS[Ruta::codigoDia($cuando->dayOfWeekIso)],
        };

        return $prefijo.' '.$cuando->format('H:i').' · '.$ocurrencia['horario']->ruta->nombre;
    }

    /**
     * Hora actual en la sede (su zona o la de la empresa).
     */
    public function ahoraEn(Sede $sede): CarbonImmutable
    {
        return CarbonImmutable::now($sede->zonaHoraria());
    }

    // ------------------------------------------------------------- Auditoría

    /**
     * @return array<string, mixed>
     */
    public function foto(Ruta $ruta): array
    {
        $ruta->load('horarios.paradas.paradero:id,nombre');

        return $ruta->only(['sede_id', 'sentido', 'nombre', 'turno_id', 'proveedor_id', 'costo_maximo_taxi', 'activo'])
            + ['horarios' => $ruta->horarios->map(fn (RutaHorario $h) => $h->nombre.' · '.$h->patronDias().' · '.$h->inicio().'-'.$h->fin()
                .($h->paradas->isEmpty() ? '' : ' · '.$h->paradas->map(fn ($p) => $p->paradero?->nombre.($p->hora ? ' '.$p->horaCorta() : ''))->implode(', ')))->all()];
    }

    /**
     * @return array<string, mixed>
     */
    public function fotoParadero(Paradero $p): array
    {
        return $p->only(['sede_id', 'nombre', 'activo']);
    }
}
