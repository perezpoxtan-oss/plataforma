@extends('publico.planes.plantilla')

@section('titulo', 'Planes y precios')

@section('contenido')
<div class="lp-envoltura lp-pagina-titulo">
    <h1>Planes y precios</h1>
    <p>Precio por sede al mes {{ $ajustes['leyenda_iva'] }}. Calcula tu total y envía tu solicitud: te contactamos para confirmar.</p>
</div>
@include('publico.planes._planes')
@include('publico.planes._paquetes')
@include('publico.planes._calculadora')
@include('publico.planes._productos')
@include('publico.planes._preguntas')
@include('publico.planes._llamado')
@endsection
