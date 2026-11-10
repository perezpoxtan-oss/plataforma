<?php

namespace App\Services\RecorridosPc;

use App\Models\Empresa;
use App\Models\EquipoPc;
use Illuminate\Validation\ValidationException;

/**
 * Operación programada de los equipos de Protección Civil (DD-17): cada
 * cuántos días se debe revisar cada tipo de equipo (0 = sin frecuencia) y
 * cuántos días antes de la próxima recarga se avisa. De fábrica solo los
 * extintores, cada 30 días (revisión mensual); lo demás, sin frecuencia.
 * En empresas.preferencias['equipos_pc'] (solo lo distinto de fábrica).
 */
class AjustesEquiposPc
{
    public const CLAVE = 'equipos_pc';

    public const FRECUENCIA_OMISION = ['EXTINTOR' => 30];

    public const MAX_DIAS = 730;

    public const RECARGA_AVISO_DIAS = 15;

    /** @return array<string, int> categoría => días (0 = sin frecuencia) */
    public function frecuencias(Empresa|int|null $empresa): array
    {
        $empresa = is_int($empresa) ? Empresa::find($empresa) : $empresa;
        $guardado = (array) ($empresa?->preferencias[self::CLAVE]['frecuencia_dias'] ?? []);
        $r = [];
        foreach (array_keys(EquipoPc::CATEGORIAS) as $cat) {
            $v = $guardado[$cat] ?? (self::FRECUENCIA_OMISION[$cat] ?? 0);
            $r[$cat] = is_numeric($v) ? max(0, min(self::MAX_DIAS, (int) $v)) : 0;
        }

        return $r;
    }

    /**
     * @param  array<string, mixed>  $entrada  frecuencia_dias[CATEGORIA]
     */
    public function guardar(Empresa $empresa, array $entrada): void
    {
        $limpio = [];
        foreach (array_keys(EquipoPc::CATEGORIAS) as $cat) {
            $v = $entrada['frecuencia_dias'][$cat] ?? null;
            if ($v === null || $v === '') {
                $v = 0;
            }
            if (! is_numeric($v) || (int) $v != $v || (int) $v < 0 || (int) $v > self::MAX_DIAS) {
                throw ValidationException::withMessages(["frecuencia_dias.{$cat}" => EquipoPc::CATEGORIAS[$cat].': escribe los días con un número entero, de 0 a '.self::MAX_DIAS.' (0 = sin frecuencia).']);
            }
            if ((int) $v !== (self::FRECUENCIA_OMISION[$cat] ?? 0)) {
                $limpio[$cat] = (int) $v;
            }
        }
        $preferencias = $empresa->preferencias ?? [];
        if ($limpio === []) {
            unset($preferencias[self::CLAVE]);
        } else {
            $preferencias[self::CLAVE] = ['frecuencia_dias' => $limpio];
        }
        $empresa->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
    }
}
