{{--
    Configuración → «Caseta y activos» (ADR-0014). Sin configurar, todo funciona
    como el diseño original. Variables: $empresa, $puedeEditar.
--}}
@php
    $ajustes = \App\Support\AjustesCaseta::de($empresa);
    $valor = fn (string $clave) => old($clave, $ajustes[$clave]);
    $max = \App\Support\AjustesCaseta::MAX_HORAS;
@endphp
<section class="tarjeta p-4 config-caseta" id="caseta" aria-labelledby="t-caseta">
    <h2 id="t-caseta" class="h5 fw-bold"><i class="bi bi-shield-lock me-2 text-primary" aria-hidden="true"></i>Caseta y activos</h2>
    <p class="small text-muted">Ajustes de la caseta para todas las sedes de {{ $empresa->nombre_comercial }}. Si no cambias nada, todo funciona como siempre. En las horas, <strong>0 = apagado</strong>.</p>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.caseta') }}" method="POST">
        @csrf
        @method('PUT')

        <h3 class="config-caseta-titulo"><i class="bi bi-taxi-front me-1" aria-hidden="true"></i>Bitácora de transporte: firmas del vale de taxi</h3>
        <label class="campo-etiqueta" for="cfg_transporte_firmas">Quién firma en la pantalla</label>
        <select id="cfg_transporte_firmas" name="transporte_firmas" class="campo" @disabled(! $puedeEditar)>
            @foreach (\App\Support\AjustesCaseta::FIRMAS_TRANSPORTE as $clave => $texto)
                <option value="{{ $clave }}" @selected($valor('transporte_firmas') === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
        <p class="campo-ayuda mt-0">Quien no firma en la pantalla firma a mano en el vale impreso. Solo aplica a los usuarios que pueden firmar vales.</p>

        <h3 class="config-caseta-titulo"><i class="bi bi-key me-1" aria-hidden="true"></i>Préstamo de llaves: llaves fuera mucho tiempo</h3>
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
        <p class="campo-ayuda mt-0">Ejemplo: 8 y 12. La ficha de la llave muestra cuánto lleva fuera y cambia de color; el aviso llega a la campana.</p>

        <h3 class="config-caseta-titulo"><i class="bi bi-pen me-1" aria-hidden="true"></i>Responsivas: equipos de «Turno»</h3>
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
        <p class="campo-ayuda mt-0">Ejemplo: 8 (un turno) y 2. La responsiva muestra «Debe regresar a las…»; si no regresa, se pone en ámbar y avisa a la caseta, y después en rojo y avisa al supervisor. Los equipos «Fijo» no tienen hora de regreso.</p>

        <h3 class="config-caseta-titulo"><i class="bi bi-book me-1" aria-hidden="true"></i>Procedimientos: cómo se firma «Leí y entendí»</h3>
        <label class="campo-etiqueta" for="cfg_procedimientos_acuse">Acuse de lectura</label>
        <select id="cfg_procedimientos_acuse" name="procedimientos_acuse" class="campo" @disabled(! $puedeEditar)>
            @foreach (\App\Support\AjustesCaseta::ACUSES as $clave => $texto)
                <option value="{{ $clave }}" @selected($valor('procedimientos_acuse') === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
        <p class="campo-ayuda mt-0">Un procedimiento «con puntos críticos» es el que tiene al menos un paso marcado como Punto crítico.</p>

        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2 btn-guardar-caseta">Guardar ajustes de caseta</button>
        @endif
    </form>
</section>
