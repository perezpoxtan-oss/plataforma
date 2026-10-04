<?php

namespace App\Support;

use App\Models\ConfiguracionPlataforma;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre, logos y colores de la plataforma. Se configuran desde la interfaz
 * (Identidad de la plataforma) y no en codigo.
 */
class Identidad
{
    public const VALORES_POR_DEFECTO = [
        'nombre' => 'Plataforma',
        'nombre_corto' => 'Plataforma',
        'eslogan' => 'Sistema de Seguridad e Infraestructura',
        // Quien opera la plataforma (aparece en el pie: "(c) 2026 VDCP")
        'titular' => 'VDCP',
        'logo_claro' => null,
        'logo_oscuro' => null,
        'simbolo' => null,
        'favicon' => null,
        'color_primario' => '#2563eb',
        'color_acento' => '#0ea5e9',
        'correo_soporte' => null,
        'telefono_soporte' => null,
    ];

    /** @var array<string, mixed>|null */
    private ?array $valores = null;

    public function get(string $clave): mixed
    {
        return $this->todos()[$clave] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function todos(): array
    {
        if ($this->valores !== null) {
            return $this->valores;
        }

        $guardados = [];
        if (Schema::hasTable('configuracion_plataforma')) {
            $guardados = ConfiguracionPlataforma::where('clave', 'identidad')->value('valor') ?? [];
        }

        $valores = array_merge(self::VALORES_POR_DEFECTO, array_filter(
            $guardados,
            fn ($valor) => $valor !== null && $valor !== '',
        ));

        // Los colores van dentro de CSS: solo se aceptan en formato #rrggbb
        foreach (['color_primario', 'color_acento'] as $color) {
            if (! is_string($valores[$color]) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $valores[$color])) {
                $valores[$color] = self::VALORES_POR_DEFECTO[$color];
            }
        }

        return $this->valores = $valores;
    }

    /**
     * @param  array<string, mixed>  $valores
     */
    public function guardar(array $valores): void
    {
        $permitidos = array_intersect_key($valores, self::VALORES_POR_DEFECTO);
        $actual = ConfiguracionPlataforma::firstOrNew(['clave' => 'identidad']);
        $actual->valor = array_merge($actual->valor ?? [], $permitidos);
        $actual->save();

        $this->valores = null;
    }
}
