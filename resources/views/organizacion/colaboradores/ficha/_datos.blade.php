{{-- Pestaña «Datos»: lo que se captura en Colaboradores y lo que hoy tiene a su cargo (cada bloque solo si puede ver ese módulo). --}}
<div class="row g-3">
    <div class="col-lg-6">
        <section class="tarjeta p-4 h-100" aria-labelledby="t-datos">
            <h2 id="t-datos" class="h6 fw-bold"><i class="bi bi-person-badge me-2 text-secondary" aria-hidden="true"></i>Datos del colaborador</h2>
            <dl class="lista-datos-expediente">
                <dt>Estado</dt><dd>{{ $pendiente ? 'Por verificar' : ($c->activo ? 'Activo' : ($c->fusionado_en_id ? 'Unido con otro registro' : 'De baja')) }}</dd>
                <dt>Núm. empleado</dt><dd>{{ $c->num_empleado ?: '—' }}</dd>
                <dt>Sede</dt><dd>{{ $c->sede?->nombre ?? '—' }}@if ($c->sedesAdicionales->isNotEmpty()) · también {{ $c->sedesAdicionales->pluck('nombre')->join(', ') }}@endif</dd>
                <dt>Departamento</dt><dd>{{ $c->departamento?->nombre ?? '—' }}</dd>
                <dt>Puesto</dt><dd>{{ $c->puesto?->nombre ?? '—' }}</dd>
                <dt>Turno</dt><dd>{{ $c->turno ? $c->turno->nombre.' ('.$c->turno->inicio().'–'.$c->turno->fin().')'.($c->turno->activo ? '' : ' · turno desactivado') : 'Sin turno' }}</dd>
                <dt>Teléfono</dt><dd>{{ $c->telefono ?: '—' }}</dd>
                <dt>Alta en la plataforma</dt><dd>@fecha($c->created_at, 'd/m/Y')</dd>
                <dt>Cuenta de usuario</dt><dd>{{ $c->usuario ? $c->usuario->username.($c->usuario->activo ? '' : ' (desactivada)') : 'Sin cuenta' }}</dd>
            </dl>
            <p class="texto-traza mb-0"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Los datos se editan desde <a href="{{ route('colaboradores.index', ['q' => $c->num_empleado]) }}">Colaboradores</a>.</p>
        </section>
    </div>

    @if ($llaves !== null)
        <div class="col-lg-6">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-llaves">
                <h2 id="t-llaves" class="h6 fw-bold"><i class="bi bi-key-fill me-2 text-secondary" aria-hidden="true"></i>Llaves en uso ({{ $llaves->count() }})</h2>
                @forelse ($llaves as $p)
                    <div class="small py-1 border-bottom"><strong>{{ $p->llave?->nomenclatura }}</strong> {{ $p->llave?->descripcion }} · {{ $p->sede?->nombre }} · desde @fecha($p->created_at)</div>
                @empty
                    <p class="small text-muted m-0">No tiene llaves prestadas.</p>
                @endforelse
                <p class="texto-traza mt-2 mb-0"><a href="{{ route('prestamo_llaves.index') }}">Ver Préstamo de llaves</a></p>
            </section>
        </div>
    @endif
    @if ($responsivas !== null)
        <div class="col-md-6">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-responsivas">
                <h2 id="t-responsivas" class="h6 fw-bold"><i class="bi bi-pen me-2 text-secondary" aria-hidden="true"></i>Resguardos en campo ({{ $responsivas->count() }})</h2>
                @forelse ($responsivas as $r)
                    <div class="small py-1 border-bottom"><strong>{{ $r->folio }}</strong> · {{ $r->equipos_count }} equipo{{ $r->equipos_count === 1 ? '' : 's' }} · {{ $r->sede?->nombre }} · @fecha($r->created_at, 'd/m/Y')</div>
                @empty
                    <p class="small text-muted m-0">No tiene equipos en resguardo.</p>
                @endforelse
                <p class="texto-traza mt-2 mb-0"><a href="{{ route('responsivas.index') }}">Ver Responsivas</a></p>
            </section>
        </div>
    @endif
    @if ($procedimientos !== null)
        <div class="col-md-6">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-procedimientos">
                <h2 id="t-procedimientos" class="h6 fw-bold"><i class="bi bi-book me-2 text-secondary" aria-hidden="true"></i>Procedimientos por leer ({{ $procedimientos->count() }})</h2>
                @forelse ($procedimientos as $p)
                    <div class="small py-1 border-bottom"><a href="{{ route('procedimientos.show', $p->id) }}">{{ $p->clave }} · {{ $p->titulo }}</a></div>
                @empty
                    <p class="small text-muted m-0">Al día: no le falta ningún acuse «Leí y entendí».</p>
                @endforelse
            </section>
        </div>
    @endif
</div>
