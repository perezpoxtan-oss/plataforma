<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos, fase 1: postulaciones y «¿A qué viene?» en la caseta.
 *
 *  - postulaciones: cada vez que una persona aplica (a una vacante o «a lo que
 *    haya»). La ficha (candidatos) conserva datos personales, solicitud, CV,
 *    documentos y firma; lo que avanza por etapas vive en la postulación.
 *    candidatos.etapa (y vacante, departamento, puesto y fechas) queda como
 *    ESPEJO de la postulación activa: la que manda es la postulación
 *    (App\Services\Candidatos\Postulaciones::reflejar()).
 *  - accesos.viene_a: a qué viene a Recursos Humanos (busca_empleo, entrevista,
 *    documentos, firma, informes, tramite).
 *  - accesos.postulacion_id: la postulación a la que se ligó esa visita.
 *  - autorizaciones.departamento_id ahora admite null: las de tipo «recepcion»
 *    (RR. HH. dice «Que pase») no son de un departamento.
 *
 * Datos existentes (idempotente):
 *  - una postulación por cada candidato que aún no tiene, con su etapa, vacante y fechas;
 *  - sus accesos quedan ligados a esa postulación y marcados «busca_empleo»;
 *    los demás accesos de Recursos Humanos, «tramite»;
 *  - el ajuste «La caseta espera a que RR. HH. diga «Que pase»» se deja APAGADO
 *    en las empresas que ya existían (su caseta sigue igual hasta que lo
 *    enciendan); las empresas nuevas lo tienen encendido.
 */
return new class extends Migration
{
    /** Columnas que se copian de la ficha a su primera postulación. */
    private const COPIAR = ['empresa_id', 'sede_id', 'vacante_id', 'departamento_id', 'puesto_id', 'vacante', 'etapa', 'origen', 'motivo_descarte',
        'revision_en', 'aprobado_rh_en', 'entrevista_en', 'decision_en', 'decision_por', 'enviado_departamento_en', 'respuesta_departamento_en',
        'contratado_en', 'colaborador_id', 'creado_por', 'actualizado_por', 'created_at', 'updated_at'];

    public function up(): void
    {
        if (! Schema::hasTable('postulaciones')) {
            Schema::create('postulaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('sede_id')->constrained('sedes');
                $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
                $table->foreignId('vacante_id')->nullable()->constrained('vacantes')->nullOnDelete();
                $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
                $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
                $table->string('vacante', 150)->nullable();
                // registrado | revision | aprobado_rh | entrevista | seleccionado | descartado | cartera | contratado
                $table->string('etapa', 20)->default('registrado');
                $table->string('origen', 12)->default('caseta'); // caseta | rh | kiosco | web
                $table->string('motivo_descarte', 500)->nullable();
                $table->timestamp('revision_en')->nullable();
                $table->timestamp('aprobado_rh_en')->nullable();
                $table->timestamp('entrevista_en')->nullable();
                $table->timestamp('decision_en')->nullable();
                $table->foreignId('decision_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('enviado_departamento_en')->nullable();
                $table->timestamp('respuesta_departamento_en')->nullable();
                $table->timestamp('contratado_en')->nullable();
                $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->unsignedBigInteger('actualizado_por')->nullable();
                $table->timestamps();
                $table->index(['empresa_id', 'sede_id', 'etapa']);
                $table->index(['candidato_id', 'etapa']);
            });
        }

        if (! Schema::hasColumn('accesos', 'viene_a')) {
            Schema::table('accesos', function (Blueprint $table) {
                $table->string('viene_a', 20)->nullable()->after('motivo_visita');
                $table->foreignId('postulacion_id')->nullable()->after('viene_a')->constrained('postulaciones')->nullOnDelete();
            });
        }

        Schema::table('autorizaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('departamento_id')->nullable()->change();
        });

        $this->postulacionesDeLasFichas();
        $this->ajusteEnEmpresasExistentes();
    }

    /** Una postulación por cada ficha que aún no tiene (y sus accesos ligados). */
    private function postulacionesDeLasFichas(): void
    {
        DB::table('candidatos')->whereNotExists(fn ($q) => $q->from('postulaciones')->whereColumn('postulaciones.candidato_id', 'candidatos.id'))
            ->orderBy('id')->chunkById(200, function ($fichas) {
                foreach ($fichas as $f) {
                    $fila = ['candidato_id' => $f->id];
                    foreach (self::COPIAR as $columna) {
                        $fila[$columna] = $f->{$columna} ?? null;
                    }
                    $fila['etapa'] ??= 'registrado';
                    $fila['origen'] ??= 'caseta';
                    $fila['created_at'] ??= now();
                    $fila['updated_at'] ??= now();
                    $id = DB::table('postulaciones')->insertGetId($fila);
                    if ($f->acceso_id !== null) {
                        DB::table('accesos')->where('id', $f->acceso_id)->whereNull('postulacion_id')
                            ->update(['postulacion_id' => $id, 'viene_a' => DB::raw("COALESCE(viene_a, 'busca_empleo')")]);
                    }
                }
            });

        DB::table('accesos')->where('tipo', 'visitante')->where('motivo_visita', 'rh')->whereNull('viene_a')->update(['viene_a' => 'tramite']);
    }

    /** Empresas que ya existían: la caseta sigue dejando pasar directo (lo enciende cada empresa). */
    private function ajusteEnEmpresasExistentes(): void
    {
        foreach (DB::table('empresas')->orderBy('id')->get(['id', 'preferencias']) as $e) {
            $preferencias = json_decode((string) ($e->preferencias ?? ''), true);
            $preferencias = is_array($preferencias) ? $preferencias : [];
            $recepcion = is_array($preferencias['recepcion'] ?? null) ? $preferencias['recepcion'] : [];
            if (array_key_exists('rh_autoriza_paso', $recepcion)) {
                continue;
            }
            $recepcion['rh_autoriza_paso'] = false;
            $preferencias['recepcion'] = $recepcion;
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($preferencias, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accesos', 'postulacion_id')) {
            Schema::table('accesos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('postulacion_id');
                $table->dropColumn('viene_a');
            });
        }
        Schema::dropIfExists('postulaciones');
    }
};
