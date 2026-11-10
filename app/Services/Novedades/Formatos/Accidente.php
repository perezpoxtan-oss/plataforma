<?php

namespace App\Services\Novedades\Formatos;

use App\Models\AccidenteColaborador;
use App\Models\AccidenteDictamen;
use App\Models\AccidenteFirma;
use App\Models\AccidenteGuardavidas;
use App\Models\AccidenteHuesped;
use App\Models\AccidenteIncapacidad;
use App\Models\Colaborador;
use App\Models\Novedad;
use App\Models\User;
use App\Services\Firmas\Firmas;

/**
 * Accidente / Lesión (SEGCAT: frag_accidente.php). El responsable del
 * proyecto pidió NO cambiar este formulario: mismas secciones, campos,
 * etiquetas, opciones y orden. Solo correcciones técnicas: validación en el
 * servidor, firmas en el disco privado y colaborador con el lector universal.
 *
 * Igual que SEGCAT: el formato de huésped o de colaborador se guarda según el
 * "Tipo de Afectado"; los testigos y lo de Recursos Humanos van con el de
 * colaborador; el dictamen médico siempre; el anexo de guardavidas solo si
 * trae el nombre del guardavidas; cada firma solo si se capturó.
 */
class Accidente extends Formato
{
    public const TIPOS_AFECTADO = ['HUESPED' => 'Huésped / Cliente', 'COLABORADOR' => 'Colaborador Interno'];

    public const HERIDAS = ['Lacerante', 'Contusa', 'Cortante', 'Punzante', 'Abrasión', 'Quemadura', 'Amputación', 'Hernia', 'Enfermedad', 'Otros'];

    /** Zonas del mapa corporal (data-zona del SVG de SEGCAT). */
    public const ZONAS = [
        'Cabeza Frontal', 'Ojo Der', 'Ojo Izq', 'Nariz', 'Boca', 'Mentón', 'Pecho', 'Abdomen', 'Brazo Der', 'Brazo Izq', 'Mano Der', 'Mano Izq',
        'Dedos Mano Der', 'Dedos Mano Izq', 'Pierna Der', 'Pierna Izq', 'Pie Der', 'Pie Izq', 'Nuca', 'Espalda', 'Pierna Post Izq', 'Pierna Post Der',
        'Perfil Der', 'Tronco Der', 'Muslo/Pierna Der', 'Perfil Izq', 'Tronco Izq', 'Muslo/Pierna Izq',
    ];

    public function __construct(private readonly Firmas $firmas) {}

    public function relaciones(): array
    {
        return ['accidenteHuesped', 'accidenteColaborador.colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
            'accidenteDictamen', 'accidenteGuardavidas', 'accidenteIncapacidad', 'firmas', 'testigos'];
    }

