<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de Novedades — despacho de tickets con expediente por categoría
 * (SEGCAT: modules/bitacora/novedades_*, fragmentos/frag_*.php).
 *
 * Cabecera y log:
 *  - novedades: el ticket (SEGCAT bitacora_novedades). Lo propio de cada
 *    categoría ya no vive en columnas sueltas de la cabecera (ig_*,
 *    acciones_inmediatas): va en su tabla de detalle.
 *  - novedad_notas: el "Minuto a Minuto" (antes un solo texto que crecía con
 *    CONCAT); ahora una fila por nota, nunca se edita ni se borra.
 *  - novedad_testigos: testigos de Accidente, Siniestro PC y Robo (antes tres
 *    tablas iguales), con "formato" para no mezclarlos si cambia la categoría.
 *
 * Detalle por categoría (1:1 con la novedad, salvo las listas):
 *  - novedad_reportes_generales (Reporte General)
 *  - accidente_huespedes, accidente_colaboradores, accidente_dictamenes,
 *    accidente_guardavidas, accidente_incapacidades, accidente_firmas
 *    (las 6 firmas: una fila por firma con la ruta del archivo privado; antes
 *    6 columnas base64 en la base de datos)
 *  - valores_vista_detalles + _personas, _aperturas, _zonas (Valores a la Vista)
 *  - siniestro_detalles + _servicios, _equipos, _danos (Siniestro PC)
 *  - novedad_recorrido_puntos (Recorrido PC histórico)
 *  - lost_found_detalles, lost_found_articulos, lost_found_reportes_perdida,
 *    lost_found_umbrales y lost_found_entregas (base para las pantallas de
 *    archivo, cierre, etiqueta y auditoría de Lost & Found)
 *  - robo_detalles (base para la pantalla de seguimiento de Robo)
 *
 * Toda tabla lleva empresa_id (scope de tenant) y auditoría de autor.
 * Las fechas y horas con día (ocurrio_en, controlado_en) se guardan en UTC.
 */
