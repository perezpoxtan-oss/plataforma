<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Espacio;
use App\Models\Novedad;
use App\Models\User;
use App\Models\ValoresVistaApertura;
use App\Models\ValoresVistaDetalle;
use App\Models\ValoresVistaPersona;
use App\Models\ValoresVistaZona;

/**
 * Valores a la Vista (SEGCAT: categoría HABITACION, frag_habitacion.php):
 * identificación de la habitación, personal involucrado, puertas/ventanas/
 * terrazas, caja fuerte y valores a la vista por zona.
 */
class ValoresVista extends Formato
{
    public const TIPOS_APERTURA = ['puerta' => 'Puerta', 'ventana' => 'Ventana', 'terraza' => 'Terraza'];

    /** La casilla "especial" de cada tipo (SEGCAT). */
    public const ESPECIAL_APERTURA = ['puerta' => 'De conexión', 'ventana' => 'De baño', 'terraza' => 'De baño'];

    public const ZONAS = ['Recámara', 'Sala', 'Baño', 'Terraza', 'Cocina', 'Otro'];

    public function relaciones(): array
    {
        return ['valoresVista', 'valoresVistaPersonas', 'valoresVistaAperturas', 'valoresVistaZonas'];
    }

    public function valores(Novedad $novedad): array
    {
        $d = $novedad->valoresVista;
        if ($d === null) {
            // Primera vez: se sugieren los datos del reporte inicial (el guardia puede corregirlos)
            $colaborador = $novedad->reportadoColaborador;

            return [
                'hab_id_area_especifica' => $novedad->area_especifica_id, 'hab_numero' => null,
                'hab_quien_reporta' => $novedad->reportado_por,
                'hab_depto_reporta' => $colaborador?->departamento?->nombre, 'hab_puesto_reporta' => $colaborador?->puesto?->nombre,
                'hab_actividad_reporta' => null,
                'hab_quien_atiende' => $novedad->asignado?->name, 'hab_depto_atiende' => 'Seguridad', 'hab_puesto_atiende' => null,
                'hab_personas' => [], 'hab_aperturas' => [], 'hab_caja_estado' => 'cerrada', 'hab_caja_accion' => null, 'hab_valores_dentro' => null,
                'hab_valores' => [], 'hab_personal_retiro' => 'si', 'hab_cliente_llega' => '0', 'hab_tomo_fotos' => 'si', 'hab_observaciones_grales' => null,
            ];
        }

        return [
            'hab_id_area_especifica' => $d->area_especifica_id, 'hab_numero' => $d->num_habitacion,
            'hab_quien_reporta' => $d->quien_reporta, 'hab_depto_reporta' => $d->depto_reporta, 'hab_puesto_reporta' => $d->puesto_reporta,
            'hab_actividad_reporta' => $d->actividad_reporta,
            'hab_quien_atiende' => $d->quien_atiende, 'hab_depto_atiende' => $d->depto_atiende, 'hab_puesto_atiende' => $d->puesto_atiende,
            'hab_personas' => $novedad->valoresVistaPersonas->map(fn ($p) => [
                'nombre' => $p->nombre, 'departamento' => $p->departamento, 'puesto' => $p->puesto, 'actividad' => $p->actividad, 'se_retira' => $p->se_retira,
            ])->all(),
            'hab_aperturas' => $novedad->valoresVistaAperturas->map(fn ($a) => [
                'tipo' => $a->tipo, 'estado' => $a->estado, 'es_especial' => $a->es_especial, 'descripcion' => $a->descripcion,
            ])->all(),
            'hab_caja_estado' => $d->caja_fuerte, 'hab_caja_accion' => $d->caja_accion, 'hab_valores_dentro' => $d->valores_dentro,
            'hab_valores' => $novedad->valoresVistaZonas->map(fn ($z) => ['zona' => $z->zona, 'descripcion' => $z->descripcion])->all(),
            'hab_personal_retiro' => $d->personal_retiro ?? 'si', 'hab_cliente_llega' => $d->cliente_llega ? '1' : '0',
            'hab_tomo_fotos' => $d->tomo_fotos ?? 'si', 'hab_observaciones_grales' => $d->observaciones,
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $this->revisar($entrada, [
            'hab_id_area_especifica' => ['nullable', 'integer'],
            'hab_numero' => ['nullable', 'string', 'max:30'],
            'hab_quien_reporta' => ['nullable', 'string', 'max:150'], 'hab_depto_reporta' => ['nullable', 'string', 'max:100'],
            'hab_puesto_reporta' => ['nullable', 'string', 'max:100'], 'hab_actividad_reporta' => ['nullable', 'string', 'max:255'],
            'hab_quien_atiende' => ['nullable', 'string', 'max:150'], 'hab_depto_atiende' => ['nullable', 'string', 'max:100'], 'hab_puesto_atiende' => ['nullable', 'string', 'max:100'],
            'hab_personas' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'hab_personas.*.nombre' => ['nullable', 'string', 'max:150'], 'hab_personas.*.departamento' => ['nullable', 'string', 'max:100'],
            'hab_personas.*.puesto' => ['nullable', 'string', 'max:100'], 'hab_personas.*.actividad' => ['nullable', 'string', 'max:255'],
            'hab_aperturas' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'hab_aperturas.*.tipo' => ['required', 'in:'.implode(',', array_keys(self::TIPOS_APERTURA))],
            'hab_aperturas.*.estado' => ['nullable', 'in:abierta,cerrada'], 'hab_aperturas.*.descripcion' => ['nullable', 'string', 'max:150'],
            'hab_caja_estado' => ['nullable', 'in:'.implode(',', array_keys(ValoresVistaDetalle::CAJA_FUERTE))],
            'hab_caja_accion' => ['nullable', 'in:'.implode(',', array_keys(ValoresVistaDetalle::CAJA_ACCION))],
            'hab_valores_dentro' => ['nullable', 'string', 'max:5000'],
            'hab_valores' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'hab_valores.*.zona' => ['required', 'in:'.implode(',', self::ZONAS)], 'hab_valores.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'hab_personal_retiro' => ['nullable', 'in:si,no'], 'hab_cliente_llega' => ['nullable', 'in:0,1'], 'hab_tomo_fotos' => ['nullable', 'in:si,no'],
            'hab_observaciones_grales' => ['nullable', 'string', 'max:5000'],
        ], [
            'hab_id_area_especifica' => 'Número de Habitación', 'hab_numero' => 'Habitación (si no está en el catálogo)',
            'hab_quien_reporta' => 'Quién Reporta', 'hab_depto_reporta' => 'Departamento (Reporta)', 'hab_puesto_reporta' => 'Puesto (Reporta)',
            'hab_actividad_reporta' => 'Actividad que realiza(ba) dentro de la habitación', 'hab_quien_atiende' => 'Nombre del Agente',
            'hab_depto_atiende' => 'Depto (Atiende)', 'hab_puesto_atiende' => 'Puesto (Atiende)', 'hab_personas' => 'Personas en la habitación',
            'hab_personas.*.nombre' => 'Nombre', 'hab_personas.*.departamento' => 'Depto', 'hab_personas.*.puesto' => 'Puesto', 'hab_personas.*.actividad' => 'Actividad',
            'hab_aperturas' => 'Puertas, Ventanas y Terrazas', 'hab_aperturas.*.tipo' => 'Tipo', 'hab_aperturas.*.estado' => 'Estado', 'hab_aperturas.*.descripcion' => 'Descripción',
            'hab_caja_estado' => 'Estado de la Caja Fuerte', 'hab_caja_accion' => '¿Cómo se dejó después de la inspección?',
            'hab_valores_dentro' => 'Valores encontrados dentro de la caja fuerte', 'hab_valores' => 'Valores a la vista, por zona',
            'hab_valores.*.zona' => 'Zona', 'hab_valores.*.descripcion' => 'Descripción de los valores',
            'hab_personal_retiro' => '¿El personal se retiró al terminar?', 'hab_cliente_llega' => '¿Cliente llega durante los valores?',
            'hab_tomo_fotos' => '¿Se tomaron fotografías?', 'hab_observaciones_grales' => 'Condiciones generales, observaciones y notificaciones',
        ]);

        $t = fn (string $campo, bool $mayus = false) => $this->texto($entrada[$campo] ?? null, $mayus);
        $caja = $entrada['hab_caja_estado'] ?? 'cerrada';
        $conValores = $caja === 'abierta_con_valores';

        return [
            'detalle' => [
                'area_especifica_id' => $this->habitacionValida($entrada['hab_id_area_especifica'] ?? null, $novedad),
                'num_habitacion' => $t('hab_numero', true),
                'quien_reporta' => $t('hab_quien_reporta', true), 'depto_reporta' => $t('hab_depto_reporta', true), 'puesto_reporta' => $t('hab_puesto_reporta', true),
                'actividad_reporta' => $t('hab_actividad_reporta'),
                'quien_atiende' => $t('hab_quien_atiende', true), 'depto_atiende' => $t('hab_depto_atiende', true), 'puesto_atiende' => $t('hab_puesto_atiende', true),
                'caja_fuerte' => $caja,
                // Acción y valores dentro solo aplican si la caja estaba abierta con valores
                'caja_accion' => $conValores ? ($entrada['hab_caja_accion'] ?? null ?: null) : null,
                'valores_dentro' => $conValores ? $t('hab_valores_dentro') : null,
                'personal_retiro' => $entrada['hab_personal_retiro'] ?? 'si',
                'cliente_llega' => $this->siNo($entrada['hab_cliente_llega'] ?? '0'),
                'tomo_fotos' => $entrada['hab_tomo_fotos'] ?? 'si',
                'observaciones' => $t('hab_observaciones_grales'),
            ],
            'personas' => array_map(fn ($p) => [
                'nombre' => $this->texto($p['nombre'], true), 'departamento' => $this->texto($p['departamento'] ?? null, true),
                'puesto' => $this->texto($p['puesto'] ?? null, true), 'actividad' => $this->texto($p['actividad'] ?? null),
                'se_retira' => $this->siNo($p['se_retira'] ?? null),
            ], $this->filas($entrada['hab_personas'] ?? [], 'nombre')),
            'aperturas' => array_map(fn ($a) => [
                'tipo' => $a['tipo'], 'estado' => ($a['estado'] ?? 'cerrada') ?: 'cerrada',
                'es_especial' => $this->siNo($a['es_especial'] ?? null), 'descripcion' => $this->texto($a['descripcion'] ?? null, true),
            ], array_values(array_filter((array) ($entrada['hab_aperturas'] ?? []), 'is_array'))),
            'zonas' => array_map(fn ($z) => ['zona' => $z['zona'], 'descripcion' => $this->texto($z['descripcion'])],
                $this->filas($entrada['hab_valores'] ?? [], 'descripcion')),
        ];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        $id = ['novedad_id' => $novedad->id];
        ValoresVistaDetalle::updateOrCreate($id, $datos['detalle']);

        // Las listas se reemplazan completas: queda exactamente lo que hay en pantalla
        $novedad->valoresVistaPersonas()->delete();
        foreach ($datos['personas'] as $p) {
            ValoresVistaPersona::create($id + $p);
        }
        $novedad->valoresVistaAperturas()->delete();
        foreach ($datos['aperturas'] as $a) {
            ValoresVistaApertura::create($id + $a);
        }
        $novedad->valoresVistaZonas()->delete();
        foreach ($datos['zonas'] as $z) {
            ValoresVistaZona::create($id + $z);
        }
    }

    /**
     * La habitación debe ser un área específica de la misma sede y, si el
     * ticket ya tiene Área General, colgar de ella (SEGCAT: "debe pertenecer a
     * la Sección del propio ticket").
     */
    private function habitacionValida(mixed $id, Novedad $novedad): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $id = (int) $id;
        if ($id === (int) $novedad->valoresVista?->area_especifica_id) {
            return $id;
        }
        $habitacion = Espacio::where('nivel', Espacio::AREA_ESPECIFICA)->where('sede_id', $novedad->sede_id)->find($id);
        if ($habitacion === null || ($novedad->area_id !== null && ! str_contains((string) $habitacion->ruta, '/'.$novedad->area_id.'/'))) {
            $this->error('hab_id_area_especifica', 'La habitación elegida no pertenece al Área General del ticket. Revisa el edificio y piso arriba.');
        }

        return $id;
    }
}
