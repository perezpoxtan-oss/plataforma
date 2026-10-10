@extends('layouts.app')

@section('titulo', 'Candidato')

@section('contenido')
@use('App\Models\Candidato')
@use('App\Models\CandidatoDocumento')
@use('App\Models\Autorizacion')
@php
    $hora = app(\App\Support\HoraLocal::class);
    $dialogo = old('_dialogo');
    $ruta = ['registrado', 'revision', 'aprobado_rh', 'entrevista', 'seleccionado', 'contratado'];
    $posicion = array_search($c->etapa, $ruta, true);
    $ultima = $c->autorizaciones->first();
    $acceso = $c->acceso;
    $tiempos = array_filter([
        'Llegó a caseta' => $c->llegada_en,
        'Aviso a RR. HH.' => $c->avisado_rh_en,
        'RR. HH. lo atendió' => $c->revision_en,
        'Aprobado por RR. HH.' => $c->aprobado_rh_en,
        'Enviado al departamento' => $c->enviado_departamento_en,
        'Respuesta del departamento' => $c->respuesta_departamento_en,
        'Entrevista' => $c->entrevista_en,
        'Decisión' => $c->decision_en,
        'Contratado' => $c->contratado_en,
    ]);
    $siguientes = array_values(array_filter(Candidato::TRANSICIONES[$c->etapa] ?? [], fn ($e) => ! in_array($e, ['cartera', 'descartado'], true)));
