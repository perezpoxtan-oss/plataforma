<?php

namespace App\Services\BaseDatos;

use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\ConfiguracionPlataforma;
use App\Models\Postulacion;
use App\Models\Ruta;
use App\Services\Candidatos\Postulaciones;
use App\Support\BaseDatos\CatalogoChecks;
use App\Support\BaseDatos\Restricciones;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;

/**
 * Revisión de coherencia de la base (docs/tecnico/integridad-bd.md, «Un solo
 * valor verdad»). Compara los datos que se guardan dos veces con su fuente:
 *
 * - rutas.hora_inicio / hora_fin / dias  ←  sus horarios (Ruta::resumenDeHorarios)
 * - candidatos.etapa (y el resto del espejo)  ←  su postulación activa
 * - movimiento_transporte_pasajeros.empresa_id  ←  su movimiento
 * - estados, etapas y categorías fuera de las constantes (solo informa: no
 *   hay un valor correcto que se pueda deducir)
 *
 * Sin reparar es solo lectura. Con reparar, corrige desde la fuente y deja
 * una fila en la Bitácora de auditoría por registro («plataforma.coherencia_reparada»).
 * Revisa todas las empresas (sin empresa activa).
 */
class RevisionCoherencia
{
    public const CLAVE = 'coherencia_revision';

    /** Hora local desde la que corre la tarea diaria (después del respaldo de las 3:00). */
    public const HORA = 4;

    public function __construct(private readonly Postulaciones $postulaciones) {}

    public function toca(): bool
    {
        $ahora = now()->setTimezone(HoraLocal::ZONA_PLATAFORMA);
        if ((int) $ahora->format('G') < self::HORA) {
            return false;
        }
        $ultima = ConfiguracionPlataforma::where('clave', self::CLAVE)->value('valor');

        return ($ultima['fecha'] ?? null) !== $ahora->toDateString();
    }

    /**
     * @return array{rutas: list<array<string, mixed>>, candidatos: list<array<string, mixed>>, pasajeros: list<array<string, mixed>>, fuera_de_catalogo: array<string, int>, reparados: int}
     */
    public function revisar(bool $reparar = false): array
    {
        $r = ['rutas' => $this->rutas(), 'candidatos' => $this->candidatos(), 'pasajeros' => $this->pasajeros(), 'fuera_de_catalogo' => $this->fueraDeCatalogo(), 'reparados' => 0];
        if ($reparar) {
            $r['reparados'] = $this->reparar($r);
        }

        return $r;
    }

    /** Diferencias que se pueden reparar (sin contar los valores fuera de catálogo). */
    public static function diferencias(array $r): int
    {
        return count($r['rutas']) + count($r['candidatos']) + count($r['pasajeros']);
    }

