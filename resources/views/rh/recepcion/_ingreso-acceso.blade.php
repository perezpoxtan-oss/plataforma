{{--
    Recepción (ADR-0007) dentro del «Registro Inteligente de Ingreso» (Personal externo):
     - Recursos Humanos → «¿A qué viene?» (tres opciones): Busca empleo (con «¿A qué vacante?» si hay vacantes
       publicadas), Ya tiene cita (la plataforma busca su ficha y deduce si viene a su entrevista, a entregar
       documentos o a firmar su contrato) u Otro trámite de RR. HH. La caseta solo registra a qué viene y avisa;
       el puesto y el departamento los deduce la vacante o los pone RR. HH. El enlace a las vacantes publicadas
       se muestra siempre que haya y la caseta pueda verlas.
     - Visita a Departamento: a qué departamento va (si la empresa lo pide, espera la autorización de su responsable).
    $previo (closure del formulario), $departamentos (con sus sedes)
--}}
@php
    $vieneA = (string) ($previo('viene_a') ?: ($previo('es_candidato') ? 'busca_empleo' : ''));
    // Los valores de antes (entrevista, documentos, firma, informes) se muestran con su opción de hoy
    $vieneA = in_array($vieneA, ['entrevista', 'documentos', 'firma'], true) ? 'cita' : ($vieneA === 'informes' ? 'tramite' : $vieneA);
    // Vacantes publicadas y vigentes (se acotan a la sede elegida, como los departamentos)
    $empresaVacantes = \App\Models\Empresa::find(app(\App\Support\Tenancy\Tenant::class)->empresaId());
    $vacantesRecepcion = $empresaVacantes === null ? collect() : \App\Models\Vacante::vigentes(\App\Services\Vacantes\AdministradorVacantes::hoy($empresaVacantes))
        ->with('sedes:id')->orderBy('titulo')->get(['id', 'titulo', 'todas_las_sedes']);
    $rhAutorizaPaso = $empresaVacantes !== null && app(\App\Services\Recepcion\AjustesRecepcion::class)->rhAutorizaPaso($empresaVacantes);
@endphp
<div data-condicion data-solo-motivo="rh" class="caja-candidato-acceso">
    <label class="campo-etiqueta" for="ingreso_viene_a">¿A qué viene? *</label>
    <select id="ingreso_viene_a" name="viene_a" class="campo" data-viene-a required>
        <option value="">-- Seleccionar --</option>
        @foreach (\App\Models\Acceso::OPCIONES_CASETA as $clave => $texto)
            <option value="{{ $clave }}" @selected($vieneA === $clave)>{{ $texto }}</option>
        @endforeach
    </select>
    <p class="campo-ayuda mt-0"><i class="bi bi-info-circle" aria-hidden="true"></i>
        {{ $rhAutorizaPaso ? 'Recursos Humanos recibe el aviso en ese momento. La persona espera en caseta hasta que RR. HH. diga «Que pase» (esta tarjeta cambia sola).' : 'Recursos Humanos recibe el aviso en ese momento.' }}</p>

    <div data-condicion data-solo-viene-a="busca_empleo">
        @if ($vacantesRecepcion->isNotEmpty())
            <label class="campo-etiqueta" for="ingreso_vacante_id">¿A qué vacante?</label>
            <select id="ingreso_vacante_id" name="vacante_id" class="campo" data-depto-recepcion>
                <option value="">-- No sabe / otra --</option>
                @foreach ($vacantesRecepcion as $vac)
                    <option value="{{ $vac->id }}" data-todas="{{ $vac->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $vac->sedes->pluck('id')->join(',') }}" @selected((string) $previo('vacante_id') === (string) $vac->id)>{{ $vac->titulo }}</option>
                @endforeach
            </select>
        @else
            <p class="campo-ayuda"><i class="bi bi-megaphone" aria-hidden="true"></i> No hay vacantes publicadas: Recursos Humanos anotará a qué puesto aplica.</p>
        @endif
    </div>
    <div data-condicion data-solo-viene-a="cita">
        <p class="campo-ayuda"><i class="bi bi-person-vcard" aria-hidden="true"></i> Ya vino antes: se busca su ficha y la plataforma anota si viene a su entrevista, a entregar documentos o a firmar. Si no aparece, Recursos Humanos lo recibe como «Busca empleo».</p>
    </div>
    @if ($vacantesRecepcion->isNotEmpty())
        @can('vacantes.ver')
            <p class="campo-ayuda enlace-vacantes-caseta"><i class="bi bi-megaphone" aria-hidden="true"></i> ¿Solo pide informes?
                <a href="{{ route('vacantes.index') }}" target="_blank" rel="noopener">Ver las vacantes publicadas</a> (sin registrar nada).</p>
        @endcan
    @endif
</div>
<div data-condicion data-solo-motivo="departamento">
    <label class="campo-etiqueta" for="ingreso_depto_visita">{{ ($giro ?? \App\Support\Terminos::actual())->esResidencial() ? '¿A qué área de la administración va?' : '¿A qué departamento va?' }} *</label>
    <select id="ingreso_depto_visita" name="departamento_id" class="campo" data-depto-recepcion>
        <option value="">-- Seleccionar --</option>
        @foreach ($departamentos as $dep)
            <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}" @selected((string) $previo('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}</option>
        @endforeach
    </select>
    <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Si la empresa pide autorización, la visita queda <strong>Esperando autorización</strong> hasta que el responsable conteste (aquí verás su respuesta).</p>
</div>