@endphp
<div class="pantalla-candidato">
    @include('administracion.partes.avisos')

    <a href="{{ route('candidatos.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Candidatos</a>

    <section class="tarjeta cabeza-candidato">
        <div class="d-flex flex-column flex-md-row gap-3 align-items-md-start">
            @if ($acceso?->foto_persona)
                <a href="{{ route('accesos.foto-persona', $acceso->id) }}" class="foto-candidato" target="_blank" rel="noopener">
                    <img src="{{ route('accesos.foto-persona', $acceso->id) }}" alt="Foto de {{ $c->nombre_completo }} tomada en caseta">
                </a>
            @else
                <div class="foto-candidato vacia" aria-hidden="true"><i class="bi bi-person"></i></div>
            @endif
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <span class="pastilla-etapa etapa-{{ Candidato::COLORES[$c->etapa] ?? 'gris' }}">{{ $c->etiquetaEtapa() }}</span>
                    <span class="pastilla-origen"><i class="bi bi-signpost me-1" aria-hidden="true"></i>{{ Candidato::ORIGENES[$c->origen] ?? $c->origen }}</span>
                    @if ($c->autocaptura_pendiente)<span class="pastilla-revisar"><i class="bi bi-phone me-1" aria-hidden="true"></i>Por revisar</span>@endif
                </div>
                <h1 class="nombre-candidato">{{ $c->nombre_completo }}</h1>
                <div class="datos-candidato">
                    <span><i class="bi bi-briefcase" aria-hidden="true"></i> {{ $c->puestoVisible() ?? 'Puesto sin definir' }}</span>
                    @if ($c->vacantePublicada)
                        <span class="dato-vacante"><i class="bi bi-megaphone" aria-hidden="true"></i> Vacante: <a href="{{ route('candidatos.index', ['vacante' => $c->vacante_id]) }}">{{ $c->vacantePublicada->titulo }}</a>{{ $c->vacantePublicada->estado !== 'publicada' ? ' ('.\App\Models\Vacante::ESTADOS[$c->vacantePublicada->estado].')' : '' }}</span>
                    @endif
                    <span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $c->departamento?->nombre ?? 'Departamento sin definir' }}</span>
                    <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $c->sede?->nombre }}</span>
                    <span><i class="bi bi-clock" aria-hidden="true"></i> Llegó: @fecha($c->llegada_en ?? $c->created_at)</span>
                    @if ($c->colaborador)
                        <span class="dato-contratado"><i class="bi bi-person-check-fill" aria-hidden="true"></i> Colaborador núm. {{ $c->colaborador->num_empleado }}</span>
                    @endif
                </div>
                <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $c->registradoPor?->name ?? 'el candidato (kiosco)' }} · @fecha($c->created_at)</div>
                @if ($c->editadoPor && $c->updated_at?->ne($c->created_at))
                    <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $c->editadoPor->name }} · @fecha($c->updated_at)</div>
                @endif
            </div>
        </div>

        {{-- Avance --}}
        <ol class="avance-candidato" aria-label="Avance del candidato">
            @foreach ($ruta as $i => $paso)
                <li class="{{ $posicion !== false && $i < $posicion ? 'hecho' : ($c->etapa === $paso ? 'actual' : '') }}">
                    <span class="punto" aria-hidden="true">{{ $posicion !== false && $i < $posicion ? '✓' : $i + 1 }}</span>
                    <span>{{ Candidato::ETAPAS[$paso] }}</span>
                </li>
            @endforeach
        </ol>
        @if (in_array($c->etapa, ['cartera', 'descartado'], true))
            <p class="aviso-etapa-lateral etapa-{{ $c->etapa }}"><i class="bi {{ $c->etapa === 'cartera' ? 'bi-archive' : 'bi-x-octagon' }} me-1" aria-hidden="true"></i>
                {{ $c->etapa === 'cartera' ? 'En cartera: se guarda para una vacante futura.' : 'Descartado' }}@if ($c->motivo_descarte) — {{ $c->motivo_descarte }}@endif
                @if ($c->decisionPor) <span class="texto-traza">({{ $c->decisionPor->name }} · @fecha($c->decision_en))</span>@endif</p>
        @endif

        {{-- Acciones --}}
        @if ($puede['editar'] && $c->etapa !== 'contratado')
            <div class="acciones-candidato">
                @foreach ($siguientes as $etapa)
                    <form action="{{ route('candidatos.etapa', $c->id) }}" method="POST" class="m-0"
                          data-confirmar="{{ $etapa === 'aprobado_rh' ? '¿Aprobar y enviar al departamento '.($c->departamento?->nombre ?? '').'? Su responsable recibirá un resumen para decidir.' : '¿'.Candidato::BOTONES_ETAPA[$etapa].'?' }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="etapa" value="{{ $etapa }}">
                        <button type="submit" class="btn-etapa {{ $etapa === 'aprobado_rh' || $etapa === 'seleccionado' ? 'principal' : '' }}">{{ Candidato::BOTONES_ETAPA[$etapa] }}</button>
                    </form>
                @endforeach
                @if ($c->etapa === 'seleccionado' && $puede['contratar'])
                    <button type="button" class="btn-etapa contratar" data-abrir-dialogo="dialogoContratar"><i class="bi bi-person-check-fill me-1" aria-hidden="true"></i>Contratar</button>
                @endif
                @if ($c->puedePasarA('cartera'))
                    <button type="button" class="btn-etapa secundario" data-abrir-dialogo="dialogoEtapa" data-etapa-destino="cartera">Guardar en cartera</button>
                @endif
                @if ($c->puedePasarA('descartado'))
                    <button type="button" class="btn-etapa peligro" data-abrir-dialogo="dialogoEtapa" data-etapa-destino="descartado">Descartar</button>
                @endif
            </div>
            @if ($c->etapa === 'revision' && $c->departamento_id === null)
                <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Para «Aprobar y enviar al departamento», primero indica el departamento en <strong>Editar solicitud</strong>.</p>
            @endif
        @endif
    </section>

    @if ($c->autocaptura_pendiente && $puede['editar'])
        <div class="aviso-autocaptura">
            <i class="bi bi-phone-fill" aria-hidden="true"></i>
            <div class="flex-grow-1"><strong>El candidato llenó su CV en el kiosco</strong> (@fecha($c->autocaptura_en)). Revisa sus datos y documentos.</div>
            <form action="{{ route('candidatos.revisado', $c->id) }}" method="POST" class="m-0">
                @csrf
                <button type="submit" class="btn-azul btn-accion-rh">Ya lo revisé</button>
            </form>
        </div>
    @endif

    @if ($ultima)
        <div class="aviso-departamento estado-{{ $ultima->estado }}">
            <i class="bi {{ $ultima->pendiente() ? 'bi-hourglass-split' : ($ultima->estado === 'rechazada' ? 'bi-x-circle-fill' : 'bi-patch-check-fill') }}" aria-hidden="true"></i>
            <div>
                <strong>{{ $ultima->departamento?->nombre }}: {{ Autorizacion::ESTADOS[$ultima->estado] ?? $ultima->estado }}</strong>
                <span class="d-block small">
                    @if ($ultima->pendiente())
                        Se avisó @fecha($ultima->solicitada_en) · esperando desde hace {{ $ultima->minutosEspera() }} min.
                    @else
                        Respondió {{ $ultima->respondidaPor?->name ?? '—' }} · @fecha($ultima->respondida_en) ({{ $ultima->minutosEspera() }} min después del aviso){{ $ultima->comentario ? ' — «'.$ultima->comentario.'»' : '' }}
                    @endif
                </span>
            </div>
        </div>
    @endif

    <div class="rejilla-ficha-candidato">
        {{-- Solicitud de empleo (CV) --}}
        @php
            $siNoTexto = fn ($v) => $v === null ? '—' : ($v ? 'Sí' : 'No');
            $mesTexto = fn (?string $m) => $m ? substr($m, 5, 2).'/'.substr($m, 0, 4) : null;
        @endphp
        <section class="tarjeta p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 fw-bold m-0"><i class="bi bi-file-person me-2 text-success" aria-hidden="true"></i>Solicitud de empleo</h2>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('candidatos.solicitud', $c->id) }}" class="btn-secundario-rh" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir</a>
                    @if ($puede['editar'])
                        <button type="button" class="btn-secundario-rh" data-abrir-dialogo="dialogoCv"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Editar solicitud</button>
                    @endif
                </div>
            </div>
            <h3 class="subtitulo-cv mt-0">Datos personales</h3>
            <dl class="cv-datos">
                <dt>Teléfono</dt><dd>{{ $c->telefono ?? '—' }}</dd>
                <dt>Correo</dt><dd>{{ $c->correo ?? '—' }}</dd>
                <dt>Nacimiento</dt><dd>{{ $c->fecha_nacimiento?->format('d/m/Y') ?? '—' }}{{ $c->lugar_nacimiento ? ' · '.$c->lugar_nacimiento : '' }}</dd>
                <dt>Sexo</dt><dd>{{ Candidato::SEXOS[$c->sexo] ?? '—' }}</dd>
                <dt>Nacionalidad</dt><dd>{{ $c->nacionalidad ?? '—' }}</dd>
                <dt>Estado civil</dt><dd>{{ Candidato::ESTADOS_CIVILES[$c->estado_civil] ?? '—' }}{{ $c->dependientes !== null ? ' · '.$c->dependientes.' dependiente(s)' : '' }}</dd>
                <dt>Licencia</dt><dd>{{ $c->licencia_tipo ? Candidato::LICENCIAS[$c->licencia_tipo].($c->licencia_vigencia ? ' (vence '.$c->licencia_vigencia->format('d/m/Y').')' : '') : '—' }}</dd>
            </dl>
            <h3 class="subtitulo-cv"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Datos oficiales y domicilio <span class="pastilla-sensible">Solo RR. HH.</span></h3>
            <dl class="cv-datos">
                <dt>CURP</dt><dd class="dato-oficial">{{ $c->curp ?? '—' }}</dd>
                <dt>RFC</dt><dd class="dato-oficial">{{ $c->rfc ?? '—' }}</dd>
                <dt>NSS</dt><dd class="dato-oficial">{{ $c->nss ?? '—' }}</dd>
                <dt>Domicilio</dt><dd>{{ $c->domicilioCompleto() ?? ($c->ciudad ?? '—') }}{{ $c->tiempo_residencia ? ' · '.$c->tiempo_residencia.' ahí' : '' }}</dd>
                <dt>Tel. fijo</dt><dd>{{ $c->telefono_fijo ?? '—' }}</dd>
                <dt>Emergencia</dt><dd>{{ $c->emergencia_nombre ? $c->emergencia_nombre.($c->emergencia_parentesco ? ' ('.$c->emergencia_parentesco.')' : '').($c->emergencia_telefono ? ' · '.$c->emergencia_telefono : '') : '—' }}</dd>
            </dl>
            <h3 class="subtitulo-cv">Datos generales</h3>
            <dl class="cv-datos">
                <dt>Se enteró por</dt><dd>{{ Candidato::MEDIOS_VACANTE[$c->medio_vacante] ?? '—' }}</dd>
                <dt>Familiares aquí</dt><dd>{{ $siNoTexto($c->tiene_familiares) }}{{ $c->familiares_nombre ? ' · '.$c->familiares_nombre : '' }}</dd>
                <dt>Trabajó aquí</dt><dd>{{ $siNoTexto($c->trabajo_antes_aqui) }}</dd>
                <dt>Rolar turnos</dt><dd>{{ $siNoTexto($c->rolar_turnos) }}</dd>
                <dt>Viajar</dt><dd>{{ $siNoTexto($c->puede_viajar) }}</dd>
                <dt>Cambiar de residencia</dt><dd>{{ $siNoTexto($c->cambiar_residencia) }}</dd>
                <dt>Puede empezar</dt><dd>{{ Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? '—' }}{{ $c->fecha_inicio_posible ? ' · '.$c->fecha_inicio_posible->format('d/m/Y') : '' }}{{ $c->disponibilidad_notas ? ' · '.$c->disponibilidad_notas : '' }}</dd>
                <dt>Sueldo que espera</dt><dd>{{ $c->pretension !== null ? '$'.number_format((float) $c->pretension, 2).' al mes' : '—' }}</dd>
                <dt>Idiomas</dt><dd>{{ $c->idiomas ?? '—' }}</dd>
                <dt>Habilidades</dt><dd>{{ $c->habilidades ?? '—' }}</dd>
            </dl>
            <h3 class="subtitulo-cv">Escolaridad</h3>
            @forelse ((array) $c->escolaridad as $e)
                <p class="renglon-cv"><strong>{{ Candidato::ESCOLARIDAD[$e['nivel'] ?? ''] ?? '—' }}</strong>{{ ! empty($e['titulo']) ? ' · '.$e['titulo'] : '' }}{{ ! empty($e['institucion']) ? ' — '.$e['institucion'] : '' }}{{ ! empty($e['periodo']) ? ' · '.$e['periodo'] : '' }}
                    <span class="texto-traza">({{ ! empty($e['documento']) ? Candidato::DOCUMENTOS_ESTUDIO[$e['documento']] ?? $e['documento'] : (! empty($e['concluido']) ? 'terminado' : 'sin terminar') }})</span></p>
            @empty
                <p class="renglon-cv text-muted">Sin capturar.</p>
            @endforelse
            <h3 class="subtitulo-cv">Empleos anteriores</h3>
            @forelse ((array) $c->experiencia as $e)
                <p class="renglon-cv"><strong>{{ $e['empresa'] ?? '—' }}</strong>{{ ! empty($e['puesto']) ? ' · '.$e['puesto'] : '' }}
                    @if (! empty($e['ingreso'])) · {{ $mesTexto($e['ingreso']) }} a {{ $mesTexto($e['salida'] ?? null) ?? 'la fecha' }}@endif
                    {{ isset($e['anos']) && $e['anos'] !== null ? ' · '.$e['anos'].' año(s)' : '' }}
                    @if (! empty($e['sueldo_final']))<span class="d-block texto-traza">Sueldo final: ${{ number_format((float) $e['sueldo_final'], 2) }}</span>@endif
                    @if (! empty($e['jefe']))<span class="d-block texto-traza">Jefe: {{ $e['jefe'] }}{{ ! empty($e['jefe_telefono']) ? ' · '.$e['jefe_telefono'] : '' }}{{ ($e['pedir_referencias'] ?? null) === 'no' ? ' · No pedir referencias' : (($e['pedir_referencias'] ?? null) === 'si' ? ' · Sí se pueden pedir referencias' : '') }}</span>@endif
                    @if (! empty($e['motivo_salida']))<span class="d-block texto-traza">Salió: {{ $e['motivo_salida'] }}</span>@endif</p>
            @empty
                <p class="renglon-cv text-muted">Sin capturar.</p>
            @endforelse
            @foreach (['referencias' => 'Referencias personales', 'referencias_laborales' => 'Referencias laborales'] as $lista => $tituloLista)
                <h3 class="subtitulo-cv">{{ $tituloLista }}</h3>
                @forelse ((array) $c->{$lista} as $r)
                    <p class="renglon-cv"><strong>{{ $r['nombre'] ?? '—' }}</strong>{{ ! empty($r['relacion']) ? ' · '.$r['relacion'] : '' }}{{ ! empty($r['telefono']) ? ' · '.$r['telefono'] : '' }}{{ isset($r['anos_conocerlo']) && $r['anos_conocerlo'] !== null ? ' · '.$r['anos_conocerlo'].' año(s) de conocerlo' : '' }}</p>
                @empty
                    <p class="renglon-cv text-muted">Sin capturar.</p>
                @endforelse
            @endforeach
            @if ($c->notas_rh)
                <h3 class="subtitulo-cv">Notas de RR. HH.</h3>
                <p class="renglon-cv notas-rh">{{ $c->notas_rh }}</p>
            @endif
            <h3 class="subtitulo-cv">Declaración y firma</h3>
            @if ($c->firma_ruta)
                <div class="firma-solicitud-ficha">
                    <img src="{{ route('candidatos.firma', $c->id) }}" alt="Firma de {{ $c->nombre_completo }}">
                    <p class="texto-traza m-0"><i class="bi bi-pen" aria-hidden="true"></i> Declaró que la información es verdadera y firmó {{ Candidato::FIRMAS_MEDIO[$c->firma_medio] ?? '' }} · @fecha($c->firma_en){{ $c->firmaCapturadaPor ? ' · capturó '.$c->firmaCapturadaPor->name : '' }}</p>
                </div>
            @else
                <p class="renglon-cv text-muted">Aún no firma su solicitud.</p>
            @endif
            <p class="privacidad-aceptada mt-3 mb-0">
                <i class="bi {{ $c->privacidad_aceptada_en ? 'bi-shield-check' : 'bi-shield-exclamation' }} me-1" aria-hidden="true"></i>
                @if ($c->privacidad_aceptada_en)
                    Aviso de privacidad aceptado @fecha($c->privacidad_aceptada_en) ({{ ['kiosco' => 'en el kiosco', 'web' => 'en la bolsa de trabajo'][$c->privacidad_medio] ?? 'con Recursos Humanos' }}) · versión {{ substr((string) $c->privacidad_version, 0, 10) }}
                @else
                    Aún no acepta el aviso de privacidad: se le pedirá al guardar su CV.
                @endif
            </p>
        </section>

        <div class="d-flex flex-column gap-3">
            {{-- Kiosco: un solo botón (muestra el QR vigente o crea uno) --}}
            @if ($puede['kiosco'] && $c->enProceso())
                <section class="tarjeta p-4">
                    <h2 class="h6 fw-bold"><i class="bi bi-qr-code me-2 text-primary" aria-hidden="true"></i>Que él mismo llene su solicitud</h2>
                    @if ($enlace)
                        <p class="small mb-2">Código vigente: <span class="codigo-kiosco-mini">{{ $enlace->codigo }}</span><br><span class="texto-traza">Vence @fecha($enlace->expira_en, 'H:i') · enviado {{ $enlace->usos }} de {{ $enlace->usos_maximos }} veces</span></p>
                    @else
                        <p class="small text-muted">Que escanee el QR con su celular en la sala de espera. Dura pocas horas y solo sirve para su propia ficha.</p>
                    @endif
                    <form action="{{ route('recepcion.kiosco.generar', $c->id) }}" method="POST" class="m-0">
                        @csrf
                        <input type="hidden" name="reusar" value="1">
                        <button type="submit" class="btn-azul btn-accion-rh w-100"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>QR para que llene su solicitud</button>
                    </form>
                    @if ($enlace)
                        <form action="{{ route('candidatos.enlace.revocar', $c->id) }}" method="POST" class="m-0 mt-2 text-end" data-confirmar="¿Anular el enlace? El candidato ya no podrá usarlo.">
                            @csrf
                            <button type="submit" class="enlace-discreto">Anular enlace</button>
                        </form>
                    @endif
                </section>
            @endif

            {{-- Postulaciones: la activa (la que se ve arriba) y las anteriores --}}
            <section class="tarjeta p-4">
                <h2 class="h6 fw-bold"><i class="bi bi-briefcase me-2 text-secondary" aria-hidden="true"></i>Postulaciones</h2>
                @if ($activa)
                    <p class="small mb-1"><strong>Actual:</strong> {{ $activa->titulo() }} · <span class="pastilla-etapa etapa-{{ Candidato::COLORES[$activa->etapa] ?? 'gris' }}">{{ $activa->etiquetaEtapa() }}</span></p>
                    <p class="texto-traza mb-0">{{ Candidato::ORIGENES[$activa->origen] ?? $activa->origen }} · desde @fecha($activa->created_at)</p>
                    @if ($activa->accesos->isNotEmpty())
                        <p class="texto-traza mb-0">Visitas a caseta:
                            {{ $activa->accesos->map(fn ($v) => $hora->formatear($v->entrada_at, 'd/m H:i').' ('.(\App\Models\Acceso::VIENE_A_CORTO[$v->viene_a] ?? 'Recursos Humanos').')')->join(', ') }}</p>
                    @endif
                @endif
                @if ($anteriores->isNotEmpty())
                    <h3 class="subtitulo-cv">Anteriores</h3>
                    <ul class="postulaciones-anteriores">
                        @foreach ($anteriores as $p)
                            <li>
                                <strong>{{ $p->titulo() }}</strong> · {{ $p->etiquetaEtapa() }}
                                <span class="d-block texto-traza">{{ $p->sede?->nombre }} · del {{ $hora->formatear($p->created_at, 'd/m/Y') }}{{ ($p->contratado_en ?? $p->decision_en) ? ' al '.$hora->formatear($p->contratado_en ?? $p->decision_en, 'd/m/Y') : '' }}{{ $p->motivo_descarte ? ' · '.$p->motivo_descarte : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @elseif (! $activa)
                    <p class="small text-muted mb-0">Sin postulaciones registradas.</p>
                @else
                    <p class="texto-traza mt-2 mb-0">Es su primera postulación.</p>
                @endif
            </section>

            {{-- Evidencia de caseta --}}
            @if ($acceso)
                <section class="tarjeta p-4">
                    <h2 class="h6 fw-bold"><i class="bi bi-camera me-2 text-secondary" aria-hidden="true"></i>Registro en caseta</h2>
                    <p class="small mb-2">Ingresó @fecha($acceso->entrada_at) · Gafete {{ $acceso->gafete_texto ?: 'S/G' }} · registró {{ $acceso->registradoPor?->name ?? '—' }}</p>
                    <div class="fotos-caseta">
                        @foreach (['foto_persona' => ['accesos.foto-persona', 'Persona'], 'foto_identificacion' => ['accesos.foto-identificacion', 'Identificación']] as $campo => [$rutaFoto, $etiqueta])
                            @if ($acceso->{$campo})
                                <a href="{{ route($rutaFoto, $acceso->id) }}" target="_blank" rel="noopener"><img src="{{ route($rutaFoto, $acceso->id) }}" alt="{{ $etiqueta }}"><span>{{ $etiqueta }}</span></a>
                            @endif
                        @endforeach
                        @if (! $acceso->foto_persona && ! $acceso->foto_identificacion)
                            <p class="small text-muted m-0">La caseta no tomó fotos.</p>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Documentos --}}
            <section class="tarjeta p-4">
                <h2 class="h6 fw-bold"><i class="bi bi-paperclip me-2 text-secondary" aria-hidden="true"></i>Documentos</h2>
                @forelse ($c->documentos as $doc)
                    <div class="documento-candidato">
                        <i class="bi {{ $doc->esImagen() ? 'bi-file-earmark-image' : 'bi-file-earmark-pdf' }}" aria-hidden="true"></i>
                        <a href="{{ route('candidatos.documento', [$c->id, $doc->id]) }}" @if ($doc->esImagen()) target="_blank" rel="noopener" @endif>
                            <strong>{{ CandidatoDocumento::TIPOS[$doc->tipo] ?? $doc->tipo }}</strong>
                            <span>{{ $doc->nombre_original }} · {{ $doc->tamano() }} · {{ $doc->origen === 'kiosco' ? 'lo subió el candidato' : ($doc->registradoPor?->name ?? '') }}</span>
                        </a>
                        @if ($puede['editar'])
                            <form action="{{ route('candidatos.documentos.destroy', [$c->id, $doc->id]) }}" method="POST" class="m-0" data-confirmar="¿Eliminar este documento?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-icono eliminar" aria-label="Eliminar {{ $doc->nombre_original }}"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="small text-muted">Sin documentos.</p>
                @endforelse
                @if ($puede['editar'])
                    <form action="{{ route('candidatos.documentos.store', $c->id) }}" method="POST" enctype="multipart/form-data" class="subir-documento mt-2">
                        @csrf
                        <label class="campo-etiqueta" for="doc_tipo">Agregar documento</label>
                        <select id="doc_tipo" name="tipo" class="campo mb-2">
                            @foreach (CandidatoDocumento::TIPOS as $clave => $texto)
                                <option value="{{ $clave }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        <input type="file" name="documento" class="campo mb-2" accept="application/pdf,image/jpeg,image/png" required aria-label="Archivo (PDF, JPG o PNG, máximo 5 MB)">
                        @error('documento')<p class="texto-error-rh">{{ $message }}</p>@enderror
                        <button type="submit" class="btn-secundario-rh w-100"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir (PDF, JPG o PNG · máx. 5 MB)</button>
                    </form>
                @endif
            </section>

            {{-- Tiempos e historial --}}
            <section class="tarjeta p-4">
                <h2 class="h6 fw-bold"><i class="bi bi-stopwatch me-2 text-secondary" aria-hidden="true"></i>Tiempos</h2>
                <ul class="tiempos-candidato">
                    @foreach ($tiempos as $texto => $fecha)
                        <li><span>{{ $texto }}</span><strong>{{ $hora->formatear($fecha, 'd/m H:i') }}</strong></li>
                    @endforeach
                </ul>
                <h2 class="h6 fw-bold mt-3"><i class="bi bi-clock-history me-2 text-secondary" aria-hidden="true"></i>Historial</h2>
                <ol class="historial-candidato">
                    @foreach ($c->eventos as $e)
                        <li>
                            <strong>{{ $e->etapa_nueva ? (Candidato::ETAPAS[$e->etapa_nueva] ?? $e->etapa_nueva) : ucfirst(str_replace('_', ' ', $e->evento)) }}</strong>
                            @if ($e->comentario)<span class="d-block small">{{ $e->comentario }}</span>@endif
                            <span class="texto-traza">{{ $e->usuario?->name ?? 'Kiosco' }} · @fecha($e->created_at)</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($puede['eliminar'])
                <form action="{{ route('candidatos.destroy', $c->id) }}" method="POST" class="m-0"
                      data-confirmar="¿Eliminar la ficha de {{ $c->nombre_completo }} y todos sus documentos? No se puede deshacer (úsalo si la persona pide borrar sus datos).">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-eliminar-candidato"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Eliminar ficha y documentos</button>
                </form>
            @endif
        </div>
    </div>

    {{-- ===== Editar CV ===== --}}
    @if ($puede['editar'])
        <dialog id="dialogoCv" class="dialogo ancho" aria-labelledby="titulo-cv" @if ($dialogo === 'cv') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-cv"><i class="bi bi-pencil-square me-2 text-success" aria-hidden="true"></i>Editar solicitud de empleo</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('candidatos.update', $c->id) }}" method="POST" autocomplete="off" data-form-cv>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_dialogo" value="cv">
                    @if ($dialogo === 'cv' && $errors->any())
                        <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa los datos:
                            <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                        </div>
                    @endif
                    @include('rh.candidatos._cv', ['id' => 'editar_cv', 'kiosco' => false, 'conOld' => $dialogo === 'cv', 'pidePrivacidad' => $c->privacidad_aceptada_en === null, 'pideFirma' => true])
                    <fieldset class="bloque-cv">
                        <legend><i class="bi bi-journal-text me-2" aria-hidden="true"></i>Notas de Recursos Humanos</legend>
                        <textarea name="notas_rh" class="campo" rows="3" maxlength="3000" aria-label="Notas de Recursos Humanos" placeholder="Solo las ve Recursos Humanos">{{ $dialogo === 'cv' ? old('notas_rh') : $c->notas_rh }}</textarea>
                    </fieldset>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-verde">Guardar solicitud</button>
                    </div>
                </form>
            </div>
        </dialog>

        {{-- ===== Cartera / Descartar ===== --}}
        <dialog id="dialogoEtapa" class="dialogo" aria-labelledby="titulo-etapa" @if ($dialogo === 'etapa') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-etapa"><i class="bi bi-signpost-split me-2 text-secondary" aria-hidden="true"></i>Cambiar etapa</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('candidatos.etapa', $c->id) }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_dialogo" value="etapa">
                    @if ($dialogo === 'etapa' && $errors->any())
                        <div class="alert alert-danger small py-2 px-3" role="alert">{{ $errors->first() }}</div>
                    @endif
                    <span class="campo-etiqueta">¿Qué hacemos con {{ $c->nombre_completo }}?</span>
                    <div class="opciones-cv mb-3">
                        @if ($c->puedePasarA('cartera'))
                            <label class="opcion-cv"><input type="radio" name="etapa" value="cartera" data-etapa-opcion @checked(old('etapa', 'cartera') === 'cartera')><span>Guardar en cartera (para otra vacante)</span></label>
                        @endif
                        @if ($c->puedePasarA('descartado'))
                            <label class="opcion-cv"><input type="radio" name="etapa" value="descartado" data-etapa-opcion @checked(old('etapa') === 'descartado')><span>Descartar</span></label>
                        @endif
                    </div>
                    <label class="campo-etiqueta" for="etapa_comentario">Motivo o comentario (obligatorio para descartar)</label>
                    <textarea id="etapa_comentario" name="comentario" class="campo" rows="3" maxlength="500" placeholder="Ej. No cubre el horario">{{ $dialogo === 'etapa' ? old('comentario') : '' }}</textarea>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-azul">Guardar</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif

    {{-- ===== Contratar ===== --}}
    @if ($puede['contratar'] && $c->etapa === 'seleccionado')
        @php $reabrir = $dialogo === 'contratar'; @endphp
        <dialog id="dialogoContratar" class="dialogo" aria-labelledby="titulo-contratar" @if ($reabrir) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-contratar"><i class="bi bi-person-check-fill me-2 text-success" aria-hidden="true"></i>Contratar</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('candidatos.contratar', $c->id) }}" method="POST" autocomplete="off">
                    @csrf
                    <input type="hidden" name="_dialogo" value="contratar">
                    @if ($reabrir && $errors->any())
                        <div class="alert alert-danger small py-2 px-3" role="alert"><ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul></div>
                    @endif
                    <p class="small text-muted">Se dará de alta en <strong>Colaboradores</strong> con los datos de su solicitud (CURP, RFC, NSS, nacimiento, nacionalidad, teléfono, correo y domicilio): no hay que volver a escribirlos. Confirma nombre y apellidos y escribe su número de empleado.</p>
                    <label class="campo-etiqueta" for="con_num">Número de empleado *</label>
                    <input type="text" id="con_num" name="num_empleado" class="campo" maxlength="20" required value="{{ $reabrir ? old('num_empleado') : '' }}">
                    <label class="campo-etiqueta" for="con_nombre">Nombre(s) *</label>
                    <input type="text" id="con_nombre" name="nombre" class="campo" maxlength="60" required value="{{ $reabrir ? old('nombre') : $partes['nombre'] }}">
                    <div class="rejilla-cv">
                        <div>
                            <label class="campo-etiqueta" for="con_paterno">Apellido paterno *</label>
                            <input type="text" id="con_paterno" name="apellido_paterno" class="campo" maxlength="60" required value="{{ $reabrir ? old('apellido_paterno') : $partes['paterno'] }}">
                        </div>
                        <div>
                            <label class="campo-etiqueta" for="con_materno">Apellido materno</label>
                            <input type="text" id="con_materno" name="apellido_materno" class="campo" maxlength="60" value="{{ $reabrir ? old('apellido_materno') : $partes['materno'] }}">
                        </div>
                    </div>
                    <label class="campo-etiqueta" for="con_sede">Sede</label>
                    <select id="con_sede" name="sede_id" class="campo">
                        @foreach ($sedesContratar as $s)
                            <option value="{{ $s->id }}" @selected((int) ($reabrir ? old('sede_id') : $c->sede_id) === $s->id)>{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                    <div class="rejilla-cv">
                        <div>
                            <label class="campo-etiqueta" for="con_depto">Departamento</label>
                            <select id="con_depto" name="departamento_id" class="campo">
                                <option value="">-- Sin definir --</option>
                                @foreach ($departamentos as $d)
                                    <option value="{{ $d->id }}" @selected((int) ($reabrir ? old('departamento_id') : $c->departamento_id) === $d->id)>{{ $d->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="campo-etiqueta" for="con_puesto">Puesto</label>
                            <select id="con_puesto" name="puesto_id" class="campo">
                                <option value="">-- Sin definir --</option>
                                @foreach ($puestos as $p)
                                    <option value="{{ $p->id }}" @selected((int) ($reabrir ? old('puesto_id') : $c->puesto_id) === $p->id)>{{ $p->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-verde">Dar de alta como colaborador</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
</div>
@endsection
