{{-- Módulos que se venden (productos activos que están en algún plan o paquete publicado) --}}
<section class="lp-seccion lp-seccion-blanca" id="modulos" aria-labelledby="titulo-modulos">
    <div class="lp-envoltura">
        <div class="lp-encabezado">
            <h2 id="titulo-modulos">Una plataforma, por módulos</h2>
            <p>Cada plan reúne los módulos que necesita tu operación. Empieza con la caseta y suma lo demás cuando lo necesites.</p>
        </div>
        <div class="lp-productos">
            @foreach ($productos as $producto)
                <div class="lp-producto">
                    <span class="lp-producto-icono"><i class="bi {{ $producto->icono }}" aria-hidden="true"></i></span>
                    <div>
                        <h3>{{ $producto->nombre }}</h3>
                        @if ($producto->descripcion)<p>{{ $producto->descripcion }}</p>@endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
