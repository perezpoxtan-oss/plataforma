<?php

namespace App\Providers;

use App\Models\Colaborador;
use App\Models\Credencial;
use App\Services\EquiposAcceso\SincronizacionUsuarios;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Decisión 10: lo que cambia en la credencial o en el colaborador se pone en
 * la cola de los equipos que aceptan altas y bajas (ZKTeco), sin tocar
 * Credenciales ni Colaboradores: se escuchan los eventos de sus modelos.
 * Un error aquí nunca impide guardar (se reporta). Ver docs/tecnico/equipos-acceso.md.
 */
class EquiposAccesoServiceProvider extends ServiceProvider
{
    /** Campos del colaborador que cambian lo que el equipo debe tener. */
    public const CAMPOS_COLABORADOR = ['activo', 'etiqueta_nfc', 'num_empleado', 'sede_id', 'fusionado_en_id'];

    public function boot(): void
    {
        Credencial::saved(function (Credencial $c): void {
            if ($c->wasRecentlyCreated || $c->wasChanged(['estado', 'vence_en'])) {
                self::sincronizar((int) $c->colaborador_id);
            }
        });
        Colaborador::updated(function (Colaborador $c): void {
            if ($c->wasChanged(self::CAMPOS_COLABORADOR)) {
                self::sincronizar($c);
            }
        });
    }

    private static function sincronizar(Colaborador|int $colaborador): void
    {
        try {
            app(SincronizacionUsuarios::class)->colaborador($colaborador);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
