<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipo de acceso (lector, torniquete, checador) que llama a la plataforma:
 * ZKTeco por el protocolo PUSH «iclock» y Hikvision por la notificación de
 * eventos ISAPI. Ver docs/tecnico/equipos-acceso.md.
 *
 * El token nunca se guarda: solo su sha256 (`token_hash`) y sus últimos
 * cuatro caracteres (`token_pista`) para reconocerlo en pantalla.
 */
class EquipoAcceso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'equipos_acceso';

    public const SENTIDOS = ['entrada' => 'Solo entrada', 'salida' => 'Solo salida', 'ambos' => 'Entrada y salida'];

    protected $attributes = ['sentido' => 'ambos', 'activo' => true];

    protected $fillable = ['sede_id', 'nombre', 'marca', 'modelo', 'numero_serie', 'puerta', 'sentido', 'ips_permitidas'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'ultimo_contacto_at' => 'datetime', 'ultimo_marcaje_at' => 'datetime'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function comandos(): HasMany
    {
        return $this->hasMany(ComandoEquipoAcceso::class, 'equipo_acceso_id');
    }

    public function marcajes(): HasMany
    {
        return $this->hasMany(MarcajeEquipoAcceso::class, 'equipo_acceso_id');
    }

    /** «Equipo: <nombre>», como lo ve la bitácora. */
    public function origen(): string
    {
        return 'Equipo: '.$this->nombre;
    }

    /** Lugar del marcaje para los avisos: la puerta o, sin ella, el nombre del equipo. */
    public function lugar(): string
    {
        return $this->puerta ?: $this->nombre;
    }

    /**
     * ¿Llama desde una IP permitida? Sin lista, cualquiera (se recomienda
     * fijarla en ZKTeco, que se identifica solo con su número de serie).
     */
    public function ipPermitida(?string $ip): bool
    {
        $lista = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $this->ips_permitidas) ?: []));

        return $lista === [] || in_array((string) $ip, $lista, true);
    }

    /**
     * En línea si llamó dentro del margen (minutos). ZKTeco pregunta cada
     * 30 s; Hikvision solo llama cuando hay un evento o su latido.
     */
    public function enLinea(int $minutos = 5): bool
    {
        return $this->ultimo_contacto_at !== null && $this->ultimo_contacto_at->gt(now()->subMinutes($minutos));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