    /** Anota la corrida diaria (para --si-toca). */
    public function anotar(array $r): void
    {
        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => self::CLAVE]);
        $registro->valor = [
            'fecha' => now()->setTimezone(HoraLocal::ZONA_PLATAFORMA)->toDateString(), 'en' => now()->toIso8601String(),
            'rutas' => count($r['rutas']), 'candidatos' => count($r['candidatos']), 'pasajeros' => count($r['pasajeros']),
            'fuera_de_catalogo' => $r['fuera_de_catalogo'], 'reparados' => $r['reparados'],
        ];
        $registro->save();
    }

    // ------------------------------------------------------------ Revisiones

    /** @return list<array<string, mixed>> */
    private function rutas(): array
    {
        $r = [];
        Ruta::withoutGlobalScopes()->with(['horarios' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')
            ->chunk(200, function ($rutas) use (&$r) {
                foreach ($rutas as $ruta) {
                    if ($ruta->horarios->isEmpty()) {
                        continue; // sin horarios no hay de dónde calcular (no debería existir)
                    }
                    $esperado = Ruta::resumenDeHorarios($ruta->horarios);
                    $actual = ['hora_inicio' => $ruta->getRawOriginal('hora_inicio'), 'hora_fin' => $ruta->getRawOriginal('hora_fin'), 'dias' => $ruta->getRawOriginal('dias')];
                    if (Ruta::normalizarResumen($actual) !== Ruta::normalizarResumen($esperado)) {
                        $r[] = ['id' => $ruta->id, 'empresa_id' => $ruta->empresa_id, 'nombre' => $ruta->nombre, 'actual' => $actual, 'esperado' => $esperado];
                    }
                }
            });

        return $r;
    }

    /** @return list<array<string, mixed>> */
    private function candidatos(): array
    {
        $activas = DB::table('postulaciones')->selectRaw('candidato_id, max(id) as id')->groupBy('candidato_id');

        return DB::table('candidatos as c')
            ->joinSub($activas, 'a', 'a.candidato_id', '=', 'c.id')
            ->join('postulaciones as p', 'p.id', '=', 'a.id')
            ->where(fn ($q) => $q->whereColumn('c.etapa', '<>', 'p.etapa')->orWhereNull('c.etapa'))
            ->orderBy('c.id')
            ->get(['c.id', 'c.empresa_id', 'c.etapa as actual', 'p.etapa as esperado', 'p.id as postulacion_id'])
            ->map(fn ($f) => (array) $f)->all();
    }

    /** @return list<array<string, mixed>> */
    private function pasajeros(): array
    {
        return DB::table('movimiento_transporte_pasajeros as p')
            ->join('movimientos_transporte as m', 'm.id', '=', 'p.movimiento_transporte_id')
            ->where(fn ($q) => $q->whereColumn('p.empresa_id', '<>', 'm.empresa_id')->orWhereNull('p.empresa_id'))
            ->orderBy('p.id')
            ->get(['p.id', 'p.empresa_id as actual', 'm.empresa_id as esperado'])
            ->map(fn ($f) => (array) $f)->all();
    }

    /** @return array<string, int> «tabla.columna» => filas */
    private function fueraDeCatalogo(): array
    {
        $r = [];
        foreach (CatalogoChecks::todas() as $tabla => $columnas) {
            foreach ($columnas as $columna => $valores) {
                $n = Restricciones::fueraDeCatalogo($tabla, $columna, $valores);
                if ($n > 0) {
                    $r["{$tabla}.{$columna}"] = $n;
                }
            }
        }

        return $r;
    }

    // ------------------------------------------------------------ Reparar

    private function reparar(array $r): int
    {
        $n = 0;
        foreach ($r['rutas'] as $d) {
            DB::transaction(function () use ($d) {
                Ruta::withoutGlobalScopes()->whereKey($d['id'])->update($d['esperado']);
                $this->auditar($d['empresa_id'], Ruta::class, $d['id'], $d['actual'], $d['esperado'], 'resumen de la ruta desde sus horarios');
            });
            $n++;
        }
        foreach ($r['candidatos'] as $d) {
            DB::transaction(function () use ($d) {
                $p = Postulacion::withoutGlobalScopes()->find($d['postulacion_id']);
                if ($p !== null) {
                    $this->postulaciones->reflejar($p);
                }
                $this->auditar($d['empresa_id'], Candidato::class, $d['id'], ['etapa' => $d['actual']], ['etapa' => $d['esperado']], 'ficha del candidato desde su postulación activa');
            });
            $n++;
        }
        foreach ($r['pasajeros'] as $d) {
            DB::table('movimiento_transporte_pasajeros')->where('id', $d['id'])->update(['empresa_id' => $d['esperado']]);
            $n++;
        }

        return $n;
    }

    private function auditar(?int $empresaId, string $tipo, int $id, array $antes, array $despues, string $que): void
    {
        Auditoria::create([
            'empresa_id' => $empresaId, 'user_id' => null, 'evento' => 'plataforma.coherencia_reparada',
            'auditable_type' => $tipo, 'auditable_id' => $id, 'antes' => $antes, 'despues' => $despues + ['reparado' => $que], 'ip' => null,
        ]);
    }
}
