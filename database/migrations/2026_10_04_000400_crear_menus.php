<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu principal configurable: cada menu es un boton de la barra superior
 * (Estructura, Padrones, Operacion...) y cada modulo indica en que menu y
 * en que seccion aparece. Se administra desde la interfaz, no en codigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 60);
            $table->string('icono', 60)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            // Orden en el menu lateral del celular (alli la operacion va primero)
            $table->unsignedSmallInteger('orden_movil')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('modulos', function (Blueprint $table) {
            $table->foreignId('menu_id')->nullable()->after('padre_id')->constrained('menus')->nullOnDelete();
            $table->string('seccion_menu', 80)->nullable()->after('menu_id');
            $table->unsignedSmallInteger('orden_menu')->default(0)->after('seccion_menu');
            $table->string('color_icono', 20)->nullable()->after('icono');
        });
    }

    public function down(): void
    {
        Schema::table('modulos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_id');
            $table->dropColumn(['seccion_menu', 'orden_menu', 'color_icono']);
        });

        Schema::dropIfExists('menus');
    }
};
