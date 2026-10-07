{{--
    Registro Rápido de Persona (para la Bitácora de accesos y otros módulos de caseta).
    Registra en el Padrón de personas a quien llega sin estar registrado, sin salir de la pantalla.

    Uso desde cualquier pantalla:
        @include('seguridad.personas._registro-rapido', ['proveedorSugerido' => $proveedorId])
        <button type="button" data-abrir-dialogo="dialogoRegistroRapidoPersona">Registrar persona</button>

    Al guardar, plataforma.js cierra el diálogo y lanza en document el evento "persona:registrada"
    con detail = {id, nombre_completo, tipo, tipo_etiqueta, categoria, empresa, proveedor_id, folio, activo}
    (folio siempre enmascarado: "INE ••••5678").

    Si el folio ya lo tiene otra persona, el servidor responde 409 con esa persona y el diálogo
    ofrece "Usar a esta persona" (lanza el mismo evento con ella) en vez de duplicarla.

    Se dibuja para quien tiene "visitantes.crear" o, desde una pantalla de
    Operación (Accesos, Lost & Found, Transporte), su permiso operativo:
    entonces la persona nace "pendiente de verificar" (ADR-0006). Antes de
    crear se ofrecen las personas parecidas ("¿Es alguna de estas?").
    Siempre con empresa de trabajo. Ver docs/tecnico/personas.md.
--}}
@php
    $tenantRapidoPe = app(\App\Support\Tenancy\Tenant::class);
    $altasRapidoPe = app(\App\Services\Padrones\AltasPorVerificar::class);
    $origenRapidoPe = $altasRapidoPe->origenDeRuta(request()->route()?->getName());
@endphp
@if (auth()->user() && $altasRapidoPe->puedeAltaRapida(auth()->user(), 'personas', $origenRapidoPe) && $tenantRapidoPe->activo())
    @php
        $proveedoresRapidoPe = \App\Models\Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'categoria']);
        $sugeridoPe = (int) ($proveedorSugerido ?? 0);
        $tipoSugeridoPe = $sugeridoPe > 0
            ? (($proveedoresRapidoPe->firstWhere('id', $sugeridoPe)?->categoria === 'contratista') ? 'contratista' : 'proveedor')
            : 'visitante';
    @endphp
    <dialog id="dialogoRegistroRapidoPersona" class="dialogo ancho tema-azul dialogo-persona" aria-labelledby="titulo-pe-rapido">
        <div class="dialogo-cabecera">
            <h2 id="titulo-pe-rapido"><i class="bi bi-person-add me-2 text-primary" aria-hidden="true"></i>Registro Rápido de Persona</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <div class="alert alert-secondary py-2 px-3 small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Úsalo solo si la persona <strong>no aparece</strong> al buscarla. Queda en el Padrón de personas.</div>
            @include('padrones.altas-por-verificar._aviso-alta', ['padron' => 'personas'])
            <form action="{{ route('personas.rapido') }}" method="POST" autocomplete="off" data-registro-rapido-persona data-form-persona
                  data-alta-padron="personas" data-url-parecidos="{{ route('altas_por_verificar.parecidos') }}">
                @csrf
                <input type="hidden" name="origen" value="{{ $origenRapidoPe }}">
                <input type="hidden" name="confirmar_nuevo" value="0" data-alta-confirmar>
                <div class="caja-parecidos" role="alert" data-alta-parecidos hidden></div>
                <div class="alert alert-danger small py-2" role="alert" data-errores-rapido-persona hidden></div>
                <div class="caja-parecidos" role="alert" data-existente-rapido-persona hidden></div>
                <div class="row">
                    <div class="col-md-7">
                        <label class="campo-etiqueta" for="rapido_pe_nombre">Nombre Completo</label>
                        <input type="text" id="rapido_pe_nombre" name="nombre_completo" class="campo" maxlength="150" required>
                    </div>
                    <div class="col-md-5">
                        <label class="campo-etiqueta" for="rapido_pe_tipo">Tipo</label>
                        <select id="rapido_pe_tipo" name="tipo" class="campo" data-persona-tipo required>
                            @foreach (\App\Models\Persona::TIPOS as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($tipoSugeridoPe === $clave)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="caja-empresa-persona" data-solo-empresa>
                    <label class="campo-etiqueta" for="rapido_pe_proveedor"><i class="bi bi-building me-1" aria-hidden="true"></i> Empresa que representa</label>
                    <select id="rapido_pe_proveedor" name="proveedor_id" class="campo mb-0" data-persona-proveedor>
                        <option value="">-- No está en el directorio de proveedores --</option>
                        @foreach ($proveedoresRapidoPe as $prov)
                            <option value="{{ $prov->id }}" data-categoria="{{ $prov->categoria }}" @selected($sugeridoPe === $prov->id)>{{ $prov->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div data-solo-sin-proveedor>
                    <label class="campo-etiqueta" for="rapido_pe_procedencia">Empresa de procedencia <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="text" id="rapido_pe_procedencia" name="empresa_procedencia" class="campo" maxlength="100">
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_pe_tipo_id">Identificación</label>
                        <select id="rapido_pe_tipo_id" name="tipo_identificacion" class="campo">
                            @foreach (\App\Models\Persona::IDENTIFICACIONES as $clave => $etiqueta)
                                <option value="{{ $clave }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_pe_folio">Folio / Número</label>
                        <input type="text" id="rapido_pe_folio" name="folio_identificacion" class="campo text-uppercase" maxlength="40" autocapitalize="characters">
                    </div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_pe_tel">Teléfono</label>
                        <input type="tel" inputmode="tel" id="rapido_pe_tel" name="telefono" class="campo" maxlength="20" placeholder="10 dígitos">
                    </div>
                </div>
                <label class="campo-etiqueta" for="rapido_pe_motivo">Motivo de la Visita</label>
                <textarea id="rapido_pe_motivo" name="motivo_visita" class="campo" rows="2" maxlength="500"></textarea>

                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-azul" data-texto-original="Registrar">Registrar</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
