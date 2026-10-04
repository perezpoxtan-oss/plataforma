{{-- Solo el Super Administrador: con qué empresa trabaja (o las plantillas) --}}
@if (auth()->user()->es_superadmin)
    @php $trabajo = app(\App\Support\Tenancy\EmpresaDeTrabajo::class); @endphp
    <form action="{{ route('empresa-activa') }}" method="POST" class="selector-empresa">
        @csrf
        <label for="empresa_id" class="fw-bold text-dark mb-0"><i class="bi bi-buildings me-1 text-primary" aria-hidden="true"></i> Empresa de trabajo</label>
        <select name="empresa_id" id="empresa_id" data-enviar-al-cambiar>
            <option value="" @selected($trabajo->id(auth()->user()) === null)>Plantillas de la plataforma (empresas nuevas)</option>
            @foreach ($trabajo->opciones() as $opcion)
                <option value="{{ $opcion->id }}" @selected($trabajo->id(auth()->user()) === $opcion->id)>{{ $opcion->nombre_comercial }}{{ $opcion->activo ? '' : ' (inactiva)' }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-primary">Cambiar</button></noscript>
        <span class="text-muted small">Solo tú (Super Administrador) ves este selector.</span>
    </form>
@endif
