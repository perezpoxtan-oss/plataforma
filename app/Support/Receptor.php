<?php

namespace App\Support;

use App\Models\Colaborador;
use App\Models\Persona;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Plan de correcciones, C-16 (DD-08): quien recibe una llave o un equipo, o
 * responde por una pérdida, puede ser un colaborador, una persona del Padrón
 * de personas (incluido el personal de una empresa externa) o una empresa
 * externa. Préstamos, responsivas y vouchers guardan `receptor_tipo` y
 * `receptor_id` (en vouchers: `responsable_tipo` y `responsable_id`).
 *
 * `colaborador_id` se sigue llenando cuando es un colaborador: así no cambian
 * la ficha del colaborador, los informes ni lo que ya funcionaba.
 */
final class Receptor
{
    public const COLABORADOR = 'colaborador';

    public const PERSONA = 'persona';

    public const PROVEEDOR = 'proveedor';

    /** tipo => texto del selector */
    public const TIPOS = [
        self::COLABORADOR => 'Colaborador',
        self::PERSONA => 'Persona externa',
        self::PROVEEDOR => 'Empresa externa',
    ];

    /** tipo => clase */
    public const CLASES = [
        self::COLABORADOR => Colaborador::class,
        self::PERSONA => Persona::class,
        self::PROVEEDOR => Proveedor::class,
    ];

    /**
     * Lee receptor_tipo / receptor_id (o, como antes, colaborador_id) de la
     * entrada. null si no viene ninguno.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: string, 1: int}|null
     */
    public static function deEntrada(array $entrada, string $prefijo = 'receptor'): ?array
    {
        $tipo = $entrada[$prefijo.'_tipo'] ?? null;
        $id = $entrada[$prefijo.'_id'] ?? null;
        if (is_string($tipo) && isset(self::TIPOS[$tipo]) && is_numeric($id) && (int) $id > 0) {
            return [$tipo, (int) $id];
        }
        $colaborador = $entrada['colaborador_id'] ?? null;
        if (is_numeric($colaborador) && (int) $colaborador > 0 && ($tipo === null || $tipo === '' || $tipo === self::COLABORADOR)) {
            return [self::COLABORADOR, (int) $colaborador];
        }

        return null;
    }

    /**
     * El registro de la empresa de trabajo (activo, y no unido a otro) o un
     * error de validación en $campo.
     */
    public static function buscar(string $tipo, int $id, string $campo = 'receptor_id', bool $soloActivos = true): Model
    {
        $registro = self::cargar($tipo, $id);
        $unido = $registro !== null && $registro->getAttribute('fusionado_en_id') !== null;
        if ($registro === null || $unido || ($soloActivos && ! $registro->getAttribute('activo'))) {
            throw ValidationException::withMessages([$campo => match ($tipo) {
                self::PERSONA => 'La persona elegida no existe en el Padrón de personas o está dada de baja.',
                self::PROVEEDOR => 'La empresa externa elegida no existe o está dada de baja.',
                default => 'El colaborador elegido no existe en tu empresa o está dado de baja.',
            }]);
        }
        if ($tipo !== self::COLABORADOR && method_exists($registro, 'restringido') && $registro->restringido()) {
            throw ValidationException::withMessages([$campo => '«'.self::nombreDe($registro).'» tiene el acceso restringido: no se le puede entregar nada. Llama a tu supervisor.']);
        }

        return $registro;
    }

    /** «Juan Pérez», «Juan Pérez (Elevadores del Sur)» o «Elevadores del Sur». */
    public static function nombreDe(?Model $registro): string
    {
        return match (true) {
            $registro instanceof Colaborador => $registro->nombreCompleto(),
            $registro instanceof Persona => $registro->nombre_completo.(($e = $registro->empresaQueRepresenta()) ? " ({$e})" : ''),
            $registro instanceof Proveedor => (string) $registro->nombre,
            default => '—',
        };
    }

    public static function tipoDe(?Model $registro): ?string
    {
        return match (true) {
            $registro instanceof Colaborador => self::COLABORADOR,
            $registro instanceof Persona => self::PERSONA,
            $registro instanceof Proveedor => self::PROVEEDOR,
            default => null,
        };
    }

    /**
     * Lo que se manda a la pantalla para proponerlo en un selector.
     *
     * @return array{tipo: string, id: int, titulo: string}|null
     */
    public static function propuesta(?Model $registro): ?array
    {
        $tipo = self::tipoDe($registro);

        return $tipo === null ? null : ['tipo' => $tipo, 'id' => (int) $registro->getKey(), 'titulo' => self::nombreDe($registro)];
    }

    /**
     * Lo que se había elegido antes de que el formulario regresara con
     * errores (old()), para volver a mostrarlo. Corre dentro del Tenant.
     *
     * @return array{tipo: string, id: int, titulo: string}|null
     */
    public static function anterior(string $prefijo = 'receptor'): ?array
    {
        $tipo = old($prefijo.'_tipo');
        $id = old($prefijo.'_id');
        if (is_string($tipo) && $tipo !== self::COLABORADOR && is_numeric($id)) {
            return self::propuesta(self::cargar($tipo, (int) $id));
        }
        $colaborador = old('colaborador_id');

        return is_numeric($colaborador) ? self::propuesta(self::cargar(self::COLABORADOR, (int) $colaborador)) : null;
    }

    /**
     * Al unir un registro duplicado con el correcto, lo que se le entregó o se
     * le cargó pasa al correcto (préstamos, responsivas y vouchers).
     *
     * @return array<string, int>
     */
    public static function moverReferencias(string $tipo, int $de, int $a): array
    {
        $movidos = [];
        foreach (['prestamos_llaves' => 'receptor', 'responsivas' => 'receptor', 'vouchers_reposicion' => 'responsable'] as $tabla => $prefijo) {
            if (Schema::hasColumn($tabla, $prefijo.'_tipo')) {
                $movidos[$tabla.'.'.$prefijo] = DB::table($tabla)->where($prefijo.'_tipo', $tipo)->where($prefijo.'_id', $de)->update([$prefijo.'_id' => $a]);
            }
        }

        return $movidos;
    }

    public static function cargar(?string $tipo, ?int $id): ?Model
    {
        $clase = self::CLASES[$tipo ?? ''] ?? null;
        if ($clase === null || $id === null) {
            return null;
        }

        // La persona se muestra con su empresa: se trae de una vez (sin carga perezosa)
        return $clase::query()->when($clase === Persona::class, fn ($q) => $q->with('proveedor:id,nombre'))->find($id);
    }
}
