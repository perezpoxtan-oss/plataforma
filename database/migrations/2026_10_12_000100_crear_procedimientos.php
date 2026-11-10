<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procedimientos (manual de procedimientos operativos). Módulo nuevo: no
 * existe en SEGCAT. Ver docs/tecnico/procedimientos.md.
 *
 *  - procedimiento_categorias: catálogo por empresa (Emergencias, Operación
 *    de caseta, Accesos, Protección civil, Administrativo) con su color.
 *  - procedimientos: la "carpeta" de un procedimiento: clave (PRO-SEG-001),
 *    estado general y la versión vigente (copias para listar rápido).
 *  - procedimiento_versiones: cada versión (v1, v2…) con su contenido, su
 *    circuito Borrador → En revisión → Publicada (y Reemplazada / Descartada)
 *    y la firma de quien la aprobó (disco privado). Una versión publicada no
 *    se edita: los cambios van en una versión nueva.
 *  - procedimiento_pasos: los pasos ordenados de cada versión.
 *  - procedimiento_aplicaciones: a quién aplica cada versión (sedes elegidas,
 *    departamentos y puestos; sin filas = a todos).
 *  - procedimiento_adjuntos: PDF e imágenes de cada versión (disco privado).
 *  - procedimiento_acuses: "Leí y entendí" firmado por cada usuario y versión.
 *  - procedimiento_eventos: historial de cada versión (creada, enviada,
 *    rechazada con comentario, aprobada, retirada…). No se edita.
 *  - procedimiento_recordatorios: último recordatorio de acuses pendientes
 *    enviado a cada usuario.
 *
 * Permisos de las plantillas (decisión del dueño del proyecto): Administrador,
 * Director y Jefe de seguridad todo (incluido aprobar); Supervisor ver, crear
 * y editar; Agente, Asistente y Recursos Humanos solo ver. En una instalación
 * nueva los da RolesPlantillaSeeder; aquí se ajustan los roles de una base
 * existente (plantillas y copias de cada empresa). El módulo no tenía
 * pantalla, así que sus permisos anteriores no se usaban.
 */
