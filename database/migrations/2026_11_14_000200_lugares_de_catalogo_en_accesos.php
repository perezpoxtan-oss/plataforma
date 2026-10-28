<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 5 · C-30 (regla 2): en la Bitácora de accesos
 * la habitación, la vivienda y el área de trabajo se eligen del árbol de Zonas
 * y edificios. accesos.espacio_id guarda el lugar del catálogo; el texto de
 * siempre (habitacion, area_trabajo) se conserva para leer y para lo que no
 * está en el catálogo («sin catálogo»).
 *
 * Datos: los registros de antes cuya habitación (o vivienda) coincide con UNA
 * sola área específica de su sede quedan ligados a ella. Los demás siguen
 * con su texto.
 *
 * down(): quita la columna (el texto nunca se tocó).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accesos', function (Blueprint $table) {
            $table->unsignedBigInteger('espacio_id')->nullable()->after('habitacion');
            $table->index('espacio_id');
        });
        Restricciones::agregarForanea('accesos', 'espacio_id', 'espacios', 'set null');

        $porSede = [];
        foreach (DB::table('espacios')->where('nivel', 'area_especifica')->get(['id', 'sede_id', 'nombre']) as $e) {
            $porSede[(int) $e->sede_id][mb_strtolower(trim((string) $e->nombre))][] = (int) $e->id;
        }
        if ($porSede === []) {
            return;
        }
        DB::table('accesos')->whereNotNull('habitacion')->where('habitacion', '!=', '')->orderBy('id')
            ->select(['id', 'sede_id', 'habitacion'])->chunkById(500, function ($filas) use ($porSede) {
                foreach ($filas as $f) {
                    $ids = $porSede[(int) $f->sede_id][mb_strtolower(trim((string) $f->habitacion))] ?? [];
                    if (count($ids) === 1) {
                        DB::table('accesos')->where('id', $f->id)->update(['espacio_id' => $ids[0]]);
                    }
                }
            });
    }

    public function down(): void
    {
        Restricciones::quitarForanea('accesos', 'espacio_id');
        Schema::table('accesos', fn (Blueprint $table) => $table->dropIndex(['espacio_id']));
        Schema::table('accesos', fn (Blueprint $table) => $table->dropColumn('espacio_id'));
    }
};
