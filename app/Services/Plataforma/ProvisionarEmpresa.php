<?php

namespace App\Services\Plataforma;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Rubro;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un cliente nuevo: crea la empresa, activa sus modulos y copia
 * las plantillas de rol con sus permisos.
 */
class ProvisionarEmpresa
{
    /**
     * @param  array<string, mixed>  $datos
     * @param  list<string>|null  $modulos  claves de modulos a activar; null = todos
     */
    public function crear(Rubro $rubro, array $datos, ?array $modulos = null): Empresa
    {
        return DB::transaction(function () use ($rubro, $datos, $modulos): Empresa {
            $empresa = Empresa::create(['rubro_id' => $rubro->id] + $datos);

            $consulta = Modulo::query()->where('activo', true);
            if ($modulos !== null) {
                $consulta->where(fn ($q) => $q->whereIn('clave', $modulos)
                    ->orWhereIn('padre_id', Modulo::whereIn('clave', $modulos)->select('id')));
            }
            $empresa->modulos()->syncWithoutDetaching($consulta->pluck('id')->all());

            foreach (Rol::plantillas()->with('permisos')->get() as $plantilla) {
                $rol = Rol::create([
                    'empresa_id' => $empresa->id,
                    'nombre' => $plantilla->nombre,
                    'descripcion' => $plantilla->descripcion,
                    'nivel_jerarquia' => $plantilla->nivel_jerarquia,
                ]);

                foreach ($plantilla->permisos as $permiso) {
                    RolPermiso::create([
                        'rol_id' => $rol->id,
                        'modulo_accion_id' => $permiso->modulo_accion_id,
                        'alcance' => $permiso->alcance,
                    ]);
                }
            }

            return $empresa;
        });
    }
}
