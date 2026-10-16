{{--
    «Agregar área reservable» (reservas.configurar): casillas con los espacios
    de Zonas y edificios en orden de árbol (sangría por nivel), buscador y, si
    hay varias sedes, filtro de sede. Para las zonas con espacios interiores se
    elige el modo: toda la zona como un solo espacio o por sus espacios
    interiores (los marca de una vez con la configuración de la zona).
    La lista se pide al abrir el diálogo (data-cargar-url); solo viene ya armada
    cuando hay que volver a mostrarla con un error. Parámetros: $opciones (o null), $sedeId (opcional).
--}}
@php
    $sedeId = $sedeId ?? null;
    $opciones = $opciones ?? null;
@endphp
<dialog id="dialogoAgregarArea" class="dialogo ancho" aria-labelledby="t-agregar-area" @if ($errors->has('espacio_id')) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="t-agregar-area"><i class="bi bi-plus-circle me-2 text-success" aria-hidden="true"></i>Agregar área reservable</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form method="POST" action="{{ route('reservas.configuracion.marcar') }}" autocomplete="off">
            @csrf
            @if ($sedeId)<input type="hidden" name="sede" value="{{ $sedeId }}">@endif
            @if ($opciones !== null)
                <div>@include('operacion.reservas._opciones-agregar', ['opciones' => $opciones, 'sedeId' => $sedeId])</div>
            @else
                <div data-cargar-url="{{ route('reservas.configuracion.opciones', $sedeId ? ['sede' => $sedeId] : []) }}">
                    <p class="text-muted text-center p-4 m-0"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Cargando espacios…</p>
                    <div class="dialogo-acciones"><button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button></div>
                </div>
            @endif
        </form>
    </div>
</dialog>
