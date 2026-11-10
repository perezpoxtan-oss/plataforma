<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recepción de candidatos y visitas + Autorizaciones departamentales (ADR-0007).
 *
 *  - notificaciones:              centro de notificaciones de cada usuario (campana).
 *  - candidatos:                  ficha / CV del candidato, ligada al Padrón de personas y al acceso.
 *  - candidato_documentos:        CV, INE, comprobante (disco privado).
 *  - candidato_eventos:           historial de etapas (y tiempos para el SLA).
 *  - enlaces_kiosco:              enlace temporal (QR) para que el candidato llene su CV en su celular.
 *  - departamento_responsables:   quién autoriza por cada departamento (titular y suplentes).
 *  - delegaciones:                «No molestar»: un responsable delega en otro usuario de/a.
 *  - autorizaciones:              solicitud de autorización al departamento (visita o candidato).
 *  - accesos:                     fotos de la persona y de la identificación (privadas) y el
 *                                 estado de la autorización departamental de la visita.
 *
 * En una instalación nueva los módulos los crean los seeders. En una base
 * existente (QA, Producción) las migraciones corren ANTES que los seeders: se
 * crean los módulos y sus acciones, se acomodan en el menú de Recursos Humanos,
 * se activan en las empresas que tenían Colaboradores y se otorgan:
 *  - candidatos.*, recepcion_rh.ver y autorizaciones.* a los roles que dan de
 *    alta colaboradores (colaboradores.crear), con el mismo alcance;
 *  - autorizaciones.ver y .responder a los roles que autorizan accesos (accesos.aprobar).
 * Solo agrega.
 */
