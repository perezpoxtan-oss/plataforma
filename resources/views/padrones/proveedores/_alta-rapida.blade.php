{{--
    Alta rápida "Nueva Empresa Externa" (SEGCAT: proveedor_alta_modal.php, que
    Pases de Salida cargaba por AJAX). Para registrar un proveedor sin salir de
    otro módulo (Pases de salida, Accesos, Transporte...).

    Uso desde cualquier pantalla:
        @include('padrones.proveedores._alta-rapida')
        <button type="button" data-abrir-dialogo="dialogoAltaRapidaProveedor">Nueva Empresa Externa</button>

    Envía a POST /proveedores/rapido (JSON). Al guardar, plataforma.js lanza en
    document el evento "proveedor:registrado" con
    detail = {id, nombre, categoria, categoria_etiqueta, ya_existia}.
    Si el nombre ya existía en la empresa no se duplica: se usa el existente (y,
    con alcance de sede, se le agrega la sede de quien lo registra).
    Ver docs/tecnico/proveedores.md.

    Solo se dibuja si el usuario tiene "proveedores.crear" y hay empresa activa.
--}}
@php
    $tenantProveedor = app(\App\Support\Tenancy\Tenant::class);
@endphp
@if ($tenantProveedor->activo() && auth()->user()?->can('proveedores.crear'))
    <dialog id="dialogoAltaRapidaProveedor" class="dialogo tema-esmeralda" aria-labelledby="titulo-proveedor-rapido">
        <div class="dialogo-cabecera">
            <h2 id="titulo-proveedor-rapido"><i class="bi bi-building-add me-2 text-success" aria-hidden="true"></i>Nueva Empresa Externa</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('proveedores.rapido') }}" method="POST" autocomplete="off" data-alta-rapida-proveedor>
                @csrf
                {{-- Sin la clase "alert": el cierre genérico de diálogos borra los .alert del DOM --}}
                <div class="errores-rapido-proveedor" role="alert" data-errores-proveedor hidden></div>
                <input type="hidden" name="todas_las_sedes" value="1">
                @include('padrones.proveedores._campos', ['prefijo' => 'rapido_proveedor', 'reabrir' => false])
                <p class="small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Si ya está registrada, no se duplica: se usa la existente. Las sedes y demás datos se ajustan después en Empresas Externas.</p>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-esmeralda" data-texto-original="Guardar Empresa">Guardar Empresa</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