return new class extends Migration
{
    /** rol => [acciones, alcance] */
    private const PERMISOS = [
        'Administrador' => [['ver', 'crear', 'editar', 'eliminar', 'aprobar', 'borrar'], 'empresa'],
        'Director' => [['ver', 'crear', 'editar', 'eliminar', 'aprobar'], 'empresa'],
        'Jefe de seguridad' => [['ver', 'crear', 'editar', 'eliminar', 'aprobar'], 'sede'],
        'Supervisor' => [['ver', 'crear', 'editar'], 'sede'],
        'Asistente' => [['ver'], 'sede'],
        'Agente' => [['ver'], 'sede'],
        'Recursos Humanos' => [['ver'], 'empresa'],
    ];

    public function up(): void
    {
        Schema::create('procedimiento_categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->string('color', 20)->default('azul');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('procedimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('clave', 30);
            $table->foreignId('categoria_id')->constrained('procedimiento_categorias');
            $table->string('titulo', 150);                                       // el de la versión vigente (o la de trabajo)
            $table->string('estado', 20)->default('borrador');                   // borrador | en_revision | publicado | retirado
            $table->unsignedSmallInteger('version_vigente')->nullable();         // número de la versión publicada
            $table->timestamp('publicado_en')->nullable();                       // cuándo se publicó la vigente
            $table->unsignedSmallInteger('version_trabajo')->nullable();         // versión en borrador o en revisión
            $table->string('estado_trabajo', 20)->nullable();                    // borrador | en_revision
            $table->string('codigo_qr', 32)->unique();                          // QR de la hoja impresa (lector universal)
            $table->string('etiqueta_nfc', 64)->nullable();
            $table->timestamp('retirado_en')->nullable();
            $table->foreignId('retirado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_retiro')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'clave']);
            $table->unique(['empresa_id', 'etiqueta_nfc']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('procedimiento_versiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('procedimiento_id')->constrained('procedimientos')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('estado', 20)->default('borrador');                   // borrador | en_revision | publicada | reemplazada | descartada
            $table->foreignId('categoria_id')->constrained('procedimiento_categorias');
            $table->string('titulo', 150);
            $table->text('objetivo');
            $table->text('alcance')->nullable();
            $table->text('responsables')->nullable();
            $table->text('notas')->nullable();
            $table->text('resumen_cambios')->nullable();                         // obligatorio desde la v2
            $table->boolean('aplica_todas_sedes')->default(true);
            $table->timestamp('enviado_en')->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aprobador_nombre', 150)->nullable();
            $table->string('aprobador_cargo', 150)->nullable();
            $table->string('firma_ruta')->nullable();                            // disco privado
            $table->text('comentario_aprobacion')->nullable();
            $table->timestamp('rechazado_en')->nullable();
            $table->foreignId('rechazado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamp('reemplazada_en')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['procedimiento_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('procedimiento_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('procedimiento_versiones')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->text('texto');
            $table->string('responsable', 120)->nullable();
            $table->boolean('critico')->default(false);
            $table->timestamps();
            $table->index(['version_id', 'orden']);
        });

        Schema::create('procedimiento_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('procedimiento_versiones')->cascadeOnDelete();
            $table->string('tipo', 15);                                          // sede | departamento | puesto
            $table->foreignId('sede_id')->nullable()->constrained('sedes');
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos');
            $table->foreignId('puesto_id')->nullable()->constrained('puestos');
            $table->index(['version_id', 'tipo']);
        });

        Schema::create('procedimiento_adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('procedimiento_versiones')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->string('ruta');                                              // disco privado
            $table->string('tipo', 10);                                          // pdf | imagen
            $table->string('mime', 60);
            $table->unsignedInteger('tamano');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('procedimiento_acuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('procedimiento_versiones')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('nombre', 150);
            $table->string('firma_ruta');                                        // disco privado
            $table->string('ip', 45)->nullable();
            $table->timestamp('leido_en');
            $table->timestamps();
            $table->unique(['version_id', 'user_id']);
            $table->index(['empresa_id', 'user_id']);
        });

        Schema::create('procedimiento_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('procedimiento_id')->constrained('procedimientos')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('procedimiento_versiones')->cascadeOnDelete();
            $table->string('evento', 30);
            $table->text('comentario')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre', 150)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['procedimiento_id', 'id']);
        });

        Schema::create('procedimiento_recordatorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('ultimo_envio');
            $table->timestamps();
            $table->unique(['empresa_id', 'user_id']);
        });

        $this->permisos();
    }

    /**
     * Base existente: liga "Eliminar definitivamente" al módulo y deja a cada
     * rol con las acciones de Procedimientos que le tocan. Idempotente.
     */
    public function permisos(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'procedimientos')->value('id');
        if ($modulo === null) {
            return; // instalación nueva: el catálogo y las plantillas ya nacen así
        }
        $ahora = now();

        $borrar = DB::table('acciones')->where('clave', 'borrar')->value('id');
        if ($borrar !== null) {
            DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $borrar, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        $acciones = DB::table('modulo_acciones as ma')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('ma.modulo_id', $modulo)->pluck('ma.id', 'a.clave');

        foreach (self::PERMISOS as $nombre => [$permitidas, $alcance]) {
            foreach (DB::table('roles')->where('nombre', $nombre)->pluck('id') as $rolId) {
                foreach ($acciones as $clave => $moduloAccionId) {
                    $existe = DB::table('rol_permisos')->where('rol_id', $rolId)->where('modulo_accion_id', $moduloAccionId);
                    if (! in_array($clave, $permitidas, true)) {
                        $existe->delete();
                    } elseif ($existe->exists()) {
                        $existe->update(['alcance' => $alcance, 'updated_at' => $ahora]);
                    } else {
                        DB::table('rol_permisos')->insert([
                            'rol_id' => $rolId, 'modulo_accion_id' => $moduloAccionId, 'alcance' => $alcance,
                            'created_at' => $ahora, 'updated_at' => $ahora,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procedimiento_recordatorios');
        Schema::dropIfExists('procedimiento_eventos');
        Schema::dropIfExists('procedimiento_acuses');
        Schema::dropIfExists('procedimiento_adjuntos');
        Schema::dropIfExists('procedimiento_aplicaciones');
        Schema::dropIfExists('procedimiento_pasos');
        Schema::dropIfExists('procedimiento_versiones');
        Schema::dropIfExists('procedimientos');
        Schema::dropIfExists('procedimiento_categorias');
    }
};
