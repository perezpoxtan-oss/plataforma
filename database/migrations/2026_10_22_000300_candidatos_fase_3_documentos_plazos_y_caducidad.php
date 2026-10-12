<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos, fase 3 (ADR-0011): documentos para contratar, contrato,
 * plazos con recordatorio único y caducidad.
 *
 *  - documentos_para_contratar: la lista de documentos de cada empresa
 *    (clave, nombre, obligatorio, orden, activo). Sin renglones, la plataforma
 *    usa la lista estándar (DocumentosParaContratar::DEFECTO); cv, otro y
 *    contrato son tipos fijos y no viven aquí.
 *  - candidato_documentos.tipo pasa a 40 caracteres (clave del checklist) y
 *    gana colaborador_id: al contratar, los documentos pasan al expediente
 *    del colaborador. ruta pasa a opcional y se agrega recibido_en para los
 *    documentos «recibidos en papel» (sin archivo).
 *  - postulaciones: firma del contrato (contrato_firmado_en, contrato_recibido_por,
 *    contrato_documento_id), un sello por recordatorio (aviso_*_en: nunca se
 *    repite) y archivada_en (caducidad de Considerar / Rechazado).
 *  - candidatos: espejo de archivada_en, aviso del borrado (aviso_borrado_en,
 *    borrar_en), «Conservar 6 meses más» (conservar_hasta) y el recordatorio
 *    de solicitud sin revisar (aviso_solicitud_en).
 *
 * Los tipos de documento ya guardados (cv, ine, comprobante, otro) conservan
 * su clave: coinciden con la lista estándar, no hay datos que convertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documentos_para_contratar')) {
            Schema::create('documentos_para_contratar', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->string('clave', 40);
                $table->string('nombre', 80);
                $table->boolean('obligatorio')->default(true);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->unsignedBigInteger('actualizado_por')->nullable();
                $table->timestamps();
                $table->unique(['empresa_id', 'clave']);
            });
        }

        Schema::table('candidato_documentos', function (Blueprint $table) {
            $table->string('tipo', 40)->change();
            // «Recibido en papel»: un renglón sin archivo (origen papel, ruta vacía)
            $table->string('ruta', 255)->nullable()->change();
            if (! Schema::hasColumn('candidato_documentos', 'colaborador_id')) {
                $table->foreignId('colaborador_id')->nullable()->after('candidato_id')->constrained('colaboradores')->nullOnDelete();
            }
            if (! Schema::hasColumn('candidato_documentos', 'recibido_en')) {
                $table->date('recibido_en')->nullable()->after('origen');
            }
        });

        Schema::table('postulaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('postulaciones', 'contrato_firmado_en')) {
                $table->timestamp('contrato_firmado_en')->nullable()->after('elegido_en');
            }
            if (! Schema::hasColumn('postulaciones', 'contrato_recibido_por')) {
                $table->foreignId('contrato_recibido_por')->nullable()->after('contrato_firmado_en')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('postulaciones', 'contrato_documento_id')) {
                $table->foreignId('contrato_documento_id')->nullable()->after('contrato_recibido_por')->constrained('candidato_documentos')->nullOnDelete();
            }
            foreach (['aviso_evaluacion_en', 'aviso_documentos_en', 'aviso_por_atender_en', 'aviso_no_se_presento_en', 'archivada_en'] as $columna) {
                if (! Schema::hasColumn('postulaciones', $columna)) {
                    $table->timestamp($columna)->nullable();
                }
            }
        });

        Schema::table('candidatos', function (Blueprint $table) {
            foreach (['archivada_en', 'aviso_solicitud_en', 'aviso_borrado_en'] as $columna) {
                if (! Schema::hasColumn('candidatos', $columna)) {
                    $table->timestamp($columna)->nullable();
                }
            }
            if (! Schema::hasColumn('candidatos', 'borrar_en')) {
                $table->date('borrar_en')->nullable();
            }
            if (! Schema::hasColumn('candidatos', 'conservar_hasta')) {
                $table->date('conservar_hasta')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            foreach (['conservar_hasta', 'borrar_en', 'aviso_borrado_en', 'aviso_solicitud_en', 'archivada_en'] as $columna) {
                if (Schema::hasColumn('candidatos', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('postulaciones', function (Blueprint $table) {
            // Las llaves foráneas se quitan antes que sus columnas (MariaDB conserva el índice que las sostiene)
            if (Schema::hasColumn('postulaciones', 'contrato_documento_id')) {
                $table->dropConstrainedForeignId('contrato_documento_id');
            }
            if (Schema::hasColumn('postulaciones', 'contrato_recibido_por')) {
                $table->dropConstrainedForeignId('contrato_recibido_por');
            }
            foreach (['archivada_en', 'aviso_no_se_presento_en', 'aviso_por_atender_en', 'aviso_documentos_en', 'aviso_evaluacion_en', 'contrato_firmado_en'] as $columna) {
                if (Schema::hasColumn('postulaciones', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        Schema::table('candidato_documentos', function (Blueprint $table) {
            if (Schema::hasColumn('candidato_documentos', 'colaborador_id')) {
                $table->dropConstrainedForeignId('colaborador_id');
            }
            if (Schema::hasColumn('candidato_documentos', 'recibido_en')) {
                $table->dropColumn('recibido_en');
            }
        });
        // Los «recibidos en papel» no tienen archivo: no caben en la versión anterior (ruta obligatoria)
        DB::table('candidato_documentos')->whereNull('ruta')->delete();
        // Claves más largas que las de antes quedan como «otro» para caber en 20 caracteres
        DB::table('candidato_documentos')->whereRaw('LENGTH(tipo) > 20')->update(['tipo' => 'otro']);
        Schema::table('candidato_documentos', function (Blueprint $table) {
            $table->string('tipo', 20)->change();
            $table->string('ruta', 255)->nullable(false)->change();
        });

        Schema::dropIfExists('documentos_para_contratar');
    }
};
