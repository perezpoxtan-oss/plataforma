@extends('layouts.app')

@section('titulo', 'Respaldos y traslado')

@php
    use App\Services\Traslado\Paquetes;
    $motivos = ['manual' => 'Manual', 'semanal' => 'Semanal automático', 'consola' => 'Desde la consola'];
@endphp

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-truck text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Respaldos y traslado a otro servidor</h1>
            <p>Un solo archivo con la base de datos y todas las fotos, identificaciones, documentos y firmas, para mudar la plataforma sin perder nada.</p>
        </div>
    </div>

    <div class="alert alert-warning small d-flex gap-2" role="note">
        <i class="bi bi-shield-exclamation fs-5" aria-hidden="true"></i>
        <div>Los paquetes contienen <strong>TODA la información, incluidos datos personales</strong>. Descárgalos solo en un equipo de confianza, guárdalos en un lugar seguro (no por correo ni en carpetas compartidas) y bórralos del servidor cuando ya no los necesites. Cada generación, descarga, revisión y borrado queda en la Bitácora de auditoría.</div>
    </div>

    <p class="small mb-3"><a href="{{ route('configuracion.index') }}#t-respaldos"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Configuración</a></p>

    {{-- ================= Paquete de traslado ================= --}}
    <section class="tarjeta p-4 mb-3" aria-labelledby="t-paquete">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <h2 id="t-paquete" class="h5 fw-bold m-0"><i class="bi bi-box-seam me-2 text-primary" aria-hidden="true"></i>Paquete de traslado</h2>
            @unless ($activo)
                <form action="{{ route('traslado.generar') }}" method="POST" class="m-0"
                      data-confirmar="¿Generar el paquete de traslado? Se prepara por partes; puede tardar varios minutos si hay muchas fotos y documentos.">
                    @csrf
                    <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1rem;"><i class="bi bi-box-arrow-down me-1" aria-hidden="true"></i>Generar paquete de traslado</button>
                </form>
            @endunless
        </div>
        <p class="small text-muted mt-2">Incluye el respaldo completo de la base, todos los archivos guardados (hoy ocupan <strong>{{ Paquetes::legible($tamanoArchivos) }}</strong>), una lista con la huella de cada archivo y las instrucciones para restaurarlo. Se conservan los {{ Paquetes::CONSERVAR }} más recientes. Los enlaces de descarga valen {{ \App\Http\Controllers\Administracion\TrasladoController::MINUTOS_ENLACE }} minutos: si vence, recarga la página.</p>

        @if ($activo)
            @include('administracion.traslado._avance', ['trabajo' => $activo, 'titulo' => 'Preparando el paquete de traslado'])
        @elseif ($fallido && $fallido['estado'] === 'error')
            <div class="alert alert-danger small" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>El último paquete no se pudo terminar (@fecha(\Illuminate\Support\Carbon::parse($fallido['inicio']))): {{ $fallido['error'] }} Vuelve a generarlo; si se repite, revisa el espacio libre del servidor.</div>
        @endif

        @if ($paquetes->isEmpty())
            <p class="text-muted small m-0">Todavía no hay paquetes.</p>
        @else
            <div class="d-flex flex-column gap-2">
                @foreach ($paquetes as $p)
                    <div class="border rounded-3 p-2 px-3 d-flex flex-wrap align-items-center gap-2 justify-content-between">
                        <div>
                            <div><i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i><strong>@fecha($p['fecha'])</strong>
                                <span class="chip-catalogo ms-1">{{ $motivos[$p['datos']['motivo'] ?? 'manual'] ?? 'Manual' }}</span></div>
                            <div class="small text-muted">{{ Paquetes::legible($p['bytes']) }} · {{ number_format($p['datos']['archivos'] ?? 0) }} archivos · {{ number_format($p['datos']['filas'] ?? 0) }} registros · por {{ $p['datos']['creado_por_nombre'] ?? '—' }}</div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ $p['enlace'] }}" class="btn btn-sm btn-primary" aria-label="Descargar el paquete del @fecha($p['fecha'])"><i class="bi bi-download me-1" aria-hidden="true"></i>Descargar</a>
                            <form action="{{ route('traslado.verificar') }}" method="POST" class="m-0">
                                @csrf
                                <input type="hidden" name="archivo" value="{{ $p['archivo'] }}">
                                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Verificar</button>
                            </form>
                            <form action="{{ route('traslado.borrar', $p['archivo']) }}" method="POST" class="m-0"
                                  data-confirmar="¿Borrar del servidor el paquete del @fecha($p['fecha'])? Si no lo descargaste, se pierde (la base y los archivos del servidor no se tocan).">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-icono eliminar" title="Borrar" aria-label="Borrar el paquete del @fecha($p['fecha'])"><i class="bi bi-trash" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ================= Automático semanal ================= --}}
    <section class="tarjeta p-4 mb-3" aria-labelledby="t-semanal">
        <h2 id="t-semanal" class="h5 fw-bold"><i class="bi bi-calendar-week me-2 text-primary" aria-hidden="true"></i>Respaldo automático</h2>
        <ul class="small mb-2">
            <li><strong>Base de datos:</strong> todos los días después de las 3:00 y antes de instalar cada versión nueva (se conservan {{ \App\Services\Respaldos\Respaldos::DIAS }} días). Los ves en <a href="{{ route('configuracion.index') }}#t-respaldos">Configuración → Respaldos</a>.</li>
            <li><strong>Fotos, documentos y firmas:</strong> no van en el respaldo diario porque duplicarían todo cada día y podrían llenar el disco. Para ellos está el paquete de traslado: genéralo a mano o enciende el automático semanal.</li>
        </ul>
        <form action="{{ route('traslado.ajustes') }}" method="POST" class="m-0">
            @csrf
            @method('PUT')
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="semanal" name="semanal" value="1" @checked($ajustes['semanal'])>
                <label class="form-check-label" for="semanal">Generar un paquete de traslado automático cada semana</label>
            </div>
            <p class="campo-ayuda">Solo se genera si hay espacio libre suficiente. Sigue valiendo «se conservan los {{ \App\Services\Traslado\Paquetes::CONSERVAR }} más recientes». Un paquete en el mismo servidor no protege si el servidor se pierde: descárgalo de vez en cuando.</p>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Guardar</button>
        </form>
    </section>

    {{-- ================= Verificar ================= --}}
    <section class="tarjeta p-4 mb-3" aria-labelledby="t-verificar">
        <h2 id="t-verificar" class="h5 fw-bold"><i class="bi bi-patch-check me-2 text-primary" aria-hidden="true"></i>Verificar paquete</h2>
        <p class="small text-muted">Revisa que un paquete esté completo y que ningún archivo haya cambiado, <strong>sin restaurar nada</strong>. Usa el botón <strong>Verificar</strong> de la lista de arriba o sube un paquete (hasta {{ Paquetes::legible($limiteSubida) }}; si pesa más, revísalo desde la consola, como explica el manual). El archivo subido se borra al terminar la revisión.</p>
        <form action="{{ route('traslado.verificar') }}" method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
            @csrf
            <div>
                <label for="paquete" class="form-label small fw-bold mb-1">Paquete (.zip)</label>
                <input type="file" id="paquete" name="paquete" accept=".zip,application/zip" class="form-control form-control-sm" required>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Verificar paquete</button>
        </form>

        @foreach ($verificaciones as $v)
            @if ($v['estado'] === 'en_proceso' && ! $activo)
                <div class="mt-3">@include('administracion.traslado._avance', ['trabajo' => $v, 'titulo' => 'Revisando «'.$v['nombre'].'»'])</div>
                @break
            @endif
        @endforeach

        @if ($verificaciones->where('estado', '!=', 'en_proceso')->isNotEmpty())
            <h3 class="h6 fw-bold mt-3">Revisiones recientes</h3>
            <div class="d-flex flex-column gap-2">
                @foreach ($verificaciones->where('estado', '!=', 'en_proceso') as $v)
                    @php $res = $v['resultado'] ?? []; $ok = $v['estado'] === 'listo' && ($res['valido'] ?? false); @endphp
                    <div class="border rounded-3 p-2 px-3 {{ $ok ? 'border-success' : 'border-danger' }}">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <strong><i class="bi {{ $ok ? 'bi-check-circle-fill text-success' : 'bi-x-octagon-fill text-danger' }} me-1" aria-hidden="true"></i>{{ $v['nombre'] }}</strong>
                            <span class="small text-muted">@fecha(\Illuminate\Support\Carbon::parse($v['inicio'])) · {{ $v['creado_por_nombre'] }}</span>
                        </div>
                        @if ($ok)
                            <div class="small">Completo: {{ number_format($res['revisados'] ?? 0) }} archivos revisados, todas las huellas coinciden.
                                @if (! empty($res['manifest']))
                                    Generado el {{ \Illuminate\Support\Carbon::parse($res['manifest']['generado_en'])->format('d/m/Y H:i') }} UTC · versión {{ $res['manifest']['version'] ?? '—' }} · {{ Paquetes::legible((int) ($res['manifest']['bytes'] ?? 0)) }}@if ($res['manifest']['empresa'] ?? null) · empresa {{ $res['manifest']['empresa'] }}@endif.
                                @endif
                            </div>
                        @else
                            <ul class="small text-danger mb-0">
                                @foreach (($res['problemas'] ?? []) ?: [$v['error'] ?? 'No se pudo revisar.'] as $problema)
                                    <li>{{ $problema }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @foreach ($res['avisos'] ?? [] as $avisoV)
                            <div class="small text-warning-emphasis"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ $avisoV }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ================= Restaurar ================= --}}
    <section class="tarjeta p-4 mb-3" aria-labelledby="t-restaurar">
        <h2 id="t-restaurar" class="h5 fw-bold"><i class="bi bi-arrow-repeat me-2 text-primary" aria-hidden="true"></i>Cómo restaurar en otro servidor</h2>
        <ol class="small mb-2">
            <li>Instala en el servidor nuevo la <strong>misma versión</strong> de la plataforma (la ves en Configuración) con una base de datos <strong>vacía</strong>.</li>
            <li>Sube el paquete al servidor nuevo, fuera de la carpeta pública del sitio.</li>
            <li>En la terminal del panel de tu hospedaje, dentro de la carpeta de la plataforma, ejecuta:<br>
                <code>php artisan plataforma:restaurar-traslado RUTA/DEL/PAQUETE.zip --importar-base</code></li>
            <li>Revisa el resumen: todas las huellas deben coincidir y los registros por tabla deben ser los mismos.</li>
        </ol>
        <p class="campo-ayuda m-0">El paso a paso completo está en el Manual: «Respaldos y traslado a otro servidor».</p>
    </section>

    {{-- ================= Exportaciones por empresa ================= --}}
    <section class="tarjeta p-4" aria-labelledby="t-empresas">
        <h2 id="t-empresas" class="h5 fw-bold"><i class="bi bi-building me-2 text-primary" aria-hidden="true"></i>Exportar una empresa</h2>
        <p class="small text-muted">Para entregar a un cliente todo lo suyo (datos, hojas de Excel y archivos). Se hace desde <a href="{{ route('empresas.index') }}">Estructura → Empresas</a>, con el botón <strong>Exportar datos</strong> de cada empresa.</p>
        @if ($exportaciones->isNotEmpty())
            <ul class="small mb-0">
                @foreach ($exportaciones as $x)
                    <li><a href="{{ route('empresas.exportaciones', $x['empresa']->id) }}">{{ $x['empresa']->nombre_comercial }}</a>: última exportación @fecha($x['ultima']['fecha']) ({{ Paquetes::legible($x['ultima']['bytes']) }})</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
