<?php

namespace Database\Seeders;

use App\Models\Rubro;
use Illuminate\Database\Seeder;

class RubrosSeeder extends Seeder
{
    public function run(): void
    {
        $rubros = [
            'hotel' => ['Hotel', ['sede' => 'Hotel', 'sedes' => 'Hoteles', 'area_especifica' => 'Habitación', 'visitante' => 'Huésped']],
            'corporativo' => ['Corporativo / industria', ['sede' => 'Planta', 'sedes' => 'Plantas', 'area_especifica' => 'Oficina', 'visitante' => 'Visitante']],
            'condominio' => ['Condominio', ['sede' => 'Torre', 'sedes' => 'Torres', 'area_especifica' => 'Departamento', 'visitante' => 'Visita', 'colaborador' => 'Residente']],
            'fraccionamiento' => ['Fraccionamiento', ['sede' => 'Privada', 'sedes' => 'Privadas', 'area_especifica' => 'Casa', 'visitante' => 'Visita', 'colaborador' => 'Residente']],
        ];

        foreach ($rubros as $clave => [$nombre, $terminologia]) {
            Rubro::updateOrCreate(['clave' => $clave], ['nombre' => $nombre, 'terminologia' => $terminologia]);
        }
    }
}
