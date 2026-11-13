<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedido del dueño (13 nov 2026): nombres de fábrica más cortos en el menú y
 * en los títulos de tres módulos. Solo cambia nombres que se ven; claves,
 * rutas, permisos, tablas y datos quedan igual.
 *
 *  - recorridos_pc:    «Recorridos de Protección Civil» → «Recorridos»
 *  - equipos_pc:       «Equipos de Protección Civil»    → «Equipos contra incendios»
 *  - estacionamientos: «Estacionamientos» / menú «Estacionamientos y zonas de
 *                      descarga» → «Estacionamiento y almacenes» (nombre y menú)
 *
 * Solo se cambia lo que sigue con el nombre de fábrica anterior: si alguien
 * ya lo había cambiado en la base, se respeta. El nombre que cada empresa
 * pone en Configuración → General → Nombres en el menú
 * (empresa_modulos.nombre_visible) no se toca y sigue mandando.
 *
 * down() regresa los nombres anteriores.
 */
return new class extends Migration
{
    /** clave => [columna => [anterior, nuevo]] */
    private const CAMBIOS = [
        'recorridos_pc' => ['nombre' => ['Recorridos de Protección Civil', 'Recorridos']],
        'equipos_pc' => ['nombre' => ['Equipos de Protección Civil', 'Equipos contra incendios']],
        'estacionamientos' => [
            'nombre' => ['Estacionamientos', 'Estacionamiento y almacenes'],
            'nombre_menu' => ['Estacionamientos y zonas de descarga', 'Estacionamiento y almacenes'],
        ],
    ];

    public function up(): void
    {
        $this->aplicar(0, 1);
    }

    public function down(): void
    {
        $this->aplicar(1, 0);
    }

    private function aplicar(int $desde, int $hacia): void
    {
        foreach (self::CAMBIOS as $clave => $columnas) {
            foreach ($columnas as $columna => $valores) {
                DB::table('modulos')->where('clave', $clave)->where($columna, $valores[$desde])
                    ->update([$columna => $valores[$hacia], 'updated_at' => now()]);
            }
        }
    }
};
