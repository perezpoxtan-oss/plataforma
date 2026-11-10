{{--
    Cartel de la vacante (hoja carta) para la entrada o la caseta: logo,
    «¡Estamos contratando!», título, sedes, horario, sueldo, requisitos,
    prestaciones y el QR que abre la vacante en la bolsa de trabajo.
--}}
@use('App\Models\Vacante')
@use('App\Models\Candidato')
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Cartel: {{ $v->titulo }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-pase">
<main>
    <div class="controles-impresion">
        <h1>Cartel de la vacante</h1>
        <p>Imprímelo en hoja carta y pégalo en la entrada o en la caseta. Quien escanee el QR con su celular ve la vacante y se postula.</p>
        @unless ($qr)
            <p class="aviso-cartel-sin-qr"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ $v->estado !== 'publicada' ? 'La vacante no está publicada: el cartel sale sin QR.' : 'La bolsa de trabajo en internet está apagada: el cartel sale sin QR.' }}</p>
        @endunless
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir cartel</button>
            <a href="{{ route('vacantes.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Volver a Vacantes</a>
        </div>
    </div>

    <article class="hoja-cartel">
        <header class="hoja-cartel-cabeza">
            @if ($logo)<img src="{{ $logo }}" alt="Logo de {{ $empresa->nombre_comercial }}" class="hoja-cartel-logo">@endif
            <div class="hoja-cartel-empresa">{{ $empresa->nombre_comercial }}</div>
        </header>
        <p class="hoja-cartel-gancho">¡Estamos contratando!</p>
        <h2 class="hoja-cartel-titulo">{{ $v->titulo }}</h2>
        <p class="hoja-cartel-sub">
            {{ $v->plazas }} plaza{{ $v->plazas !== 1 ? 's' : '' }}{{ $v->departamento ? ' · '.$v->departamento->nombre : '' }} · {{ $v->sedesTexto() }}
        </p>

        <div class="hoja-cartel-cuerpo">
            <div class="hoja-cartel-datos">
                <p><i class="bi bi-cash-coin" aria-hidden="true"></i> <strong>{{ $v->sueldoTexto() }}</strong></p>
                @if ($v->jornada || $v->tipo_contrato)<p><i class="bi bi-briefcase" aria-hidden="true"></i> {{ collect([Vacante::JORNADAS[$v->jornada] ?? null, Vacante::CONTRATOS[$v->tipo_contrato] ?? null])->filter()->join(' · ') }}</p>@endif
                @if ($v->turno || $v->horario)<p><i class="bi bi-clock" aria-hidden="true"></i> {{ collect([$v->turno ? $v->turno->nombre.' ('.$v->turno->inicio().' a '.$v->turno->fin().')' : null, $v->horario])->filter()->join(' · ') }}</p>@endif
                @if ($v->listaRequisitos() !== [] || $v->escolaridad_minima || $v->experiencia)
                    <h3>Requisitos</h3>
                    <ul>
                        @if ($v->escolaridad_minima)<li>Escolaridad: {{ Candidato::ESCOLARIDAD[$v->escolaridad_minima] ?? $v->escolaridad_minima }} o más</li>@endif
                        @if ($v->experiencia)<li>Experiencia: {{ $v->experiencia }}</li>@endif
                        @foreach (array_slice($v->listaRequisitos(), 0, 8) as $r)<li>{{ $r }}</li>@endforeach
                    </ul>
                @endif
                @if ($v->listaPrestaciones() !== [])
                    <h3>Ofrecemos</h3>
                    <ul>
                        @foreach (array_slice($v->listaPrestaciones(), 0, 8) as $p)<li>{{ $p }}</li>@endforeach
                    </ul>
                @endif
            </div>
            @if ($qr)
                <div class="hoja-cartel-qr">
                    <div class="hoja-cartel-qr-imagen" role="img" aria-label="Código QR para postularse a {{ $v->titulo }}">{!! $qr !!}</div>
                    <p>Escanea con tu celular<br>y <strong>postúlate</strong></p>
                </div>
            @endif
        </div>

        <footer class="hoja-cartel-pie">
            @if ($v->contacto_nombre || $v->contacto_telefono || $v->contacto_correo)
                <p>Informes: {{ collect([$v->contacto_nombre, $v->contacto_telefono, $v->contacto_correo])->filter()->join(' · ') }}</p>
            @endif
            <p>También puedes preguntar en la caseta de vigilancia.</p>
        </footer>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
