<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;
use App\Models\RecorridoPcPunto;
use App\Models\User;

/**
 * Recorrido PC (SEGCAT: RECORRIDO_PC, frag_recorrido_pc.php). Ya no se crea
 * desde la bitácora (tiene su propio módulo, Recorridos de Protección Civil):
 * solo se conserva para ver y completar los tickets que ya la traían.
 */
class RecorridoPc extends Formato
{
    /** categoría => [texto, [clave => pieza]] (las de SEGCAT, mismo orden). */
    public const CATEGORIAS = [
        'EXTINTOR' => ['1. Extintores', ['cilindro' => 'Cilindro / Cuerpo', 'manometro' => 'Manómetro', 'manguera' => 'Manguera', 'boquilla' => 'Boquilla / Tobera', 'pasador' => 'Pasador de seguridad', 'precinto' => 'Precinto', 'collarin' => 'Collarín servicio', 'etiqueta' => 'Etiqueta inst.', 'senializacion' => 'Señal visual']],
        'HIDRANTE' => ['2. Hidrantes', ['gabinete' => 'Gabinete (Caja)', 'cristal' => 'Cristal / Puerta', 'valvula' => 'Válvula apertura', 'manguera' => 'Manguera', 'chiflon' => 'Chiflón / Pitón', 'llave' => 'Llave apriete', 'manometro' => 'Manómetro', 'senial' => 'Señalización']],
        'ESTACION_MANUAL' => ['3. Estaciones Manuales / Alarmas', ['carcasa' => 'Carcasa exterior', 'mica' => 'Mica protectora', 'palanca' => 'Palanca / Botón', 'estrobo' => 'Estrobo luminoso', 'sirena' => 'Sirena acústica', 'senial' => 'Señalización']],
        'DETECTOR_HUMO' => ['4. Detectores de Humo', ['base' => 'Base / Soporte', 'cuerpo' => 'Cuerpo detector', 'led' => 'Indicador LED', 'senial' => 'Señalización']],
        'MANTA_ANTIFLAMA' => ['5. Mantas Antiflamas (Ignífugas)', ['contenedor' => 'Contenedor/Funda', 'tirantes' => 'Tirantes extra.', 'tela' => 'Tela Ignífuga', 'senial' => 'Señalización']],
        'SALIDA_EMERGENCIA' => ['6. Salidas / Rutas de Emergencia', ['puerta' => 'Puerta Abatible', 'bisagras' => 'Bisagras OK', 'barra' => 'Barra Antipánico', 'brazo' => 'Brazo cierrapta.', 'obstaculos' => 'Área despejada', 'letrero' => 'Letrero luminoso']],
        'ASPERSORES' => ['7. Aspersores (Sprinklers)', ['cuerpo' => 'Rociador metal', 'bulbo' => 'Bulbo térmico', 'deflector' => 'Plato deflector', 'tuberia' => 'Tubería abasto', 'chapeton' => 'Chapetón']],
        'RACK_BOMBEROS' => ['8. Rack de Bomberos / E.P.P.', ['estructura' => 'Rack físico', 'casco' => 'Casco', 'monja' => 'Monja/Escafandra', 'chaqueton' => 'Chaquetón', 'pantalon' => 'Pantalón', 'tirantes' => 'Tirantes pant.', 'botas' => 'Botas incendio', 'guantes' => 'Guantes', 'hacha' => 'Hacha de pico', 'halliburton' => 'Barra Halliburton', 'scba_cilindro' => 'Cilindro SCBA', 'scba_mascarilla' => 'Mascarilla SCBA']],
        'BOTIQUIN' => ['9. Botiquines de Primeros Auxilios', ['gabinete' => 'Gabinete/Maletín', 'sello' => 'Sello seguridad', 'gasas' => 'Gasas', 'vendas' => 'Vendas elásticas', 'apositos' => 'Apósitos', 'cinta' => 'Cinta adhesiva', 'tijeras' => 'Tijeras uso rudo', 'antiseptico' => 'Solución', 'senial' => 'Señalización']],
        'SENALETICA' => ['10. Señaléticas de Seguridad', ['anclaje' => 'Fijación/Anclaje', 'letrero' => 'Acrílico visible', 'fotoluminiscencia' => 'Fotoluminiscente']],
        'GAS_LP' => ['11. Tanques y Red de Gas L.P.', ['tanque' => 'Tanque/Cilindro', 'valvula_servicio' => 'Válvula servicio', 'valvula_emergencia' => 'Válvula emergencia', 'manometro' => 'Manómetro', 'tuberia' => 'Tubería/Ductería', 'pintura_normativa' => 'Pintura normativa', 'base' => 'Base/Zapata', 'senial' => 'Señalización']],
        'TABLERO_ELECTRICO' => ['12. Tableros y Red Eléctrica', ['tapa' => 'Tapa frontal', 'empaque' => 'Empaque/Sello', 'directorio' => 'Directorio circuitos', 'pastillas' => 'Pastillas termomag.', 'riel' => 'Riel de montaje', 'cableado' => 'Cableado interno', 'senial' => 'Señalización']],
        'LAMPARA_EMERGENCIA' => ['13. Lámparas de Emergencia', ['carcasa' => 'Carcasa Principal', 'foco_izq' => 'Faro Izquierdo', 'foco_der' => 'Faro Derecho', 'boton' => 'Botón de Prueba', 'cable' => 'Enchufe a pared']],
    ];

