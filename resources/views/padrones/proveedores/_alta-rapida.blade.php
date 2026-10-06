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

    Se dibuja si hay empresa activa y el usuario tiene "proveedores.crear" o,
    desde una pantalla de Operación (Pases de salida, Accesos), su permiso
    operativo: entonces la empresa nace "pendiente de verificar" (ADR-0006).
    Antes de crear se ofrecen los nombres parecidos ("¿Es alguna de estas?").
--}}
@php
    $tenantProveedor = app(\App\Support\Tenancy\Tenant::class);
    $altasProveedor = app(\App\Services\Padrones\AltasPorVerificar::class);
    $origenProveedor = $altasProveedor->origenDeRuta(request()->route()?->getName());
@endphp
@if ($tenantProveedor->activo() && auth()->user() && $altasProveedor->puedeAltaRapida(auth()->user(), 'proveedores', $origenProveedor))
    <dialog id="dialogoAltaRapidaProveedor" class="dialogo tema-esmeralda" aria-labelledby="titulo-proveedor-rapido">
        <div class="dialogo-cabecera">
            <h2 id="titulo-proveedor-rapido"><i class="bi bi-building-add me-2 text-success" aria-hidden="true"></i>Nueva Empresa Externa</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            @include('padrones.altas-por-verificar._aviso-alta', ['padron' => 'proveedores'])
            <form action="{{ route('proveedores.rapido') }}" method="POST" autocomplete="off" data-alta-rapida-proveedor
                  data-alta-padron="proveedores" data-url-parecidos="{{ route('altas_por_verificar.parecidos') }}">
                @csrf
                <input type="hidden" name="origen" value="{{ $origenProveedor }}">
                <input type="hidden" name="confirmar_nuevo" value="0" data-alta-confirmar>
                <div class="caja-parecidos" role="alert" data-alta-parecidos hidden></div>
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
