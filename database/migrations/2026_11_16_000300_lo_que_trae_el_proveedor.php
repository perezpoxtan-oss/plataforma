<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de correcciones, Etapa 6 · C-19 (B-07, P-07, DD-18): lo que el
 * proveedor o contratista trae al entrar (lista corta y opcional) y la
 * comparación al darle salida.
 *
 *  - accesos_articulos: artículo, cantidad y número de serie de cada cosa
 *    que trae (una fila por artículo; se borra con su acceso).
 *  - accesos.articulos_salida: «mismo» (salió con lo mismo) o «distinto»
 *    (salió con algo de más o de menos: se canalizó al supervisor).
 *  - accesos.articulos_nota: qué notó el guardia («lleva una escalera más»).
 *  - accesos.articulos_por_revisar / _revisado_en / _revisado_por: el
 *    supervisor revisa la diferencia y toca «Atender» (reloj y Mis pendientes).
 *  - accesos.pase_salida_id: el pase de salida abierto de su empresa que la
 *    caseta tuvo a la vista al darle salida.
 *
 * down(): quita las columnas y la tabla (los accesos conservan lo demás).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_articulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('acceso_id')->constrained('accesos')->cascadeOnDelete();
            $table->string('articulo', 120);
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->string('serie', 80)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'acceso_id']);
        });

        Schema::table('accesos', function (Blueprint $table) {
            $table->string('articulos_salida', 10)->nullable()->after('identificacion_devuelta');
            $table->string('articulos_nota', 250)->nullable()->after('articulos_salida');
            $table->boolean('articulos_por_revisar')->default(false)->after('articulos_nota');
            $table->timestamp('articulos_revisado_en')->nullable()->after('articulos_por_revisar');
            $table->unsignedBigInteger('articulos_revisado_por')->nullable()->after('articulos_revisado_en');
            $table->unsignedBigInteger('pase_salida_id')->nullable()->after('articulos_revisado_por');
            $table->index(['empresa_id', 'articulos_por_revisar']);
        });
        Restricciones::agregarForanea('accesos', 'articulos_revisado_por', 'users', 'set null');
        Restricciones::agregarForanea('accesos', 'pase_salida_id', 'pases_salida', 'set null');
    }

    public function down(): void
    {
        Restricciones::quitarForanea('accesos', 'pase_salida_id');
        Restricciones::quitarForanea('accesos', 'articulos_revisado_por');
        Schema::table('accesos', fn (Blueprint $table) => $table->dropIndex(['empresa_id', 'articulos_por_revisar']));
        Schema::table('accesos', fn (Blueprint $table) => $table->dropColumn(['articulos_salida', 'articulos_nota', 'articulos_por_revisar', 'articulos_revisado_en', 'articulos_revisado_por', 'pase_salida_id']));
        Schema::dropIfExists('accesos_articulos');
    }
};
