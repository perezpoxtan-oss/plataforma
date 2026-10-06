<?php

namespace App\Services\Borrado;

use App\Models\Auditoria;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Eliminar definitivamente": borrado físico controlado de catálogos y
 * padrones (ver RegistroBorrado y docs/tecnico/borrado.md).
 *
 * Antes de borrar busca TODO lo que apunta al registro:
 *  1. las llaves foráneas de la base de datos (Schema::getForeignKeys de
 *     todas las tablas, en caché): cualquier fila que apunte al registro es
 *     una dependencia, sin importar si la llave foránea borraría en cascada o
 *     dejaría el campo vacío (así nunca se pierde historia en silencio);
 *  2. referencias sin llave foránea declaradas en el registro (extras) y las
 *     polimórficas (vouchers: origen_tipo + origen_id).
 * Excepciones: las tablas puente "puras" (solo las dos llaves, p. ej.
 * proveedor_sede) y las tablas hijas propias del registro (horarios de una
 * llave) se borran con él; de las hijas también se revisa que nadie las use.
 *
 * Si hay dependencias no se borra nada: se explica qué lo usa y se ofrece la
 * baja. Si no, se borra en una transacción y queda en la auditoría el evento
 * "<modulo>.eliminado_definitivo" con la copia completa del registro (y de sus
 * relaciones) para poder reconstruirlo a mano.
 */
class BorradoSeguro
{
    /** Tablas puente que siempre cuentan como dependencia (usuarios con un rol o en una sede). */
    private const PUENTES_QUE_CUENTAN = ['usuario_roles'];

    /**
     * Referencias polimórficas: [tabla, columna del tipo, columna del id]. El
     * tipo de cada clase sale de VoucherReposicion::ORIGENES (llave, gafete, equipo).
     */
    private const POLIMORFICAS = [
        ['vouchers_reposicion', 'origen_tipo', 'origen_id'],
    ];

    /** Cómo se llama lo que depende del registro: tabla => [singular, plural]. */
    public const ETIQUETAS = [
        'accesos' => ['registro en la bitácora de accesos', 'registros en la bitácora de accesos'],
        'acompanantes_acceso' => ['acompañante en la bitácora de accesos', 'acompañantes en la bitácora de accesos'],
        'accidente_colaboradores' => ['reporte de accidente', 'reportes de accidente'],
        'colaboradores' => ['colaborador', 'colaboradores'],
        'colaborador_sede' => ['colaborador asignado', 'colaboradores asignados'],
        'departamentos' => ['departamento', 'departamentos'],
        'equipos' => ['equipo de seguridad', 'equipos de seguridad'],
        'equipos_pc' => ['equipo de Protección Civil', 'equipos de Protección Civil'],
        'equipos_responsiva' => ['equipo en una responsiva', 'equipos en responsivas'],
        'espacios' => ['zona o área dentro', 'zonas o áreas dentro'],
        'espacio_llave' => ['llave que la abre', 'llaves que la abren'],
        'gafetes' => ['gafete', 'gafetes'],
        'grupos_espacio' => ['sección', 'secciones'],
        'grupo_espacio_llave' => ['llave de la sección', 'llaves de la sección'],
        'llaves' => ['llave', 'llaves'],
        'lost_found_articulos' => ['artículo de Lost & Found', 'artículos de Lost & Found'],
        'lost_found_entregas' => ['entrega de Lost & Found', 'entregas de Lost & Found'],
        'lost_found_reportes_perdida' => ['reporte de pérdida', 'reportes de pérdida'],
        'movimientos_transporte' => ['movimiento de transporte', 'movimientos de transporte'],
        'movimiento_transporte_pasajeros' => ['viaje en la bitácora de transporte', 'viajes en la bitácora de transporte'],
        'novedades' => ['novedad', 'novedades'],
        'paraderos' => ['paradero', 'paraderos'],
        'pases_salida' => ['pase de salida', 'pases de salida'],
        'pases_salida_articulos' => ['artículo en un pase de salida', 'artículos en pases de salida'],
        'personas' => ['persona del padrón', 'personas del padrón'],
        'prestamos_llaves' => ['préstamo de llave', 'préstamos de llave'],
        'proveedor_sede' => ['empresa externa que opera aquí', 'empresas externas que operan aquí'],
        'recorridos_pc' => ['recorrido de Protección Civil', 'recorridos de Protección Civil'],
        'recorrido_pc_revisiones' => ['revisión de Protección Civil', 'revisiones de Protección Civil'],
        'responsivas' => ['responsiva', 'responsivas'],
        'rutas' => ['ruta de transporte', 'rutas de transporte'],
        'ruta_paradas' => ['parada en una ruta', 'paradas en rutas'],
        'users' => ['usuario', 'usuarios'],
        'usuario_roles' => ['usuario asignado', 'usuarios asignados'],
        'valores_vista_detalles' => ['reporte de valores a la vista', 'reportes de valores a la vista'],
        'vehiculos' => ['vehículo', 'vehículos'],
        'vouchers_reposicion' => ['voucher', 'vouchers'],
        'zonas_estacionamiento' => ['zona de estacionamiento', 'zonas de estacionamiento'],
        // Procedimientos
        'procedimiento_aplicaciones' => ['procedimiento que le aplica', 'procedimientos que le aplican'],
        'procedimiento_acuses' => ['acuse de lectura', 'acuses de lectura'],
    ];

