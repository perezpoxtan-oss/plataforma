{{--
    Diálogos de la ficha: Enviar a revisión, Aprobar (con firma), Rechazar
    (comentario obligatorio) y Retirar (motivo). Los errores vuelven DENTRO de
    su diálogo (old('_dialogo')).
--}}
@php
    $dialogo = old('_dialogo');
    $erroresDentro = function () use ($errors) {
        if (! $errors->any()) {
            return '';
        }
        $html = '<div class="alert alert-danger small py-2" role="alert">';
        foreach ($errors->all() as $error) {
            $html .= '<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>'.e($error).'</div>';
        }

        return $html.'</div>';
    };
@endphp

@if ($puede['enviar'])
    {{-- ===== Enviar a revisión ===== --}}
    <dialog id="dialogoEnviarProcedimiento" class="dialogo dialogo-pase" aria-labelledby="titulo-enviar" @if ($dialogo === 'enviar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-enviar"><i class="bi bi-send me-2 text-primary" aria-hidden="true"></i>Enviar a revisión — versión {{ $trabajo->numero }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('procedimientos.enviar', $p->id) }}" method="POST">
                @csrf
                @if ($dialogo === 'enviar'){!! $erroresDentro() !!}@endif
                <p class="small">Al enviarlo ya no se puede editar. Lo aprueba (con su firma) alguien con el permiso «Aprobar» que no seas tú; si pide cambios, regresa a borrador con sus comentarios.</p>
                @if ($trabajo->numero > 1)
                    <label class="campo-etiqueta" for="enviar_resumen">Qué cambió respecto a la versión {{ $trabajo->numero - 1 }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea id="enviar_resumen" name="resumen_cambios" class="campo" rows="3" maxlength="1000" required
                              placeholder="Ej: Se agregó llamar al 911 antes de avisar a la gerencia.">{{ $dialogo === 'enviar' ? old('resumen_cambios') : $trabajo->resumen_cambios }}</textarea>
                @endif
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-azul"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar a revisión</button>
                </div>
            </form>
        </div>
    </dialog>
@endif

@if ($puede['aprobar'])
    {{-- ===== Aprobar ===== --}}
    <dialog id="dialogoAprobarProcedimiento" class="dialogo dialogo-pase" aria-labelledby="titulo-aprobar" @if ($dialogo === 'aprobar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-aprobar"><i class="bi bi-patch-check-fill me-2 text-primary" aria-hidden="true"></i>Aprobar y publicar — versión {{ $trabajo->numero }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('procedimientos.aprobar', $p->id) }}" method="POST" autocomplete="off" data-form-firma-propia>
                @csrf
                <input type="hidden" name="version_id" value="{{ $trabajo->id }}">
                @if ($dialogo === 'aprobar'){!! $erroresDentro() !!}@endif
                <p class="small">Con tu firma, la versión {{ $trabajo->numero }} de <strong>{{ $p->clave }}</strong> queda <strong>publicada</strong>{{ $vigente ? ' y reemplaza a la versión '.$vigente->numero : '' }}. Se avisa al personal al que aplica para que la lea y firme.</p>
                @if ($trabajo->resumen_cambios)<p class="small"><strong>Qué cambió:</strong> {{ $trabajo->resumen_cambios }}</p>@endif
                @include('seguridad.procedimientos._firma-propia', ['id' => 'aprobar', 'etiqueta' => 'Tu firma ('.auth()->user()->name.')'])
                <label class="campo-etiqueta" for="aprobar_comentario">Comentario <span class="text-lowercase fw-normal">(opcional)</span></label>
                <textarea id="aprobar_comentario" name="comentario" class="campo" rows="2" maxlength="1000">{{ $dialogo === 'aprobar' ? old('comentario') : '' }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-azul"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Aprobar y publicar</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- ===== Rechazar ===== --}}
    <dialog id="dialogoRechazarProcedimiento" class="dialogo dialogo-pase" aria-labelledby="titulo-rechazar" @if ($dialogo === 'rechazar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-rechazar"><i class="bi bi-x-octagon me-2 text-danger" aria-hidden="true"></i>Pedir cambios — versión {{ $trabajo->numero }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('procedimientos.rechazar', $p->id) }}" method="POST">
                @csrf
                <input type="hidden" name="version_id" value="{{ $trabajo->id }}">
                @if ($dialogo === 'rechazar'){!! $erroresDentro() !!}@endif
                <p class="small">La versión regresa a <strong>borrador</strong> con tu comentario para que la corrijan y la vuelvan a enviar.</p>
                <label class="campo-etiqueta" for="rechazo_comentario">Qué hay que corregir <span class="text-danger" aria-hidden="true">*</span></label>
                <textarea id="rechazo_comentario" name="comentario" class="campo" rows="3" maxlength="1000" required
                          placeholder="Ej: Falta indicar a quién llamar si no contesta el gerente.">{{ $dialogo === 'rechazar' ? old('comentario') : '' }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-confirmar-rechazo">Rechazar y regresar a borrador</button>
                </div>
            </form>
        </div>
    </dialog>
@endif

@if ($puede['retirar'])
    {{-- ===== Retirar ===== --}}
    <dialog id="dialogoRetirarProcedimiento" class="dialogo dialogo-pase" aria-labelledby="titulo-retirar" @if ($dialogo === 'retirar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-retirar"><i class="bi bi-slash-circle me-2 text-danger" aria-hidden="true"></i>Retirar {{ $p->clave }} (obsoleto)</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('procedimientos.retirar', $p->id) }}" method="POST">
                @csrf
                @if ($dialogo === 'retirar'){!! $erroresDentro() !!}@endif
                <p class="small">Ya no aparecerá para consulta ni se pedirá firmarlo. Su historial, versiones y acuses se conservan; se puede reactivar después.
                    @if ($trabajo) <strong>La versión {{ $trabajo->numero }} en trabajo se descartará.</strong>@endif</p>
                <label class="campo-etiqueta" for="retiro_motivo">Motivo <span class="text-danger" aria-hidden="true">*</span></label>
                <textarea id="retiro_motivo" name="motivo" class="campo" rows="3" maxlength="1000" required placeholder="Ej: Lo sustituye PRO-SEG-010.">{{ $dialogo === 'retirar' ? old('motivo') : '' }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-confirmar-rechazo">Retirar procedimiento</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
