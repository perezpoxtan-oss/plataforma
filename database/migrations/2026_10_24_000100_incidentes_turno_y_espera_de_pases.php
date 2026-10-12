<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mejoras de Incidentes (ADR-0013):
 *
 *  - novedades: cuándo quedó «Pendiente de turno» (pendiente_turno_en), cuándo
 *    se avisó al turno que la recibe (turno_avisado_en) y cuándo se avisó al
 *    jefe porque ese turno terminó sin atenderla (turno_escalado_en). Un aviso
 *    por nivel: la tarea programada no repite.
 *  - pases_salida_aprobaciones: recordatorio al firmante (recordatorio_en) y
 *    aviso al siguiente nivel o al administrador (escalado_en, escalado_a)
 *    cuando una firma tarda más de las horas que configura la empresa.
 *
 * Solo columnas nuevas y vacías: la versión anterior las ignora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novedades', function (Blueprint $table) {
            if (! Schema::hasColumn('novedades', 'pendiente_turno_en')) {
                $table->timestamp('pendiente_turno_en')->nullable()->after('estatus');
            }
            if (! Schema::hasColumn('novedades', 'turno_avisado_en')) {
                $table->timestamp('turno_avisado_en')->nullable()->after('pendiente_turno_en');
            }
            if (! Schema::hasColumn('novedades', 'turno_escalado_en')) {
                $table->timestamp('turno_escalado_en')->nullable()->after('turno_avisado_en');
            }
        });

        Schema::table('pases_salida_aprobaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('pases_salida_aprobaciones', 'recordatorio_en')) {
                $table->timestamp('recordatorio_en')->nullable()->after('resuelto_en');
            }
            if (! Schema::hasColumn('pases_salida_aprobaciones', 'escalado_en')) {
                $table->timestamp('escalado_en')->nullable()->after('recordatorio_en');
            }
            if (! Schema::hasColumn('pases_salida_aprobaciones', 'escalado_a')) {
                $table->json('escalado_a')->nullable()->after('escalado_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pases_salida_aprobaciones', function (Blueprint $table) {
            foreach (['escalado_a', 'escalado_en', 'recordatorio_en'] as $columna) {
                if (Schema::hasColumn('pases_salida_aprobaciones', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('novedades', function (Blueprint $table) {
            foreach (['turno_escalado_en', 'turno_avisado_en', 'pendiente_turno_en'] as $columna) {
                if (Schema::hasColumn('novedades', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
