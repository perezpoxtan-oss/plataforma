{{--
    Categorías de procedimientos (catálogo de la empresa). Cambiarlas pide
    «Editar» con alcance de empresa; con alcance de sede solo se consultan.
--}}
@php
    $conErrores = old('_dialogo') === 'categorias';
    $editandoCategoria = $conErrores ? (int) old('_categoria') : 0;
@endphp
<dialog id="dialogoCategoriasProcedimientos" class="dialogo dialogo-pase" aria-labelledby="titulo-categorias" @if ($conErrores) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-categorias"><i class="bi bi-tags me-2 text-primary" aria-hidden="true"></i>Categorías de procedimientos</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        @if ($conErrores && $errors->any())
            <div class="alert alert-danger small py-2" role="alert">
                @foreach ($errors->all() as $error)<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</div>@endforeach
            </div>
        @endif
        <p class="small text-muted">El color pinta la franja de las fichas. Se muestran en este orden (Emergencias primero).
            @unless ($puede['categoriasEmpresa']) <strong>Solo quien edita procedimientos en toda la empresa puede cambiarlas.</strong>@endunless</p>
        <ul class="lista-categorias">
            @foreach ($categorias as $c)
                <li class="categoria-fila cat-{{ $c->color }} {{ $c->activo ? '' : 'inactiva' }}">
                    @if ($puede['categoriasEmpresa'])
                        <form action="{{ route('procedimientos.categorias.update', $c->id) }}" method="POST" class="form-categoria">
                            @csrf
                            @method('PUT')
                            <span class="punto-categoria" aria-hidden="true"></span>
                            <label class="visually-hidden" for="cat_{{ $c->id }}_nombre">Nombre</label>
                            <input type="text" id="cat_{{ $c->id }}_nombre" name="nombre" class="campo" maxlength="80" required value="{{ $editandoCategoria === $c->id ? old('nombre') : $c->nombre }}">
                            <label class="visually-hidden" for="cat_{{ $c->id }}_color">Color</label>
                            <select id="cat_{{ $c->id }}_color" name="color" class="campo">
                                @foreach (\App\Models\ProcedimientoCategoria::COLORES as $clave => $texto)
                                    <option value="{{ $clave }}" @selected($c->color === $clave)>{{ $texto }}</option>
                                @endforeach
                            </select>
                            <label class="visually-hidden" for="cat_{{ $c->id }}_orden">Orden</label>
                            <input type="number" id="cat_{{ $c->id }}_orden" name="orden" class="campo campo-orden" min="0" max="999" inputmode="numeric" value="{{ $c->orden }}" title="Orden">
                            <label class="chk-activa"><input type="checkbox" name="activo" value="1" @checked($c->activo)> Activa</label>
                            <button type="submit" class="btn-icono" title="Guardar {{ $c->nombre }}" aria-label="Guardar {{ $c->nombre }}"><i class="bi bi-check2" aria-hidden="true"></i></button>
                        </form>
                    @else
                        <span class="punto-categoria" aria-hidden="true"></span> {{ $c->nombre }} @unless ($c->activo)<span class="text-muted small">(inactiva)</span>@endunless
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($puede['categoriasEmpresa'])
            <form action="{{ route('procedimientos.categorias.store') }}" method="POST" class="form-categoria nueva">
                @csrf
                <label class="visually-hidden" for="cat_nueva_nombre">Nombre de la categoría nueva</label>
                <input type="text" id="cat_nueva_nombre" name="nombre" class="campo" maxlength="80" required placeholder="Nueva categoría" value="{{ $conErrores && ! $editandoCategoria ? old('nombre') : '' }}">
                <label class="visually-hidden" for="cat_nueva_color">Color</label>
                <select id="cat_nueva_color" name="color" class="campo">
                    @foreach (\App\Models\ProcedimientoCategoria::COLORES as $clave => $texto)
                        <option value="{{ $clave }}" @selected($clave === 'azul')>{{ $texto }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn-azul"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar</button>
            </form>
        @endif
        <div class="dialogo-acciones">
            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cerrar</button>
        </div>
    </div>
</dialog>