return new class extends Migration
{
    /** clave => [nombre, icono, acciones, color] */
    private const MODULOS = [
        'recepcion_rh' => ['Recepción de RR. HH.', 'bi-person-check', ['ver'], 'primary'],
        'candidatos' => ['Candidatos', 'bi-person-workspace', ['ver', 'crear', 'editar', 'eliminar', 'exportar', 'contratar', 'configurar'], 'success'],
        'autorizaciones' => ['Autorizaciones departamentales', 'bi-patch-check', ['ver', 'responder', 'configurar'], 'warning'],
    ];

    /** Acciones nuevas del catálogo. */
    private const ACCIONES = ['contratar' => 'Contratar', 'responder' => 'Responder'];

    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('titulo', 150);
            $table->string('texto', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('icono', 40)->default('bi-bell');
            $table->string('nivel', 12)->default('info');
            $table->string('referencia_tipo', 40)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->json('acciones')->nullable();
            $table->timestamp('leida_en')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'leida_en']);
            $table->index(['empresa_id', 'referencia_tipo', 'referencia_id']);
        });

        Schema::create('candidatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('acceso_id')->nullable()->constrained('accesos')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->string('vacante', 150)->nullable();
            // Datos de contacto
            $table->string('nombre_completo', 150);
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('ciudad', 120)->nullable();
            // CV
            $table->json('escolaridad')->nullable();
            $table->json('experiencia')->nullable();
            $table->string('habilidades', 1000)->nullable();
            $table->string('idiomas', 255)->nullable();
            $table->string('disponibilidad', 20)->nullable();
            $table->string('disponibilidad_notas', 255)->nullable();
            $table->decimal('pretension', 10, 2)->nullable();
            $table->json('referencias')->nullable();
            $table->text('notas_rh')->nullable();
            // Etapa: registrado | revision | aprobado_rh | entrevista | seleccionado | descartado | cartera | contratado
            $table->string('etapa', 20)->default('registrado');
            $table->string('motivo_descarte', 500)->nullable();
            $table->string('origen', 12)->default('caseta'); // caseta | rh | kiosco
            $table->boolean('autocaptura_pendiente')->default(false);
            $table->timestamp('autocaptura_en')->nullable();
            // Aviso de privacidad aceptado
            $table->timestamp('privacidad_aceptada_en')->nullable();
            $table->string('privacidad_ip', 45)->nullable();
            $table->string('privacidad_version', 64)->nullable();
            $table->string('privacidad_medio', 12)->nullable(); // kiosco | rh
            // Tiempos (SLA): llegada a caseta → aviso a RR. HH. → revisión → aprobado → departamento → entrevista → decisión
            $table->timestamp('llegada_en')->nullable();
            $table->timestamp('avisado_rh_en')->nullable();
            $table->timestamp('revision_en')->nullable();
            $table->timestamp('aprobado_rh_en')->nullable();
            $table->timestamp('enviado_departamento_en')->nullable();
            $table->timestamp('respuesta_departamento_en')->nullable();
            $table->timestamp('entrevista_en')->nullable();
            $table->timestamp('decision_en')->nullable();
            $table->foreignId('decision_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contratado_en')->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'sede_id', 'etapa']);
            $table->index(['empresa_id', 'created_at']);
        });

        Schema::create('candidato_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('tipo', 20); // cv | ine | comprobante | otro
            $table->string('nombre_original', 150);
            $table->string('ruta', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->string('origen', 12)->default('rh'); // rh | kiosco
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('candidato_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('evento', 30);
            $table->string('etapa_anterior', 20)->nullable();
            $table->string('etapa_nueva', 20)->nullable();
            $table->string('comentario', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['candidato_id', 'created_at']);
        });

        Schema::create('enlaces_kiosco', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('candidatos')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('codigo', 8)->index();
            $table->timestamp('expira_en');
            $table->unsignedTinyInteger('usos_maximos')->default(3);
            $table->unsignedTinyInteger('usos')->default(0);
            $table->timestamp('ultimo_uso_en')->nullable();
            $table->timestamp('revocado_en')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('departamento_responsables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->cascadeOnDelete(); // null = todas las sedes del departamento
            $table->boolean('es_suplente')->default(false);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->unique(['departamento_id', 'user_id']);
        });

        Schema::create('delegaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegado_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('desde');
            $table->timestamp('hasta');
            $table->string('motivo', 255)->nullable();
            $table->timestamp('cancelada_en')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'user_id', 'hasta']);
        });

        Schema::create('autorizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('departamento_id')->constrained('departamentos');
            $table->string('tipo', 12); // visita | candidato
            $table->foreignId('acceso_id')->nullable()->constrained('accesos')->cascadeOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->cascadeOnDelete();
            // pendiente | autorizada | rechazada | entrevista | cancelada
            $table->string('estado', 12)->default('pendiente');
            $table->timestamp('solicitada_en');
            $table->timestamp('respondida_en')->nullable();
            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('respuesta_medio', 12)->nullable(); // plataforma | correo | caseta | rh
            $table->string('comentario', 500)->nullable();
            $table->json('avisados')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
            $table->index(['empresa_id', 'departamento_id', 'solicitada_en']);
        });

        Schema::table('accesos', function (Blueprint $table) {
            $table->string('foto_persona', 255)->nullable()->after('observaciones');
            $table->string('foto_identificacion', 255)->nullable()->after('foto_persona');
            // null (no aplica) | esperando | autorizada | rechazada
            $table->string('autorizacion', 12)->nullable()->after('foto_identificacion');
        });

        $this->modulos();
    }

    private function modulos(): void
    {
        $area = DB::table('areas')->where('clave', 'recursos_humanos')->value('id');
        if ($area === null || DB::table('modulos')->where('clave', 'candidatos')->exists()) {
            return; // instalación nueva (lo crean los seeders) o ya migrada
        }
        $ahora = now();

        $orden = 0;
        foreach (self::ACCIONES as $clave => $nombre) {
            if (! DB::table('acciones')->where('clave', $clave)->exists()) {
                DB::table('acciones')->insert(['clave' => $clave, 'nombre' => $nombre, 'orden' => (int) DB::table('acciones')->max('orden') + (++$orden),
                    'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
        $acciones = DB::table('acciones')->pluck('id', 'clave');

        $menu = DB::table('menus')->where('clave', 'recursos_humanos')->value('id');
        $ordenMenu = $menu ? (int) DB::table('modulos')->where('menu_id', $menu)->max('orden_menu') : 0;
        $origen = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('m.clave', 'colaboradores')->get(['em.empresa_id', 'em.activo']);

        foreach (self::MODULOS as $clave => [$nombre, $icono, $claves, $color]) {
            $modulo = DB::table('modulos')->insertGetId([
                'area_id' => $area, 'padre_id' => null, 'clave' => $clave, 'nombre' => $nombre, 'icono' => $icono,
                'ruta' => ['recepcion_rh' => 'recepcion.index', 'candidatos' => 'candidatos.index', 'autorizaciones' => 'autorizaciones.index'][$clave],
                'orden' => (int) DB::table('modulos')->where('area_id', $area)->max('orden') + 1, 'tipo' => 'sistema', 'activo' => true,
                'menu_id' => $menu, 'seccion_menu' => $menu ? 'Recepción y candidatos' : null, 'orden_menu' => $menu ? ++$ordenMenu : null,
                'color_icono' => $color, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            foreach ($claves as $accion) {
                DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $acciones[$accion], 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            foreach ($origen as $fila) {
                DB::table('empresa_modulos')->insertOrIgnore(['empresa_id' => $fila->empresa_id, 'modulo_id' => $modulo, 'activo' => (bool) $fila->activo,
                    'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }

        // Permisos: [origen modulo.accion] => lista de destino modulo.accion (mismo alcance)
        $otorgar = [
            'colaboradores.crear' => ['recepcion_rh.ver', 'candidatos.ver', 'candidatos.crear', 'candidatos.editar', 'candidatos.eliminar', 'candidatos.exportar',
                'candidatos.contratar', 'candidatos.configurar', 'autorizaciones.ver', 'autorizaciones.responder', 'autorizaciones.configurar'],
            'accesos.aprobar' => ['autorizaciones.ver', 'autorizaciones.responder'],
        ];
        $idDe = fn (string $permiso) => DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', explode('.', $permiso)[0])->where('a.clave', explode('.', $permiso)[1])->value('ma.id');
        foreach ($otorgar as $desde => $destinos) {
            $origenId = $idDe($desde);
            if ($origenId === null) {
                continue;
            }
            $permisos = DB::table('rol_permisos')->where('modulo_accion_id', $origenId)->get(['rol_id', 'alcance']);
            foreach ($destinos as $destino) {
                $destinoId = $idDe($destino);
                foreach ($permisos as $p) {
                    DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $p->rol_id, 'modulo_accion_id' => $destinoId, 'alcance' => $p->alcance,
                        'created_at' => $ahora, 'updated_at' => $ahora]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::MODULOS) as $clave) {
            $modulo = DB::table('modulos')->where('clave', $clave)->value('id');
            if ($modulo !== null) {
                DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->where('modulo_id', $modulo)->select('id'))->delete();
                DB::table('modulo_acciones')->where('modulo_id', $modulo)->delete();
                DB::table('empresa_modulos')->where('modulo_id', $modulo)->delete();
                DB::table('modulos')->where('id', $modulo)->delete();
            }
        }

        Schema::table('accesos', function (Blueprint $table) {
            $table->dropColumn(['foto_persona', 'foto_identificacion', 'autorizacion']);
        });
        Schema::dropIfExists('autorizaciones');
        Schema::dropIfExists('delegaciones');
        Schema::dropIfExists('departamento_responsables');
        Schema::dropIfExists('enlaces_kiosco');
        Schema::dropIfExists('candidato_eventos');
        Schema::dropIfExists('candidato_documentos');
        Schema::dropIfExists('candidatos');
        Schema::dropIfExists('notificaciones');
    }
};