    /** @var array{referencias: array<string, list<array{0: string, 1: string}>>, puentes: array<string, true>}|null */
    private ?array $mapa = null;

    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * Lo que impide eliminar el registro: [tabla => cantidad] más los motivos
     * propios del módulo (p. ej. "Es la única sede de la empresa.").
     *
     * @return array{conteos: array<string, int>, motivos: list<string>}
     */
    public function dependencias(array $definicion, Model $registro, ?User $actor = null): array
    {
        $plan = $this->plan($definicion, $registro);
        $motivo = $actor === null ? null : RegistroBorrado::verificar($definicion, $registro, $actor);

        return ['conteos' => $plan['conteos'], 'motivos' => $motivo === null ? [] : [$motivo]];
    }

    /**
     * "Tiene 3 préstamos de llave y 1 voucher: no se puede eliminar; puedes darla de baja."
     *
     * @param  array{conteos: array<string, int>, motivos: list<string>}  $dependencias
     */
    public function mensaje(array $definicion, array $dependencias): ?string
    {
        $partes = [];
        foreach ($dependencias['conteos'] as $tabla => $n) {
            [$uno, $varios] = self::ETIQUETAS[$tabla] ?? ['registro en '.str_replace('_', ' ', $tabla), 'registros en '.str_replace('_', ' ', $tabla)];
            $partes[] = $n.' '.($n === 1 ? $uno : $varios);
        }

        $frases = $dependencias['motivos'];
        if ($partes !== []) {
            $ultima = array_pop($partes);
            $frases[] = 'Tiene '.($partes === [] ? $ultima : implode(', ', $partes).' y '.$ultima).'.';
        }

        if ($frases === []) {
            return null;
        }

        $lo = $definicion['tipo'][1] === 'f' ? 'darla' : 'darlo';

        return rtrim(implode(' ', $frases), '.').": no se puede eliminar; puedes {$lo} de baja.";
    }

    /**
     * Borra el registro (con sus tablas puente y sus tablas hijas propias) si
     * nada depende de él. Lanza \DomainException con la explicación si algo lo usa.
     */
    public function eliminar(User $actor, array $definicion, Model $registro): void
    {
        DB::transaction(function () use ($actor, $definicion, $registro) {
            // Se revisa otra vez dentro de la transacción (alguien pudo usarlo mientras se confirmaba)
            $plan = $this->plan($definicion, $registro);
            $mensaje = $this->mensaje($definicion, [
                'conteos' => $plan['conteos'],
                'motivos' => array_filter([RegistroBorrado::verificar($definicion, $registro, $actor)]),
            ]);
            if ($mensaje !== null) {
                throw new \DomainException($mensaje);
            }

            $copia = $registro->getAttributes();
            $relaciones = [];

            try {
                // Primero lo más profundo (paradas antes que horarios), luego las tablas puente
                foreach (array_reverse($plan['borrar']) as [$tabla, $columna, $ids]) {
                    $filas = DB::table($tabla)->whereIn($columna, $ids)->get()->map(fn ($f) => (array) $f)->all();
                    if ($filas !== []) {
                        $relaciones[$tabla] = [...($relaciones[$tabla] ?? []), ...$filas];
                        DB::table($tabla)->whereIn($columna, $ids)->delete();
                    }
                }

                method_exists($registro, 'forceDelete') ? $registro->forceDelete() : $registro->delete();
            } catch (QueryException $e) {
                // Una llave foránea que no se detectó: no se borra nada
                throw new \DomainException('Otro registro todavía lo usa: no se puede eliminar; puedes '
                    .($definicion['tipo'][1] === 'f' ? 'darla' : 'darlo').' de baja.', 0, $e);
            }

            if ($relaciones !== []) {
                $copia['relaciones'] = $relaciones;
            }

            Auditoria::create([
                'empresa_id' => $registro->getAttributes()['empresa_id'] ?? $actor->empresa_id,
                'user_id' => $actor->id,
                'evento' => $definicion['modulo'].'.eliminado_definitivo',
                'auditable_type' => $registro::class,
                'auditable_id' => $registro->getKey(),
                'antes' => $copia,
                'despues' => null,
                'ip' => request()?->ip(),
            ]);
        });

        // Un rol borrado ya no da permisos
        $this->autorizador->olvidar();
    }

