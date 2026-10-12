<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisión del dueño (mapa de procesos): «Vouchers de reposición» pasa al
 * menú Operación, sección Caseta, justo después de «Préstamo de llaves».
 * Mismo permiso y misma ruta: solo cambia su lugar en el menú.
 *
 * Solo mueve el módulo si sigue donde lo dejó la plataforma (Operación →
 * Activos): si alguien ya lo acomodó en otro lugar desde la interfaz, se
 * respeta. Se puede correr más de una vez sin efectos dobles. En una
 * instalación nueva el MenuSeeder ya lo acomoda así.
 */
return new class extends Migration
{
    private const MODULO = 'vouchers';

    private const DESPUES_DE = 'prestamo_llaves';

    private const SECCION_NUEVA = 'Caseta';

    private const SECCION_ANTERIOR = 'Activos';

    /** Con quién iba antes (para regresarlo después de él). */
    private const ANTES_IBA_DESPUES_DE = 'responsivas';

    public function up(): void
    {
        $menu = DB::table('menus')->where('clave', 'operacion')->value('id');
        $voucher = DB::table('modulos')->where('clave', self::MODULO)->first(['id', 'menu_id', 'seccion_menu', 'orden_menu']);
        $ancla = DB::table('modulos')->where('clave', self::DESPUES_DE)->first(['menu_id', 'seccion_menu', 'orden_menu']);
        if ($menu === null || $voucher === null || $ancla === null
            || (int) $voucher->menu_id !== (int) $menu || $voucher->seccion_menu !== self::SECCION_ANTERIOR
            || (int) $ancla->menu_id !== (int) $menu) {
            return;
        }

        $this->mover((int) $menu, $voucher, $ancla->seccion_menu ?? self::SECCION_NUEVA, (int) $ancla->orden_menu + 1);
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('clave', 'operacion')->value('id');
        $voucher = DB::table('modulos')->where('clave', self::MODULO)->first(['id', 'menu_id', 'seccion_menu', 'orden_menu']);
        $ancla = DB::table('modulos')->where('clave', self::ANTES_IBA_DESPUES_DE)->first(['menu_id', 'orden_menu']);
        if ($menu === null || $voucher === null || $ancla === null
            || (int) $voucher->menu_id !== (int) $menu || $voucher->seccion_menu !== self::SECCION_NUEVA
            || (int) $ancla->menu_id !== (int) $menu) {
            return;
        }

        $this->mover((int) $menu, $voucher, self::SECCION_ANTERIOR, (int) $ancla->orden_menu + 1);
    }

    /**
     * Saca el módulo de su lugar (lo de detrás sube) y lo pone en $orden de
     * $seccion (lo de detrás baja), dentro del mismo menú. $orden se da con
     * la numeración de antes de sacarlo.
     */
    private function mover(int $menu, object $voucher, string $seccion, int $orden): void
    {
        DB::transaction(function () use ($menu, $voucher, $seccion, $orden) {
            $ahora = now();
            DB::table('modulos')->where('menu_id', $menu)->where('id', '!=', $voucher->id)->where('orden_menu', '>', (int) $voucher->orden_menu)
                ->update(['orden_menu' => DB::raw('orden_menu - 1'), 'updated_at' => $ahora]);
            // La posición se calcula ya con el módulo fuera
            $orden = $orden > (int) $voucher->orden_menu ? $orden - 1 : $orden;
            DB::table('modulos')->where('menu_id', $menu)->where('id', '!=', $voucher->id)->where('orden_menu', '>=', $orden)
                ->update(['orden_menu' => DB::raw('orden_menu + 1'), 'updated_at' => $ahora]);
            DB::table('modulos')->where('id', $voucher->id)
                ->update(['seccion_menu' => $seccion, 'orden_menu' => $orden, 'updated_at' => $ahora]);
        });
    }
};