    /** Criterios Operativos Universales (se suman a los de cada categoría). */
    public const UNIVERSALES = [
        'visible' => 'Totalmente visible (Línea de visión)',
        'accesible' => 'Totalmente accesible (Sin bloqueos)',
        'estado_gral' => 'Estado general funcional (Operativo)',
    ];

    public function relaciones(): array
    {
        return ['recorridoPuntos'];
    }

    public function valores(Novedad $novedad): array
    {
        return ['rpc_puntos' => $novedad->recorridoPuntos->map(fn (RecorridoPcPunto $p) => [
            'identificador' => $p->identificador, 'categoria' => $p->categoria, 'edificio' => $p->edificio, 'nivel' => $p->nivel,
            'area' => $p->area, 'criterios' => array_keys(array_filter((array) $p->criterios)), 'observaciones' => $p->observaciones,
        ])->all()];
    }

    /** Piezas a revisar de una categoría (incluye las universales). */
    public static function piezas(string $categoria): array
    {
        return isset(self::CATEGORIAS[$categoria]) ? self::CATEGORIAS[$categoria][1] + self::UNIVERSALES : [];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $this->revisar($entrada, [
            'rpc_puntos' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'rpc_puntos.*.identificador' => ['nullable', 'string', 'max:100'],
            'rpc_puntos.*.categoria' => ['nullable', 'in:'.implode(',', array_keys(self::CATEGORIAS))],
            'rpc_puntos.*.edificio' => ['nullable', 'string', 'max:100'], 'rpc_puntos.*.nivel' => ['nullable', 'string', 'max:50'],
            'rpc_puntos.*.area' => ['nullable', 'string', 'max:150'], 'rpc_puntos.*.criterios' => ['nullable', 'array'],
            'rpc_puntos.*.observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'rpc_puntos' => 'Puntos de Inspección', 'rpc_puntos.*.identificador' => 'Lector ID / Escáner QR', 'rpc_puntos.*.categoria' => 'Categoría del Equipo',
            'rpc_puntos.*.edificio' => 'Edificio / Torre', 'rpc_puntos.*.nivel' => 'Nivel / Piso', 'rpc_puntos.*.area' => 'Área / Ubicación',
            'rpc_puntos.*.observaciones' => 'Observaciones / Desperfectos Encontrados',
        ]);

        $puntos = [];
        foreach ($this->filas($entrada['rpc_puntos'] ?? [], 'identificador') as $n => $p) {
            $categoria = $p['categoria'] ?? '';
            if ($categoria === '') {
                $this->error('rpc_puntos', 'Elige la Categoría del Equipo del punto de inspección #'.($n + 1).'.');
            }
            $marcadas = array_map('strval', array_keys(array_filter((array) ($p['criterios'] ?? []))));
            $criterios = [];
            foreach (array_keys(self::piezas($categoria)) as $clave) {
                $criterios[$clave] = in_array($clave, $marcadas, true);
            }
            $puntos[] = [
                'identificador' => $this->texto($p['identificador'], true), 'categoria' => $categoria,
                'edificio' => $this->texto($p['edificio'] ?? null, true), 'nivel' => $this->texto($p['nivel'] ?? null, true),
                'area' => $this->texto($p['area'] ?? null, true), 'criterios' => $criterios, 'observaciones' => $this->texto($p['observaciones'] ?? null),
            ];
        }

        return ['puntos' => $puntos];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        $novedad->recorridoPuntos()->delete();
        foreach ($datos['puntos'] as $p) {
            RecorridoPcPunto::create(['novedad_id' => $novedad->id] + $p);
        }
    }
}
