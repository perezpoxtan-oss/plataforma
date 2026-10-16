{{--
    Ajustes generales de Reservas de áreas (un solo formulario): se muestra en
    Reservas → Configuración y en Configuración → Reservas de áreas.
    Parámetros: $empresa, $desde ('reservas' | 'configuracion'), $editable (bool).
--}}
@php
    $ajustesRes = app(\App\Services\Reservas\AjustesReservas::class)->de($empresa);
    $usuariosRes = \App\Models\User::where('empresa_id', $empresa->id)->where('activo', true)->where('es_superadmin', false)->orderBy('name')->get(['id', 'name']);
    $defecto = \App\Services\Reservas\AjustesReservas::DEFECTO;
    $deptosRes = \App\Models\Departamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
@endphp
<div class="tarjeta p-4" id="ajustes">
    <h3 class="h6 fw-bold"><i class="bi bi-calendar-check me-2 text-success" aria-hidden="true"></i>Reservas de áreas</h3>
    <form method="POST" action="{{ route('reservas.ajustes') }}" autocomplete="off">
        @csrf
        @method('PUT')
        <input type="hidden" name="desde" value="{{ $desde }}">
        <fieldset @disabled(! $editable)>
            <div class="res-rejilla">
                <div>
                    <label class="campo-etiqueta" for="res_responsable">Responsable general de reservas</label>
                    <select id="res_responsable" name="responsable_id" class="campo">
                        <option value="">Nadie (aprueba quien tenga «Aprobar» reservas)</option>
                        @foreach ($usuariosRes as $us)<option value="{{ $us->id }}" @selected($ajustesRes['responsable_id'] === $us->id)>{{ $us->name }}</option>@endforeach
                    </select>
                    <p class="small text-muted mb-0">Aprueba las áreas que no tienen responsable propio (regularmente el asistente de gerencia).</p>
                </div>
                <div>
                    <label class="campo-etiqueta" for="res_suplente">Suplente</label>
                    <select id="res_suplente" name="suplente_id" class="campo">
                        <option value="">Sin suplente</option>
                        @foreach ($usuariosRes as $us)<option value="{{ $us->id }}" @selected($ajustesRes['suplente_id'] === $us->id)>{{ $us->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="campo-etiqueta" for="res_recordar">Recordar a las (horas)</label>
                    <input type="number" id="res_recordar" name="recordar_horas" class="campo" min="1" max="720" value="{{ $ajustesRes['recordar_horas'] }}">
                    <p class="small text-muted mb-0">La aprobación se pone ámbar y se le recuerda al responsable y a su suplente.</p>
                </div>
                <div>
                    <label class="campo-etiqueta" for="res_escalar">Avisar al responsable general a las (horas)</label>
                    <input type="number" id="res_escalar" name="escalar_horas" class="campo" min="2" max="720" value="{{ $ajustesRes['escalar_horas'] }}">
                    <p class="small text-muted mb-0">Se pone roja y se avisa al responsable general y a su suplente.</p>
                </div>
                <div>
                    <label class="campo-etiqueta" for="res_depto_montaje">Departamento que hace el montaje</label>
                    <select id="res_depto_montaje" name="departamento_montaje_id" class="campo">
                        <option value="">Ninguno (el montaje se elige con los datos del evento)</option>
                        @foreach ($deptosRes as $d)<option value="{{ $d->id }}" @selected($ajustesRes['departamento_montaje_id'] === $d->id)>{{ $d->nombre }}</option>@endforeach
                    </select>
                    <p class="small text-muted mb-0">Opcional (en un hotel, Steward). El tipo de montaje aparece en su tarjeta de servicios y recibe la orden de servicio del montaje.</p>
                </div>
                <div>
                    <label class="campo-etiqueta" for="res_iva">IVA de las reservas para clientes (%)</label>
                    <input type="number" id="res_iva" name="iva_porcentaje" class="campo" min="0" max="30" step="0.01" value="{{ $ajustesRes['iva_porcentaje'] }}">
                    <label class="d-flex gap-2 align-items-center mt-2 small"><input type="checkbox" name="iva_aplica" value="1" @checked($ajustesRes['iva_aplica'])> Cobrar IVA en las cotizaciones</label>
                </div>
            </div>
            <p class="small text-muted mt-2 mb-2"><span class="valor-original">Valor original: sin responsable general, recordar a las {{ $defecto['recordar_horas'] }} h, avisar a las {{ $defecto['escalar_horas'] }} h, IVA {{ (int) $defecto['iva_porcentaje'] }} % cobrado, sin departamento de montaje. Moneda: la de la empresa ({{ $ajustesRes['moneda'] }}).</span></p>
            @if ($editable)
                <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Guardar</button>
            @else
                <p class="small text-muted m-0">Solo quien configura reservas en toda la empresa cambia estos ajustes.</p>
            @endif
        </fieldset>
    </form>
</div>
