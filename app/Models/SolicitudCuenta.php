<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Pedir a mi administrador que restablezca mi contraseña o mis dos pasos»
 * (C-36; docs/tecnico/usuarios.md). Cae en «Cuentas por revisar» de quien
 * administra esa cuenta. empresa_id nulo = cuenta de Super Administrador: la
 * atiende otro Super Administrador.
 */
class SolicitudCuenta extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const CONTRASENA = 'contrasena';

    public const DOS_PASOS = 'dos_pasos';

    public const TIPOS = [
        self::CONTRASENA => 'restablecer su contraseña',
        self::DOS_PASOS => 'restablecer su verificación en dos pasos',
    ];

    public const PENDIENTE = 'pendiente';

    public const ATENDIDA = 'atendida';

    public const RECHAZADA = 'rechazada';

    public const ESTADOS = [self::PENDIENTE => 'Por atender', self::ATENDIDA => 'Atendida', self::RECHAZADA => 'Rechazada'];

    protected $table = 'solicitudes_cuenta';

    protected $fillable = ['empresa_id', 'user_id', 'tipo', 'origen'];

    protected $attributes = ['estado' => self::PENDIENTE];

    protected function casts(): array
    {
        return ['atendida_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
