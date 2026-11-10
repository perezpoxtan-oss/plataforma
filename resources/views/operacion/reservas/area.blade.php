@extends('layouts.app')

@section('titulo', 'Área reservable: '.$area->nombre())

@php
    use App\Models\ReservaArea;
    $u = auth()->user();
    $navPuede = ['crear' => $u->can('reservas.crear'), 'ver' => $u->can('reservas.ver'), 'aprobar' => $u->can('reservas.aprobar'), 'configurar' => true, 'exportar' => $u->can('reservas.exportar')];
    $conHorario = old('con_horario', is_array($area->horario) && $area->horario !== [] ? '1' : '0') === '1';
    $elegidos = $area->montajes->keyBy('id');
    $nombreGeneral = $general['responsable_id'] ? ($usuarios->firstWhere('id', $general['responsable_id'])?->name ?? 'el responsable general') : null;
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('componentes.siguiente-paso')
    <nav class="migas migas-espacios" aria-label="Ubicación">
        <a href="{{ route('reservas.configuracion') }}"><i class="bi bi-gear" aria-hidden="true"></i> Configuración de reservas</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <a href="{{ route('reservas.index', ['sede' => $area->sede_id]) }}"><small>Sede</small>{{ $area->sede?->nombre }}</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span class="actual" aria-current="page"><small>Área</small>{{ $area->rutaTexto() }}</span>
    </nav>
    @include('operacion.reservas._nav', ['titulo' => $area->nombre(), 'descripcion' => $area->porInteriores()
        ? 'Esta zona se reserva por sus espacios interiores. Lo que guardes aquí es la configuración inicial de sus espacios; puedes copiarla a todos.'
        : 'Cómo se reserva esta área. Todo es opcional: sin datos, se reserva a cualquier hora, sin costo y la aprueba el responsable general.', 'icono' => $area->porInteriores() ? 'bi-building' : 'bi-door-open', 'actual' => 'configuracion', 'puede' => $navPuede])

    @unless ($area->reservable)
        <div class="alert alert-warning aviso mb-3" role="status"><i class="bi bi-info-circle-fill" aria-hidden="true"></i> Esta área está quitada de reservas: puedes editar sus datos, pero no se ofrece para reservar. Para volver a ofrecerla, usa <strong>Reactivar</strong> en su ficha de Configuración de reservas (con «Mostrar quitadas»).</div>
    @endunless

    <form method="POST" action="{{ route('reservas.configuracion.area.guardar', $area->id) }}" class="res-form" enctype="multipart/form-data" autocomplete="off">
        @csrf
        @method('PUT')

        <section class="res-bloque" aria-labelledby="t-basico">
            <h2 id="t-basico">Lo básico</h2>
            <div class="res-rejilla">
                <div>
                    <label class="campo-etiqueta" for="aforo_maximo">Aforo máximo (personas)</label>
                    <input type="number" id="aforo_maximo" name="aforo_maximo" class="campo" min="1" max="100000" value="{{ old('aforo_maximo', $area->aforo_maximo) }}">
                </div>
                <div>
                    <label class="campo-etiqueta" for="responsable_id">Responsable de aprobación</label>
                    <select id="responsable_id" name="responsable_id" class="campo">
                        <option value="">{{ $nombreGeneral ? 'El responsable general ('.$nombreGeneral.')' : 'El responsable general de reservas' }}</option>
                        @foreach ($usuarios as $us)<option value="{{ $us->id }}" @selected((int) old('responsable_id', $area->responsable_id) === $us->id)>{{ $us->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="campo-etiqueta" for="suplente_id">Suplente</label>
                    <select id="suplente_id" name="suplente_id" class="campo">
                        <option value="">{{ $area->responsable_id ? 'El responsable general' : 'El suplente general' }}</option>
                        @foreach ($usuarios as $us)<option value="{{ $us->id }}" @selected((int) old('suplente_id', $area->suplente_id) === $us->id)>{{ $us->name }}</option>@endforeach
                    </select>
                </div>
            </div>
            <label class="d-flex gap-2 align-items-center mt-3"><input type="checkbox" name="confirmacion_inmediata" value="1" @checked(old('confirmacion_inmediata', $area->confirmacion_inmediata))>
                <span><strong>Confirmación inmediata</strong> <span class="res-meta">— la reserva queda confirmada al momento si no lleva servicios ni costo (ideal para salas de juntas)</span></span></label>
        </section>

        <section class="res-bloque" aria-labelledby="t-montajes">
            <h2 id="t-montajes">Montajes posibles</h2>
            <p class="res-meta">Marca cómo se puede acomodar el área y cuántas personas caben en cada forma. El catálogo se edita en Configuración de reservas.</p>
            <div class="res-rejilla">
                @foreach ($montajes as $m)
                    <div class="d-flex gap-2 align-items-center">
                        <input type="checkbox" id="montaje{{ $m->id }}" name="montajes[{{ $m->id }}][usar]" value="1" @checked(old('montajes.'.$m->id.'.usar', $elegidos->has($m->id)))>
                        <label for="montaje{{ $m->id }}" class="flex-grow-1">{{ $m->nombre }}</label>
                        <input type="number" name="montajes[{{ $m->id }}][aforo]" class="campo" style="max-width: 100px" min="1" placeholder="Aforo" aria-label="Aforo en {{ $m->nombre }}"
                               value="{{ old('montajes.'.$m->id.'.aforo', $elegidos->get($m->id)?->pivot->aforo) }}">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="res-bloque" aria-labelledby="t-horario">
            <h2 id="t-horario">Horario y tiempos</h2>
            <div class="res-rejilla mb-3">
                <div>
                    <label class="campo-etiqueta" for="montaje_minutos">Montaje (minutos antes)</label>
                    <input type="number" id="montaje_minutos" name="montaje_minutos" class="campo" min="0" max="1440" value="{{ old('montaje_minutos', $area->montaje_minutos) }}">
                </div>
                <div>
                    <label class="campo-etiqueta" for="desmontaje_minutos">Desmontaje (minutos después)</label>
                    <input type="number" id="desmontaje_minutos" name="desmontaje_minutos" class="campo" min="0" max="1440" value="{{ old('desmontaje_minutos', $area->desmontaje_minutos) }}">
                </div>
            </div>
            <p class="res-meta">El montaje y el desmontaje también ocupan el área: nadie más puede reservarla en esos minutos.</p>
            <label class="d-flex gap-2 align-items-center"><input type="checkbox" name="con_horario" value="1" @checked($conHorario)> <strong>Solo se reserva en este horario</strong> <span class="res-meta">(sin marcar: cualquier día y hora)</span></label>
            <div class="mt-2">
                @foreach (ReservaArea::DIAS as $n => $dia)
                    @php $ventana = ($area->horario[(string) $n] ?? [])[0] ?? null; @endphp
                    <div class="res-horario-dia">
                        <label class="d-flex gap-2 align-items-center"><input type="checkbox" name="horario[{{ $n }}][abierto]" value="1" @checked(old('horario.'.$n.'.abierto', $ventana !== null || ! $conHorario))> {{ $dia }}</label>
                        <input type="time" name="horario[{{ $n }}][desde]" class="campo" value="{{ old('horario.'.$n.'.desde', $ventana[0] ?? '08:00') }}" aria-label="{{ $dia }}: desde">
                        <input type="time" name="horario[{{ $n }}][hasta]" class="campo" value="{{ old('horario.'.$n.'.hasta', $ventana[1] ?? '22:00') }}" aria-label="{{ $dia }}: hasta">
                    </div>
                @endforeach
            </div>
        </section>

        <details class="res-bloque" @if (count($area->tarifas())) open @endif>
            <summary class="fw-bold">Tarifas para clientes <span class="res-meta fw-normal">(opcional; en {{ $moneda }}, antes de IVA)</span></summary>
            <div class="res-rejilla mt-3">
                @foreach (ReservaArea::TARIFAS as $clave => [$columna, $texto])
                    <div>
                        <label class="campo-etiqueta" for="{{ $columna }}">{{ $texto }}</label>
                        <input type="number" id="{{ $columna }}" name="{{ $columna }}" class="campo" min="0" step="0.01" value="{{ old($columna, $area->{$columna}) }}">
                    </div>
                @endforeach
            </div>
            <p class="res-meta mt-2 mb-0">Por hora se cobra cada hora o fracción. Las reservas de uso interno no tienen costo.</p>
        </details>

        <details class="res-bloque" @if ($area->descripcion || $area->foto) open @endif>
            <summary class="fw-bold">Foto y descripción <span class="res-meta fw-normal">(opcional; se ven al reservar)</span></summary>
            <div class="res-rejilla mt-3">
                <div>
                    @if ($area->foto)
                        <img src="{{ route('reservas.foto', $area->id) }}" alt="Foto de {{ $area->nombre() }}" class="res-foto mb-2">
                        <label class="d-flex gap-2 align-items-center small mb-2"><input type="checkbox" name="quitar_foto" value="1"> Quitar la foto</label>
                    @endif
                    <label class="campo-etiqueta" for="foto">{{ $area->foto ? 'Cambiar foto' : 'Foto' }}</label>
                    <input type="file" id="foto" name="foto" class="campo" accept="image/png,image/jpeg,image/webp">
                </div>
                <div style="grid-column: span 2">
                    <label class="campo-etiqueta" for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" class="campo" rows="4" maxlength="2000" placeholder="Ej. Salón con vista al mar, pantalla fija y aire acondicionado.">{{ old('descripcion', $area->descripcion) }}</textarea>
                </div>
            </div>
        </details>

        @if ($interiores > 0)
            <section class="res-bloque" aria-labelledby="t-interiores">
                <h2 id="t-interiores">Espacios interiores</h2>
                <p class="res-meta">{{ $area->porInteriores() ? 'Esta zona se reserva por sus espacios interiores' : 'Además de toda la zona, se reservan sus espacios interiores' }}: {{ $interiores }} {{ $interiores === 1 ? 'espacio' : 'espacios' }}. Si se reserva la zona completa, sus espacios quedan ocupados en ese horario, y al revés.</p>
                <label class="d-flex gap-2 align-items-center"><input type="checkbox" name="copiar_interiores" value="1" @checked(old('copiar_interiores'))>
                    <span><strong>Copiar esta configuración a sus {{ $interiores }} espacios interiores</strong> <span class="res-meta">— reemplaza el aforo, horario, responsable, montajes, tarifas y minutos de montaje de cada uno</span></span></label>
            </section>
        @endif

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Guardar</button>
            <a href="{{ route('reservas.index', ['sede' => $area->sede_id]) }}" class="btn-secundario-rh">Cancelar</a>
        </div>
    </form>
</div>
@endsection
