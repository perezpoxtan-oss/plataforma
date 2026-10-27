{{--
    C-25 (B-05, DD-13): la caseta de noche, solo en una tarjeta PENDIENTE y para quien
    no puede autorizar (agente). «Pedir al supervisor» es un toque; si nadie responde a
    tiempo y la empresa lo permite, «Aprobar por excepción» con motivo y foto.
    $a: Acceso pendiente.
--}}
@php
    $noche = app(\App\Services\Accesos\NocheSinSupervisor::class);
    $empresaNoche = $empresaCaseta ?? \App\Models\Empresa::find($a->empresa_id);
    $permitido = $empresaNoche !== null && \App\Support\AjustesCaseta::valor($empresaNoche, 'agente_excepcion') === 'si';
    $agenteAprueba = $permitido && $noche->agentePuedeAprobar($a, $empresaNoche);
@endphp
<div class="ficha-acceso-acciones noche-caseta">
    @if ($noche->puedePedir($a))
        <form action="{{ route('accesos.pedir-supervisor', $a->id) }}" method="POST" class="m-0 w-100">
            @csrf
            <button type="submit" class="btn-accion-acceso temporal"><i class="bi bi-megaphone me-1" aria-hidden="true"></i>Pedir al supervisor</button>
        </form>
    @elseif ($a->supervisor_pedido_en)
        <p class="aviso-sin-permiso m-0"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Se pidió al supervisor @fecha($a->supervisor_pedido_en, 'H:i'). Si no responde, pasa al jefe de seguridad.
            @if ($permitido && ! $agenteAprueba && ($faltan = $noche->minutosParaAprobar($a, $empresaNoche)) !== null) Si nadie responde en {{ max(1, $faltan) }} min, podrás aprobarlo por excepción.@endif
        </p>
    @endif
    @if ($agenteAprueba)
        <details class="excepcion-acceso w-100 mt-2">
            <summary class="btn-accion-acceso excepcion"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>Aprobar por excepción</summary>
            <form action="{{ route('accesos.excepcion-agente', $a->id) }}" method="POST" enctype="multipart/form-data" class="m-0 mt-2"
                  data-confirmar="¿Dejar pasar a {{ $a->nombre }} sin autorización? Quedará con tu nombre, se avisará de inmediato al supervisor y al jefe de seguridad, y lo revisarán.">
                @csrf
                @method('PATCH')
                <label class="campo-etiqueta" for="motivo_agente_{{ $a->id }}">Motivo *</label>
                <input type="text" id="motivo_agente_{{ $a->id }}" name="motivo_agente" class="campo" maxlength="250" required placeholder="Ej: fuga de gas, la pipa no puede esperar">
                <label class="caja-foto-ingreso mt-2" for="foto_agente_{{ $a->id }}">
                    <img alt="" hidden data-vista-foto>
                    <i class="bi bi-person-bounding-box" aria-hidden="true"></i>
                    <span>Foto de la persona {{ $a->foto_persona ? '(ya hay una del registro; puedes tomar otra)' : '*' }}</span>
                </label>
                <input type="file" id="foto_agente_{{ $a->id }}" name="foto_agente" class="visually-hidden" accept="image/*" capture="user" data-foto-ingreso @required(! $a->foto_persona)>
                <button type="submit" class="btn-accion-acceso excepcion mt-2"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Aprobar por excepción</button>
            </form>
        </details>
    @endif
</div>
