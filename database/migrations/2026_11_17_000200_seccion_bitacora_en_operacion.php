<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan de correcciones, Etapa 7 · C-39 (glosario): en el menú Operación la
 * sección «Incidentes» (Novedades, Lost & Found y Robo) se llama «Bitácora».
 * Con la bitácora renombrada a «Incidentes» el camino decía
 * «Operación → Incidentes → Incidentes».
 *
 * El MenuSeeder solo acomoda los módulos que aún no tienen menú, por eso el
 * cambio en instalaciones existentes va aquí. Solo se toca la sección de
 * fábrica (si alguien la cambió a mano, se respeta).
 *
 * down(): la sección vuelve a llamarse «Incidentes».
 */
return new class extends Migration
{
    private const MODULOS = ['novedades', 'lost_found', 'robo'];

    public function up(): void
    {
        DB::table('modulos')->whereIn('clave', self::MODULOS)->where('seccion_menu', 'Incidentes')->update(['seccion_menu' => 'Bitácora']);
    }

    public function down(): void
    {
        DB::table('modulos')->whereIn('clave', self::MODULOS)->where('seccion_menu', 'Bitácora')->update(['seccion_menu' => 'Incidentes']);
    }
};
