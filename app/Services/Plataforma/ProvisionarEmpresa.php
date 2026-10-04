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

            $consulta = Modulo::query()->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA);
            if ($modulos !== null) {
                $consulta->where(fn ($q) => $q->whereIn('clave', $modulos)
                    ->orWhereIn('padre_id', Modulo::whereIn('clave', $modulos)->select('id')));
            }
            $empresa->modulos()->syncWithoutDetaching($consulta->pluck('id')->all());

            foreach (Rol::plantillas()->with('permisos')->get() as $plantilla) {
                $this->copiarPlantilla($plantilla, $empresa->id);
            }

            return $empresa;
        });
    }

    /**
     * Copia una plantilla de rol (con sus permisos) a una empresa.
     */
    public function copiarPlantilla(Rol $plantilla, int $empresaId): Rol
    {
        $rol = Rol::create([
            'empresa_id' => $empresaId,
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

        return $rol;
    }

    /**
     * Copia la plantilla solo si la empresa aún no tiene un rol con ese nombre
     * ni otro rol en ese nivel (cada nivel es único por empresa). Para agregar
     * una plantilla nueva a las empresas que ya existían.
     */
    public function copiarPlantillaSiFalta(Rol $plantilla, int $empresaId): ?Rol
    {
        $ocupado = Rol::where('empresa_id', $empresaId)
            ->where(fn ($q) => $q->where('nombre', $plantilla->nombre)->orWhere('nivel_jerarquia', $plantilla->nivel_jerarquia))
            ->exists();

        return $ocupado ? null : DB::transaction(fn () => $this->copiarPlantilla($plantilla, $empresaId));
    }
}
