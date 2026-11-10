{{--
    Ajustes de la bolsa de trabajo pública (lección 36). Se muestra en Vacantes
    (diálogo «Ajustes») y en Configuración. $empresa · $enDialogo (bool)
--}}
@php
    $bolsaTrabajo = app(\App\Services\Vacantes\BolsaTrabajo::class);
    $ajustesBolsa = $bolsaTrabajo->ajustes($empresa);
    $puedeBolsa = auth()->user()->can('vacantes.configurar')
        && app(\App\Services\Permisos\Autorizador::class)->alcanceDeEmpresa(auth()->user(), 'vacantes.configurar');
    $enDialogo = $enDialogo ?? false;
@endphp
@unless ($enDialogo)
<section class="tarjeta p-4" id="bolsa-trabajo" aria-labelledby="t-bolsa-trabajo">
    <h2 id="t-bolsa-trabajo" class="h5 fw-bold"><i class="bi bi-globe2 me-2 text-primary" aria-hidden="true"></i>Bolsa de trabajo en internet</h2>
@endunless
    <p class="small text-muted">Una página pública con las vacantes <strong>publicadas</strong> de {{ $empresa->nombre_comercial }}. Cualquiera la abre sin usuario ni contraseña y se postula llenando la solicitud de empleo desde su celular. Nunca muestra datos de candidatos.</p>
    @if ($bolsaTrabajo->activa($empresa))
        <p class="small"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Dirección: <a href="{{ route('empleos.index', $empresa->bolsa_slug) }}" target="_blank" rel="noopener" class="enlace-bolsa">{{ route('empleos.index', $empresa->bolsa_slug) }}</a></p>
    @endif
    @unless ($puedeBolsa)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar estos ajustes.</div>
    @endunless
    <form action="{{ route('vacantes.bolsa') }}" method="POST">
        @csrf
        @method('PUT')
        <input type="hidden" name="bolsa_activa" value="0">
        <label class="opcion-todas">
            <input type="checkbox" name="bolsa_activa" value="1" @checked($ajustesBolsa['activa']) @disabled(! $puedeBolsa)>
            <span><strong>Publicar la bolsa de trabajo</strong><br><span class="small text-muted">Apagada, la página pública dice «no existe».</span></span>
        </label>
        <label class="campo-etiqueta" for="bolsa_presentacion{{ $enDialogo ? '_d' : '' }}">Texto de presentación</label>
        <textarea id="bolsa_presentacion{{ $enDialogo ? '_d' : '' }}" name="bolsa_presentacion" class="campo" rows="4" maxlength="2000" @disabled(! $puedeBolsa)
                  placeholder="Ej. Somos un hotel en Cancún con más de 20 años. Buscamos personas con ganas de crecer.">{{ $ajustesBolsa['presentacion'] }}</textarea>
        <input type="hidden" name="bolsa_indexar" value="0">
        <label class="opcion-todas mt-2">
            <input type="checkbox" name="bolsa_indexar" value="1" @checked($ajustesBolsa['indexar']) @disabled(! $puedeBolsa)>
            <span><strong>Permitir que Google la muestre</strong><br><span class="small text-muted">Si no lo marcas, solo la encuentra quien tenga el enlace o el QR del cartel.</span></span>
        </label>
        @if ($puedeBolsa)
            <div class="{{ $enDialogo ? 'dialogo-acciones' : 'mt-3' }}">
                @if ($enDialogo)<button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>@endif
                <button type="submit" class="btn-verde btn-accion-rh">Guardar ajustes de la bolsa</button>
            </div>
        @endif
    </form>
@unless ($enDialogo)
</section>
@endunless
