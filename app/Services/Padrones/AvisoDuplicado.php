<?php

namespace App\Services\Padrones;

use Illuminate\Http\JsonResponse;

/**
 * Avisos de duplicado en vivo (Ronda 5, parte 2; ver docs/tecnico/avisos-duplicado.md).
 *
 * Un campo del formulario pregunta mientras se escribe
 * (<input data-duplicado="url"> en el bloque "Avisos de duplicado en vivo"
 * de plataforma.js) y cada módulo responde con este mismo formato JSON:
 *
 *   {estado: "nada" | "libre" | "existe" | "parecido", mensaje: string,
 *    coincidencias: [{titulo, detalle, inactivo, reactivar}]}
 *
 *  - existe:   el servidor lo rechazará al guardar (o ya está en la lista).
 *  - parecido: solo es un aviso ("Se parece a «Torre B» (TB)").
 *  - libre:    no hay nada igual ni parecido ("Disponible.").
 *  - nada:     no hay suficiente texto para revisar (no se muestra nada).
 *
 * La comparación reutiliza las reglas de "¿Es alguno de estos?" de las altas
 * por verificar (AltasPorVerificar::similitud / claveNombre): sin mayúsculas,
 * acentos, espacios, guiones ni signos.
 */
class AvisoDuplicado
{
    public const NADA = 'nada';

    public const LIBRE = 'libre';

    public const EXISTE = 'existe';

    public const PARECIDO = 'parecido';

    public function __construct(private readonly AltasPorVerificar $altas) {}

    /** Mayúsculas, sin acentos, espacios, guiones ni signos: «TB» = «T-B» = «T B» = «tb». */
    public function clave(?string $texto): string
    {
        return mb_strtoupper(str_replace(' ', '', $this->altas->claveNombre($texto)));
    }

    /** Nombre comparable: minúsculas, sin acentos ni signos, espacios simples. */
    public function claveNombre(?string $texto): string
    {
        return $this->altas->claveNombre($texto);
    }

    /**
     * ¿Se parecen dos nombres? (mismas reglas que "¿Es alguno de estos?").
     * Iguales sin espacios ni signos («Torre-B» y «TorreB») también cuentan.
     */
    public function seParecen(string $a, string $b): bool
    {
        $ca = $this->altas->claveNombre($a);
        $cb = $this->altas->claveNombre($b);
        if ($ca === '' || $cb === '') {
            return false;
        }
        if (str_replace(' ', '', $ca) === str_replace(' ', '', $cb)) {
            return true;
        }

        return $this->altas->similitud($ca, $cb, mb_strlen($ca) <= 5 ? 1 : 2, true) !== null;
    }

    /**
     * $abrir: dirección del registro que ya existe (botón «Abrir su ficha»).
     *
     * @return array{titulo: string, detalle: ?string, inactivo: bool, reactivar: ?string, abrir?: string}
     */
    public function coincidencia(string $titulo, ?string $detalle = null, bool $inactivo = false, ?string $reactivar = null, ?string $abrir = null): array
    {
        return ['titulo' => $titulo, 'detalle' => $detalle, 'inactivo' => $inactivo, 'reactivar' => $inactivo ? $reactivar : null]
            + ($abrir !== null ? ['abrir' => $abrir] : []);
    }

    public function nada(): JsonResponse
    {
        return $this->respuesta(self::NADA, '');
    }

    public function libre(string $mensaje = 'Disponible.'): JsonResponse
    {
        return $this->respuesta(self::LIBRE, $mensaje);
    }

    /**
     * @param  list<array<string, mixed>>  $coincidencias
     */
    public function existe(string $mensaje, array $coincidencias = []): JsonResponse
    {
        return $this->respuesta(self::EXISTE, $mensaje, $coincidencias);
    }

    /**
     * @param  list<array<string, mixed>>  $coincidencias
     */
    public function parecido(string $mensaje, array $coincidencias): JsonResponse
    {
        return $this->respuesta(self::PARECIDO, $mensaje, $coincidencias);
    }

    /**
     * @param  list<array<string, mixed>>  $coincidencias
     */
    private function respuesta(string $estado, string $mensaje, array $coincidencias = []): JsonResponse
    {
        return response()->json(['estado' => $estado, 'mensaje' => $mensaje, 'coincidencias' => array_values($coincidencias)]);
    }
}
