<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundDetalle;
use App\Models\LostFoundReportePerdida;
use App\Models\LostFoundUmbral;
use App\Models\Novedad;
use App\Models\RoboDetalle;
use App\Models\User;

/**
 * Lost & Found (SEGCAT: LOST_FOUND, frag_lost_found.php): plataforma externa,
 * artículos encontrados (folio LF-000123) y reportes de pérdida (RP-000123).
 *
 * - Un artículo nuevo siempre nace EN_RESGUARDO; su estatus nunca se cambia
 *   desde aquí (solo con "Cerrar / Entregar", que pide firma).
 * - El folio se asigna una sola vez (consecutivo por empresa).
 * - Solo se tocan los artículos y reportes de este ticket; un artículo ya
 *   entregado o vinculado, y un reporte ya vinculado, no se pueden quitar.
 */
class LostFound extends Formato
{
    public function relaciones(): array
    {
        return ['lostFound', 'articulos.areaEspecifica:id,nombre', 'articulos.reportesVinculados:id,articulo_vinculado_id',
            'reportesPerdida.areaEspecifica:id,nombre', 'reportesPerdida.articuloVinculado:id,folio,objeto'];
    }

    public function valores(Novedad $novedad): array
    {
        $umbrales = LostFoundUmbral::vigentes();

        return [
            'lf_folio_externo' => $novedad->lostFound?->folio_externo,
            'lf_enlace_externo' => $novedad->lostFound?->enlace_externo,
            'lf_articulos' => $novedad->articulos->map(fn (LostFoundArticulo $a) => [
                'id' => $a->id, 'folio' => $a->folio, 'objeto' => $a->objeto, 'tipo_valor' => $a->tipo_valor, 'marca' => $a->marca, 'color' => $a->color,
                'area_especifica_id' => $a->area_especifica_id, 'ubicacion_bodega' => $a->ubicacion_bodega, 'lugar_detalle' => $a->lugar_detalle,
                'estatus' => $a->estatus, 'semaforo' => $a->semaforo($umbrales), 'bloqueado' => ! $this->sePuedeQuitar($a),
            ])->all(),
            'rp_reportes' => $novedad->reportesPerdida->map(fn (LostFoundReportePerdida $r) => [
                'id' => $r->id, 'folio' => $r->folio, 'objeto' => $r->objeto, 'tipo_valor' => $r->tipo_valor, 'marca' => $r->marca, 'color' => $r->color,
                'nombre_huesped' => $r->nombre_huesped, 'area_especifica_id' => $r->area_especifica_id,
                'fecha_aproximada' => $r->fecha_aproximada?->format('Y-m-d'), 'telefono' => $r->telefono, 'correo' => $r->correo,
                'descripcion' => $r->descripcion, 'estatus' => $r->estatus,
                'vinculado' => $r->articuloVinculado ? $r->articuloVinculado->folio.' — '.$r->articuloVinculado->objeto : null,
            ])->all(),
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $tipos = implode(',', array_keys(LostFoundArticulo::TIPOS_VALOR));
        $this->revisar($entrada, [
            'lf_folio_externo' => ['nullable', 'string', 'max:100'],
            'lf_enlace_externo' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'lf_articulos' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'lf_articulos.*.id' => ['nullable', 'integer'], 'lf_articulos.*.objeto' => ['nullable', 'string', 'max:150'],
            'lf_articulos.*.tipo_valor' => ['nullable', 'in:'.$tipos], 'lf_articulos.*.marca' => ['nullable', 'string', 'max:100'],
            'lf_articulos.*.color' => ['nullable', 'string', 'max:50'], 'lf_articulos.*.area_especifica_id' => ['nullable', 'integer'],
            'lf_articulos.*.ubicacion_bodega' => ['nullable', 'string', 'max:100'], 'lf_articulos.*.lugar_detalle' => ['nullable', 'string', 'max:150'],
            'rp_reportes' => ['nullable', 'array', 'max:'.self::MAX_FILAS],
            'rp_reportes.*.id' => ['nullable', 'integer'], 'rp_reportes.*.objeto' => ['nullable', 'string', 'max:150'],
            'rp_reportes.*.tipo_valor' => ['nullable', 'in:'.$tipos], 'rp_reportes.*.marca' => ['nullable', 'string', 'max:100'],
            'rp_reportes.*.color' => ['nullable', 'string', 'max:50'], 'rp_reportes.*.nombre_huesped' => ['nullable', 'string', 'max:150'],
            'rp_reportes.*.area_especifica_id' => ['nullable', 'integer'], 'rp_reportes.*.fecha_aproximada' => ['nullable', 'date_format:Y-m-d'],
            'rp_reportes.*.telefono' => ['nullable', 'string', 'max:20'], 'rp_reportes.*.correo' => ['nullable', 'email', 'max:150'],
            'rp_reportes.*.descripcion' => ['nullable', 'string', 'max:2000'],
        ], [
            'lf_folio_externo' => 'Folio en plataforma externa', 'lf_enlace_externo' => 'Enlace directo al artículo',
            'lf_articulos' => 'Artículos Encontrados', 'lf_articulos.*.objeto' => 'Objeto', 'lf_articulos.*.tipo_valor' => 'Tipo / Categoría de Valor',
            'lf_articulos.*.marca' => 'Marca', 'lf_articulos.*.color' => 'Color', 'lf_articulos.*.area_especifica_id' => 'Área Específica',
            'lf_articulos.*.ubicacion_bodega' => 'Ubicación en bodega', 'lf_articulos.*.lugar_detalle' => 'Detalle adicional del lugar',
            'rp_reportes' => 'Reportes de Pérdida', 'rp_reportes.*.objeto' => 'Objeto', 'rp_reportes.*.tipo_valor' => 'Tipo / Categoría de Valor',
            'rp_reportes.*.marca' => 'Marca', 'rp_reportes.*.color' => 'Color', 'rp_reportes.*.nombre_huesped' => 'Nombre del huésped',
            'rp_reportes.*.area_especifica_id' => 'Habitación', 'rp_reportes.*.fecha_aproximada' => '¿Desde cuándo nota que no lo tiene?',
            'rp_reportes.*.telefono' => 'Teléfono de contacto', 'rp_reportes.*.correo' => 'Correo de contacto', 'rp_reportes.*.descripcion' => 'Señas particulares',
        ]);

        $t = fn (array $fila, string $campo, bool $mayus = false) => $this->texto($fila[$campo] ?? null, $mayus);
        $articulos = array_map(fn ($a) => [
            'id' => isset($a['id']) && $a['id'] !== '' ? (int) $a['id'] : null,
            'objeto' => $t($a, 'objeto', true), 'tipo_valor' => ($a['tipo_valor'] ?? '') ?: 'OTRO',
            'marca' => $t($a, 'marca', true), 'color' => $t($a, 'color', true),
            'area_especifica_id' => $this->habitacionValida($a['area_especifica_id'] ?? null, $novedad, 'lf_articulos'),
            'ubicacion_bodega' => $t($a, 'ubicacion_bodega', true), 'lugar_detalle' => $t($a, 'lugar_detalle', true),
        ], $this->filas($entrada['lf_articulos'] ?? [], 'objeto'));

        $reportes = array_map(fn ($r) => [
            'id' => isset($r['id']) && $r['id'] !== '' ? (int) $r['id'] : null,
            'objeto' => $t($r, 'objeto', true), 'tipo_valor' => ($r['tipo_valor'] ?? '') ?: 'OTRO',
            'marca' => $t($r, 'marca', true), 'color' => $t($r, 'color', true), 'nombre_huesped' => $t($r, 'nombre_huesped', true),
            'area_especifica_id' => $this->habitacionValida($r['area_especifica_id'] ?? null, $novedad, 'rp_reportes'),
            'fecha_aproximada' => $t($r, 'fecha_aproximada'), 'telefono' => $t($r, 'telefono'), 'correo' => $t($r, 'correo'),
            'descripcion' => $t($r, 'descripcion'),
        ], $this->filas($entrada['rp_reportes'] ?? [], 'objeto'));

        return [
            'detalle' => ['folio_externo' => $this->texto($entrada['lf_folio_externo'] ?? null), 'enlace_externo' => $this->texto($entrada['lf_enlace_externo'] ?? null)],
            'articulos' => $articulos,
            'reportes' => $reportes,
        ];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        LostFoundDetalle::updateOrCreate(['novedad_id' => $novedad->id], $datos['detalle']);
        $base = ['novedad_id' => $novedad->id, 'sede_id' => $novedad->sede_id];

        // Artículos: solo los de este ticket (un id ajeno se trata como nuevo)
        $existentes = $novedad->articulos()->with('reportesVinculados:id,articulo_vinculado_id')->get()->keyBy('id');
        $conservados = [];
        foreach ($datos['articulos'] as $a) {
            $id = $a['id'];
            unset($a['id']);
            if ($id !== null && $existentes->has($id)) {
                $existentes[$id]->fill($a)->save();
                $conservados[] = $id;
            } else {
                $nuevo = new LostFoundArticulo($base + $a);
                [$nuevo->numero, $nuevo->folio] = $this->siguienteFolio(LostFoundArticulo::class, 'LF');
                $nuevo->save();
                $conservados[] = $nuevo->id;
            }
        }
        foreach ($existentes->except($conservados) as $articulo) {
            if ($this->sePuedeQuitar($articulo)) {
                $articulo->delete();
            }
        }

        // Reportes de pérdida: mismo criterio; uno ya vinculado se conserva
        $previos = $novedad->reportesPerdida()->get()->keyBy('id');
        $vigentes = [];
        foreach ($datos['reportes'] as $r) {
            $id = $r['id'];
            unset($r['id']);
            if ($id !== null && $previos->has($id)) {
                $previos[$id]->fill($r)->save();
                $vigentes[] = $id;
            } else {
                $nuevo = new LostFoundReportePerdida($base + $r);
                [$nuevo->numero, $nuevo->folio] = $this->siguienteFolio(LostFoundReportePerdida::class, 'RP');
                $nuevo->save();
                $vigentes[] = $nuevo->id;
            }
        }
        foreach ($previos->except($vigentes) as $reporte) {
            if ($reporte->estatus === LostFoundReportePerdida::BUSCANDO) {
                $reporte->delete();
            }
        }
    }

    /**
     * Un artículo se puede quitar del ticket solo si sigue en resguardo y nadie lo ha vinculado.
     */
    public function sePuedeQuitar(LostFoundArticulo $articulo): bool
    {
        return $articulo->enResguardo()
            && $articulo->reportesVinculados->isEmpty()
            && ! RoboDetalle::where('articulo_vinculado_id', $articulo->id)->exists();
    }

    /**
     * Siguiente folio de la empresa: LF-000124 / RP-000124.
     *
     * @param  class-string<LostFoundArticulo|LostFoundReportePerdida>  $modelo
     * @return array{0: int, 1: string}
     */
    private function siguienteFolio(string $modelo, string $prefijo): array
    {
        $numero = (int) $modelo::query()->lockForUpdate()->max('numero') + 1;

        return [$numero, $prefijo.'-'.str_pad((string) $numero, 6, '0', STR_PAD_LEFT)];
    }

    /**
     * El Área Específica debe ser de la sede y del Área General del ticket
     * (SEGCAT: no se vuelve a preguntar Edificio/Sección por artículo).
     */
    private function habitacionValida(mixed $id, Novedad $novedad, string $campo): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $habitacion = Espacio::where('nivel', Espacio::AREA_ESPECIFICA)->where('sede_id', $novedad->sede_id)->find((int) $id);
        if ($habitacion === null || ($novedad->area_id !== null && ! str_contains((string) $habitacion->ruta, '/'.$novedad->area_id.'/'))) {
            $this->error($campo, 'Una de las habitaciones elegidas no pertenece al Área General del ticket. Revisa el edificio y piso arriba.');
        }

        return $habitacion->id;
    }
}
