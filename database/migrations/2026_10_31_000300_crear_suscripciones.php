<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripciones de los clientes (ver docs/tecnico/suscripciones.md).
 *
 *  - promociones / promocion_plan: códigos o promociones configurables (% o
 *    monto fijo, vigencia, planes, periodicidad, límite de usos, primeros N
 *    meses). Globales de la plataforma.
 *  - suscripciones: una por empresa (plan, sedes, periodicidad, precio pactado
 *    congelado, promoción, estado, fechas, revisión del Super Administrador).
 *    Cuelga de la empresa (empresa_id único); la administra solo la plataforma.
 *  - suscripcion_paquete: paquetes contratados.
 *  - suscripcion_excepciones: ajustes a mano por cliente (usuarios, sedes,
 *    módulos agregados o quitados) con motivo y quién; se dan de baja, no se borran.
 *  - suscripcion_historial: cada cambio de la suscripción (estado antes / después).
 *  - solicitudes_compra: tipo (compra, prueba, renovación, ampliación),
 *    periodicidad, desglose del periodo con descuentos, promoción, empresa
 *    (la del cliente que pide ampliar o renovar, o la que se creó al activarla).
 *
 * Una empresa SIN suscripción (las que ya existían) no tiene límites ni vencimiento.
 *
 * down(): quita las columnas nuevas de solicitudes_compra (primero sus llaves
 * foráneas, por MariaDB) y borra las tablas en orden inverso. Las empresas,
 * sedes y usuarios que se crearon al activar clientes se conservan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promociones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('codigo', 40)->nullable()->unique(); // nulo = solo se aplica a mano a un cliente
            $table->string('tipo', 12)->default('porcentaje'); // porcentaje | monto
            $table->decimal('valor', 10, 2)->default(0); // % o pesos al mes
            $table->string('periodicidad', 8)->default('ambas'); // ambas | mensual | anual
            $table->unsignedSmallInteger('meses')->nullable(); // primeros N meses; nulo = siempre
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();
            $table->unsignedInteger('usos_maximos')->nullable(); // nulo = sin límite
            $table->unsignedInteger('usos')->default(0);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['activo', 'vigente_hasta']);
        });

        Schema::create('promocion_plan', function (Blueprint $table) {
            $table->foreignId('promocion_id')->constrained('promociones')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('planes')->cascadeOnDelete();
            $table->primary(['promocion_id', 'plan_id']);
        });

        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('planes')->nullOnDelete();
            $table->foreignId('solicitud_compra_id')->nullable()->constrained('solicitudes_compra')->nullOnDelete();
            $table->unsignedSmallInteger('sedes_contratadas')->default(1);
            $table->string('periodicidad', 8)->default('mensual'); // mensual | anual
            // Precio pactado (congelado al activar o al cambiar de plan): lo que se cobra por periodo
            $table->string('moneda', 3)->default('MXN');
            $table->decimal('iva_porcentaje', 5, 2)->default(16);
            $table->decimal('precio_subtotal', 12, 2)->default(0);
            $table->decimal('precio_iva', 12, 2)->default(0);
            $table->decimal('precio_total', 12, 2)->default(0);
            $table->json('detalle')->nullable(); // renglones, descuentos y precio después de la promoción
            $table->foreignId('promocion_id')->nullable()->constrained('promociones')->nullOnDelete();
            $table->date('promocion_hasta')->nullable(); // fin de los «primeros N meses»
            // prueba | activa | por_vencer | en_gracia | solo_consulta | cancelada
            $table->string('estado', 14)->default('activa');
            $table->boolean('es_prueba')->default(false); // prueba gratis que todavía no tiene un pago
            $table->boolean('suspendida')->default(false); // solo consulta puesta a mano por el Super Administrador
            $table->date('inicio_en');
            $table->date('vence_en');
            $table->date('gracia_hasta')->nullable();
            $table->json('avisos_enviados')->nullable(); // avisos de la tarea diaria ya enviados (no se repiten)
            $table->timestamp('revisado_en')->nullable();
            $table->unsignedBigInteger('revisado_por')->nullable();
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['estado', 'vence_en']);
            $table->index('revisado_en');
        });

        Schema::create('suscripcion_paquete', function (Blueprint $table) {
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->primary(['suscripcion_id', 'paquete_id']);
        });

        Schema::create('suscripcion_excepciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->unsignedBigInteger('empresa_id')->index();
            $table->string('tipo', 16); // usuarios | sedes | modulo_agregado | modulo_quitado
            $table->integer('cantidad')->nullable(); // usuarios o sedes de más (o de menos, con signo)
            $table->foreignId('modulo_id')->nullable()->constrained('modulos')->cascadeOnDelete();
            $table->string('motivo', 255);
            $table->boolean('activo')->default(true);
            $table->timestamp('dado_de_baja_en')->nullable();
            $table->unsignedBigInteger('dado_de_baja_por')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['suscripcion_id', 'activo']);
        });

        Schema::create('suscripcion_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->unsignedBigInteger('empresa_id')->index();
            $table->string('evento', 40);
            $table->string('estado_antes', 14)->nullable();
            $table->string('estado_despues', 14)->nullable();
            $table->string('descripcion', 255)->nullable();
            $table->json('datos')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['suscripcion_id', 'id']);
        });

        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->string('tipo', 12)->default('compra')->after('folio'); // compra | prueba | renovacion | ampliacion
            $table->string('periodicidad', 8)->default('mensual')->after('sedes');
            $table->decimal('periodo_subtotal', 12, 2)->default(0)->after('unico_total');
            $table->decimal('periodo_iva', 12, 2)->default(0)->after('periodo_subtotal');
            $table->decimal('periodo_total', 12, 2)->default(0)->after('periodo_iva');
            $table->decimal('descuento', 12, 2)->default(0)->after('periodo_total');
            $table->string('codigo_promocion', 40)->nullable()->after('descuento');
            $table->foreignId('promocion_id')->nullable()->after('codigo_promocion')->constrained('promociones')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->after('promocion_id')->constrained('empresas')->nullOnDelete();
            $table->timestamp('activada_en')->nullable()->after('atendida_en');
            $table->index(['tipo', 'estado']);
        });

        // Las solicitudes que ya existían: su periodo es el mes
        DB::table('solicitudes_compra')->update([
            'periodo_subtotal' => DB::raw('mensual_subtotal'),
            'periodo_iva' => DB::raw('mensual_iva'),
            'periodo_total' => DB::raw('mensual_total'),
        ]);
    }

    public function down(): void
    {
        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->dropIndex(['tipo', 'estado']);
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropConstrainedForeignId('promocion_id');
        });
        Schema::table('solicitudes_compra', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'periodicidad', 'periodo_subtotal', 'periodo_iva', 'periodo_total', 'descuento', 'codigo_promocion', 'activada_en']);
        });

        Schema::dropIfExists('suscripcion_historial');
        Schema::dropIfExists('suscripcion_excepciones');
        Schema::dropIfExists('suscripcion_paquete');
        Schema::dropIfExists('suscripciones');
        Schema::dropIfExists('promocion_plan');
        Schema::dropIfExists('promociones');

        DB::table('notificaciones')->whereIn('tipo', ['cliente_por_revisar', 'prueba_por_aprobar', 'suscripcion_aviso'])->delete();
    }
};
