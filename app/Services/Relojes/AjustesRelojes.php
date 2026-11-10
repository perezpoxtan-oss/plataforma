<?php

namespace App\Services\Relojes;

use App\Models\Empresa;
use App\Models\Rol;
use App\Support\AjustesCaseta;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de «Reloj y suplente» por empresa (C-22, DD-10), en
 * empresas.preferencias['relojes']:
 *
 *  - Las horas generales son las del antiguo «Reloj del visto bueno»
 *    (AjustesCaseta: vobo_horas_amarillo / vobo_horas_rojo): 24 h ámbar y
 *    recordatorio, 72 h rojo y suplente. Valen para todo lo que espera.
 *  - encendido: recordar y pasar al suplente (de fábrica, sí). Apagado, el
 *    reloj y el color siguen; solo dejan de salir los avisos.
 *  - modulos[clave] (en «Avanzado»): horas propias de un módulo, el rol al
 *    que pasa y si se apaga solo ese. Solo se guarda lo distinto.
 */
class AjustesRelojes
{
    public const CLAVE = 'relojes';

    public const MAX_HORAS = 720;

    /** @return array{encendido: bool, ambar: int, suplente: int} horas generales */
    public function generales(Empresa|int|null $empresa): array
    {
        $empresa = is_int($empresa) ? Empresa::find($empresa) : $empresa;
        $caseta = AjustesCaseta::de($empresa);
        $guardado = $empresa?->preferencias[self::CLAVE] ?? [];

        return [
            'encendido' => (bool) ($guardado['encendido'] ?? true),
            'ambar' => (int) $caseta['vobo_horas_amarillo'],
            'suplente' => (int) $caseta['vobo_horas_rojo'],
        ];
    }

    /**
     * Ajuste vigente de un reloj (horas en minutos).
     *
     * @return array{encendido: bool, ambar: int, suplente: int, rol_id: ?int, propio: bool}
     */
    public function de(Empresa|int|null $empresa, Reloj $reloj): array
    {
        $empresa = is_int($empresa) ? Empresa::find($empresa) : $empresa;
        $general = $this->generales($empresa);
        $modulo = (array) (($empresa?->preferencias[self::CLAVE]['modulos'] ?? [])[$reloj->clave()] ?? []);
        $propios = $reloj->umbralesPropios($empresa);
        $ambar = isset($modulo['ambar']) ? (int) $modulo['ambar'] * 60 : ($propios[0] ?? $general['ambar'] * 60);
        $suplente = isset($modulo['suplente']) ? (int) $modulo['suplente'] * 60 : ($propios[1] ?? $general['suplente'] * 60);

        return [
            'encendido' => $general['encendido'] && (bool) ($modulo['encendido'] ?? true),
            'ambar' => max(1, $ambar),
            'suplente' => max($ambar + 1, $suplente),
            'rol_id' => isset($modulo['rol_id']) ? (int) $modulo['rol_id'] : null,
            'propio' => $propios !== null && ! isset($modulo['ambar']),
        ];
    }

    /**
     * Guarda lo que llega de Configuración (solo lo distinto de fábrica).
     *
     * @param  array<string, mixed>  $entrada  encendido, modulos[clave][ambar|suplente|rol_id|encendido]
     * @param  array<string, Reloj>  $relojes
     */
    public function guardar(Empresa $empresa, array $entrada, array $relojes): void
    {
        $errores = [];
        $modulos = [];
        foreach ((array) ($entrada['modulos'] ?? []) as $clave => $datos) {
            if (! isset($relojes[$clave]) || ! is_array($datos)) {
                continue;
            }
            $fila = [];
            foreach (['ambar', 'suplente'] as $campo) {
                $valor = $datos[$campo] ?? '';
                if ($valor === '' || $valor === null) {
                    continue;
                }
                if (! is_numeric($valor) || (int) $valor != $valor || (int) $valor < 1 || (int) $valor > self::MAX_HORAS) {
                    $errores["modulos.{$clave}.{$campo}"] = $relojes[$clave]->nombre().': escribe un número entero de horas, de 1 a '.self::MAX_HORAS.'.';

                    continue;
                }
                $fila[$campo] = (int) $valor;
            }
            if (isset($fila['ambar'], $fila['suplente']) && $fila['suplente'] <= $fila['ambar']) {
                $errores["modulos.{$clave}.suplente"] = $relojes[$clave]->nombre().': las horas para pasar al suplente deben ser más que las del recordatorio.';
            }
            $rol = $datos['rol_id'] ?? '';
            if ($rol !== '' && $rol !== null) {
                if (! Rol::where('empresa_id', $empresa->id)->where('activo', true)->whereKey((int) $rol)->exists()) {
                    $errores["modulos.{$clave}.rol_id"] = $relojes[$clave]->nombre().': elige un rol de la lista.';
                } else {
                    $fila['rol_id'] = (int) $rol;
                }
            }
            if (array_key_exists('encendido', $datos) && ! filter_var($datos['encendido'], FILTER_VALIDATE_BOOL)) {
                $fila['encendido'] = false;
            }
            if ($fila !== []) {
                $modulos[$clave] = $fila;
            }
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
        $nuevo = array_filter([
            'encendido' => array_key_exists('encendido', $entrada) && ! filter_var($entrada['encendido'], FILTER_VALIDATE_BOOL) ? false : null,
            'modulos' => $modulos === [] ? null : $modulos,
        ], fn ($v) => $v !== null);
        $preferencias = $empresa->preferencias ?? [];
        if ($nuevo === []) {
            unset($preferencias[self::CLAVE]);
        } else {
            $preferencias[self::CLAVE] = $nuevo;
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();
    }
}
