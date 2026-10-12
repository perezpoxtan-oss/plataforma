<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos (ADR-0010): postulación corta por internet. En la bolsa de
 * trabajo pública la primera pantalla pide solo nombre, teléfono, correo y CV
 * opcional; la ficha queda marcada «Solicitud incompleta» (solicitud_corta)
 * hasta que el candidato llena la solicitud completa (kiosco) o Recursos
 * Humanos la captura. Idempotente: solo agrega la columna si no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('candidatos', 'solicitud_corta')) {
            Schema::table('candidatos', function (Blueprint $table) {
                $table->boolean('solicitud_corta')->default(false)->after('autocaptura_en');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('candidatos', 'solicitud_corta')) {
            Schema::table('candidatos', function (Blueprint $table) {
                $table->dropColumn('solicitud_corta');
            });
        }
    }
};
