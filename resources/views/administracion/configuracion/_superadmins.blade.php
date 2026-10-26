{{--
    Configuración → Plataforma → Super Administradores (C-36, DD-21, E-14; docs/tecnico/dos-pasos.md).
    Dos activos como mínimo; uno restablece la contraseña o la verificación en dos pasos del otro, sin terminal.
    $sa = ['lista', 'activos', 'faltan', 'solicitudes', 'correoListo']
--}}
@php $yo = auth()->id(); @endphp
<div class="tarjeta p-4" id="cfg-superadmins">
    <h3 class="h6 fw-bold"><i class="bi bi-shield-lock me-2 text-primary" aria-hidden="true"></i>Super Administradores</h3>
    <p class="small text-muted">Debe haber <strong>al menos dos</strong> activos: si uno pierde su teléfono y sus códigos de respaldo, el otro le devuelve el acceso desde aquí. Cada cambio pide tu contraseña, queda en la Bitácora de auditoría y se avisa a los demás.</p>

    @if ($sa['faltan'])
        <div class="alert alert-danger small py-2" role="alert"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i><strong>Solo hay {{ $sa['activos'] }} Super Administrador activo.</strong> Da de alta a otra persona de confianza aquí abajo.</div>
    @endif

    @if ($sa['solicitudes']->isNotEmpty())
        <div class="alert alert-warning small py-2" role="status">
            <i class="bi bi-person-exclamation me-1" aria-hidden="true"></i><strong>Piden ayuda:</strong>
            @foreach ($sa['solicitudes'] as $sc)
                {{ $sc->usuario?->name }} ({{ $sc->etiquetaTipo() }}){{ $loop->last ? '.' : ',' }}
            @endforeach
            Confirma con la persona que fue quien lo pidió y usa su botón aquí abajo.
        </div>
    @endif

    <ul class="list-unstyled mb-3">
        @foreach ($sa['lista'] as $c)
            <li class="border rounded p-3 mb-2">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <strong>{{ $c->name }}</strong>@if ($c->id === $yo) <small class="text-muted">(tú)</small>@endif
                        <span class="small text-muted d-block">{{ '@'.$c->username }} · {{ $c->email }}</span>
                        <span class="small d-block">
                            <span class="etiqueta-estado {{ $c->activo ? 'activo' : 'inactivo' }}">{{ $c->activo ? 'ACTIVO' : 'DE BAJA' }}</span>
                            @if ($c->two_factor_confirmado_en)
                                <span class="text-success ms-1"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i> Dos pasos activos · {{ count((array) ($c->two_factor_recovery_codes ?? [])) }} códigos de respaldo</span>
                            @else
                                <span class="text-warning ms-1"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Activará sus dos pasos al entrar</span>
                            @endif
                        </span>
                    </div>
                </div>
                @if ($c->id !== $yo)
                    <details class="mt-2">
                        <summary class="small fw-bold">Restablecer o dar de baja</summary>
                        <div class="row g-3 mt-1">
                            <form action="{{ route('superadmins.contrasena', $c->id) }}" method="POST" class="col-md-4">
                                @csrf
                                <p class="small fw-bold mb-1"><i class="bi bi-key me-1" aria-hidden="true"></i>Restablecer su contraseña</p>
                                <label class="campo-etiqueta" for="sa_pw_{{ $c->id }}">Contraseña temporal{{ $sa['correoListo'] ? ' (o déjala vacía y le llega un enlace)' : '' }}</label>
                                <input type="password" id="sa_pw_{{ $c->id }}" name="password" class="campo" autocomplete="new-password" @unless ($sa['correoListo']) required @endunless>
                                <label class="campo-etiqueta" for="sa_pw_yo_{{ $c->id }}">Tu contraseña</label>
                                <input type="password" id="sa_pw_yo_{{ $c->id }}" name="contrasena_actual" class="campo" autocomplete="current-password" required>
                                <button type="submit" class="btn btn-outline-primary w-100" style="min-height:44px;border-radius:10px;">Guardar</button>
                            </form>
                            @if ($c->two_factor_confirmado_en)
                                <form action="{{ route('superadmins.dos-pasos', $c->id) }}" method="POST" class="col-md-4" data-confirmar="¿Quitar la verificación en dos pasos de {{ $c->name }}? Confirma antes con esa persona que perdió su teléfono.">
                                    @csrf
                                    <p class="small fw-bold mb-1"><i class="bi bi-phone me-1" aria-hidden="true"></i>Perdió su teléfono</p>
                                    <p class="small text-muted">Quita sus dos pasos; al entrar los activa con su teléfono nuevo.</p>
                                    <label class="campo-etiqueta" for="sa_2p_yo_{{ $c->id }}">Tu contraseña</label>
                                    <input type="password" id="sa_2p_yo_{{ $c->id }}" name="contrasena_actual" class="campo" autocomplete="current-password" required>
                                    <button type="submit" class="btn btn-outline-primary w-100" style="min-height:44px;border-radius:10px;">Quitar sus dos pasos</button>
                                </form>
                            @endif
                            <form action="{{ route('superadmins.estado', $c->id) }}" method="POST" class="col-md-4"
                                  data-confirmar="{{ $c->activo ? '¿Dar de baja a '.$c->name.'? Ya no podrá entrar.' : '¿Reactivar a '.$c->name.'?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="activo" value="{{ $c->activo ? 0 : 1 }}">
                                <p class="small fw-bold mb-1"><i class="bi bi-person-x me-1" aria-hidden="true"></i>{{ $c->activo ? 'Dar de baja' : 'Reactivar' }}</p>
                                @if ($c->activo && $sa['activos'] <= 2)
                                    <p class="small text-muted">No se puede: quedaría menos de dos Super Administradores activos.</p>
                                @else
                                    <label class="campo-etiqueta" for="sa_es_yo_{{ $c->id }}">Tu contraseña</label>
                                    <input type="password" id="sa_es_yo_{{ $c->id }}" name="contrasena_actual" class="campo" autocomplete="current-password" required>
                                    <button type="submit" class="btn {{ $c->activo ? 'btn-outline-danger' : 'btn-outline-success' }} w-100" style="min-height:44px;border-radius:10px;">{{ $c->activo ? 'Dar de baja' : 'Reactivar' }}</button>
                                @endif
                            </form>
                        </div>
                    </details>
                @endif
            </li>
        @endforeach
    </ul>

    <details @if ($sa['faltan'] || old('_form') === 'superadmin') open @endif>
        <summary class="small fw-bold"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Agregar Super Administrador</summary>
        <form action="{{ route('superadmins.store') }}" method="POST" class="mt-2" autocomplete="off">
            @csrf
            <input type="hidden" name="_form" value="superadmin">
            <div class="row g-2">
                <div class="col-md-4"><label class="campo-etiqueta" for="sa_nombre">Nombre completo</label><input id="sa_nombre" name="name" class="campo" maxlength="150" value="{{ old('name') }}" required></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="sa_usuario">Usuario</label><input id="sa_usuario" name="username" class="campo" maxlength="60" value="{{ old('username') }}" autocapitalize="none" required></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="sa_correo">Correo</label><input id="sa_correo" type="email" name="email" class="campo" maxlength="150" value="{{ old('email') }}" required></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="sa_temporal">Contraseña temporal{{ $sa['correoListo'] ? ' (o déjala vacía y le llega un enlace de bienvenida)' : '' }}</label>
                    <input id="sa_temporal" type="password" name="password" class="campo" autocomplete="new-password" @unless ($sa['correoListo']) required @endunless></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="sa_yo">Tu contraseña</label><input id="sa_yo" type="password" name="contrasena_actual" class="campo" autocomplete="current-password" required></div>
            </div>
            <p class="small text-muted mb-2">Al entrar cambia la contraseña y activa su verificación en dos pasos (siempre obligatoria para el Super Administrador).</p>
            <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar</button>
        </form>
    </details>
</div>
