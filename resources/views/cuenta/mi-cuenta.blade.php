@extends('layouts.app')

@section('titulo', 'Mi cuenta')

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-4">
        <div class="icono"><i class="bi bi-person-circle text-primary" aria-hidden="true"></i></div>
        <div><h1>Mi cuenta</h1><p>Tus datos de acceso. Tu contraseña es solo tuya: nadie más la conoce.</p></div>
    </div>

    @if ($debeCambiar)
        <div class="tarjeta p-4 mb-4 cuenta-primer-acceso" role="alert">
            <h2 class="h6 fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill text-warning me-2" aria-hidden="true"></i>Crea tu propia contraseña para continuar</h2>
            <p class="small text-muted m-0">La contraseña con la que entraste la escribió quien te dio de alta. Cámbiala abajo; después podrás usar el resto de la plataforma.</p>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-datos">
                <h2 id="t-datos" class="h5 fw-bold mb-3"><i class="bi bi-person-vcard me-2 text-primary" aria-hidden="true"></i>Mis datos</h2>
                <dl class="mi-cuenta-datos">
                    <div><dt>Nombre</dt><dd>{{ $cuenta->name }}</dd></div>
                    <div><dt>Usuario</dt><dd>{{ '@'.$cuenta->username }}</dd></div>
                    <div><dt>Correo</dt><dd>{{ $cuenta->email }}<small>Aquí llegan los enlaces para recuperar tu contraseña. Para cambiarlo, pide a tu administrador.</small></dd></div>
                    <div><dt>Rol</dt><dd>{{ $rolNombreCuenta }}</dd></div>
                    @if ($empresaNombre)
                        <div><dt>Empresa</dt><dd>{{ $empresaNombre }}</dd></div>
                        <div><dt>{{ str_contains((string) $sedeNombre, ',') ? 'Sedes' : 'Sede' }}</dt><dd>{{ $sedeNombre ?? ($todasLasSedes ? ($sedesEmpresa === 1 ? 'Tu sede' : 'Todas las sedes de la empresa') : 'Sin sede asignada') }}</dd></div>
                    @endif
                    @if ($cuenta->ultimo_acceso_en)
                        <div><dt>Último acceso</dt><dd>@fecha($cuenta->ultimo_acceso_en)</dd></div>
                    @endif
                </dl>
                <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>El nombre, el rol, la sede y el correo los administra quien da de alta usuarios.</p>
            </section>
        </div>

        <div class="col-lg-7">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-contrasena">
                <h2 id="t-contrasena" class="h5 fw-bold mb-1"><i class="bi bi-key-fill me-2 text-primary" aria-hidden="true"></i>Cambiar mi contraseña</h2>
                <p class="small text-muted mb-3">Mínimo 8 caracteres, con letras y números; sin tu usuario ni tu correo. Al cambiarla se cierran tus otras sesiones.</p>

                <form action="{{ route('mi-cuenta.contrasena') }}" method="POST" autocomplete="off">
                    @csrf
                    @method('PUT')

                    <label class="campo-etiqueta" for="contrasena_actual">Contraseña actual</label>
                    <div class="acceso-entrada sin-icono mb-3">
                        <input type="password" id="contrasena_actual" name="contrasena_actual" class="campo con-boton m-0" required autocomplete="current-password" @if ($debeCambiar) autofocus @endif>
                        <button type="button" class="acceso-ver-contrasena" data-accion="ver-contrasena" data-objetivo="contrasena_actual" aria-label="Mostrar u ocultar contraseña" tabindex="-1"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>

                    <label class="campo-etiqueta" for="password">Contraseña nueva</label>
                    <div class="acceso-entrada sin-icono mb-3">
                        <input type="password" id="password" name="password" class="campo con-boton m-0" required autocomplete="new-password" placeholder="Mínimo 8, con letras y números">
                        <button type="button" class="acceso-ver-contrasena" data-accion="ver-contrasena" data-objetivo="password" aria-label="Mostrar u ocultar contraseña" tabindex="-1"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>

                    <label class="campo-etiqueta" for="password_confirmation">Repite la contraseña nueva</label>
                    <div class="acceso-entrada sin-icono mb-4">
                        <input type="password" id="password_confirmation" name="password_confirmation" class="campo con-boton m-0" required autocomplete="new-password" placeholder="Igual que arriba">
                        <button type="button" class="acceso-ver-contrasena" data-accion="ver-contrasena" data-objetivo="password_confirmation" aria-label="Mostrar u ocultar contraseña" tabindex="-1"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>

                    <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar</button>
                </form>
            </section>
        </div>
    </div>

    {{-- Avisos del navegador: avisos del sistema aunque la pestaña esté en segundo plano (no aplica dentro de la app de Android) --}}
    @unless (\App\Support\AppMovil::es())
    <section class="tarjeta p-4 mt-4" id="avisos-navegador" aria-labelledby="t-avisos-navegador">
        <h2 id="t-avisos-navegador" class="h5 fw-bold mb-1"><i class="bi bi-window-stack me-2 text-primary" aria-hidden="true"></i>Avisos en este navegador</h2>
        <p class="small text-muted mb-3">Con la plataforma abierta en una pestaña (aunque estés en otra pestaña u otro programa), tu computadora te muestra un aviso cuando llega una notificación nueva. Se activa en cada navegador por separado.</p>
        @include('componentes.avisos-navegador')
    </section>
    @endunless

    {{-- Mejoras estructura (ADR-0016): cada persona elige qué avisos recibe --}}
    @if ($opcionesAvisos['campana'] !== [] || $opcionesAvisos['correo'] !== [])
        <section class="tarjeta p-4 mt-4" id="mis-avisos" aria-labelledby="t-mis-avisos">
            <h2 id="t-mis-avisos" class="h5 fw-bold mb-1"><i class="bi bi-bell me-2 text-primary" aria-hidden="true"></i>Mis avisos</h2>
            <p class="small text-muted mb-3">Quita la marca de lo que no quieres recibir. Lo que espera tu respuesta sigue apareciendo en «Mis pendientes» aunque lo silencies aquí.</p>
            <form action="{{ route('mi-cuenta.avisos') }}" method="POST">
                @csrf
                @method('PUT')
                <div class="row g-4">
                    @foreach (['campana' => ['En la campana', 'bi-bell-fill'], 'correo' => ['Por correo', 'bi-envelope-fill']] as $canal => [$titulo, $icono])
                        @continue ($opcionesAvisos[$canal] === [])
                        <div class="col-lg-6">
                            <h3 class="h6 fw-bold"><i class="bi {{ $icono }} me-1 text-primary" aria-hidden="true"></i>{{ $titulo }}</h3>
                            @foreach ($opcionesAvisos[$canal] as $grupo => $avisosGrupo)
                                <fieldset class="mis-avisos-grupo">
                                    <legend class="small fw-bold text-muted mb-1">{{ $grupo }}</legend>
                                    @foreach ($avisosGrupo as $clave => $etiqueta)
                                        <label class="fila-check mb-1">
                                            <input type="checkbox" name="{{ $canal }}[{{ $clave }}]" value="1" @checked(! in_array($clave, $silenciados[$canal], true))>
                                            <span class="small">{{ $etiqueta }}</span>
                                        </label>
                                    @endforeach
                                </fieldset>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <button type="submit" class="btn-azul mt-3" style="min-height:44px;border-radius:10px;padding:0 1.25rem;"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar</button>
            </form>
        </section>
    @endif
</div>
@endsection
