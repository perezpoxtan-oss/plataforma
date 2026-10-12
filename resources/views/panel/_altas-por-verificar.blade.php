{{--
    Aviso de Inicio con desglose (ADR-0006 y ADR-0016): una sola tarjeta que
    agrupa lo pendiente con un enlace directo por parte: altas por verificar
    por padrón (Vehículos, Empresas externas, Personas) o candidatos por etapa.
    $aviso: renglón de Mis pendientes con 'detalle'
--}}
<section class="aviso-pendiente aviso-altas-verificar mt-3" aria-labelledby="titulo-{{ $aviso['clave'] }}">
    <span class="icono"><i class="bi {{ $aviso['icono'] }}" aria-hidden="true"></i></span>
    <div class="flex-grow-1">
        <strong class="d-block" id="titulo-{{ $aviso['clave'] }}">{{ $aviso['titulo'] }} <span class="conteo-inicio">{{ $aviso['total'] }}</span></strong>
        <span class="small">{{ $aviso['texto'] }}</span>
        <div class="grupos-altas-verificar">
            @foreach ($aviso['detalle'] as $g)
                <a href="{{ $g['url'] }}" class="grupo-alta-verificar">
                    <i class="bi {{ $g['icono'] }}" aria-hidden="true"></i>
                    <span>{{ $g['titulo'] }}</span>
                    <span class="conteo">{{ $g['total'] }}</span>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            @endforeach
        </div>
    </div>
</section>
