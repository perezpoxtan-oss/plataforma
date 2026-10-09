{{--
    "Nuevo Pase de Salida" (SEGCAT: diálogo dialogNuevoPase de pases_salida_lista.php),
    en los mismos 3 pasos: 1. Motivo y Solicitante, 2. Enviar A, 3. Artículos que Salen.
    El solicitante y el colaborador destino se eligen con el lector universal
    (gafete, QR o NFC) o buscando su nombre; los equipos del padrón se pueden
    escanear para llenar su renglón.

    $modoFormulario: 'crear' (lista) o 'corregir' (ficha de un pase rechazado: $paseEditar,
    se guarda con PUT y empieza una ronda nueva de aprobaciones).
--}}
@php
    $modoFormulario = $modoFormulario ?? 'crear';
    $corrigiendo = $modoFormulario === 'corregir';
    $reabrir = old('_dialogo') === $modoFormulario || ($modoFormulario === 'crear' && request()->boolean('nuevo'));
    $conErrores = old('_dialogo') === $modoFormulario;
    $base = $corrigiendo ? [
        'sede_id' => $paseEditar->sede_id, 'motivo' => $paseEditar->motivo, 'colaborador_id' => $paseEditar->colaborador_id,
        'destino_tipo' => $paseEditar->destino_tipo, 'sede_destino_id' => $paseEditar->sede_destino_id, 'proveedor_id' => $paseEditar->proveedor_id,
        'colaborador_destino_id' => $paseEditar->colaborador_destino_id, 'destino_direccion' => $paseEditar->destino_direccion,
        'destino_telefono' => $paseEditar->destino_telefono, 'fecha_salida_programada' => $paseEditar->fecha_salida_programada?->format('Y-m-d'),
        'fecha_tentativa_regreso' => $paseEditar->fecha_tentativa_regreso?->format('Y-m-d'),
    ] : [];
    $previo = fn (string $campo, $porDefecto = '') => $conErrores ? old($campo, $base[$campo] ?? $porDefecto) : ($base[$campo] ?? $porDefecto);
    $hoy = now(app(\App\Support\HoraLocal::class)->zona())->format('Y-m-d');
    $sedeUnica = $formulario['sedesOrigen']->count() === 1 ? (string) $formulario['sedesOrigen']->first()->id : '';
    $articulosPrevios = $conErrores && is_array(old('articulos')) ? array_values(old('articulos'))
        : ($corrigiendo ? $paseEditar->articulos->map(fn ($a) => $a->only(['equipo_id', 'cantidad', 'equipo', 'marca', 'modelo', 'serie', 'descripcion']))->all() : [[]]);
    $conRegreso = array_values(array_diff(array_keys(\App\Models\PaseSalida::MOTIVOS), \App\Models\PaseSalida::SIN_REGRESO));
    $buscarNombres = auth()->user()->can('colaboradores.ver') || auth()->user()->can('colaboradores.provisional');
    $idDialogo = $corrigiendo ? 'dialogoCorregirPase' : 'dialogoNuevoPase';
