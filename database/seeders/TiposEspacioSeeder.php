<?php

namespace Database\Seeders;

use App\Models\Espacio;
use App\Models\TipoEspacio;
use Illuminate\Database\Seeder;

/**
 * Tipos base del sistema por nivel. Solo agrega los que falten; cada empresa
 * puede sumar los suyos desde la pantalla.
 */
class TiposEspacioSeeder extends Seeder
{
    public const TIPOS = [
        Espacio::EDIFICIO => ['Edificio', 'Torre', 'Zona exterior', 'Estacionamiento'],
        Espacio::AREA => ['Piso', 'Sótano', 'Planta baja', 'Azotea', 'Área común'],
        Espacio::AREA_ESPECIFICA => ['Habitación', 'Suite', 'Oficina', 'Bodega', 'Lobby', 'Restaurante', 'Alberca', 'Cuarto de máquinas'],
        Espacio::SUBAREA => ['Recámara', 'Baño', 'Sala', 'Comedor', 'Cocina', 'Terraza', 'Balcón', 'Clóset', 'Vestidor'],
        Espacio::ELEMENTO => ['Cama', 'Lavabo', 'Regadera', 'Inodoro', 'Minibar', 'Caja fuerte', 'Televisión', 'Aire acondicionado', 'Cafetera', 'Secadora de cabello', 'Extintor', 'Detector de humo', 'Rociador', 'Lámpara'],
    ];

    public function run(): void
    {
        foreach (self::TIPOS as $nivel => $nombres) {
            foreach ($nombres as $nombre) {
                TipoEspacio::firstOrCreate(['empresa_id' => null, 'nivel' => $nivel, 'nombre' => $nombre]);
            }
        }
    }
}
