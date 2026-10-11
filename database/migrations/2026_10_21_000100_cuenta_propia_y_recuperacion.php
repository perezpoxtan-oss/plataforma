<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta es de su dueño (criterio 6 de docs/criterios-ux.md):
 *  - debe_cambiar_contrasena: la cuenta se creó o se restableció con una
 *    contraseña que conoce el administrador; al entrar, la persona la cambia
 *    en Mi cuenta antes de usar cualquier pantalla. Los usuarios existentes
 *    NO se marcan (por omisión false).
 *  - desactivado_por_baja_en: la cuenta se desactivó sola al dar de baja al
 *    colaborador vinculado (Mis pendientes → «Cuentas por revisar»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'debe_cambiar_contrasena')) {
                $table->boolean('debe_cambiar_contrasena')->default(false)->after('activo');
            }
            if (! Schema::hasColumn('users', 'desactivado_por_baja_en')) {
                $table->timestamp('desactivado_por_baja_en')->nullable()->after('ultimo_acceso_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnas = array_filter(['debe_cambiar_contrasena', 'desactivado_por_baja_en'], fn ($c) => Schema::hasColumn('users', $c));
            if ($columnas !== []) {
                $table->dropColumn(array_values($columnas));
            }
        });
    }
};
