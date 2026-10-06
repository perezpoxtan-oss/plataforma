@extends('layouts.app')

@section('titulo', $p->clave.' · '.$version->titulo)

@section('contenido')
@php
    $color = $version->categoria?->color ?? 'gris';
    $criticos = $version->pasos->where('critico', true)->count();
@endphp
<div class="modo-lectura cat-{{ $color }}" data-modo-lectura>
    {{-- Los errores del acuse se muestran junto a la firma, al final --}}
    @if (session('ok'))
        <div class="alert alert-success aviso mb-3" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> {{ session('ok') }}</div>
    @endif

    <div class="barra-lectura">
        <a href="{{ route('procedimientos.index') }}" class="volver-pases"><i class="bi bi-arrow-left" aria-hidden="true"></i> Procedimientos</a>
        <div class="barra-lectura-acciones">
            <button type="button" class="btn-letra" data-letra="-1" title="Letra más chica" aria-label="Letra más chica">A−</button>
            <button type="button" class="btn-letra" data-letra="1" title="Letra más grande" aria-label="Letra más grande">A+</button>
            <a href="{{ route('procedimientos.show', $p->id) }}" class="btn-letra" title="Ficha del procedimiento" aria-label="Ficha del procedimiento"><i class="bi bi-folder2-open" aria-hidden="true"></i></a>
            <a href="{{ route('procedimientos.imprimir', array_filter(['procedimiento' => $p->id, 'version' => $esVigente ? null : $version->numero])) }}" target="_blank" rel="noopener" class="btn-letra" title="Imprimir" aria-label="Imprimir"><i class="bi bi-printer" aria-hidden="true"></i></a>
        </div>
    </div>

    @unless ($esVigente)
        <p class="aviso-version {{ $version->insignia()[1] }}"><i class="bi bi-eye me-1" aria-hidden="true"></i>
            Vista previa de la <strong>versión {{ $version->numero }} ({{ mb_strtolower($version->insignia()[0]) }})</strong>{{ $p->estado === \App\Models\Procedimiento::RETIRADO ? ': este procedimiento está retirado.' : ': no está vigente.' }}</p>
    @endunless

    <article class="lectura">
        <header class="lectura-cabecera">
            <p class="categoria-procedimiento"><span class="punto-categoria" aria-hidden="true"></span>{{ $version->categoria?->nombre }}</p>
            <p class="lectura-clave">{{ $p->clave }} · versión {{ $version->numero }}</p>
            <h1>{{ $version->titulo }}</h1>
            @if ($version->aprobado_en)
                <p class="lectura-vigencia">Vigente desde @fecha($version->aprobado_en, 'd/m/Y') · Aprobó {{ $version->aprobador_nombre }}</p>
            @endif
            @if ($esVigente && ! $miAcuse && $meAplica)
                <a href="#acuse" class="aviso-por-leer d-block text-decoration-none"><i class="bi bi-book-half me-1" aria-hidden="true"></i>Te toca: léelo completo y firma al final «Leí y entendí».</a>
            @endif
        </header>

        <section class="lectura-bloque">
            <h2>Objetivo</h2>
            <p>{{ $version->objetivo }}</p>
            @if ($version->alcance)<h2>Cuándo aplica</h2><p>{{ $version->alcance }}</p>@endif
            @if ($version->responsables)<h2>Responsables</h2><p>{{ $version->responsables }}</p>@endif
        </section>

        <section class="lectura-bloque">
            <h2>Qué hacer <span class="lectura-resumen">{{ $version->pasos->count() }} {{ $version->pasos->count() === 1 ? 'paso' : 'pasos' }}{{ $criticos ? ' · '.$criticos.' '.($criticos === 1 ? 'crítico' : 'críticos') : '' }}</span></h2>
            <ol class="lectura-pasos">
                @foreach ($version->pasos as $paso)
                    <li class="{{ $paso->critico ? 'critico' : '' }}">
                        <span class="lectura-numero" aria-hidden="true">{{ $paso->orden }}</span>
                        <div class="min-w-0">
                            @if ($paso->critico)<span class="etiqueta-critico"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Punto crítico</span>@endif
                            <p>{{ $paso->texto }}</p>
                            @if ($paso->responsable)<span class="paso-responsable"><i class="bi bi-person" aria-hidden="true"></i> {{ $paso->responsable }}</span>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($version->notas)
            <section class="lectura-bloque"><h2>Notas</h2><p class="texto-notas">{{ $version->notas }}</p></section>
        @endif

        @if ($version->adjuntos->isNotEmpty())
            <section class="lectura-bloque">
                <h2>Adjuntos</h2>
                <ul class="adjuntos-procedimiento">
                    @foreach ($version->adjuntos as $a)
                        <li>
                            <a href="{{ route('procedimientos.adjunto', [$p->id, $a->id]) }}" target="_blank" rel="noopener">
                                @if ($a->esImagen())
                                    <img src="{{ route('procedimientos.adjunto', [$p->id, $a->id]) }}" alt="" class="miniatura-adjunto" loading="lazy">
                                @else
                                    <span class="icono-adjunto" aria-hidden="true"><i class="bi bi-file-earmark-pdf-fill"></i></span>
                                @endif
                                <span class="min-w-0"><strong>{{ $a->nombre }}</strong><span class="d-block small text-muted">{{ $a->esImagen() ? 'Imagen' : 'PDF' }} · {{ $a->tamanoLegible() }}</span></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($esVigente)
            <section class="lectura-acuse" id="acuse">
                @if ($miAcuse)
                    <p class="aviso-leido grande"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Firmaste «Leí y entendí» la versión {{ $version->numero }} el @fecha($miAcuse->leido_en).</p>
                @else
                    <h2><i class="bi bi-pen me-2" aria-hidden="true"></i>Leí y entendí</h2>
                    <form action="{{ route('procedimientos.acuse', $p->id) }}" method="POST" autocomplete="off" data-form-firma-propia>
                        @csrf
                        <input type="hidden" name="version_id" value="{{ $version->id }}">
                        @if ($errors->any())
                            <div class="alert alert-danger small py-2" role="alert">
                                @foreach ($errors->all() as $error)<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</div>@endforeach
                            </div>
                        @endif
                        <label class="casilla-entendido">
                            <input type="checkbox" name="entendido" value="1" required @checked(old('entendido'))>
                            <span>Leí y entendí este procedimiento (<strong>{{ $p->clave }}</strong>, versión {{ $version->numero }}) y sé qué hacer.</span>
                        </label>
                        @include('seguridad.procedimientos._firma-propia', ['id' => 'acuse', 'etiqueta' => 'Tu firma ('.auth()->user()->name.')'])
                        <button type="submit" class="btn-azul btn-firmar-acuse"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Firmar de enterado</button>
                    </form>
                @endif
            </section>
        @endif
    </article>
</div>
@endsection
