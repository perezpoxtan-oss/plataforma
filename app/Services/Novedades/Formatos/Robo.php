<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;
use App\Models\RoboDetalle;
use App\Models\User;

/**
 * Robo (SEGCAT: frag_robo.php): circunstancias, sospechoso, testigos con su
 * declaración y canalización. La pantalla de seguimiento de Robo (archivo)
 * llega después y usa estas mismas tablas.
 */
class Robo extends Formato
{
    public function relaciones(): array
    {
        return ['robo.articuloVinculado:id,folio,objeto', 'testigos'];
    }

    public function valores(Novedad $novedad): array
    {
        $r = $novedad->robo;

        return [
            'robo_hora_aproximada' => $r?->hora_aproximada ? substr((string) $r->hora_aproximada, 0, 5) : null,
            'robo_lugar_exacto' => $r?->lugar_exacto, 'robo_objetos_descripcion' => $r?->objetos_descripcion,
            'robo_valor_estimado' => $r?->valor_estimado, 'robo_hay_sospechoso' => $r?->hay_sospechoso ? '1' : '0',
            'robo_descripcion_sospechoso' => $r?->descripcion_sospechoso,
            'robo_testigos' => $this->testigosGuardados($novedad, 'robo'),
            'robo_se_dio_parte_policia' => $r?->parte_policia ? '1' : '0', 'robo_folio_policial' => $r?->folio_policial,
            'robo_canalizado_gerencia' => (bool) $r?->canalizado_gerencia, 'robo_canalizado_legal' => (bool) $r?->canalizado_legal,
            'robo_observaciones_investigacion' => $r?->observaciones,
            'robo_vinculado' => $r?->articuloVinculado ? $r->articuloVinculado->folio.' — '.$r->articuloVinculado->objeto : null,
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $this->revisar($entrada, [
            'robo_hora_aproximada' => ['nullable', 'date_format:H:i'], 'robo_lugar_exacto' => ['nullable', 'string', 'max:150'],
            'robo_objetos_descripcion' => ['nullable', 'string', 'max:5000'], 'robo_valor_estimado' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'robo_hay_sospechoso' => ['nullable', 'in:0,1'], 'robo_descripcion_sospechoso' => ['nullable', 'string', 'max:5000'],
            'robo_testigos' => ['nullable', 'array', 'max:'.self::MAX_FILAS], 'robo_testigos.*.nombre' => ['nullable', 'string', 'max:150'],
            'robo_testigos.*.departamento' => ['nullable', 'string', 'max:100'], 'robo_testigos.*.declaracion' => ['nullable', 'string', 'max:5000'],
            'robo_se_dio_parte_policia' => ['nullable', 'in:0,1'], 'robo_folio_policial' => ['nullable', 'string', 'max:100'],
            'robo_observaciones_investigacion' => ['nullable', 'string', 'max:5000'],
        ], [
            'robo_hora_aproximada' => 'Hora aproximada', 'robo_lugar_exacto' => 'Lugar exacto', 'robo_objetos_descripcion' => '¿Qué se llevaron?',
            'robo_valor_estimado' => 'Valor estimado de lo robado', 'robo_hay_sospechoso' => '¿Hay algún sospechoso identificado?',
            'robo_descripcion_sospechoso' => 'Descripción del sospechoso', 'robo_testigos' => 'Testigos', 'robo_testigos.*.nombre' => 'Nombre',
            'robo_testigos.*.departamento' => 'Departamento / Procedencia', 'robo_testigos.*.declaracion' => 'Declaración',
            'robo_se_dio_parte_policia' => '¿Se dio parte a la policía?', 'robo_folio_policial' => 'Folio / número de reporte policial',
            'robo_observaciones_investigacion' => 'Observaciones de la investigación',
        ]);

        $t = fn (string $campo, bool $mayus = false) => $this->texto($entrada[$campo] ?? null, $mayus);
        $sospechoso = $this->siNo($entrada['robo_hay_sospechoso'] ?? '0');
        $policia = $this->siNo($entrada['robo_se_dio_parte_policia'] ?? '0');

        return [
            'detalle' => [
                'hora_aproximada' => $this->hora($entrada['robo_hora_aproximada'] ?? null),
                'lugar_exacto' => $t('robo_lugar_exacto', true), 'objetos_descripcion' => $t('robo_objetos_descripcion'),
                'valor_estimado' => is_numeric($entrada['robo_valor_estimado'] ?? null) ? round((float) $entrada['robo_valor_estimado'], 2) : null,
                'hay_sospechoso' => $sospechoso, 'descripcion_sospechoso' => $sospechoso ? $t('robo_descripcion_sospechoso') : null,
                'parte_policia' => $policia, 'folio_policial' => $policia ? $t('robo_folio_policial', true) : null,
                'canalizado_gerencia' => $this->siNo($entrada['robo_canalizado_gerencia'] ?? null),
                'canalizado_legal' => $this->siNo($entrada['robo_canalizado_legal'] ?? null),
                'observaciones' => $t('robo_observaciones_investigacion'),
            ],
            'testigos' => $this->testigosDe($entrada['robo_testigos'] ?? [], true),
        ];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        RoboDetalle::updateOrCreate(['novedad_id' => $novedad->id], $datos['detalle']);
        $this->guardarTestigos($novedad, 'robo', $datos['testigos']);
    }
}
