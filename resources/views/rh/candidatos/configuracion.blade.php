@extends('layouts.app')

@section('titulo', 'Documentos y plazos de candidatos')

@section('contenido')
@use('App\Services\Candidatos\PlazosCandidatos')
{{-- Candidatos, fase 3: lista de documentos para contratar y plazos (recordatorios y caducidad) por empresa --}}
<div class="pantalla-ajustes-rh">
    @include('administracion.partes.avisos')
    <a href="{{ route('candidatos.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Candidatos</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-folder-check text-success" aria-hidden="true"></i></div>
        <div>
            <h1>Documentos y plazos</h1>
            <p>Qué documentos pide la empresa para contratar y cuánto puede esperar cada paso antes de recordarlo.</p>
        </div>
    </div>
    @unless ($puedeGuardar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar estos ajustes (hace falta «configurar» candidatos con alcance de toda la empresa).</div>
    @endunless

    @php
        $filasForm = old('documentos');
        if (! is_array($filasForm)) {
            $filasForm = $lista->map(fn ($d) => ['clave' => $d->clave, 'nombre' => $d->nombre, 'obligatorio' => $d->obligatorio ? '1' : '0'])->values()->all();
        }
        $filasForm = array_values(array_slice($filasForm, 0, $maxDocumentos));
        while (count($filasForm) < $maxDocumentos) {
            $filasForm[] = ['clave' => '', 'nombre' => '', 'obligatorio' => '1'];
        }
    @endphp
    <section class="tarjeta p-4" id="documentos" aria-labelledby="t-documentos">
        <h2 id="t-documentos" class="h5 fw-bold"><i class="bi bi-paperclip me-2 text-success" aria-hidden="true"></i>Documentos para contratar</h2>
        <p class="small text-muted">Cada ficha muestra «Documentos: 5 de 8 obligatorios» con lo que falta, y <strong>Contratar</strong> avisa si falta alguno obligatorio o el contrato firmado. Deja vacío el renglón que no uses; el Currículum (CV), «Otro documento» y el contrato siempre existen. Si quitas un documento, los ya subidos conservan su nombre.</p>
        @if (! $configurada)
            <p class="small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Esta es la lista estándar de la plataforma; al guardar queda como la lista de tu empresa.</p>
        @endif
        <form action="{{ route('candidatos.configuracion.documentos') }}" method="POST">
            @csrf
            @method('PUT')
            @error('documentos')<p class="texto-error-rh">{{ $message }}</p>@enderror
            <ol class="lista-documentos-config">
                @foreach ($filasForm as $i => $f)
                    <li>
                        <span aria-hidden="true">{{ $i + 1 }}.</span>
                        <input type="hidden" name="documentos[{{ $i }}][clave]" value="{{ $f['clave'] ?? '' }}">
                        <input type="text" name="documentos[{{ $i }}][nombre]" class="campo" maxlength="80" value="{{ $f['nombre'] ?? '' }}" aria-label="Documento {{ $i + 1 }}" placeholder="{{ $i === 0 ? 'Ej. Identificación oficial (INE)' : '' }}" @disabled(! $puedeGuardar)>
                        <label class="casilla-candidato">
                            <input type="hidden" name="documentos[{{ $i }}][obligatorio]" value="0" @disabled(! $puedeGuardar)>
                            <input type="checkbox" name="documentos[{{ $i }}][obligatorio]" value="1" @checked(filter_var($f['obligatorio'] ?? false, FILTER_VALIDATE_BOOL)) @disabled(! $puedeGuardar)>
                            <span>Obligatorio</span>
                        </label>
                    </li>
                @endforeach
            </ol>
            @if ($puedeGuardar)
                <button type="submit" class="btn-verde btn-accion-rh mt-3">Guardar lista de documentos</button>
            @endif
        </form>
    </section>

    <section class="tarjeta p-4 mt-3" id="plazos" aria-labelledby="t-plazos">
        <h2 id="t-plazos" class="h5 fw-bold"><i class="bi bi-hourglass-split me-2 text-warning" aria-hidden="true"></i>Plazos de candidatos</h2>
        <p class="small text-muted">Cuando algo pasa de su plazo, aparece en ámbar en <strong>Mis pendientes</strong> (rojo si pasa del doble) y se manda <strong>un recordatorio al día</strong> por campana y correo a quien debe atenderlo, mientras siga pendiente: Recursos Humanos de la sede o quien entrevista. La cita vencida sin registro en caseta solo <em>propone</em> «Marcar como no se presentó»; nadie queda marcado solo.</p>
        <form action="{{ route('candidatos.configuracion.plazos') }}" method="POST">
            @csrf
            @method('PUT')
            @if ($errors->hasAny(array_keys($defecto)))
                <div class="alert alert-danger small py-2 px-3" role="alert"><ul class="mb-0 ps-3">@foreach (array_keys($defecto) as $k)@error($k)<li>{{ $message }}</li>@enderror @endforeach</ul></div>
            @endif
            <div class="rejilla-cv">
                @foreach (['solicitud_dias' => ['Solicitud sin revisar', 'días', 'Llenó su solicitud en el kiosco o por internet y nadie la ha revisado.'],
                    'evaluacion_dias' => ['Evaluación del departamento sin registrar', 'días hábiles', 'Pasó la entrevista y quien entrevistó no ha calificado (se cuentan de lunes a viernes).'],
                    'documentos_dias' => ['Elegido sin documentos o sin contrato', 'días', 'Está elegido y faltan documentos obligatorios o la firma del contrato.'],
                    'por_atender_dias' => ['Candidato con Recursos Humanos sin movimiento', 'días', 'Está por atender o por entrevistar con Recursos Humanos y nadie lo ha movido (atender, entrevistar, canalizar, considerar o rechazar).'],
                    'no_se_presento_horas' => ['Cita vencida sin llegada a caseta', 'horas', 'Después de este tiempo se propone a RR. HH. marcar «No se presentó».']] as $clave => [$titulo, $unidad, $ayuda])
                    <div>
                        <label class="campo-etiqueta" for="plazo_{{ $clave }}">{{ $titulo }} ({{ $unidad }})</label>
                        <input type="number" id="plazo_{{ $clave }}" name="{{ $clave }}" class="campo" min="{{ $limites[$clave][0] }}" max="{{ $limites[$clave][1] }}" value="{{ old($clave, $plazos[$clave]) }}" required @disabled(! $puedeGuardar)>
                        <p class="campo-ayuda">{{ $ayuda }} Por omisión: {{ $defecto[$clave] }}.</p>
                    </div>
                @endforeach
            </div>
            <h3 class="h6 fw-bold mt-3"><i class="bi bi-archive me-2 text-secondary" aria-hidden="true"></i>Caducidad y privacidad</h3>
            <p class="small text-muted">Las postulaciones en «Considerar» o «Rechazado» se archivan (salen de la lista; la ficha se conserva). Las fichas sin contratación se borran con sus documentos y su firma: {{ $diasAviso }} días antes se avisa a Recursos Humanos de la sede con el botón <strong>Conservar 6 meses más</strong>. Las fichas de contratados nunca se borran: su expediente ya es del colaborador.</p>
            <div class="rejilla-cv">
                <div>
                    <label class="campo-etiqueta" for="plazo_archivar_meses">Archivar Considerar y Rechazado a los (meses)</label>
                    <input type="number" id="plazo_archivar_meses" name="archivar_meses" class="campo" min="{{ $limites['archivar_meses'][0] }}" max="{{ $limites['archivar_meses'][1] }}" value="{{ old('archivar_meses', $plazos['archivar_meses']) }}" required @disabled(! $puedeGuardar)>
                    <p class="campo-ayuda">Por omisión: {{ $defecto['archivar_meses'] }}.</p>
                </div>
                <div>
                    <label class="campo-etiqueta" for="plazo_borrar_meses">Borrar fichas sin contratación a los (meses)</label>
                    <input type="number" id="plazo_borrar_meses" name="borrar_meses" class="campo" min="{{ $limites['borrar_meses'][0] }}" max="{{ $limites['borrar_meses'][1] }}" value="{{ old('borrar_meses', $plazos['borrar_meses']) }}" required @disabled(! $puedeGuardar)>
                    <p class="campo-ayuda">Contados desde el último movimiento de la ficha. Por omisión: {{ $defecto['borrar_meses'] }}.</p>
                </div>
            </div>
            @if ($puedeGuardar)
                <button type="submit" class="btn-verde btn-accion-rh mt-3">Guardar plazos</button>
            @endif
        </form>
    </section>
</div>
@endsection
