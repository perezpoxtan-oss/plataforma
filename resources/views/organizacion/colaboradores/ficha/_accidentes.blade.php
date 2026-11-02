{{-- Pestaña «Accidentes» (C-33, D-14): solo lectura, con el permiso «Colaboradores → Ver accidentes». Lo que se captura sigue en el ticket de Accidente (su formato no cambia). --}}
<section class="tarjeta p-4" aria-labelledby="t-accidentes">
    <h2 id="t-accidentes" class="h6 fw-bold"><i class="bi bi-bandaid me-2 text-danger" aria-hidden="true"></i>Accidentes</h2>
    @if ($accidentes->isEmpty())
        <p class="small text-muted mb-0">No tiene accidentes registrados.</p>
    @else
        <p class="small text-muted">{{ $accidentes->count() === 1 ? '1 accidente registrado' : $accidentes->count().' accidentes registrados' }}. Para ver o completar el detalle, abre el ticket.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr><th scope="col">Ticket</th><th scope="col">Fecha</th><th scope="col">Turno</th><th scope="col">Tipo de herida</th><th scope="col">Incapacidad</th><th scope="col">Estatus</th></tr>
                </thead>
                <tbody>
                    @foreach ($accidentes as $acc)
                        @php
                            $ac = $acc->accidenteColaborador;
                            $inc = $acc->accidenteIncapacidad;
                        @endphp
                        <tr>
                            <td>
                                @if (in_array($acc->id, $accidentesVisibles, true))
                                    <a href="{{ route('novedades.index', ['abrir' => $acc->id]) }}">{{ $acc->folio() }}</a>
                                @else
                                    {{ $acc->folio() }}
                                @endif
                                <span class="d-block small text-muted">{{ $acc->sede?->nombre }}</span>
                            </td>
                            <td>{{ $ac?->fecha_accidente?->format('d/m/Y') ?? \Illuminate\Support\Carbon::parse($acc->created_at)->format('d/m/Y') }}{{ $ac?->hora_accidente ? ' '.substr((string) $ac->hora_accidente, 0, 5) : '' }}</td>
                            <td>{{ $ac?->turno ?: '—' }}</td>
                            <td>{{ implode(', ', $acc->accidenteDictamen?->tipos_herida ?? []) ?: '—' }}</td>
                            <td>{{ (int) ($inc?->dias_incapacidad ?? 0) > 0 ? $inc->dias_incapacidad.' días'.($inc->fecha_presenta ? ' · regresa el '.$inc->fecha_presenta->format('d/m/Y') : '') : 'Sin incapacidad' }}</td>
                            <td>{{ $acc->etiquetaEstatus() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
