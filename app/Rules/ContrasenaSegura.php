<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reglas extra de contraseña (además de 8+ caracteres con letras y números):
 *  - máximo 72 bytes: el cifrado (bcrypt) ignora lo que pase de ahí;
 *  - no puede ser una de las contraseñas más usadas;
 *  - no puede contener el nombre de usuario ni el correo.
 */
class ContrasenaSegura implements ValidationRule
{
    /** Contraseñas muy comunes que sí cumplen "letras y números" (en minúsculas). */
    public const COMUNES = [
        'password1', 'password12', 'password123', 'passw0rd', 'p4ssw0rd', 'qwerty123', 'qwerty12', 'qwerty1',
        'abc12345', 'abcd1234', 'abc123456', 'a1234567', 'a12345678', '1234567a', '12345678a', '123456789a',
        '1q2w3e4r', '1q2w3e4r5t', '1qaz2wsx', 'zaq12wsx', 'q1w2e3r4', 'admin123', 'admin1234', 'administrador1',
        'test1234', 'prueba123', 'prueba1234', 'demo1234', 'temporal1', 'temporal123', 'cambiar123', 'cambiame1',
        'contrasena1', 'contrasena123', 'contraseña1', 'contraseña123', 'usuario1', 'usuario123', 'clave123',
        'seguridad1', 'seguridad123', 'guardia123', 'hotel123', 'hotel1234', 'mexico123', 'cancun123',
        'welcome1', 'iloveyou1', 'letmein1', 'monkey123', 'dragon123', 'master123', 'sunshine1', 'trustno1',
    ];

    public function __construct(
        private readonly ?string $usuario = null,
        private readonly ?string $correo = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (strlen($value) > 72) {
            $fail('La :attribute es demasiado larga (máximo 72 caracteres).');

            return;
        }

        $minusculas = mb_strtolower($value);

        if (in_array($minusculas, self::COMUNES, true)) {
            $fail('Esa :attribute es de las más usadas y fácil de adivinar; elige otra.');

            return;
        }

        foreach ([$this->usuario, strstr((string) $this->correo, '@', true) ?: null] as $dato) {
            $dato = mb_strtolower(trim((string) $dato));
            if (mb_strlen($dato) >= 4 && str_contains($minusculas, $dato)) {
                $fail('La :attribute no puede contener el nombre de usuario ni el correo.');

                return;
            }
        }
    }
}
