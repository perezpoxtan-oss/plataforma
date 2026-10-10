<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos, fase 2 (ADR-0009): etapas nuevas de la postulación.
 *
 *   registrado (Esperando) → revision → entrevista_rh → canalizado (entrevista
 *   con el departamento) → evaluado → elegido → contratado; a un lado:
 *   considerar, rechazado y no_se_presento.
 *
 * Columnas nuevas en postulaciones: entrevistador_id, cita_en, cita_ahora,
 * cita_lugar, numero_entrevista (1.ª, 2.ª…) y las fechas entrevista_rh_en,
 * canalizado_en, canalizado_por, evaluado_en, elegido_en, no_se_presento_en.
 *
 * Datos existentes (idempotente: cada paso solo toca lo que sigue con el valor viejo):
 *  - aprobado_rh y entrevista → canalizado (canalizado_en = la fecha que tenga;
 *    entrevistador = quien respondió «Bajar a entrevistar», si no el primer
 *    responsable del departamento en esa sede, si no a quien se avisó; sin cita:
 *    RR. HH. la pone con «Reprogramar»);
 *  - seleccionado → elegido (elegido_en = fecha de la decisión);
 *  - cartera → considerar; descartado → rechazado;
 *  - lo mismo en el espejo de la ficha (candidatos.etapa) y en el historial;
 *  - las autorizaciones de tipo «candidato» que seguían pendientes se cancelan
 *    (medio «sistema»): el aviso al jefe ahora es la entrevista canalizada.
 */
