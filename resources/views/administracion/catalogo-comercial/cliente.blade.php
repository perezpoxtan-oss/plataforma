@extends('layouts.app')

@php
    use App\Models\Suscripcion;
    use App\Models\SuscripcionExcepcion;
    use App\Services\Comercial\Calculadora;
    $dinero = fn ($v) => Calculadora::formato(Calculadora::centavos($v), $s->moneda);
    $f = fn ($c) => Calculadora::formato((int) $c);
    $empresa = $s->empresa;
    $nombre = $empresa?->nombre_comercial ?? 'Empresa eliminada';
    $reloj = $s->revisado_en === null ? \App\Support\RelojVoBo::de($s->created_at) : null;
    $dias = $s->diasParaVencer();
    $d = $s->detalle ?? [];
    $manual = $s->suspendida || $s->estado === Suscripcion::CANCELADA;
@endphp

@section('titulo', 'Cliente '.$nombre)

@section('contenido')
    @include('administracion.partes.avisos')
    <a href="{{ route('catalogo-comercial.index', ['pestana' => 'clientes']) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Clientes</a>

    <div class="encabezado-pantalla mb-4 mt-2">
        <div class="icono"><i class="bi bi-building-check text-success" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $nombre }}</h1>
            <p><span class="etiqueta-estado {{ Suscripcion::COLORES[$estadoHoy] ?? 'pendiente' }}">{{ $s->estadoTexto($estadoHoy) }}</span>
                Cliente desde el {{ $s->created_at->format('d/m/Y') }}
                @if ($reloj)<span class="reloj-vobo espera-{{ $reloj['nivel'] }} ms-1"><i class="bi bi-clock" aria-hidden="true"></i>Sin revisar {{ $reloj['hace'] }} ({{ $reloj['etiqueta'] }})</span>@endif</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            {{-- ===== Suscripción ===== --}}
            <div class="tarjeta p-4 mb-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-card-checklist me-1 text-primary" aria-hidden="true"></i> Suscripción</h2>
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Plan</dt><dd class="col-sm-8">{{ $s->plan?->nombre ?? 'Sin plan' }}@if ($s->paquetes->isNotEmpty()) + {{ $s->paquetes->pluck('nombre')->join(', ') }}@endif</dd>
                    <dt class="col-sm-4">Forma de pago</dt><dd class="col-sm-8">{{ $s->periodicidadTexto() }}</dd>
                    <dt class="col-sm-4">Sedes</dt><dd class="col-sm-8">{{ $limites['sedes_usadas'] ?? 0 }} activas de {{ $limites['sedes'] ?? $s->sedes_contratadas }} permitidas
                        <span class="text-muted">({{ $s->sedes_contratadas }} contratadas{{ ($limites['sedes'] ?? $s->sedes_contratadas) !== $s->sedes_contratadas ? ', con excepción' : '' }})</span></dd>
                    <dt class="col-sm-4">Usuarios activos</dt><dd class="col-sm-8">{{ $limites['usuarios_usados'] ?? 0 }} de {{ ($limites['usuarios'] ?? null) === null ? 'ilimitados' : $limites['usuarios'] }}</dd>
                    <dt class="col-sm-4">Precio pactado</dt>
                    <dd class="col-sm-8">
                        @if ($s->es_prueba)
                            Prueba gratis; al contratar: {{ $dinero($s->precio_total) }} {{ $s->periodicidad === 'anual' ? 'al año' : 'al mes' }} con IVA
                        @else
                            {{ $dinero($s->precio_total) }} {{ $s->periodicidad === 'anual' ? 'al año' : 'al mes' }} con IVA
                        @endif
                        @if (($d['descuento_anual'] ?? 0) > 0)<div class="text-muted">Incluye descuento por pago anual: −{{ $f($d['descuento_anual']) }}</div>@endif
                        @if (($d['descuento_promocion'] ?? 0) > 0)<div class="text-muted">Promoción {{ $d['promocion']['nombre'] ?? '' }}: −{{ $f($d['descuento_promocion']) }}@if ($s->promocion_hasta) (hasta el {{ $s->promocion_hasta->format('d/m/Y') }})@endif</div>@endif
                        @if (isset($d['periodo_regular']) && ($d['periodo_regular']['total'] ?? 0) !== Calculadora::centavos($s->precio_total))<div class="text-muted">Precio regular: {{ $f($d['periodo_regular']['total']) }}</div>@endif
                    </dd>
                    <dt class="col-sm-4">{{ $s->es_prueba ? 'Fin de la prueba' : 'Vence' }}</dt>
                    <dd class="col-sm-8">{{ $s->vence_en->format('d/m/Y') }}
                        <span class="{{ $dias < 0 ? 'text-danger' : ($dias <= 7 ? 'text-warning-emphasis' : 'text-muted') }}">({{ $dias < 0 ? 'venció hace '.abs($dias).' '.(abs($dias) === 1 ? 'día' : 'días') : ($dias === 0 ? 'vence hoy' : 'faltan '.$dias.' '.($dias === 1 ? 'día' : 'días')) }})</span></dd>
                    <dt class="col-sm-4">Gracia hasta</dt><dd class="col-sm-8">{{ $s->gracia_hasta?->format('d/m/Y') ?? 'Sin gracia' }}<span class="text-muted"> · después, solo consulta</span></dd>
                    @if ($s->solicitud)
                        <dt class="col-sm-4">Solicitud</dt><dd class="col-sm-8"><a href="{{ route('catalogo-comercial.solicitudes.show', $s->solicitud->id) }}">{{ $s->solicitud->folio }}</a> · {{ $s->solicitud->nombre }} · <a href="mailto:{{ $s->solicitud->correo }}">{{ $s->solicitud->correo }}</a></dd>
                    @endif
                </dl>
            </div>

            {{-- ===== Módulos ===== --}}
            <div class="tarjeta p-4 mb-4">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-grid me-1 text-primary" aria-hidden="true"></i> Módulos que tiene</h2>
                <p class="small text-muted">Los del plan y sus paquetes, más los agregados y menos los quitados en Excepciones. Los de Dirección siempre se quedan.</p>
                <p class="small mb-0">{{ $modulosActivos->join(', ') ?: 'Ninguno' }}</p>
            </div>

            {{-- ===== Excepciones ===== --}}
            <div class="tarjeta p-4 mb-4">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-sliders2 me-1 text-primary" aria-hidden="true"></i> Excepciones</h2>
                <p class="small text-muted">Ajustes a mano respecto al plan, con su motivo. Al darlos de baja se quedan en el historial.</p>
                @if ($s->excepciones->isNotEmpty())
                    <ul class="list-unstyled small">
                        @foreach ($s->excepciones as $e)
                            <li class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom @if (! $e->activo) text-muted @endif">
                                <span><strong>{{ $e->texto() }}</strong>@unless ($e->activo) <span class="etiqueta-estado inactivo">DE BAJA</span>@endunless
                                    <br>{{ $e->motivo }} · {{ $e->autor?->name ?? 'Sistema' }}, {{ $e->created_at->format('d/m/Y') }}</span>
                                @if ($e->activo && $puedeEditar)
                                    <form action="{{ route('catalogo-comercial.clientes.excepciones.baja', [$s->id, $e->id]) }}" method="POST" class="m-0" data-confirmar="¿Dar de baja la excepción «{{ $e->texto() }}»?">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja la excepción {{ $e->texto() }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($puedeEditar)
                    <details @if ($errors->hasAny(['tipo', 'cantidad', 'modulo_id', 'motivo'])) open @endif>
                        <summary class="fw-bold small">Agregar una excepción</summary>
                        <form action="{{ route('catalogo-comercial.clientes.excepciones.store', $s->id) }}" method="POST" class="mt-2">
                            @csrf
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="campo-etiqueta" for="ex-tipo">Qué se ajusta</label>
                                    <select id="ex-tipo" name="tipo" class="campo">
                                        @foreach (SuscripcionExcepcion::TIPOS as $clave => $texto)
                                            <option value="{{ $clave }}" @selected(old('tipo') === $clave)>{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="campo-etiqueta" for="ex-cantidad">Cantidad (usuarios o sedes)</label>
                                    <input type="number" id="ex-cantidad" name="cantidad" class="campo" min="-999" max="9999" value="{{ old('cantidad') }}" placeholder="Por ejemplo 5 (o -2)">
                                </div>
                            </div>
                            <label class="campo-etiqueta" for="ex-modulo">Módulo (para agregar o quitar)</label>
                            <select id="ex-modulo" name="modulo_id" class="campo">
                                <option value="">Elige el módulo</option>
                                @foreach ($modulos as $m)
                                    <option value="{{ $m->id }}" @selected((string) old('modulo_id') === (string) $m->id)>{{ $m->nombre }}</option>
                                @endforeach
                            </select>
                            <label class="campo-etiqueta" for="ex-motivo">Motivo *</label>
                            <input type="text" id="ex-motivo" name="motivo" class="campo" maxlength="255" required value="{{ old('motivo') }}" placeholder="Lo acordado con el cliente">
                            <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save2 me-1" aria-hidden="true"></i>Guardar</button>
                        </form>
                    </details>
                @endif
            </div>

            {{-- ===== Historial ===== --}}
            <div class="tarjeta p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-clock-history me-1 text-primary" aria-hidden="true"></i> Historial</h2>
                <ol class="list-unstyled small mb-0">
                    @forelse ($s->historial as $h)
                        <li class="py-2 border-bottom">
                            <strong>{{ $h->created_at->format('d/m/Y H:i') }}</strong> · {{ $h->usuario?->name ?? 'Automático' }}
                            @if ($h->estado_antes !== $h->estado_despues && $h->estado_despues)<span class="text-muted">· {{ $s->estadoTexto($h->estado_antes ?? '') ?: 'Nuevo' }} → {{ $s->estadoTexto($h->estado_despues) }}</span>@endif
                            <div>{{ $h->descripcion }}</div>
                        </li>
                    @empty
                        <li class="text-muted">Sin movimientos.</li>
                    @endforelse
                </ol>
            </div>
        </div>

        <div class="col-lg-5">
            @if ($puedeEditar)
                {{-- ===== Revisión ===== --}}
                <form action="{{ route('catalogo-comercial.clientes.revision', $s->id) }}" method="POST" class="tarjeta p-4 mb-4">
                    @csrf
                    @method('PUT')
                    <h2 class="h6 fw-bold mb-2"><i class="bi bi-person-check me-1 text-primary" aria-hidden="true"></i> Revisión</h2>
                    @if ($s->revisado_en)
                        <p class="small text-muted">Revisado por {{ $s->revisor?->name ?? 'el Super Administrador' }} el {{ $s->revisado_en->format('d/m/Y H:i') }}.</p>
                    @else
                        <p class="small">Revisa la empresa, su sede, su Administrador, los módulos y el precio. Si algo no cuadra, corrígelo aquí mismo o dalo de baja.</p>
                    @endif
                    <label class="campo-etiqueta" for="notas">Notas internas</label>
                    <textarea id="notas" name="notas" class="campo" rows="3" maxlength="5000">{{ old('notas', $s->notas) }}</textarea>
                    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>{{ $s->revisado_en ? 'Guardar' : 'Guardar como revisado' }}</button>
                </form>

                {{-- ===== Pago ===== --}}
                <form action="{{ route('catalogo-comercial.clientes.pago', $s->id) }}" method="POST" class="tarjeta p-4 mb-4"
                      data-confirmar="¿Recibir el pago de {{ $nombre }}? La suscripción se extiende {{ $s->periodicidad === 'anual' ? 'un año' : 'un mes' }} y sale de la prueba, la gracia o la solo consulta.">
                    @csrf
                    <h2 class="h6 fw-bold mb-2"><i class="bi bi-cash-coin me-1 text-primary" aria-hidden="true"></i> {{ $s->es_prueba ? 'Contratar después de la prueba' : 'Renovar' }}</h2>
                    <p class="small">{{ $s->es_prueba ? 'Cuando pague, la prueba termina y empieza su primer periodo desde hoy.' : 'Un periodo más desde el vencimiento (o desde hoy si ya venció).' }}
                        Monto: <strong>{{ $dinero($s->precio_total) }}</strong>.</p>
                    <label class="campo-etiqueta" for="referencia">Referencia del pago</label>
                    <input type="text" id="referencia" name="referencia" class="campo" maxlength="100" placeholder="Folio de la transferencia o del recibo">
                    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-cash me-1" aria-hidden="true"></i>Recibir pago</button>
                </form>

                {{-- ===== Cambiar plan ===== --}}
                <form action="{{ route('catalogo-comercial.clientes.plan', $s->id) }}" method="POST" class="tarjeta p-4 mb-4">
                    @csrf
                    @method('PUT')
                    <h2 class="h6 fw-bold mb-2"><i class="bi bi-layers me-1 text-primary" aria-hidden="true"></i> Plan, sedes y forma de pago</h2>
                    <p class="small text-muted">El precio se recalcula con los precios de hoy; las fechas no cambian. Lo que deja de incluir solo se oculta: sus datos se conservan.</p>
                    <label class="campo-etiqueta" for="plan_id">Plan</label>
                    <select id="plan_id" name="plan_id" class="campo">
                        <option value="">Sin plan</option>
                        @foreach ($planes as $p)
                            <option value="{{ $p->id }}" @selected((string) old('plan_id', $s->plan_id) === (string) $p->id)>{{ $p->nombre }}{{ $p->cotizar ? ' (cotizar)' : '' }}</option>
                        @endforeach
                    </select>
                    <div class="row">
                        <div class="col-6">
                            <label class="campo-etiqueta" for="sedes">Sedes contratadas</label>
                            <input type="number" id="sedes" name="sedes" class="campo" min="1" max="999" required value="{{ old('sedes', $s->sedes_contratadas) }}">
                        </div>
                        <div class="col-6">
                            <label class="campo-etiqueta" for="periodicidad">Forma de pago</label>
                            <select id="periodicidad" name="periodicidad" class="campo">
                                @foreach (Suscripcion::PERIODICIDADES as $clave => $texto)
                                    <option value="{{ $clave }}" @selected(old('periodicidad', $s->periodicidad) === $clave)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if ($paquetes->isNotEmpty())
                        <fieldset class="mb-2">
                            <legend class="campo-etiqueta">Paquetes al mes</legend>
                            @foreach ($paquetes as $q)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="paq-{{ $q->id }}" name="paquetes[]" value="{{ $q->id }}" @checked($s->paquetes->contains('id', $q->id))>
                                    <label class="form-check-label small" for="paq-{{ $q->id }}">{{ $q->nombre }}</label>
                                </div>
                            @endforeach
                        </fieldset>
                    @endif
                    <label class="campo-etiqueta" for="motivo-plan">Motivo (para el historial)</label>
                    <input type="text" id="motivo-plan" name="motivo" class="campo" maxlength="200" placeholder="Por ejemplo: amplió a 3 sedes">
                    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save2 me-1" aria-hidden="true"></i>Guardar</button>
                </form>

                {{-- ===== Promoción ===== --}}
                @if ($promociones->isNotEmpty())
                    <form action="{{ route('catalogo-comercial.clientes.promocion', $s->id) }}" method="POST" class="tarjeta p-4 mb-4">
                        @csrf
                        @method('PUT')
                        <h2 class="h6 fw-bold mb-2"><i class="bi bi-ticket-perforated me-1 text-primary" aria-hidden="true"></i> Promoción</h2>
                        <p class="small text-muted">{{ $s->promocion ? 'Tiene «'.$s->promocion->nombre.'» ('.$s->promocion->resumen().').' : 'Sin promoción.' }} Al aplicar otra, el precio pactado se recalcula y vale desde el siguiente periodo.</p>
                        <label class="campo-etiqueta" for="promocion_id">Aplicar la promoción</label>
                        <select id="promocion_id" name="promocion_id" class="campo">
                            @foreach ($promociones as $p)
                                <option value="{{ $p->id }}">{{ $p->nombre }}{{ $p->codigo ? ' ('.$p->codigo.')' : '' }} · {{ $p->resumen() }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save2 me-1" aria-hidden="true"></i>Guardar</button>
                    </form>
                @endif

                {{-- ===== Dar de baja / Reactivar ===== --}}
                <form action="{{ route('catalogo-comercial.clientes.estado', $s->id) }}" method="POST" class="tarjeta p-4"
                      data-confirmar="{{ $manual ? '¿Reactivar a '.$nombre.'?' : '¿Dar de baja a '.$nombre.'? Sus usuarios quedan en solo consulta: ven y exportan, pero no registran nada.' }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="activo" value="{{ $manual ? 1 : 0 }}">
                    <h2 class="h6 fw-bold mb-2"><i class="bi bi-lock me-1 text-primary" aria-hidden="true"></i> Solo consulta</h2>
                    @if ($manual)
                        <p class="small">Está dado de baja a mano: sus usuarios solo pueden ver y exportar. Al reactivarlo vuelve a registrar normalmente (si su vigencia ya terminó, primero recibe su pago).</p>
                        <label class="campo-etiqueta" for="motivo-estado">Motivo (opcional)</label>
                        <input type="text" id="motivo-estado" name="motivo" class="campo" maxlength="200">
                        <button type="submit" class="btn btn-outline-success fw-bold"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reactivar</button>
                    @else
                        <p class="small">Deja al cliente en solo consulta antes de que termine su gracia (por ejemplo, por un acuerdo de pago incumplido). Se deshace con «Reactivar».</p>
                        <label class="campo-etiqueta" for="motivo-estado">Motivo *</label>
                        <input type="text" id="motivo-estado" name="motivo" class="campo" maxlength="200" required>
                        <button type="submit" class="btn btn-outline-danger fw-bold"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Dar de baja</button>
                    @endif
                </form>
            @endif
        </div>
    </div>
@endsection
