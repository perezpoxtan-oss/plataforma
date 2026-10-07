{{--
    Diálogo "Verificar Alta" de la lista de un padrón (ADR-0006). Uno por
    pantalla; el botón "Verificar" de cada ficha (_boton) lo llena con sus datos.
    Tres caminos, uno a la vez:
      - Es correcto: Aceptar (corrigiendo los datos si hace falta);
      - Ya existía: Unir con el registro correcto (sugeridos o búsqueda);
      - Rechazar: con el motivo (se conserva como historia).
    Se envía por JSON (los errores se ven aquí dentro) y al terminar se recarga la lista.
    $altas = AltasPorVerificar::paraLista(...)
--}}
@if ($altas['dialogos'] !== [])
    @php
        $padronVa = $altas['padron'];
        $defVa = \App\Services\Padrones\AltasPorVerificar::PADRONES[$padronVa];
        $buscarVa = ['vehiculos' => 'vehiculos.buscar', 'proveedores' => 'proveedores.buscar', 'personas' => 'personas.buscar'][$padronVa];
        $unVa = $defVa['genero'] === 'f' ? 'una' : 'un';
    @endphp
    <dialog id="dialogoVerificarAlta" class="dialogo ancho dialogo-verificar-alta" aria-labelledby="titulo-verificar-alta"
            data-dialogo-verificar data-url-buscar="{{ route($buscarVa) }}">
        <div class="dialogo-cabecera">
            <h2 id="titulo-verificar-alta"><i class="bi bi-patch-check me-2 text-warning" aria-hidden="true"></i>Verificar Alta Pendiente</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <div class="resumen-alta">
                <span class="insignia-verificacion pendiente"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Pendiente de verificar</span>
                <strong class="titulo-alta" data-va-titulo></strong>
                <small class="d-block" data-va-registro></small>
            </div>
            <div class="errores-verificar" role="alert" data-va-errores hidden></div>

            <fieldset class="caminos-verificar">
                <legend class="campo-etiqueta">¿Qué encontraste?</legend>
                <div class="modo-toggle tres">
                    <label class="modo-opt"><input type="radio" name="va_camino" value="aceptar" checked data-va-camino><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Es correcto</span></label>
                    <label class="modo-opt"><input type="radio" name="va_camino" value="unir" data-va-camino><i class="bi bi-union" aria-hidden="true"></i><span>Ya existía</span></label>
                    <label class="modo-opt"><input type="radio" name="va_camino" value="rechazar" data-va-camino><i class="bi bi-x-octagon" aria-hidden="true"></i><span>Rechazar</span></label>
                </div>
            </fieldset>

            {{-- Es correcto: aceptar y, si hace falta, corregir los datos --}}
            <form method="POST" autocomplete="off" data-va-form="aceptar">
                @csrf
                @method('PUT')
                <p class="campo-ayuda mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Revisa y corrige los datos que capturó la caseta. Al aceptar queda como {{ $unVa }} {{ $defVa['singular'] }} normal del padrón.</p>
                @if ($padronVa === 'vehiculos')
                    <div class="row">
                        <div class="col-md-5">
                            <label class="campo-etiqueta" for="va_placas">Placas</label>
                            <input type="text" id="va_placas" name="placas" class="campo campo-placas" maxlength="25" autocapitalize="characters" required>
                        </div>
                        <div class="col-md-7">
                            <label class="campo-etiqueta" for="va_propiedad">Categoría</label>
                            <select id="va_propiedad" name="propiedad" class="campo" required>
                                @foreach (\App\Services\Vehiculos\AdministradorVehiculos::OPCIONES_PROPIEDAD as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="va_tipo">Tipo</label>
                            <select id="va_tipo" name="tipo" class="campo" required>
                                @foreach (\App\Models\Vehiculo::TIPOS as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="va_marca">Marca</label>
                            <input type="text" id="va_marca" name="marca" class="campo text-uppercase" maxlength="50" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="va_modelo">Modelo</label>
                            <input type="text" id="va_modelo" name="modelo" class="campo text-uppercase" maxlength="60">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="va_color">Color</label>
                            <input type="text" id="va_color" name="color" class="campo text-uppercase" maxlength="30" required>
                        </div>
                    </div>
                @elseif ($padronVa === 'proveedores')
                    <label class="campo-etiqueta" for="va_nombre">Razón social o nombre comercial</label>
                    <input type="text" id="va_nombre" name="nombre" class="campo" maxlength="150" required>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="va_categoria">Categoría</label>
                            <select id="va_categoria" name="categoria" class="campo" required>
                                @foreach (\App\Models\Proveedor::CATEGORIAS as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="va_rfc">RFC <span class="text-lowercase fw-normal">(opcional)</span></label>
                            <input type="text" id="va_rfc" name="rfc" class="campo text-uppercase" maxlength="13" autocapitalize="characters">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="va_telefono">Teléfono</label>
                            <input type="tel" inputmode="tel" id="va_telefono" name="telefono" class="campo" maxlength="20">
                        </div>
                    </div>
                @else
                    <div class="row">
                        <div class="col-md-8">
                            <label class="campo-etiqueta" for="va_nombre_completo">Nombre completo</label>
                            <input type="text" id="va_nombre_completo" name="nombre_completo" class="campo" maxlength="150" required>
                        </div>
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="va_tipo_persona">Tipo</label>
                            <select id="va_tipo_persona" name="tipo" class="campo" required>
                                @foreach (\App\Models\Persona::TIPOS as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="va_tipo_id">Identificación</label>
                            <select id="va_tipo_id" name="tipo_identificacion" class="campo">
                                <option value="">-- Sin identificación --</option>
                                @foreach (\App\Models\Persona::IDENTIFICACIONES as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="va_folio">Folio / Número</label>
                            <input type="text" id="va_folio" name="folio_identificacion" class="campo text-uppercase" maxlength="40" autocapitalize="characters">
                        </div>
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="va_tel">Teléfono</label>
                            <input type="tel" inputmode="tel" id="va_tel" name="telefono" class="campo" maxlength="20">
                        </div>
                    </div>
                @endif
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-verificar-aceptar" data-texto-original="Aceptar y verificar"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Aceptar y verificar</button>
                </div>
            </form>

            {{-- Ya existía: unir con el registro correcto --}}
            <form method="POST" autocomplete="off" data-va-form="unir" hidden>
                @csrf
                @method('PUT')
                <p class="fw-semibold mb-2">¿Es alguno de estos?</p>
                <div class="lista-unir" data-va-parecidos aria-live="polite"></div>
                <label class="campo-etiqueta mt-2" for="va_buscar">¿No está en la lista? Búscalo en el padrón</label>
                <input type="search" id="va_buscar" class="campo" maxlength="100" placeholder="Escribe al menos 2 letras..." data-va-buscar>
                <div class="lista-unir" data-va-resultados aria-live="polite"></div>
                <p class="campo-ayuda mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Todo lo que la caseta registró con el alta (accesos, movimientos, entregas…) pasa al registro elegido, y el alta queda como «Unida».</p>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-verificar-unir" data-texto-original="Unir con el elegido"><i class="bi bi-union me-1" aria-hidden="true"></i>Unir con el elegido</button>
                </div>
            </form>

            {{-- Rechazar con motivo --}}
            <form method="POST" autocomplete="off" data-va-form="rechazar" hidden>
                @csrf
                @method('PUT')
                <label class="campo-etiqueta" for="va_motivo">Motivo del rechazo</label>
                <textarea id="va_motivo" name="motivo_rechazo" class="campo" rows="3" maxlength="255" required minlength="5"
                          placeholder="Ej: Las placas no existen; datos inventados; capturado por error..."></textarea>
                <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Lo ya registrado con el alta no se borra (queda como historia), pero la caseta ya no la podrá usar.</p>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-verificar-rechazar" data-texto-original="Rechazar alta"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Rechazar alta</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
