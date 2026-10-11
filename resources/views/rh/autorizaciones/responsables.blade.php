@extends('layouts.app')

@section('titulo', 'Responsables por departamento')

@section('contenido')
<div class="pantalla-responsables">
    @include('administracion.partes.avisos')
    <a href="{{ route('autorizaciones.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Autorizaciones</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-people-fill text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Responsables por departamento</h1>
            <p>Quién autoriza las visitas de cada departamento y quién entrevista a sus candidatos (titular y suplentes).</p>
        </div>
    </div>

    @unless ($deEmpresa)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Los responsables son de toda la empresa: con tu alcance solo puedes consultarlos.</div>
    @endunless

    {{-- Visitas: ¿esperan autorización? --}}
    <section class="tarjeta p-4 mb-3">
        <h2 class="h6 fw-bold"><i class="bi bi-door-open me-2 text-primary" aria-hidden="true"></i>Visitas a un departamento</h2>
        <form action="{{ route('recepcion.ajustes.guardar') }}" method="POST" class="m-0">
            @csrf
            @method('PUT')
            <input type="hidden" name="visitas_requieren_autorizacion" value="0">
            <label class="casilla-privacidad">
                <input type="checkbox" name="visitas_requieren_autorizacion" value="1" @checked($requiere) @disabled(! $deEmpresa)>
                <span><strong>Las visitas esperan la autorización del responsable</strong><br>
                    <span class="small text-muted">Cuando la caseta registre una visita a un departamento (o a un colaborador de ese departamento) que tenga responsable, la persona queda «Esperando autorización» hasta que él responda. Si el departamento no tiene responsable, entra como siempre.</span></span>
            </label>
            @if ($deEmpresa)
                <button type="submit" class="btn-azul btn-accion-rh mt-2">Guardar</button>
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