    public function valores(Novedad $novedad): array
    {
        $h = $novedad->accidenteHuesped;
        $c = $novedad->accidenteColaborador;
        $m = $novedad->accidenteDictamen;
        $g = $novedad->accidenteGuardavidas;
        $rh = $novedad->accidenteIncapacidad;
        $sn = fn (?bool $v, string $porOmision) => $v === null ? $porOmision : ($v ? '1' : '0');

        return [
            'acc_tipo_afectado' => $h !== null ? 'HUESPED' : ($c !== null ? 'COLABORADOR' : ''),
            'h_fecha_accidente' => $h?->fecha_accidente?->format('Y-m-d'), 'h_hora_accidente' => $h ? substr((string) $h->hora_accidente, 0, 5) : null,
            'h_nombre' => $h?->nombre, 'h_hab' => $h?->num_habitacion, 'h_agencia' => $h?->agencia,
            'h_checkin' => $h?->fecha_check_in?->format('Y-m-d'), 'h_checkout' => $h?->fecha_check_out?->format('Y-m-d'),
            'h_pais' => $h?->pais, 'h_sexo' => $h?->sexo ?? 'M', 'h_edad' => $h?->edad, 'h_lugar' => $h?->lugar, 'h_explicacion' => $h?->explicacion,
            'h_req_medico' => $sn($h?->requiere_asistencia_medica, '1'), 'h_motivo' => $h?->motivo_asistencia,
            'h_testigos' => $sn($h?->hubo_testigos, '0'), 'h_detalles_testigos' => $h?->detalles_testigos,

            'c_fecha_accidente' => $c?->fecha_accidente?->format('Y-m-d'), 'c_hora_accidente' => $c ? substr((string) $c->hora_accidente, 0, 5) : null,
            'c_id_colaborador' => $c?->colaborador_id,
            'c_colaborador_texto' => $c?->colaborador ? $c->colaborador->nombreCompleto().' · Núm. '.$c->colaborador->num_empleado : null,
            'c_depto_colaborador' => $c?->departamento, 'c_puesto_colaborador' => $c?->puesto, 'c_turno_colaborador' => $c?->turno,
            'c_area_trabajo' => $c?->area_trabajo, 'c_jefe' => $c?->jefe_inmediato, 'c_puesto_jefe' => $c?->puesto_jefe,
            'c_primera_vez' => $sn($c?->primera_vez, '1'),
            'c_causa_terceros' => (bool) $c?->causa_terceras_personas, 'c_causa_acto' => (bool) $c?->causa_acto_inseguro, 'c_causa_condicion' => (bool) $c?->causa_condicion_insegura,
            'c_explicacion' => $c?->explicacion_causas,
            'acc_testigos' => $this->testigosGuardados($novedad, 'accidente'),
            // Caso nuevo: se sugiere quién dio el aviso, tomado de "¿Quién reporta?"
            'c_aviso_por' => $c !== null ? $c->aviso_dado_por : $novedad->reportado_por,
            'c_depto_aviso' => $c?->depto_aviso, 'c_actividades' => $c?->actividades_cotidianas, 'c_mismas_actividades' => $sn($c?->mismas_actividades, '1'),

            'm_herida' => $m?->tipos_herida ?? [], 'm_parte' => implode(', ', $m?->zonas_cuerpo ?? []),
            'm_primeros_aux' => $sn($m?->primeros_auxilios, '1'), 'm_primeros_cuales' => $m?->cuales_auxilios,
            'm_atencion_med' => $sn($m?->atencion_medica, '1'), 'm_atencion_cuales' => $m?->cuales_atencion,
            'm_diagnostico' => $m?->diagnostico, 'm_hosp' => $sn($m?->hospitalizacion, '1'), 'm_hosp_nombre' => $m?->nombre_hospital,
            'm_traslado' => $m?->trasladado_en, 'm_doctor' => $m?->nombre_medico, 'm_observaciones' => $m?->observaciones,

            'g_fecha' => $g?->fecha?->format('Y-m-d'), 'g_hora' => $g ? substr((string) $g->hora, 0, 5) : null, 'g_turno' => $g?->turno, 'g_lugar' => $g?->lugar,
            'g_nombre' => $g?->nombre, 'g_puesto' => $g?->puesto, 'g_supervisor' => $g?->supervisor, 'g_alcohol' => (bool) $g?->alcoholizado,
            'g_descalzo' => $sn($g?->descalzo, '0'), 'g_calzado' => $g?->tipo_calzado, 'g_tipo_herida' => $g?->tipo_herida, 'g_parte_afectada' => $g?->parte_afectada,
            'g_acto' => (bool) $g?->acto_inseguro, 'g_condicion' => (bool) $g?->condicion_insegura, 'g_especifique' => $g?->especifique_riesgo,
            'g_desc' => $g?->descripcion, 'g_acudio_medico' => $sn($g?->acudio_servicio_medico, '0'), 'g_material' => $g?->material_curacion, 'g_informa' => $g?->se_informa_a,

            'rh_dias' => $rh?->dias_incapacidad ?? 0, 'rh_fecha' => $rh?->fecha_presenta?->format('Y-m-d'),
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $sn = ['nullable', 'in:0,1'];
        $this->revisar($entrada, [
            'acc_tipo_afectado' => ['nullable', 'in:HUESPED,COLABORADOR'],
            'h_fecha_accidente' => ['nullable', 'date_format:Y-m-d'], 'h_hora_accidente' => ['nullable', 'date_format:H:i'],
            'h_nombre' => ['nullable', 'string', 'max:150'], 'h_hab' => ['nullable', 'string', 'max:20'], 'h_agencia' => ['nullable', 'string', 'max:150'],
            'h_checkin' => ['nullable', 'date_format:Y-m-d'], 'h_checkout' => ['nullable', 'date_format:Y-m-d'], 'h_pais' => ['nullable', 'string', 'max:100'],
            'h_sexo' => ['nullable', 'in:M,F'], 'h_edad' => ['nullable', 'integer', 'min:0', 'max:120'], 'h_lugar' => ['nullable', 'string', 'max:255'],
            'h_explicacion' => ['nullable', 'string', 'max:5000'], 'h_req_medico' => $sn, 'h_motivo' => ['nullable', 'string', 'max:255'],
            'h_testigos' => $sn, 'h_detalles_testigos' => ['nullable', 'string', 'max:255'],
            'c_fecha_accidente' => ['nullable', 'date_format:Y-m-d'], 'c_hora_accidente' => ['nullable', 'date_format:H:i'],
            'c_id_colaborador' => ['nullable', 'required_if:acc_tipo_afectado,COLABORADOR', 'integer'],
            'c_turno_colaborador' => ['nullable', 'string', 'max:50'], 'c_area_trabajo' => ['nullable', 'string', 'max:150'],
            'c_jefe' => ['nullable', 'string', 'max:150'], 'c_puesto_jefe' => ['nullable', 'string', 'max:150'], 'c_primera_vez' => $sn,
            'c_explicacion' => ['nullable', 'string', 'max:5000'], 'c_aviso_por' => ['nullable', 'string', 'max:150'], 'c_depto_aviso' => ['nullable', 'string', 'max:100'],
            'c_actividades' => ['nullable', 'string', 'max:5000'], 'c_mismas_actividades' => $sn,
            'acc_testigos' => ['nullable', 'array', 'max:'.self::MAX_FILAS], 'acc_testigos.*.nombre' => ['nullable', 'string', 'max:150'], 'acc_testigos.*.departamento' => ['nullable', 'string', 'max:100'],
            'm_herida' => ['nullable', 'array'], 'm_herida.*' => ['string', 'in:'.implode(',', self::HERIDAS)], 'm_parte' => ['nullable', 'string', 'max:1000'],
            'm_primeros_aux' => $sn, 'm_primeros_cuales' => ['nullable', 'string', 'max:255'], 'm_atencion_med' => $sn, 'm_atencion_cuales' => ['nullable', 'string', 'max:255'],
            'm_diagnostico' => ['nullable', 'string', 'max:5000'], 'm_hosp' => $sn, 'm_hosp_nombre' => ['nullable', 'string', 'max:255'],
            'm_traslado' => ['nullable', 'string', 'max:150'], 'm_doctor' => ['nullable', 'string', 'max:150'], 'm_observaciones' => ['nullable', 'string', 'max:5000'],
            'g_fecha' => ['nullable', 'date_format:Y-m-d'], 'g_hora' => ['nullable', 'date_format:H:i'], 'g_turno' => ['nullable', 'string', 'max:50'],
            'g_lugar' => ['nullable', 'string', 'max:150'], 'g_nombre' => ['nullable', 'string', 'max:150'], 'g_puesto' => ['nullable', 'string', 'max:100'],
            'g_supervisor' => ['nullable', 'string', 'max:150'], 'g_descalzo' => $sn, 'g_calzado' => ['nullable', 'string', 'max:100'],
            'g_tipo_herida' => ['nullable', 'string', 'max:150'], 'g_parte_afectada' => ['nullable', 'string', 'max:150'], 'g_especifique' => ['nullable', 'string', 'max:2000'],
            'g_desc' => ['nullable', 'string', 'max:5000'], 'g_acudio_medico' => $sn, 'g_material' => ['nullable', 'string', 'max:255'], 'g_informa' => ['nullable', 'string', 'max:150'],
            'rh_dias' => ['nullable', 'integer', 'min:0', 'max:999'], 'rh_fecha' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'acc_tipo_afectado' => 'Tipo de Afectado', 'h_fecha_accidente' => 'Fecha del Accidente', 'h_hora_accidente' => 'Hora del Accidente',
            'h_nombre' => 'Nombre del Huésped', 'h_hab' => 'No. Habitación', 'h_agencia' => 'Agencia', 'h_checkin' => 'Check-in', 'h_checkout' => 'Check-out',
            'h_pais' => 'País', 'h_sexo' => 'Sexo', 'h_edad' => 'Edad', 'h_lugar' => 'Lugar o área del accidente', 'h_explicacion' => 'Explique cómo sucedió',
            'h_req_medico' => '¿Asistencia médica?', 'h_motivo' => '¿Por qué?', 'h_testigos' => '¿Hubo testigos?', 'h_detalles_testigos' => 'Nombres/Datos de los Testigos',
            'c_fecha_accidente' => 'Fecha del Accidente', 'c_hora_accidente' => 'Hora del Accidente', 'c_id_colaborador' => 'Buscar Colaborador Afectado',
            'c_turno_colaborador' => 'Turno', 'c_area_trabajo' => 'Área de Trabajo', 'c_jefe' => 'Nombre del jefe inmediato', 'c_puesto_jefe' => 'Puesto del jefe',
            'c_primera_vez' => '1ra vez accidentado', 'c_explicacion' => 'Explique cualquiera de los anteriores o ambos', 'c_aviso_por' => 'Aviso dado por (Nombre)',
            'c_depto_aviso' => 'Depto de quien avisa', 'c_actividades' => 'Actividades cotidianas', 'c_mismas_actividades' => '¿Mismas actividades al momento del accidente?',
            'acc_testigos' => 'Testigos', 'acc_testigos.*.nombre' => 'Nombre Testigo', 'acc_testigos.*.departamento' => 'Departamento',
            'm_herida' => 'Tipo de Herida', 'm_herida.*' => 'Tipo de Herida', 'm_parte' => 'Zonas Afectadas', 'm_primeros_aux' => 'Primeros Auxilios',
            'm_primeros_cuales' => 'Primeros Auxilios (cuáles)', 'm_atencion_med' => 'Atención Médica', 'm_atencion_cuales' => 'Atención Médica (cuáles)',
            'm_diagnostico' => 'Diagnóstico preliminar', 'm_hosp' => 'Hospitalización', 'm_hosp_nombre' => 'Nombre Hospital', 'm_traslado' => 'Trasladado en',
            'm_doctor' => 'Nombre del Doctor que atendió', 'm_observaciones' => 'Observaciones Generales Médicas',
            'g_fecha' => 'Fecha Suceso', 'g_hora' => 'Hora Suceso', 'g_turno' => 'Turno', 'g_lugar' => 'Lugar Exacto', 'g_nombre' => 'Nombre Guardavidas',
            'g_puesto' => 'Puesto', 'g_supervisor' => 'Supervisor', 'g_descalzo' => '¿Estaba Descalzo?', 'g_calzado' => 'Tipo de calzado',
            'g_tipo_herida' => 'Tipo de Herida Apreciada', 'g_parte_afectada' => 'Parte Afectada Apreciada', 'g_especifique' => 'Especifique riesgo',
            'g_desc' => 'Descripción de los hechos', 'g_acudio_medico' => '¿Acudió a servicio médico?', 'g_material' => 'Material de curación utilizado',
            'g_informa' => 'Se informa a (Nombre)', 'rh_dias' => 'Días de Incapacidad', 'rh_fecha' => 'Se presenta a laborar',
        ], [
            'c_id_colaborador.required_if' => 'Busca y elige al colaborador afectado (escanea su gafete o escribe su número de empleado).',
        ]);

        $tipo = $entrada['acc_tipo_afectado'] ?? '';
        $datos = ['tipo' => $tipo, 'huesped' => null, 'colaborador' => null, 'testigos' => null, 'rh' => null];
        $t = fn (string $campo, bool $mayus = false) => $this->texto($entrada[$campo] ?? null, $mayus);

        if ($tipo === 'HUESPED') {
            $datos['huesped'] = [
                'fecha_accidente' => $t('h_fecha_accidente'), 'hora_accidente' => $this->hora($entrada['h_hora_accidente'] ?? null),
                'nombre' => $t('h_nombre', true), 'num_habitacion' => $t('h_hab'), 'agencia' => $t('h_agencia', true),
                'fecha_check_in' => $t('h_checkin'), 'fecha_check_out' => $t('h_checkout'), 'pais' => $t('h_pais', true),
                'sexo' => $t('h_sexo'), 'edad' => isset($entrada['h_edad']) && $entrada['h_edad'] !== '' ? (int) $entrada['h_edad'] : null,
                'lugar' => $t('h_lugar', true), 'explicacion' => $t('h_explicacion'),
                'requiere_asistencia_medica' => $this->siNo($entrada['h_req_medico'] ?? '1'), 'motivo_asistencia' => $t('h_motivo'),
                'hubo_testigos' => $this->siNo($entrada['h_testigos'] ?? '0'), 'detalles_testigos' => $t('h_detalles_testigos', true),
            ];
        } elseif ($tipo === 'COLABORADOR') {
            $colaborador = $this->colaboradorValido((int) $entrada['c_id_colaborador'], $novedad);
            $colaborador->loadMissing(['departamento:id,nombre', 'puesto:id,nombre']);
            $datos['colaborador'] = [
                'colaborador_id' => $colaborador->id,
                'fecha_accidente' => $t('c_fecha_accidente'), 'hora_accidente' => $this->hora($entrada['c_hora_accidente'] ?? null),
                // Departamento y puesto se toman del expediente del colaborador (en SEGCAT llegaban del formulario)
                'departamento' => $colaborador->departamento?->nombre !== null ? mb_strtoupper($colaborador->departamento->nombre) : null,
                'puesto' => $colaborador->puesto?->nombre !== null ? mb_strtoupper($colaborador->puesto->nombre) : null,
                'turno' => $t('c_turno_colaborador', true), 'area_trabajo' => $t('c_area_trabajo', true),
                'jefe_inmediato' => $t('c_jefe', true), 'puesto_jefe' => $t('c_puesto_jefe', true),
                'primera_vez' => $this->siNo($entrada['c_primera_vez'] ?? '1'),
                'causa_terceras_personas' => $this->siNo($entrada['c_causa_terceros'] ?? null),
                'causa_acto_inseguro' => $this->siNo($entrada['c_causa_acto'] ?? null),
                'causa_condicion_insegura' => $this->siNo($entrada['c_causa_condicion'] ?? null),
                'explicacion_causas' => $t('c_explicacion'), 'aviso_dado_por' => $t('c_aviso_por', true), 'depto_aviso' => $t('c_depto_aviso', true),
                'actividades_cotidianas' => $t('c_actividades'), 'mismas_actividades' => $this->siNo($entrada['c_mismas_actividades'] ?? '1'),
            ];
            $datos['testigos'] = $this->testigosDe($entrada['acc_testigos'] ?? []);
            $datos['rh'] = ['dias_incapacidad' => (int) ($entrada['rh_dias'] ?? 0), 'fecha_presenta' => $t('rh_fecha')];
        }

        $heridas = array_values(array_intersect(self::HERIDAS, (array) ($entrada['m_herida'] ?? [])));
        $zonas = array_values(array_intersect(self::ZONAS, array_map('trim', explode(',', (string) ($entrada['m_parte'] ?? '')))));
        $datos['medico'] = [
            'tipos_herida' => $heridas, 'zonas_cuerpo' => $zonas,
            'primeros_auxilios' => $this->siNo($entrada['m_primeros_aux'] ?? '1'), 'cuales_auxilios' => $t('m_primeros_cuales'),
            'atencion_medica' => $this->siNo($entrada['m_atencion_med'] ?? '1'), 'cuales_atencion' => $t('m_atencion_cuales'),
            'diagnostico' => $t('m_diagnostico'), 'hospitalizacion' => $this->siNo($entrada['m_hosp'] ?? '1'), 'nombre_hospital' => $t('m_hosp_nombre'),
            'trasladado_en' => $t('m_traslado', true), 'nombre_medico' => $t('m_doctor', true), 'observaciones' => $t('m_observaciones'),
        ];

        $datos['guardavidas'] = $t('g_nombre') === null ? null : [
            'fecha' => $t('g_fecha'), 'hora' => $this->hora($entrada['g_hora'] ?? null), 'turno' => $t('g_turno', true), 'lugar' => $t('g_lugar', true),
            'nombre' => $t('g_nombre', true), 'puesto' => $t('g_puesto', true), 'supervisor' => $t('g_supervisor', true),
            'alcoholizado' => $this->siNo($entrada['g_alcohol'] ?? null), 'descalzo' => $this->siNo($entrada['g_descalzo'] ?? '0'),
            'tipo_calzado' => $t('g_calzado'), 'tipo_herida' => $t('g_tipo_herida'), 'parte_afectada' => $t('g_parte_afectada'),
            'acto_inseguro' => $this->siNo($entrada['g_acto'] ?? null), 'condicion_insegura' => $this->siNo($entrada['g_condicion'] ?? null),
            'especifique_riesgo' => $t('g_especifique'), 'descripcion' => $t('g_desc'),
            'acudio_servicio_medico' => $this->siNo($entrada['g_acudio_medico'] ?? '0'), 'material_curacion' => $t('g_material'), 'se_informa_a' => $t('g_informa', true),
        ];

        // Firmas: se revisan aquí (imagen real, tamaño) pero se guardan al final, ya validado todo
        // Ronda 8 (NV-03): solo los firmantes que corresponden al Tipo de Afectado
        $datos['firmas'] = [];
        $permitidos = AccidenteFirma::rolesPara($tipo);
        foreach (array_keys(AccidenteFirma::ROLES) as $rol) {
            $valor = $entrada['f_'.$rol] ?? null;
            if (! $this->firmas->viene(is_string($valor) ? $valor : null)) {
                continue;
            }
            if (! isset($permitidos[$rol])) {
                $this->error('f_'.$rol, 'La firma de «'.AccidenteFirma::ROLES[$rol].'» no corresponde a un accidente de '
                    .($tipo === 'HUESPED' ? 'huésped' : 'colaborador').'. Elige otro firmante.');
            }
            $datos['firmas'][$rol] = $valor;
        }

        return $datos;
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        $id = ['novedad_id' => $novedad->id];
        if ($datos['huesped'] !== null) {
            AccidenteHuesped::updateOrCreate($id, $datos['huesped']);
        }
        if ($datos['colaborador'] !== null) {
            AccidenteColaborador::updateOrCreate($id, $datos['colaborador']);
            AccidenteIncapacidad::updateOrCreate($id, $datos['rh']);
            $this->guardarTestigos($novedad, 'accidente', $datos['testigos']);
        }
        AccidenteDictamen::updateOrCreate($id, $datos['medico']);
        if ($datos['guardavidas'] !== null) {
            AccidenteGuardavidas::updateOrCreate($id, $datos['guardavidas']);
        }

        foreach ($datos['firmas'] as $rol => $imagen) {
            $ruta = $this->firmas->guardar($imagen, 'accidentes', 'f_'.$rol, 'la firma de '.AccidenteFirma::etiqueta($rol, $datos['tipo']));
            $anterior = AccidenteFirma::where('novedad_id', $novedad->id)->where('rol', $rol)->first();
            if ($anterior !== null) {
                $this->firmas->borrar($anterior->ruta);
                $anterior->forceFill(['ruta' => $ruta])->save();
            } else {
                AccidenteFirma::create($id + ['rol' => $rol, 'ruta' => $ruta]);
            }
        }
    }

    /**
     * El colaborador debe ser de la empresa y estar activo (o ser el que ya tenía el expediente).
     */
    private function colaboradorValido(int $id, Novedad $novedad): Colaborador
    {
        $colaborador = Colaborador::find($id);
        $esElMismo = $novedad->accidenteColaborador?->colaborador_id === $id;
        if ($colaborador === null || ((! $colaborador->activo || $colaborador->fusionado_en_id !== null) && ! $esElMismo)) {
            $this->error('c_id_colaborador', 'El colaborador afectado no existe en esta empresa o está dado de baja.');
        }

        return $colaborador;
    }
}
