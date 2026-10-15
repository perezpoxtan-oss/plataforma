@extends('layouts.app')

@section('titulo', 'Catálogo comercial')

@php
    use App\Services\Comercial\Calculadora;
    use App\Models\SolicitudCompra;
    $dinero = fn ($v) => Calculadora::formato(Calculadora::centavos($v));
    $pestanas = \App\Http\Controllers\Administracion\CatalogoComercialController::PESTANAS;
    $iva = rtrim(rtrim(number_format($ajustes['iva_porcentaje'], 2), '0'), '.');
@endphp

@section('contenido')
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-shop text-success" aria-hidden="true"></i></div>
            <div>
                <h1>Catálogo comercial</h1>
                <p>Lo que se ofrece en la página pública de planes: productos, planes, paquetes, precios y las solicitudes que llegan.</p>
            </div>
        </div>
        <a href="{{ route('catalogo-comercial.vista-previa') }}" class="btn btn-outline-primary fw-bold" target="_blank" rel="noopener"><i class="bi bi-eye me-1" aria-hidden="true"></i>Ver la página</a>
    </div>

    @unless ($ajustes['landing_publicada'])
        <div class="alert alert-warning small" role="status">
            <i class="bi bi-eye-slash-fill me-1" aria-hidden="true"></i>
            <strong>La página pública no está publicada.</strong> Los visitantes siguen viendo la pantalla de acceso. Los precios iniciales son de ejemplo: revísalos y después publícala en <a href="{{ route('catalogo-comercial.index', ['pestana' => 'ajustes']) }}#ajustes">Ajustes</a>.
        </div>
    @endunless

    <div class="pestanas-pase" role="tablist" aria-label="Secciones del catálogo comercial" data-pestanas data-inicial="{{ $pestana }}">
        @foreach ($pestanas as $clave => [$titulo, $icono])
            <button type="button" role="tab" id="pestana-{{ $clave }}" class="pestana-pase {{ $clave === $pestana ? 'activa' : '' }}"
                    aria-selected="{{ $clave === $pestana ? 'true' : 'false' }}" aria-controls="panel-{{ $clave }}" tabindex="{{ $clave === $pestana ? '0' : '-1' }}"
                    data-pestana="{{ $clave }}"><i class="bi {{ $icono }}" aria-hidden="true"></i> {{ $titulo }}@if ($clave === 'solicitudes' && $nuevas > 0) <span class="badge text-bg-danger ms-1">{{ $nuevas }}</span>@endif</button>
        @endforeach
    </div>

    {{-- ===== Solicitudes ===== --}}
    <section id="panel-solicitudes" class="panel-pase" role="tabpanel" aria-labelledby="pestana-solicitudes" tabindex="0" data-panel="solicitudes" @if ($pestana !== 'solicitudes') hidden @endif>
        <div class="filtros-estado mb-3" role="group" aria-label="Filtrar solicitudes">
            @foreach (['abiertas' => 'Por atender', 'nueva' => 'Nuevas', 'pagada' => 'Pagadas', 'cancelada' => 'Canceladas', 'todas' => 'Todas'] as $valor => $texto)
                <a href="{{ route('catalogo-comercial.index', ['pestana' => 'solicitudes', 'estado' => $valor]) }}" class="btn btn-sm fw-bold {{ $estado === $valor ? 'btn-dark' : 'btn-outline-secondary' }}" @if ($estado === $valor) aria-current="true" @endif>{{ $texto }}</a>
            @endforeach
        </div>
        @if ($solicitudes->isEmpty())
            <div class="tarjeta estado-vacio p-4">
                <div class="icono"><i class="bi bi-inbox" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">No hay solicitudes en esta vista. Cuando alguien envíe el formulario «Contratar» de la página, llega aquí, a tu campana y a tu correo.</p>
            </div>
        @else
            <div class="tabla-scroll">
                <table class="table align-middle mb-0 bg-white">
                    <thead><tr><th>Folio</th><th>Interesado</th><th>Lo que pidió</th><th class="text-end">Total al mes</th><th>Estado</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                        @foreach ($solicitudes as $s)
                            @php $plan = collect($s->detalle['renglones'] ?? [])->firstWhere('tipo', 'plan'); @endphp
                            <tr>
                                <td class="fw-bold text-nowrap">{{ $s->folio }}<div class="small text-muted fw-normal">@fecha($s->created_at)</div></td>
                                <td>{{ $s->nombre }}@if ($s->empresa)<div class="small text-muted">{{ $s->empresa }}</div>@endif</td>
                                <td>{{ $plan['nombre'] ?? 'Sin plan' }}, {{ $s->sedes }} {{ $s->sedes === 1 ? 'sede' : 'sedes' }}
                                    @if (count($s->detalle['renglones'] ?? []) > 1)<div class="small text-muted">+ {{ count($s->detalle['renglones']) - ($plan ? 1 : 0) }} paquete(s)</div>@endif</td>
                                <td class="text-end text-nowrap">{{ $s->requiere_cotizacion ? 'A cotizar' : $dinero($s->mensual_total) }}</td>
                                <td>
                                    <span class="etiqueta-estado {{ in_array($s->estado, ['pagada'], true) ? 'activo' : ($s->estado === 'cancelada' ? 'inactivo' : 'pendiente') }}">{{ $s->estadoTexto() }}</span>
                                    @if ($s->estado === 'nueva' && ($reloj = \App\Support\RelojVoBo::de($s->created_at)))
                                        <div class="mt-1"><span class="reloj-vobo espera-{{ $reloj['nivel'] }}"><i class="bi bi-clock" aria-hidden="true"></i>Sin atender {{ $reloj['hace'] }} ({{ $reloj['etiqueta'] }})</span></div>
                                    @endif
                                </td>
                                <td class="text-end"><a href="{{ route('catalogo-comercial.solicitudes.show', $s->id) }}" class="btn btn-sm btn-success fw-bold">Atender</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $solicitudes->links() }}</div>
        @endif
    </section>

    {{-- ===== Planes ===== --}}
    <section id="panel-planes" class="panel-pase" role="tabpanel" aria-labelledby="pestana-planes" tabindex="0" data-panel="planes" @if ($pestana !== 'planes') hidden @endif>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
            <p class="small text-muted m-0">Precio por sede al mes, antes de IVA ({{ $iva }} %). El orden de esta lista es el de la página.</p>
            @if ($puedeEditar)<a href="{{ route('catalogo-comercial.crear', 'planes') }}" class="btn btn-success fw-bold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo plan</a>@endif
        </div>
        <div class="tabla-scroll">
            <table class="table align-middle mb-0 bg-white">
                <thead><tr><th>Plan</th><th class="text-end">Por sede al mes</th><th>Incluye</th><th>En la página</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                    @forelse ($planes as $i => $r)
                        <tr id="planes-{{ $r->id }}" @class(['text-muted' => ! $r->activo])>
                            <td class="fw-bold">{{ $r->nombre }} @if ($r->destacado)<span class="badge text-bg-primary">Recomendado</span>@endif
                                <div class="small text-muted fw-normal">Desde {{ $r->sedes_minimas }} {{ $r->sedes_minimas === 1 ? 'sede' : 'sedes' }} · {{ $r->usuarios_por_sede === null ? 'usuarios ilimitados' : $r->usuarios_por_sede.' usuarios por sede' }}</div></td>
                            <td class="text-end text-nowrap">{{ $r->cotizar ? 'Cotizar' : $dinero($r->precio_por_sede_mensual) }}</td>
                            <td class="small">{{ $r->productos->pluck('nombre')->join(', ') ?: 'Sin productos' }}</td>
                            <td>@include('administracion.catalogo-comercial._en-pagina')</td>
                            <td>@if ($puedeEditar)@include('administracion.catalogo-comercial._acciones', ['tipo' => 'planes', 'primero' => $i === 0, 'ultimo' => $i === $planes->count() - 1])@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted small">Todavía no hay planes. Toca «Nuevo plan».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ===== Paquetes ===== --}}
    <section id="panel-paquetes" class="panel-pase" role="tabpanel" aria-labelledby="pestana-paquetes" tabindex="0" data-panel="paquetes" @if ($pestana !== 'paquetes') hidden @endif>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
            <p class="small text-muted m-0">Complementos que se suman a un plan: por suscripción (al mes) o de venta directa (pago único).</p>
            @if ($puedeEditar)<a href="{{ route('catalogo-comercial.crear', 'paquetes') }}" class="btn btn-success fw-bold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo paquete</a>@endif
        </div>
        <div class="tabla-scroll">
            <table class="table align-middle mb-0 bg-white">
                <thead><tr><th>Paquete</th><th>Cobro</th><th class="text-end">Precio</th><th>Se suma a</th><th>En la página</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                    @forelse ($paquetes as $i => $r)
                        <tr id="paquetes-{{ $r->id }}" @class(['text-muted' => ! $r->activo])>
                            <td class="fw-bold">{{ $r->nombre }}<div class="small text-muted fw-normal">{{ $r->productos->pluck('nombre')->join(', ') }}</div></td>
                            <td class="small">{{ \App\Models\Paquete::MODALIDADES[$r->modalidad] ?? $r->modalidad }}</td>
                            <td class="text-end text-nowrap">{{ $dinero($r->precio) }}<div class="small text-muted">{{ $r->por_sede ? 'por sede' : 'en total' }}</div></td>
                            <td class="small">{{ $r->planes->isEmpty() ? 'Cualquier plan' : $r->planes->pluck('nombre')->join(', ') }}</td>
                            <td>@include('administracion.catalogo-comercial._en-pagina')</td>
                            <td>@if ($puedeEditar)@include('administracion.catalogo-comercial._acciones', ['tipo' => 'paquetes', 'primero' => $i === 0, 'ultimo' => $i === $paquetes->count() - 1])@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted small">Todavía no hay paquetes. Toca «Nuevo paquete».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ===== Productos ===== --}}
    <section id="panel-productos" class="panel-pase" role="tabpanel" aria-labelledby="pestana-productos" tabindex="0" data-panel="productos" @if ($pestana !== 'productos') hidden @endif>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
            <p class="small text-muted m-0">Cada producto agrupa módulos de la plataforma. Los planes y paquetes dicen qué productos incluyen.</p>
            @if ($puedeEditar)<a href="{{ route('catalogo-comercial.crear', 'productos') }}" class="btn btn-success fw-bold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo producto</a>@endif
        </div>
        <div class="tabla-scroll">
            <table class="table align-middle mb-0 bg-white">
                <thead><tr><th>Producto</th><th>Módulos que habilita</th><th>En planes</th><th>Estado</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                    @forelse ($productos as $i => $r)
                        <tr id="productos-{{ $r->id }}" @class(['text-muted' => ! $r->activo])>
                            <td class="fw-bold"><i class="bi {{ $r->icono }} me-1 text-primary" aria-hidden="true"></i>{{ $r->nombre }}<div class="small text-muted fw-normal">{{ $r->descripcion }}</div></td>
                            <td class="small">{{ $r->modulos->pluck('nombre')->join(', ') ?: 'Ninguno' }}</td>
                            <td>{{ $r->planes_count }}</td>
                            <td><span class="etiqueta-estado {{ $r->activo ? 'activo' : 'inactivo' }}">{{ $r->activo ? 'ACTIVO' : 'DE BAJA' }}</span></td>
                            <td>@if ($puedeEditar)@include('administracion.catalogo-comercial._acciones', ['tipo' => 'productos', 'primero' => $i === 0, 'ultimo' => $i === $productos->count() - 1])@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted small">Todavía no hay productos. Toca «Nuevo producto».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ===== Ajustes ===== --}}
    <section id="panel-ajustes" class="panel-pase" role="tabpanel" aria-labelledby="pestana-ajustes" tabindex="0" data-panel="ajustes" @if ($pestana !== 'ajustes') hidden @endif>
        @include('administracion.catalogo-comercial._ajustes')
    </section>

@push('scripts')
    <script src="{{ asset('js/pestanas.js') }}?v={{ @filemtime(public_path('js/pestanas.js')) ?: '1' }}"></script>
@endpush
@endsection
