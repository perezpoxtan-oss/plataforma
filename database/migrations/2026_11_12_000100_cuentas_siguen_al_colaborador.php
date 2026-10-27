<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan de correcciones, C-11 (DD-22, A-06): la cuenta vinculada sigue al
 * colaborador. Una sola vez, a cada cuenta vinculada se le vuelve a copiar
 * el nombre completo y el número de su colaborador (el número solo si ninguna
 * otra cuenta de la empresa lo usa). De aquí en adelante lo hace
 * AdministradorColaboradores::sincronizarCuenta() al editar, verificar, unir
 * duplicados o importar.
 *
 * Solo datos, sin columnas nuevas. down(): sin efecto (los nombres y números
 * anteriores eran copias viejas del colaborador; no se pueden ni deben
 * restaurar).
 */
return new class extends Migration
{
    public function up(): void
    {
        $filas = DB::table('users')->join('colaboradores', 'colaboradores.id', '=', 'users.colaborador_id')
            ->whereNotNull('users.colaborador_id')
            // Solo cuentas de la misma empresa que su colaborador; un Super Administrador nunca se renombra desde una empresa
            ->whereColumn('users.empresa_id', 'colaboradores.empresa_id')->where('users.es_superadmin', false)
            ->get(['users.id', 'users.empresa_id', 'users.name', 'users.numero_colaborador',
                'colaboradores.nombre', 'colaboradores.apellido_paterno', 'colaboradores.apellido_materno', 'colaboradores.num_empleado']);

        foreach ($filas as $f) {
            $nombre = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $f->nombre.' '.$f->apellido_paterno.' '.($f->apellido_materno ?? ''))), 0, 255);
            $cambios = [];
            if ($nombre !== '' && $nombre !== $f->name) {
                $cambios['name'] = $nombre;
            }
            $numero = $f->num_empleado;
            if ($numero !== null && $numero !== '' && $numero !== $f->numero_colaborador
                && ! DB::table('users')->where('empresa_id', $f->empresa_id)->where('numero_colaborador', $numero)->where('id', '!=', $f->id)->exists()) {
                $cambios['numero_colaborador'] = $numero;
            }
            if ($cambios !== []) {
                DB::table('users')->where('id', $f->id)->update($cambios);
            }
        }
    }

    public function down(): void
    {
        // Sin efecto: ver el comentario de la clase.
    }
};
