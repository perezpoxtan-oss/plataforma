<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para todo modelo operativo con columna empresa_id:
 * - aplica el filtro de la empresa activa a todas las consultas;
 * - al guardar, asigna la empresa activa si no viene;
 * - impide guardar un registro de otra empresa.
 */
trait PerteneceAEmpresa
{
    public static function bootPerteneceAEmpresa(): void
    {
        static::addGlobalScope(new EmpresaScope);

        // "saving" corre antes que "creating": aqui se asigna y se valida.
        static::saving(function (Model $model): void {
            $tenant = app(Tenant::class);

            if (! $tenant->activo()) {
                return;
            }

            if (empty($model->empresa_id)) {
                $model->empresa_id = $tenant->empresaId();
            }

            if ((int) $model->empresa_id !== $tenant->empresaId()) {
                throw new \DomainException('No se puede guardar un registro de otra empresa.');
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
