<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Llena creado_por / actualizado_por con el usuario en sesion.
 */
trait RegistraAutor
{
    public static function bootRegistraAutor(): void
    {
        static::creating(function (Model $model): void {
            if (Auth::id() !== null) {
                $model->creado_por ??= Auth::id();
                $model->actualizado_por ??= Auth::id();
            }
        });

        static::updating(function (Model $model): void {
            if (Auth::id() !== null) {
                $model->actualizado_por = Auth::id();
            }
        });
    }
}
