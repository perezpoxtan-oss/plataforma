<?php

use App\Support\BaseDatos\CatalogoChecks;
use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, C-16 (DD-08): quien recibe una llave o un equipo, o
 * responde por una pérdida, puede ser un colaborador, una persona del Padrón
 * de personas o una empresa externa (App\Support\Receptor).
 *
 *  - prestamos_llaves y responsivas: receptor_tipo + receptor_id; colaborador_id
 *    pasa a opcional (solo se llena cuando es un colaborador).
 *  - vouchers_reposicion: responsable_tipo + responsable_id.
 *  - Datos: lo de antes queda con receptor/responsable «colaborador» y su colaborador_id.
 *
 * down(): quita las columnas (el responsable externo de un voucher queda
 * escrito en su «¿Cómo pasó?»). Si ya hay préstamos o responsivas a nombre de
 * personas o empresas externas, se detiene con un mensaje (perderían a quién se
 * entregó): primero hay que recibirlos y respaldar la base.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLAS = ['prestamos_llaves' => 'receptor', 'responsivas' => 'receptor', 'vouchers_reposicion' => 'responsable'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla => $prefijo) {
            if (! Schema::hasColumn($tabla, $prefijo.'_tipo')) {
                Schema::table($tabla, function (Blueprint $t) use ($prefijo) {
                    $t->string($prefijo.'_tipo', 20)->nullable()->after('colaborador_id');
                    $t->unsignedBigInteger($prefijo.'_id')->nullable()->after($prefijo.'_tipo');
                    $t->index([$prefijo.'_tipo', $prefijo.'_id']);
                });
            }
            DB::table($tabla)->whereNotNull('colaborador_id')->whereNull($prefijo.'_tipo')
                ->update([$prefijo.'_tipo' => 'colaborador', $prefijo.'_id' => DB::raw('colaborador_id')]);
        }

        // En SQLite cambiar la columna reconstruye la tabla: sin revisar llaves foráneas mientras tanto
        $this->diferirLlavesEnSqlite();
        Schema::withoutForeignKeyConstraints(function () {
            foreach (['prestamos_llaves', 'responsivas'] as $tabla) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->unsignedBigInteger('colaborador_id')->nullable()->change();
                });
                $this->volverAPonerChecks($tabla);
            }
        });
    }

    public function down(): void
    {
        foreach (['prestamos_llaves', 'responsivas'] as $tabla) {
            $ajenos = DB::table($tabla)->whereNull('colaborador_id')->count();
            if ($ajenos > 0) {
                throw new RuntimeException("Hay {$ajenos} registros de {$tabla} entregados a personas o empresas externas: recíbelos (o respáldalos) antes de revertir.");
            }
        }
        $this->conservarResponsablesExternos();
        $this->diferirLlavesEnSqlite();
        Schema::withoutForeignKeyConstraints(function () {
            foreach (['prestamos_llaves', 'responsivas'] as $tabla) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->unsignedBigInteger('colaborador_id')->nullable(false)->change();
                });
                $this->volverAPonerChecks($tabla);
            }
        });
        foreach (self::TABLAS as $tabla => $prefijo) {
            if (Schema::hasColumn($tabla, $prefijo.'_tipo')) {
                Schema::table($tabla, function (Blueprint $t) use ($prefijo) {
                    $t->dropIndex([$prefijo.'_tipo', $prefijo.'_id']);
                    $t->dropColumn([$prefijo.'_tipo', $prefijo.'_id']);
                });
            }
        }
    }

    /**
     * Revisión Etapa 2 (A-7): un voucher cobrado a una persona o empresa
     * externa no tiene colaborador; al quitar responsable_tipo/_id el dato se
     * conserva al final de su «¿Cómo pasó?» (descripcion) y se anota en el
     * registro de la aplicación cuántos fueron.
     */
    private function conservarResponsablesExternos(): void
    {
        if (! Schema::hasColumn('vouchers_reposicion', 'responsable_tipo')) {
            return;
        }
        $externos = DB::table('vouchers_reposicion')->whereIn('responsable_tipo', ['persona', 'proveedor'])->get(['id', 'responsable_tipo', 'responsable_id', 'descripcion']);
        foreach ($externos as $v) {
            $nombre = $v->responsable_tipo === 'persona'
                ? DB::table('personas')->where('id', $v->responsable_id)->value('nombre_completo')
                : DB::table('proveedores')->where('id', $v->responsable_id)->value('nombre');
            $nota = 'Responsable del cobro: '.($nombre ?? '#'.$v->responsable_id).' ('.($v->responsable_tipo === 'persona' ? 'persona externa' : 'empresa externa').').';
            if (! str_contains((string) $v->descripcion, $nota)) {
                DB::table('vouchers_reposicion')->where('id', $v->id)->update(['descripcion' => trim(((string) $v->descripcion)."\n".$nota)]);
            }
        }
        if ($externos->isNotEmpty()) {
            Log::warning("Reversión del receptor general: {$externos->count()} vouchers cobrados a personas o empresas externas conservan su responsable en «¿Cómo pasó?».");
        }
    }

    /**
     * Dentro de una transacción (pruebas) SQLite no deja apagar las llaves
     * foráneas; diferirlas hasta el final permite reconstruir la tabla.
     */
    private function diferirLlavesEnSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
    }

    /** Cambiar la columna reconstruye la tabla en SQLite: el CHECK del estado se vuelve a poner. */
    private function volverAPonerChecks(string $tabla): void
    {
        $columnas = CatalogoChecks::todas()[$tabla] ?? [];
        foreach ($columnas as $columna => $valores) {
            $actuales = DB::table($tabla)->distinct()->pluck($columna)->filter()->map(fn ($v) => (string) $v)->all();
            $valores = array_values(array_unique([...$valores, ...array_intersect($actuales, ['en_uso', 'devuelta', 'no_regreso', 'en_campo'])]));
            if (Restricciones::valoresDelCheck($tabla, $columna) === null && Restricciones::fueraDeCatalogo($tabla, $columna, $valores) === 0) {
                Restricciones::agregarChecks($tabla, [$columna => $valores]);
            }
        }
    }
};
