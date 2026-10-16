{{--
    Configuración → Cuentas y seguridad → Verificación en dos pasos (docs/tecnico/dos-pasos.md).
    $dosPasos: ['politica' => nadie|administradores|todos, 'activas' => int, 'total' => int]; $puedeEditar
--}}
<div class="tarjeta p-4" id="cfg-dos-pasos">
    <h3 class="h6 fw-bold"><i class="bi bi-phone-vibrate me-2 text-primary" aria-hidden="true"></i>Verificación en dos pasos</h3>
    <p class="small text-muted mb-2">Además de la contraseña, al entrar se pide un código del teléfono de cada persona. Hoy la tienen activa <strong>{{ $dosPasos['activas'] }} de {{ $dosPasos['total'] }}</strong> cuentas de tu empresa. El Super Administrador siempre la necesita.</p>
    <form action="{{ route('configuracion.dos-pasos') }}" method="POST">
        @csrf
        @method('PUT')
        <fieldset>
            <legend class="small fw-bold mb-1">Exigir la verificación en dos pasos a:</legend>
            <label class="opcion-todas">
                <input type="radio" name="dos_pasos" value="nadie" @checked($dosPasos['politica'] === 'nadie') @disabled(! $puedeEditar)>
                <span><strong>Nadie</strong> <span class="valor-original">(valor original)</span><br><span class="small text-muted">Cada persona decide si la activa en Mi cuenta.</span></span>
            </label>
            <label class="opcion-todas">
                <input type="radio" name="dos_pasos" value="administradores" @checked($dosPasos['politica'] === 'administradores') @disabled(! $puedeEditar)>
                <span><strong>Administradores</strong><br><span class="small text-muted">Quien puede dar de alta o editar usuarios, roles, permisos o la configuración. La activan la próxima vez que entren.</span></span>
            </label>
            <label class="opcion-todas">
                <input type="radio" name="dos_pasos" value="todos" @checked($dosPasos['politica'] === 'todos') @disabled(! $puedeEditar)>
                <span><strong>Todos</strong><br><span class="small text-muted">Toda cuenta de tu empresa. Antes de elegirlo, avisa a tu personal que necesitará su teléfono para entrar.</span></span>
            </label>
        </fieldset>
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar</button>
        @endif
    </form>
    <p class="small text-muted mt-2 mb-0">¿Alguien perdió su teléfono? Restablece su verificación en <a href="{{ route('usuarios.index') }}">Usuarios</a> (botón con el teléfono).</p>
    @if ($puedeEditar && $dosPasos['politica'] !== 'nadie')
        @include('administracion.configuracion._restablecer', ['ajuste' => 'dos_pasos', 'que' => 'a quién se exige la verificación en dos pasos'])
    @endif
</div>
