{{--
    Un taxi del despacho de emergencia (SEGCAT: generarEstructuraFilaTaxi).
    Parámetros: $i (índice o "__T__" en la plantilla), $t (lo capturado, tras un error), $pasajerosAnteriores (id => texto),
    $conFirmas (bool), $altaColaborador (bool: puede dar de alta provisional), $sedeId (para la lista de paraderos)
--}}
@php
    $t = $t ?? [];
    $v = fn (string $campo) => is_scalar($t[$campo] ?? null) ? (string) $t[$campo] : '';
    $n = "taxis[{$i}]";
    $elegidos = collect((array) ($t['pasajeros'] ?? []))->filter(fn ($id) => is_numeric($id) && isset($pasajerosAnteriores[(int) $id]))->map(fn ($id) => (int) $id)->unique();
    $tipoTaxi = $v('tipo') ?: 'sedan';
@endphp
<fieldset class="taxi-fila" data-taxi>
    <legend class="taxi-fila-cabecera">
        <span class="taxi-fila-titulo" data-titulo-taxi>Taxi</span>
        <button type="button" class="btn-quitar-fila" data-quitar-taxi title="Quitar este taxi" aria-label="Quitar este taxi"><i class="bi bi-trash" aria-hidden="true"></i></button>
    </legend>
    <div class="row">
        <div class="col-md-6">
            <label class="campo-etiqueta text-danger" for="taxi_{{ $i }}_placas">Placas <span class="text-muted text-lowercase fw-normal">(buscar o nuevo)</span></label>
            <input type="text" id="taxi_{{ $i }}_placas" name="{{ $n }}[placas]" class="campo text-uppercase" maxlength="20" required list="taxisConocidos"
                   placeholder="Ej: TX-230" autocapitalize="characters" value="{{ $v('placas') }}" data-autollenar="taxi"
                   data-parecidos-vivo="vehiculos" data-parecidos-url="{{ route('altas_por_verificar.parecidos') }}" data-parecidos-origen="transporte">
        </div>
        <div class="col-md-6">
            <label class="campo-etiqueta text-danger" for="taxi_{{ $i }}_chofer">Nombre Conductor <span class="text-muted text-lowercase fw-normal">(buscar o nuevo)</span></label>
            <input type="text" id="taxi_{{ $i }}_chofer" name="{{ $n }}[chofer]" class="campo text-uppercase" maxlength="150" required list="choferesConocidos"
                   placeholder="Nombre" autocapitalize="characters" value="{{ $v('chofer') }}" data-autollenar-chofer
                   data-parecidos-vivo="personas" data-parecidos-url="{{ route('altas_por_verificar.parecidos') }}" data-parecidos-origen="transporte">
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <label class="campo-etiqueta text-danger" for="taxi_{{ $i }}_monto">Monto Vale ($)</label>
            <input type="number" id="taxi_{{ $i }}_monto" name="{{ $n }}[monto]" class="campo fw-bold text-danger" step="0.01" min="0.01" max="99999.99" inputmode="decimal" required
                   placeholder="0.00" value="{{ $v('monto') }}" data-monto-taxi>
        </div>
        <div class="col-md-6">
            <label class="campo-etiqueta" for="taxi_{{ $i }}_destino">Destino Específico <span class="text-muted text-lowercase fw-normal">(paradero del catálogo)</span></label>
            <input type="text" id="taxi_{{ $i }}_destino" name="{{ $n }}[destino]" class="campo text-uppercase" maxlength="150" required
                   list="paraderosTransporte-{{ $sedeId ?? '0' }}" placeholder="Buscar paradero..." value="{{ $v('destino') }}" data-destino-taxi>
        </div>
    </div>
    <div class="aviso-tope" data-aviso-tope hidden>
        <p class="mb-1"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><span data-texto-tope>Este monto supera el tope autorizado para esta ruta.</span></p>
        <label class="campo-etiqueta" for="taxi_{{ $i }}_justificacion">Justificación (obligatoria)</label>
        <textarea id="taxi_{{ $i }}_justificacion" name="{{ $n }}[justificacion]" class="campo mb-0" rows="2" maxlength="1000"
                  placeholder="Explica por qué se autorizó este monto..." data-justificacion-taxi>{{ $v('justificacion') }}</textarea>
    </div>
    <details class="transporte-detalles">
        <summary>Más detalles <span class="text-muted fw-normal">(opcional)</span></summary>
        <div class="row mt-2">
            <div class="col-6 col-md-3">
                <label class="campo-etiqueta" for="taxi_{{ $i }}_tipo">Tipo Vehículo</label>
                <select id="taxi_{{ $i }}_tipo" name="{{ $n }}[tipo]" class="campo" data-campo-unidad="tipo">
                    @foreach (\App\Models\MovimientoTransporte::TIPOS_TAXI as $clave => [$etiquetaTipo])
                        <option value="{{ $clave }}" @selected($tipoTaxi === $clave) @if ($clave === 'sedan') data-por-defecto @endif>{{ $etiquetaTipo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="campo-etiqueta" for="taxi_{{ $i }}_marca">Marca</label>
                <input type="text" id="taxi_{{ $i }}_marca" name="{{ $n }}[marca]" class="campo text-uppercase" maxlength="50" placeholder="Se autocompleta" value="{{ $v('marca') }}" data-campo-unidad="marca">
            </div>
            <div class="col-6 col-md-3">
                <label class="campo-etiqueta" for="taxi_{{ $i }}_modelo">Modelo</label>
                <input type="text" id="taxi_{{ $i }}_modelo" name="{{ $n }}[modelo]" class="campo text-uppercase" maxlength="60" placeholder="Se autocompleta" value="{{ $v('modelo') }}" data-campo-unidad="modelo">
            </div>
            <div class="col-6 col-md-3">
                <label class="campo-etiqueta" for="taxi_{{ $i }}_economico">Núm. Económico</label>
                <input type="text" id="taxi_{{ $i }}_economico" name="{{ $n }}[economico]" class="campo text-uppercase" maxlength="30" placeholder="Ej: ECO-12" value="{{ $v('economico') }}" data-campo-unidad="economico">
            </div>
            <div class="col-6">
                <label class="campo-etiqueta" for="taxi_{{ $i }}_capacidad">Capacidad</label>
                <input type="number" id="taxi_{{ $i }}_capacidad" name="{{ $n }}[capacidad]" class="campo" min="1" max="99" inputmode="numeric" placeholder="Ej: 4" value="{{ $v('capacidad') }}" data-campo-unidad="capacidad">
            </div>
            <div class="col-6">
                <label class="campo-etiqueta" for="taxi_{{ $i }}_tel">Tel. Conductor</label>
                <input type="tel" id="taxi_{{ $i }}_tel" name="{{ $n }}[chofer_telefono]" class="campo" maxlength="20" inputmode="tel" placeholder="Opcional" value="{{ $v('chofer_telefono') }}" data-telefono-chofer>
            </div>
        </div>
    </details>

    <div class="taxi-pasajeros" data-pasajeros-taxi data-nombre="{{ $n }}[pasajeros][]">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span class="campo-etiqueta mb-0">Pasajeros de este Taxi <span class="pax-contador" data-contador-pax>{{ $elegidos->count() }} PAX</span></span>
            @if ($altaColaborador)
                <button type="button" class="btn-alta-pasajero" data-abrir-dialogo="dialogoRegistroRapidoColaborador" data-alta-pasajero>
                    <i class="bi bi-person-plus" aria-hidden="true"></i> ¿No aparece? Alta provisional
                </button>
            @endif
        </div>
        @include('componentes.lector', ['id' => "taxi_{$i}_pasajero", 'tipos' => 'colaborador', 'nombre' => '', 'etiqueta' => null, 'valor' => null, 'elegido' => null, 'requerido' => false, 'modo' => 'buscar',
            'ayuda' => 'Escanea el gafete o escribe el número de empleado y presiona Enter. Puedes agregar varios.'])
        <div class="pasajeros-elegidos" data-pasajeros>
            @foreach ($elegidos as $id)
                <span class="chip-pasajero" data-id="{{ $id }}">{{ $pasajerosAnteriores[$id] }}<input type="hidden" name="{{ $n }}[pasajeros][]" value="{{ $id }}"><button type="button" data-quitar-pasajero aria-label="Quitar a {{ $pasajerosAnteriores[$id] }}">×</button></span>
            @endforeach
        </div>
    </div>

    @if ($conFirmas)
        @include('componentes.firma', ['id' => "taxi_{$i}_firma", 'nombre' => "{$n}[firma]", 'etiqueta' => 'Firma del taxista (recibí)', 'requerido' => true,
            'ayuda' => 'El conductor firma con el dedo. Si hubo un error, vuelve a firmar.'])
    @endif
</fieldset>
