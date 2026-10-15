{{--
    Configuración → ajustes de caseta y activos (ADR-0014), una tarjeta por tema
    en su sección (Configuración por secciones). Sin configurar, todo funciona
    como el diseño original. Cada tarjeta manda solo sus campos (_tarjeta) y el
    resto se conserva. Variables: $empresa, $puedeEditar, $tarjeta
    (transporte | llaves | responsivas | procedimientos | vobo).
--}}
@php
    $ajustes = \App\Support\AjustesCaseta::de($empresa);
    $omision = \App\Support\AjustesCaseta::OMISION;
    $valor = fn (string $clave) => old($clave, $ajustes[$clave]);
    $max = \App\Support\AjustesCaseta::MAX_HORAS;
    $min = \App\Support\AjustesCaseta::MIN;
    $titulos = [
        'transporte' => ['bi-taxi-front', 'Firmas del vale de taxi', 'las firmas del vale de taxi'],
        'llaves' => ['bi-key', 'Préstamo de llaves: llaves fuera mucho tiempo', 'el semáforo de llaves'],
        'responsivas' => ['bi-pen', 'Responsivas: equipos de «Turno»', 'los equipos de «Turno»'],
        'procedimientos' => ['bi-book', 'Procedimientos: acuse «Leí y entendí» y recordatorio', 'los ajustes de Procedimientos'],
        'vobo' => ['bi-clock-history', 'Reloj del visto bueno', 'el reloj del visto bueno'],
    ];
    [$icono, $titulo, $que] = $titulos[$tarjeta];
    $distinto = collect(\App\Support\AjustesCaseta::GRUPOS[$tarjeta])->contains(fn ($c) => $ajustes[$c] !== $omision[$c]);
