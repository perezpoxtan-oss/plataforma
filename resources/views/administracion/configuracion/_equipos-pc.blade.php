{{-- Configuración → (bitácora) → Equipos de Protección Civil: frecuencia de revisión por tipo (DD-17). $empresa, $puedeEditar --}}
@php
    $frecuencias = app(\App\Services\RecorridosPc\AjustesEquiposPc::class)->frecuencias($empresa);
    $distintoPc = collect($frecuencias)->contains(fn ($d, $c) => $d !== (\App\Services\RecorridosPc\AjustesEquiposPc::FRECUENCIA_OMISION[$c] ?? 0));
@endphp
<div class="tarjeta p-4 config-caseta" id="cfg-equipos-pc">
    <h3 class="h6 fw-bold"><i class="bi bi-fire me-2 text-danger" aria-hidden="true"></i>Equipos de Protección Civil: frecuencia de revisión</h3>
    <p class="small text-muted">Cuando un equipo pasa estos días sin aparecer en un recorrido, se avisa a quien administra los equipos en su sede y, una semana después, a quien los administra en toda la empresa. La próxima recarga se captura en cada equipo y se avisa 15 días antes.</p>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.equipos_pc') }}" method="POST">
        @csrf
        @method('PUT')
        <details @if ($distintoPc || $errors->has('frecuencia_dias.*')) open @endif>
            <summary class="small fw-bold">Avanzado: días por tipo de equipo</summary>
            <div class="row g-2 mt-1">
                @foreach (\App\Models\EquipoPc::CATEGORIAS as $clave => $texto)
                    <div class="col-6 col-md-4">
                        <label class="campo-etiqueta" for="cfg_frecuencia_{{ $clave }}">{{ $texto }}</label>
                        <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_frecuencia_{{ $clave }}" name="frecuencia_dias[{{ $clave }}]" class="campo campo-horas-caseta" min="0" max="{{ \App\Services\RecorridosPc\AjustesEquiposPc::MAX_DIAS }}" inputmode="numeric" value="{{ old('frecuencia_dias.'.$clave, $frecuencias[$clave]) }}" @disabled(! $puedeEditar)><span class="small text-muted">días</span></div>
                        @error('frecuencia_dias.'.$clave)<p class="text-danger small mb-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </details>
        <p class="campo-ayuda mt-1"><strong>0 = sin frecuencia.</strong> <span class="valor-original">Valor original: extintores cada 30 días; lo demás, sin frecuencia.</span></p>
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2 btn-guardar-caseta">Guardar</button>
        @endif
    </form>
    @if ($puedeEditar && $distintoPc)
        @include('administracion.configuracion._restablecer', ['ajuste' => 'equipos_pc', 'que' => 'la frecuencia de revisión de los equipos'])
    @endif
</div>
