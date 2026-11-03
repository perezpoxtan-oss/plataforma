<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión del dueño (3 nov) sobre la tecnología de la credencial: de
 * fábrica solo QR; cada empresa puede habilitar además la tarjeta de
 * proximidad 125 kHz, la MIFARE 13.56 MHz o las dos.
 *
 *  - credenciales.tipo_tarjeta: qué tarjeta lleva el plástico entregado
 *    (proximidad_125 | mifare_1356; vacío = sin tarjeta o anterior a esta
 *    decisión). La ficha dice con ella qué lector la lee.
 *  - empresas.preferencias['credenciales']: el ajuste anterior
 *    «tecnologia = qr_tarjeta» (QR y una tarjeta sin decir cuál) pasa a
 *    «tarjetas = [proximidad_125, mifare_1356]» (las dos habilitadas: nadie
 *    pierde el campo de tarjeta que ya tenía).
 *
 * down(): quita la columna y regresa el ajuste a «tecnologia = qr_tarjeta»
 * si la empresa tenía alguna tarjeta habilitada.
 */
return new class extends Migration
{
    private const TODAS = ['proximidad_125', 'mifare_1356'];

    public function up(): void
    {
        Schema::table('credenciales', function (Blueprint $table) {
            $table->string('tipo_tarjeta', 20)->nullable();
        });

        $this->recorrer(function (array $g): array {
            $tecnologia = $g['tecnologia'] ?? null;
            unset($g['tecnologia']);
            if ($tecnologia === 'qr_tarjeta' && ! isset($g['tarjetas'])) {
                $g['tarjetas'] = self::TODAS;
            }

            return $g;
        });
    }

    public function down(): void
    {
        $this->recorrer(function (array $g): array {
            $tarjetas = $g['tarjetas'] ?? [];
            unset($g['tarjetas']);
            if (is_array($tarjetas) && $tarjetas !== []) {
                $g['tecnologia'] = 'qr_tarjeta';
            }

            return $g;
        });

        Schema::table('credenciales', function (Blueprint $table) {
            $table->dropColumn('tipo_tarjeta');
        });
    }

    /** Aplica $cambio a los ajustes de credenciales de cada empresa que los tenga. */
    private function recorrer(callable $cambio): void
    {
        DB::table('empresas')->whereNotNull('preferencias')->orderBy('id')->select(['id', 'preferencias'])
            ->chunkById(200, function ($empresas) use ($cambio) {
                foreach ($empresas as $e) {
                    $p = json_decode((string) $e->preferencias, true);
                    if (! is_array($p) || ! is_array($p['credenciales'] ?? null)) {
                        continue;
                    }
                    $nuevo = $cambio($p['credenciales']);
                    if ($nuevo === $p['credenciales']) {
                        continue;
                    }
                    if ($nuevo === []) {
                        unset($p['credenciales']);
                    } else {
                        $p['credenciales'] = $nuevo;
                    }
                    DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
                }
            });
    }
};
