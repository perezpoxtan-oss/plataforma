<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mejoras de Estructura y plataforma (ADR-0016).
 *
 *  - users.preferencias_avisos (JSON): avisos que cada persona silenció
 *    (tipos de la campana y avisos por correo). Nulo = recibe todo, como siempre.
 *  - auditoria.sede_id: sede del registro tocado, para que «Su sede» en la
 *    Bitácora de auditoría pueda mostrar los movimientos de la sede (si la
 *    empresa lo elige en Configuración). Los movimientos anteriores quedan sin
 *    sede y se siguen viendo por quién los hizo.
 *  - Nombres consistentes: el módulo «Zonas y áreas» se llama «Zonas y
 *    edificios» (la palabra «área» queda solo para las partes de una
 *    habitación) e «Identidad de la plataforma» pasa a la sección
 *    «Plataforma» del menú Estructura (solo la ve el Super Administrador).
 *
 * La configuración nueva de la empresa (interruptor de avisos por módulo,
 * alcance de sede en la auditoría y avisos de movimientos sensibles) vive en
 * empresas.preferencias: sin tabla nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferencias_avisos')) {
                $table->json('preferencias_avisos')->nullable();
            }
        });

        Schema::table('auditoria', function (Blueprint $table) {
            if (! Schema::hasColumn('auditoria', 'sede_id')) {
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->index(['empresa_id', 'sede_id'], 'auditoria_empresa_sede_idx');
            }
        });

        DB::table('modulos')->where('clave', 'espacios')->where('nombre', 'Zonas y áreas')->update(['nombre' => 'Zonas y edificios']);
        $this->moverIdentidad('Sistema', 'Plataforma');
    }

    /**
     * Identidad pasa a su propia sección y queda al final del menú (después de
     * la Bitácora de auditoría), o regresa a «Sistema» entre Configuración y la bitácora.
     */
    private function moverIdentidad(string $de, string $a): void
    {
        $identidad = DB::table('modulos')->where('clave', 'identidad')->where('seccion_menu', $de)->first(['id', 'orden_menu']);
        $auditoria = DB::table('modulos')->where('clave', 'auditoria')->first(['id', 'orden_menu']);
        if ($identidad === null) {
            return;
        }
        DB::table('modulos')->where('id', $identidad->id)->update(['seccion_menu' => $a]);
        $intercambiar = $a === 'Plataforma' ? $identidad->orden_menu < $auditoria?->orden_menu : $identidad->orden_menu > $auditoria?->orden_menu;
        if ($auditoria !== null && $intercambiar) {
            DB::table('modulos')->where('id', $identidad->id)->update(['orden_menu' => $auditoria->orden_menu]);
            DB::table('modulos')->where('id', $auditoria->id)->update(['orden_menu' => $identidad->orden_menu]);
        }
    }

    public function down(): void
    {
        $this->moverIdentidad('Plataforma', 'Sistema');
        DB::table('modulos')->where('clave', 'espacios')->where('nombre', 'Zonas y edificios')->update(['nombre' => 'Zonas y áreas']);

        Schema::table('auditoria', function (Blueprint $table) {
            if (Schema::hasColumn('auditoria', 'sede_id')) {
                $table->dropIndex('auditoria_empresa_sede_idx');
                $table->dropColumn('sede_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferencias_avisos')) {
                $table->dropColumn('preferencias_avisos');
            }
        });
    }
};
