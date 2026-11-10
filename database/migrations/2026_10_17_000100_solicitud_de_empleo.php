<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitud de empleo formal (formato general de México) en la ficha del
 * candidato y en el kiosco: datos personales oficiales, domicilio, contacto
 * de emergencia, referencias laborales, datos generales, declaración y firma.
 *
 * Todas las columnas son opcionales: los candidatos que ya existen siguen
 * funcionando igual. nombre_completo se conserva (se arma con nombre y
 * apellidos cuando se capturan por separado).
 *
 * Escolaridad y empleos anteriores siguen en sus columnas JSON (se agregan
 * campos a cada renglón: periodo, documento obtenido, fechas, sueldo, jefe…).
 * No hay datos de salud, religión, política ni similares (decisión legal y ética).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            // Datos personales
            $table->string('nombre', 60)->nullable()->after('nombre_completo');
            $table->string('apellido_paterno', 60)->nullable()->after('nombre');
            $table->string('apellido_materno', 60)->nullable()->after('apellido_paterno');
            $table->string('sexo', 12)->nullable()->after('apellido_materno');
            $table->string('lugar_nacimiento', 40)->nullable()->after('fecha_nacimiento');
            $table->string('nacionalidad', 40)->nullable()->after('lugar_nacimiento');
            $table->string('estado_civil', 20)->nullable()->after('nacionalidad');
            $table->unsignedTinyInteger('dependientes')->nullable()->after('estado_civil');
            // Identificadores oficiales (dato personal sensible: nunca en auditoría, resúmenes ni avisos)
            $table->string('curp', 18)->nullable()->after('dependientes');
            $table->string('rfc', 13)->nullable()->after('curp');
            $table->string('nss', 11)->nullable()->after('rfc');
            $table->string('licencia_tipo', 20)->nullable()->after('nss');
            $table->date('licencia_vigencia')->nullable()->after('licencia_tipo');
            // Domicilio
            $table->string('calle_numero', 150)->nullable()->after('ciudad');
            $table->string('colonia', 100)->nullable()->after('calle_numero');
            $table->string('codigo_postal', 5)->nullable()->after('colonia');
            $table->string('municipio', 100)->nullable()->after('codigo_postal');
            $table->string('estado_domicilio', 40)->nullable()->after('municipio');
            $table->string('tiempo_residencia', 40)->nullable()->after('estado_domicilio');
            $table->string('telefono_fijo', 15)->nullable()->after('tiempo_residencia');
            // Contacto de emergencia
            $table->string('emergencia_nombre', 150)->nullable()->after('telefono_fijo');
            $table->string('emergencia_parentesco', 40)->nullable()->after('emergencia_nombre');
            $table->string('emergencia_telefono', 15)->nullable()->after('emergencia_parentesco');
            // Referencias laborales (las personales siguen en «referencias»)
            $table->json('referencias_laborales')->nullable()->after('referencias');
            // Datos generales
            $table->string('medio_vacante', 20)->nullable()->after('referencias_laborales');
            $table->boolean('tiene_familiares')->nullable()->after('medio_vacante');
            $table->string('familiares_nombre', 150)->nullable()->after('tiene_familiares');
            $table->boolean('trabajo_antes_aqui')->nullable()->after('familiares_nombre');
            $table->boolean('rolar_turnos')->nullable()->after('trabajo_antes_aqui');
            $table->boolean('puede_viajar')->nullable()->after('rolar_turnos');
            $table->boolean('cambiar_residencia')->nullable()->after('puede_viajar');
            $table->date('fecha_inicio_posible')->nullable()->after('cambiar_residencia');
            // Declaración y firma (la firma vive en el disco privado)
            $table->timestamp('declaracion_aceptada_en')->nullable()->after('privacidad_medio');
            $table->string('firma_ruta', 255)->nullable()->after('declaracion_aceptada_en');
            $table->timestamp('firma_en')->nullable()->after('firma_ruta');
            $table->string('firma_medio', 12)->nullable()->after('firma_en'); // kiosco | web | rh
            $table->unsignedBigInteger('firma_capturada_por')->nullable()->after('firma_medio'); // RR. HH. que capturó por el candidato
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn([
                'nombre', 'apellido_paterno', 'apellido_materno', 'sexo', 'lugar_nacimiento', 'nacionalidad', 'estado_civil', 'dependientes',
                'curp', 'rfc', 'nss', 'licencia_tipo', 'licencia_vigencia',
                'calle_numero', 'colonia', 'codigo_postal', 'municipio', 'estado_domicilio', 'tiempo_residencia', 'telefono_fijo',
                'emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono', 'referencias_laborales',
                'medio_vacante', 'tiene_familiares', 'familiares_nombre', 'trabajo_antes_aqui', 'rolar_turnos', 'puede_viajar', 'cambiar_residencia',
                'fecha_inicio_posible', 'declaracion_aceptada_en', 'firma_ruta', 'firma_en', 'firma_medio', 'firma_capturada_por',
            ]);
        });
    }
};
