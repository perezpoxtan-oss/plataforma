<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra automaticamente por la empresa activa. Ninguna consulta de un
 * modelo operativo depende de que el programador recuerde el filtro.
 */
class EmpresaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);

        if ($tenant->activo()) {
            $builder->where($model->qualifyColumn('empresa_id'), $tenant->empresaId());
        }
    }
}
