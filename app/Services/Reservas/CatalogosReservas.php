<?php

namespace App\Services\Reservas;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\ReservaServicio;
use App\Models\ReservaTipoUso;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Espacios\Etiquetas;
use App\Support\ImagenSegura;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Configuración de Reservas de áreas: qué espacios de Zonas y edificios se
 * reservan (y cómo) y los catálogos editables de la empresa (montajes, tipos
 * de uso y servicios). Todo dentro de la empresa activa (scope de tenant).
 */
class CatalogosReservas
{
    /** catálogo => [modelo, nombre en singular] */
    public const CATALOGOS = [
        'montajes' => [ReservaMontaje::class, 'Montaje'],
        'tipos' => [ReservaTipoUso::class, 'Tipo de uso'],
        'servicios' => [ReservaServicio::class, 'Servicio'],
    ];

    /** Marca en empresas.preferencias: la empresa ya tiene (o tuvo) áreas reservables (Mis pendientes no consulta si no). */
    public const EN_USO = 'reservas_en_uso';

    /** Niveles de Zonas y edificios que se pueden reservar (salones, salas, terrazas…). */
    public const NIVELES = [Espacio::EDIFICIO, Espacio::AREA, Espacio::AREA_ESPECIFICA, Espacio::SUBAREA];

    /** Espacios interiores que marcó (o reactivó) el último marcarReservable «por sus espacios interiores». */
    private int $ultimosInteriores = 0;

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly NivelesReservas $niveles,
    ) {}

    /**
     * La primera vez, la empresa recibe los montajes y tipos de uso de siempre
     * (los puede cambiar). Idempotente.
     */
    public function asegurar(): void
    {
        if (! ReservaMontaje::exists()) {
            foreach (ReservaMontaje::DEFECTO as $i => $nombre) {
                ReservaMontaje::create(['nombre' => $nombre, 'orden' => $i + 1]);
            }
        }
        if (! ReservaTipoUso::exists()) {
            foreach (ReservaTipoUso::DEFECTO as $i => $nombre) {
                ReservaTipoUso::create(['nombre' => $nombre, 'orden' => $i + 1]);
            }
        }
    }

    // ------------------------------------------------------------ Catálogos

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardarCatalogo(User $actor, string $catalogo, ?Model $registro, array $datos): Model
    {
        [$clase, $singular] = self::CATALOGOS[$catalogo];
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > ($catalogo === 'servicios' ? 80 : 60)) {
            throw ValidationException::withMessages(['nombre' => 'Escribe el nombre.']);
        }
        $repetido = $clase::query()->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($registro !== null, fn ($q) => $q->whereKeyNot($registro->getKey()))->exists();
        if ($repetido) {
            throw ValidationException::withMessages(['nombre' => "Ya existe «{$nombre}» en el catálogo."]);
        }
        $valores = ['nombre' => $nombre];
        if ($catalogo === 'servicios') {
            $departamento = ($datos['departamento_id'] ?? null) ? (int) $datos['departamento_id'] : null;
            if ($departamento !== null && ! Departamento::whereKey($departamento)->exists()) {
                throw ValidationException::withMessages(['departamento_id' => 'Elige un departamento de la empresa.']);
            }
            $costo = ($datos['costo'] ?? '') === '' || $datos['costo'] === null ? null : round((float) $datos['costo'], 2);
            if ($costo !== null && ($costo < 0 || $costo > 9999999)) {
                throw ValidationException::withMessages(['costo' => 'El costo no es válido.']);
            }
            $valores += [
                'categoria' => array_key_exists($datos['categoria'] ?? '', ReservaServicio::CATEGORIAS) ? $datos['categoria'] : 'otro',
                'unidad' => array_key_exists($datos['unidad'] ?? '', ReservaServicio::UNIDADES) ? $datos['unidad'] : 'pieza',
                'costo' => $costo,
                'departamento_id' => $departamento,
                'requiere_aprobacion' => filter_var($datos['requiere_aprobacion'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        $antes = $registro?->only(array_keys($valores));
        if ($registro === null) {
            $registro = $clase::create($valores + ['orden' => (int) $clase::query()->max('orden') + 1]);
        } else {
            $registro->update($valores);
        }
        $this->auditoria->auditar($actor, $antes === null ? 'reservas.catalogo_creado' : 'reservas.catalogo_actualizado', $registro, $antes,
            ['catalogo' => $singular] + $registro->only(array_keys($valores)));

        return $registro;
    }

    public function cambiarEstado(User $actor, Model $registro, bool $activo): void
    {
        $antes = (bool) $registro->activo;
        $registro->update(['activo' => $activo]);
        $this->auditoria->auditar($actor, $activo ? 'reservas.catalogo_reactivado' : 'reservas.catalogo_baja', $registro, ['activo' => $antes], ['activo' => $activo]);
    }

    // ---------------------------------------------------- Áreas reservables

    /**
     * Marca un espacio de Zonas y edificios como reservable (o devuelve su
     * configuración si ya la tenía; si estaba quitada, la reactiva con su
     * configuración de antes).
     *
     * Una zona con espacios interiores se reserva en uno de dos modos
     * (ReservaArea::MODOS): completa (por omisión) o por sus espacios
     * interiores; en ese caso también marca de una vez sus espacios
     * interiores finales, que reciben la configuración de la zona como valores
     * iniciales. No cambia nada en Zonas y edificios.
     */
    public function marcarReservable(User $actor, Espacio $espacio, ?string $modo = null): ReservaArea
    {
        if (! in_array($espacio->nivel, self::NIVELES, true) || ! $espacio->activo) {
            throw ValidationException::withMessages(['espacio_id' => 'Elige una zona, área o sala activa de Zonas y edificios.']);
        }
        $interiores = $modo === ReservaArea::INTERIORES && $this->niveles->tieneInteriores($espacio);
        $this->ultimosInteriores = 0;

        return DB::transaction(function () use ($actor, $espacio, $modo, $interiores) {
            $area = ReservaArea::firstWhere('espacio_id', $espacio->id);
            if ($area !== null) {
                if (! $area->reservable) {
                    $this->cambiarReservable($actor, $area, true);
                }
                if ($modo !== null && $area->modo !== ($interiores ? ReservaArea::INTERIORES : ReservaArea::COMPLETA)) {
                    $antes = ['modo' => $area->modo];
                    $area->update(['modo' => $interiores ? ReservaArea::INTERIORES : ReservaArea::COMPLETA]);
                    $this->auditoria->auditar($actor, 'reservas.area_modo', $area, $antes, ['modo' => $area->modo, 'espacio' => $espacio->nombre]);
                }
            } else {
                $area = ReservaArea::create(['sede_id' => $espacio->sede_id, 'espacio_id' => $espacio->id, 'reservable' => true,
                    'modo' => $interiores ? ReservaArea::INTERIORES : ReservaArea::COMPLETA]);
                $empresa = Empresa::find($area->empresa_id);
                if ($empresa !== null && empty($empresa->preferencias[self::EN_USO])) {
                    $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], [self::EN_USO => true])])->save();
                }
                $this->auditoria->auditar($actor, 'reservas.area_reservable', $area, null, ['espacio' => $espacio->nombre, 'modo' => $area->modo]);
            }

            if ($interiores) {
                $nuevas = 0;
                foreach ($this->niveles->interioresFinales($espacio) as $interior) {
                    $hija = ReservaArea::firstWhere('espacio_id', $interior->id);
                    if ($hija === null) {
                        $hija = ReservaArea::create(['sede_id' => $interior->sede_id, 'espacio_id' => $interior->id, 'reservable' => true, 'modo' => ReservaArea::COMPLETA]);
                        $this->heredar($area, $hija);
                        $this->auditoria->auditar($actor, 'reservas.area_reservable', $hija, null,
                            ['espacio' => $interior->nombre, 'modo' => ReservaArea::COMPLETA, 'heredada_de' => $espacio->nombre]);
                        $nuevas++;
                    } elseif (! $hija->reservable) {
                        $this->cambiarReservable($actor, $hija, true);
                        $nuevas++;
                    }
                }
                $this->ultimosInteriores = $nuevas;
            }

            return $area;
        });
    }

    /**
     * Marca varios espacios a la vez (diálogo «Agregar área reservable»).
     *
     * @param  iterable<Espacio>  $espacios
     * @return array{areas: list<ReservaArea>, interiores: int}
     */
    public function marcarVarios(User $actor, iterable $espacios, string $modo): array
    {
        $areas = [];
        $interiores = 0;
        foreach ($espacios as $espacio) {
            $area = $this->marcarReservable($actor, $espacio, $modo);
            $interiores += $this->ultimosInteriores;
            $areas[] = $area;
        }

        return ['areas' => $areas, 'interiores' => $interiores];
    }

    /**
     * Copia la configuración de una zona a un espacio interior (aforo, horario,
     * responsable y suplente, montajes con su aforo, tarifas y minutos de
     * montaje y desmontaje). Son valores iniciales: después se editan.
     */
    public function heredar(ReservaArea $zona, ReservaArea $interior): void
    {
        $interior->update($zona->only(ReservaArea::HEREDABLES));
        $interior->montajes()->sync($zona->montajes()->get()->mapWithKeys(fn ($m) => [(int) $m->id => ['aforo' => $m->pivot->aforo]])->all());
    }

    /**
     * Áreas reservables (de alta o quitadas) que cuelgan de una zona.
     *
     * @return Collection<int, ReservaArea>
     */
    public function interioresDe(ReservaArea $zona): Collection
    {
        $espacio = $zona->espacio ?? Espacio::find($zona->espacio_id);
        if ($espacio === null) {
            return collect();
        }
        $ids = Espacio::where('ruta', 'like', $espacio->ruta.'%')->whereKeyNot($espacio->id)->pluck('id');

        return ReservaArea::with('espacio')->whereIn('espacio_id', $ids)->get();
    }

    /**
     * «Aplicar a sus espacios interiores»: la configuración de la zona se
     * copia a sus espacios interiores reservables (reemplaza la de cada uno).
     */
    public function copiarAInteriores(User $actor, ReservaArea $zona): int
    {
        $interiores = $this->interioresDe($zona)->where('reservable', true);
        foreach ($interiores as $interior) {
            $antes = $interior->only(ReservaArea::HEREDABLES);
            $this->heredar($zona, $interior);
            $this->auditoria->auditar($actor, 'reservas.area_configurada', $interior, $antes, $interior->only(ReservaArea::HEREDABLES) + ['copiada_de' => $zona->nombre()]);
        }

        return $interiores->count();
    }

    /**
     * Reservas próximas (por aprobar, con cambio pedido o confirmadas que aún
     * no terminan) de un conjunto de áreas.
     *
     * @param  list<int>  $areas
     */
    public function reservasProximas(array $areas): int
    {
        return Reserva::ocupan()->whereIn('reserva_area_id', $areas)->where('fin', '>', now())->count();
    }

    /**
     * «Quitar de reservas». Nunca borra el espacio de Zonas y edificios ni el
     * historial:
     *  - con reservas próximas no se quita (se cancelan o reprograman antes);
     *  - con reservas pasadas se desactiva (reservable = false) y conserva su
     *    configuración para volver a agregarla;
     *  - sin ninguna reserva se borra su configuración de reserva.
     * Una zona «por sus espacios interiores» se quita junto con ellos.
     *
     * @return array{borradas: int, desactivadas: int}
     */
    public function quitar(User $actor, ReservaArea $area): array
    {
        $areas = collect([$area]);
        if ($area->porInteriores()) {
            $areas = $areas->merge($this->interioresDe($area));
        }
        $proximas = $this->reservasProximas($areas->pluck('id')->map(fn ($id) => (int) $id)->all());
        if ($proximas > 0) {
            throw ValidationException::withMessages(['quitar' => $proximas === 1
                ? 'Tiene 1 reserva próxima; cancélala o reprográmala primero.'
                : "Tiene {$proximas} reservas próximas; cancélalas o reprográmalas primero."]);
        }

        $resultado = ['borradas' => 0, 'desactivadas' => 0];
        $fotos = [];
        DB::transaction(function () use ($actor, $areas, &$resultado, &$fotos) {
            foreach ($areas as $a) {
                $nombre = $a->nombre();
                if (Reserva::where('reserva_area_id', $a->id)->exists()) {
                    if ($a->reservable) {
                        $a->update(['reservable' => false]);
                    }
                    $resultado['desactivadas']++;
                    $this->auditoria->auditar($actor, 'reservas.area_quitada', $a, ['reservable' => true], ['espacio' => $nombre, 'resultado' => 'desactivada (tiene historial)']);

                    continue;
                }
                $antes = $a->only(['espacio_id', 'modo', ...ReservaArea::HEREDABLES]);
                if ($a->foto) {
                    $fotos[] = $a->foto;
                }
                $a->delete();
                $resultado['borradas']++;
                $this->auditoria->auditar($actor, 'reservas.area_quitada', $a, $antes, ['espacio' => $nombre, 'resultado' => 'configuración borrada']);
            }
        });
        foreach ($fotos as $foto) {
            Storage::disk('local')->delete($foto);
        }

        return $resultado;
    }

    /**
     * Espacios para el diálogo «Agregar área reservable», en orden de árbol:
     * nivel permitido y activos, con su sangría, cuántos espacios interiores
     * tienen y si ya se reservan (o se quitaron y se pueden volver a agregar).
     *
     * @param  list<int>|null  $sedes  null = todas las de la empresa
     * @return Collection<int, array<string, mixed>>
     */
    public function opcionesParaAgregar(?array $sedes, ?Empresa $empresa = null): Collection
    {
        $espacios = Espacio::with('sede:id,nombre')->where('activo', true)->whereIn('nivel', self::NIVELES)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
            ->orderBy('sede_id')->orderBy('ruta')->limit(3000)->get(['id', 'sede_id', 'nombre', 'nivel', 'ruta', 'padre_id', 'profundidad']);
        $areas = ReservaArea::whereIn('espacio_id', $espacios->pluck('id'))->get(['id', 'espacio_id', 'reservable', 'modo'])->keyBy('espacio_id');
        $etiquetas = Etiquetas::para($empresa?->loadMissing('rubro'));
        // Orden de árbol: la ruta con ids no ordena por nombre; se arma por padres
        $porPadre = $espacios->groupBy(fn ($e) => (int) $e->padre_id);
        $orden = [];
        $recorrer = function (int $padre, int $nivel) use (&$recorrer, &$orden, $porPadre) {
            foreach ($porPadre->get($padre, collect())->sortBy(fn ($e) => mb_strtolower($e->nombre)) as $e) {
                $orden[] = [$e, $nivel];
                $recorrer((int) $e->id, $nivel + 1);
            }
        };
        $ids = $espacios->pluck('id')->flip();
        foreach ($espacios->filter(fn ($e) => $e->padre_id === null || ! $ids->has($e->padre_id)) as $raiz) {
            $orden[] = [$raiz, 0];
            $recorrer((int) $raiz->id, 1);
        }
        $nombres = $espacios->pluck('nombre', 'id');

        return collect($orden)->map(function (array $par) use ($espacios, $areas, $etiquetas, $nombres) {
            [$e, $sangria] = $par;
            $interiores = $espacios->filter(fn ($o) => $o->id !== $e->id && str_starts_with($o->ruta, $e->ruta))->count();
            $area = $areas->get($e->id);

            return [
                'id' => (int) $e->id, 'sede_id' => (int) $e->sede_id, 'sede' => $e->sede?->nombre, 'nombre' => $e->nombre,
                'nivel' => $etiquetas[$e->nivel]['singular'] ?? '', 'sangria' => min($sangria, 4), 'interiores' => $interiores,
                'ruta' => collect($e->idsAncestros())->map(fn ($id) => $nombres[$id] ?? null)->filter()->join(' › '),
                'estado' => $area === null ? 'nuevo' : ($area->reservable ? 'reservable' : 'quitada'),
            ];
        })->values();
    }

    public function cambiarReservable(User $actor, ReservaArea $area, bool $reservable): void
    {
        $area->update(['reservable' => $reservable]);
        $this->auditoria->auditar($actor, $reservable ? 'reservas.area_reservable' : 'reservas.area_no_reservable', $area, null, ['espacio' => $area->nombre()]);
    }

    /**
     * Guarda la configuración del área (formulario de la pantalla del área).
     *
     * @param  array<string, mixed>  $datos
     */
    public function guardarArea(User $actor, ReservaArea $area, array $datos, ?UploadedFile $foto = null): void
    {
        $usuarios = User::where('empresa_id', $area->empresa_id)->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $usuario = function (string $campo) use ($datos, $usuarios): ?int {
            $v = $datos[$campo] ?? null;
            if ($v === null || $v === '') {
                return null;
            }
            if (! in_array((int) $v, $usuarios, true)) {
                throw ValidationException::withMessages([$campo => 'Elige a un usuario activo de la empresa.']);
            }

            return (int) $v;
        };
        $entero = fn (string $campo, int $max) => ($datos[$campo] ?? '') === '' || ! isset($datos[$campo]) ? null : max(0, min($max, (int) $datos[$campo]));
        $dinero = fn (string $campo) => ($datos[$campo] ?? '') === '' || ! isset($datos[$campo]) ? null : max(0, round((float) $datos[$campo], 2));

        $responsable = $usuario('responsable_id');
        $suplente = $usuario('suplente_id');
        if ($responsable !== null && $responsable === $suplente) {
            throw ValidationException::withMessages(['suplente_id' => 'El suplente debe ser otra persona.']);
        }

        // Horario: por día, «abierto» con su hora de inicio y fin (sin días marcados = cualquier hora)
        $horario = null;
        if (filter_var($datos['con_horario'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $horario = [];
            foreach (array_keys(ReservaArea::DIAS) as $dia) {
                $abierto = filter_var($datos['horario'][$dia]['abierto'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $desde = (string) ($datos['horario'][$dia]['desde'] ?? '');
                $hasta = (string) ($datos['horario'][$dia]['hasta'] ?? '');
                if (! $abierto) {
                    $horario[(string) $dia] = [];

                    continue;
                }
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $desde) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hasta) || $hasta <= $desde) {
                    throw ValidationException::withMessages(['horario' => 'Revisa el horario del '.mb_strtolower(ReservaArea::DIAS[$dia]).': la hora de cierre debe ser después de la de apertura.']);
                }
                $horario[(string) $dia] = [[$desde, $hasta]];
            }
        }

        $valores = [
            'aforo_maximo' => $entero('aforo_maximo', 100000),
            'confirmacion_inmediata' => filter_var($datos['confirmacion_inmediata'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'montaje_minutos' => $entero('montaje_minutos', 1440) ?? 0,
            'desmontaje_minutos' => $entero('desmontaje_minutos', 1440) ?? 0,
            'horario' => $horario,
            'tarifa_hora' => $dinero('tarifa_hora'),
            'tarifa_medio_dia' => $dinero('tarifa_medio_dia'),
            'tarifa_dia' => $dinero('tarifa_dia'),
            'tarifa_evento' => $dinero('tarifa_evento'),
            'descripcion' => ($d = trim((string) ($datos['descripcion'] ?? ''))) === '' ? null : mb_substr($d, 0, 2000),
            'responsable_id' => $responsable,
            'suplente_id' => $suplente,
        ];

        // Montajes posibles con su aforo
        $montajes = [];
        foreach ((array) ($datos['montajes'] ?? []) as $montajeId => $fila) {
            if (! is_array($fila) || ! filter_var($fila['usar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $aforo = ($fila['aforo'] ?? '') === '' ? null : max(1, min(100000, (int) $fila['aforo']));
            if ($aforo !== null && $valores['aforo_maximo'] !== null && $aforo > $valores['aforo_maximo']) {
                throw ValidationException::withMessages(['montajes' => 'El aforo de un montaje no puede ser mayor que el aforo máximo del área.']);
            }
            $montajes[(int) $montajeId] = ['aforo' => $aforo];
        }
        $validos = ReservaMontaje::whereIn('id', array_keys($montajes))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $montajes = array_intersect_key($montajes, array_flip($validos));

        $antes = $area->only(array_keys($valores));
        DB::transaction(function () use ($area, $valores, $montajes, $foto, $datos) {
            if ($foto !== null) {
                $anterior = $area->foto;
                $valores['foto'] = ImagenSegura::guardar($foto, 'reservas/areas', 'foto', 'local');
                if ($anterior) {
                    Storage::disk('local')->delete($anterior);
                }
            } elseif (filter_var($datos['quitar_foto'] ?? false, FILTER_VALIDATE_BOOLEAN) && $area->foto) {
                Storage::disk('local')->delete($area->foto);
                $valores['foto'] = null;
            }
            $area->update($valores);
            $area->montajes()->sync($montajes);
        });
        $this->auditoria->auditar($actor, 'reservas.area_configurada', $area, $antes, $area->only(array_keys($valores)) + ['montajes' => $montajes]);
    }
}
