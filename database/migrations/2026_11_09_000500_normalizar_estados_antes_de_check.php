<?php

use App\Models\ConfiguracionPlataforma;
use App\Support\BaseDatos\CatalogoChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md, «CHECK»): antes de
 * proteger los estados, etapas y categorías con un CHECK, pasa a su valor
 * vigente lo que se escribió distinto (valores de SEGCAT, etapas de antes de
 * la fase 2, mayúsculas, espacios). Lo que no tenga un equivalente claro NO
 * se toca: se informa en el log y en configuracion_plataforma
 * («restricciones_check»), y la migración siguiente no pone el CHECK de esa
 * columna (la revisión de coherencia lo sigue reportando).
 *
 * down(): no regresa los valores viejos (eran sinónimos del vigente y la
 * versión anterior del código lee el vigente igual); solo borra el informe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $r = CatalogoChecks::normalizar();
        if ($r['normalizados'] !== [] || $r['sin_reconocer'] !== []) {
            Log::warning('Mantenimiento de la base: estados normalizados antes de los CHECK', $r);
        }
        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => 'restricciones_check']);
        $registro->valor = ['normalizado_en' => now()->toIso8601String()] + $r;
        $registro->save();
    }

    public function down(): void
    {
        ConfiguracionPlataforma::where('clave', 'restricciones_check')->delete();
    }
};
