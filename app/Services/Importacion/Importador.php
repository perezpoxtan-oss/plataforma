<?php

namespace App\Services\Importacion;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un módulo que se puede llenar desde archivo. Cada importador solo traduce
 * la fila (textos como «Sede Centro» o «Particular») a lo que recibe el alta
 * manual y llama al MISMO servicio que la pantalla: las reglas no se repiten.
 *
 * Todo corre con la empresa de trabajo fijada en el Tenant.
 */
abstract class Importador
{
    /** colaboradores | personas | proveedores | vehiculos | zonas */
    abstract public function tipo(): string;

    /** Clave del módulo en el catálogo de permisos («modulo.importar», «modulo.crear»…). */
    abstract public function modulo(): string;

    /** Nombre de la lista tal como se ve en el menú. */
    abstract public function titulo(): string;

    /** Nombre de cada registro en singular («colaborador», «vehículo»). */
    abstract public function registro(): string;

    abstract public function rutaLista(): string;

    /**
     * @return list<Columna>
     */
    abstract public function columnas(): array;

    /** Valor que identifica el registro (para «Ya existe» y repetidos en el archivo); null = sin clave. */
    abstract public function clave(array $fila): ?string;

    /** Texto de la columna que identifica («Número de empleado»). */
    abstract public function nombreClave(): string;

    abstract public function existente(array $fila): ?Model;

    abstract public function crear(User $actor, int $empresaId, array $fila): Model;

    abstract public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model;

    /** ¿El registro que ya existe está dentro de lo que el actor puede editar? */
    abstract public function editable(User $actor, Model $existente): bool;

    /** Zonas y edificios no tienen datos que actualizar (solo se omiten). */
    public function actualizable(): bool
    {
        return true;
    }

    /** Lo que se dice de un registro que ya existe («Ya existe: Juan Pérez»). */
    public function describir(Model $registro): string
    {
        return '';
    }

    /**
     * Columna donde se muestra el error de un campo del servicio.
     */
    public function columnaDeCampo(string $campo): ?string
    {
        foreach ($this->columnas() as $columna) {
            if ($columna->clave === $campo || in_array($campo, $columna->campos, true)) {
                return $columna->clave;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Guarda con el servicio. Si al traducir la fila ya hubo errores (una
     * sede que no existe…), igual la pasa por el servicio para mostrar TODOS
     * los errores de la fila de una vez, y deshace lo que el servicio haga.
     *
     * @param  array<string, list<string>>  $errores
     */
    protected function guardarOReportar(array $errores, \Closure $guardar): Model
    {
        if ($errores === []) {
            return $guardar();
        }
        try {
            DB::transaction(function () use ($guardar) {
                $guardar();

                throw new FilaConErrores;
            });
        } catch (ValidationException $e) {
            $errores += $e->errors();
        } catch (FilaConErrores) {
            // El servicio no encontró más errores: quedan los de la traducción
        }

        throw ValidationException::withMessages($errores);
    }

    /**
     * Ejecuta y, si falla la validación, junta el error en $errores y devuelve null.
     *
     * @param  array<string, list<string>>  $errores
     */
    protected function intentar(array &$errores, \Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            $errores += $e->errors();

            return null;
        }
    }

    /**
     * Petición con los datos de la fila, como si vinieran del formulario.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function peticion(array $datos): Request
    {
        return Request::create('/', 'POST', $datos);
    }

    /** Valor de la celda (null si viene vacía). */
    protected function valor(array $fila, string $clave): ?string
    {
        $valor = $fila[$clave] ?? null;

        return $valor === null || trim((string) $valor) === '' ? null : trim((string) $valor);
    }

    /**
     * Sede de la empresa por nombre o código (sin importar mayúsculas ni acentos).
     */
    protected function sede(?string $texto, string $columna = 'sede'): ?Sede
    {
        if ($texto === null) {
            return null;
        }
        $buscado = Columna::normalizar($texto);
        foreach (Sede::query()->orderBy('id')->get(['id', 'empresa_id', 'nombre', 'codigo', 'activo']) as $sede) {
            if (Columna::normalizar($sede->nombre) === $buscado || Columna::normalizar((string) $sede->codigo) === $buscado) {
                return $sede;
            }
        }

        throw ValidationException::withMessages([$columna => "No existe la sede «{$texto}» en esta empresa: escríbela como aparece en Estructura → Sedes (nombre o código)."]);
    }

    /**
     * Clave de una lista (tipo, categoría…) a partir de su texto o de su clave.
     *
     * @param  array<string, string>  $opciones  clave => texto
     * @param  array<string, string>  $extra  otros textos aceptados => clave
     */
    protected function opcion(?string $texto, array $opciones, string $columna, string $nombre, array $extra = []): ?string
    {
        if ($texto === null) {
            return null;
        }
        $buscado = Columna::normalizar($texto);
        foreach ($opciones as $clave => $etiqueta) {
            if ($buscado === Columna::normalizar($clave) || $buscado === Columna::normalizar($etiqueta)) {
                return $clave;
            }
        }
        foreach ($extra as $etiqueta => $clave) {
            if ($buscado === Columna::normalizar($etiqueta)) {
                return $clave;
            }
        }

        throw ValidationException::withMessages([$columna => "«{$texto}» no es {$nombre}. Usa uno de estos: ".implode(', ', array_values($opciones)).'.']);
    }

    /**
     * Fecha escrita como 31/12/1990, 31-12-1990, 1990-12-31 o la que da Excel.
     */
    protected function fecha(?string $texto, string $columna): ?string
    {
        if ($texto === null) {
            return null;
        }
        $texto = trim(explode(' ', $texto)[0]);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $texto, $m)) {
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $texto, $m)) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw ValidationException::withMessages([$columna => "La fecha «{$texto}» no se entiende: escríbela como día/mes/año (31/12/1990)."]);
        }
        if (! checkdate($mes, $d, $a)) {
            throw ValidationException::withMessages([$columna => "La fecha «{$texto}» no existe: revisa el día y el mes (día/mes/año)."]);
        }

        return sprintf('%04d-%02d-%02d', $a, $mes, $d);
    }

    /**
     * Toma lo que ya tiene el registro y encima lo que trae la fila: al
     * actualizar, una celda vacía conserva el dato actual.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $nuevo
     * @return array<string, mixed>
     */
    protected function encima(array $actual, array $nuevo): array
    {
        return array_merge($actual, array_filter($nuevo, fn ($v) => $v !== null && $v !== ''));
    }
}
