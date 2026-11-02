@extends('layouts.app')

@section('titulo', 'Circuito de aprobación — Pases de Salida')

@section('contenido')
<div class="tema-azul pantalla-pases">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <a href="{{ route('pases-salida.index') }}" class="volver-pases"><i class="bi bi-arrow-left" aria-hidden="true"></i> Pases de salida</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-diagram-3 text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Circuito de aprobación</h1>
            <p>Quién aprueba cada pase de salida y en qué orden. Configuración → Pases de salida.</p>
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        @php
            $filas = old('pasos') !== null ? array_values((array) old('pasos')) : $pasos->map(fn ($p) => [
                'nombre' => $p->nombre, 'tipo' => $p->tipo, 'rol_id' => $p->rol_id, 'departamento' => $p->departamento, 'departamento_id' => $p->departamento_id,
                'obligatorio' => $p->obligatorio, 'motivos' => $p->motivos ?? [], 'usuarios' => $p->usuarios->pluck('id')->all(),
                'valor_minimo' => $p->valor_minimo, 'valor_maximo' => $p->valor_maximo,
            ])->all();
            $firmasPrevias = old('firmas_caseta') !== null ? (array) old('firmas_caseta') : $firmasCaseta;
            $rechazoPrevio = old('rechazo', $rechazo);
            $excepciones = collect($firmasPrevias)->contains(fn ($f) => ! empty(array_filter((array) ($f['motivos'] ?? []))));
        @endphp

        <div class="tarjeta p-3 mb-3 explicacion-circuito">
            <p class="mb-2"><strong>Cómo funciona:</strong> los pasos se firman <strong>en orden</strong>; el siguiente se abre cuando el anterior aprueba. Si alguien rechaza, el pase regresa al solicitante para corregirlo.</p>
            <ul class="small mb-0">
                <li>Quien aprueba necesita el permiso <strong>«Aprobar»</strong> de Pases de salida en la sede de donde sale el equipo.</li>
                <li><strong>El jefe del departamento del solicitante</strong> es el responsable de ese departamento en <strong>Estructura → Departamentos</strong> (si no tiene, su suplente; si delegó con «No molestar», también su delegado). Si el departamento no tiene responsable, firma quien tenga «Aprobar» en ese departamento.</li>
                <li>El solicitante nunca aprueba su propio pase, y una persona no firma dos pasos del mismo pase (salvo que nadie más pueda).</li>
                <li>Si nadie cumple la regla de un paso, lo puede firmar cualquiera con «Aprobar» en la sede (así ningún pase se queda atorado).</li>
                <li>Los cambios aplican a los pases nuevos y a los que se reenvíen; los que ya están en curso conservan sus pasos y sus firmas en caseta.</li>
            </ul>
            @unless ($configurado)
                <p class="small text-primary mt-2 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Hoy se usa la cadena original: <strong>Jefe del departamento del solicitante → Contraloría → Gerencia</strong>.</p>
            @endunless
        </div>

        @if ($puedeEditar && ($ofrecerPasoJefe ?? false))
            <div class="tarjeta p-3 mb-3 aviso-paso-jefe">
                <p class="mb-2"><i class="bi bi-person-badge me-1 text-primary" aria-hidden="true"></i><strong>Tu circuito no tiene el paso del jefe del departamento.</strong> El circuito predeterminado ahora empieza con «Jefe del departamento del solicitante». Tu circuito no se cambió: si quieres, agrégalo como primer paso (los pases en curso no cambian).</p>
                <form action="{{ route('pases-salida.circuito.paso-jefe') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn-agregar-articulo"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar el paso del jefe del departamento</button>
                </form>
            </div>
        @endif
        @error('pasos')<div class="alert alert-danger small">{{ $message }}</div>@enderror

        @unless ($puedeEditar)
            <div class="alert alert-warning small"><i class="bi bi-lock me-1" aria-hidden="true"></i>Solo consulta: el circuito es de toda la empresa y lo cambia quien tiene «Configurar» de Pases de salida con alcance de empresa.</div>
        @endunless

        <form action="{{ route('pases-salida.circuito.update') }}" method="POST" data-form-circuito>
            @csrf
            @method('PUT')
            <fieldset @disabled(! $puedeEditar)>
                <div class="pasos-circuito" data-pasos-circuito data-siguiente="{{ count($filas) }}">
                    @foreach ($filas as $i => $f)
                        @include('seguridad.pases-salida._paso-circuito', ['i' => $i, 'f' => $f])
                    @endforeach
                </div>
                @if ($puedeEditar)
                    <button type="button" class="btn-agregar-articulo mt-2" data-agregar-paso-circuito><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar paso</button>
                @endif

                {{-- ===== Al corregir un rechazado ===== --}}
                <section class="tarjeta p-3 mt-3 ajustes-circuito" aria-labelledby="t-rechazo-circuito">
                    <h2 id="t-rechazo-circuito" class="h6 fw-bold"><i class="bi bi-arrow-repeat me-2 text-primary" aria-hidden="true"></i>Al corregir y reenviar un pase rechazado</h2>
                    @foreach (\App\Services\PasesSalida\CircuitoPasesSalida::RECHAZO as $clave => $texto)
                        <label class="opcion-guardar-firma">
                            <input type="radio" name="rechazo" value="{{ $clave }}" @checked($rechazoPrevio === $clave)>
                            <span><strong>{{ $texto }}</strong>
                                <small class="d-block text-muted">{{ $clave === 'inicio'
                                    ? 'Todas las aprobaciones se piden otra vez, desde el paso 1.'
                                    : 'Las firmas que ya se habían dado se conservan y el pase vuelve al paso que lo rechazó. Si al corregir cambió el motivo o el valor y ahora aplica otro paso, también se pide.' }}</small></span>
                        </label>
                    @endforeach
                </section>

                {{-- ===== Firmas en caseta ===== --}}
                <section class="tarjeta p-3 mt-3 ajustes-circuito" aria-labelledby="t-firmas-caseta">
                    <h2 id="t-firmas-caseta" class="h6 fw-bold"><i class="bi bi-pen me-2 text-primary" aria-hidden="true"></i>Firmas en caseta</h2>
                    <p class="small text-muted">Qué firmas pide cada movimiento de caseta. La revisión de los artículos (escanear o marcar) se pide siempre. Con <strong>Sin firma</strong> queda registrado quién lo hizo, la hora y los artículos revisados. Aplica a los pases nuevos; los que ya están en curso conservan lo que tenían.</p>
                    <div class="tabla-firmas-caseta">
                        @foreach (\App\Models\PaseSalida::PASOS_FISICOS as $movimiento => [, $boton])
                            @php $todos = $firmasPrevias[$movimiento]['todos'] ?? \App\Models\PaseSalida::FIRMAS_CASETA_OMISION; @endphp
                            <div class="fila-firmas-caseta">
                                <label class="campo-etiqueta m-0" for="firmas_{{ $movimiento }}">{{ $boton }}</label>
                                <select id="firmas_{{ $movimiento }}" name="firmas_caseta[{{ $movimiento }}][todos]" class="campo m-0">
                                    @foreach (\App\Models\PaseSalida::FIRMAS_CASETA as $clave => $texto)
                                        <option value="{{ $clave }}" @selected($todos === $clave)>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                    <details class="mt-2" @if ($excepciones) open @endif>
                        <summary>Excepciones por motivo <span class="text-muted fw-normal">(avanzado)</span></summary>
                        <p class="small text-muted my-2">Deja «Igual que todos» salvo en el motivo que deba pedir otras firmas. Venta y Traspaso definitivo solo tienen salida.</p>
                        <div class="tabla-excepciones-firmas">
                            <table class="table table-sm align-middle">
                                <thead><tr><th scope="col">Motivo</th>
                                    @foreach (\App\Models\PaseSalida::PASOS_FISICOS as $movimiento => [, $boton])<th scope="col">{{ $boton }}</th>@endforeach
                                </tr></thead>
                                <tbody>
                                    @foreach (\App\Models\PaseSalida::MOTIVOS as $motivo => $textoMotivo)
                                        <tr><th scope="row">{{ $textoMotivo }}</th>
                                            @foreach (\App\Models\PaseSalida::PASOS_FISICOS as $movimiento => [, $boton])
                                                <td>
                                                    @if (\App\Services\PasesSalida\CircuitoPasesSalida::movimientoAplica($movimiento, $motivo))
                                                        @php $elegido = (string) ($firmasPrevias[$movimiento]['motivos'][$motivo] ?? ''); @endphp
                                                        <select name="firmas_caseta[{{ $movimiento }}][motivos][{{ $motivo }}]" class="campo m-0" aria-label="{{ $boton }} · {{ $textoMotivo }}">
                                                            <option value="">Igual que todos</option>
                                                            @foreach (\App\Models\PaseSalida::FIRMAS_CASETA as $clave => $texto)
                                                                <option value="{{ $clave }}" @selected($elegido === $clave)>{{ $texto }}</option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>

                {{-- ===== Mejoras Incidentes (criterio 3): firmas que tardan ===== --}}
                @php
                    $esperaPrevia = old('espera') !== null ? (array) old('espera') : [];
                    $enUnidad = function (?int $horas, string $campo) use ($esperaPrevia): array {
                        if ($esperaPrevia !== []) {
                            return [$esperaPrevia[$campo] ?? '', $esperaPrevia[$campo.'_unidad'] ?? 'horas'];
                        }
                        if ($horas === null) {
                            return ['', 'horas'];
                        }

                        return $horas % 24 === 0 ? [(string) ($horas / 24), 'dias'] : [(string) $horas, 'horas'];
                    };
                    $tiemposEspera = ['recordar' => ['Recordar a quien debe firmar después de', 'Le llega de nuevo el aviso (campana y correo) y el pase se pinta en ámbar.'],
                        'escalar' => ['Avisar al siguiente nivel después de', 'Se avisa a quien firma el siguiente paso; si es el último, a quien configura los pases en esa sede. El pase se pinta en rojo.']];
                @endphp
                <section class="tarjeta p-3 mt-3 ajustes-circuito" aria-labelledby="t-espera-circuito">
                    <h2 id="t-espera-circuito" class="h6 fw-bold"><i class="bi bi-alarm me-2 text-primary" aria-hidden="true"></i>Firmas que tardan</h2>
                    <p class="small text-muted">Cada pase muestra cuánto lleva esperando la firma del paso actual. Déjalo vacío para usar las horas de «Reloj y suplente» de Configuración (24 h y 72 h de fábrica). Cada aviso se manda una sola vez por paso.</p>
                    @foreach ($tiemposEspera as $campo => [$etiquetaEspera, $ayudaEspera])
                        @php [$valorEspera, $unidadEspera] = $enUnidad($espera[$campo] ?? null, $campo); @endphp
                        <div class="fila-espera-circuito">
                            <label class="campo-etiqueta" for="espera_{{ $campo }}">{{ $etiquetaEspera }}</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="number" id="espera_{{ $campo }}" name="espera[{{ $campo }}]" class="campo campo-dias-lf mb-0" min="1" max="720" inputmode="numeric" value="{{ $valorEspera }}" placeholder="—">
                                <select name="espera[{{ $campo }}_unidad]" class="campo mb-0" aria-label="Unidad">
                                    @foreach (\App\Services\PasesSalida\CircuitoPasesSalida::UNIDADES_ESPERA as $claveUnidad => $textoUnidad)
                                        <option value="{{ $claveUnidad }}" @selected($unidadEspera === $claveUnidad)>{{ $textoUnidad }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('espera.'.$campo)<p class="text-danger small mb-1">{{ $message }}</p>@enderror
                            <p class="campo-ayuda mt-1">{{ $ayudaEspera }}</p>
                        </div>
                    @endforeach
                </section>

                <template data-plantilla-paso-circuito>
                    @include('seguridad.pases-salida._paso-circuito', ['i' => '__i__', 'f' => ['tipo' => 'permiso', 'departamento' => 'cualquiera', 'obligatorio' => true, 'motivos' => [], 'usuarios' => []]])
                </template>
                @if ($puedeEditar)
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="submit" class="btn-nuevo-pase"><i class="bi bi-save me-2" aria-hidden="true"></i>Guardar circuito</button>
                    </div>
                @endif
            </fieldset>
        </form>

        @if ($puedeEditar && $configurado)
            <form action="{{ route('pases-salida.circuito.destroy') }}" method="POST" class="mt-3" data-confirmar="¿Volver a la configuración original (Jefe del departamento del solicitante, Contraloría y Gerencia), con dos firmas en cada movimiento de caseta y el rechazo desde el primer paso?">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-enlace-pase"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restablecer la configuración original</button>
            </form>
        @endif
    @endif
</div>
@endsection
