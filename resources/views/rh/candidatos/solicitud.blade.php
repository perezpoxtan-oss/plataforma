{{--
    Hoja impresa «Solicitud de empleo» para el expediente (formato general de
    México): logo de la empresa, folio, fecha, foto tomada en caseta, todas las
    secciones y la firma del candidato. Solo con candidatos.ver.
--}}
@use('App\Models\Candidato')
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $hora = app(\App\Support\HoraLocal::class);
    $siNo = fn ($v) => $v === null ? '—' : ($v ? 'Sí' : 'No');
    $mes = fn (?string $m) => $m ? substr($m, 5, 2).'/'.substr($m, 0, 4) : null;
    $folio = 'SOL-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT);
    $vacio = '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Solicitud de empleo {{ $folio }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-pase">
<main>
    <div class="controles-impresion">
        <h1>Solicitud de empleo {{ $folio }}</h1>
        <p>Hoja carta para el expediente del candidato. Contiene datos personales: guárdala en un lugar seguro.</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir solicitud</button>
            <a href="{{ route('candidatos.show', $c->id) }}" class="btn-cerrar-calcomania"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Volver a la ficha</a>
        </div>
    </div>

    <article class="hoja-pase hoja-solicitud">
        <header class="hoja-pase-encabezado">
            <div class="hoja-pase-empresa">
                @if ($logo)<img src="{{ $logo }}" alt="Logo de {{ $empresa->nombre_comercial }}" class="hoja-pase-logo">@endif
                <div>
                    <div class="hoja-pase-nombre-empresa">{{ $empresa->nombre_comercial }}</div>
                    @if ($empresa->razon_social && $empresa->razon_social !== $empresa->nombre_comercial)<div class="hoja-pase-sede">{{ $empresa->razon_social }}</div>@endif
                    <div class="hoja-pase-sede">Sede: {{ $c->sede?->nombre }}</div>
                </div>
            </div>
            <div class="hoja-pase-folio-qr">
                <div class="hoja-pase-folio">
                    <div class="hoja-pase-titulo">Solicitud de empleo</div>
                    <div class="hoja-pase-numero">{{ $folio }}</div>
                    <div class="hoja-solicitud-fecha">Fecha: {{ $hora->formatear($c->firma_en ?? $c->created_at, 'd/m/Y') }}</div>
                </div>
                <div class="hoja-solicitud-foto">
                    @if ($c->acceso?->foto_persona)
                        <img src="{{ route('accesos.foto-persona', $c->acceso_id) }}" alt="Foto de {{ $c->nombre_completo }}">
                    @else
                        <span>Foto</span>
                    @endif
                </div>
            </div>
        </header>

        <section class="hoja-solicitud-seccion">
            <h2>Puesto solicitado</h2>
            <table class="hoja-solicitud-tabla">
                <tr><th>Puesto</th><td>{{ $c->puestoVisible() ?? $vacio }}</td><th>Departamento</th><td>{{ $c->departamento?->nombre ?? $vacio }}</td></tr>
                <tr><th>Sueldo que espera</th><td>{{ $c->pretension !== null ? '$'.number_format((float) $c->pretension, 2).' al mes' : $vacio }}</td><th>Puede empezar</th><td>{{ $c->fecha_inicio_posible?->format('d/m/Y') ?? (Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? $vacio) }}</td></tr>
            </table>
        </section>

        <section class="hoja-solicitud-seccion">
            <h2>Datos personales</h2>
            <table class="hoja-solicitud-tabla">
                <tr><th>Nombre(s)</th><td>{{ $c->partesNombre()['nombre'] ?: $vacio }}</td><th>Apellidos</th><td>{{ trim($c->partesNombre()['paterno'].' '.$c->partesNombre()['materno']) ?: $vacio }}</td></tr>
                <tr><th>Fecha de nacimiento</th><td>{{ $c->fecha_nacimiento?->format('d/m/Y') ?? $vacio }}</td><th>Lugar de nacimiento</th><td>{{ $c->lugar_nacimiento ?? $vacio }}</td></tr>
                <tr><th>Sexo</th><td>{{ Candidato::SEXOS[$c->sexo] ?? $vacio }}</td><th>Nacionalidad</th><td>{{ $c->nacionalidad ?? $vacio }}</td></tr>
                <tr><th>Estado civil</th><td>{{ Candidato::ESTADOS_CIVILES[$c->estado_civil] ?? $vacio }}</td><th>Dependientes económicos</th><td>{{ $c->dependientes ?? $vacio }}</td></tr>
                <tr><th>CURP</th><td class="dato-oficial">{{ $c->curp ?? $vacio }}</td><th>RFC</th><td class="dato-oficial">{{ $c->rfc ?? $vacio }}</td></tr>
                <tr><th>NSS</th><td class="dato-oficial">{{ $c->nss ?? $vacio }}</td><th>Licencia de manejo</th><td>{{ $c->licencia_tipo ? Candidato::LICENCIAS[$c->licencia_tipo].($c->licencia_vigencia ? ', vence '.$c->licencia_vigencia->format('d/m/Y') : '') : 'No' }}</td></tr>
                <tr><th>Teléfono celular</th><td>{{ $c->telefono ?? $vacio }}</td><th>Correo</th><td>{{ $c->correo ?? $vacio }}</td></tr>
            </table>
        </section>

        <section class="hoja-solicitud-seccion">
            <h2>Domicilio y contacto de emergencia</h2>
            <table class="hoja-solicitud-tabla">
                <tr><th>Tiempo de residencia</th><td>{{ $c->tiempo_residencia ?? $vacio }}</td><th>Teléfono fijo</th><td>{{ $c->telefono_fijo ?? $vacio }}</td></tr>
                <tr><th>Domicilio</th><td colspan="3">{{ $c->domicilioCompleto() ?? ($c->ciudad ?? $vacio) }}</td></tr>
                <tr><th>En emergencia avisar a</th><td>{{ $c->emergencia_nombre ?? $vacio }}{{ $c->emergencia_parentesco ? ' ('.$c->emergencia_parentesco.')' : '' }}</td><th>Teléfono</th><td>{{ $c->emergencia_telefono ?? $vacio }}</td></tr>
            </table>
        </section>

        <section class="hoja-solicitud-seccion">
            <h2>Escolaridad</h2>
            <table class="hoja-solicitud-tabla lista">
                <thead><tr><th>Nivel</th><th>Escuela</th><th>Periodo</th><th>Documento</th></tr></thead>
                <tbody>
                @forelse ((array) $c->escolaridad as $e)
                    <tr><td>{{ Candidato::ESCOLARIDAD[$e['nivel'] ?? ''] ?? $vacio }}{{ ! empty($e['titulo']) ? ' · '.$e['titulo'] : '' }}</td><td>{{ $e['institucion'] ?? $vacio }}</td><td>{{ $e['periodo'] ?? $vacio }}</td>
                        <td>{{ ! empty($e['documento']) ? (Candidato::DOCUMENTOS_ESTUDIO[$e['documento']] ?? $e['documento']) : (! empty($e['concluido']) ? 'Terminado' : 'Sin terminar') }}</td></tr>
                @empty
                    <tr><td colspan="4">Sin capturar.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="hoja-solicitud-seccion">
            <h2>Empleos anteriores</h2>
            <table class="hoja-solicitud-tabla lista">
                <thead><tr><th>Empresa y puesto</th><th>Periodo</th><th>Sueldo final</th><th>Jefe inmediato</th><th>Motivo de salida</th><th>¿Referencias?</th></tr></thead>
                <tbody>
                @forelse ((array) $c->experiencia as $e)
                    <tr><td><strong>{{ $e['empresa'] ?? $vacio }}</strong>{{ ! empty($e['puesto']) ? ' · '.$e['puesto'] : '' }}</td>
                        <td>{{ ! empty($e['ingreso']) ? $mes($e['ingreso']).' a '.($mes($e['salida'] ?? null) ?? 'la fecha') : (isset($e['anos']) && $e['anos'] !== null ? $e['anos'].' año(s)' : $vacio) }}</td>
                        <td>{{ ! empty($e['sueldo_final']) ? '$'.number_format((float) $e['sueldo_final'], 2) : $vacio }}</td>
                        <td>{{ $e['jefe'] ?? $vacio }}{{ ! empty($e['jefe_telefono']) ? ' · '.$e['jefe_telefono'] : '' }}</td>
                        <td>{{ $e['motivo_salida'] ?? $vacio }}</td>
                        <td>{{ ['si' => 'Sí', 'no' => 'No'][$e['pedir_referencias'] ?? ''] ?? $vacio }}</td></tr>
                @empty
                    <tr><td colspan="6">Sin capturar.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="hoja-solicitud-seccion">
            <h2>Referencias</h2>
            <table class="hoja-solicitud-tabla lista">
                <thead><tr><th>Tipo</th><th>Nombre</th><th>Teléfono</th><th>Relación u ocupación</th><th>Años de conocerlo</th></tr></thead>
                <tbody>
                @php $hayReferencias = false; @endphp
                @foreach (['referencias' => 'Personal', 'referencias_laborales' => 'Laboral'] as $lista => $tipo)
                    @foreach ((array) $c->{$lista} as $r)
                        @php $hayReferencias = true; @endphp
                        <tr><td>{{ $tipo }}</td><td>{{ $r['nombre'] ?? $vacio }}</td><td>{{ $r['telefono'] ?? $vacio }}</td><td>{{ $r['relacion'] ?? $vacio }}</td><td>{{ $r['anos_conocerlo'] ?? $vacio }}</td></tr>
                    @endforeach
                @endforeach
                @unless ($hayReferencias)
                    <tr><td colspan="5">Sin capturar.</td></tr>
                @endunless
                </tbody>
            </table>
        </section>

        <section class="hoja-solicitud-seccion">
            <h2>Datos generales</h2>
            <table class="hoja-solicitud-tabla">
                <tr><th>¿Cómo se enteró de la vacante?</th><td>{{ Candidato::MEDIOS_VACANTE[$c->medio_vacante] ?? $vacio }}</td><th>¿Ha trabajado antes aquí?</th><td>{{ $siNo($c->trabajo_antes_aqui) }}</td></tr>
                <tr><th>¿Familiares en la empresa?</th><td colspan="3">{{ $siNo($c->tiene_familiares) }}{{ $c->familiares_nombre ? ' · '.$c->familiares_nombre : '' }}</td></tr>
                <tr><th>¿Puede rolar turnos?</th><td>{{ $siNo($c->rolar_turnos) }}</td><th>¿Puede viajar?</th><td>{{ $siNo($c->puede_viajar) }}</td></tr>
                <tr><th>¿Puede cambiar de residencia?</th><td>{{ $siNo($c->cambiar_residencia) }}</td><th>Idiomas</th><td>{{ $c->idiomas ?? $vacio }}</td></tr>
                <tr><th>Habilidades</th><td colspan="3">{{ $c->habilidades ?? $vacio }}</td></tr>
            </table>
        </section>

        <section class="hoja-solicitud-seccion hoja-solicitud-firma">
            <p class="hoja-solicitud-declaracion">Declaro que la información de esta solicitud es verdadera y autorizo que se verifiquen mis datos y referencias.</p>
            <div class="hoja-solicitud-firma-caja">
                @if ($c->firma_ruta)
                    <img src="{{ route('candidatos.firma', $c->id) }}" alt="Firma de {{ $c->nombre_completo }}">
                @endif
                <div class="hoja-solicitud-linea">{{ $c->nombre_completo }}</div>
                <div class="hoja-solicitud-nota">
                    @if ($c->firma_ruta)
                        Firmó {{ Candidato::FIRMAS_MEDIO[$c->firma_medio] ?? '' }} el {{ $hora->formatear($c->firma_en, 'd/m/Y H:i') }}{{ $c->firmaCapturadaPor ? ' (capturó '.$c->firmaCapturadaPor->name.')' : '' }}
                    @else
                        Firma del solicitante
                    @endif
                </div>
            </div>
            @if ($c->privacidad_aceptada_en)
                <p class="hoja-solicitud-nota">Aviso de privacidad aceptado el {{ $hora->formatear($c->privacidad_aceptada_en, 'd/m/Y H:i') }} (versión {{ substr((string) $c->privacidad_version, 0, 10) }}).</p>
            @endif
        </section>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
