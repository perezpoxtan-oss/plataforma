<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas de acceso. Se conserva la tabla `users` de Laravel (excepcion
 * documentada en ADR-0002) y se amplia con empresa, estado y bloqueo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('id')
                ->constrained('empresas')->restrictOnDelete();
            $table->string('username', 60)->nullable()->unique()->after('name');
            $table->boolean('es_superadmin')->default(false)->after('password');
            $table->boolean('activo')->default(true)->after('es_superadmin');
            $table->unsignedTinyInteger('intentos_fallidos')->default(0)->after('activo');
            $table->timestamp('bloqueado_hasta')->nullable()->after('intentos_fallidos');
            $table->timestamp('ultimo_acceso_en')->nullable()->after('bloqueado_hasta');
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username', 'es_superadmin', 'activo', 'intentos_fallidos',
                'bloqueado_hasta', 'ultimo_acceso_en', 'creado_por', 'actualizado_por',
            ]);
        });
    }
};
