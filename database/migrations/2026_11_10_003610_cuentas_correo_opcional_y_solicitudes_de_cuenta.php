<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta es de su dueño (C-36, DD-21; docs/tecnico/usuarios.md y dos-pasos.md).
 *
 *  - users.email pasa a ser OPCIONAL: las cuentas de caseta (sin permisos de
 *    administración) pueden no tener correo. Sigue siendo único cuando existe
 *    (varios NULL no chocan en el índice único, ni en MariaDB ni en SQLite).
 *  - solicitudes_cuenta: «Pedir a mi administrador que restablezca mi
 *    contraseña o mis dos pasos». Cae en «Cuentas por revisar» de quien
 *    administra esa cuenta; las del Super Administrador (empresa nula), en
 *    las de los otros Super Administradores.
 *
 * down(): borra la tabla y vuelve el correo obligatorio. Antes, a las cuentas
 * sin correo les pone uno de marcador único (sin-correo-{id}@cuenta.invalid,
 * dominio reservado que nunca recibe correo) para no perder ninguna cuenta.
 */
return new class extends Migration
{
    public const MARCADOR = '@cuenta.invalid';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
        // Las cuentas que vienen de un down() anterior recuperan su «sin correo»
        DB::table('users')->where('email', 'like', 'sin-correo-%'.self::MARCADOR)->update(['email' => null]);

        Schema::create('solicitudes_cuenta', function (Blueprint $table) {
            $table->id();
            // Nula = cuenta de Super Administrador: la atiende otro Super Administrador
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 15);                       // contrasena | dos_pasos
            $table->string('estado', 15)->default('pendiente'); // pendiente | atendida | rechazada
            $table->string('origen', 45)->nullable();         // red recortada desde donde se pidió (sin el último bloque)
            $table->timestamp('atendida_en')->nullable();
            $table->foreignId('atendida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_rechazo', 255)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cuenta');

        foreach (DB::table('users')->whereNull('email')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['email' => 'sin-correo-'.$id.self::MARCADOR]);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
