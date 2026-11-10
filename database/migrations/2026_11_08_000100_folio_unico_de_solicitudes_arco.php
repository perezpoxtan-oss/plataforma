<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folio de solicitud ARCO único por empresa (docs/tecnico/privacidad.md,
 * «Folios»). Las solicitudes de la página de la plataforma se numeraban con
 * el mismo formato que las de la empresa y, al canalizarlas, chocaban con
 * sus folios (varias «ARCO-2026-0001» en la misma bandeja).
 *
 * up(): renumera los repetidos que ya existan (se queda con su folio la más
 * antigua; las demás toman el siguiente número libre de su empresa y año, o
 * «ARCO-P-AAAA-NNNN» si siguen sin empresa) y agrega el índice único
 * (empresa_id, folio).
 *
 * down(): quita el índice único. Los folios renumerados se quedan como están
 * (eran duplicados: regresarlos no aporta nada).
 */
return new class extends Migration
{
    public function up(): void
    {
        $repetidos = DB::table('solicitudes_arco')
            ->select('empresa_id', 'folio')->groupBy('empresa_id', 'folio')->havingRaw('COUNT(*) > 1')->get();

        foreach ($repetidos as $grupo) {
            $ids = DB::table('solicitudes_arco')->where('folio', $grupo->folio)
                ->when($grupo->empresa_id === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $grupo->empresa_id))
                ->orderBy('id')->pluck('id')->slice(1);
            foreach ($ids as $id) {
                $fila = DB::table('solicitudes_arco')->where('id', $id)->first(['empresa_id', 'created_at']);
                $anio = substr((string) ($fila->created_at ?? now()), 0, 4);
                $prefijo = $fila->empresa_id === null ? "ARCO-P-{$anio}-" : "ARCO-{$anio}-";
                $mayor = DB::table('solicitudes_arco')->where('folio', 'like', $prefijo.'%')
                    ->when($fila->empresa_id !== null, fn ($q) => $q->where('empresa_id', $fila->empresa_id))
                    ->pluck('folio')->map(fn ($f) => (int) substr($f, strlen($prefijo)))->max() ?? 0;
                DB::table('solicitudes_arco')->where('id', $id)
                    ->update(['folio' => $prefijo.str_pad((string) ($mayor + 1), 4, '0', STR_PAD_LEFT)]);
            }
        }

        Schema::table('solicitudes_arco', function (Blueprint $table) {
            $table->unique(['empresa_id', 'folio'], 'solicitudes_arco_empresa_folio_unico');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_arco', function (Blueprint $table) {
            $table->dropUnique('solicitudes_arco_empresa_folio_unico');
        });
    }
};
