{{-- Calculadora: sin JavaScript envía lo elegido a /contratar; con JavaScript recalcula al momento --}}
<section class="lp-seccion" id="calculadora" aria-labelledby="titulo-calculadora">
    <div class="lp-envoltura">
        <div class="lp-encabezado">
            <h2 id="titulo-calculadora">Calcula tu mensualidad</h2>
            <p>Elige un plan, cuántas sedes, si pagas mes con mes o el año, y los paquetes que quieras sumar. Verás el total con IVA al momento.</p>
        </div>
        <form action="{{ route('planes.contratar') }}" method="GET" class="lp-calculo" data-calculadora data-catalogo="{{ json_encode($calculadora) }}">
            <div>
                @include('publico.planes._seleccion', ['prefijo' => 'calc', 'eleccion' => $eleccionCalculadora, 'campoCodigo' => 'codigo'])
            </div>
            @include('publico.planes._resumen', ['prefijo' => 'calc', 'calculo' => $calculoCalculadora, 'boton' => 'Contratar con esta selección'])
        </form>
    </div>
</section>
