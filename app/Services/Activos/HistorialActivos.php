<?php

namespace App\Services\Activos;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\MovimientoActivo;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Support\EstadoActivo;
use App\Support\Receptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * C-45 (DD-19): historial único «Entregar / Recibir» de llaves, gafetes,
 * equipos de seguridad y credenciales (tabla movimientos_activo).
 *
 * Los módulos dueños NO cambian: el historial se escribe solo al guardarse
 * sus registros (eventos de Eloquent, ver registrarEventos()) y, donde la
 * Bitácora de accesos actualiza en bloque, con una llamada explícita
 * (gafetesRecibidos()). Así Préstamo de llaves, Gafetes, Responsivas y Pases
 * de salida conservan sus pantallas, impresiones, reportes y reglas.
 *
 * Un fallo al escribir el historial nunca detiene la operación de la caseta:
 * se informa al registro de errores y se sigue.
 */
class HistorialActivos
{
    /** Resultado de la recepción que está en curso (Dañado), para el evento que la registra. */
    private static ?string $resultadoEnCurso = null;

    private static ?bool $hayTabla = null;

    /**
     * Ejecuta $fn marcando que lo que se reciba dentro viene con $resultado
     * (p. ej. una llave que regresa dañada: el préstamo se recibe igual).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function conResultado(string $resultado, callable $fn): mixed
    {
        $antes = self::$resultadoEnCurso;
        self::$resultadoEnCurso = $resultado;
        try {
            return $fn();
        } finally {
            self::$resultadoEnCurso = $antes;
        }
    }

    /**
     * Quién lo tiene se guarda como referencia (receptor del Padrón o el
     * acceso de origen), nunca como texto: el nombre se lee al mostrarlo.
     *
     * @param  array<string, mixed>  $datos  sede_id, resultado, origen_tipo, origen_id, receptor (Model), detalle
     */
    public function registrar(int $empresaId, string $tipo, int $id, string $accion, array $datos = []): void
    {
        if (! self::tabla()) {
            return;
        }
        try {
            $receptor = $datos['receptor'] ?? null;
            $fila = new MovimientoActivo([
                'sede_id' => $datos['sede_id'] ?? null,
                'activo_tipo' => $tipo,
                'activo_id' => $id,
                'accion' => $accion,
                'resultado' => $datos['resultado'] ?? null,
                'origen_tipo' => $datos['origen_tipo'] ?? null,
                'origen_id' => $datos['origen_id'] ?? null,
                'receptor_tipo' => $receptor instanceof Model ? Receptor::tipoDe($receptor) : ($datos['receptor_tipo'] ?? null),
                'receptor_id' => $receptor instanceof Model ? (int) $receptor->getKey() : ($datos['receptor_id'] ?? null),
                'detalle' => isset($datos['detalle']) ? mb_substr((string) $datos['detalle'], 0, 250) : null,
            ]);
            $fila->forceFill(['empresa_id' => $empresaId])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Historial de un objeto, del más reciente al más antiguo.
     *
     * @return Collection<int, MovimientoActivo>
     */
    public function de(string $tipo, int $id, int $limite = 100): Collection
    {
        if (! self::tabla()) {
            return collect();
        }

        $filas = MovimientoActivo::with('autor:id,name')->where('activo_tipo', $tipo)->where('activo_id', $id)
            ->orderByDesc('id')->limit($limite)->get();

        // Nombres: del Padrón (colaborador, persona, empresa) o del acceso de origen, con pocas consultas
        $accesos = Acceso::query()->whereIn('id', $filas->where('origen_tipo', 'acceso')->pluck('origen_id')->filter()->unique())->pluck('nombre', 'id');
        $acompanantes = AcompananteAcceso::query()->whereIn('id', $filas->where('origen_tipo', 'acompanante')->pluck('origen_id')->filter()->unique())->get();
        $receptores = [];
        foreach ($filas as $f) {
            $clave = $f->receptor_tipo.':'.$f->receptor_id;
            $f->setAttribute('quien', match (true) {
                $f->receptor_tipo !== null && $f->receptor_id !== null => $receptores[$clave] ??= Receptor::nombreDe(Receptor::cargar($f->receptor_tipo, (int) $f->receptor_id)),
                $f->origen_tipo === 'acceso' => $accesos[$f->origen_id] ?? null,
                $f->origen_tipo === 'acompanante' => $acompanantes->firstWhere('id', $f->origen_id)?->nombreVisible(),
                default => null,
            });
        }

        return $filas;
    }

    /**
     * Bitácora de accesos: al dar salida, los gafetes que sí devolvieron
     * (el titular y los acompañantes que seguían dentro) se reciben en OK.
     * Los que no, ya quedaron «No regresó» por el evento del gafete.
     *
     * @param  list<int>  $gafetes
     */
    public function gafetesRecibidos(Acceso $acceso, array $gafetes, ?AcompananteAcceso $acompanante = null): void
    {
        foreach (array_unique(array_filter($gafetes)) as $gafeteId) {
            $this->registrar((int) $acceso->empresa_id, 'gafete', (int) $gafeteId, 'recepcion', ['resultado' => 'ok', 'sede_id' => $acceso->sede_id,
                'origen_tipo' => $acompanante ? 'acompanante' : 'acceso', 'origen_id' => $acompanante?->id ?? $acceso->id]);
        }
    }

    // ------------------------------------------------------------- Eventos

    /** Se llama una vez desde AppServiceProvider::boot(). */
    public static function registrarEventos(): void
    {
        $h = fn (): self => app(self::class);

        // Préstamo de llaves: prestar, recibir, «No regresó» y anular
        PrestamoLlave::created(function (PrestamoLlave $p) use ($h) {
            $h()->registrar((int) $p->empresa_id, 'llave', (int) $p->llave_id, 'salida', ['sede_id' => $p->sede_id, 'origen_tipo' => 'prestamo_llave',
                'origen_id' => $p->id, 'receptor' => self::receptor($p), 'detalle' => 'Garantía: '.(PrestamoLlave::GARANTIAS[$p->tipo_garantia] ?? $p->tipo_garantia)]);
        });
        PrestamoLlave::updated(function (PrestamoLlave $p) use ($h) {
            $base = ['sede_id' => $p->sede_id, 'origen_tipo' => 'prestamo_llave', 'origen_id' => $p->id, 'receptor' => self::receptor($p)];
            if ($p->wasChanged('estado') && $p->estado === PrestamoLlave::DEVUELTA) {
                $h()->registrar((int) $p->empresa_id, 'llave', (int) $p->llave_id, 'recepcion', $base + ['resultado' => self::$resultadoEnCurso ?? 'ok']);
            } elseif ($p->wasChanged('estado') && $p->estado === PrestamoLlave::NO_REGRESO) {
                $h()->registrar((int) $p->empresa_id, 'llave', (int) $p->llave_id, 'no_regreso', $base);
            }
            if ($p->wasChanged('anulado') && $p->anulado) {
                $h()->registrar((int) $p->empresa_id, 'llave', (int) $p->llave_id, 'anulado', $base);
            }
        });

        // Responsivas: cada equipo que se entrega y cómo regresa (OK, dañado o faltante)
        EquipoResponsiva::created(function (EquipoResponsiva $f) use ($h) {
            $r = Responsiva::find($f->responsiva_id);
            $h()->registrar((int) $f->empresa_id, 'equipo', (int) $f->equipo_id, 'salida', ['sede_id' => $r?->sede_id, 'origen_tipo' => 'responsiva',
                'origen_id' => $f->responsiva_id, 'receptor' => $r === null ? null : self::receptor($r),
                'detalle' => trim(($r?->folio ?? '').' · '.(EquipoResponsiva::MODALIDADES[$f->modalidad] ?? $f->modalidad), ' ·')]);
        });
        EquipoResponsiva::updated(function (EquipoResponsiva $f) use ($h) {
            $atributos = $f->getAttributes();
            if (! $f->wasChanged('devuelto_en') || ($atributos['devuelto_en'] ?? null) === null || ($atributos['estado_devolucion'] ?? null) === 'baja') {
                return;
            }
            $r = Responsiva::find($f->responsiva_id);
            $resultado = ['ok' => 'ok', 'danado' => 'danado', 'faltante' => 'no_regreso'][$atributos['estado_devolucion'] ?? 'ok'] ?? 'ok';
            $h()->registrar((int) $f->empresa_id, 'equipo', (int) $f->equipo_id, 'recepcion', ['resultado' => $resultado, 'sede_id' => $r?->sede_id,
                'origen_tipo' => 'responsiva', 'origen_id' => $f->responsiva_id, 'receptor' => $r === null ? null : self::receptor($r),
                'detalle' => $r?->folio]);
        });

        // Bitácora de accesos: el gafete que se presta al titular o a un acompañante
        Acceso::created(function (Acceso $a) use ($h) {
            $atributos = $a->getAttributes();
            if (($atributos['gafete_id'] ?? null) !== null && ($atributos['movimiento'] ?? 'entrada') === 'entrada') {
                $h()->registrar((int) $a->empresa_id, 'gafete', (int) $atributos['gafete_id'], 'salida', ['sede_id' => $a->sede_id, 'origen_tipo' => 'acceso',
                    'origen_id' => $a->id]);
            }
        });
        AcompananteAcceso::created(function (AcompananteAcceso $ac) use ($h) {
            $atributos = $ac->getAttributes();
            if (($atributos['gafete_id'] ?? null) !== null) {
                $acceso = Acceso::query()->select(['id', 'empresa_id', 'sede_id'])->find($ac->acceso_id);
                $h()->registrar((int) ($acceso->empresa_id ?? $ac->empresa_id), 'gafete', (int) $atributos['gafete_id'], 'salida', ['sede_id' => $acceso?->sede_id,
                    'origen_tipo' => 'acompanante', 'origen_id' => $ac->id]);
            }
        });

        // El estado del objeto: «No regresó», baja, reactivación, mantenimiento y pases de salida
        foreach ([Llave::class => 'llave', Gafete::class => 'gafete', Equipo::class => 'equipo'] as $clase => $tipo) {
            $clase::updated(function (Model $activo) use ($h, $tipo) {
                if (! $activo->wasChanged('estado')) {
                    return;
                }
                $antes = $activo->getOriginal('estado');
                $ahora = $activo->getAttribute('estado');
                $base = ['sede_id' => $activo->getAttribute('sede_id'), 'origen_tipo' => 'catalogo'];
                $accion = match (true) {
                    $ahora === EstadoActivo::BAJA => 'baja',
                    $antes === EstadoActivo::BAJA => 'reactivacion',
                    // La llave «No regresó» la registra su préstamo; el equipo de una responsiva, al recibirse
                    $ahora === EstadoActivo::NO_REGRESO && $tipo !== 'llave' => 'no_regreso',
                    $antes === EstadoActivo::NO_REGRESO && $ahora === EstadoActivo::DISPONIBLE && $tipo !== 'llave' => 'recepcion',
                    $tipo === 'equipo' && $ahora === EstadoActivo::FUERA => 'salida',
                    $tipo === 'equipo' && $antes === EstadoActivo::FUERA && $ahora === EstadoActivo::DISPONIBLE => 'recepcion',
                    $tipo === 'equipo' && $ahora === EstadoActivo::EN_MANTENIMIENTO => 'mantenimiento',
                    default => null,
                };
                if ($accion === null) {
                    return;
                }
                if ($tipo === 'equipo' && ($ahora === EstadoActivo::FUERA || $antes === EstadoActivo::FUERA)) {
                    $base['origen_tipo'] = 'pase_salida';
                }
                $h()->registrar((int) $activo->getAttribute('empresa_id'), $tipo, (int) $activo->getKey(), $accion,
                    $base + ($accion === 'recepcion' ? ['resultado' => self::$resultadoEnCurso ?? 'ok'] : []));
            });
        }
    }

    private static function receptor(Model $registro): ?Model
    {
        $atributos = $registro->getAttributes();
        $tipo = $atributos['receptor_tipo'] ?? Receptor::COLABORADOR;
        $id = $atributos['receptor_id'] ?? ($atributos['colaborador_id'] ?? null);

        return Receptor::cargar($tipo, $id === null ? null : (int) $id);
    }

    private static function tabla(): bool
    {
        return self::$hayTabla ??= Schema::hasTable('movimientos_activo');
    }

    /** Pruebas: después de migrar o revertir, volver a preguntar si existe la tabla. */
    public static function olvidarTabla(): void
    {
        self::$hayTabla = null;
    }
}
