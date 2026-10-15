{{-- Suscripciones: lo que ve el Administrador del cliente en Empresas (ver docs/usuario/mi-suscripcion.md) --}}
@php
    use App\Models\Suscripcion;
    use App\Services\Comercial\Calculadora;
    $s = $suscripcion;
    $estadoHoy = $s->estadoEfectivo();
    $l = $limitesSuscripcion;
    $dias = $s->diasParaVencer();
    $abrir = $errors->hasAny(['tipo', 'plan_id', 'sedes', 'periodicidad', 'paquetes', 'codigo_promocion', 'mensaje']);
@endphp
<section class="tarjeta p-4 mb-4" id="tu-suscripcion" aria-labelledby="titulo-tu-suscripcion">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <h2 class="h5 fw-bold m-0" id="titulo-tu-suscripcion"><i class="bi bi-calendar2-check me-1 text-primary" aria-hidden="true"></i> Tu suscripción</h2>
        <span class="etiqueta-estado {{ Suscripcion::COLORES[$estadoHoy] ?? 'pendiente' }}">{{ $s->estadoTexto($estadoHoy) }}</span>
    </div>
    <div class="row g-3 small">
        <div class="col-sm-6 col-lg-3"><div class="text-muted">Plan</div><div class="fw-bold">{{ $s->plan?->nombre ?? 'Personalizado' }}</div>
            @if ($s->paquetes->isNotEmpty())<div>+ {{ $s->paquetes->pluck('nombre')->join(', ') }}</div>@endif</div>
        <div class="col-sm-6 col-lg-3"><div class="text-muted">Sedes</div><div class="fw-bold">{{ $l['sedes_usadas'] }} de {{ $l['sedes'] }}</div><div>activas de las incluidas</div></div>
        <div class="col-sm-6 col-lg-3"><div class="text-muted">Usuarios activos</div><div class="fw-bold">{{ $l['usuarios_usados'] }}{{ $l['usuarios'] !== null ? ' de '.$l['usuarios'] : '' }}</div><div>{{ $l['usuarios'] === null ? 'sin límite' : 'incluidos en tu plan' }}</div></div>
        <div class="col-sm-6 col-lg-3"><div class="text-muted">{{ $s->es_prueba ? 'Tu prueba termina' : 'Vence' }}</div><div class="fw-bold">{{ $s->vence_en->format('d/m/Y') }}</div>
            <div class="{{ $dias < 0 ? 'text-danger' : ($dias <= 7 ? 'text-warning-emphasis' : '') }}">{{ $dias < 0 ? 'Venció hace '.abs($dias).' '.(abs($dias) === 1 ? 'día' : 'días') : ($dias === 0 ? 'Vence hoy' : 'Faltan '.$dias.' '.($dias === 1 ? 'día' : 'días')) }}</div></div>
    </div>
    <p class="small text-muted mt-3 mb-0">
        @if ($s->es_prueba)
            Estás en tu prueba gratis. Para seguir usando la plataforma después del {{ $s->vence_en->format('d/m/Y') }}, contrata tu plan: {{ Calculadora::formato(Calculadora::centavos($s->precio_total)) }} {{ $s->periodicidad === 'anual' ? 'al año' : 'al mes' }} con IVA.
        @else
            {{ $s->periodicidadTexto() }}: {{ Calculadora::formato(Calculadora::centavos($s->precio_total)) }} {{ $s->periodicidad === 'anual' ? 'al año' : 'al mes' }} con IVA.
        @endif
        @if ($estadoHoy === Suscripcion::EN_GRACIA) Tienes hasta el {{ $s->gracia_hasta?->format('d/m/Y') }} para normalizar el pago; después quedará en solo consulta.@endif
    </p>

    @if ($puedePedirCambio)
        <details class="mt-3" @if ($abrir) open @endif>
            <summary class="fw-bold">Ampliar o renovar mi plan</summary>
            <form action="{{ route('mi-suscripcion.solicitar') }}" method="POST" class="mt-3" autocomplete="off">
                @csrf
                <div class="row">
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="ms-tipo">¿Qué necesitas?</label>
                        <select id="ms-tipo" name="tipo" class="campo">
                            <option value="renovacion" @selected(old('tipo') === 'renovacion')>{{ $s->es_prueba ? 'Contratar mi plan' : 'Renovar mi plan' }}</option>
                            <option value="ampliacion" @selected(old('tipo') === 'ampliacion')>Ampliar o cambiar de plan (sedes, paquetes)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="ms-plan">Plan</label>
                        <select id="ms-plan" name="plan_id" class="campo">
                            @foreach ($planesSuscripcion as $p)
                                <option value="{{ $p->id }}" @selected((string) old('plan_id', $s->plan_id) === (string) $p->id)>{{ $p->nombre }}{{ $p->cotizar ? ' (a cotizar)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="ms-sedes">¿Cuántas sedes?</label>
                        <input type="number" id="ms-sedes" name="sedes" class="campo" min="1" max="999" required value="{{ old('sedes', $s->sedes_contratadas) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="ms-periodicidad">Forma de pago</label>
                        <select id="ms-periodicidad" name="periodicidad" class="campo">
                            @foreach (Suscripcion::PERIODICIDADES as $clave => $texto)
                                <option value="{{ $clave }}" @selected(old('periodicidad', $s->periodicidad) === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @if ($paquetesSuscripcion->isNotEmpty())
                    <fieldset class="mb-2">
                        <legend class="campo-etiqueta">Paquetes</legend>
                        @foreach ($paquetesSuscripcion as $q)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="ms-paq-{{ $q->id }}" name="paquetes[]" value="{{ $q->id }}" @checked(in_array($q->id, array_map('intval', (array) old('paquetes', $s->paquetes->pluck('id')->all())), true))>
                                <label class="form-check-label small" for="ms-paq-{{ $q->id }}">{{ $q->nombre }}</label>
                            </div>
                        @endforeach
                    </fieldset>
                @endif
                <details class="mb-2">
                    <summary class="small fw-bold">Avanzado: código de promoción y comentarios</summary>
                    <label class="campo-etiqueta mt-2" for="ms-codigo">Código de promoción</label>
                    <input type="text" id="ms-codigo" name="codigo_promocion" class="campo text-uppercase" maxlength="40" value="{{ old('codigo_promocion') }}">
                    <label class="campo-etiqueta" for="ms-mensaje">Comentarios</label>
                    <textarea id="ms-mensaje" name="mensaje" class="campo" rows="2" maxlength="1000">{{ old('mensaje') }}</textarea>
                </details>
                <p class="small text-muted">Te contactamos para confirmar el pago. En cuanto se registre, tu suscripción se actualiza sola.</p>
                <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save2 me-1" aria-hidden="true"></i>Guardar solicitud</button>
            </form>
        </details>
    @endif
</section>
