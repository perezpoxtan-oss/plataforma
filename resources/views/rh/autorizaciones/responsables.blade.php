@extends('layouts.app')

@section('titulo', 'Responsables por departamento')

@section('contenido')
<div class="pantalla-responsables">
    @include('administracion.partes.avisos')
    @php
        $desde = request('desde');
        [$volverUrl, $volverTexto] = match (true) {
            $desde === 'departamentos' && auth()->user()->can('departamentos.ver') => [route('departamentos.index'), 'Departamentos'],
            $desde === 'recepcion' && auth()->user()->can('recepcion_rh.ver') => [route('recepcion.ajustes'), 'Ajustes de Recepción'],
            default => [route('autorizaciones.index'), 'Autorizaciones'],
        };
        $ajustesRecepcion = app(\App\Services\Recepcion\AjustesRecepcion::class);
        $maximoMinutos = \App\Services\Recepcion\AjustesRecepcion::MAX_MINUTOS_ESCALAMIENTO;
    @endphp
    <a href="{{ $volverUrl }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ $volverTexto }}</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-people-fill text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Responsables por departamento</h1>
            <p>Quién autoriza las visitas de cada departamento y quién entrevista a sus candidatos (titular y suplentes), y qué espera en la caseta.</p>
        </div>
    </div>

    @unless ($deEmpresa)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Los responsables y las esperas son de toda la empresa: con tu alcance solo puedes consultarlos.</div>
    @endunless

    {{-- Esperas (ADR-0010): autorización de visitas, «Que pase» de RR. HH., recordatorio y escalamiento, en un solo lugar --}}
    <section class="tarjeta p-4 mb-3" id="esperas" aria-labelledby="t-esperas">
        <h2 id="t-esperas" class="h6 fw-bold"><i class="bi bi-hourglass-split me-2 text-primary" aria-hidden="true"></i>Qué espera en la caseta</h2>
        <form action="{{ route('recepcion.ajustes.guardar') }}" method="POST" class="m-0">
            @csrf
            @method('PUT')
            {{-- Una casilla por tipo de persona: visita, proveedor y contratista --}}
            <p class="mb-1"><strong>Esperan la autorización del responsable del departamento</strong></p>
            <div class="d-flex flex-wrap column-gap-4">
                @foreach (\App\Services\Recepcion\AjustesRecepcion::ESPERAN_DEPARTAMENTO as $tipoEspera => [$claveEspera, $textoEspera])
                    <input type="hidden" name="{{ $claveEspera }}" value="0">
                    <label class="casilla-concluido mb-1">
                        <input type="checkbox" name="{{ $claveEspera }}" value="1" @checked(old($claveEspera, $ajustesRecepcion->esperaAlDepartamento($empresa, $tipoEspera))) @disabled(! $deEmpresa)> {{ $textoEspera }}
                    </label>
                @endforeach
            </div>
            <p class="small text-muted mb-2">La caseta elige a qué departamento viene la persona. Si ese departamento tiene responsable en la sede, la persona queda «Esperando autorización» hasta que él responda (una visita a un colaborador espera al responsable de su departamento). Si no tiene responsable, la visita entra como siempre y el proveedor o contratista lo pasa quien supervisa la caseta con <strong>Autorizar por excepción</strong>. Proveedor o contratista sin marcar: se confirma con el host, como antes.</p>
            <input type="hidden" name="rh_autoriza_paso" value="0">
            <label class="casilla-privacidad mt-2">
                <input type="checkbox" name="rh_autoriza_paso" value="1" @checked(old('rh_autoriza_paso', $ajustesRecepcion->rhAutorizaPaso($empresa))) @disabled(! $deEmpresa)>
                <span><strong>La caseta espera a que RR. HH. diga «Que pase»</strong><br>
                    <span class="small text-muted">Quien viene a Recursos Humanos queda «Esperando a RR. HH.» en caseta hasta que alguien de RR. HH. responda Que pase, Que espere o No puede pasar. Apagado: pasa directo y RR. HH. solo recibe el aviso.</span></span>
            </label>
            <p class="small text-muted mt-3 mb-1"><strong>Cuando la respuesta tarda.</strong> A los primeros minutos se vuelve a avisar a quien debe responder (campana y correo); después se avisa a los suplentes (o a quien los cubre) y al Jefe de seguridad de la sede; para Recursos Humanos, al resto del equipo y al Jefe de seguridad. La caseta ve a quién espera, cuántos minutos lleva y a quién se escaló.</p>
            <div class="rejilla-cv">
                <div>
                    <label class="campo-etiqueta" for="recordar_minutos">Recordar a los (minutos)</label>
                    <input type="number" id="recordar_minutos" name="recordar_minutos" class="campo" min="1" max="{{ $maximoMinutos }}"
                           value="{{ old('recordar_minutos', $ajustesRecepcion->recordarMinutos($empresa)) }}" @disabled(! $deEmpresa)>
                    <p class="campo-ayuda">La espera se pinta en ámbar.</p>
                    @error('recordar_minutos')<p class="texto-error-rh">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="campo-etiqueta" for="escalar_minutos">Avisar al suplente y al jefe a los (minutos)</label>
                    <input type="number" id="escalar_minutos" name="escalar_minutos" class="campo" min="2" max="{{ $maximoMinutos }}"
                           value="{{ old('escalar_minutos', $ajustesRecepcion->escalarMinutos($empresa)) }}" @disabled(! $deEmpresa)>
                    <p class="campo-ayuda">La espera se pinta en rojo.</p>
                    @error('escalar_minutos')<p class="texto-error-rh">{{ $message }}</p>@enderror
                </div>
            </div>
            @if ($deEmpresa)
                <button type="submit" class="btn-azul btn-accion-rh mt-2">Guardar esperas</button>
            @endif
        </form>
    </section>

    @if ($usuarios->isEmpty())
        <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Ningún usuario tiene el permiso «Responder» de Autorizaciones. Dalo en la Matriz de permisos (por ejemplo, al rol de los jefes de departamento).</div>
    @endif

    <div class="fichas-grid">
        @forelse ($departamentos as $d)
            @php
                $lista = $responsables[$d->id] ?? collect();
                $reabrir = old('_dialogo') === 'depto-'.$d->id;
                $filas = $reabrir ? array_values((array) old('responsables', [])) : $lista->map(fn ($r) => ['user_id' => $r->user_id, 'sede_id' => $r->sede_id, 'es_suplente' => $r->es_suplente])->all();
                $filas = array_pad($filas, max(count($filas) + 1, 2), ['user_id' => '', 'sede_id' => '', 'es_suplente' => true]);
                $sedesDepto = $d->todas_las_sedes ? $sedes : $d->sedes;
            @endphp
            <div class="ficha-card" id="depto-{{ $d->id }}">
                <div>
                    <h2 class="ficha-title">{{ $d->nombre }}</h2>
                    @forelse ($lista as $r)
                        <p class="renglon-cv mb-1"><i class="bi {{ $r->es_suplente ? 'bi-person' : 'bi-person-fill-check' }} me-1" aria-hidden="true"></i><strong>{{ $r->usuario?->name }}</strong>
                            <span class="texto-traza">{{ $r->es_suplente ? 'Suplente' : 'Titular' }} · {{ $r->sede?->nombre ?? 'Todas sus sedes' }}</span></p>
                    @empty
                        <p class="small text-muted">Sin responsable: sus visitas entran sin esperar y, al canalizar un candidato, Recursos Humanos elige quién lo entrevista.</p>
                    @endforelse
                </div>
                @if ($deEmpresa)
                    <div class="ficha-footer">
                        <span></span>
                        <button type="button" class="btn-secundario-rh" data-abrir-dialogo="dialogoDepto{{ $d->id }}"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Elegir responsables</button>
                    </div>
                    <dialog id="dialogoDepto{{ $d->id }}" class="dialogo" aria-labelledby="titulo-depto-{{ $d->id }}" @if ($reabrir) data-abrir-al-cargar @endif>
                        <div class="dialogo-cabecera">
                            <h2 id="titulo-depto-{{ $d->id }}"><i class="bi bi-people me-2 text-warning" aria-hidden="true"></i>{{ $d->nombre }}</h2>
                            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </div>
                        <div class="dialogo-cuerpo">
                            <form action="{{ route('autorizaciones.responsables.guardar', $d->id) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="_dialogo" value="depto-{{ $d->id }}">
                                @if ($reabrir && $errors->any())
                                    <div class="alert alert-danger small py-2 px-3" role="alert"><ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul></div>
                                @endif
                                <p class="small text-muted">El primero es el titular; marca «Suplente» para quien responde cuando el titular no está. Deja vacío un renglón para quitarlo.</p>
                                @foreach ($filas as $i => $f)
                                    <div class="fila-cv">
                                        <div class="rejilla-cv">
                                            <label class="campo-etiqueta">Usuario
                                                <select name="responsables[{{ $i }}][user_id]" class="campo">
                                                    <option value="">-- Nadie --</option>
                                                    @foreach ($usuarios as $u)
                                                        <option value="{{ $u->id }}" @selected((string) ($f['user_id'] ?? '') === (string) $u->id)>{{ $u->name }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="campo-etiqueta">Sede
                                                <select name="responsables[{{ $i }}][sede_id]" class="campo">
                                                    <option value="">Todas sus sedes</option>
                                                    @foreach ($sedesDepto as $s)
                                                        <option value="{{ $s->id }}" @selected((string) ($f['sede_id'] ?? '') === (string) $s->id)>{{ $s->nombre }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </div>
                                        <input type="hidden" name="responsables[{{ $i }}][es_suplente]" value="0">
                                        <label class="casilla-concluido"><input type="checkbox" name="responsables[{{ $i }}][es_suplente]" value="1" @checked(filter_var($f['es_suplente'] ?? false, FILTER_VALIDATE_BOOL))> Suplente</label>
                                    </div>
                                @endforeach
                                <div class="dialogo-acciones">
                                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                                    <button type="submit" class="btn-azul">Guardar responsables</button>
                                </div>
                            </form>
                        </div>
                    </dialog>
                @endif
            </div>
        @empty
            <div class="tarjeta estado-vacio"><p class="text-muted small m-0">No hay departamentos activos. Créalos en Recursos Humanos → Departamentos.</p></div>
        @endforelse
    </div>
</div>
@endsection