@endphp
<div class="tarjeta p-4 config-caseta" id="cfg-{{ $tarjeta }}">
    <h3 class="h6 fw-bold"><i class="bi {{ $icono }} me-2 text-primary" aria-hidden="true"></i>{{ $titulo }}</h3>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.caseta') }}" method="POST">
        @csrf
        @method('PUT')
        <input type="hidden" name="_tarjeta" value="{{ $tarjeta }}">

        @if ($tarjeta === 'transporte')
            <label class="campo-etiqueta" for="cfg_transporte_firmas">Quién firma en la pantalla</label>
            <select id="cfg_transporte_firmas" name="transporte_firmas" class="campo" @disabled(! $puedeEditar)>
                @foreach (\App\Support\AjustesCaseta::FIRMAS_TRANSPORTE as $clave => $texto)
                    <option value="{{ $clave }}" @selected($valor('transporte_firmas') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <p class="campo-ayuda mt-0">Quien no firma en la pantalla firma a mano en el vale impreso. Solo aplica a los usuarios que pueden firmar vales. <span class="valor-original">Valor original: guardia y taxista firman en pantalla.</span></p>
        @elseif ($tarjeta === 'llaves')
            <div class="campo-grupo">
                <div class="flex-fill">
                    <label class="campo-etiqueta" for="cfg_llaves_horas_aviso">Ámbar y recordatorio a la caseta a las</label>
                    <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_llaves_horas_aviso" name="llaves_horas_aviso" class="campo campo-horas-caseta" min="0" max="{{ $max['llaves_horas_aviso'] }}" inputmode="numeric" value="{{ $valor('llaves_horas_aviso') }}" @disabled(! $puedeEditar)><span class="small text-muted">horas fuera</span></div>
                </div>
                <div class="flex-fill">
                    <label class="campo-etiqueta" for="cfg_llaves_horas_escalar">Rojo y aviso al jefe de seguridad a las</label>
                    <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_llaves_horas_escalar" name="llaves_horas_escalar" class="campo campo-horas-caseta" min="0" max="{{ $max['llaves_horas_escalar'] }}" inputmode="numeric" value="{{ $valor('llaves_horas_escalar') }}" @disabled(! $puedeEditar)><span class="small text-muted">horas fuera</span></div>
                </div>
            </div>
            <p class="campo-ayuda mt-0">En las horas, <strong>0 = apagado</strong>. Ejemplo: 8 y 12. La ficha de la llave muestra cuánto lleva fuera y cambia de color; el aviso llega a la campana. <span class="valor-original">Valor original: 0 y 0 (sin semáforo).</span></p>
        @elseif ($tarjeta === 'responsivas')
            <div class="campo-grupo">
                <div class="flex-fill">
                    <label class="campo-etiqueta" for="cfg_responsivas_horas_turno">Deben regresar después de</label>
                    <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_responsivas_horas_turno" name="responsivas_horas_turno" class="campo campo-horas-caseta" min="0" max="{{ $max['responsivas_horas_turno'] }}" inputmode="numeric" value="{{ $valor('responsivas_horas_turno') }}" @disabled(! $puedeEditar)><span class="small text-muted">horas de la entrega</span></div>
                </div>
                <div class="flex-fill">
                    <label class="campo-etiqueta" for="cfg_responsivas_horas_escalar">Avisar al supervisor si pasan otras</label>
                    <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_responsivas_horas_escalar" name="responsivas_horas_escalar" class="campo campo-horas-caseta" min="0" max="{{ $max['responsivas_horas_escalar'] }}" inputmode="numeric" value="{{ $valor('responsivas_horas_escalar') }}" @disabled(! $puedeEditar)><span class="small text-muted">horas sin regresar</span></div>
                </div>
            </div>
            <p class="campo-ayuda mt-0">En las horas, <strong>0 = apagado</strong>. Ejemplo: 8 (un turno) y 2. La responsiva muestra «Debe regresar a las…»; si no regresa, se pone en ámbar y avisa a la caseta, y después en rojo y avisa al supervisor. Los equipos «Fijo» no tienen hora de regreso. <span class="valor-original">Valor original: 0 y 0 (sin hora de regreso).</span></p>
        @elseif ($tarjeta === 'procedimientos')
            <label class="campo-etiqueta" for="cfg_procedimientos_acuse">Cómo se firma «Leí y entendí»</label>
            <select id="cfg_procedimientos_acuse" name="procedimientos_acuse" class="campo" @disabled(! $puedeEditar)>
                @foreach (\App\Support\AjustesCaseta::ACUSES as $clave => $texto)
                    <option value="{{ $clave }}" @selected($valor('procedimientos_acuse') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <p class="campo-ayuda mt-0">Un procedimiento «con puntos críticos» es el que tiene al menos un paso marcado como Punto crítico. <span class="valor-original">Valor original: casilla y firma dibujada.</span></p>
            <label class="campo-etiqueta" for="cfg_procedimientos_recordatorio_dias">Recordar cada</label>
            <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_procedimientos_recordatorio_dias" name="procedimientos_recordatorio_dias" class="campo campo-horas-caseta" min="{{ $min['procedimientos_recordatorio_dias'] }}" max="{{ $max['procedimientos_recordatorio_dias'] }}" inputmode="numeric" value="{{ $valor('procedimientos_recordatorio_dias') }}" @disabled(! $puedeEditar)><span class="small text-muted">días a quien aún no firma de enterado</span></div>
            <p class="campo-ayuda">El recordatorio sale por correo si el aviso «Procedimientos: recordar…» está encendido. <span class="valor-original">Valor original: 3 días.</span></p>
        @elseif ($tarjeta === 'vobo')
            <p class="small text-muted">Lo que espera un visto bueno (vales de taxi sin Vo.Bo. en la Bitácora de transporte y procedimientos en revisión) muestra cuánto lleva esperando y cambia de color.</p>
            <div class="campo-grupo">
                <div class="flex-fill">
                    <label class="campo-etiqueta" for="cfg_vobo_horas_amarillo">Amarillo desde las</label>
                    <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_vobo_horas_amarillo" name="vobo_horas_amarillo" class="campo campo-horas-caseta" min="{{ $min['vobo_horas_amarillo'] }}" max="{{ $max['vobo_horas_amarillo'] }}" inputmode="numeric" value="{{ $valor('vobo_horas_amarillo') }}" @disabled(! $puedeEditar)><span class="small text-muted">horas de espera</span></div>
                </div>
                <div class="flex-fill">
                    <label class="campo-etiqueta" for="cfg_vobo_horas_rojo">Rojo después de las</label>
                    <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_vobo_horas_rojo" name="vobo_horas_rojo" class="campo campo-horas-caseta" min="{{ $min['vobo_horas_rojo'] }}" max="{{ $max['vobo_horas_rojo'] }}" inputmode="numeric" value="{{ $valor('vobo_horas_rojo') }}" @disabled(! $puedeEditar)><span class="small text-muted">horas de espera</span></div>
                </div>
            </div>
            <p class="campo-ayuda mt-0">El color siempre va con su texto («más de 1 día», «más de 3 días»…). <span class="valor-original">Valor original: 24 y 72 horas.</span></p>
        @endif
        @foreach (\App\Support\AjustesCaseta::GRUPOS[$tarjeta] as $campo)
            @error($campo)<p class="text-danger small mb-1">{{ $message }}</p>@enderror
        @endforeach

        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2 btn-guardar-caseta">Guardar</button>
        @endif
    </form>
    @if ($puedeEditar && $distinto)
        @include('administracion.configuracion._restablecer', ['ajuste' => $tarjeta, 'que' => $que])
    @endif
</div>
