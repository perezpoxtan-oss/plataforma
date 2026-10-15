{{--
    Días de resguardo por clasificación: los mismos campos en Estructura →
    Configuración y en el diálogo «Días de resguardo» del archivo de Lost &
    Found. Una sola fuente de verdad: tabla lost_found_umbrales (LostFoundUmbral)
    y ArchivoLostFound::guardarUmbrales(). $dias, $guardados, $puedeEditar, $prefijo
--}}
@foreach (\App\Models\LostFoundArticulo::TIPOS_VALOR as $tipo => $nombre)
    @php $guardado = $guardados[$tipo] ?? null; $diasTipo = $dias[$tipo]; @endphp
    <div class="umbral-card">
        <div>
            <label class="fw-bold" for="{{ $prefijo }}dias_{{ $tipo }}">{{ $nombre }}</label>
            <div class="small text-muted">Amarillo a partir del día {{ (int) ceil($diasTipo * 0.7) }}; rojo (vencido) desde el día {{ $diasTipo }}. <span class="valor-original">Valor original: {{ \App\Models\LostFoundUmbral::POR_OMISION[$tipo] ?? $diasTipo }} días.</span></div>
            @if ($guardado && $guardado->updated_at)
                <div class="texto-traza">Editado por {{ $guardado->editor?->name ?? 'el sistema' }} · @fecha($guardado->updated_at)</div>
            @endif
        </div>
        <div class="d-flex align-items-center gap-2">
            <input type="number" id="{{ $prefijo }}dias_{{ $tipo }}" name="dias[{{ $tipo }}]" class="campo campo-dias-lf" min="1" max="{{ \App\Services\Novedades\ArchivoLostFound::MAX_DIAS }}" inputmode="numeric" required
                   value="{{ old('dias.'.$tipo, $diasTipo) }}" @disabled(! $puedeEditar)
                   data-mensaje-min="Debe ser al menos 1 día: un artículo no puede vencer el mismo día que se encuentra.">
            <span class="text-muted small">día(s)</span>
        </div>
    </div>
@endforeach
