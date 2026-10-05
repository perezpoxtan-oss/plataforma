<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;
use App\Models\SiniestroDano;
use App\Models\SiniestroDetalle;
use App\Models\SiniestroEquipo;
use App\Models\SiniestroServicio;
use App\Models\User;

/**
 * Siniestro Protección Civil (SEGCAT: PROTECCION_CIVIL, frag_proteccion_civil.php).
 * El ticket de Accidente ligado (cuando hubo lesionados) lo abre
 * AdministradorNovedades después de guardar, una sola vez.
 */
class Siniestro extends Formato
{
    public function relaciones(): array
    {
        return ['siniestro.accidente:id,numero', 'siniestroServicios', 'siniestroEquipos', 'siniestroDanos', 'testigos'];
    }

    public function valores(Novedad $novedad): array
    {
        $s = $novedad->siniestro;
        $servicios = [];
        foreach (array_keys(SiniestroDetalle::SERVICIOS) as $i => $servicio) {
            $guardado = $novedad->siniestroServicios->firstWhere('servicio', $servicio);
            $servicios[$i] = ['activo' => $guardado !== null, 'hora' => $guardado?->hora_llegada ? substr((string) $guardado->hora_llegada, 0, 5) : null];
        }

        return [
            'pc_tipo_evento' => $s?->tipo_evento ?? 'CONATO DE INCENDIO', 'pc_descripcion_otro' => $s?->descripcion_otro,
            'pc_fecha_control' => $novedad->localParaCampo($s?->controlado_en),
            'pc_alarma' => $s?->alarma_activada ? '1' : '0', 'pc_evacuacion' => $s?->requiere_evacuacion ? '1' : '0',
            'pc_num_evacuados' => $s?->num_evacuados, 'pc_punto_reunion' => $s?->punto_reunion,
            'pc_servicios' => $servicios,
            'pc_hubo_lesionados' => $s?->hubo_lesionados ? '1' : '0', 'pc_num_lesionados' => $s?->num_lesionados,
            'siniestro_equipos' => $novedad->siniestroEquipos->map(fn ($e) => ['identificador' => $e->identificador, 'estado_uso' => $e->estado_uso])->all(),
            'siniestro_danos' => $novedad->siniestroDanos->map(fn ($d) => ['zona' => $d->zona, 'descripcion' => $d->descripcion])->all(),
            'siniestro_testigos' => $this->testigosGuardados($novedad, 'proteccion_civil'),
            'pc_causa_probable' => $s?->causa_probable, 'pc_acciones_tomadas' => $s?->acciones_tomadas,
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $this->revisar($entrada, [
            'pc_tipo_evento' => ['required', 'in:'.implode(',', array_keys(SiniestroDetalle::TIPOS))],
            'pc_descripcion_otro' => ['nullable', 'string', 'max:150'],
            'pc_fecha_control' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'pc_alarma' => ['nullable', 'in:0,1'], 'pc_evacuacion' => ['nullable', 'in:0,1'],
            'pc_num_evacuados' => ['nullable', 'integer', 'min:0', 'max:100000'], 'pc_punto_reunion' => ['nullable', 'string', 'max:150'],
            'pc_servicios' => ['nullable', 'array'], 'pc_servicios.*.hora' => ['nullable', 'date_format:H:i'],
            'pc_hubo_lesionados' => ['nullable', 'in:0,1'],
            'pc_num_lesionados' => ['nullable', 'required_if:pc_hubo_lesionados,1', 'integer', 'min:1', 'max:10000'],
            'siniestro_equipos' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'siniestro_equipos.*.identificador' => ['nullable', 'string', 'max:100'], 'siniestro_equipos.*.estado_uso' => ['nullable', 'in:utilizado,danado'],
            'siniestro_danos' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'siniestro_danos.*.zona' => ['nullable', 'string', 'max:100'], 'siniestro_danos.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'siniestro_testigos' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'siniestro_testigos.*.nombre' => ['nullable', 'string', 'max:150'], 'siniestro_testigos.*.departamento' => ['nullable', 'string', 'max:100'],
            'pc_causa_probable' => ['nullable', 'string', 'max:5000'], 'pc_acciones_tomadas' => ['nullable', 'string', 'max:5000'],
        ], [
            'pc_tipo_evento' => 'Clasificación del Evento', 'pc_descripcion_otro' => 'Describe el tipo de evento',
            'pc_fecha_control' => 'Fecha y Hora en que se controló', 'pc_alarma' => '¿Se activó la alarma de Protección Civil?',
            'pc_evacuacion' => '¿Requiere / requirió evacuación?', 'pc_num_evacuados' => 'Personas evacuadas', 'pc_punto_reunion' => 'Punto de reunión utilizado',
            'pc_servicios.*.hora' => 'Hora de llegada', 'pc_hubo_lesionados' => '¿Hubo lesionados?', 'pc_num_lesionados' => '¿Cuántos?',
            'siniestro_equipos' => 'Equipos de Protección Civil Involucrados', 'siniestro_equipos.*.identificador' => 'Identificador del equipo',
            'siniestro_equipos.*.estado_uso' => '¿Cómo se involucró?', 'siniestro_danos' => 'Daños Materiales', 'siniestro_danos.*.zona' => 'Zona afectada',
            'siniestro_danos.*.descripcion' => 'Descripción del daño', 'siniestro_testigos' => 'Testigos', 'siniestro_testigos.*.nombre' => 'Nombre',
            'siniestro_testigos.*.departamento' => 'Departamento', 'pc_causa_probable' => 'Causa probable', 'pc_acciones_tomadas' => 'Acciones tomadas / medidas correctivas',
        ], [
            'pc_num_lesionados.required_if' => 'Indica cuántas personas resultaron lesionadas.',
        ]);

        $t = fn (string $campo, bool $mayus = false) => $this->texto($entrada[$campo] ?? null, $mayus);
        $tipo = $entrada['pc_tipo_evento'];
        $evacuacion = $this->siNo($entrada['pc_evacuacion'] ?? '0');
        $lesionados = $this->siNo($entrada['pc_hubo_lesionados'] ?? '0');

        $servicios = [];
        foreach (array_keys(SiniestroDetalle::SERVICIOS) as $i => $servicio) {
            $fila = $entrada['pc_servicios'][$i] ?? null;
            if (is_array($fila) && $this->siNo($fila['activo'] ?? null)) {
                $servicios[] = ['servicio' => $servicio, 'hora_llegada' => $this->hora($fila['hora'] ?? null)];
            }
        }

        return [
            'detalle' => [
                'tipo_evento' => $tipo, 'descripcion_otro' => $tipo === 'OTROS' ? $t('pc_descripcion_otro', true) : null,
                'controlado_en' => $this->localAUtc($t('pc_fecha_control'), $novedad),
                'alarma_activada' => $this->siNo($entrada['pc_alarma'] ?? '0'), 'requiere_evacuacion' => $evacuacion,
                'num_evacuados' => $evacuacion && ($entrada['pc_num_evacuados'] ?? '') !== '' ? (int) $entrada['pc_num_evacuados'] : null,
                'punto_reunion' => $evacuacion ? $t('pc_punto_reunion', true) : null,
                'hubo_lesionados' => $lesionados, 'num_lesionados' => $lesionados ? (int) $entrada['pc_num_lesionados'] : null,
                'causa_probable' => $t('pc_causa_probable'), 'acciones_tomadas' => $t('pc_acciones_tomadas'),
            ],
            'servicios' => $servicios,
            'equipos' => array_map(fn ($e) => [
                'identificador' => $this->texto($e['identificador'], true), 'estado_uso' => ($e['estado_uso'] ?? '') === 'danado' ? 'danado' : 'utilizado',
            ], $this->filas($entrada['siniestro_equipos'] ?? [], 'identificador')),
            'danos' => array_map(fn ($d) => ['zona' => $this->texto($d['zona'], true), 'descripcion' => $this->texto($d['descripcion'] ?? null)],
                $this->filas($entrada['siniestro_danos'] ?? [], 'zona')),
            'testigos' => $this->testigosDe($entrada['siniestro_testigos'] ?? []),
        ];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        $id = ['novedad_id' => $novedad->id];
        SiniestroDetalle::updateOrCreate($id, $datos['detalle']);

        $novedad->siniestroServicios()->delete();
        foreach ($datos['servicios'] as $s) {
            SiniestroServicio::create($id + $s);
        }
        $novedad->siniestroEquipos()->delete();
        foreach ($datos['equipos'] as $e) {
            SiniestroEquipo::create($id + $e);
        }
        $novedad->siniestroDanos()->delete();
        foreach ($datos['danos'] as $d) {
            SiniestroDano::create($id + $d);
        }
        $this->guardarTestigos($novedad, 'proteccion_civil', $datos['testigos']);
    }
}
