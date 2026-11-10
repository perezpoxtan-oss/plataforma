@extends('layouts.app')

@section('titulo', 'Antes de dar de baja')

@section('contenido')
{{--
    C-20 (DD-06): lo vigente que depende del registro antes de darlo de baja.
    - bloquea: una sede con gente en sitio o una zona con autos dentro (no hay botón).
    - pendiente: lo que tiene a su cargo pasa a «No regresó» al continuar.
    - aviso: lo que sigue igual pero conviene saber.
--}}
<div class="pantalla-antes-de-baja">
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-clipboard2-check text-danger" aria-hidden="true"></i></div>
        <div>
            <h1>Antes de dar de baja</h1>
            <p>{{ ucfirst($articulo) }} <strong>«{{ $nombre }}»</strong> tiene cosas vigentes. Revísalas antes de continuar.</p>
        </div>
    </div>

    <div class="tarjeta p-3 p-md-4 mb-3">
        <ul class="lista-antes-de-baja">
            @foreach ($revision['items'] as $item)
                <li class="renglon-baja nivel-{{ $item['nivel'] }}">
                    <div class="renglon-baja-titulo">
                        <i class="bi {{ ['bloquea' => 'bi-x-octagon-fill', 'pendiente' => 'bi-exclamation-triangle-fill', 'aviso' => 'bi-info-circle-fill'][$item['nivel']] }}" aria-hidden="true"></i>
                        <strong>{{ $item['texto'] }}</strong>
                    </div>
                    @if ($item['efecto'])
                        <p class="renglon-baja-efecto">{{ $item['efecto'] }}</p>
                    @endif
                    @if ($item['ejemplos'] !== [])
                        <ul class="renglon-baja-ejemplos">
                            @foreach ($item['ejemplos'] as $ejemplo)
                                <li>@if ($ejemplo['url'])<a href="{{ $ejemplo['url'] }}">{{ $ejemplo['texto'] }}</a>@else{{ $ejemplo['texto'] }}@endif</li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
            @if ($revision['reemplazo'] !== [])
                <li class="renglon-baja nivel-pendiente">
                    <div class="renglon-baja-titulo"><i class="bi bi-person-fill-exclamation" aria-hidden="true"></i>
                        <strong>Es el único responsable de {{ collect($revision['reemplazo'])->pluck('nombre')->join(', ', ' y ') }}</strong></div>
                    <p class="renglon-baja-efecto">Elige abajo quién lo reemplaza: así las visitas y autorizaciones no se quedan sin respuesta.</p>
                </li>
            @endif
        </ul>
    </div>

    @if ($revision['bloquea'])
        <p class="aviso-bloqueo-baja"><i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>Todavía no se puede dar de baja: atiende primero lo marcado en rojo.</p>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ $volver }}" class="btn-cancelar">Volver</a>
        </div>
    @else
        <form action="{{ $urlBaja }}" method="POST" class="tarjeta p-3 p-md-4">
            @csrf
            @unless ($conVoucher)
                @method('PATCH')
                <input type="hidden" name="activo" value="0">
                <input type="hidden" name="revisado" value="1">
            @endunless

            @if ($revision['vehiculos'] > 0)
                <input type="hidden" name="bajar_vehiculos" value="0">
                <label class="casilla-devolucion mb-2">
                    <input type="checkbox" name="bajar_vehiculos" value="1" checked>
                    Dar de baja sus vehículos ({{ $revision['vehiculos'] }}): su calcomanía deja de servir en la caseta
                </label>
            @endif

            @if ($revision['reemplazo'] !== [])
                <label class="campo-etiqueta" for="baja_reemplazo">¿Quién lo reemplaza como responsable?</label>
                <select id="baja_reemplazo" name="reemplazo_id" class="campo" required>
                    <option value="">-- Elige a una persona --</option>
                    @foreach ($reemplazos as $u)
                        <option value="{{ $u->id }}" @selected((string) $reemplazoAnterior === (string) $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            @endif

            @if ($conVoucher)
                <p class="campo-ayuda">Si ya regresó, recíbelo en su módulo (enlace de arriba). Si no regresó, continúa: queda «No regresó» y se abre su baja con voucher a nombre de quien lo tenía.</p>
            @endif

            <div class="dialogo-acciones">
                <a href="{{ $volver }}" class="btn-cancelar">Cancelar</a>
                <button type="submit" class="btn-rojo-acceso"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Dar de baja</button>
            </div>
        </form>
    @endif
</div>
@endsection
