<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El rol de sistema «Asistente» pasa a llamarse «Asistente de seguridad»
 * (decisión del dueño del proyecto: hay muchos puestos «asistente» en otras
 * áreas y este rol es de seguridad). Mismos permisos: solo cambia el nombre.
 *
 * Se renombran la plantilla y el rol de cada empresa SOLO si conservan el
 * nombre original exacto «Asistente»; los que el cliente ya renombró no se
 * tocan. Si en esa empresa ya existe un rol «Asistente de seguridad», tampoco
 * (el nombre es único por empresa).
 *
 * down(): regresa a «Asistente» los que se llaman exactamente «Asistente de
 * seguridad», con la misma salvedad.
 *
 * Los nombres van escritos aquí (no desde el seeder) para que la migración no
 * cambie si la plantilla cambia después.
 */
return new class extends Migration
{
    private const ANTERIOR = 'Asistente';

    private const NUEVO = 'Asistente de seguridad';

    public function up(): void
    {
        $this->renombrar(self::ANTERIOR, self::NUEVO);
    }

    public function down(): void
    {
        $this->renombrar(self::NUEVO, self::ANTERIOR);
    }

    private function renombrar(string $de, string $a): void
    {
        // La comparación de MariaDB no distingue mayúsculas ni acentos: el
        // nombre exacto se confirma aquí («asistente» o «ASISTENTE» no se tocan).
        $roles = DB::table('roles')->where('nombre', $de)->get(['id', 'empresa_id', 'nombre'])
            ->filter(fn ($r) => $r->nombre === $de);

        foreach ($roles as $rol) {
            $ocupado = DB::table('roles')
                ->where('nombre', $a)
                ->when($rol->empresa_id === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $rol->empresa_id))
                ->exists();
            if ($ocupado) {
                continue;
            }

            DB::table('roles')->where('id', $rol->id)->where('nombre', $de)->update(['nombre' => $a, 'updated_at' => now()]);
        }
    }
};
