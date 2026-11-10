<?php

namespace App\Models\Concerns;

use App\Support\Lector\Etiqueta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Para modelos con columnas codigo_qr (aleatorio, va impreso en el QR o
 * grabado en la etiqueta NFC) y etiqueta_nfc (número de serie del chip o de
 * la tarjeta RFID que se le asignó; único por empresa, opcional).
 *
 * - Genera codigo_qr al crear (24 caracteres, no adivinable).
 * - Guarda etiqueta_nfc normalizada ("04:a2:3b" -> "04A23B").
 * - Aporta la búsqueda por lectura (código o cualquiera de las formas del chip).
 * - Si el modelo declara una columna "humana" (nomenclatura, placas, número
 *   de empleado…) en $columnaLegible, también se encuentra tecleándola o con
 *   un lector de código de barras.
 */
trait TieneIdentificador
{
    public static function bootTieneIdentificador(): void
    {
        static::creating(function ($modelo): void {
            $modelo->codigo_qr ??= Str::lower(Str::random(24));
        });

        static::saving(function ($modelo): void {
            if ($modelo->isDirty('etiqueta_nfc')) {
                $limpia = Etiqueta::normalizar($modelo->etiqueta_nfc);
                $modelo->etiqueta_nfc = $limpia === '' ? null : $limpia;
            }
        });
    }

    public function scopeCoincideConLectura(Builder $consulta, ?string $codigo, array $candidatos): void
    {
        $columna = property_exists($this, 'columnaLegible') ? $this->columnaLegible : null;

        $consulta->where(function (Builder $q) use ($codigo, $candidatos, $columna) {
            if ($codigo !== null) {
                $q->orWhere($this->qualifyColumn('codigo_qr'), $codigo);
            }
            if ($candidatos !== []) {
                $q->orWhereIn($this->qualifyColumn('etiqueta_nfc'), $candidatos)
                    // El texto de la etiqueta puede ser el propio código
                    ->orWhereIn($this->qualifyColumn('codigo_qr'), array_map('strtolower', $candidatos));
                if ($columna !== null) {
                    $q->orWhereIn($this->qualifyColumn($columna), $candidatos);
                }
            }
        });
    }

    /**
     * ¿Ya hay otro registro de la empresa con esa etiqueta? Para validar al asignarla.
     */
    public static function etiquetaOcupada(?string $etiqueta, ?int $exceptoId = null): ?static
    {
        $limpia = Etiqueta::normalizar($etiqueta);
        if ($limpia === '') {
            return null;
        }

        return static::query()->where('etiqueta_nfc', $limpia)
            ->when($exceptoId, fn ($q) => $q->whereKeyNot($exceptoId))
            ->first();
    }
}
