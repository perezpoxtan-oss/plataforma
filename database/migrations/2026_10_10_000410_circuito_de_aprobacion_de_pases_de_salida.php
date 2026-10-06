<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pases de salida v2: circuito de aprobación configurable por empresa,
 * bandeja de firmas, pasos físicos de caseta con verificación de artículos,
 * bitácora inmutable y hoja con QR de verificación.
 *
 *  - pases_salida_pasos (+ pases_salida_paso_usuarios): el circuito de
 *    aprobación de la empresa (Configuración → Pases de salida). Sin filas,
 *    se usa la cadena de SEGCAT: Jefe de Departamento, Contraloría, Gerencia.
 *  - pases_salida_aprobaciones: copia del circuito al enviar cada pase (un
 *    cambio de configuración no altera los pases en curso). "ronda" crece cada
 *    vez que el solicitante corrige y reenvía un pase rechazado.
 *  - pases_salida_bitacora: cada paso con quién, cuándo, comentario e IP. No
 *    se edita ni se borra.
 *  - firmas_usuarios: la firma guardada de cada usuario (disco privado), solo
 *    si él acepta guardarla; se reutiliza al aprobar o firmar en caseta.
 *  - Columnas nuevas: código de verificación del QR, ronda, cancelación,
 *    cierre con faltantes, último recordatorio de vencido; en artículos, la
 *    verificación de salida (a mano o con lector) y lo que ya regresó; en
 *    firmas, el usuario que firmó, su cargo y la entrada de bitácora.
 *
 * Los pases existentes se conservan: reciben su código, sus 3 aprobaciones
 * (marcadas con las firmas de SEGCAT que ya tenían) y su bitácora a partir
 * de las firmas y del rechazo. También se da de alta la acción
 * "pases_salida.configurar" en una base existente (Administrador, con el
 * alcance de su "pases_salida.editar").
 */
