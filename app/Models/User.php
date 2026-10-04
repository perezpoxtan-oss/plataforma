<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Cuenta de acceso. empresa_id nulo solo para el Super Administrador de la
 * plataforma; cualquier otro usuario pertenece a una empresa.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true, 'es_superadmin' => false, 'intentos_fallidos' => 0];

    protected $fillable = [
        'empresa_id', 'name', 'username', 'email', 'password', 'activo',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'es_superadmin' => 'boolean',
            'activo' => 'boolean',
            'bloqueado_hasta' => 'datetime',
            'ultimo_acceso_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'usuario_roles', 'user_id', 'rol_id')
            ->withPivot('sede_id')
            ->withTimestamps();
    }

    /**
     * Nivel jerarquico mas alto (numero menor) entre sus roles activos.
     * 0 para el Super Administrador; 65535 si no tiene roles.
     */
    public function nivelJerarquia(): int
    {
        if ($this->es_superadmin) {
            return 0;
        }

        return (int) ($this->roles()->where('roles.activo', true)->min('nivel_jerarquia') ?? 65535);
    }

    /**
     * Iniciales para el avatar (primera letra de las dos primeras palabras).
     */
    public function iniciales(): string
    {
        $palabras = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        return mb_strtoupper(mb_substr($palabras[0] ?? '', 0, 1).mb_substr($palabras[1] ?? '', 0, 1));
    }

    /**
     * Rol que se muestra junto al nombre: el de mayor jerarquia.
     */
    public function nombreRolPrincipal(): string
    {
        if ($this->es_superadmin) {
            return 'Super Administrador';
        }

        return $this->roles()->where('roles.activo', true)->orderBy('nivel_jerarquia')->value('nombre') ?? 'Personal';
    }

    public function estaBloqueado(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }
}
