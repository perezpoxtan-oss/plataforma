@extends('layouts.app')

@section('titulo', 'Solicitud '.$s->folio)

@section('contenido')
{{-- Atender una solicitud ARCO (docs/tecnico/privacidad.md): verificar, buscar, exportar, rectificar, eliminar y responder --}}
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <p class="mb-2"><a href="{{ route('arco.index') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Solicitudes ARCO</a></p>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-person-vcard text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $s->folio }} · {{ $s->etiquetaDerecho() }}</h1>
            <p>@include('administracion.arco._reloj', ['s' => $s, 'recordar' => $recordar])
                @if ($s->vence_en) <span class="ms-2">Responder a más tardar el <strong>{{ $s->vence_en->format('d/m/Y') }}</strong>.</span>@endif</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-quien">
                <h2 id="t-quien" class="h6 fw-bold mb-3"><i class="bi bi-person me-2 text-primary" aria-hidden="true"></i>Quién la pide</h2>
                <dl class="mi-cuenta-datos">
                    <div><dt>Nombre</dt><dd>{{ $s->nombre }}</dd></div>
                    <div><dt>Correo</dt><dd>{{ $s->correo }}</dd></div>
                    @if ($s->telefono)<div><dt>Teléfono</dt><dd>{{ $s->telefono }}</dd></div>@endif
                    <div><dt>Relación</dt><dd>{{ \App\Models\SolicitudArco::RELACIONES[$s->relacion] ?? 'No la indicó' }}</dd></div>
                    <div><dt>Recibida</dt><dd>@fecha($s->created_at, 'd/m/Y H:i')</dd></div>
                    <div><dt>Verificada</dt><dd>{{ $s->verificada_en ? app(\App\Support\HoraLocal::class)->formatear($s->verificada_en, 'd/m/Y H:i').' ('.($s->verificada_como === 'correo' ? 'confirmó su correo' : 'identidad confirmada en persona').')' : 'Aún no' }}</dd></div>
                    @if ($empresaNombre)<div><dt>Empresa</dt><dd>{{ $empresaNombre }}</dd></div>@endif
                </dl>
                <h3 class="h6 fw-bold mt-3">Lo que pide</h3>
                <p class="small" style="white-space:pre-line;">{{ $s->detalle }}</p>
                @if ($s->respuesta)
                    <h3 class="h6 fw-bold mt-3">Respuesta enviada</h3>
                    <p class="small" style="white-space:pre-line;">{{ $s->respuesta }}</p>
                    <p class="small text-muted">{{ $s->respondidaPor?->name }} · @fecha($s->respondida_en, 'd/m/Y H:i')</p>
                @endif
            </section>
        </div>

        <div class="col-lg-7">
            {{-- Correo sin confirmar: verificar en persona --}}
            @if ($s->estado === \App\Models\SolicitudArco::POR_VERIFICAR)
                <section class="tarjeta p-4 mb-4" aria-labelledby="t-verificar">
                    <h2 id="t-verificar" class="h6 fw-bold"><i class="bi bi-envelope-exclamation me-2 text-warning" aria-hidden="true"></i>La persona aún no confirma su correo</h2>
                    <p class="small text-muted">Le enviamos un enlace para confirmar que el correo es suyo. Si no le llegó (o la plataforma no tiene correo configurado), confirma su identidad en persona (identificación oficial) y apruébala aquí: el plazo legal empieza hoy.</p>
                    <form action="{{ route('arco.verificar', $s->id) }}" method="POST" data-confirmar="¿Confirmaste la identidad de {{ $s->nombre }} con una identificación oficial?">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Aprobar</button>
                    </form>
                </section>
            @endif

            {{-- Sin empresa: el Super Administrador la canaliza --}}
            @if ($esSuperadmin && $s->empresa_id === null && $s->abierta())
                <section class="tarjeta p-4 mb-4" aria-labelledby="t-canalizar">
                    <h2 id="t-canalizar" class="h6 fw-bold"><i class="bi bi-signpost-split me-2 text-primary" aria-hidden="true"></i>Llegó desde la página de la plataforma</h2>
                    <p class="small text-muted">Elige la empresa que tiene los datos de la persona; pasará a su bandeja con el mismo plazo.</p>
                    <form action="{{ route('arco.canalizar', $s->id) }}" method="POST" class="d-flex flex-wrap gap-2 align-items-end">
                        @csrf
                        @method('PATCH')
                        <div><label class="campo-etiqueta" for="canalizar_empresa">Empresa</label>
                            <select id="canalizar_empresa" name="empresa_id" class="campo m-0" required>
                                <option value="">Elige…</option>
                                @foreach ($empresas as $e)<option value="{{ $e->id }}">{{ $e->nombre_comercial }}</option>@endforeach
                            </select></div>
                        <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Canalizar</button>
                    </form>
                </section>
            @endif

            @if ($coincidencias !== null && $s->empresa_id !== null)
                {{-- Buscar lo que tenemos de la persona --}}
                <section class="tarjeta p-4 mb-4" aria-labelledby="t-buscar">
                    <h2 id="t-buscar" class="h6 fw-bold"><i class="bi bi-search me-2 text-primary" aria-hidden="true"></i>1. Busca lo que la plataforma tiene de la persona</h2>
                    <form action="{{ route('arco.show', $s->id) }}" method="GET" class="row g-2 align-items-end mb-3">
                        <div class="col-sm-6"><label class="campo-etiqueta" for="b_nombre">Nombre</label><input id="b_nombre" name="nombre" class="campo m-0" value="{{ $criterios['nombre'] }}" maxlength="150"></div>
                        <div class="col-sm-6"><label class="campo-etiqueta" for="b_correo">Correo</label><input id="b_correo" name="correo" class="campo m-0" value="{{ $criterios['correo'] }}" maxlength="150"></div>
                        <div class="col-sm-5"><label class="campo-etiqueta" for="b_telefono">Teléfono</label><input id="b_telefono" name="telefono" class="campo m-0" value="{{ $criterios['telefono'] }}" maxlength="20"></div>
                        <div class="col-sm-5"><label class="campo-etiqueta" for="b_folio">Folio de su identificación</label><input id="b_folio" name="folio" class="campo m-0" value="{{ $criterios['folio'] }}" maxlength="50"></div>
                        <div class="col-sm-2"><button type="submit" class="btn btn-outline-primary w-100" style="min-height:44px;border-radius:10px;">Buscar</button></div>
                    </form>

                    @php $hay = collect($coincidencias)->flatten(1)->isNotEmpty(); @endphp
                    @if (! $hay)
                        <p class="small text-muted mb-0">No encontramos registros con esos datos. Prueba con el nombre como lo escribió la caseta o con el folio de su identificación. Si de verdad no hay datos, respóndele que no tenemos información suya.</p>
                    @else
                        <form method="POST" id="form-seleccion" class="coincidencias-arco">
                            @csrf
                            @foreach ($tipos as $tipo => $nombreTipo)
                                @continue (($coincidencias[$tipo] ?? []) === [])
                                <fieldset>
                                    <legend>{{ $nombreTipo }} ({{ count($coincidencias[$tipo]) }})</legend>
                                    @foreach ($coincidencias[$tipo] as $c)
                                        <label class="fila-check mb-1">
                                            <input type="checkbox" name="seleccion[{{ $tipo }}][]" value="{{ $c['id'] }}">
                                            <span class="small"><strong>{{ $c['titulo'] }}</strong> · {{ $c['detalle'] }}
                                                @if ($c['url'])<a href="{{ $c['url'] }}" target="_blank" rel="noopener" class="ms-1">Abrir ficha (rectificar)</a>@endif</span>
                                        </label>
                                    @endforeach
                                </fieldset>
                            @endforeach
                            <p class="small text-muted">Marca lo que sí es de la persona. Para <strong>rectificar</strong>, abre su ficha y corrige ahí.</p>

                            <h2 class="h6 fw-bold mt-3"><i class="bi bi-file-earmark-zip me-2 text-primary" aria-hidden="true"></i>2. Entrega o elimina</h2>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button type="submit" formaction="{{ route('arco.exportar', $s->id) }}" class="btn btn-outline-primary" style="min-height:44px;border-radius:10px;"><i class="bi bi-download me-1" aria-hidden="true"></i>Exportar datos</button>
                                @if ($s->archivo)
                                    <a href="{{ route('arco.descargar', $s->id) }}" class="btn btn-outline-secondary" style="min-height:44px;border-radius:10px;"><i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i>Descargar el archivo</a>
                                @endif
                            </div>
                            <div class="alert alert-danger small mt-3 mb-0">
                                <label class="fila-check mb-2"><input type="checkbox" name="confirmar" value="1"><span>Entiendo que <strong>eliminar no se puede deshacer</strong>: los registros de la bitácora se conservan con «{{ \App\Services\Privacidad\SolicitudesArco::TEXTO_ELIMINADO }}», la ficha del padrón queda anónima y la del candidato se borra con sus documentos. Los colaboradores no se eliminan.</span></label>
                                <button type="submit" formaction="{{ route('arco.eliminar', $s->id) }}" class="btn btn-danger" style="min-height:44px;border-radius:10px;"><i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar definitivamente</button>
                            </div>
                        </form>
                    @endif
                </section>

                {{-- Responder --}}
                <section class="tarjeta p-4" aria-labelledby="t-responder">
                    <h2 id="t-responder" class="h6 fw-bold"><i class="bi bi-reply me-2 text-primary" aria-hidden="true"></i>3. Responde a la persona</h2>
                    <p class="small text-muted">Le llega por correo y la solicitud se cierra. Di qué hiciste (por ejemplo: «Te enviamos tus datos al correo que nos diste» o «Eliminamos tus fotos y datos de nuestras bitácoras»).</p>
                    <form action="{{ route('arco.responder', $s->id) }}" method="POST">
                        @csrf
                        <label class="campo-etiqueta" for="respuesta">Respuesta</label>
                        <textarea id="respuesta" name="respuesta" class="campo" rows="5" maxlength="5000" required>{{ old('respuesta') }}</textarea>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="resultado" value="atendida" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Atender</button>
                            <button type="submit" name="resultado" value="rechazada" class="btn btn-outline-danger" style="min-height:44px;border-radius:10px;"
                                    formnovalidate><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Rechazar</button>
                        </div>
                        <p class="small text-muted mt-2 mb-0">«Rechazar» solo si la solicitud no procede (por ejemplo, no se pudo confirmar la identidad); explica el motivo en la respuesta.</p>
                    </form>
                </section>
            @endif

            @if (! empty($s->acciones))
                <section class="tarjeta p-4 mt-4" aria-labelledby="t-historial">
                    <h2 id="t-historial" class="h6 fw-bold"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Lo que se ha hecho</h2>
                    <ul class="small mb-0">
                        @foreach ($s->acciones as $acc)
                            <li>@fecha(\Illuminate\Support\Carbon::parse($acc['en']), 'd/m/Y H:i') · {{ ['exportado' => 'Datos exportados', 'eliminado' => 'Datos eliminados', 'atendida' => 'Respondida', 'rechazada' => 'Rechazada'][$acc['accion']] ?? $acc['accion'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
