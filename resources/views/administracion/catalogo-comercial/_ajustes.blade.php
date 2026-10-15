{{-- Ajustes comerciales: publicar la página, moneda e IVA, textos y quién recibe las solicitudes --}}
@php $v = fn (string $campo, $omision = '') => old($campo, $omision); @endphp
<form action="{{ route('catalogo-comercial.ajustes') }}" method="POST" class="tarjeta p-4" autocomplete="off" id="ajustes">
    @csrf
    @method('PUT')
    <fieldset @disabled(! $puedeEditar)>
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-globe2 me-1 text-primary" aria-hidden="true"></i> Página pública</h2>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" id="landing_publicada" name="landing_publicada" value="1" @checked(old('landing_publicada', $ajustes['landing_publicada']))>
            <label class="form-check-label fw-bold" for="landing_publicada">Publicar la página de planes</label>
        </div>
        <p class="campo-ayuda mb-4">Encendida: quien abre la dirección de la plataforma sin haber entrado ve la página de planes (con el botón «Entrar»). Apagada: ve la pantalla de acceso, como siempre. Quien ya entró siempre ve su Inicio.</p>

        <h2 class="h6 fw-bold mb-3"><i class="bi bi-cash-coin me-1 text-primary" aria-hidden="true"></i> Precios</h2>
        <div class="row">
            <div class="col-md-4">
                <label class="campo-etiqueta" for="moneda">Moneda</label>
                <select id="moneda" name="moneda" class="campo">
                    @foreach (\App\Services\Comercial\AjustesComerciales::MONEDAS as $clave => $texto)
                        <option value="{{ $clave }}" @selected($v('moneda', $ajustes['moneda']) === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="campo-etiqueta" for="iva_porcentaje">IVA (%)</label>
                <input type="number" id="iva_porcentaje" name="iva_porcentaje" class="campo" min="0" max="100" step="0.01" value="{{ $v('iva_porcentaje', $ajustes['iva_porcentaje']) }}" required>
            </div>
            <div class="col-md-4">
                <label class="campo-etiqueta" for="leyenda_iva">Leyenda junto al precio</label>
                <input type="text" id="leyenda_iva" name="leyenda_iva" class="campo" maxlength="30" value="{{ $v('leyenda_iva', $ajustes['leyenda_iva']) }}" placeholder="+ IVA">
            </div>
        </div>

        <h2 class="h6 fw-bold mb-3 mt-2"><i class="bi bi-calendar2-check me-1 text-primary" aria-hidden="true"></i> Suscripciones</h2>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" id="prueba_habilitada" name="prueba_habilitada" value="1" @checked(old('prueba_habilitada', $ajustes['prueba_habilitada']))>
            <label class="form-check-label fw-bold" for="prueba_habilitada">Ofrecer prueba gratis en la página</label>
        </div>
        <p class="campo-ayuda">Muestra el botón «Probar gratis». Cada prueba la apruebas tú desde Solicitudes (no se activa sola).</p>
        <div class="row">
            <div class="col-md-4">
                <label class="campo-etiqueta" for="dias_prueba">Días de prueba gratis</label>
                <input type="number" id="dias_prueba" name="dias_prueba" class="campo" min="1" max="90" value="{{ $v('dias_prueba', $ajustes['dias_prueba']) }}">
            </div>
            <div class="col-md-4">
                <label class="campo-etiqueta" for="dias_gracia">Días de gracia al vencer</label>
                <input type="number" id="dias_gracia" name="dias_gracia" class="campo" min="0" max="90" value="{{ $v('dias_gracia', $ajustes['dias_gracia']) }}">
                <p class="campo-ayuda mt-0">Después, el cliente queda en solo consulta hasta que pague.</p>
            </div>
            <div class="col-md-4">
                <label class="campo-etiqueta" for="descuento_anual_porcentaje">Descuento por pago anual (%)</label>
                <input type="number" id="descuento_anual_porcentaje" name="descuento_anual_porcentaje" class="campo" min="0" max="90" step="0.01" value="{{ $v('descuento_anual_porcentaje', $ajustes['descuento_anual_porcentaje']) }}">
            </div>
        </div>
        <details class="mb-3">
            <summary class="fw-bold small">Avanzado: avisos de vencimiento y contacto</summary>
            <div class="row mt-2">
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="avisos_dias_antes">Avisar antes de vencer (días)</label>
                    <input type="text" id="avisos_dias_antes" name="avisos_dias_antes" class="campo" maxlength="40" value="{{ $v('avisos_dias_antes', implode(', ', $ajustes['avisos_dias_antes'])) }}" placeholder="7, 1">
                    <p class="campo-ayuda mt-0">Separados por comas. También se avisa al vencer, a la mitad de la gracia y al pasar a solo consulta.</p>
                </div>
                <div class="col-md-8">
                    <label class="campo-etiqueta" for="contacto_suscripciones">A quién contactar para pagar</label>
                    <input type="text" id="contacto_suscripciones" name="contacto_suscripciones" class="campo" maxlength="200" value="{{ $v('contacto_suscripciones', $ajustes['contacto_suscripciones']) }}" placeholder="cobranza@tuempresa.com o 998 000 0000">
                    <p class="campo-ayuda mt-0">Sale en la franja de solo consulta y en los avisos. Vacío: los correos que reciben las solicitudes.</p>
                </div>
            </div>
        </details>

        <h2 class="h6 fw-bold mb-3 mt-2"><i class="bi bi-type me-1 text-primary" aria-hidden="true"></i> Portada</h2>
        <label class="campo-etiqueta" for="portada_titulo">Título</label>
        <input type="text" id="portada_titulo" name="portada_titulo" class="campo" maxlength="120" required value="{{ $v('portada_titulo', $ajustes['portada_titulo']) }}">
        <label class="campo-etiqueta" for="portada_subtitulo">Texto debajo del título</label>
        <textarea id="portada_subtitulo" name="portada_subtitulo" class="campo" rows="2" maxlength="300">{{ $v('portada_subtitulo', $ajustes['portada_subtitulo']) }}</textarea>
        <label class="campo-etiqueta" for="beneficios">Beneficios (uno por renglón, hasta 8)</label>
        <textarea id="beneficios" name="beneficios" class="campo" rows="4" maxlength="1200">{{ $v('beneficios', implode("\n", $ajustes['beneficios'])) }}</textarea>

        <h2 class="h6 fw-bold mb-3 mt-2"><i class="bi bi-envelope me-1 text-primary" aria-hidden="true"></i> Solicitudes</h2>
        <label class="campo-etiqueta" for="correos_solicitudes">Correos que reciben las solicitudes (además del Super Administrador)</label>
        <input type="text" id="correos_solicitudes" name="correos_solicitudes" class="campo" maxlength="600" value="{{ $v('correos_solicitudes', implode(', ', $ajustes['correos_solicitudes'])) }}" placeholder="ventas@tuempresa.com, direccion@tuempresa.com">
        <p class="campo-ayuda">Separa varios con comas. El aviso por correo sale solo si el correo de la plataforma está configurado en Configuración.</p>

        <details class="mt-3">
            <summary class="fw-bold">Avanzado: preguntas frecuentes y textos legales</summary>
            <div class="mt-3">
                <label class="campo-etiqueta" for="preguntas">Preguntas frecuentes</label>
                <textarea id="preguntas" name="preguntas" class="campo" rows="10" maxlength="8000">{{ $v('preguntas', \App\Services\Comercial\AjustesComerciales::preguntasATexto($ajustes['preguntas'])) }}</textarea>
                <p class="campo-ayuda">La pregunta en el primer renglón y la respuesta debajo. Deja un renglón en blanco entre cada pregunta.</p>
                <label class="campo-etiqueta" for="texto_legal">Texto legal del pie de página</label>
                <textarea id="texto_legal" name="texto_legal" class="campo" rows="3" maxlength="1000">{{ $v('texto_legal', $ajustes['texto_legal']) }}</textarea>
                <p class="campo-ayuda">El texto inicial es un borrador: pide a tu abogado que lo revise antes de publicar.</p>
                <label class="campo-etiqueta" for="aviso_privacidad">Aviso de privacidad</label>
                <textarea id="aviso_privacidad" name="aviso_privacidad" class="campo" rows="6" maxlength="20000">{{ $v('aviso_privacidad', $ajustes['aviso_privacidad']) }}</textarea>
                <p class="campo-ayuda">Si lo escribes, el formulario muestra el enlace «aviso de privacidad» y el pie de la página lo enlaza.</p>
            </div>
        </details>
    </fieldset>
    @if ($puedeEditar)
        <button type="submit" class="btn-guardar-identidad"><i class="bi bi-save2-fill me-2" aria-hidden="true"></i>Guardar</button>
    @endif
</form>
