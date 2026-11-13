@extends('layouts.app')

@section('titulo', 'Bloquear un sitio')

@php
    $v = fn (string $campo) => old($campo, $valores[$campo] ?? '');
    $todoElDia = filter_var(old('todo_el_dia', '0'), FILTER_VALIDATE_BOOLEAN);
    $elegida = (int) $v('reserva_area_id');
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Bloquear un sitio', 'descripcion' => 'Mientras esté bloqueado nadie podrá reservarlo en ese horario. Las reservas que ya existan no se dan de baja solas: avisamos a quienes las hicieron.',
        'icono' => 'bi-lock', 'actual' => 'bloqueos'])

    @if ($areas->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">No hay sitios reservables que te toque bloquear.</p>
        </div>
    @else
    <form method="POST" action="{{ route('reservas.bloqueos.store') }}" class="res-form" autocomplete="off" data-form-bloqueo data-impacto-url="{{ route('reservas.bloqueos.impacto') }}">
        @csrf
        <section class="res-bloque" aria-labelledby="t-sitio">
            <h2 id="t-sitio"><i class="bi bi-lock me-1 text-success" aria-hidden="true"></i>¿Qué sitio y cuándo?</h2>
            <div class="res-rejilla">
                <div class="res-ancho-2">
                    <label class="campo-etiqueta" for="reserva_area_id">Sitio <span class="text-danger" aria-hidden="true">*</span></label>
                    <select id="reserva_area_id" name="reserva_area_id" class="campo" required data-bloqueo-campo>
                        <option value="">Elige el sitio…</option>
                        @foreach ($areas->groupBy(fn ($a) => $a->sede?->nombre) as $sedeNombre => $grupo)
                            <optgroup label="{{ $sedeNombre }}">
                                @foreach ($grupo as $a)
                                    <option value="{{ $a->id }}" @selected($elegida === (int) $a->id)>{{ $a->rutaTexto() }}{{ $a->porInteriores() ? ' (con todos sus espacios)' : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="res-meta mt-1 mb-0">Bloquear una zona bloquea también sus espacios interiores, y bloquear un espacio impide reservar la zona completa.</p>
                </div>
                <div>
                    <label class="campo-etiqueta" for="fecha_desde">Desde el día <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="date" id="fecha_desde" name="fecha_desde" class="campo" required value="{{ $v('fecha_desde') }}" data-bloqueo-campo>
                </div>
                <div data-bloqueo-hora>
                    <label class="campo-etiqueta" for="hora_desde">A las</label>
                    <input type="time" id="hora_desde" name="hora_desde" class="campo" step="900" value="{{ $v('hora_desde') }}" data-bloqueo-campo>
                </div>
                <div>
                    <label class="campo-etiqueta" for="fecha_hasta">Hasta el día <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="date" id="fecha_hasta" name="fecha_hasta" class="campo" required value="{{ $v('fecha_hasta') }}" data-bloqueo-campo>
                </div>
                <div data-bloqueo-hora>
                    <label class="campo-etiqueta" for="hora_hasta">A las</label>
                    <input type="time" id="hora_hasta" name="hora_hasta" class="campo" step="900" value="{{ $v('hora_hasta') }}" data-bloqueo-campo>
                </div>
            </div>
            <label class="res-interruptor mt-3">
                <input type="hidden" name="todo_el_dia" value="0">
                <input type="checkbox" name="todo_el_dia" value="1" @checked($todoElDia) data-bloqueo-campo data-bloqueo-todo-el-dia>
                <span><strong>Todo el día</strong> <span class="res-meta">· desde las 00:00 del primer día hasta las 24:00 del último</span></span>
            </label>
        </section>

        <section class="res-bloque" aria-labelledby="t-motivo">
            <h2 id="t-motivo"><i class="bi bi-chat-left-text me-1 text-success" aria-hidden="true"></i>¿Por qué?</h2>
            <label class="campo-etiqueta" for="motivo">Motivo <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" id="motivo" name="motivo" class="campo" required minlength="3" maxlength="255" list="motivos-bloqueo" value="{{ $v('motivo') }}" placeholder="Ej. Mantenimiento del aire acondicionado">
            <datalist id="motivos-bloqueo">
                @foreach ($motivos as $m)<option value="{{ $m }}">@endforeach
            </datalist>
            <p class="res-meta mt-1 mb-0">Todos lo verán en el calendario: «Bloqueado: {{ $v('motivo') ?: 'motivo' }}».</p>
        </section>

        {{-- Impacto antes de guardar: con JavaScript se calcula al cambiar los datos; sin JavaScript, al guardar la primera vez --}}
        <section class="res-bloque" data-bloqueo-impacto aria-live="polite" @unless ($impacto) hidden @endunless>
            <h2><i class="bi bi-exclamation-triangle me-1 text-warning" aria-hidden="true"></i>Reservas en esas fechas</h2>
            <p class="mb-2 fw-semibold" data-bloqueo-impacto-texto>{{ $impacto['texto'] ?? '' }}</p>
            <ul class="small mb-0" data-bloqueo-impacto-lista>
                @foreach ($impacto['reservas'] ?? [] as $linea)<li>{{ $linea }}</li>@endforeach
            </ul>
        </section>
        <input type="hidden" name="impacto_visto" value="{{ $impacto['total'] ?? -1 }}" data-bloqueo-impacto-visto>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-lock me-1" aria-hidden="true"></i>Guardar · bloquear</button>
            <a href="{{ route('reservas.bloqueos') }}" class="btn-secundario-rh">Cancelar</a>
        </div>
    </form>
    @endif
</div>
@push('scripts')
    <script src="{{ asset('js/reservas-bloqueos.js') }}?v={{ @filemtime(public_path('js/reservas-bloqueos.js')) ?: '1' }}"></script>
@endpush
@endsection
