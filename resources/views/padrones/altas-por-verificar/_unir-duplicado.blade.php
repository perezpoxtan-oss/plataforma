{{--
    Mejoras padrones (ADR-0015): «Unir con otra ficha» para dos registros YA
    verificados que son el mismo (Padrón de personas). Uno por pantalla; el
    botón [data-unir-duplicado] de cada ficha lo llena. Sugiere los parecidos
    y permite buscar; se envía por JSON y al terminar se recarga la lista.
    Nada se borra: el duplicado queda «Unido» como historia.
--}}
@php $buscarUd = ['vehiculos' => 'vehiculos.buscar', 'proveedores' => 'proveedores.buscar', 'personas' => 'personas.buscar'][$padron]; @endphp
<dialog id="dialogoUnirDuplicado" class="dialogo ancho dialogo-verificar-alta" aria-labelledby="titulo-unir-duplicado"
        data-dialogo-unir-duplicado data-url-buscar="{{ route($buscarUd) }}">
    <div class="dialogo-cabecera">
        <h2 id="titulo-unir-duplicado"><i class="bi bi-union me-2 text-primary" aria-hidden="true"></i>Unir con otra ficha</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <div class="resumen-alta">
            <span class="insignia-verificacion"><i class="bi bi-files" aria-hidden="true"></i> Ficha repetida</span>
            <strong class="titulo-alta" data-ud-titulo></strong>
        </div>
        <div class="errores-verificar" role="alert" data-ud-errores hidden></div>
        <form method="POST" autocomplete="off" data-ud-form>
            @csrf
            @method('PUT')
            <p class="fw-semibold mb-2">¿Con cuál ficha se une? (la que se queda)</p>
            <div class="lista-unir" data-ud-parecidos aria-live="polite"></div>
            <label class="campo-etiqueta mt-2" for="ud_buscar">¿No está en la lista? Búscala en el padrón</label>
            <input type="search" id="ud_buscar" class="campo" maxlength="100" placeholder="Escribe al menos 2 letras..." data-ud-buscar>
            <div class="lista-unir" data-ud-resultados aria-live="polite"></div>
            <p class="campo-ayuda mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Todo lo registrado con esta ficha (accesos, entregas, movimientos de transporte…) pasa a la elegida, que además se queda con la identificación o el teléfono que le falten. Esta ficha queda como «Unida»: no se borra, pero ya no se usa en la caseta. No se puede deshacer desde la pantalla.</p>
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-verificar-unir"><i class="bi bi-union me-1" aria-hidden="true"></i>Guardar unión</button>
            </div>
        </form>
    </div>
</dialog>
