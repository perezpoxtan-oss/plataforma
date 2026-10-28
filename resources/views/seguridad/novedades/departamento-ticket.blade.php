@extends('layouts.app')

@section('titulo', 'Ticket '.$n->folio())

@section('contenido')
{{-- C-28 (DD-15): el departamento ve solo este ticket; agrega notas y «Atender» marca su parte --}}
<div class="tema-rojo">
    @include('administracion.partes.avisos')
    <p class="mb-2"><a href="{{ route('novedades.departamento.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Tickets de mi departamento</a></p>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-diagram-2-fill text-danger" aria-hidden="true"></i></div>
        <div>
            <h1>Ticket {{ $n->folio() }} · {{ $n->etiquetaCategoria() }}</h1>
            <p>Canalizado a <strong>{{ $n->departamento?->nombre }}</strong> · {{ $n->sede?->nombre }}</p>
        </div>
    </div>

    <div class="tarjeta mb-3">
        <dl class="row mb-0 small">
            <dt class="col-sm-3">¿Dónde pasó?</dt><dd class="col-sm-9">{{ $n->ubicacion }}</dd>
            <dt class="col-sm-3">¿Cuándo?</dt><dd class="col-sm-9">@if ($n->ocurrio_en) @fecha($n->ocurrio_en) @else — @endif</dd>
            <dt class="col-sm-3">¿Qué sucedió?</dt><dd class="col-sm-9">{{ $n->descripcion }}</dd>
            @if ($n->como_sucedio)<dt class="col-sm-3">¿Cómo sucedió?</dt><dd class="col-sm-9">{{ $n->como_sucedio }}</dd>@endif
            <dt class="col-sm-3">Reportó</dt><dd class="col-sm-9">{{ $n->reportado_por }}</dd>
            <dt class="col-sm-3">Lo lleva en Seguridad</dt><dd class="col-sm-9">{{ $n->asignado?->name ?? $n->creador?->name ?? '—' }}</dd>
            <dt class="col-sm-3">Estatus</dt><dd class="col-sm-9">{{ $n->etiquetaEstatus() }}@if ($n->departamento_atendido_en) · {{ $n->departamento?->nombre }} atendió su parte ({{ $n->departamentoAtendidoPor?->name ?? '—' }}, @fecha($n->departamento_atendido_en))@endif</dd>
        </dl>
    </div>

    <div class="tarjeta mb-3">
        <h2 class="h6 fw-bold"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Minuto a Minuto</h2>
        @forelse ($n->notas as $nota)
            <p class="small mb-1"><span class="text-muted">@fecha($nota->created_at)</span> · {{ $nota->texto }}</p>
        @empty
            <p class="small text-muted mb-0">Sin notas todavía.</p>
        @endforelse
    </div>

    @unless ($n->resuelto())
        <div class="tarjeta">
            <form action="{{ route('novedades.departamento.nota', $n->id) }}" method="POST" class="mb-3">
                @csrf
                <label class="campo-etiqueta" for="nota_depto">Agregar nota</label>
                <textarea id="nota_depto" name="nota" class="campo" rows="2" maxlength="2000" required placeholder="Ej: Se cambió la luminaria; queda en observación."></textarea>
                <div class="text-end mt-2"><button type="submit" class="btn-cancelar">Guardar nota</button></div>
            </form>
            @if ($puedeAtender)
                <form action="{{ route('novedades.departamento.atender', $n->id) }}" method="POST" data-confirmar="¿Marcar que {{ $n->departamento?->nombre }} ya hizo su parte? Se avisa a Seguridad.">
                    @csrf
                    <label class="campo-etiqueta" for="nota_atender">¿Qué se hizo? <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="text" id="nota_atender" name="nota" class="campo" maxlength="2000">
                    <div class="text-end mt-2"><button type="submit" class="btn-cian"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Atender</button></div>
                </form>
            @endif
        </div>
    @endunless
</div>
@endsection
