<?php

use App\Services\Lector\PlantillasEtiquetas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 7 (parte B): gestor centralizado de impresión QR (Etiquetas QR).
 *
 *  - etiquetas_plantillas: plantillas de etiqueta por empresa (y opcionalmente
 *    por sede): rollo térmico (1 etiqueta por página) u hoja carta/A4 con
 *    planilla de N × M, medidas en mm, márgenes, separación, orientación,
 *    tamaño del QR y datos visibles.
 *  - impresiones_etiquetas (+ _items): historial de cada impresión (quién,
 *    cuándo, plantilla, cuántas y cuáles) para «Reimprimir».
 *  - Acción «configurar» del módulo etiquetas_qr (sub-pantalla Plantillas),
 *    para Administrador y Director (toda la empresa) y Jefe de seguridad (su sede).
 *
 * En una instalación nueva la acción la crean los seeders (CatalogoSeeder y
 * RolesPlantillaSeeder). En una base existente (QA, Producción) las
 * migraciones corren ANTES que los seeders: aquí se agrega la acción, se da
 * a esos roles y se crean las 4 plantillas de siempre en cada empresa. Solo agrega.
 */
return new class extends Migration
{
    /** rol => alcance de «etiquetas_qr.configurar» */
    private const ROLES = ['Administrador' => 'empresa', 'Director' => 'empresa', 'Jefe de seguridad' => 'sede'];

    public function up(): void
    {
        Schema::create('etiquetas_plantillas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // null = de toda la empresa
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            // llavero | etiqueta | gafete | calcomania: las 4 de siempre (no se borran)
            $table->string('clave', 30)->nullable();
            $table->string('nombre', 80);
            $table->string('formato', 10)->default('rollo'); // rollo | hoja
            $table->string('papel', 10)->nullable(); // carta | a4 (solo hoja)
            $table->decimal('ancho_mm', 5, 1);
            $table->decimal('alto_mm', 5, 1);
            $table->decimal('margen_superior_mm', 4, 1)->default(0);
            $table->decimal('margen_izquierdo_mm', 4, 1)->default(0);
            $table->decimal('separacion_horizontal_mm', 4, 1)->default(0);
            $table->decimal('separacion_vertical_mm', 4, 1)->default(0);
            $table->unsignedTinyInteger('columnas')->default(1);
            $table->unsignedTinyInteger('filas')->default(1);
            $table->string('orientacion', 12)->default('horizontal'); // horizontal (QR a la izquierda) | vertical (QR arriba)
            $table->decimal('qr_mm', 4, 1);
            $table->boolean('mostrar_titulo')->default(true);
            $table->boolean('mostrar_codigo')->default(true);
            $table->boolean('mostrar_fecha')->default(false);
            $table->boolean('mostrar_ubicacion')->default(false);
            $table->boolean('mostrar_tipo')->default(true);
            $table->boolean('mostrar_logo')->default(true);
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'clave']);
        });

        Schema::create('impresiones_etiquetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            // La sede de todas sus etiquetas (null = varias sedes o de toda la empresa)
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->foreignId('plantilla_id')->nullable()->constrained('etiquetas_plantillas')->nullOnDelete();
            $table->string('plantilla_nombre', 80);
            $table->unsignedSmallInteger('cantidad');
            $table->foreignId('reimpresion_de_id')->nullable()->constrained('impresiones_etiquetas')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['empresa_id', 'created_at']);
            $table->index(['empresa_id', 'creado_por']);
        });

        Schema::create('impresiones_etiquetas_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('impresion_id')->constrained('impresiones_etiquetas')->cascadeOnDelete();
            $table->string('tipo', 30); // llave, gafete, vehiculo… (config/lector.php)
            $table->unsignedBigInteger('registro_id');
            $table->string('titulo', 150); // como se veía al imprimir
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->index(['impresion_id', 'orden']);
            $table->index(['empresa_id', 'tipo', 'registro_id']);
        });

        $this->accionConfigurar();
        $this->plantillasDeSiempre();
    }

    /** Base existente: «etiquetas_qr.configurar» para Administrador, Director y Jefe de seguridad. */
    private function accionConfigurar(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'etiquetas_qr')->value('id');
        $accion = DB::table('acciones')->where('clave', 'configurar')->value('id');
        if ($modulo === null || $accion === null) {
            return; // instalación nueva: lo hacen los seeders
        }
        $ahora = now();
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $destino = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');

        foreach (self::ROLES as $nombre => $alcance) {
            foreach (DB::table('roles')->where('nombre', $nombre)->pluck('id') as $rolId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $rolId, 'modulo_accion_id' => $destino, 'alcance' => $alcance, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    /** Las 4 medidas de la Ronda 6 como plantillas en cada empresa que ya existe. */
    private function plantillasDeSiempre(): void
    {
        $ahora = now();
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            foreach (PlantillasEtiquetas::PREDETERMINADAS as $clave => $datos) {
                if (DB::table('etiquetas_plantillas')->where('empresa_id', $empresaId)->where('clave', $clave)->exists()) {
                    continue;
                }
                DB::table('etiquetas_plantillas')->insert(['empresa_id' => $empresaId, 'clave' => $clave] + $datos + ['created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impresiones_etiquetas_items');
        Schema::dropIfExists('impresiones_etiquetas');
        Schema::dropIfExists('etiquetas_plantillas');

        $modulo = DB::table('modulos')->where('clave', 'etiquetas_qr')->value('id');
        $accion = DB::table('acciones')->where('clave', 'configurar')->value('id');
        if ($modulo !== null && $accion !== null) {
            $ma = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');
            if ($ma !== null) {
                DB::table('rol_permisos')->where('modulo_accion_id', $ma)->delete();
                DB::table('modulo_acciones')->where('id', $ma)->delete();
            }
        }
    }
};
