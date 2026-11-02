<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan de correcciones, Etapa 7 · C-38 (Configuración ordenada): el aviso por
 * correo «Autorizaciones: avisar al responsable del departamento…» deja el
 * grupo «Recepción y candidatos» y tiene su propio grupo «Autorizaciones de
 * visitas» en Caseta y accesos (App\Support\GruposAvisos).
 *
 * El interruptor general de un grupo se guarda en
 * empresas.preferencias.avisos_modulos.<grupo>. Para que ninguna empresa
 * cambie lo que recibe, el grupo nuevo hereda el interruptor que tenía
 * «candidatos» (solo se escribe cuando estaba apagado: encendido es el valor
 * original). Las casillas de cada aviso no cambian de lugar en los datos.
 *
 * down(): quita el interruptor del grupo nuevo (el aviso vuelve a obedecer al
 * de «candidatos», que no se tocó).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('empresas')->whereNotNull('preferencias')->get(['id', 'preferencias']) as $e) {
            $p = json_decode((string) $e->preferencias, true);
            if (! is_array($p) || ! array_key_exists('candidatos', (array) ($p['avisos_modulos'] ?? [])) || array_key_exists('autorizaciones', $p['avisos_modulos'])) {
                continue;
            }
            if ((bool) $p['avisos_modulos']['candidatos']) {
                continue;
            }
            $p['avisos_modulos']['autorizaciones'] = false;
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('empresas')->whereNotNull('preferencias')->get(['id', 'preferencias']) as $e) {
            $p = json_decode((string) $e->preferencias, true);
            if (! is_array($p) || ! array_key_exists('autorizaciones', (array) ($p['avisos_modulos'] ?? []))) {
                continue;
            }
            unset($p['avisos_modulos']['autorizaciones']);
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
