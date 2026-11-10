{{-- Suscripciones: lista de clientes (pestaña Clientes) o solo los nuevos sin revisar (pestaña Por revisar). $clientes, $soloPorRevisar --}}
@php
    use App\Models\Suscripcion;
    use App\Services\Comercial\Calculadora;
    use App\Services\Suscripciones\ListaClientes;
    $soloPorRevisar = $soloPorRevisar ?? false;
@endphp
@if ($soloPorRevisar)
    <p class="small text-muted">Clientes que se activaron solos al pagar. Revisa sus datos (empresa, sede, Administrador, módulos y precio) y guárdalos como revisados; desde su ficha puedes corregir el plan o darlos de baja.</p>
@else
    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
        <div class="filtros-estado m-0" role="group" aria-label="Filtrar clientes por estado">
            @foreach (ListaClientes::FILTROS as $valor => $texto)
                <a href="{{ route('catalogo-comercial.index', ['pestana' => 'clientes', 'cliente_estado' => $valor, 'q' => $buscarCliente ?: null]) }}" class="btn btn-sm fw-bold {{ $clienteEstado === $valor ? 'btn-dark' : 'btn-outline-secondary' }}" @if ($clienteEstado === $valor) aria-current="true" @endif>{{ $texto }} <span class="opacity-75">{{ $conteosClientes[$valor] ?? 0 }}</span></a>
            @endforeach
        </div>
        <form action="{{ route('catalogo-comercial.index') }}" method="GET" class="buscador m-0" role="search">
            <input type="hidden" name="pestana" value="clientes">
            <input type="hidden" name="cliente_estado" value="{{ $clienteEstado }}">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $buscarCliente }}" placeholder="Buscar cliente..." aria-label="Buscar cliente por nombre">
        </form>
    </div>
@endif

@if ($clientes->isEmpty())
    <div class="tarjeta estado-vacio p-4">
        <div class="icono"><i class="bi {{ $soloPorRevisar ? 'bi-check2-all' : 'bi-people' }}" aria-hidden="true"></i></div>
        <p class="text-muted small m-0">{{ $soloPorRevisar
            ? 'No hay clientes nuevos por revisar. Cuando una solicitud se marque como pagada, el cliente se activa solo y llega aquí y a tu campana.'
            : 'Todavía no hay clientes con suscripción en esta vista. Se crean al marcar una solicitud como pagada o al aprobar una prueba gratis. Las empresas que ya existían no tienen suscripción: siguen sin límites ni vencimiento.' }}</p>
    </div>
@else
    <div class="tabla-scroll">
        <table class="table align-middle mb-0 bg-white">
            <thead><tr><th>Cliente</th><th>Plan</th><th class="text-end">Precio pactado</th><th>Vence</th><th>Estado</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
            <tbody>
                @foreach ($clientes as $c)
                    @php
                        $estadoHoy = $c->estado_hoy ?? $c->estadoEfectivo();
                        $dias = $c->diasParaVencer();
                        $reloj = $c->revisado_en === null ? \App\Support\RelojVoBo::de($c->created_at) : null;
                    @endphp
                    <tr>
                        <td class="fw-bold">{{ $c->empresa?->nombre_comercial ?? 'Empresa eliminada' }}
                            @if ($c->revisado_en === null)
                                <div class="mt-1"><span class="reloj-vobo espera-{{ $reloj['nivel'] ?? 'normal' }}"><i class="bi bi-clock" aria-hidden="true"></i>Sin revisar {{ $reloj['hace'] ?? '' }} ({{ $reloj['etiqueta'] ?? '' }})</span></div>
                            @endif</td>
                        <td>{{ $c->plan?->nombre ?? 'Sin plan' }}<div class="small text-muted">{{ $c->sedes_contratadas }} {{ $c->sedes_contratadas === 1 ? 'sede' : 'sedes' }} · {{ $c->periodicidadTexto() }}</div></td>
                        <td class="text-end text-nowrap">{{ $c->es_prueba ? 'Prueba gratis' : Calculadora::formato(Calculadora::centavos($c->precio_total)) }}@unless ($c->es_prueba)<div class="small text-muted">{{ $c->periodicidad === 'anual' ? 'al año' : 'al mes' }} con IVA</div>@endunless</td>
                        <td class="text-nowrap">{{ $c->vence_en->format('d/m/Y') }}
                            <div class="small {{ $dias < 0 ? 'text-danger' : ($dias <= 7 ? 'text-warning-emphasis' : 'text-muted') }}">{{ $dias < 0 ? 'Venció hace '.abs($dias).' '.(abs($dias) === 1 ? 'día' : 'días') : ($dias === 0 ? 'Vence hoy' : 'Faltan '.$dias.' '.($dias === 1 ? 'día' : 'días')) }}</div></td>
                        <td><span class="etiqueta-estado {{ Suscripcion::COLORES[$estadoHoy] ?? 'pendiente' }}">{{ $c->estadoTexto($estadoHoy) }}</span></td>
                        <td class="text-end"><a href="{{ route('catalogo-comercial.clientes.show', $c->id) }}" class="btn btn-sm btn-success fw-bold">Atender</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
