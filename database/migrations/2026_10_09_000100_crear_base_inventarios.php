<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Base de los inventarios de seguridad (llaves, gafetes, equipos):
 *
 *  - vouchers_reposicion (SEGCAT: vouchers_reposicion): comprobante que se
 *    genera al dar de baja una llave, un gafete o un equipo perdido, dañado o
 *    robado, con o sin cobro al responsable.
 *  - costos_reposicion (SEGCAT: cat_costos_reposicion y cat_costos_equipo):
 *    último costo usado por tipo, para sugerirlo la siguiente vez.
 *  - Lector universal: colaboradores y vehículos ganan codigo_qr y
 *    etiqueta_nfc (número de la tarjeta o del chip que se les asigne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers_reposicion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('folio', 16)->unique();                   // VR-2610-48213
            $table->string('origen_tipo', 20);                       // llave | gafete | equipo
            $table->unsignedBigInteger('origen_id');
            $table->string('origen_descripcion', 150);               // lo que era, aunque cambie después
            $table->string('motivo', 20);                            // extraviado | danado | robado
            $table->text('descripcion')->nullable();                 // ¿cómo pasó?
            $table->boolean('aplica_cobro')->default(false);
            $table->decimal('monto', 10, 2)->default(0);
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['empresa_id', 'origen_tipo', 'origen_id']);
            $table->index(['empresa_id', 'created_at']);
        });

        Schema::create('costos_reposicion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('origen_tipo', 20);
            $table->string('referencia', 150);                       // tipo de llave, tipo de gafete, "MARCA|MODELO"…
            $table->decimal('monto', 10, 2);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'origen_tipo', 'referencia']);
        });

        foreach (['colaboradores', 'vehiculos'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'codigo_qr')) {
                    $table->string('codigo_qr', 32)->nullable();
                }
                $table->string('etiqueta_nfc', 64)->nullable();
                $table->unique(['empresa_id', 'etiqueta_nfc']);
            });
        }

        // Colaboradores existentes: código aleatorio para su QR
        DB::table('colaboradores')->whereNull('codigo_qr')->orderBy('id')->select('id')
            ->each(fn ($c) => DB::table('colaboradores')->where('id', $c->id)->update(['codigo_qr' => Str::lower(Str::random(24))]));
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->unique('codigo_qr');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropUnique(['codigo_qr']);
            $table->dropUnique(['empresa_id', 'etiqueta_nfc']);
            $table->dropColumn(['codigo_qr', 'etiqueta_nfc']);
        });
        Schema::table('vehiculos', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'etiqueta_nfc']);
            $table->dropColumn('etiqueta_nfc');
        });
        Schema::dropIfExists('costos_reposicion');
        Schema::dropIfExists('vouchers_reposicion');
    }
};
