<?php

namespace Database\Seeders;

use App\Models\Rubro;
use Illuminate\Database\Seeder;

class RubrosSeeder extends Seeder
{
    public function run(): void
    {
        $rubros = [
            'hotel' => ['Hotel', ['sede' => 'Sede', 'sedes' => 'Sedes', 'area_especifica' => 'Habitación', 'visitante' => 'Huésped']],
            'corporativo' => ['Corporativo / industria', ['sede' => 'Sede', 'sedes' => 'Sedes', 'area_especifica' => 'Oficina', 'visitante' => 'Visitante']],
            'condominio' => ['Condominio', ['sede' => 'Sede', 'sedes' => 'Sedes', 'area_especifica' => 'Departamento', 'visitante' => 'Visita', 'colaborador' => 'Residente']],
            'fraccionamiento' => ['Fraccionamiento', ['sede' => 'Sede', 'sedes' => 'Sedes', 'area_especifica' => 'Casa', 'visitante' => 'Visita', 'colaborador' => 'Residente']],
        ];

        foreach ($rubros as $clave => [$nombre, $terminologia]) {
            Rubro::updateOrCreate(['clave' => $clave], ['nombre' => $nombre, 'terminologia' => $terminologia]);
        }
    }
}
