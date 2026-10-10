@extends('layouts.app')

@section('titulo', 'Autorizaciones')

@section('contenido')
@use('App\Models\Autorizacion')
@use('App\Models\Delegacion')
<div class="pantalla-autorizaciones">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-patch-check-fill text-warning" aria-hidden="true"></i></div>
            <div>
                <h1>Autorizaciones</h1>
                <p>Visitas que esperan la respuesta de su departamento.</p>
            </div>
        </div>
        @unless ($sinEmpresa)
            <div class="d-flex flex-wrap gap-2">
                @if ($entrevistas !== null)
                    <a href="{{ route('entrevistas.index') }}" class="btn-secundario-rh"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Entrevistas ({{ $entrevistas }})</a>
                @endif
                @if ($puede['responder'])
                    <button type="button" class="btn-secundario-rh" data-abrir-dialogo="dialogoDelegar"><i class="bi bi-moon-stars me-1" aria-hidden="true"></i>No molestar / delegar</button>
                @endif
                @if ($puede['configurar'])
                    <a href="{{ route('autorizaciones.responsables') }}" class="btn-secundario-rh"><i class="bi bi-people me-1" aria-hidden="true"></i>Responsables por departamento</a>
                @endif
            </div>
        @endunless
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        <section class="mb-4">
            <h2 class="titulo-seccion-rh"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Por responder ({{ $porResponder->count() }})</h2>
            @forelse ($porResponder as $a)
                @include('rh.autorizaciones._tarjeta', ['a' => $a, 'botones' => true])
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">
                        @if ($esResponsable || $delegaciones->contains(fn ($d) => $d->delegado_id === auth()->id() && $d->estado() === 'activa'))
                            Nada pendiente. Cuando alguien espere tu autorización, te llegará un aviso en la campana (y por correo).
                        @else
                            No eres responsable de ningún departamento. Recursos Humanos o Dirección lo definen en «Responsables por departamento».
                        @endif
                    </p>
                </div>
            @endforelse
        </section>

        @if ($delegaciones->isNotEmpty())
            <section class="mb-4">
                <h2 class="titulo-seccion-rh"><i class="bi bi-moon-stars me-1" aria-hidden="true"></i>Delegaciones</h2>
                <div class="lista-delegaciones">
                    @foreach ($delegaciones as $d)
                        @php $estadoD = $d->estado(); @endphp
                        <div class="fila-delegacion estado-{{ $estadoD }}">
                            <div class="flex-grow-1 min-w-0">
                                <strong>{{ $d->usuario?->name }}</strong> <i class="bi bi-arrow-right" aria-hidden="true"></i> <strong>{{ $d->delegado?->name }}</strong>
                                <span class="d-block small">Del @fecha($d->desde) al @fecha($d->hasta){{ $d->motivo ? ' · '.$d->motivo : '' }}</span>
                                @if ($d->cancelada_en)<span class="texto-traza">Cancelada por {{ $d->canceladaPor?->name }} · @fecha($d->cancelada_en)</span>@endif
                            </div>
                            <span class="pastilla-estado-aut estado-{{ $estadoD }}">{{ Delegacion::ESTADOS[$estadoD] }}</span>
                            @if (in_array($estadoD, ['activa', 'programada'], true) && ($d->user_id === auth()->id() || $puede['configurar']))
                                <form action="{{ route('autorizaciones.delegaciones.cancelar', $d->id) }}" method="POST" class="m-0" data-confirmar="¿Cancelar esta delegación? Los avisos volverán a llegarle a {{ $d->usuario?->name }}.">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-secundario-rh">Cancelar</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="titulo-seccion-rh m-0"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historial</h2>
                <nav class="pildoras-pases m-0" aria-label="Filtrar por estado">
                    <a href="{{ route('autorizaciones.index') }}" class="btn-pill-tipo {{ $estado === '' ? 'active' : '' }}">Todas</a>
                    @foreach (Autorizacion::ESTADOS as $clave => $texto)
                        <a href="{{ route('autorizaciones.index', ['estado' => $clave]) }}" class="btn-pill-tipo {{ $estado === $clave ? 'active' : '' }}">{{ $texto }}</a>
                    @endforeach
                </nav>
            </div>
            <div class="mt-2">
                @forelse ($historial as $a)
                    @include('rh.autorizaciones._tarjeta', ['a' => $a, 'botones' => false])
                @empty
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Sin solicitudes{{ $estado !== '' ? ' con ese estado' : '' }}.</p></div>
                @endforelse
            </div>
            <div class="mt-3">{{ $historial->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        </section>

        @if ($puede['responder'])
            @php $reabrir = old('_dialogo') === 'delegar'; $zona = app(\App\Support\HoraLocal::class); @endphp
            <dialog id="dialogoDelegar" class="dialogo" aria-labelledby="titulo-delegar" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-delegar"><i class="bi bi-moon-stars me-2 text-warning" aria-hidden="true"></i>No molestar: delegar autorizaciones</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ route('autorizaciones.delegar') }}" method="POST" autocomplete="off">
                        @csrf
                        <input type="hidden" name="_dialogo" value="delegar">
                        @if ($reabrir && $errors->any())
                            <div class="alert alert-danger small py-2 px-3" role="alert"><ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul></div>
                        @endif
                        <p class="small text-muted">Mientras estés fuera, los avisos de tu departamento le llegarán a la persona que elijas y ella podrá responder por ti.</p>
                        @if ($puede['configurar'])
                            <label class="campo-etiqueta" for="del_titular">¿Quién delega?</label>
                            <select id="del_titular" name="user_id" class="campo">
                                <option value="">Yo ({{ auth()->user()->name }})</option>
                                @foreach ($usuarios as $u)
                                    @continue ($u->id === auth()->id())
                                    <option value="{{ $u->id }}" @selected($reabrir && (string) old('user_id') === (string) $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <label class="campo-etiqueta" for="del_delegado">¿En quién delegas? *</label>
                        <select id="del_delegado" name="delegado_id" class="campo" required>
                            <option value="">-- Elegir --</option>
                            @foreach ($usuarios as $u)
                                {{-- Ronda 8: nadie delega en sí mismo (quien configura puede elegirse para otro titular) --}}
                                @continue ($u->id === auth()->id() && ! $puede['configurar'])
                                <option value="{{ $u->id }}" @selected($reabrir && (string) old('delegado_id') === (string) $u->id)>{{ $u->name }}{{ $u->id === auth()->id() ? ' (tú)' : '' }}</option>
                            @endforeach
                        </select>
                        <div class="rejilla-cv">
                            <div>
                                <label class="campo-etiqueta" for="del_desde">Desde *</label>
                                <input type="datetime-local" id="del_desde" name="desde" class="campo" required value="{{ $reabrir ? old('desde') : $zona->formatear(now(), 'Y-m-d\TH:i') }}">
                            </div>
                            <div>
                                <label class="campo-etiqueta" for="del_hasta">Hasta *</label>
                                <input type="datetime-local" id="del_hasta" name="hasta" class="campo" required value="{{ $reabrir ? old('hasta') : $zona->formatear(now()->addDay(), 'Y-m-d\TH:i') }}">
                            </div>
                        </div>
                        <label class="campo-etiqueta" for="del_motivo">Motivo (opcional)</label>
                        <input type="text" id="del_motivo" name="motivo" class="campo" maxlength="255" value="{{ $reabrir ? old('motivo') : '' }}" placeholder="Ej. Vacaciones, junta fuera">
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-azul">Guardar delegación</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    @endif
</div>
@endsection
