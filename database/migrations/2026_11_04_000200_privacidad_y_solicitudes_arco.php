<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacidad (LFPDPPP; docs/tecnico/privacidad.md).
 *
 *  - avisos_privacidad: versiones del aviso de privacidad de cada empresa
 *    (empresa_id) y de la página pública de la plataforma (empresa_id nulo).
 *    Cada versión guarda su número, su texto, su huella SHA-256 y quién la publicó.
 *  - solicitudes_arco: solicitudes de Acceso, Rectificación, Cancelación u
 *    Oposición que llegan del formulario público, con su verificación por
 *    correo, su plazo legal (20 días hábiles) y lo que se hizo.
 *  - empresas.privacidad_codigo: código público del aviso de la empresa
 *    (/privacidad/{codigo}); se genera la primera vez que se necesita.
 *  - accesos.datos_eliminados_en: la conservación quitó los datos personales
 *    de ese registro de la bitácora (el registro se conserva).
 *  - candidatos.aviso_privacidad_id y solicitudes_compra.aviso_privacidad_id:
 *    qué versión del aviso aceptó cada persona.
 *  - Empresas que ya existían: la conservación automática queda APAGADA
 *    (preferencias.privacidad.conservacion_activa = false) hasta que el
 *    administrador la confirme. Las empresas nuevas la traen encendida.
 *  - Acción «datos_personales» en Configuración («Privacidad y solicitudes
 *    ARCO»), otorgada con alcance de empresa a los roles que hoy EDITAN la
 *    configuración con alcance de empresa (el Administrador y su plantilla).
 *
 * down(): quita la acción y sus permisos, la marca de las empresas, las
 * columnas (primero sus llaves foráneas, luego el índice único) y las tablas.
 * Seguro en MariaDB: ningún índice que se borra sostiene una llave foránea
 * que siga viva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_privacidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->text('texto');
            $table->char('huella', 64);
            $table->timestamp('publicado_en');
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'numero']);
        });

        Schema::create('solicitudes_arco', function (Blueprint $table) {
            $table->id();
            // Nula = llegó desde la página pública de la plataforma: el Super Administrador la canaliza
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->string('folio', 20);
            $table->string('derecho', 15);                 // acceso | rectificacion | cancelacion | oposicion
            $table->string('estado', 15)->default('por_verificar'); // por_verificar | pendiente | atendida | rechazada
            $table->string('nombre', 150);
            $table->string('correo', 150);
            $table->string('telefono', 20)->nullable();
            $table->string('relacion', 20)->nullable();    // visitante | candidato | colaborador | cliente | otro
            $table->text('detalle');
            $table->string('token_hash', 64)->nullable();
            $table->timestamp('token_vence_en')->nullable();
            $table->timestamp('verificada_en')->nullable();
            $table->string('verificada_como', 10)->nullable(); // correo | en_persona
            $table->date('vence_en')->nullable();          // 20 días hábiles desde la verificación
            $table->timestamp('recordada_en')->nullable(); // recordatorio de «faltan N días hábiles»
            $table->timestamp('escalada_en')->nullable();  // aviso de plazo vencido al Super Administrador
            $table->text('respuesta')->nullable();
            $table->timestamp('respondida_en')->nullable();
            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('acciones')->nullable();          // lo que se hizo: exportar, eliminar, canalizar (sin datos personales)
            $table->string('archivo', 255)->nullable();    // ZIP de «Exportar datos» (disco privado)
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
            $table->index('token_hash');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->string('privacidad_codigo', 16)->nullable()->unique();
        });
        Schema::table('accesos', function (Blueprint $table) {
            $table->timestamp('datos_eliminados_en')->nullable();
        });
        Schema::table('candidatos', function (Blueprint $table) {
            $table->foreignId('aviso_privacidad_id')->nullable()->constrained('avisos_privacidad')->nullOnDelete();
        });
        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->foreignId('aviso_privacidad_id')->nullable()->constrained('avisos_privacidad')->nullOnDelete();
        });

        $this->apagarConservacionEnEmpresasExistentes();
        $this->permiso();
    }

    public function down(): void
    {
        $this->quitarPermiso();
        $this->quitarMarcaDeEmpresas();

        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aviso_privacidad_id');
        });
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aviso_privacidad_id');
        });
        Schema::table('accesos', function (Blueprint $table) {
            $table->dropColumn('datos_eliminados_en');
        });
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique(['privacidad_codigo']);
            $table->dropColumn('privacidad_codigo');
        });
        Schema::dropIfExists('solicitudes_arco');
        Schema::dropIfExists('avisos_privacidad');
    }

    /** No borrar nada sin aprobación: las empresas que ya existían empiezan con la tarea apagada. */
    private function apagarConservacionEnEmpresasExistentes(): void
    {
        foreach (DB::table('empresas')->get(['id', 'preferencias']) as $e) {
            $p = is_string($e->preferencias) ? (json_decode($e->preferencias, true) ?: []) : [];
            $p['privacidad'] = array_merge(is_array($p['privacidad'] ?? null) ? $p['privacidad'] : [], ['conservacion_activa' => false]);
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function quitarMarcaDeEmpresas(): void
    {
        foreach (DB::table('empresas')->whereNotNull('preferencias')->get(['id', 'preferencias']) as $e) {
            $p = is_string($e->preferencias) ? (json_decode($e->preferencias, true) ?: []) : [];
            if (! isset($p['privacidad'])) {
                continue;
            }
            unset($p['privacidad']);
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => $p === [] ? null : json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function permiso(): void
    {
        $accion = DB::table('acciones')->where('clave', 'datos_personales')->value('id');
        $editar = DB::table('acciones')->where('clave', 'editar')->value('id');
        $modulo = DB::table('modulos')->where('clave', 'configuracion')->value('id');
        if ($accion === null || $editar === null || $modulo === null) {
            return; // instalación nueva: el catálogo y las plantillas los crean los seeders
        }
        $ahora = now();
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $nueva = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');
        $base = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $editar)->value('id');
        if ($nueva === null || $base === null) {
            return;
        }
        foreach (DB::table('rol_permisos')->where('modulo_accion_id', $base)->where('alcance', 'empresa')->pluck('rol_id') as $rolId) {
            DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $rolId, 'modulo_accion_id' => $nueva, 'alcance' => 'empresa', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    private function quitarPermiso(): void
    {
        $accion = DB::table('acciones')->where('clave', 'datos_personales')->value('id');
        $modulo = DB::table('modulos')->where('clave', 'configuracion')->value('id');
        if ($accion === null || $modulo === null) {
            return;
        }
        $ma = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');
        if ($ma === null) {
            return;
        }
        DB::table('rol_permisos')->where('modulo_accion_id', $ma)->delete();
        DB::table('modulo_acciones')->where('id', $ma)->delete();
    }
};
