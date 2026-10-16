<?php

namespace App\Services\Reservas;

use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaServicio;

/**
 * Textos de avisos, fichas y hoja impresa: siempre en la hora local de la
 * sede del área (quien recibe el aviso puede estar en otra zona).
 */
class TextosReservas
{
    private const DIAS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    public function __construct(private readonly Disponibilidad $disponibilidad) {}

    /** «jue 20/11/2026 de 10:00 a 14:00». */
    public function cuando(Reserva $r): string
    {
        $zona = $this->disponibilidad->zona($r->area);
        $i = $r->inicio->copy()->setTimezone($zona);
        $f = $r->fin->copy()->setTimezone($zona);

        return self::DIAS[$i->dayOfWeekIso].' '.$i->format('d/m/Y').' de '.$i->format('H:i').' a '.$f->format('H:i').($r->dia_completo ? ' (día completo)' : '');
    }

    /** «Salón Maya · jue 20/11/2026 de 10:00 a 14:00 · 40 personas». */
    public function resumen(Reserva $r): string
    {
        $r->loadMissing('area.espacio');

        return $r->area->nombre().' · '.$this->cuando($r).' · '.$r->asistentes.' '.($r->asistentes === 1 ? 'persona' : 'personas')
            .($r->excede_aforo ? ' (excede el aforo: '.$r->motivo_excepcion_aforo.')' : '')
            .($r->para_cliente ? ' · Cliente: '.($r->cliente_empresa ?: $r->cliente_nombre) : ' · Uso interno');
    }

    public function parte(ReservaAprobacion $a): string
    {
        $a->loadMissing('departamento:id,nombre');

        return $a->tipo === 'area' ? 'responsable del área' : (string) ($a->departamento?->nombre ?? 'departamento');
    }

    /** «Coffee break × 40 pers., Agua × 40 pers.» */
    public function serviciosDe(Reserva $r, int $departamentoId): string
    {
        $r->loadMissing(['items', 'montaje']);
        $texto = $r->items->where('departamento_id', $departamentoId)
            ->map(fn ($i) => $i->nombre.' × '.$i->cantidadTexto().' '.(ReservaServicio::UNIDADES[$i->unidad][1] ?? '').($i->notas ? ' ('.$i->notas.')' : ''))
            ->join(', ');

        // El departamento que hace el montaje puede tener orden solo por el tipo de montaje
        return $texto !== '' ? $texto : ($r->montaje ? 'Montaje '.$r->montaje->nombre : 'sin servicios');
    }

    /** « · Montaje: Herradura (U), listo a las 09:30». */
    public function montajeTexto(Reserva $r): string
    {
        $r->loadMissing(['montaje', 'area']);
        $minutos = $r->minutosMontaje();
        $listo = $minutos > 0 ? ', listo a las '.$r->inicio->copy()->setTimezone($this->disponibilidad->zona($r->area))->format('H:i') : '';

        return ($r->montaje ? ' · Montaje: '.$r->montaje->nombre.$listo : '')
            .($r->indicaciones_montaje ? ' · Indicaciones: '.$r->indicaciones_montaje : '');
    }

    /** «Juan Pérez — Capacitación». */
    public function solicitadoPor(Reserva $r): string
    {
        $r->loadMissing(['solicitante:id,name', 'departamentoSolicitante:id,nombre']);

        return $r->solicitadoPorTexto();
    }

    /** «30 min antes (montaje) · 15 min después (desmontaje)». */
    public function tiemposMontaje(Reserva $r): string
    {
        $r->loadMissing('area');
        $antes = $r->minutosMontaje();
        $despues = $r->minutosDesmontaje();
        if ($antes === 0 && $despues === 0) {
            return 'Sin tiempo de montaje ni desmontaje';
        }

        return self::duracion($antes).' antes (montaje) · '.self::duracion($despues).' después (desmontaje)';
    }

    /** 30 → «30 min»; 90 → «1 h 30 min»; 120 → «2 h». */
    public static function duracion(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos.' min';
        }
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return $h.' h'.($m > 0 ? ' '.$m.' min' : '');
    }
}
