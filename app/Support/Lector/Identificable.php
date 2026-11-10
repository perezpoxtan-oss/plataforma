<?php

namespace App\Support\Lector;

use Illuminate\Database\Eloquent\Builder;

/**
 * Un registro que se puede encontrar con el lector universal (QR, NFC, RFID,
 * código de barras o tecleando). Llaves, gafetes, equipos, vehículos,
 * colaboradores… Se registra en config/lector.php.
 *
 * La mayoría usa el trait TieneIdentificador, que aporta codigo_qr y
 * etiqueta_nfc; aquí solo va lo que cambia de un tipo a otro.
 */
interface Identificable
{
    /** Clave del tipo para el lector y la API: "llave", "gafete", "vehiculo"… */
    public static function tipoLector(): string;

    /** Permiso para verlo: "llaves.ver", "vehiculos.ver"… */
    public static function permisoLector(): string;

    /**
     * Filtra los registros que corresponden a lo leído.
     *
     * @param  Builder<static>  $consulta
     * @param  string|null  $codigo  código de la plataforma (QR / URL), si lo hubo
     * @param  list<string>  $candidatos  formas equivalentes de lo leído (Etiqueta::candidatos)
     */
    public function scopeCoincideConLectura(Builder $consulta, ?string $codigo, array $candidatos): void;

    /**
     * Lo que el lector muestra al encontrarlo.
     *
     * @return array{titulo: string, detalle: ?string, activo: bool, sede_id: ?int}
     */
    public function resumenLector(): array;

    /** Pantalla donde se ve el registro (a donde lleva un QR escaneado con la cámara del celular). */
    public function urlLector(): string;
}
