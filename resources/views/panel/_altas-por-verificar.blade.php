{{--
    Aviso de Inicio "Altas por verificar" (ADR-0006): una sola tarjeta que
    agrupa por padrón (Vehículos, Empresas externas, Personas) lo que la caseta
    registró desde Operación. Solo los padrones que el usuario puede editar;
    cada uno lleva a su lista con la píldora "Pendientes de verificar" encendida.
    $aviso: entrada de $pendientes con 'grupos'
--}}
<section class="aviso-pendiente aviso-altas-verificar mt-3" aria-labelledby="titulo-altas-verificar">
    <span class="icono"><i class="bi {{ $aviso['icono'] }}" aria-hidden="true"></i></span>
    <div class="flex-grow-1">
        <strong class="d-block" id="titulo-altas-verificar">{{ $aviso['titulo'] }}</strong>
        <span class="small">{{ $aviso['texto'] }}</span>
        <div class="grupos-altas-verificar">
            @foreach ($aviso['grupos'] as $g)
                <a href="{{ $g['ruta'] }}" class="grupo-alta-verificar">
                    <i class="bi {{ $g['icono'] }}" aria-hidden="true"></i>
                    <span>{{ $g['nombre'] }}</span>
                    <span class="conteo">{{ $g['total'] }}</span>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            @endforeach
        </div>
    </div>
</section>
