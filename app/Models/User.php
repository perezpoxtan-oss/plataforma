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

    public function estaBloqueado(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }
}
