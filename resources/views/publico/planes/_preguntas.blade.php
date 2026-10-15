@if (! empty($ajustes['preguntas']))
<section class="lp-seccion lp-seccion-blanca" id="preguntas" aria-labelledby="titulo-preguntas">
    <div class="lp-envoltura">
        <div class="lp-encabezado">
            <h2 id="titulo-preguntas">Preguntas frecuentes</h2>
        </div>
        <div class="lp-preguntas">
            @foreach ($ajustes['preguntas'] as [$pregunta, $respuesta])
                <details class="lp-pregunta">
                    <summary>{{ $pregunta }}</summary>
                    <p>{{ $respuesta }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
@endif
