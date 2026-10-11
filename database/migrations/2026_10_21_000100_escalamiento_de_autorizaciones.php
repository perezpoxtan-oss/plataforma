<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalamiento de autorizaciones (Bitácora de accesos): cuándo se reenvió el
 * aviso al responsable (recordatorio_en), cuándo se avisó a los suplentes o
 * al Jefe de seguridad (escalado_en) y a quiénes (escalado_a). Un aviso por
 * nivel: la tarea programada no repite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('autorizaciones', 'recordatorio_en')) {
                $table->timestamp('recordatorio_en')->nullable()->after('avisados');
            }
            if (! Schema::hasColumn('autorizaciones', 'escalado_en')) {
                $table->timestamp('escalado_en')->nullable()->after('recordatorio_en');
            }
            if (! Schema::hasColumn('autorizaciones', 'escalado_a')) {
                $table->json('escalado_a')->nullable()->after('escalado_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            foreach (['escalado_a', 'escalado_en', 'recordatorio_en'] as $columna) {
                if (Schema::hasColumn('autorizaciones', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
