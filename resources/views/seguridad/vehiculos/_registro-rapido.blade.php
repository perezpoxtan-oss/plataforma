{{--
    Registro Rápido de Vehículo, para dar de alta un auto de paso sin salir de
    otro módulo (Bitácora de accesos, Estacionamientos...).

    Uso desde cualquier pantalla:
        @include('seguridad.vehiculos._registro-rapido')
        <button type="button" data-abrir-dialogo="dialogoRegistroRapidoVehiculo">Registrar vehículo</button>

    Al guardar, plataforma.js lanza en document el evento "vehiculo:registrado"
    con detail = {id, placas, descripcion, propiedad, propiedad_etiqueta,
    numero_economico, empresa, colaborador, codigo_qr, activo}.
    Si las placas ya existen (409), se ofrece usar ese vehículo: elegirlo lanza
    el mismo evento con el vehículo existente. Ver docs/tecnico/vehiculos.md.

    Se muestra a quien tiene "vehiculos.crear" o, desde una pantalla de
    Operación (Accesos, Transporte), su permiso operativo: entonces el vehículo
    nace "pendiente de verificar" (ADR-0006). Antes de crear se ofrecen las
    placas parecidas ("¿Es alguno de estos?").
--}}
@php
    $tenantRapidoVe = app(\App\Support\Tenancy\Tenant::class);
    $altasRapidoVe = app(\App\Services\Padrones\AltasPorVerificar::class);
    $origenRapidoVe = $altasRapidoVe->origenDeRuta(request()->route()?->getName());
@endphp
@if (auth()->user() && $altasRapidoVe->puedeAltaRapida(auth()->user(), 'vehiculos', $origenRapidoVe) && $tenantRapidoVe->activo())
    @php
        $proveedoresRapido = \App\Models\Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
    @endphp
    <dialog id="dialogoRegistroRapidoVehiculo" class="dialogo ancho tema-pizarra" aria-labelledby="titulo-ve-rapido">
        <div class="dialogo-cabecera">
            <h2 id="titulo-ve-rapido"><i class="bi bi-car-front-fill me-2 text-secondary" aria-hidden="true"></i>Registro Rápido de Vehículo</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            @include('padrones.altas-por-verificar._aviso-alta', ['padron' => 'vehiculos'])
            <form action="{{ route('vehiculos.rapido') }}" method="POST" autocomplete="off" data-registro-rapido-vehiculo data-form-vehiculo
                  data-alta-padron="vehiculos" data-url-parecidos="{{ route('altas_por_verificar.parecidos') }}">
                @csrf
                <input type="hidden" name="origen" value="{{ $origenRapidoVe }}">
                <input type="hidden" name="confirmar_nuevo" value="0" data-alta-confirmar>
                <div class="caja-parecidos" role="alert" data-alta-parecidos hidden></div>
                <div class="alert alert-danger small py-2" role="alert" data-errores-rapido-vehiculo hidden></div>
                <div class="caja-parecidos" role="alert" data-existente-vehiculo hidden></div>
                <div class="row">
                    <div class="col-md-5">
                        <label class="campo-etiqueta" for="rapido_ve_placas">Placas</label>
                        <input type="text" id="rapido_ve_placas" name="placas" class="campo campo-placas" maxlength="25" placeholder="Ej: ABC-123-A" autocapitalize="characters" required>
                    </div>
                    <div class="col-md-7">
                        <label class="campo-etiqueta" for="rapido_ve_propiedad">Categoría</label>
                        <select id="rapido_ve_propiedad" name="propiedad" class="campo" required>
                            @foreach (\App\Services\Vehiculos\AdministradorVehiculos::OPCIONES_PROPIEDAD as $clave => $texto)
                                <option value="{{ $clave }}" @if ($clave === 'propio_visitante') selected data-por-defecto @endif>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="caja-propietario" data-mostrar-si='{"propiedad":{{ json_encode(\App\Services\Vehiculos\AdministradorVehiculos::CON_PROVEEDOR) }}}'>
                    <label class="campo-etiqueta" for="rapido_ve_proveedor">Agencia o Empresa Propietaria</label>
                    <select id="rapido_ve_proveedor" name="proveedor_id" class="campo mb-0" data-requerido-si='{"propiedad":{{ json_encode(\App\Services\Vehiculos\AdministradorVehiculos::PROVEEDOR_OBLIGATORIO) }}}'>
                        <option value="">-- Seleccionar del Directorio --</option>
                        @foreach ($proveedoresRapido as $pr)
                            <option value="{{ $pr->id }}">{{ $pr->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_ve_tipo">Tipo / Estilo</label>
                        <select id="rapido_ve_tipo" name="tipo" class="campo" required>
                            @foreach (\App\Models\Vehiculo::TIPOS as $clave => $texto)
                                <option value="{{ $clave }}" @if ($clave === 'sedan') selected data-por-defecto @endif>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_ve_marca">Marca</label>
                        <input type="text" id="rapido_ve_marca" name="marca" class="campo text-uppercase" maxlength="50" required>
                    </div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_ve_color">Color</label>
                        <input type="text" id="rapido_ve_color" name="color" class="campo text-uppercase" maxlength="30" required>
                    </div>
                </div>
                <div data-mostrar-si='{"tipo":["otro"]}'>
                    <label class="campo-etiqueta" for="rapido_ve_otro">Describe el Tipo de Vehículo</label>
                    <input type="text" id="rapido_ve_otro" name="descripcion_otro" class="campo" maxlength="150" data-requerido-si='{"tipo":["otro"]}'>
                </div>
                <label class="campo-etiqueta" for="rapido_ve_modelo">Modelo <span class="text-lowercase fw-normal">(opcional)</span></label>
                <input type="text" id="rapido_ve_modelo" name="modelo" class="campo text-uppercase" maxlength="60">

                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-pizarra" data-texto-original="Registrar Vehículo">Registrar Vehículo</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
