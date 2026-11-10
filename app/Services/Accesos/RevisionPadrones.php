<?php

namespace App\Services\Accesos;

use App\Models\Persona;
use App\Models\Proveedor;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Padrones\AvisoDuplicado;
use Illuminate\Support\Collection;

/**
 * C-13 (B-02): al GUARDAR un ingreso (no solo al teclear) la caseta compara
 * lo capturado con lo que tiene el acceso restringido: sin acentos, sin
 * «S.A. de C.V.» ni signos y con las palabras en otro orden (las mismas
 * reglas de «¿Es alguno de estos?»). También los acompañantes con nombre.
 */
class RevisionPadrones
{
    /** @var Collection<int, Persona>|null */
    private ?Collection $personas = null;

    /** @var Collection<int, Proveedor>|null */
    private ?Collection $proveedores = null;

    public function __construct(private readonly AvisoDuplicado $avisos) {}

    /** Persona restringida igual o parecida al nombre (null si no hay). */
    public function personaRestringida(?string $nombre): ?Persona
    {
        if ($nombre === null || trim($nombre) === '') {
            return null;
        }
        $this->personas ??= Persona::query()->restringidos()->whereNull('fusionado_en_id')->limit(500)->get(['id', 'nombre_completo', 'restringido_en', 'restringido_hasta', 'restringido_motivo']);

        return $this->personas->first(fn (Persona $p) => $this->avisos->seParecen($nombre, $p->nombre_completo));
    }

    /** Empresa externa restringida igual o parecida al nombre (null si no hay). */
    public function proveedorRestringido(?string $nombre): ?Proveedor
    {
        if ($nombre === null || trim($nombre) === '') {
            return null;
        }
        $this->proveedores ??= Proveedor::query()->restringidos()->whereNull('fusionado_en_id')->limit(500)->get(['id', 'nombre', 'restringido_en', 'restringido_hasta', 'restringido_motivo']);
        $altas = app(AltasPorVerificar::class);

        return $this->proveedores->first(fn (Proveedor $p) => $altas->mismaEmpresa($nombre, $p->nombre) || $this->avisos->seParecen($nombre, $p->nombre));
    }

    /** «Se parece a «X» (acceso restringido: motivo). ¿Es la misma?» */
    public static function mensajeParecido(string $que, string $nombre, string $restriccion): string
    {
        return "{$que} se parece a «{$nombre}», que tiene el acceso restringido ({$restriccion}). ¿Es la misma?";
    }
}
