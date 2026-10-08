{{--
    Recepción (ADR-0007) dentro del «Registro Inteligente de Ingreso» (Personal externo):
     - Recursos Humanos → «Viene como candidato»: puesto y departamento; al guardar se crea su ficha y se avisa a RR. HH.
     - Visita a Departamento: a qué departamento va (si la empresa lo pide, espera la autorización de su responsable).
    $previo (closure del formulario), $departamentos (con sus sedes)
--}}
@php
    $puestosRecepcion = \App\Models\Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
    $esCandidato = (bool) $previo('es_candidato');
@endphp
<div data-condicion data-solo-motivo="rh" class="caja-candidato-acceso">
    <label class="casilla-candidato">
        <input type="checkbox" name="es_candidato" value="1" @checked($esCandidato) data-muestra-si-marcado="#ingreso_datos_candidato">
        <span><strong>Viene como candidato</strong> (solicitud de empleo o entrevista)<br><span class="small text-muted">Recursos Humanos recibe el aviso en ese momento y abre su ficha.</span></span>
    </label>
    <div id="ingreso_datos_candidato" class="datos-candidato-acceso" @unless ($esCandidato) hidden @endunless>
        {{-- Vacantes (lección 36): vacantes publicadas y vigentes (se acotan a la sede elegida, como los departamentos) --}}
        @php
            $empresaVacantes = \App\Models\Empresa::find(app(\App\Support\Tenancy\Tenant::class)->empresaId());
            $vacantesRecepcion = $empresaVacantes === null ? collect() : \App\Models\Vacante::vigentes(\App\Services\Vacantes\AdministradorVacantes::hoy($empresaVacantes))
                ->with('sedes:id')->orderBy('titulo')->get(['id', 'titulo', 'todas_las_sedes']);
        @endphp
        @if ($vacantesRecepcion->isNotEmpty())
            <label class="campo-etiqueta" for="ingreso_vacante_id">¿A qué vacante viene?</label>
            <select id="ingreso_vacante_id" name="vacante_id" class="campo" data-depto-recepcion>
                <option value="">-- No sabe / otra --</option>
                @foreach ($vacantesRecepcion as $vac)
                    <option value="{{ $vac->id }}" data-todas="{{ $vac->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $vac->sedes->pluck('id')->join(',') }}" @selected((string) $previo('vacante_id') === (string) $vac->id)>{{ $vac->titulo }}</option>
                @endforeach
            </select>
            <p class="campo-ayuda mt-0"><i class="bi bi-megaphone" aria-hidden="true"></i> Si eliges la vacante, el puesto y el departamento se toman de ella.</p>
        @endif
        {{-- Fin Vacantes --}}
        <div class="row">
            <div class="col-md-6">
                <label class="campo-etiqueta" for="ingreso_puesto">Puesto al que aplica</label>
                <select id="ingreso_puesto" name="puesto_id" class="campo">
                    <option value="">-- Elegir (opcional) --</option>
                    @foreach ($puestosRecepcion as $p)
                        <option value="{{ $p->id }}" @selected((string) $previo('puesto_id') === (string) $p->id)>{{ $p->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="campo-etiqueta" for="ingreso_depto_candidato">Departamento</label>
                <select id="ingreso_depto_candidato" name="departamento_id" class="campo" data-depto-recepcion>
                    <option value="">-- No sabe --</option>
                    @foreach ($departamentos as $dep)
                        <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}" @selected((string) $previo('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label class="campo-etiqueta" for="ingreso_vacante">Vacante (si no está en la lista)</label>
        <input type="text" id="ingreso_vacante" name="vacante" class="campo" maxlength="150" value="{{ $previo('vacante') }}" placeholder="Ej. Camarista, Ayudante de cocina">
    </div>
</div>
<div data-condicion data-solo-motivo="departamento">
    <label class="campo-etiqueta" for="ingreso_depto_visita">¿A qué departamento va? *</label>
    <select id="ingreso_depto_visita" name="departamento_id" class="campo" data-depto-recepcion>
        <option value="">-- Seleccionar --</option>
        @foreach ($departamentos as $dep)
            <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}" @selected((string) $previo('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}</option>
        @endforeach
    </select>
    <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Si la empresa pide autorización, la visita queda <strong>Esperando autorización</strong> hasta que el responsable conteste (aquí verás su respuesta).</p>
</div>