return new class extends Migration
{
    /** Días de resguardo por tipo de valor (SEGCAT lost_found_umbrales). */
    private const UMBRALES = ['ALTO_VALOR' => 180, 'ELECTRONICO' => 180, 'OTRO' => 90, 'PERECEDERO' => 2, 'ROPA' => 30];

    public function up(): void
    {
        Schema::create('novedades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->unsignedInteger('numero');                       // consecutivo por empresa: #00012
            $table->string('categoria', 20)->default('sin_clasificar');
            $table->string('estatus', 20)->default('abierto');       // abierto | pendiente_turno | resuelto
            $table->string('reportado_por', 150);                    // ¿Quién reporta? (texto libre o colaborador)
            $table->foreignId('reportado_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('asignado_a')->nullable()->constrained('users')->nullOnDelete(); // ¿A quién se canaliza?
            $table->foreignId('area_id')->nullable()->constrained('espacios')->nullOnDelete();  // Área General: edificio o piso
            $table->foreignId('area_especifica_id')->nullable()->constrained('espacios')->nullOnDelete(); // habitación
            $table->string('ubicacion', 255);                        // Ubicación específica
            $table->string('involucrados', 255)->nullable();
            $table->dateTime('ocurrio_en')->nullable();              // ¿Cuándo sucedió? (UTC)
            $table->text('descripcion');                             // ¿Qué sucedió?
            $table->text('como_sucedio')->nullable();
            $table->text('resolucion')->nullable();
            $table->dateTime('cerrado_en')->nullable();
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('origen_novedad_id')->nullable()->constrained('novedades')->nullOnDelete(); // p. ej. siniestro → accidente
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'sede_id', 'estatus']);
            $table->index(['empresa_id', 'categoria']);
            $table->index(['area_especifica_id', 'created_at']);
            $table->index(['area_id', 'created_at']);
        });

        Schema::create('novedad_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
            $table->string('tipo', 20)->default('nota');             // nota | reapertura | sistema
            $table->string('autor_nombre', 150);                     // como se llamaba al escribirla
            $table->text('texto');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['novedad_id', 'id']);
        });

        Schema::create('novedad_testigos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
            $table->string('formato', 20);                           // accidente | proteccion_civil | robo
            $table->string('nombre', 150);
            $table->string('departamento', 100)->nullable();         // o procedencia (huésped, externo…)
            $table->text('declaracion')->nullable();                 // solo Robo
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['novedad_id', 'formato']);
        });

        Schema::create('novedad_reportes_generales', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->string('observados', 255)->nullable();
            $table->string('actividad', 255)->nullable();
            $table->string('motivo', 255)->nullable();
            $table->text('acciones_inmediatas')->nullable();
            $this->autor($table);
        });

        // ------------------------------------------------------------ Accidente
        Schema::create('accidente_huespedes', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->date('fecha_accidente')->nullable();
            $table->time('hora_accidente')->nullable();
            $table->string('nombre', 150)->nullable();
            $table->string('num_habitacion', 20)->nullable();
            $table->string('agencia', 150)->nullable();
            $table->date('fecha_check_in')->nullable();
            $table->date('fecha_check_out')->nullable();
            $table->string('pais', 100)->nullable();
            $table->char('sexo', 1)->nullable();                     // M | F
            $table->unsignedSmallInteger('edad')->nullable();
            $table->string('lugar', 255)->nullable();
            $table->text('explicacion')->nullable();
            $table->boolean('requiere_asistencia_medica')->default(true);
            $table->string('motivo_asistencia', 255)->nullable();
            $table->boolean('hubo_testigos')->default(false);
            $table->string('detalles_testigos', 255)->nullable();
            $this->autor($table);
        });

        Schema::create('accidente_colaboradores', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->date('fecha_accidente')->nullable();
            $table->time('hora_accidente')->nullable();
            $table->string('departamento', 100)->nullable();
            $table->string('puesto', 100)->nullable();
            $table->string('turno', 50)->nullable();
            $table->string('area_trabajo', 150)->nullable();
            $table->string('jefe_inmediato', 150)->nullable();
            $table->string('puesto_jefe', 150)->nullable();
            $table->boolean('primera_vez')->default(true);
            $table->boolean('causa_terceras_personas')->default(false);
            $table->boolean('causa_acto_inseguro')->default(false);
            $table->boolean('causa_condicion_insegura')->default(false);
            $table->text('explicacion_causas')->nullable();
            $table->string('aviso_dado_por', 150)->nullable();
            $table->string('depto_aviso', 100)->nullable();
            $table->text('actividades_cotidianas')->nullable();
            $table->boolean('mismas_actividades')->default(true);
            $this->autor($table);
        });

        Schema::create('accidente_dictamenes', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->json('tipos_herida')->nullable();                // ["Lacerante", "Contusa"…]
            $table->json('zonas_cuerpo')->nullable();                // ["Pie Der", "Pecho"…]
            $table->boolean('primeros_auxilios')->default(true);
            $table->string('cuales_auxilios', 255)->nullable();
            $table->boolean('atencion_medica')->default(true);
            $table->string('cuales_atencion', 255)->nullable();
            $table->text('diagnostico')->nullable();
            $table->boolean('hospitalizacion')->default(true);
            $table->string('nombre_hospital', 255)->nullable();
            $table->string('trasladado_en', 150)->nullable();
            $table->string('nombre_medico', 150)->nullable();
            $table->text('observaciones')->nullable();
            $this->autor($table);
        });

        Schema::create('accidente_guardavidas', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->date('fecha')->nullable();
            $table->time('hora')->nullable();
            $table->string('turno', 50)->nullable();
            $table->string('lugar', 150)->nullable();
            $table->string('nombre', 150);
            $table->string('puesto', 100)->nullable();
            $table->string('supervisor', 150)->nullable();
            $table->boolean('alcoholizado')->default(false);
            $table->boolean('descalzo')->default(false);
            $table->string('tipo_calzado', 100)->nullable();
            $table->string('tipo_herida', 150)->nullable();
            $table->string('parte_afectada', 150)->nullable();
            $table->boolean('acto_inseguro')->default(false);
            $table->boolean('condicion_insegura')->default(false);
            $table->text('especifique_riesgo')->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('acudio_servicio_medico')->default(false);
            $table->string('material_curacion', 255)->nullable();
            $table->string('se_informa_a', 150)->nullable();
            $this->autor($table);
        });

        Schema::create('accidente_incapacidades', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->unsignedSmallInteger('dias_incapacidad')->default(0);
            $table->date('fecha_presenta')->nullable();
            $this->autor($table);
        });

        Schema::create('accidente_firmas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
            $table->string('rol', 15);                               // afectado | seguridad | medico | jefe | rh | ejecutivo
            $table->string('ruta', 255);                             // disco privado (App\Services\Firmas\Firmas)
            $this->autor($table);
            $table->unique(['novedad_id', 'rol']);
        });

        // ---------------------------------------------------- Valores a la Vista
        Schema::create('valores_vista_detalles', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->foreignId('area_especifica_id')->nullable()->constrained('espacios')->nullOnDelete();
            $table->string('num_habitacion', 30)->nullable();        // si no está en el catálogo
            $table->string('quien_reporta', 150)->nullable();
            $table->string('depto_reporta', 100)->nullable();
            $table->string('puesto_reporta', 100)->nullable();
            $table->string('actividad_reporta', 255)->nullable();
            $table->string('quien_atiende', 150)->nullable();
            $table->string('depto_atiende', 100)->nullable();
            $table->string('puesto_atiende', 100)->nullable();
            $table->string('caja_fuerte', 25)->default('cerrada');   // cerrada | abierta_sin_valores | abierta_con_valores
            $table->string('caja_accion', 40)->nullable();
            $table->text('valores_dentro')->nullable();
            $table->string('personal_retiro', 2)->nullable();        // si | no
            $table->boolean('cliente_llega')->nullable();
            $table->string('tomo_fotos', 2)->nullable();             // si | no
            $table->text('observaciones')->nullable();
            $this->autor($table);
            $table->index(['area_especifica_id']);
        });

        Schema::create('valores_vista_personas', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('nombre', 150);
            $table->string('departamento', 100)->nullable();
            $table->string('puesto', 100)->nullable();
            $table->string('actividad', 255)->nullable();
            $table->boolean('se_retira')->default(true);
            $this->autor($table);
        });

        Schema::create('valores_vista_aperturas', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('tipo', 10);                              // puerta | ventana | terraza
            $table->string('estado', 10)->default('cerrada');        // abierta | cerrada
            $table->boolean('es_especial')->default(false);          // puerta de conexión / de baño
            $table->string('descripcion', 150)->nullable();
            $this->autor($table);
        });

        Schema::create('valores_vista_zonas', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('zona', 20);                              // Recámara, Sala, Baño, Terraza, Cocina, Otro
            $table->text('descripcion');
            $this->autor($table);
        });

        // -------------------------------------------------- Siniestro Protección Civil
        Schema::create('siniestro_detalles', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->string('tipo_evento', 30);
            $table->string('descripcion_otro', 150)->nullable();
            $table->dateTime('controlado_en')->nullable();           // UTC
            $table->boolean('alarma_activada')->default(false);
            $table->boolean('requiere_evacuacion')->default(false);
            $table->unsignedInteger('num_evacuados')->nullable();
            $table->string('punto_reunion', 150)->nullable();
            $table->boolean('hubo_lesionados')->default(false);
            $table->unsignedInteger('num_lesionados')->nullable();
            $table->foreignId('accidente_novedad_id')->nullable()->constrained('novedades')->nullOnDelete(); // ticket de Accidente generado (una vez)
            $table->text('causa_probable')->nullable();
            $table->text('acciones_tomadas')->nullable();
            $this->autor($table);
        });

        Schema::create('siniestro_servicios', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('servicio', 50);
            $table->time('hora_llegada')->nullable();
            $this->autor($table);
        });

        Schema::create('siniestro_equipos', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('identificador', 100);                    // número de serie / ID (el catálogo PC llega con Recorridos PC)
            $table->string('estado_uso', 10)->default('utilizado');  // utilizado | danado
            $this->autor($table);
        });

        Schema::create('siniestro_danos', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('zona', 100);
            $table->text('descripcion')->nullable();
            $this->autor($table);
        });

        // ------------------------------------------------ Recorrido PC (histórico)
        Schema::create('novedad_recorrido_puntos', function (Blueprint $table) {
            $table->id();
            $this->cabeceraLista($table);
            $table->string('identificador', 100);
            $table->string('categoria', 30);
            $table->string('edificio', 100)->nullable();
            $table->string('nivel', 50)->nullable();
            $table->string('area', 150)->nullable();
            $table->json('criterios')->nullable();                   // {"cilindro": true, "manometro": false…}
            $table->text('observaciones')->nullable();
            $this->autor($table);
        });

        // ---------------------------------------------------------- Lost & Found
        Schema::create('lost_found_detalles', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->string('folio_externo', 100)->nullable();
            $table->string('enlace_externo', 255)->nullable();
            $this->autor($table);
        });

        Schema::create('lost_found_articulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
            $table->unsignedInteger('numero');                       // consecutivo por empresa
            $table->string('folio', 20);                             // LF-000123
            $table->string('objeto', 150);
            $table->string('tipo_valor', 20)->default('OTRO');       // ALTO_VALOR | ELECTRONICO | ROPA | PERECEDERO | OTRO
            $table->string('marca', 100)->nullable();
            $table->string('color', 50)->nullable();
            $table->foreignId('area_especifica_id')->nullable()->constrained('espacios')->nullOnDelete();
            $table->string('lugar_detalle', 150)->nullable();        // "junto a la alberca chica"
            $table->string('ubicacion_bodega', 100)->nullable();
            $table->string('estatus', 25)->default('EN_RESGUARDO');  // EN_RESGUARDO | DEVUELTO | DONADO | DESTRUIDO | ENTREGADO_BENEFICENCIA
            $table->dateTime('cerrado_en')->nullable();
            $this->autor($table);
            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'folio']);
            $table->index(['empresa_id', 'sede_id', 'estatus']);
            $table->index(['area_especifica_id', 'created_at']);
        });

        Schema::create('lost_found_reportes_perdida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes');
            $table->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->string('folio', 20);                             // RP-000123
            $table->string('objeto', 150);
            $table->string('tipo_valor', 20)->default('OTRO');
            $table->string('marca', 100)->nullable();
            $table->string('color', 50)->nullable();
            $table->text('descripcion')->nullable();                 // señas particulares
            $table->string('nombre_huesped', 150)->nullable();
            $table->foreignId('area_especifica_id')->nullable()->constrained('espacios')->nullOnDelete();
            $table->date('fecha_aproximada')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('estatus', 25)->default('BUSCANDO');      // BUSCANDO | VINCULADO | CERRADO_SIN_HALLAZGO
            $table->foreignId('articulo_vinculado_id')->nullable()->constrained('lost_found_articulos')->nullOnDelete();
            $table->dateTime('vinculado_en')->nullable();
            $table->foreignId('vinculado_por')->nullable()->constrained('users')->nullOnDelete();
            $this->autor($table);
            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'folio']);
            $table->index(['empresa_id', 'sede_id', 'estatus']);
            $table->index(['area_especifica_id', 'created_at']);
        });

        Schema::create('lost_found_umbrales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo_valor', 20);
            $table->unsignedSmallInteger('dias');
            $this->autor($table);
            $table->unique(['empresa_id', 'tipo_valor']);
        });

        Schema::create('lost_found_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('articulo_id')->constrained('lost_found_articulos')->cascadeOnDelete();
            $table->string('tipo_cierre', 20);                       // PERSONA | PAQUETERIA | DONADO | DESTRUIDO | BENEFICENCIA
            $table->string('nombre_recibe', 150)->nullable();
            $table->string('tipo_identificacion', 50)->nullable();
            $table->string('correo_recibe', 150)->nullable();
            $table->string('paqueteria', 50)->nullable();
            $table->string('numero_guia', 100)->nullable();
            $table->string('firma_ruta', 255)->nullable();           // disco privado
            $table->text('observaciones')->nullable();
            $this->autor($table);
            $table->index('articulo_id');
        });

        // ------------------------------------------------------------------ Robo
        Schema::create('robo_detalles', function (Blueprint $table) {
            $table->id();
            $this->cabeceraDetalle($table);
            $table->time('hora_aproximada')->nullable();
            $table->string('lugar_exacto', 150)->nullable();
            $table->text('objetos_descripcion')->nullable();
            $table->decimal('valor_estimado', 12, 2)->nullable();
            $table->boolean('hay_sospechoso')->default(false);
            $table->text('descripcion_sospechoso')->nullable();
            $table->boolean('parte_policia')->default(false);
            $table->string('folio_policial', 100)->nullable();
            $table->boolean('canalizado_gerencia')->default(false);
            $table->boolean('canalizado_legal')->default(false);
            $table->text('observaciones')->nullable();
            $table->foreignId('articulo_vinculado_id')->nullable()->constrained('lost_found_articulos')->nullOnDelete();
            $this->autor($table);
        });

        // Días de resguardo por omisión para las empresas que ya existen (las
        // nuevas usan los mismos valores hasta que los configuren).
        $ahora = now();
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            foreach (self::UMBRALES as $tipo => $dias) {
                DB::table('lost_found_umbrales')->insert(['empresa_id' => $empresaId, 'tipo_valor' => $tipo, 'dias' => $dias, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'robo_detalles', 'lost_found_entregas', 'lost_found_umbrales', 'lost_found_reportes_perdida', 'lost_found_articulos', 'lost_found_detalles',
            'novedad_recorrido_puntos', 'siniestro_danos', 'siniestro_equipos', 'siniestro_servicios', 'siniestro_detalles',
            'valores_vista_zonas', 'valores_vista_aperturas', 'valores_vista_personas', 'valores_vista_detalles',
            'accidente_firmas', 'accidente_incapacidades', 'accidente_guardavidas', 'accidente_dictamenes', 'accidente_colaboradores', 'accidente_huespedes',
            'novedad_reportes_generales', 'novedad_testigos', 'novedad_notas', 'novedades',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    /** Detalle 1:1 de la novedad. */
    private function cabeceraDetalle(Blueprint $table): void
    {
        $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignId('novedad_id')->unique()->constrained('novedades')->cascadeOnDelete();
    }

    /** Fila de una lista de la novedad (personas, testigos, equipos…). */
    private function cabeceraLista(Blueprint $table): void
    {
        $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignId('novedad_id')->constrained('novedades')->cascadeOnDelete();
        $table->index('novedad_id');
    }

    private function autor(Blueprint $table): void
    {
        $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }
};
