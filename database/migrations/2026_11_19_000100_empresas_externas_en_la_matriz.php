<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisión del dueño (3 nov): en la Matriz de permisos, en la Bitácora de
 * auditoría y en lo que se contrata, el módulo «Proveedores» se llama
 * «Empresas externas», igual que el menú y la pantalla (glosario C-39).
 * Solo cambia el nombre que se ve: la clave del módulo y los permisos
 * `proveedores.*` no cambian.
 *
 * Solo se cambia si conserva el nombre original; down() lo regresa.
 */
return new class extends Migration
{
    private const ANTES = 'Proveedores';

    private const AHORA = 'Empresas externas';

    public function up(): void
    {
        DB::table('modulos')->where('clave', 'proveedores')->where('nombre', self::ANTES)
            ->update(['nombre' => self::AHORA, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modulos')->where('clave', 'proveedores')->where('nombre', self::AHORA)
            ->update(['nombre' => self::ANTES, 'updated_at' => now()]);
    }
};
