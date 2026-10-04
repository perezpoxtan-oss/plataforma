<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Persona que trabaja para la empresa (réplica de la tabla colaboradores de
 * SEGCAT). Tiene una sede física (o ninguna = corporativo), sedes
 * adicionales donde también tiene presencia, un departamento (área) y un
 * puesto (rango), que se eligen por separado.
 */
class Colaborador extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'colaboradores';

    /** Datos personales: solo con el permiso colaboradores.datos_personales. */
    public const DATOS_PERSONALES = [
        'curp', 'rfc', 'nss', 'fecha_nacimiento', 'lugar_nacimiento', 'nacionalidad', 'correo_personal', 'direccion_completa',
    ];

    /** Estado de nacimiento: las 32 entidades federativas y "Extranjero". */
    public const ESTADOS_NACIMIENTO = [
        'Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas', 'Chihuahua',
        'Ciudad de México', 'Coahuila', 'Colima', 'Durango', 'Estado de México', 'Guanajuato', 'Guerrero',
        'Hidalgo', 'Jalisco', 'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro',
        'Quintana Roo', 'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz',
        'Yucatán', 'Zacatecas', 'Extranjero',
    ];

    public const CURP = '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/';

    public const RFC = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u';

    public const NSS = '/^\d{11}$/';

    protected $attributes = ['activo' => true, 'nacionalidad' => 'Mexicana'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'departamento_id', 'puesto_id', 'num_empleado', 'nombre', 'apellido_paterno',
        'apellido_materno', 'telefono', 'fecha_nacimiento', 'lugar_nacimiento', 'nacionalidad', 'curp', 'rfc',
        'nss', 'correo_personal', 'direccion_completa', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'fecha_nacimiento' => 'date:Y-m-d'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function sedesAdicionales(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'colaborador_sede');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function nombreCompleto(): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->nombre.' '.$this->apellido_paterno.' '.($this->apellido_materno ?? '')));
    }

    public function iniciales(): string
    {
        return mb_strtoupper(mb_substr((string) $this->nombre, 0, 1).mb_substr((string) $this->apellido_paterno, 0, 1));
    }

    /**
     * Colaboradores con presencia en alguna de estas sedes (física o adicional).
     *
     * @param  array<int>  $sedeIds
     */
    public function scopeEnSedes(Builder $consulta, array $sedeIds): Builder
    {
        return $consulta->where(fn ($q) => $q->whereIn('colaboradores.sede_id', $sedeIds)
            ->orWhereHas('sedesAdicionales', fn ($s) => $s->whereIn('sedes.id', $sedeIds)));
    }

    /**
     * Últimos 4 caracteres, para la bitácora (nunca el dato completo).
     */
    public static function enmascarar(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return str_repeat('*', max(0, mb_strlen($valor) - 4)).mb_substr($valor, -4);
    }
}
