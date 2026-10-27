<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 4 (C-22 a C-26; reglas 1 y 3): un solo motor
 * «Reloj y suplente».
 *
 *  - relojes_esperas: lo que el motor ya avisó de cada asunto que espera
 *    (recordatorio y paso al suplente, una sola vez cada uno). Es la memoria
 *    del motor; si se borra, a lo mucho se repite un aviso.
 *  - vacantes.propuesta_en / propuesta_por / cubierta_en (C-23, E-06): desde
 *    cuándo espera una vacante propuesta por un jefe o una cubierta por cerrar.
 *  - novedades.canalizado_en (C-24, D-04): desde cuándo espera el ticket a la
 *    persona canalizada (reloj por categoría).
 *  - movimientos_transporte.rechazado_* (C-24, D-08): «Rechazar» el vale de
 *    taxi con motivo (antes solo se podía anular).
 *  - accesos (C-25, DD-13): «Pedir al supervisor» desde la tarjeta y la
 *    aprobación por excepción del agente, marcada para revisarla.
 *  - equipos_responsiva.faltante_reportado_* (C-24): el equipo faltante que
 *    el guardia no puede dar de baja le llega al supervisor.
 *
 * down(): quita lo agregado. Los datos de esas columnas se pierden (las
 * visitas aprobadas por el agente conservan su motivo en motivo_excepcion y
 * en la Bitácora de auditoría).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relojes_esperas', function (Blueprint $table) {
            $table->id();
            // Nula = asunto de la plataforma (Super Administrador)
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->string('reloj', 40);
            $table->string('referencia_tipo', 40);
            $table->unsignedBigInteger('referencia_id');
            $table->timestamp('desde');
            $table->timestamp('recordado_en')->nullable();
            $table->timestamp('escalado_en')->nullable();
            $table->json('escalado_a')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->unique(['reloj', 'referencia_tipo', 'referencia_id'], 'relojes_esperas_asunto_unico');
            $table->index(['empresa_id', 'reloj']);
        });

        Schema::table('vacantes', function (Blueprint $table) {
            $table->timestamp('propuesta_en')->nullable();
            $table->unsignedBigInteger('propuesta_por')->nullable();
            $table->timestamp('cubierta_en')->nullable();
        });
        Schema::table('novedades', function (Blueprint $table) {
            $table->timestamp('canalizado_en')->nullable();
        });
        Schema::table('movimientos_transporte', function (Blueprint $table) {
            $table->timestamp('rechazado_en')->nullable();
            $table->unsignedBigInteger('rechazado_por')->nullable();
            $table->string('motivo_rechazo', 255)->nullable();
        });
        Schema::table('accesos', function (Blueprint $table) {
            $table->timestamp('supervisor_pedido_en')->nullable();
            $table->boolean('excepcion_por_revisar')->default(false);
            $table->timestamp('excepcion_revisada_en')->nullable();
            $table->unsignedBigInteger('excepcion_revisada_por')->nullable();
        });
        // C-24: el guardia que no puede dar de baja un equipo faltante se lo manda al supervisor
        Schema::table('equipos_responsiva', function (Blueprint $table) {
            $table->timestamp('faltante_reportado_en')->nullable();
            $table->unsignedBigInteger('faltante_reportado_por')->nullable();
            $table->string('faltante_nota', 500)->nullable();
        });

        // Lo que ya espera arranca su reloj desde que se creó o se canalizó (sin avisos de golpe: el motor
        // solo avisa lo que pase del umbral desde hoy, ver MotorRelojes::correrEmpresa)
        DB::table('vacantes')->where('estado', 'borrador')->whereNotNull('creado_por')->update(['propuesta_en' => DB::raw('created_at'), 'propuesta_por' => DB::raw('creado_por')]);
        DB::table('novedades')->whereNotNull('asignado_a')->where('estatus', '!=', 'resuelto')->update(['canalizado_en' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('equipos_responsiva', function (Blueprint $table) {
            $table->dropColumn(['faltante_reportado_en', 'faltante_reportado_por', 'faltante_nota']);
        });
        Schema::table('accesos', function (Blueprint $table) {
            $table->dropColumn(['supervisor_pedido_en', 'excepcion_por_revisar', 'excepcion_revisada_en', 'excepcion_revisada_por']);
        });
        Schema::table('movimientos_transporte', function (Blueprint $table) {
            $table->dropColumn(['rechazado_en', 'rechazado_por', 'motivo_rechazo']);
        });
        Schema::table('novedades', function (Blueprint $table) {
            $table->dropColumn('canalizado_en');
        });
        Schema::table('vacantes', function (Blueprint $table) {
            $table->dropColumn(['propuesta_en', 'propuesta_por', 'cubierta_en']);
        });
        Schema::dropIfExists('relojes_esperas');
    }
};
