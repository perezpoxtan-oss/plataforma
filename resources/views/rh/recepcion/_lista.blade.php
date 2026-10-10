{{-- Lista del panel de Recepción (se vuelve a pintar cada 15 s). $filas, $puede
     Botones: «Que pase» (si su acceso espera a RR. HH.), «Atender» (= En revisión) y «Abrir ficha». El QR del kiosco
     vive en la ficha y en el Modo kiosco. --}}
@forelse ($filas as $f)
    <article class="fila-recepcion espera-{{ $f['espera'] }}">
        <div class="fila-recepcion-foto">
            @if ($f['foto'])
                <img src="{{ $f['foto'] }}" alt="" loading="lazy">
            @else
                <span aria-hidden="true">{{ mb_strtoupper(mb_substr($f['nombre'], 0, 1)) }}</span>
            @endif
        </div>
        <div class="fila-recepcion-cuerpo">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 class="fila-recepcion-nombre">{{ $f['nombre'] }}</h2>
                <span class="pastilla-tipo-rh {{ $f['es_candidato'] ? 'candidato' : 'tramite' }}">{{ $f['tipo'] }}</span>
                @if ($f['autocaptura'])<span class="pastilla-revisar"><i class="bi bi-phone me-1" aria-hidden="true"></i>Llenó su CV</span>@endif
            </div>
            <div class="datos-candidato">
                <span class="viene-a-rh"><i class="bi bi-signpost" aria-hidden="true"></i> Viene a: {{ $f['viene_a'] }}</span>
                @if ($f['puesto'])<span><i class="bi bi-briefcase" aria-hidden="true"></i> {{ $f['puesto'] }}</span>@endif
                @if ($f['departamento'])<span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $f['departamento'] }}</span>@endif
                <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $f['sede'] }}</span>
            </div>
            <div class="fila-recepcion-tiempos">
                <span><i class="bi bi-door-open" aria-hidden="true"></i> Llegó {{ $f['llegada'] }}</span>
                <span class="minutos-espera"><i class="bi bi-stopwatch" aria-hidden="true"></i> {{ $f['minutos'] < 60 ? $f['minutos'].' min' : intdiv($f['minutos'], 60).' h '.($f['minutos'] % 60).' min' }}</span>
                <span class="estado-fila-rh">{{ $f['estado'] }}</span>
            </div>
        </div>
        <div class="fila-recepcion-acciones">
            @if ($f['autorizacion_id'] && $puede['responder'])
                <form action="{{ route('autorizaciones.responder', $f['autorizacion_id']) }}" method="POST" class="m-0">
                    @csrf
                    <input type="hidden" name="respuesta" value="pase">
                    <input type="hidden" name="volver" value="recepcion">
                    <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-door-open-fill me-1" aria-hidden="true"></i>Que pase</button>
                </form>
                <a href="{{ route('autorizaciones.show', $f['autorizacion_id']) }}" class="btn-otra-respuesta">Que espere / No puede pasar</a>
            @endif
            @if ($f['candidato_id'] && $f['etapa'] === 'registrado' && $puede['editar'])
                <form action="{{ route('candidatos.etapa', $f['candidato_id']) }}" method="POST" class="m-0">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="etapa" value="revision">
                    <input type="hidden" name="volver" value="recepcion">
                    <button type="submit" class="{{ $f['autorizacion_id'] ? 'btn-secundario-rh' : 'btn-verde btn-accion-rh' }}"><i class="bi bi-hand-index-thumb me-1" aria-hidden="true"></i>Atender</button>
                </form>
            @endif
            @if ($f['ficha'])
                <a href="{{ $f['ficha'] }}" class="btn-secundario-rh">Abrir ficha</a>
            @endif
        </div>
    </article>
@empty
    <div class="tarjeta estado-vacio">
        <div class="icono"><i class="bi bi-cup-hot" aria-hidden="true"></i></div>
        <p class="text-muted small m-0">Nadie espera a Recursos Humanos en este momento. Cuando la caseta registre a un candidato, aparecerá aquí y te llegará un aviso en la campana.</p>
    </div>
@endforelse