    /**
     * Recorre las referencias del registro: cuenta las dependencias y arma la
     * lista de lo que se borra con él.
     *
     * @return array{conteos: array<string, int>, borrar: list<array{0: string, 1: string, 2: list<int>}>}
     */
    private function plan(array $definicion, Model $registro): array
    {
        $conteos = [];
        $borrar = [];
        $tabla = $registro->getTable();
        $id = (int) $registro->getKey();

        $this->recorrer($tabla, [$id], $definicion, $conteos, $borrar, [$tabla => true]);

        // Referencias sin llave foránea declaradas por el módulo
        $conFk = collect($this->referenciasA($tabla))->map(fn ($r) => $r[0].'.'.$r[1])->all();
        $extras = [];
        foreach ($definicion['extras'] ?? [] as [$otra, $columna]) {
            if (! in_array($otra.'.'.$columna, $conFk, true) && Schema::hasColumn($otra, $columna)) {
                $extras[$otra][] = $columna;
            }
        }
        foreach ($extras as $otra => $columnas) {
            $this->sumar($conteos, $otra, $this->contar($otra, $columnas, [$id]));
        }

        // Polimórficas (vouchers de una llave, un gafete o un equipo)
        $tipo = array_search($registro::class, array_map(fn ($o) => $o[2], VoucherReposicion::ORIGENES), true);
        foreach (self::POLIMORFICAS as [$otra, $columnaTipo, $columnaId]) {
            if ($tipo !== false) {
                $this->sumar($conteos, $otra, DB::table($otra)->where($columnaTipo, $tipo)->where($columnaId, $id)->count());
            }
        }

        return ['conteos' => array_filter($conteos), 'borrar' => $borrar];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, int>  $conteos
     * @param  list<array{0: string, 1: string, 2: list<int>}>  $borrar
     * @param  array<string, true>  $visitadas
     */
    private function recorrer(string $tabla, array $ids, array $definicion, array &$conteos, array &$borrar, array $visitadas): void
    {
        $propios = $definicion['propios'] ?? [];
        $cuentan = [...($definicion['cuentan'] ?? []), ...self::PUENTES_QUE_CUENTAN];
        $porTabla = [];

        foreach ($this->referenciasA($tabla) as [$otra, $columna]) {
            if ($otra === $tabla) {
                // Auto-referencia (zonas dentro de una zona, colaborador unido a otro)
                $this->sumar($conteos, $otra, DB::table($otra)->whereIn($columna, $ids)->whereNotIn('id', $ids)->count());

                continue;
            }

            if (in_array($otra, $propios, true) && ! isset($visitadas[$otra])) {
                $hijos = DB::table($otra)->whereIn($columna, $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
                if ($hijos !== []) {
                    $borrar[] = [$otra, $columna, $ids];
                    $this->recorrer($otra, $hijos, $definicion, $conteos, $borrar, $visitadas + [$otra => true]);
                }

                continue;
            }

            if ($this->esPuente($otra) && ! in_array($otra, $cuentan, true)) {
                $borrar[] = [$otra, $columna, $ids];

                continue;
            }

            $porTabla[$otra][] = $columna;
        }

        foreach ($porTabla as $otra => $columnas) {
            $this->sumar($conteos, $otra, $this->contar($otra, $columnas, $ids));
        }
    }

    /**
     * Filas distintas de $tabla que apuntan a alguno de los ids por cualquiera de las columnas.
     *
     * @param  list<string>  $columnas
     * @param  list<int>  $ids
     */
    private function contar(string $tabla, array $columnas, array $ids): int
    {
        return DB::table($tabla)->where(function ($q) use ($columnas, $ids) {
            foreach ($columnas as $columna) {
                $q->orWhereIn($columna, $ids);
            }
        })->count();
    }

    /**
     * @param  array<string, int>  $conteos
     */
    private function sumar(array &$conteos, string $tabla, int $n): void
    {
        if ($n > 0) {
            $conteos[$tabla] = ($conteos[$tabla] ?? 0) + $n;
        }
    }

    /**
     * Tablas y columnas que apuntan a $tabla según las llaves foráneas.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function referenciasA(string $tabla): array
    {
        return $this->mapa()['referencias'][$tabla] ?? [];
    }

    /**
     * Tabla puente "pura": solo sus llaves foráneas (y quizá id y fechas), sin datos propios.
     */
    public function esPuente(string $tabla): bool
    {
        return isset($this->mapa()['puentes'][$tabla]);
    }

    /**
     * Mapa de llaves foráneas de toda la base. Se guarda en caché y cambia
     * solo cuando cambia la última migración aplicada.
     *
     * @return array{referencias: array<string, list<array{0: string, 1: string}>>, puentes: array<string, true>}
     */
    private function mapa(): array
    {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $version = (string) DB::table('migrations')->max('migration');

        return $this->mapa = Cache::remember('borrado.llaves_foraneas.'.md5($version), now()->addDay(), function () {
            $referencias = [];
            $puentes = [];

            foreach (Schema::getTableListing(schemaQualified: false) as $tabla) {
                $columnasFk = [];
                foreach (Schema::getForeignKeys($tabla) as $fk) {
                    if (count($fk['columns']) !== 1) {
                        continue;
                    }
                    $columnasFk[] = $fk['columns'][0];
                    $referencias[$fk['foreign_table']][] = [$tabla, $fk['columns'][0]];
                }

                $propias = array_diff(Schema::getColumnListing($tabla), $columnasFk, ['id', 'created_at', 'updated_at']);
                if (count($columnasFk) >= 2 && $propias === []) {
                    $puentes[$tabla] = true;
                }
            }

            return ['referencias' => $referencias, 'puentes' => $puentes];
        });
    }
}
