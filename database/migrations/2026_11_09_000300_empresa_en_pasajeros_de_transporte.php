<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md): los pasajeros de
 * un vale de taxi (movimiento_transporte_pasajeros) eran la única tabla
 * operativa sin empresa_id. Se agrega, se llena desde su movimiento, queda
 * obligatoria, con llave foránea restrict (como su padre) e índice; el modelo
 * MovimientoTransportePasajero usa el filtro de empresa.
 *
 * Idempotente (si se corta, la siguiente corrida termina). down() la quita.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('movimiento_transporte_pasajeros', 'empresa_id')) {
            Schema::table('movimiento_transporte_pasajeros', function (Blueprint $table) {
                $table->unsignedBigInteger('empresa_id')->nullable()->after('id');
            });
        }

        // Desde el movimiento padre (la llave movimiento_transporte_id garantiza que existe)
        DB::table('movimiento_transporte_pasajeros')->whereNull('empresa_id')->update([
            'empresa_id' => DB::raw('(select m.empresa_id from movimientos_transporte m where m.id = movimiento_transporte_pasajeros.movimiento_transporte_id)'),
        ]);

        $tieneLlave = collect(Schema::getForeignKeys('movimiento_transporte_pasajeros'))->contains(fn ($fk) => $fk['columns'] === ['empresa_id']);
        $tieneIndice = collect(Schema::getIndexes('movimiento_transporte_pasajeros'))->contains(fn ($i) => $i['name'] === 'mov_transporte_pasajero_empresa_idx');
        Schema::table('movimiento_transporte_pasajeros', function (Blueprint $table) use ($tieneLlave, $tieneIndice) {
            $table->unsignedBigInteger('empresa_id')->nullable(false)->change();
            if (! $tieneIndice) {
                $table->index(['empresa_id', 'movimiento_transporte_id'], 'mov_transporte_pasajero_empresa_idx');
            }
            if (! $tieneLlave) {
                $table->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('movimiento_transporte_pasajeros', 'empresa_id')) {
            return;
        }
        $tieneLlave = collect(Schema::getForeignKeys('movimiento_transporte_pasajeros'))->contains(fn ($fk) => $fk['columns'] === ['empresa_id']);
        $tieneIndice = collect(Schema::getIndexes('movimiento_transporte_pasajeros'))->contains(fn ($i) => $i['name'] === 'mov_transporte_pasajero_empresa_idx');
        Schema::table('movimiento_transporte_pasajeros', function (Blueprint $table) use ($tieneLlave, $tieneIndice) {
            if ($tieneLlave) {
                $table->dropForeign(['empresa_id']);
            }
            if ($tieneIndice) {
                $table->dropIndex('mov_transporte_pasajero_empresa_idx');
            }
        });
        Schema::table('movimiento_transporte_pasajeros', function (Blueprint $table) {
            $table->dropColumn('empresa_id');
        });
    }
};
