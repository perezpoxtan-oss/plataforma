{{--
    Configuración → Privacidad (docs/tecnico/privacidad.md): conservación de datos personales,
    versiones del aviso de privacidad y solicitudes ARCO. $p = ConservacionDatos::paraConfiguracion().
--}}
@php
    $a = $p['ajustes'];
    $dias = \App\Services\Privacidad\ConservacionDatos::DIAS;
@endphp

{{-- Conservación --}}
<div class="tarjeta p-4" id="cfg-conservacion">
    <h3 class="h6 fw-bold"><i class="bi bi-hourglass-split me-2 text-primary" aria-hidden="true"></i>Conservación de datos personales</h3>
    <p class="small text-muted">Cuántos días se guardan los datos personales de quien visita tus sedes. Al pasar esos días, la revisión de cada noche los quita: <strong>el registro de la bitácora se conserva</strong> (fecha, sede, gafete, quién lo registró) pero sin foto, identificación ni datos de la persona; en su lugar dice «Datos eliminados por conservación».</p>

    <form action="{{ route('privacidad.conservacion') }}" method="POST"
          data-confirmar="{{ $a['activa'] ? '¿Guardar los días de conservación? Lo que pase de los días nuevos se quitará en la revisión de esta noche.' : 'Si marcaste «Quitar automáticamente…», esta noche se quitarán los datos que ya pasaron sus días y no se puede deshacer. ¿Guardar?' }}">
        @csrf
        @method('PUT')
        <label class="fila-check mb-3">
            <input type="checkbox" name="conservacion_activa" value="1" @checked($a['activa'])>
            <span><strong>Quitar automáticamente los datos que pasaron sus días</strong>
                <span class="d-block small text-muted">{{ $a['activa'] ? 'Encendida: se revisa cada noche.' : 'Apagada: no se quita nada hasta que la enciendas.' }}</span></span>
        </label>

        <div class="row g-3">
            @foreach ($dias as $clave => [$etiqueta, $omision, $min, $max])
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="cons_{{ $clave }}">{{ $etiqueta }} (días)</label>
                    <input type="number" id="cons_{{ $clave }}" name="{{ $clave }}" class="campo" min="{{ $min }}" max="{{ $max }}" value="{{ old($clave, $a[$clave]) }}" required>
                    <span class="valor-original small">Valor original: {{ $omision }} días</span>
                </div>
            @endforeach
        </div>

        <p class="small mt-3 mb-1"><i class="bi bi-people me-1" aria-hidden="true"></i><strong>Candidatos no contratados:</strong> su ficha y sus documentos se borran a los <strong>{{ $p['candidatos_meses'] }} meses</strong> sin movimiento, con aviso a Recursos Humanos 15 días antes (siempre, aunque la casilla de arriba esté apagada).
            @if ($p['candidatos_url'])<a href="{{ $p['candidatos_url'] }}">Configurar en Candidatos → Documentos y plazos</a>@endif
        </p>

        <div class="mt-3" style="max-width:22rem;">
            <label class="campo-etiqueta" for="arco_recordar_dias">Solicitudes ARCO: recordar cuando falten (días hábiles)</label>
            <input type="number" id="arco_recordar_dias" name="arco_recordar_dias" class="campo" min="1" max="15" value="{{ old('arco_recordar_dias', $a['arco_recordar_dias']) }}" required>
            <span class="valor-original small">Valor original: {{ \App\Services\Privacidad\ConservacionDatos::RECORDAR_ARCO }} días</span>
        </div>

        <div class="alert alert-secondary small py-2 mt-3 mb-2">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Con los días guardados, hoy se quitarían: <strong>{{ $p['pendientes']['fotos_persona'] }}</strong> foto(s) de personas, <strong>{{ $p['pendientes']['fotos_identificacion'] }}</strong> de identificaciones y los datos de <strong>{{ $p['pendientes']['visitantes'] }}</strong> registro(s) de visitantes.
            @if (is_array($p['ultima']) && ! empty($p['ultima']['en']))
                Última revisión: @fecha(\Illuminate\Support\Carbon::parse($p['ultima']['en']), 'd/m/Y H:i').
            @endif
        </div>
        <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar</button>
    </form>
</div>

{{-- Aviso de privacidad con versiones --}}
<div class="tarjeta p-4" id="cfg-aviso">
    <h3 class="h6 fw-bold"><i class="bi bi-file-earmark-lock me-2 text-primary" aria-hidden="true"></i>Aviso de privacidad</h3>
    @if ($p['vigente'])
        <p class="small mb-1">Vigente: <strong>versión {{ $p['vigente']->numero }}</strong>, publicada el @fecha($p['vigente']->publicado_en, 'd/m/Y'){{ $p['vigente']->publicador ? ' por '.$p['vigente']->publicador->name : '' }}.
            Es la que aceptan en el kiosco, la solicitud de empleo y la bolsa de trabajo; cada persona queda con la versión que aceptó.</p>
    @endif
    <p class="small mb-2">Página pública del aviso (con el formulario de derechos ARCO): <a href="{{ $p['url_publica'] }}" target="_blank" rel="noopener">{{ $p['url_publica'] }}</a></p>
    @if (str_contains($p['texto'], \App\Services\Recepcion\AjustesRecepcion::MARCA_BORRADOR))
        <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>El texto es el <strong>borrador</strong> que trae la plataforma: pide a tu abogado que lo revise y complete los datos entre corchetes.</div>
    @endif
    <form action="{{ route('privacidad.aviso.publicar') }}" method="POST">
        @csrf
        <label class="campo-etiqueta" for="aviso_texto">Texto del aviso</label>
        <textarea id="aviso_texto" name="texto" class="campo" rows="8" maxlength="{{ \App\Services\Privacidad\AvisosPrivacidad::MAX_CARACTERES }}" required>{{ old('texto', $p['texto']) }}</textarea>
        <p class="small text-muted mb-2">Al guardar un texto distinto se publica como <strong>versión nueva</strong>; las anteriores quedan en el historial.</p>
        <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar como versión nueva</button>
    </form>
    @if ($p['historial']->isNotEmpty())
        <details class="mt-3">
            <summary class="small fw-bold">Historial de versiones ({{ $p['historial']->count() }})</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm historial-aviso mb-0">
                    <thead><tr><th>Versión</th><th>Publicada</th><th>Por</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($p['historial'] as $v)
                            <tr>
                                <td>{{ $v->numero }}</td>
                                <td>@fecha($v->publicado_en, 'd/m/Y H:i')</td>
                                <td>{{ $v->publicador?->name ?? 'Cambio en Recepción o texto original' }}</td>
                                <td><a href="{{ route('privacidad.aviso.version', $v->id) }}">Ver texto</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>

{{-- Solicitudes ARCO --}}
<div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-arco">
    <h3 class="h6 fw-bold"><i class="bi bi-person-vcard me-2 text-primary" aria-hidden="true"></i>Solicitudes ARCO</h3>
    <p class="small mb-2">Acceso, Rectificación, Cancelación u Oposición: lo que piden las personas sobre sus datos. La ley da <strong>20 días hábiles</strong> para responder. Abiertas ahora: <strong>{{ $p['arco'] }}</strong>.</p>
    <a href="{{ route('arco.index') }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir solicitudes ARCO</a>
</div>
