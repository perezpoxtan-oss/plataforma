{{-- Abre el diálogo «Justificar» con la persona, el día y lo que falta justificar --}}
@if ($puedeJustificar && $dia['por_justificar'] !== [] && $dia['fecha'] <= $hoy)
    <button type="button" class="btn-cancelar boton-informe" data-accion="editar-registro" data-dialogo="dialogoJustificar"
            data-url="{{ route('asistencia.justificar', ['colaborador' => $colaborador->id, 'fecha' => $dia['fecha']]) }}" data-id="{{ $colaborador->id }}"
            data-valores="{{ json_encode(['persona' => $colaborador->nombreCompleto().' · '.\Carbon\CarbonImmutable::parse($dia['fecha'])->format('d/m/Y'), 'tipo' => $dia['por_justificar'][0], 'motivo' => '', 'nota' => '']) }}"
            aria-label="Justificar a {{ $colaborador->nombreCompleto() }} el {{ \Carbon\CarbonImmutable::parse($dia['fecha'])->format('d/m/Y') }}">
        <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Justificar
    </button>
@endif
