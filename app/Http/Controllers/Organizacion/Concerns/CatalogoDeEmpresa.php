<?php

namespace App\Http\Controllers\Organizacion\Concerns;

use App\Models\User;
use App\Services\Permisos\Autorizador;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos que son de toda la empresa (Departamentos, Puestos, Turnos...):
 * se consultan con cualquier alcance, pero solo quien tiene alcance de
 * empresa los modifica. Quien solo tiene una sede los ve en modo consulta.
 */
trait CatalogoDeEmpresa
{
    abstract protected function autorizador(): Autorizador;

    /**
     * @return array{crear: bool, editar: bool, estado: bool}
     */
    protected function permisosCatalogo(User $usuario, string $modulo): array
    {
        // Seguridad (AZ-04): "Solo los propios" no es alcance de empresa
        $enEmpresa = fn (string $accion) => $usuario->can("{$modulo}.{$accion}")
            && $this->autorizador()->alcanceDeEmpresa($usuario, "{$modulo}.{$accion}");

        return ['crear' => $enEmpresa('crear'), 'editar' => $enEmpresa('editar'), 'estado' => $enEmpresa('eliminar')];
    }

    protected function exigirAlcanceDeEmpresa(User $usuario, string $permiso): void
    {
        abort_unless($this->autorizador()->alcanceDeEmpresa($usuario, $permiso), 403, 'Este catálogo es de toda la empresa: solo lo modifica quien tiene alcance de empresa.');
    }

    /**
     * Nombre único dentro de la empresa sin importar mayúsculas
     * (no depende de la intercalación de la base de datos).
     */
    protected function nombreLibre(string $tabla, int $empresaId, ?int $excepto, string $mensaje): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falla) use ($tabla, $empresaId, $excepto, $mensaje): void {
            $existe = DB::table($tabla)->where('empresa_id', $empresaId)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower((string) $valor)])
                ->when($excepto !== null, fn ($q) => $q->where('id', '!=', $excepto))
                ->exists();
            if ($existe) {
                $falla($mensaje);
            }
        };
    }

    /**
     * Quita espacios dobles y de los extremos.
     */
    protected function normalizarNombre(?string $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));
    }
}
