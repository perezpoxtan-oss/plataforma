<?php

use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión 10 del dueño (3 nov): conectores de equipos de acceso ZKTeco y
 * Hikvision (docs/tecnico/equipos-acceso.md, ADR-0025). La plataforma solo
 * RECIBE llamadas: el equipo pregunta y empuja sus marcajes.
 *
 *  - `equipos_acceso`: un equipo por sede (marca, modelo, número de serie,
 *    puerta, sentido), con el resumen de su token (nunca el token) y su
 *    estado (último contacto y último marcaje).
 *  - `equipos_acceso_comandos`: la cola de altas y bajas de usuarios que el
 *    equipo ZKTeco recoge al preguntar (sin cron).
 *  - `equipos_acceso_marcajes`: cada marcaje recibido una sola vez
 *    (idempotencia: `unique(equipo_acceso_id, clave)`), con lo que se hizo
 *    con él. El número de tarjeta se guarda enmascarado.
 *  - `accesos.equipo_entrada_id` / `equipo_salida_id`: el equipo que
 *    registró la entrada o la salida («Equipo: <nombre>» en la bitácora).
 *
 * down(): quita las columnas de `accesos` y las tres tablas. Las entradas y
 * salidas que registraron los equipos se conservan (quedan «por —»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos_acceso', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('nombre', 80);
            $table->string('marca', 20);                         // zkteco | hikvision
            $table->string('modelo', 60)->nullable();
            $table->string('numero_serie', 60);                  // ZKTeco: SN con el que se identifica
            $table->string('puerta', 80)->nullable();
            $table->string('sentido', 10)->default('ambos');      // entrada | salida | ambos
            $table->string('token_hash', 64)->unique();           // sha256 del token (Hikvision lo manda en la dirección)
            $table->string('token_pista', 8);                     // últimos 4 caracteres, para reconocerlo
            $table->string('ips_permitidas', 255)->nullable();    // opcional: IP públicas desde las que puede llamar
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_contacto_at')->nullable();
            $table->string('ultima_ip', 45)->nullable();
            $table->timestamp('ultimo_marcaje_at')->nullable();
            $table->string('version_equipo', 120)->nullable();    // lo que el equipo dice de sí (firmware)
            $table->string('estampa_marcajes', 20)->nullable();   // ZKTeco: «Stamp» del último paquete de marcajes
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['marca', 'numero_serie']);
            $table->index(['empresa_id', 'sede_id', 'activo']);
        });

        Schema::create('equipos_acceso_comandos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('equipo_acceso_id')->constrained('equipos_acceso')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('tipo', 20);                           // alta | baja
            $table->string('pin', 30);                            // número de empleado en el equipo
            $table->text('comando');
            $table->string('estado', 12)->default('pendiente');   // pendiente | enviado | hecho | error
            $table->integer('resultado')->nullable();             // Return del equipo (0 = bien)
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('respondido_at')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['equipo_acceso_id', 'estado', 'id']);
            $table->index(['equipo_acceso_id', 'pin', 'estado']);
        });

        Schema::create('equipos_acceso_marcajes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('equipo_acceso_id')->constrained('equipos_acceso')->cascadeOnDelete();
            $table->string('clave', 64);                          // huella del marcaje (sha256) para no repetirlo
            $table->timestamp('marcado_at')->nullable();
            $table->string('identificador', 30)->nullable();      // PIN o tarjeta, enmascarado
            $table->string('sentido', 10)->nullable();            // entrada | salida
            $table->string('resultado', 30);                      // entrada | salida | desconocido | no_vigente | otra_sede | sin_entrada | ignorado
            $table->string('detalle', 255)->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('acceso_id')->nullable()->constrained('accesos')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['equipo_acceso_id', 'clave']);
            $table->index(['empresa_id', 'equipo_acceso_id', 'id']);
            $table->index(['empresa_id', 'created_at']);
        });

        Schema::table('accesos', function (Blueprint $table) {
            $table->foreignId('equipo_entrada_id')->nullable()->after('sede_id');
            $table->foreignId('equipo_salida_id')->nullable()->after('equipo_entrada_id');
        });
        // Las llaves aparte: en SQLite sin reconstruir accesos (conserva sus CHECK y sus hijos restrict; docs/tecnico/integridad-bd.md)
        Restricciones::agregarForanea('accesos', 'equipo_entrada_id', 'equipos_acceso', 'set null');
        Restricciones::agregarForanea('accesos', 'equipo_salida_id', 'equipos_acceso', 'set null');
    }

    public function down(): void
    {
        Restricciones::quitarForanea('accesos', 'equipo_salida_id');
        Restricciones::quitarForanea('accesos', 'equipo_entrada_id');
        Schema::table('accesos', function (Blueprint $table) {
            $table->dropColumn(['equipo_entrada_id', 'equipo_salida_id']);
        });
        Schema::dropIfExists('equipos_acceso_marcajes');
        Schema::dropIfExists('equipos_acceso_comandos');
        Schema::dropIfExists('equipos_acceso');
    }
};