@endphp
<dialog id="{{ $idDialogo }}" class="dialogo extra-ancho dialogo-pase" aria-labelledby="titulo-{{ $idDialogo }}" @if ($reabrir) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-{{ $idDialogo }}"><i class="bi {{ $corrigiendo ? 'bi-pencil-square' : 'bi-box-arrow-up-right' }} me-2 text-primary" aria-hidden="true"></i>{{ $corrigiendo ? 'Corregir y reenviar '.$paseEditar->folio : 'Nuevo Pase de Salida' }}</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ $corrigiendo ? route('pases-salida.update', $paseEditar->id) : route('pases-salida.store') }}" method="POST" autocomplete="off" data-form-pase
              data-url-equipo="{{ route('pases-salida.equipo', 0) }}" @if ($buscarNombres) data-url-buscar-colaborador="{{ route('colaboradores.buscar') }}" @endif>
            @csrf
            @if ($corrigiendo) @method('PUT') @endif
            <input type="hidden" name="_dialogo" value="{{ $modoFormulario }}">
            @if ($corrigiendo)
                <div class="alert alert-warning small py-2"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Corrige lo que pidieron y reenvía: las aprobaciones empiezan otra vez desde el primer paso.
                    @if ($paseEditar->motivo_rechazo)<br><strong>Motivo del rechazo:</strong> {{ $paseEditar->motivo_rechazo }}@endif
                </div>
            @endif
            @if ($conErrores && $errors->any())
                <div class="alert alert-danger small py-2" role="alert">
                    @foreach ($errors->all() as $error)<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</div>@endforeach
                </div>
            @endif

            <label class="campo-etiqueta" for="ps_sede">Sede de Origen</label>
            <select id="ps_sede" name="sede_id" class="campo" required data-pase-origen>
                @if ($sedeUnica === '')<option value="">-- Seleccionar --</option>@endif
                @foreach ($formulario['sedesOrigen'] as $s)
                    <option value="{{ $s->id }}" @selected((string) $previo('sede_id', $sedeUnica) === (string) $s->id) @if ((string) $s->id === $sedeUnica) data-por-defecto @endif>{{ $s->nombre }}</option>
                @endforeach
            </select>

            {{-- ===== 1. Motivo y Solicitante ===== --}}
            <x-seccion clave="pase-1" :abierta="true">
                <x-slot:titulo><span class="numero-paso">1</span><i class="bi bi-info-square text-primary me-2" aria-hidden="true"></i>Motivo y Solicitante</x-slot:titulo>
            <label class="campo-etiqueta" for="ps_motivo">Motivo de Salida</label>
            <select id="ps_motivo" name="motivo" class="campo mb-1" required data-pase-motivo>
                <option value="">-- Seleccionar --</option>
                @foreach (\App\Models\PaseSalida::MOTIVOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($previo('motivo') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <p class="aviso-regreso" data-mostrar-si='{"motivo":{{ json_encode($conRegreso) }}}'><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Este motivo espera que el equipo regrese — se habilitará el circuito de regreso.</p>
            <p class="aviso-regreso" data-mostrar-si='{"motivo":{{ json_encode(\App\Models\PaseSalida::SIN_REGRESO) }}}'><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Este motivo no espera regreso del equipo.</p>

            <div class="fila-con-boton" data-pase-colaborador data-para="solicitante">
                <div class="flex-fill min-w-0">
                    @include('componentes.lector', ['id' => 'ps_solicitante', 'etiqueta' => 'Solicitante (escanea su gafete o busca su nombre)', 'tipos' => 'colaborador',
                        'nombre' => 'colaborador_id', 'requerido' => true, 'valor' => $previo('colaborador_id', $formulario['solicitanteFijo']['id'] ?? ''),
                        'elegido' => $anteriores['colaborador_id'] ?? ($formulario['solicitanteFijo']['texto'] ?? null)])
                </div>
                @if ($puede['colaborador'])
                    <button type="button" class="btn-atajo-pase" data-abrir-dialogo="dialogoRegistroRapidoColaborador" data-registrar-para="solicitante">
                        <i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Nuevo Colaborador
                    </button>
                @endif
            </div>
            <div class="row">
                <div class="col-md-6"><label class="campo-etiqueta" for="ps_depto">Departamento</label><input type="text" id="ps_depto" class="campo campo-solo-lectura" readonly tabindex="-1" data-solicitante-depto></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="ps_puesto">Puesto</label><input type="text" id="ps_puesto" class="campo campo-solo-lectura" readonly tabindex="-1" data-solicitante-puesto></div>
            </div>

            {{-- ===== 2. Enviar A ===== --}}
            </x-seccion>
            <x-seccion clave="pase-2" :abierta="true">
                <x-slot:titulo><span class="numero-paso">2</span><i class="bi bi-signpost-split text-success me-2" aria-hidden="true"></i>Enviar A</x-slot:titulo>
            <label class="campo-etiqueta" for="ps_destino_tipo">Tipo de Destino</label>
            <select id="ps_destino_tipo" name="destino_tipo" class="campo" required>
                @foreach (\App\Models\PaseSalida::DESTINOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($previo('destino_tipo', 'sede') === $clave) @if ($clave === 'sede') data-por-defecto @endif>{{ $texto }}</option>
                @endforeach
            </select>

            <div data-mostrar-si='{"destino_tipo":["sede"]}'>
                <label class="campo-etiqueta" for="ps_sede_destino">Sede Destino</label>
                <select id="ps_sede_destino" name="sede_destino_id" class="campo" data-requerido-si='{"destino_tipo":["sede"]}' data-pase-sede-destino>
                    <option value="">-- Seleccionar --</option>
                    @foreach ($formulario['sedesDestino'] as $s)
                        <option value="{{ $s['id'] }}" data-direccion="{{ $s['direccion'] }}" data-telefono="{{ $s['telefono'] }}" @selected((string) $previo('sede_destino_id') === (string) $s['id'])>{{ $s['nombre'] }}</option>
                    @endforeach
                </select>
            </div>

            <div data-mostrar-si='{"destino_tipo":["proveedor"]}'>
                <div class="fila-con-boton">
                    <div class="flex-fill min-w-0">
                        <label class="campo-etiqueta" for="ps_proveedor">Proveedor Destino</label>
                        <select id="ps_proveedor" name="proveedor_id" class="campo" data-requerido-si='{"destino_tipo":["proveedor"]}' data-pase-proveedor>
                            <option value="">-- Seleccionar --</option>
                            @foreach ($formulario['proveedores'] as $pr)
                                <option value="{{ $pr->id }}" data-direccion="{{ $pr->direccion }}" data-telefono="{{ $pr->telefono }}" @selected((string) $previo('proveedor_id') === (string) $pr->id)>{{ $pr->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($puede['proveedor'])
                        <button type="button" class="btn-atajo-pase" data-abrir-dialogo="dialogoAltaRapidaProveedor"><i class="bi bi-building-add me-1" aria-hidden="true"></i>Nuevo Proveedor</button>
                    @endif
                </div>
            </div>

            <div data-mostrar-si='{"destino_tipo":["colaborador"]}'>
                <div class="fila-con-boton" data-pase-colaborador data-para="destino">
                    <div class="flex-fill min-w-0">
                        @include('componentes.lector', ['id' => 'ps_colaborador_destino', 'etiqueta' => 'Colaborador que se lo lleva (escanea su gafete o busca su nombre)', 'tipos' => 'colaborador',
                            'nombre' => 'colaborador_destino_id', 'valor' => $previo('colaborador_destino_id'), 'elegido' => $anteriores['colaborador_destino_id'] ?? null])
                    </div>
                    @if ($puede['colaborador'])
                        <button type="button" class="btn-atajo-pase" data-abrir-dialogo="dialogoRegistroRapidoColaborador" data-registrar-para="destino">
                            <i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Nuevo Colaborador
                        </button>
                    @endif
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <label class="campo-etiqueta" for="ps_direccion">Dirección de destino</label>
                    <input type="text" id="ps_direccion" name="destino_direccion" class="campo" maxlength="255" value="{{ $previo('destino_direccion') }}" data-pase-direccion>
                </div>
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="ps_telefono">Teléfono</label>
                    <input type="tel" inputmode="tel" id="ps_telefono" name="destino_telefono" class="campo" maxlength="20" value="{{ $previo('destino_telefono') }}" data-pase-telefono>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="ps_fecha_salida">Fecha de Salida Programada</label>
                    <input type="date" id="ps_fecha_salida" name="fecha_salida_programada" class="campo" value="{{ $previo('fecha_salida_programada', $hoy) }}">
                </div>
                <div class="col-md-6" data-mostrar-si='{"motivo":{{ json_encode($conRegreso) }}}'>
                    <label class="campo-etiqueta" for="ps_fecha_regreso">Fecha Tentativa de Regreso <span class="text-lowercase fw-normal">(para dar seguimiento)</span></label>
                    <input type="date" id="ps_fecha_regreso" name="fecha_tentativa_regreso" class="campo" value="{{ $previo('fecha_tentativa_regreso') }}">
                </div>
            </div>

            {{-- ===== 3. Artículos que Salen ===== --}}
            </x-seccion>
            <x-seccion clave="pase-3" :abierta="true">
                <x-slot:titulo><span class="numero-paso">3</span><i class="bi bi-box-seam text-warning me-2" aria-hidden="true"></i>Artículos que Salen</x-slot:titulo>
            @if ($puede['equipos'])
                <div class="lector-equipo-pase" data-pase-lector-equipo>
                    @include('componentes.lector', ['id' => 'ps_equipo_lector', 'etiqueta' => 'Escanear un equipo del padrón (opcional)', 'tipos' => 'equipo', 'nombre' => '',
                        'ayuda' => 'Escanea el QR o la etiqueta de un radio, lámpara, etc. y se agrega solo como artículo. Lo demás se captura a mano.'])
                </div>
            @endif
            <div class="articulos-pase" data-articulos-pase data-siguiente="{{ count($articulosPrevios) }}">
                @foreach ($articulosPrevios as $i => $a)
                    @include('seguridad.pases-salida._articulo', ['i' => $i, 'a' => is_array($a) ? $a : []])
                @endforeach
            </div>
            <template data-plantilla-articulo>
                @include('seguridad.pases-salida._articulo', ['i' => '__i__', 'a' => []])
            </template>
            <button type="button" class="btn-agregar-articulo" data-agregar-articulo><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar artículo</button>

            @if ($corrigiendo)
                <label class="campo-etiqueta mt-2" for="ps_respuesta">Qué corregiste <span class="text-lowercase fw-normal">(opcional, lo verán los aprobadores)</span></label>
                <textarea id="ps_respuesta" name="comentario" class="campo" rows="2" maxlength="1000" placeholder="Ej: Se agregó la factura y se corrigió la serie.">{{ $conErrores ? old('comentario') : '' }}</textarea>
            @endif
            </x-seccion>
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                @if ($corrigiendo)
                    <button type="submit" class="btn-azul">Guardar y Reenviar a Aprobación</button>
                @else
                    <button type="submit" class="btn-cancelar btn-siguiente-pase" name="siguiente" value="1">Registrar y capturar siguiente</button>
                    <button type="submit" class="btn-azul">Guardar y Enviar a Aprobación</button>
                @endif
            </div>
        </form>
    </div>
</dialog>
