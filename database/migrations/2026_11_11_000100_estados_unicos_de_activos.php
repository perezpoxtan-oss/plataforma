<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-14 (Etapa 2): un solo ciclo de vida para llaves,
 * gafetes y equipos (App\Support\EstadoActivo).
 *
 *  - llaves.estado y gafetes.estado: disponible | no_regreso | baja. Se
 *    llenan desde `activo` (falso = baja); `activo` se conserva y el modelo
 *    lo mantiene igual.
 *  - equipos.estado: además de disponible, asignado, en_mantenimiento y baja,
 *    ahora en_uso (resguardo de «Turno»), fuera (salió en un pase) y
 *    no_regreso. Los que hoy están en una responsiva de Turno pasan a en_uso;
 *    los que salieron en un pase que no ha regresado pasan a fuera.
 *  - prestamos_llaves.estado: además, no_regreso («No regresó»).
 *  - CHECK de los cuatro estados (docs/tecnico/integridad-bd.md).
 *
 * down(): en_uso → asignado; fuera y no_regreso de equipos → disponible (como
 * se veían antes); préstamos no_regreso → en_uso (o devuelta si la llave ya
 * tiene otro préstamo vigente); quita las columnas nuevas y deja los CHECK
 * como estaban.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const ESTADOS_EQUIPO = ['disponible', 'en_uso', 'asignado', 'fuera', 'en_mantenimiento', 'no_regreso', 'baja'];

    private const ESTADOS_EQUIPO_ANTES = ['disponible', 'asignado', 'en_mantenimiento', 'baja'];

    private const ESTADOS_LLAVE_GAFETE = ['disponible', 'no_regreso', 'baja'];

    private const PASES_FUERA = ['salio', 'en_destino', 'en_transito_regreso', 'regreso_parcial'];

    public function up(): void
    {
        foreach (['llaves', 'gafetes'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'estado')) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->string('estado', 20)->default('disponible')->after('activo');
                });
            }
            DB::table($tabla)->where('activo', false)->update(['estado' => 'baja']);
            DB::table($tabla)->where('activo', true)->where('estado', 'baja')->update(['estado' => 'disponible']);
        }

        // Equipos de «Turno» en una responsiva sin recibir: EN USO
        $turno = DB::table('equipos_responsiva')->whereNull('devuelto_en')->where('modalidad', 'prestado')->pluck('equipo_id')->all();
        foreach (array_chunk($turno, 500) as $ids) {
            DB::table('equipos')->whereIn('id', $ids)->where('estado', 'asignado')->update(['estado' => 'en_uso']);
        }
        // Equipos que salieron en un pase que no ha regresado: FUERA
        $fuera = DB::table('pases_salida_articulos')
            ->join('pases_salida', 'pases_salida.id', '=', 'pases_salida_articulos.pase_salida_id')
            ->whereNotNull('pases_salida_articulos.equipo_id')
            ->whereIn('pases_salida.estado', self::PASES_FUERA)
            ->whereColumn('pases_salida_articulos.cantidad_regresada', '<', 'pases_salida_articulos.cantidad')
            ->pluck('pases_salida_articulos.equipo_id')->unique()->all();
        foreach (array_chunk($fuera, 500) as $ids) {
            DB::table('equipos')->whereIn('id', $ids)->where('estado', 'disponible')->update(['estado' => 'fuera']);
        }

        Restricciones::agregarChecks('equipos', ['estado' => self::ESTADOS_EQUIPO]);
        Restricciones::agregarChecks('llaves', ['estado' => self::ESTADOS_LLAVE_GAFETE]);
        Restricciones::agregarChecks('gafetes', ['estado' => self::ESTADOS_LLAVE_GAFETE]);
        if (Restricciones::fueraDeCatalogo('prestamos_llaves', 'estado', ['en_uso', 'devuelta', 'no_regreso']) === 0) {
            Restricciones::agregarChecks('prestamos_llaves', ['estado' => ['en_uso', 'devuelta', 'no_regreso']]);
        }
    }

    public function down(): void
    {
        Restricciones::quitarChecks('equipos', ['estado']);
        DB::table('equipos')->where('estado', 'en_uso')->update(['estado' => 'asignado']);
        DB::table('equipos')->whereIn('estado', ['fuera', 'no_regreso'])->update(['estado' => 'disponible']);

        // Préstamos «No regresó»: vuelven a «en uso», salvo que la llave ya tenga otro vigente
        foreach (DB::table('prestamos_llaves')->where('estado', 'no_regreso')->get(['id', 'llave_id']) as $p) {
            $otro = DB::table('prestamos_llaves')->where('llave_id', $p->llave_id)->where('estado', 'en_uso')->where('anulado', false)->exists();
            DB::table('prestamos_llaves')->where('id', $p->id)->update(['estado' => $otro ? 'devuelta' : 'en_uso']);
        }
        Restricciones::quitarChecks('prestamos_llaves', ['estado']);
        if (Restricciones::fueraDeCatalogo('prestamos_llaves', 'estado', ['en_uso', 'devuelta']) === 0) {
            Restricciones::agregarChecks('prestamos_llaves', ['estado' => ['en_uso', 'devuelta']]);
        }

        foreach (['llaves', 'gafetes'] as $tabla) {
            Restricciones::quitarChecks($tabla, ['estado']);
            if (Schema::hasColumn($tabla, 'estado')) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->dropColumn('estado');
                });
            }
        }
    }
};
