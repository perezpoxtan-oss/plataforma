<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 8 · C-44: credencialización. La credencial
 * (el plástico del empleado; el gafete es el de visita) se maneja como un
 * activo más, con los estados de C-14: DISPONIBLE (la tiene Recursos
 * Humanos), ASIGNADO (la trae el colaborador), NO REGRESÓ y DE BAJA.
 *
 *  - Tabla `credenciales`: una fila por plástico entregado a un colaborador,
 *    con su folio por empresa (CRED-000001), la tarjeta NFC/RFID que llevaba
 *    al entregarse, quién la entregó y la recibió, y el motivo de su baja
 *    (reposición con voucher, baja del colaborador).
 *  - Permiso nuevo «Colaboradores → Credencial» (`colaboradores.credencial`):
 *    dar salida, recibir y dar de baja la credencial. Lo reciben los roles
 *    que ya editan colaboradores, con el mismo alcance (en una instalación
 *    nueva lo dan las plantillas: Administrador y Recursos Humanos).
 *
 * down(): quita el permiso (los permisos se borran con él) y la tabla. Los
 * vouchers con origen «credencial» se conservan (son comprobantes de cobro;
 * su descripción ya dice qué credencial era).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciales', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->unsignedInteger('consecutivo');
            $table->string('folio', 20);
            $table->string('estado', 20)->default('asignado');      // disponible | asignado | no_regreso | baja
            $table->string('etiqueta_nfc', 64)->nullable();           // la tarjeta que llevaba al entregarse (constancia)
            $table->timestamp('entregada_en')->nullable();
            $table->foreignId('entregada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recibida_en')->nullable();
            $table->foreignId('recibida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('baja_motivo', 30)->nullable();            // reposicion | baja_colaborador
            $table->timestamp('baja_en')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers_reposicion')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'consecutivo']);
            $table->unique(['empresa_id', 'folio']);
            $table->index(['empresa_id', 'colaborador_id', 'estado']);
            $table->index(['empresa_id', 'estado', 'sede_id']);
        });

        $ahora = now();
        $orden = (int) DB::table('acciones')->max('orden') + 1;
        DB::table('acciones')->insertOrIgnore(['clave' => 'credencial', 'nombre' => 'Credencial', 'orden' => $orden, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $accion = DB::table('acciones')->where('clave', 'credencial')->value('id');
        $modulo = DB::table('modulos')->where('clave', 'colaboradores')->value('id');
        if ($accion === null || $modulo === null) {
            return; // instalación nueva: lo crean el catálogo y las plantillas
        }
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $nueva = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');
        $editar = DB::table('modulo_acciones as ma')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('ma.modulo_id', $modulo)->where('a.clave', 'editar')->value('ma.id');
        if ($editar === null) {
            return;
        }
        foreach (DB::table('rol_permisos')->where('modulo_accion_id', $editar)->get(['rol_id', 'alcance']) as $p) {
            DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $p->rol_id, 'modulo_accion_id' => $nueva, 'alcance' => $p->alcance,
                'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        $accion = DB::table('acciones')->where('clave', 'credencial')->value('id');
        if ($accion !== null) {
            $ids = DB::table('modulo_acciones')->where('accion_id', $accion)->pluck('id');
            DB::table('rol_permisos')->whereIn('modulo_accion_id', $ids)->delete();
            DB::table('modulo_acciones')->whereIn('id', $ids)->delete();
            DB::table('acciones')->where('id', $accion)->delete();
        }
        Schema::dropIfExists('credenciales');
    }
};
