@extends('publico.planes.plantilla')

@section('titulo', $ajustes['portada_titulo'])

@section('contenido')
<section class="lp-portada" aria-labelledby="titulo-portada">
    <div class="lp-envoltura lp-portada-rejilla">
        <div>
            <h1 id="titulo-portada">{{ $ajustes['portada_titulo'] }}</h1>
            @if ($ajustes['portada_subtitulo'])<p class="lp-portada-subtitulo">{{ $ajustes['portada_subtitulo'] }}</p>@endif
            <div class="lp-portada-botones">
                <a href="#planes" class="lp-boton lp-boton-principal">Ver planes y precios</a>
                @if ($ajustes['prueba_habilitada'])
                    <a href="{{ route('planes.contratar', ['prueba' => 1]) }}" class="lp-boton lp-boton-borde">Probar gratis {{ $ajustes['dias_prueba'] }} días</a>
                @else
                    <a href="{{ route('planes.contratar') }}" class="lp-boton lp-boton-borde">Solicitar información</a>
                @endif
            </div>
            @if ($ajustes['beneficios'] !== [])
                <ul class="lp-beneficios">
                    @foreach ($ajustes['beneficios'] as $beneficio)
                        <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>{{ $beneficio }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Así se ve la caseta en la plataforma (ejemplo ilustrativo, datos ficticios) --}}
        <figure class="lp-bitacora" aria-label="Ejemplo de la bitácora de accesos de una caseta">
            <div class="lp-bitacora-cabeza">
                <span class="lp-bitacora-titulo"><i class="bi bi-door-open-fill" aria-hidden="true"></i>Caseta principal</span>
                <span class="lp-en-vivo">En vivo</span>
            </div>
            <ul class="lp-bitacora-lista">
                <li class="lp-registro">
                    <span class="lp-registro-hora">07:42</span>
                    <span class="lp-registro-icono"><i class="bi bi-truck" aria-hidden="true"></i></span>
                    <span><span class="lp-registro-nombre">Refrigeración del Sur</span><br>
                        <span class="lp-registro-detalle">Proveedor para Mantenimiento</span><br>
                        <span class="lp-estado lp-estado-verde">Autorizado por Mantenimiento</span></span>
                </li>
                <li class="lp-registro">
                    <span class="lp-registro-hora">07:55</span>
                    <span class="lp-registro-icono"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                    <span><span class="lp-registro-nombre">Laura Méndez</span><br>
                        <span class="lp-registro-detalle">Candidata, viene a entrevista</span><br>
                        <span class="lp-estado lp-estado-ambar">Espera a Recursos Humanos: 3 min</span></span>
                </li>
                <li class="lp-registro">
                    <span class="lp-registro-hora">08:10</span>
                    <span class="lp-registro-icono"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></span>
                    <span><span class="lp-registro-nombre">Pase de salida: laptop de Sistemas</span><br>
                        <span class="lp-registro-detalle">Firmado por la jefa de Sistemas</span><br>
                        <span class="lp-estado lp-estado-azul">Salida registrada</span></span>
                </li>
                <li class="lp-registro">
                    <span class="lp-registro-hora">08:16</span>
                    <span class="lp-registro-icono"><i class="bi bi-car-front" aria-hidden="true"></i></span>
                    <span><span class="lp-registro-nombre">Placas YZT-482-B</span><br>
                        <span class="lp-registro-detalle">Ya registrado: se llenó solo</span><br>
                        <span class="lp-estado lp-estado-verde">En sitio</span></span>
                </li>
            </ul>
            <figcaption class="lp-bitacora-pie"><i class="bi bi-bell" aria-hidden="true"></i>El responsable autoriza desde la campana de su celular.</figcaption>
        </figure>
    </div>
</section>

@include('publico.planes._productos')
@include('publico.planes._planes')
@include('publico.planes._paquetes')
@include('publico.planes._calculadora')
@include('publico.planes._preguntas')
@include('publico.planes._llamado')
@endsection
