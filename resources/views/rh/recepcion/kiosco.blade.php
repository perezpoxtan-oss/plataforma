@extends('layouts.app')

@section('titulo', 'Modo kiosco')

@section('contenido')
<div class="pantalla-kiosco-rh">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-tablet text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Modo kiosco</h1>
            <p>Deja esta pantalla en la sala de espera: cada candidato escanea su QR con su celular y llena su CV.</p>
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        <div class="rejilla-kiosco">
            <section class="tarjeta p-3">
                <h2 class="h6 fw-bold mb-2">Candidatos en proceso</h2>
                @forelse ($lista as $c)
                    @php $vigente = $c->enlaces->first(fn ($e) => $e->vigente()); @endphp
                    <div class="fila-kiosco {{ $elegido && $elegido->id === $c->id ? 'elegida' : '' }}">
                        <div class="flex-grow-1 min-w-0">
                            <strong>{{ $c->nombre_completo }}</strong>
                            <span class="d-block small text-muted">{{ \App\Models\Candidato::ETAPAS[$c->etapa] }}@if ($vigente) · código <span class="codigo-kiosco-mini">{{ $vigente->codigo }}</span>@endif</span>
                        </div>
                        @if ($vigente)
                            <a href="{{ route('recepcion.kiosco', ['candidato' => $c->id]) }}" class="btn-azul btn-accion-rh">Mostrar QR</a>
                        @else
                            <form action="{{ route('recepcion.kiosco.generar', $c->id) }}" method="POST" class="m-0">
                                @csrf
                                <button type="submit" class="btn-secundario-rh"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>Generar QR</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="small text-muted m-0">No hay candidatos en proceso. Llegan cuando la caseta registra a alguien como candidato.</p>
                @endforelse
            </section>

            <section class="tarjeta qr-kiosco">
                @if ($elegido && $enlace)
                    <p class="qr-kiosco-hola">Hola, <strong>{{ $elegido->nombre_completo }}</strong></p>
                    <p class="qr-kiosco-instruccion">Escanea este código con la cámara de tu celular para llenar tu solicitud.</p>
                    <div class="qr-kiosco-imagen" role="img" aria-label="Código QR para llenar la solicitud">{!! $qr !!}</div>
                    <p class="qr-kiosco-alterno">¿No funciona la cámara? Entra a <strong>{{ $urlCodigo }}</strong> y escribe el código:</p>
                    <p class="codigo-kiosco">{{ $enlace->codigo }}</p>
                    <p class="texto-traza m-0">Vence a las @fecha($enlace->expira_en, 'H:i') · solo sirve para esta solicitud.</p>
                @else
                    <i class="bi bi-qr-code qr-kiosco-vacio" aria-hidden="true"></i>
                    <p class="text-muted m-0">Elige a un candidato de la lista para mostrar su QR en grande.</p>
                @endif
            </section>
        </div>
    @endif
</div>
@endsection
