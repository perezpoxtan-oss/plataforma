<section class="lp-seccion" aria-labelledby="titulo-llamado">
    <div class="lp-envoltura">
        <div class="lp-llamado">
            <div>
                <h2 id="titulo-llamado">¿Lo platicamos con tus números?</h2>
                <p>Cuéntanos cuántas sedes tienes y qué necesitas. Te respondemos con una propuesta.</p>
            </div>
            <div class="lp-llamado-botones">
                <a href="{{ route('planes.contratar') }}" class="lp-boton lp-boton-claro">Solicitar información</a>
                @if ($ajustes['prueba_habilitada'])
                    <a href="{{ route('planes.contratar', ['prueba' => 1]) }}" class="lp-boton lp-boton-borde">Probar gratis {{ $ajustes['dias_prueba'] }} días</a>
                @endif
                <a href="{{ auth()->check() ? route('panel') : route('login') }}" class="lp-boton lp-boton-borde">{{ auth()->check() ? 'Ir a la plataforma' : 'Ya soy cliente: entrar' }}</a>
            </div>
        </div>
    </div>
</section>
