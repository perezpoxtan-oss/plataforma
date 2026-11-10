<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedor y contratista esperan la autorización del departamento.
 *
 *  - accesos.motivo_excepcion: cuando la caseta autoriza por excepción
 *    (sin responsable, sin respuesta a tiempo, autorizó por teléfono,
 *    urgencia u otro), el motivo que se ve en la tarjeta y el historial.
 *  - Ajuste «Quién espera la autorización del departamento»: por omisión
 *    ahora las tres casillas (visita, proveedor y contratista) van marcadas.
 *    Las empresas que ya existían conservan su valor de visitas: si no lo
 *    tenían guardado (equivalía a «no»), se guarda «no» explícito. Proveedor
 *    y contratista quedan marcados (sin dato = sí).
 *
 * down(): quita la columna y el «no» explícito de visitas (sin el dato, la
 * versión anterior lo lee como «no»: mismo comportamiento). Las casillas de
 * proveedor y contratista se quedan guardadas (la versión anterior no las lee).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accesos', 'motivo_excepcion')) {
            Schema::table('accesos', function (Blueprint $table) {
                $table->string('motivo_excepcion', 300)->nullable()->after('autorizacion');
            });
        }

        $this->cadaEmpresa(function (array $recepcion) {
            if (! array_key_exists('visitas_requieren_autorizacion', $recepcion)) {
                $recepcion['visitas_requieren_autorizacion'] = false;
            }

            return $recepcion;
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('accesos', 'motivo_excepcion')) {
            Schema::table('accesos', function (Blueprint $table) {
                $table->dropColumn('motivo_excepcion');
            });
        }

        $this->cadaEmpresa(function (array $recepcion) {
            if (($recepcion['visitas_requieren_autorizacion'] ?? null) === false) {
                unset($recepcion['visitas_requieren_autorizacion']);
            }
            // Las casillas de proveedor y contratista se quedan: la versión anterior no las lee

            return $recepcion;
        });
    }

    /** Aplica $cambio a preferencias['recepcion'] de cada empresa (solo guarda si cambió). */
    private function cadaEmpresa(Closure $cambio): void
    {
        foreach (DB::table('empresas')->orderBy('id')->get(['id', 'preferencias']) as $e) {
            $preferencias = json_decode((string) ($e->preferencias ?? ''), true);
            $preferencias = is_array($preferencias) ? $preferencias : [];
            $antes = is_array($preferencias['recepcion'] ?? null) ? $preferencias['recepcion'] : [];
            $despues = $cambio($antes);
            if ($despues === $antes) {
                continue;
            }
            $preferencias['recepcion'] = $despues;
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($preferencias, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
