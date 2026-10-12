{{--
    Matriz de permisos (ADR-0016, criterio 7):
     1. «Lo que verá»: los menús que tendrá una persona con este rol (se
        actualiza en vivo al marcar «Ver» en la tabla; plataforma.js, bloque
        «Mejoras estructura»).
     2. «¿Qué hace este rol?»: elegir un perfil (las plantillas de la
        plataforma) u otro rol de la empresa reemplaza los permisos de un solo
        golpe; la tabla completa queda abajo, en «Avanzado».
--}}
@php
    $otrosRoles = $roles->reject(fn ($r) => $r->id === $rol->id)->values();
@endphp
<section class="tarjeta p-3 p-md-4 mb-3 vista-menus" aria-labelledby="t-vista-menus"
         data-vista-menus data-mapa="{{ json_encode($mapaMenus, JSON_UNESCAPED_UNICODE) }}">
    <h2 id="t-vista-menus" class="h6 fw-bold mb-1"><i class="bi bi-eye me-2 text-primary" aria-hidden="true"></i>Lo que verá una persona con el rol «{{ $rol->nombre }}»</h2>
    <p class="small text-muted mb-2">Los menús y pantallas que tendrá al entrar (además de Inicio, Mis pendientes, la campana y el Manual). Cambia en vivo mientras marcas «Ver» en la tabla.</p>
    <div class="vista-menus-lista" data-vista-menus-lista>
        @forelse ($menusRol as $menu)
            <div class="vista-menu">
                <strong><i class="bi {{ $menu['icono'] }}" aria-hidden="true"></i> {{ $menu['nombre'] }}</strong>
                <span>{{ collect($menu['secciones'])->flatten()->join(' · ') }}</span>
            </div>
        @empty
            <p class="small text-muted m-0" data-vista-menus-vacia>Ningún menú: solo verá Inicio, Mis pendientes, la campana y el Manual.</p>
        @endforelse
    </div>
</section>

@if ($editable && ($perfiles->isNotEmpty() || $otrosRoles->isNotEmpty()))
    <section class="tarjeta p-3 p-md-4 mb-3 asistente-rol" aria-labelledby="t-asistente-rol">
        <h2 id="t-asistente-rol" class="h6 fw-bold mb-1"><i class="bi bi-magic me-2 text-warning" aria-hidden="true"></i>¿Qué hace este rol?</h2>
        @include('componentes.ayuda-apartado', ['pagina' => 'roles-y-permisos', 'apartado' => 'Cómo dar o quitar permisos'])
        <p class="small text-muted mb-3">Elige el perfil que más se parece y toca «Guardar». Reemplaza los permisos de «{{ $rol->nombre }}» de un solo golpe; después, si algo cambia, ajústalo en «Avanzado». Solo se otorga lo que tú tienes.</p>
        <form action="{{ route('permisos.perfil', $rol->id) }}" method="POST"
              data-confirmar="¿Reemplazar los permisos de «{{ $rol->nombre }}» por los del perfil elegido? Afecta de inmediato a todas las personas con ese rol.">
            @csrf
            @method('PUT')
            <div class="perfiles-rol">
                @foreach ($perfiles as $p)
                    <label class="perfil-rol">
                        <input type="radio" name="perfil" value="perfil-{{ $p['id'] }}" required>
                        <span class="perfil-rol-cuerpo">
                            <strong>{{ $p['nombre'] }}</strong>
                            <span class="small">{{ $p['descripcion'] }}</span>
                            <span class="perfil-rol-menus">{{ $p['menus'] !== [] ? 'Ve: '.implode(', ', $p['menus']) : 'Sin menús' }}</span>
                        </span>
                    </label>
                @endforeach
                @if ($otrosRoles->isNotEmpty())
                    <label class="perfil-rol">
                        <input type="radio" name="perfil" value="otro" required>
                        <span class="perfil-rol-cuerpo">
                            <strong>Igual que otro rol de la empresa</strong>
                            <select name="perfil_rol" class="campo mb-0 mt-1" aria-label="Rol del que se copian los permisos">
                                @foreach ($otrosRoles as $r)
                                    <option value="rol-{{ $r->id }}">{{ $r->nombre }}</option>
                                @endforeach
                            </select>
                        </span>
                    </label>
                @endif
            </div>
            <button type="submit" class="btn-guardar-permisos mt-3"><i class="bi bi-check2-circle me-2" aria-hidden="true"></i>Guardar</button>
        </form>
    </section>
@endif
