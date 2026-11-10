<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mejoras padrones (ADR-0015): solo cambia nombres que se ven, no datos.
 *
 *  - En la Matriz de permisos la acción «eliminar» se llama «Dar de baja»
 *    (en toda la plataforma «eliminar» siempre fue la baja reversible; el
 *    borrado es «Eliminar definitivamente»).
 *  - En el menú, Estacionamientos se llama «Estacionamientos y zonas de
 *    descarga» (solo si nadie le había puesto otro nombre de menú).
 *
 * down() regresa los dos nombres anteriores.
 */
return new class extends Migration
{
    private const MENU_ESTACIONAMIENTOS = 'Estacionamientos y zonas de descarga';

    public function up(): void
    {
        DB::table('acciones')->where('clave', 'eliminar')->where('nombre', 'Eliminar')
            ->update(['nombre' => 'Dar de baja', 'updated_at' => now()]);
        DB::table('modulos')->where('clave', 'estacionamientos')->whereNull('nombre_menu')
            ->update(['nombre_menu' => self::MENU_ESTACIONAMIENTOS, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('acciones')->where('clave', 'eliminar')->where('nombre', 'Dar de baja')
            ->update(['nombre' => 'Eliminar', 'updated_at' => now()]);
        DB::table('modulos')->where('clave', 'estacionamientos')->where('nombre_menu', self::MENU_ESTACIONAMIENTOS)
            ->update(['nombre_menu' => null, 'updated_at' => now()]);
    }
};
