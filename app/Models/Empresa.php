<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente de la plataforma (tenant). No usa el filtro de empresa porque
 * es la entidad que lo define; el acceso se controla con permisos.
 */
class Empresa extends Model
{
    use RegistraAutor, SoftDeletes;

    protected $table = 'empresas';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true, 'zona_horaria' => 'America/Mexico_City', 'idioma' => 'es', 'moneda' => 'MXN'];

    protected $fillable = [
        'rubro_id', 'nombre_comercial', 'razon_social', 'rfc', 'logo_ruta',
        'zona_horaria', 'idioma', 'moneda', 'activo',
    ];

    /** Avisos por correo y su valor si la empresa no lo ha cambiado. */
    public const AVISOS = [
        'alta_provisional' => ['Avisar a Recursos Humanos cuando la caseta registre un alta provisional de colaborador', true],
        // Bitácora de transporte (SEGCAT: configuracion_correo.destinatarios_vouchers)
        'vale_taxi' => ['Enviar cada vale de taxi de la Bitácora de transporte (para su autorización)', true],
        // Pases de salida (circuito de aprobación)
        'pase_salida_aprobacion' => ['Pases de salida: avisar a quien debe aprobar el siguiente paso', true],
        'pase_salida_resultado' => ['Pases de salida: avisar al solicitante cuando su pase se aprueba o se rechaza', true],
        'pase_salida_vencido' => ['Pases de salida: recordatorio diario de los pases vencidos que no han regresado', true],
        // Procedimientos (acuse «Leí y entendí»)
        'procedimiento_publicado' => ['Procedimientos: avisar al personal cuando se publica una versión que debe leer y firmar', true],
        'procedimiento_recordatorio' => ['Procedimientos: recordar (cada 3 días) los procedimientos que alguien aún no firma de enterado', true],
    ];

    /** Avisos que se mandan a una lista de correos capturada en Configuración (y no solo a usuarios con permiso). */
    public const AVISOS_CON_DESTINATARIOS = ['vale_taxi'];

    public function aviso(string $clave): bool
    {
        return (bool) ($this->preferencias['avisos'][$clave] ?? (self::AVISOS[$clave][1] ?? false));
    }

    /**
     * Correos capturados para un aviso (Configuración → Avisos por correo).
     *
     * @return list<string>
     */
    public function destinatariosAviso(string $clave): array
    {
        $lista = $this->preferencias['avisos_destinatarios'][$clave] ?? [];

        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'preferencias' => 'array'];
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'empresa_modulos')
            ->withPivot(['nombre_visible', 'orden', 'activo'])
            ->withTimestamps();
    }
}
