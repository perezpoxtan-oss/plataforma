<?php

namespace App\Services\Auditoria;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Espacio;
use App\Models\Gafete;
use App\Models\GrupoEspacio;
use App\Models\Llave;
use App\Models\Modulo;
use App\Models\Paradero;
use App\Models\PaseSalida;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\TipoEquipo;
use App\Models\TipoEspacio;
use App\Models\TipoGafete;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Traduce la bitácora técnica (evento "modulo.accion", modelo e id, antes y
 * después en JSON) a algo legible: "Colaboradores · Alta provisional ·
 * Jorge Méndez Tun", con la lista de campos que cambiaron.
 */
class LectorAuditoria
{
    /** Verbo de cada acción registrada. */
    private const ACCIONES = [
        'creado' => 'Alta', 'creada' => 'Alta', 'actualizado' => 'Edición', 'actualizada' => 'Edición',
        'desactivado' => 'Desactivación', 'desactivada' => 'Desactivación', 'reactivado' => 'Reactivación', 'reactivada' => 'Reactivación',
        'eliminado' => 'Eliminación', 'eliminada' => 'Eliminación', 'desbloqueado' => 'Desbloqueo', 'rol_asignado' => 'Asignación de rol',
        'rol_actualizado' => 'Cambio de permisos', 'sedes' => 'Cambio de sedes', 'sedes_actualizadas' => 'Cambio de sedes',
        'lote' => 'Alta por lote', 'pisos_copiados' => 'Copia de pisos', 'seccion_creada' => 'Alta de sección',
        'seccion_asignada' => 'Asignación a sección', 'tipo_creado' => 'Alta de tipo', 'provisional' => 'Alta provisional',
        'validado' => 'Validación', 'fusionado' => 'Unión de duplicado',
    ];

    /** Qué es cada registro y cómo se llama. */
    private const REGISTROS = [
        Empresa::class => ['Empresa', 'nombre_comercial'],
        Sede::class => ['Sede', 'nombre'],
        User::class => ['Usuario', 'name'],
        Rol::class => ['Rol', 'nombre'],
        Espacio::class => ['Espacio', 'nombre'],
        GrupoEspacio::class => ['Sección', 'nombre'],
        TipoEspacio::class => ['Tipo de espacio', 'nombre'],
        Departamento::class => ['Departamento', 'nombre'],
        Puesto::class => ['Puesto', 'nombre'],
        Turno::class => ['Turno', 'nombre'],
        Colaborador::class => ['Colaborador', null],
        Proveedor::class => ['Proveedor', 'nombre'],
        Persona::class => ['Persona', 'nombre_completo'],
        Vehiculo::class => ['Vehículo', 'placas'],
        VoucherReposicion::class => ['Voucher', 'folio'],
        Llave::class => ['Llave', 'nomenclatura'],
        Gafete::class => ['Gafete', 'nomenclatura'],
        TipoGafete::class => ['Tipo de gafete', 'nombre'],
        Equipo::class => ['Equipo', 'numero_serie'],
        TipoEquipo::class => ['Tipo de equipo', 'nombre'],
        ZonaEstacionamiento::class => ['Zona de estacionamiento', 'nombre'],
        Ruta::class => ['Ruta de transporte', 'nombre'],
        Paradero::class => ['Paradero', 'nombre'],
        PaseSalida::class => ['Pase de salida', 'folio'],
    ];

    /** Módulos que registran algo en la bitácora, para el filtro. */
    public function modulos(Builder $consulta): Collection
    {
        $claves = (clone $consulta)->reorder()->selectRaw('DISTINCT evento')->pluck('evento')
            ->map(fn ($e) => explode('.', $e)[0])->unique()->values();
        $nombres = Modulo::whereIn('clave', $claves)->pluck('nombre', 'clave');

        return $claves->mapWithKeys(fn ($c) => [$c => $nombres[$c] ?? ucfirst($c)])->sort();
    }

    public function modulo(string $evento): string
    {
        static $nombres = null;
        $nombres ??= Modulo::pluck('nombre', 'clave');
        $clave = explode('.', $evento)[0];

        return $nombres[$clave] ?? ucfirst($clave);
    }

    public function accion(string $evento): string
    {
        $accion = explode('.', $evento, 2)[1] ?? $evento;

        return self::ACCIONES[$accion] ?? ucfirst(str_replace('_', ' ', $accion));
    }

    /**
     * Nombre legible de los registros de una página, con pocas consultas.
     *
     * @param  Collection<int, Auditoria>  $filas
     * @return array<string, string> "Clase#id" => "Colaborador · Jorge Méndez"
     */
    public function registros(Collection $filas): array
    {
        $resultado = [];
        foreach ($filas->groupBy('auditable_type') as $tipo => $grupo) {
            [$etiqueta, $columna] = self::REGISTROS[$tipo] ?? [class_basename((string) $tipo), null];
            if (! class_exists((string) $tipo)) {
                continue;
            }
            $modelos = $tipo::withoutGlobalScopes()->whereIn('id', $grupo->pluck('auditable_id')->filter()->unique())->get()->keyBy('id');
            foreach ($grupo as $fila) {
                $m = $modelos[$fila->auditable_id] ?? null;
                $nombre = $m === null ? '#'.$fila->auditable_id.' (ya no existe)'
                    : ($m instanceof Colaborador ? $m->nombreCompleto() : (string) ($columna ? $m->{$columna} : '#'.$m->getKey()));
                $resultado[$tipo.'#'.$fila->auditable_id] = $etiqueta.' · '.$nombre;
            }
        }

        return $resultado;
    }

    /**
     * Campos del antes y el después, marcando los que cambiaron.
     *
     * @return list<array{campo: string, antes: string, despues: string, cambio: bool}>
     */
    public function diferencias(?array $antes, ?array $despues): array
    {
        $antes ??= [];
        $despues ??= [];
        $filas = [];
        foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $campo) {
            $a = $this->texto($antes[$campo] ?? null);
            $d = $this->texto($despues[$campo] ?? null);
            $filas[] = ['campo' => str_replace('_', ' ', (string) $campo), 'antes' => array_key_exists($campo, $antes) ? $a : '—', 'despues' => array_key_exists($campo, $despues) ? $d : '—', 'cambio' => $a !== $d];
        }

        return $filas;
    }

    private function texto(mixed $valor): string
    {
        return match (true) {
            $valor === null => 'vacío',
            is_bool($valor) => $valor ? 'sí' : 'no',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $valor,
        };
    }
}