return new class extends Migration
{
    private const MAPA = ['aprobado_rh' => 'canalizado', 'entrevista' => 'canalizado', 'seleccionado' => 'elegido', 'cartera' => 'considerar', 'descartado' => 'rechazado'];

    public function up(): void
    {
        if (! Schema::hasColumn('postulaciones', 'entrevistador_id')) {
            Schema::table('postulaciones', function (Blueprint $table) {
                $table->foreignId('entrevistador_id')->nullable()->after('motivo_descarte')->constrained('users')->nullOnDelete();
                $table->timestamp('cita_en')->nullable()->after('entrevistador_id');
                $table->boolean('cita_ahora')->default(false)->after('cita_en');
                $table->string('cita_lugar', 300)->nullable()->after('cita_ahora');
                $table->unsignedTinyInteger('numero_entrevista')->default(1)->after('cita_lugar');
                $table->timestamp('entrevista_rh_en')->nullable()->after('revision_en');
                $table->timestamp('canalizado_en')->nullable()->after('entrevista_rh_en');
                $table->foreignId('canalizado_por')->nullable()->after('canalizado_en')->constrained('users')->nullOnDelete();
                $table->timestamp('evaluado_en')->nullable()->after('canalizado_por');
                $table->timestamp('elegido_en')->nullable()->after('evaluado_en');
                $table->timestamp('no_se_presento_en')->nullable()->after('elegido_en');
                $table->index(['empresa_id', 'entrevistador_id', 'etapa']);
                $table->index(['vacante_id', 'etapa']);
            });
        }

        $this->postulacionesConElDepartamento();
        DB::table('postulaciones')->where('etapa', 'seleccionado')->whereNull('elegido_en')->update(['elegido_en' => DB::raw('decision_en')]);
        $this->cancelarAutorizacionesDeCandidato();

        foreach (self::MAPA as $viejo => $nuevo) {
            DB::table('postulaciones')->where('etapa', $viejo)->update(['etapa' => $nuevo]);
            DB::table('candidatos')->where('etapa', $viejo)->update(['etapa' => $nuevo]);
            DB::table('candidato_eventos')->where('etapa_anterior', $viejo)->update(['etapa_anterior' => $nuevo]);
            DB::table('candidato_eventos')->where('etapa_nueva', $viejo)->update(['etapa_nueva' => $nuevo]);
        }
    }

    /**
     * aprobado_rh / entrevista: quedan «Entrevista con el departamento» con su
     * entrevistador (sin cita).
     */
    private function postulacionesConElDepartamento(): void
    {
        $filas = DB::table('postulaciones')->whereIn('etapa', ['aprobado_rh', 'entrevista'])->orderBy('id')
            ->get(['id', 'candidato_id', 'sede_id', 'departamento_id', 'entrevistador_id', 'aprobado_rh_en', 'enviado_departamento_en', 'entrevista_en', 'canalizado_en', 'updated_at']);
        foreach ($filas as $p) {
            DB::table('postulaciones')->where('id', $p->id)->update([
                'canalizado_en' => $p->canalizado_en ?? $p->entrevista_en ?? $p->enviado_departamento_en ?? $p->aprobado_rh_en ?? $p->updated_at,
                'entrevistador_id' => $p->entrevistador_id ?? $this->entrevistador($p),
            ]);
        }
    }

    /**
     * Quien respondió «Bajar a entrevistar»; si no, el primer responsable del
     * departamento en esa sede (el titular, aunque hoy tenga delegación: el
     * aviso nuevo seguiría la delegación); si no, a quien se avisó.
     */
    private function entrevistador(object $p): ?int
    {
        $aut = DB::table('autorizaciones')->where('tipo', 'candidato')->where('candidato_id', $p->candidato_id)->orderByDesc('id')
            ->first(['estado', 'respondida_por', 'avisados']);
        if ($aut !== null && $aut->estado === 'entrevista' && $aut->respondida_por !== null) {
            return (int) $aut->respondida_por;
        }
        if ($p->departamento_id !== null) {
            $id = DB::table('departamento_responsables as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('u.activo', true)
                ->where('r.departamento_id', $p->departamento_id)->where(fn ($q) => $q->whereNull('r.sede_id')->orWhere('r.sede_id', $p->sede_id))
                ->orderBy('r.es_suplente')->orderBy('r.id')->value('r.user_id');
            if ($id !== null) {
                return (int) $id;
            }
        }
        $avisados = $aut === null ? null : json_decode((string) ($aut->avisados ?? ''), true);

        return is_array($avisados) && $avisados !== [] && is_numeric($avisados[0]) ? (int) $avisados[0] : null;
    }

    /** Lo que esperaba la respuesta del departamento se cancela y sus avisos pierden los botones. */
    private function cancelarAutorizacionesDeCandidato(): void
    {
        $ids = DB::table('autorizaciones')->where('tipo', 'candidato')->where('estado', 'pendiente')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('autorizaciones')->whereIn('id', $ids)->update([
            'estado' => 'cancelada', 'respondida_en' => now(), 'respuesta_medio' => 'sistema', 'updated_at' => now(),
            'comentario' => 'Se reemplazó por «Canalizar al departamento»: la entrevista sigue en la ficha del candidato.',
        ]);
        if (Schema::hasTable('notificaciones')) {
            DB::table('notificaciones')->where('referencia_tipo', 'autorizacion')->whereIn('referencia_id', $ids)
                ->update(['acciones' => null, 'leida_en' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Las etapas no se regresan (cada etapa vieja se unió con otra). Solo se quitan las columnas.
        if (Schema::hasColumn('postulaciones', 'entrevistador_id')) {
            Schema::table('postulaciones', function (Blueprint $table) {
                $table->dropIndex(['empresa_id', 'entrevistador_id', 'etapa']);
                $table->dropIndex(['vacante_id', 'etapa']);
                $table->dropConstrainedForeignId('entrevistador_id');
                $table->dropConstrainedForeignId('canalizado_por');
                $table->dropColumn(['cita_en', 'cita_ahora', 'cita_lugar', 'numero_entrevista', 'entrevista_rh_en', 'canalizado_en', 'evaluado_en', 'elegido_en', 'no_se_presento_en']);
            });
        }
    }
};
