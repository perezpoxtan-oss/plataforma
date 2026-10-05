<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;
use App\Models\NovedadTestigo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Formato específico de una categoría del expediente (SEGCAT:
 * fragmentos/frag_*.php + su bloque en novedades_proceso.php).
 *
 * - valores(): lo guardado, con la forma del formulario (para pintarlo).
 * - validar(): revisa y limpia lo que llegó del formulario.
 * - guardar(): lo escribe en sus tablas (dentro de la transacción del
 *   expediente).
 *
 * Los nombres de campo son los del formulario; la vista del formato está en
 * resources/views/seguridad/novedades/formatos/.
 */
abstract class Formato
{
    /** Máximo de filas por lista dinámica (testigos, personas, artículos…). */
    public const MAX_FILAS = 50;

    /** Relaciones que la vista del formato necesita ya cargadas. */
    abstract public function relaciones(): array;

    /** @return array<string, mixed> */
    abstract public function valores(Novedad $novedad): array;

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    abstract public function validar(array $entrada, Novedad $novedad): array;

    /** @param  array<string, mixed>  $datos */
    abstract public function guardar(Novedad $novedad, array $datos, User $actor): void;

    // ------------------------------------------------------------ Ayudas

    /**
     * Valida con mensajes en español; el nombre de cada campo sale de
     * $atributos (la misma etiqueta de la pantalla).
     *
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $reglas
     * @param  array<string, string>  $atributos
     * @param  array<string, string>  $mensajes
     * @return array<string, mixed>
     */
    protected function revisar(array $entrada, array $reglas, array $atributos, array $mensajes = []): array
    {
        return Validator::make($entrada, $reglas, $mensajes + [
            'max' => 'El campo «:attribute» es muy largo (máximo :max caracteres).',
            'array.max' => 'Son demasiadas filas en «:attribute» (máximo :max).',
            'date_format' => 'Revisa la fecha u hora de «:attribute».',
            'in' => 'Elige una opción válida en «:attribute».',
            'integer' => '«:attribute» debe ser un número entero.',
            'numeric' => '«:attribute» debe ser un número.',
            'min' => '«:attribute» no puede ser menor que :min.',
            'email' => 'Revisa el correo de «:attribute».',
            'url' => 'Revisa el enlace de «:attribute» (debe empezar con https://).',
        ], $atributos)->validate();
    }

    /** Texto limpio (espacios dobles fuera) o null si está vacío. */
    protected function texto(mixed $valor, bool $mayusculas = false): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }
        $limpio = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $valor));
        if ($limpio === '') {
            return null;
        }

        return $mayusculas ? mb_strtoupper($limpio) : $limpio;
    }

    /** "1"/"0", casilla marcada o no. */
    protected function siNo(mixed $valor): bool
    {
        return in_array($valor, [true, 1, '1', 'on', 'si', 'SI'], true);
    }

    protected function hora(mixed $valor): ?string
    {
        $t = $this->texto($valor);

        return $t === null ? null : substr($t, 0, 5).':00';
    }

    /**
     * Fecha y hora local de la sede ("2026-10-05T14:30") a UTC.
     */
    protected function localAUtc(?string $valor, Novedad $novedad): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d\TH:i', substr($valor, 0, 16), $novedad->zonaSede())->utc();
    }

    /**
     * Filas de una lista dinámica: arreglo de arreglos, sin las vacías.
     *
     * @param  string  $clave  campo que decide si la fila está vacía
     * @return list<array<string, mixed>>
     */
    protected function filas(mixed $lista, string $clave): array
    {
        if (! is_array($lista)) {
            return [];
        }

        return array_values(array_filter($lista, fn ($fila) => is_array($fila) && $this->texto($fila[$clave] ?? null) !== null));
    }

    /**
     * Reemplaza los testigos de un formato (se guarda exactamente lo que hay en pantalla).
     *
     * @param  list<array{nombre: string, departamento: ?string, declaracion?: ?string}>  $testigos
     */
    protected function guardarTestigos(Novedad $novedad, string $formato, array $testigos): void
    {
        $novedad->testigos()->where('formato', $formato)->delete();
        foreach ($testigos as $t) {
            NovedadTestigo::create(['novedad_id' => $novedad->id, 'formato' => $formato] + $t);
        }
    }

    /**
     * Testigos del formulario: nombre en mayúsculas, departamento opcional.
     *
     * @return list<array<string, ?string>>
     */
    protected function testigosDe(mixed $lista, bool $conDeclaracion = false): array
    {
        return array_map(fn ($t) => [
            'nombre' => mb_substr($this->texto($t['nombre'] ?? null, true), 0, 150),
            'departamento' => $this->texto($t['departamento'] ?? null, true),
        ] + ($conDeclaracion ? ['declaracion' => $this->texto($t['declaracion'] ?? null)] : []), $this->filas($lista, 'nombre'));
    }

    /**
     * @return list<array<string, ?string>>
     */
    protected function testigosGuardados(Novedad $novedad, string $formato): array
    {
        return $novedad->testigos->where('formato', $formato)->values()
            ->map(fn (NovedadTestigo $t) => ['nombre' => $t->nombre, 'departamento' => $t->departamento, 'declaracion' => $t->declaracion])->all();
    }

    /**
     * Error en un campo del formato (se muestra dentro del expediente).
     */
    protected function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