return new class extends Migration
{
    /** Cadena de SEGCAT: rol de firma antiguo => nombre del paso */
    private const CADENA = ['jefe_depto' => 'Jefe de Departamento', 'contraloria_salida' => 'Contraloría', 'gerencia' => 'Gerencia'];

    public function up(): void
    {
        Schema::table('pases_salida', function (Blueprint $table) {
            $table->string('codigo_verificacion', 32)->nullable()->after('folio');
            $table->unsignedSmallInteger('ronda')->default(1)->after('estado');
            $table->timestamp('cancelado_en')->nullable()->after('rechazado_por');
            $table->foreignId('cancelado_por')->nullable()->after('cancelado_en')->constrained('users')->nullOnDelete();
            $table->text('motivo_cancelacion')->nullable()->after('cancelado_por');
            $table->boolean('cerrado_con_faltantes')->default(false)->after('regreso_en');
            $table->date('recordatorio_vencido_en')->nullable()->after('cerrado_con_faltantes');
            $table->unique('codigo_verificacion');
        });

        Schema::table('pases_salida_articulos', function (Blueprint $table) {
            $table->timestamp('verificado_salida_en')->nullable()->after('descripcion');
            $table->boolean('verificado_con_lector')->default(false)->after('verificado_salida_en');
            $table->unsignedInteger('cantidad_regresada')->default(0)->after('verificado_con_lector');
        });

        Schema::create('pases_salida_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->string('nombre', 120);                                              // "Jefe del departamento", "Contraloría"…
            $table->string('tipo', 20);                                                 // permiso | rol | usuarios
            $table->foreignId('rol_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('departamento', 20)->default('cualquiera');                  // cualquiera | solicitante | especifico
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->boolean('obligatorio')->default(true);
            $table->json('motivos')->nullable();                                        // null = todos los motivos
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['empresa_id', 'activo', 'orden']);
        });

        Schema::create('pases_salida_paso_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('paso_id')->constrained('pases_salida_pasos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['paso_id', 'user_id']);
        });

        Schema::table('pases_salida_firmas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('nombre_firma')->constrained('users')->nullOnDelete(); // quien firmó con su usuario (null: una persona sin cuenta)
            $table->string('cargo', 120)->nullable()->after('user_id');                  // "Contraloría", "Seguridad"…
            $table->dropUnique(['pase_salida_id', 'rol']);                                 // un rol puede firmar en cada ronda y en cada regreso parcial
            $table->index(['pase_salida_id', 'rol']);
        });

        Schema::create('pases_salida_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('pase_salida_id')->constrained('pases_salida')->cascadeOnDelete();
            $table->unsignedSmallInteger('ronda')->default(1);
            $table->unsignedSmallInteger('orden');
            $table->foreignId('paso_id')->nullable()->constrained('pases_salida_pasos')->nullOnDelete();
            $table->string('nombre', 120);
            $table->string('tipo', 20);                                                 // permiso | rol | usuarios
            $table->foreignId('rol_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete(); // ya resuelto (el del solicitante o uno fijo)
            $table->json('usuarios')->nullable();                                       // ids, si el paso es de usuarios específicos
            $table->boolean('obligatorio')->default(true);
            $table->string('estado', 20)->default('pendiente');                         // pendiente | aprobado | omitido | rechazado
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_en')->nullable();
            $table->text('comentario')->nullable();
            $table->foreignId('firma_id')->nullable()->constrained('pases_salida_firmas')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['pase_salida_id', 'ronda', 'orden']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('pases_salida_bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('pase_salida_id')->constrained('pases_salida')->cascadeOnDelete();
            $table->string('evento', 40);                                               // creado, aprobado, rechazado, salida, regreso…
            $table->string('titulo', 150);                                              // texto que se muestra, fijo al momento
            $table->text('comentario')->nullable();
            $table->json('detalle')->nullable();                                        // artículos verificados, cantidades…
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre', 150)->nullable();                          // nombre del usuario al momento
            $table->string('ip', 45)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pase_salida_id', 'id']);
            $table->index(['empresa_id', 'evento']);
        });

        Schema::table('pases_salida_firmas', function (Blueprint $table) {
            $table->foreignId('bitacora_id')->nullable()->after('pase_salida_id')->constrained('pases_salida_bitacora')->nullOnDelete();
        });

        Schema::create('firmas_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('firma_ruta', 255);                                          // disco privado (App\Services\Firmas\Firmas)
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'user_id']);
        });

        $this->conservarPasesExistentes();
        $this->accionConfigurar();
    }

    /**
     * Los pases que ya existían reciben código, aprobaciones y bitácora.
     */
    private function conservarPasesExistentes(): void
    {
        $ahora = now();
        $rolesAntiguos = $this->rolesAntiguos();

        foreach (DB::table('pases_salida')->orderBy('id')->get() as $pase) {
            do {
                $codigo = Str::upper(Str::random(20));
            } while (DB::table('pases_salida')->where('codigo_verificacion', $codigo)->exists());
            DB::table('pases_salida')->where('id', $pase->id)->update(['codigo_verificacion' => $codigo]);

            // Si salió completo y regresó, sus artículos ya regresaron y salieron verificados
            $cambios = ($pase->salio_en !== null ? ['verificado_salida_en' => $pase->salio_en] : [])
                + ($pase->estado === 'regresado' ? ['cantidad_regresada' => DB::raw('cantidad')] : []);
            if ($cambios !== []) {
                DB::table('pases_salida_articulos')->where('pase_salida_id', $pase->id)->update($cambios);
            }

            $bitacora = fn (string $evento, string $titulo, $fecha, ?int $usuario, ?string $comentario = null) => DB::table('pases_salida_bitacora')->insertGetId([
                'empresa_id' => $pase->empresa_id, 'pase_salida_id' => $pase->id, 'evento' => $evento, 'titulo' => $titulo,
                'comentario' => $comentario, 'user_id' => $usuario, 'usuario_nombre' => $usuario ? DB::table('users')->where('id', $usuario)->value('name') : null,
                'creado_por' => $usuario, 'actualizado_por' => $usuario, 'created_at' => $fecha, 'updated_at' => $fecha,
            ]);

            $bitacora('creado', 'Solicitud registrada y enviada a aprobación', $pase->created_at, $pase->creado_por);

            $firmas = DB::table('pases_salida_firmas')->where('pase_salida_id', $pase->id)->orderBy('id')->get();
            foreach ($firmas as $firma) {
                $evento = $firma->grupo === 'aprobacion' ? 'aprobado' : 'firma';
                $titulo = ($firma->grupo === 'aprobacion' ? 'Aprobó: ' : 'Firmó: ').($rolesAntiguos[$firma->rol] ?? $firma->rol);
                $id = $bitacora($evento, $titulo, $firma->created_at, $firma->creado_por);
                DB::table('pases_salida_firmas')->where('id', $firma->id)->update(['bitacora_id' => $id, 'cargo' => $rolesAntiguos[$firma->rol] ?? null]);
            }

            if ($pase->estado === 'rechazado') {
                $bitacora('rechazado', 'Rechazó el pase y lo devolvió al solicitante', $pase->rechazado_en ?? $pase->updated_at, $pase->rechazado_por, $pase->motivo_rechazo);
            }

            // Las 3 aprobaciones de SEGCAT, marcadas con sus firmas
            $porRol = $firmas->keyBy('rol');
            $orden = 0;
            $rechazoPendiente = $pase->estado === 'rechazado';
            foreach (self::CADENA as $rol => $nombre) {
                $firma = $porRol[$rol] ?? null;
                // En un pase rechazado, el rechazo queda en el primer paso que no se había firmado
                $estado = $firma !== null ? 'aprobado' : ($rechazoPendiente ? 'rechazado' : 'pendiente');
                if ($estado === 'rechazado') {
                    $rechazoPendiente = false;
                }
                DB::table('pases_salida_aprobaciones')->insert([
                    'empresa_id' => $pase->empresa_id, 'pase_salida_id' => $pase->id, 'ronda' => 1, 'orden' => ++$orden,
                    'nombre' => $nombre, 'tipo' => 'permiso', 'obligatorio' => true, 'estado' => $estado,
                    'resuelto_por' => $firma?->creado_por ?? ($estado === 'rechazado' ? $pase->rechazado_por : null),
                    'resuelto_en' => $firma?->created_at ?? ($estado === 'rechazado' ? $pase->rechazado_en : null),
                    'comentario' => $estado === 'rechazado' ? $pase->motivo_rechazo : null,
                    'firma_id' => $firma?->id,
                    'created_at' => $pase->created_at ?? $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    /**
     * Textos de los roles de firma de SEGCAT (PaseSalida::GRUPOS al momento de esta migración).
     *
     * @return array<string, string>
     */
    private function rolesAntiguos(): array
    {
        return [
            'jefe_depto' => 'Jefe de Departamento', 'contraloria_salida' => 'Contraloría', 'gerencia' => 'Gerencia',
            'solicitante_salida' => 'Solicitante', 'recibe_salida' => 'Recibe (se lleva el equipo)', 'seguridad_salida' => 'Seguridad',
            'entrega_destino' => 'Quien trasladó el equipo', 'recibe_destino' => 'Quien recibe en destino', 'seguridad_destino' => 'Seguridad', 'contraloria_destino' => 'Contraloría',
            'jefe_depto_salida_regreso' => 'Jefe de Departamento (destino)', 'contraloria_salida_regreso' => 'Contraloría (destino)', 'gerencia_salida_regreso' => 'Gerencia (destino)',
            'solicitante_salida_regreso' => 'Solicitante', 'seguridad_salida_regreso' => 'Seguridad', 'traslada_salida_regreso' => 'Quien lo traslada de vuelta',
            'recibe_regreso' => 'Recibe (en origen)', 'entrega_regreso' => 'Entrega (trae el equipo de vuelta)', 'seguridad_regreso' => 'Visto bueno de Seguridad', 'contraloria_regreso' => 'Contraloría',
        ];
    }

    /**
     * Acción "configurar" de Pases de salida en una base existente (en una
     * instalación nueva la crean el catálogo y las plantillas).
     */
    private function accionConfigurar(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'pases_salida')->value('id');
        $accion = DB::table('acciones')->where('clave', 'configurar')->value('id');
        if ($modulo === null || $accion === null) {
            return;
        }

        $ahora = now();
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);

        $permiso = fn (string $clave) => DB::table('modulo_acciones as ma')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('ma.modulo_id', $modulo)->where('a.clave', $clave)->value('ma.id');
        $configurar = $permiso('configurar');
        $editar = $permiso('editar');
        if ($configurar === null || $editar === null) {
            return;
        }

        $administradores = DB::table('roles as r')->join('rol_permisos as rp', 'rp.rol_id', '=', 'r.id')
            ->where('r.nombre', 'Administrador')->where('rp.modulo_accion_id', $editar)->get(['r.id', 'rp.alcance']);
        foreach ($administradores as $rol) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $rol->id, 'modulo_accion_id' => $configurar, 'alcance' => $rol->alcance, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pases_salida_firmas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bitacora_id');
        });
        Schema::dropIfExists('firmas_usuarios');
        Schema::dropIfExists('pases_salida_bitacora');
        Schema::dropIfExists('pases_salida_aprobaciones');
        Schema::table('pases_salida_firmas', function (Blueprint $table) {
            $table->dropIndex(['pase_salida_id', 'rol']);
            $table->unique(['pase_salida_id', 'rol']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('cargo');
        });
        Schema::dropIfExists('pases_salida_paso_usuarios');
        Schema::dropIfExists('pases_salida_pasos');
        Schema::table('pases_salida_articulos', function (Blueprint $table) {
            $table->dropColumn(['verificado_salida_en', 'verificado_con_lector', 'cantidad_regresada']);
        });
        Schema::table('pases_salida', function (Blueprint $table) {
            $table->dropUnique(['codigo_verificacion']);
            $table->dropConstrainedForeignId('cancelado_por');
            $table->dropColumn(['codigo_verificacion', 'ronda', 'cancelado_en', 'motivo_cancelacion', 'cerrado_con_faltantes', 'recordatorio_vencido_en']);
        });
    }
};
