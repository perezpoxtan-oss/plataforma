<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paso de aprobación de UN pase: copia del circuito de la empresa al momento
 * de enviarlo (un cambio de configuración no altera los pases en curso). Una
 * ronda nueva empieza cada vez que el solicitante corrige y reenvía.
 */
class PaseSalidaAprobacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida_aprobaciones';

    public const PENDIENTE = 'pendiente';

    public const APROBADO = 'aprobado';

    public const OMITIDO = 'omitido';

    public const RECHAZADO = 'rechazado';

    protected $attributes = ['estado' => self::PENDIENTE, 'obligatorio' => true, 'ronda' => 1];

    protected $fillable = ['empresa_id', 'pase_salida_id', 'ronda', 'orden', 'paso_id', 'nombre', 'tipo', 'rol_id', 'departamento_id', 'usuarios', 'obligatorio'];

    protected function casts(): array
    {
        return [
            'usuarios' => 'array',
            'obligatorio' => 'boolean',
            'resuelto_en' => 'datetime',
            'ronda' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function pase(): BelongsTo
    {
        return $this->belongsTo(PaseSalida::class, 'pase_salida_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function resolvio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function firma(): BelongsTo
    {
        return $this->belongsTo(PaseSalidaFirma::class, 'firma_id');
    }

    public function resuelta(): bool
    {
        return in_array($this->estado, [self::APROBADO, self::OMITIDO], true);
    }

    /**
     * Nombres de los usuarios de un paso "usuarios específicos" (se recuerdan
     * durante la petición para no consultar una vez por ficha).
     *
     * @param  list<int>  $ids
     */
    private static function nombresUsuarios(array $ids): string
    {
        $memoria = app()->bound('pases_salida.nombres') ? app('pases_salida.nombres') : [];
        $faltan = array_diff(array_map('intval', $ids), array_keys($memoria));
        if ($faltan !== []) {
            $memoria += User::whereIn('id', $faltan)->pluck('name', 'id')->all();
            app()->instance('pases_salida.nombres', $memoria);
        }

        return implode(', ', array_filter(array_map(fn ($id) => $memoria[(int) $id] ?? null, $ids)));
    }

    /** Quién debe firmar este paso, en palabras. */
    public function quienFirma(): string
    {
        $base = match ($this->tipo) {
            'rol' => 'Rol «'.($this->rol?->nombre ?? '—').'»',
            'usuarios' => self::nombresUsuarios($this->usuarios ?? []) ?: 'Usuarios asignados',
            default => 'Quien tenga permiso «Aprobar»',
        };

        return $base.($this->departamento ? ' de '.$this->departamento->nombre : '');
    }
}
