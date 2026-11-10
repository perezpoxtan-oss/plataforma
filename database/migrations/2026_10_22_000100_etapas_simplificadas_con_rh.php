<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidatos (ADR-0010): etapas más simples. «En revisión RR. HH.»
 * (revision) y «Entrevista RR. HH.» (entrevista_rh) se funden en una sola
 * etapa «Con RR. HH.» (con_rh); la evaluación de RR. HH. se registra desde
 * ahí sin cambiar de etapa. «Esperando» (registrado) conserva su clave y solo
 * cambia de etiqueta («Por atender»): no hay nada que migrar.
 *
 * Idempotente: cada paso solo toca lo que conserva el valor viejo (en la
 * postulación, en el espejo de la ficha y en el historial).
 *
 * down(): con_rh regresa a revision o a entrevista_rh según si la postulación
 * ya tenía anotada su entrevista de RR. HH. (entrevista_rh_en); el espejo de
 * la ficha sigue a su postulación activa y el historial regresa a revision.
 */
return new class extends Migration
{
    private const MAPA = ['revision' => 'con_rh', 'entrevista_rh' => 'con_rh'];

    public function up(): void
    {
        foreach (self::MAPA as $viejo => $nuevo) {
            DB::table('postulaciones')->where('etapa', $viejo)->update(['etapa' => $nuevo]);
            DB::table('candidatos')->where('etapa', $viejo)->update(['etapa' => $nuevo]);
            DB::table('candidato_eventos')->where('etapa_anterior', $viejo)->update(['etapa_anterior' => $nuevo]);
            DB::table('candidato_eventos')->where('etapa_nueva', $viejo)->update(['etapa_nueva' => $nuevo]);
        }
    }

    public function down(): void
    {
        DB::table('postulaciones')->where('etapa', 'con_rh')->whereNotNull('entrevista_rh_en')->update(['etapa' => 'entrevista_rh']);
        DB::table('postulaciones')->where('etapa', 'con_rh')->update(['etapa' => 'revision']);
        // El espejo de la ficha sigue a su postulación activa (la más reciente)
        foreach (DB::table('candidatos')->where('etapa', 'con_rh')->pluck('id') as $id) {
            $etapa = DB::table('postulaciones')->where('candidato_id', $id)->orderByDesc('id')->value('etapa');
            DB::table('candidatos')->where('id', $id)->update(['etapa' => in_array($etapa, ['revision', 'entrevista_rh'], true) ? $etapa : 'revision']);
        }
        DB::table('candidato_eventos')->where('etapa_anterior', 'con_rh')->update(['etapa_anterior' => 'revision']);
        DB::table('candidato_eventos')->where('etapa_nueva', 'con_rh')->update(['etapa_nueva' => 'revision']);
    }
};
