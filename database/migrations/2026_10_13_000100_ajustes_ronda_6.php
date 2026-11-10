<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 6 de ajustes (QA del dueño):
 *  - GV-04: el artículo de un voucher (llave, gafete o equipo) puede
 *    aparecer. «Recuperado» lo reactiva y deja el voucher como «Cancelado por
 *    recuperación» (si el cobro no se había pagado, se cancela) o
 *    «Reembolso pendiente» (si ya se había cobrado); después se marca
 *    «Reembolsado». Se guarda quién, cuándo y el comentario.
 *  - LL-06: módulo «Etiquetas QR» (Padrones → Inventarios de Seguridad) para
 *    imprimir en bloque las etiquetas de todo lo que se identifica con QR.
 *
 * En una instalación nueva el módulo lo crean los seeders (aquí solo se
 * agregan columnas). En una base existente (QA, Producción) el despliegue
 * corre las migraciones ANTES que los seeders: se crea el módulo con su
 * acción «ver», se acomoda en el menú, se activa en las empresas que tenían
 * algún padrón con etiquetas y se otorga «etiquetas_qr.ver» a cada rol que
 * ya podía imprimir etiquetas en algún módulo (mismo alcance). Solo agrega.
 */
return new class extends Migration
{
    private const CLAVE = 'etiquetas_qr';

    /** Permisos que ya imprimían etiquetas: quien tenga alguno ve la pantalla nueva. */
    private const ORIGEN = [
        ['llaves', 'imprimir'], ['gafetes', 'imprimir'], ['equipos', 'imprimir'], ['equipos_pc', 'imprimir'],
        ['vehiculos', 'imprimir'], ['lost_found', 'imprimir'],
    ];

    public function up(): void
    {
        Schema::table('vouchers_reposicion', function (Blueprint $table) {
            // vigente | cancelado_recuperacion | reembolso_pendiente | reembolsado
            $table->string('estado', 30)->default('vigente')->after('colaborador_id');
            $table->timestamp('recuperado_en')->nullable()->after('estado');
            $table->foreignId('recuperado_por')->nullable()->after('recuperado_en')->constrained('users')->nullOnDelete();
            $table->string('recuperacion_comentario', 500)->nullable()->after('recuperado_por');
            $table->timestamp('reembolsado_en')->nullable()->after('recuperacion_comentario');
            $table->foreignId('reembolsado_por')->nullable()->after('reembolsado_en')->constrained('users')->nullOnDelete();
            $table->string('reembolso_comentario', 500)->nullable()->after('reembolsado_por');
            $table->index(['empresa_id', 'estado']);
        });

        $this->moduloEtiquetas();
    }

    private function moduloEtiquetas(): void
    {
        $area = DB::table('areas')->where('clave', 'seguridad')->value('id');
        if ($area === null || DB::table('modulos')->where('clave', self::CLAVE)->exists()) {
            return; // instalación nueva (lo crean los seeders) o ya migrada
        }
        $ahora = now();

        // Padrones → Inventarios de Seguridad, al final de la sección
        $menu = DB::table('menus')->where('clave', 'padrones')->value('id');
        $ultimo = $menu ? DB::table('modulos')->where('menu_id', $menu)->where('seccion_menu', 'Inventarios de Seguridad')->max('orden_menu') : null;
        $ordenMenu = $ultimo !== null ? (int) $ultimo + 1 : null;
        if ($menu && $ordenMenu !== null) {
            DB::table('modulos')->where('menu_id', $menu)->where('orden_menu', '>=', $ordenMenu)->increment('orden_menu');
        }

        $modulo = DB::table('modulos')->insertGetId([
            'area_id' => $area,
            'padre_id' => null,
            'clave' => self::CLAVE,
            'nombre' => 'Etiquetas QR',
            'icono' => 'bi-qr-code',
            'ruta' => 'etiquetas.index',
            'orden' => (int) DB::table('modulos')->where('area_id', $area)->max('orden') + 1,
            'tipo' => 'sistema',
            'activo' => true,
            'menu_id' => $menu,
            'seccion_menu' => $menu ? 'Inventarios de Seguridad' : null,
            'orden_menu' => $menu ? ($ordenMenu ?? 0) : null,
            'color_icono' => 'dark',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $ver = DB::table('acciones')->where('clave', 'ver')->value('id');
        if ($ver === null) {
            return;
        }
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $ver, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $destino = (int) DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $ver)->value('id');

        // Activo en cada empresa que tenía algún padrón con etiquetas
        $origenes = array_unique(array_column(self::ORIGEN, 0));
        $contratos = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->whereIn('m.clave', $origenes)->get(['em.empresa_id', 'em.activo']);
        foreach ($contratos->groupBy('empresa_id') as $empresaId => $filas) {
            DB::table('empresa_modulos')->insertOrIgnore([
                'empresa_id' => $empresaId, 'modulo_id' => $modulo, 'activo' => $filas->contains(fn ($f) => (bool) $f->activo),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        // «etiquetas_qr.ver» a quien ya imprimía etiquetas en algún módulo (mismo alcance)
        foreach (self::ORIGEN as [$moduloOrigen, $accionOrigen]) {
            $origen = DB::table('modulo_acciones as ma')
                ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
                ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->where('m.clave', $moduloOrigen)->where('a.clave', $accionOrigen)
                ->value('ma.id');
            if ($origen === null) {
                continue;
            }
            foreach (DB::table('rol_permisos')->where('modulo_accion_id', $origen)->get(['rol_id', 'alcance']) as $permiso) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $permiso->rol_id, 'modulo_accion_id' => $destino, 'alcance' => $permiso->alcance,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        $modulo = DB::table('modulos')->where('clave', self::CLAVE)->value('id');
        if ($modulo !== null) {
            DB::table('modulo_acciones')->where('modulo_id', $modulo)->delete();
            DB::table('empresa_modulos')->where('modulo_id', $modulo)->delete();
            DB::table('modulos')->where('id', $modulo)->delete();
        }

        Schema::table('vouchers_reposicion', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'estado']);
            $table->dropConstrainedForeignId('recuperado_por');
            $table->dropConstrainedForeignId('reembolsado_por');
            $table->dropColumn(['estado', 'recuperado_en', 'recuperacion_comentario', 'reembolsado_en', 'reembolso_comentario']);
        });
    }
};
