{{-- Un paso del circuito de aprobación. $i: índice (o "__i__" en la plantilla); $f: valores. --}}
@php
    $tipo = $f['tipo'] ?? 'permiso';
    $depto = $f['departamento'] ?? 'cualquiera';
    $motivos = array_map('strval', (array) ($f['motivos'] ?? []));
    $elegidos = array_map('intval', (array) ($f['usuarios'] ?? []));
    $obligatorio = filter_var($f['obligatorio'] ?? false, FILTER_VALIDATE_BOOLEAN);
@endphp
<section class="paso-circuito" data-paso-circuito>
    <div class="paso-circuito-cabecera">
        <span class="numero-paso" data-numero-paso>{{ is_int($i) ? $i + 1 : '' }}</span>
        <label class="visually-hidden" for="paso_{{ $i }}_nombre">Nombre del paso</label>
        <input type="text" id="paso_{{ $i }}_nombre" name="pasos[{{ $i }}][nombre]" class="campo m-0 paso-circuito-nombre" maxlength="120" required
               placeholder="Ej: Contraloría" value="{{ $f['nombre'] ?? '' }}">
        <div class="paso-circuito-mover">
            <button type="button" class="btn-icono" data-mover-paso="arriba" title="Subir" aria-label="Subir paso"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
            <button type="button" class="btn-icono" data-mover-paso="abajo" title="Bajar" aria-label="Bajar paso"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
            <button type="button" class="btn-icono eliminar" data-quitar-paso title="Quitar paso" aria-label="Quitar paso"><i class="bi bi-trash" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <label class="campo-etiqueta" for="paso_{{ $i }}_tipo">¿Quién firma?</label>
            <select id="paso_{{ $i }}_tipo" name="pasos[{{ $i }}][tipo]" class="campo" data-paso-tipo>
                @foreach (\App\Models\PaseSalidaPaso::TIPOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($tipo === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <div data-si-tipo="rol" @if ($tipo !== 'rol') hidden @endif>
                <label class="campo-etiqueta" for="paso_{{ $i }}_rol">Rol</label>
                <select id="paso_{{ $i }}_rol" name="pasos[{{ $i }}][rol_id]" class="campo" @disabled($tipo !== 'rol')>
                    <option value="">-- Seleccionar --</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r->id }}" @selected((string) ($f['rol_id'] ?? '') === (string) $r->id)>{{ $r->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div data-si-tipo="usuarios" @if ($tipo !== 'usuarios') hidden @endif>
                <span class="campo-etiqueta">Usuarios</span>
                <div class="lista-casillas-circuito">
                    @foreach ($usuarios as $u)
                        <label><input type="checkbox" name="pasos[{{ $i }}][usuarios][]" value="{{ $u->id }}" @checked(in_array($u->id, $elegidos, true)) @disabled($tipo !== 'usuarios')> {{ $u->name }} <span class="text-muted">({{ $u->username }})</span></label>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <label class="campo-etiqueta" for="paso_{{ $i }}_depto">Departamento de quien firma</label>
            <select id="paso_{{ $i }}_depto" name="pasos[{{ $i }}][departamento]" class="campo" data-paso-departamento>
                @foreach (\App\Models\PaseSalidaPaso::DEPARTAMENTOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($depto === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <div data-si-departamento="especifico" @if ($depto !== 'especifico') hidden @endif>
                <label class="campo-etiqueta" for="paso_{{ $i }}_depto_id">Departamento</label>
                <select id="paso_{{ $i }}_depto_id" name="pasos[{{ $i }}][departamento_id]" class="campo" @disabled($depto !== 'especifico')>
                    <option value="">-- Seleccionar --</option>
                    @foreach ($departamentos as $d)
                        <option value="{{ $d->id }}" @selected((string) ($f['departamento_id'] ?? '') === (string) $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <label class="opcion-guardar-firma">
                <input type="hidden" name="pasos[{{ $i }}][obligatorio]" value="0">
                <input type="checkbox" name="pasos[{{ $i }}][obligatorio]" value="1" @checked($obligatorio)>
                <span><strong>Obligatorio</strong> <small class="d-block text-muted">Si no, quien lo firma puede omitirlo con un comentario.</small></span>
            </label>
        </div>
    </div>
    <span class="campo-etiqueta">Aplica a los motivos <span class="text-lowercase fw-normal">(sin marcar = todos)</span></span>
    <div class="motivos-circuito">
        @foreach (\App\Models\PaseSalida::MOTIVOS as $clave => $texto)
            <label><input type="checkbox" name="pasos[{{ $i }}][motivos][]" value="{{ $clave }}" @checked(in_array($clave, $motivos, true))> {{ $texto }}</label>
        @endforeach
    </div>
</section>
