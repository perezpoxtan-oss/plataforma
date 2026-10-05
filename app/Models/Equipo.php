<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Equipo de seguridad prestable a la guardia (SEGCAT: cat_equipos_seguridad):
 * radios, lámparas, detectores, chalecos… Pertenece a una sede y se
 * identifica con su QR, su etiqueta NFC/RFID o tecleando su número de serie.
 */
class Equipo extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador;

    /** Se encuentra también tecleando o escaneando el número de serie. */
    protected string $columnaLegible = 'numero_serie';

    /** clave => texto en pantalla (los de SEGCAT) */
    public const ESTADOS = [
        'disponible' => 'DISPONIBLE',
        'asignado' => 'ASIGNADO',
        'en_mantenimiento' => 'EN MANTENIMIENTO',
        'baja' => 'BAJA/PERDIDO',
    ];

    /** Estados que se eligen al editar. "asignado" lo pone Responsivas y "baja" el botón de baja con voucher. */
    public const ESTADOS_EDITABLES = ['disponible', 'en_mantenimiento'];

    protected $attributes = ['estado' => 'disponible'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'tipo_equipo_id', 'marca', 'modelo', 'numero_serie', 'costo', 'observaciones', 'etiqueta_nfc',
    ];

    protected function casts(): array
    {
        return ['costo' => 'decimal:2'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoEquipo::class, 'tipo_equipo_id');
    }

    /** Renglón del resguardo abierto (Responsivas): quién lo tiene ahora. */
    public function resguardoActual(): HasOne
    {
        return $this->hasOne(EquipoResponsiva::class)->whereNull('devuelto_en');
    }

    public function estaActivo(): bool
    {
        return $this->estado !== 'baja';
    }

    /** "Radio de Comunicación MOTOROLA DEP 450 · S/N 752TSFQ504" (voucher, auditoría). */
    public function descripcion(): string
    {
        $equipo = trim(implode(' ', array_filter([$this->tipo?->nombre, $this->marca, $this->modelo])));

        return trim($equipo.' · S/N '.$this->numero_serie, ' ·');
    }

    /** Mayúsculas, sin espacios en los extremos ni dobles: " rad  8829 " -> "RAD 8829". */
    public static function normalizarSerie(?string $serie): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $serie)));
    }

    public static function tipoLector(): string
    {
        return 'equipo';
    }

    public static function permisoLector(): string
    {
        return 'equipos.ver';
    }

    public function resumenLector(): array
    {
        $this->loadMissing(['tipo:id,nombre', 'sede:id,nombre']);

        return [
            'titulo' => $this->numero_serie,
            'detalle' => implode(' · ', array_filter([
                trim(implode(' ', array_filter([$this->tipo?->nombre, $this->marca, $this->modelo]))),
                self::ESTADOS[$this->estado] ?? $this->estado,
                $this->sede?->nombre,
            ])),
            'activo' => $this->estaActivo(),
            'sede_id' => $this->sede_id,
        ];
    }

    public function urlLector(): string
    {
        return route('equipos.index').'#equipo-'.$this->id;
    }
}
