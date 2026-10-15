@extends('layouts.app')

@php
    $nuevo = ! $r->exists;
    $titulo = ($nuevo ? 'Nuevo ' : 'Editar ').$singular;
    $v = fn (string $campo, $omision = null) => old($campo, $r->exists ? $r->{$campo} : $omision);
    $marcado = fn (string $lista, $valor) => in_array($valor, array_map('strval', (array) old($lista, $marcados[$lista])), true);
    $accion = $nuevo ? route('catalogo-comercial.guardar', $tipo) : route('catalogo-comercial.actualizar', [$tipo, $r->id]);
@endphp

@section('titulo', $titulo)

@section('contenido')
    @include('administracion.partes.avisos')
    <a href="{{ route('catalogo-comercial.index', ['pestana' => $tipo]) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Catálogo comercial</a>

    <div class="encabezado-pantalla mb-4 mt-2">
        <div class="icono"><i class="bi {{ ['productos' => 'bi-grid', 'planes' => 'bi-layers', 'paquetes' => 'bi-boxes'][$tipo] }} text-success" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $nuevo ? $titulo : $titulo.': '.$r->nombre }}</h1>
            <p>{{ ['productos' => 'Un producto agrupa módulos de la plataforma para venderlos juntos.', 'planes' => 'Un plan se cobra por sede al mes e incluye productos.', 'paquetes' => 'Un paquete se suma a un plan: al mes o en un solo pago.'][$tipo] }}</p>
        </div>
    </div>

    <form action="{{ $accion }}" method="POST" class="tarjeta p-4" autocomplete="off" style="max-width: 860px;">
        @csrf
        @unless ($nuevo) @method('PUT') @endunless

        @if ($tipo === 'productos')
            <div class="row">
                <div class="col-md-7">
                    <label class="campo-etiqueta" for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" class="campo" maxlength="100" required value="{{ $v('nombre') }}" placeholder="Caseta y accesos">
                </div>
                <div class="col-md-5">
                    <label class="campo-etiqueta" for="clave">Clave{{ $nuevo ? ' *' : '' }}</label>
                    @if ($nuevo)
                        <input type="text" id="clave" name="clave" class="campo" maxlength="40" required pattern="[a-z0-9_]+" value="{{ old('clave') }}" placeholder="caseta_accesos">
                        <p class="campo-ayuda mt-0">Minúsculas, números y guion bajo. No se puede cambiar después.</p>
                    @else
                        <input type="text" id="clave" class="campo" value="{{ $r->clave }}" disabled>
                    @endif
                </div>
            </div>
            <fieldset class="mb-3">
                <legend class="campo-etiqueta">Módulos de la plataforma que habilita</legend>
                <div class="row">
                    @foreach ($modulos as $m)
                        <div class="col-md-4 col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="modulo-{{ $m->id }}" name="modulos[]" value="{{ $m->clave }}" @checked($marcado('modulos', $m->clave))>
                                <label class="form-check-label small" for="modulo-{{ $m->id }}"><i class="bi {{ $m->icono }} me-1" aria-hidden="true"></i>{{ $m->nombre }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </fieldset>
            <details @if (! $nuevo || $errors->hasAny(['descripcion', 'icono'])) open @endif>
                <summary class="fw-bold mb-2">Avanzado: descripción e ícono</summary>
                <label class="campo-etiqueta" for="descripcion">Descripción corta (se ve en la página)</label>
                <input type="text" id="descripcion" name="descripcion" class="campo" maxlength="255" value="{{ $v('descripcion') }}">
                <label class="campo-etiqueta" for="icono">Ícono</label>
                <input type="text" id="icono" name="icono" class="campo" maxlength="40" value="{{ $v('icono', 'bi-box') }}" placeholder="bi-door-open">
                <p class="campo-ayuda mt-0">Nombre de un ícono de Bootstrap Icons, por ejemplo bi-door-open, bi-people o bi-graph-up.</p>
            </details>
        @else
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" class="campo" maxlength="{{ $tipo === 'planes' ? 60 : 80 }}" required value="{{ $v('nombre') }}">
                </div>
                @if ($tipo === 'planes')
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="precio_por_sede_mensual">Precio por sede al mes ({{ $ajustes['moneda'] }}, antes de IVA) *</label>
                        <input type="number" id="precio_por_sede_mensual" name="precio_por_sede_mensual" class="campo" min="0" max="9999999.99" step="0.01" value="{{ $v('precio_por_sede_mensual') }}">
                    </div>
                @else
                    <div class="col-md-3">
                        <label class="campo-etiqueta" for="modalidad">Cobro *</label>
                        <select id="modalidad" name="modalidad" class="campo">
                            @foreach (\App\Models\Paquete::MODALIDADES as $clave => $texto)
                                <option value="{{ $clave }}" @selected($v('modalidad', 'suscripcion') === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="campo-etiqueta" for="precio">Precio ({{ $ajustes['moneda'] }}, antes de IVA) *</label>
                        <input type="number" id="precio" name="precio" class="campo" min="0" max="9999999.99" step="0.01" required value="{{ $v('precio') }}">
                    </div>
                @endif
            </div>
            <label class="campo-etiqueta" for="{{ $tipo === 'planes' ? 'frase' : 'descripcion' }}">{{ $tipo === 'planes' ? 'Frase corta (debajo del nombre)' : 'Descripción corta' }}</label>
            <input type="text" id="{{ $tipo === 'planes' ? 'frase' : 'descripcion' }}" name="{{ $tipo === 'planes' ? 'frase' : 'descripcion' }}" class="campo" maxlength="{{ $tipo === 'planes' ? 150 : 255 }}" value="{{ $v($tipo === 'planes' ? 'frase' : 'descripcion') }}">

            <fieldset class="mb-3">
                <legend class="campo-etiqueta">Productos que incluye</legend>
                <div class="row">
                    @foreach ($productos as $p)
                        <div class="col-md-4 col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="producto-{{ $p->id }}" name="productos[]" value="{{ $p->id }}" @checked($marcado('productos', (string) $p->id))>
                                <label class="form-check-label small" for="producto-{{ $p->id }}"><i class="bi {{ $p->icono }} me-1" aria-hidden="true"></i>{{ $p->nombre }}{{ $p->activo ? '' : ' (de baja)' }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </fieldset>

            <div class="form-check form-switch mb-3">
                <input type="hidden" name="publicado" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="publicado" name="publicado" value="1" @checked((bool) $v('publicado', false))>
                <label class="form-check-label fw-bold" for="publicado">Mostrar en la página pública</label>
            </div>

            <details @if (! $nuevo || $errors->hasAny(['slug', 'sedes_minimas', 'usuarios_por_sede', 'planes', 'descripcion'])) open @endif>
                <summary class="fw-bold mb-2">Avanzado</summary>
                @if ($tipo === 'planes')
                    <div class="row">
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="sedes_minimas">Sedes mínimas</label>
                            <input type="number" id="sedes_minimas" name="sedes_minimas" class="campo" min="1" max="999" value="{{ $v('sedes_minimas', 1) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="usuarios_por_sede">Usuarios por sede incluidos</label>
                            <input type="number" id="usuarios_por_sede" name="usuarios_por_sede" class="campo" min="1" max="9999" value="{{ $v('usuarios_por_sede') }}" placeholder="Vacío = ilimitados">
                        </div>
                        <div class="col-md-4">
                            <label class="campo-etiqueta" for="slug">Dirección corta</label>
                            <input type="text" id="slug" name="slug" class="campo" maxlength="60" value="{{ $v('slug') }}" placeholder="Se arma con el nombre">
                        </div>
                    </div>
                    <label class="campo-etiqueta" for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" class="campo" rows="3" maxlength="2000">{{ $v('descripcion') }}</textarea>
                    <div class="form-check mb-1">
                        <input type="hidden" name="destacado" value="0">
                        <input class="form-check-input" type="checkbox" id="destacado" name="destacado" value="1" @checked((bool) $v('destacado', false))>
                        <label class="form-check-label" for="destacado"><strong>Recomendado</strong>: se resalta en la página (solo uno a la vez)</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="hidden" name="cotizar" value="0">
                        <input class="form-check-input" type="checkbox" id="cotizar" name="cotizar" value="1" @checked((bool) $v('cotizar', false))>
                        <label class="form-check-label" for="cotizar"><strong>Cotizar</strong>: no muestra el precio; la persona pide una cotización</label>
                    </div>
                @else
                    <div class="row">
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="slug">Dirección corta</label>
                            <input type="text" id="slug" name="slug" class="campo" maxlength="60" value="{{ $v('slug') }}" placeholder="Se arma con el nombre">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-3">
                                <input type="hidden" name="por_sede" value="0">
                                <input class="form-check-input" type="checkbox" id="por_sede" name="por_sede" value="1" @checked((bool) $v('por_sede', true))>
                                <label class="form-check-label" for="por_sede">El precio es <strong>por sede</strong> (se multiplica por las sedes)</label>
                            </div>
                        </div>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="campo-etiqueta">Se puede sumar a (sin marcar = a cualquier plan)</legend>
                        @foreach ($planes as $p)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="plan-{{ $p->id }}" name="planes[]" value="{{ $p->id }}" @checked($marcado('planes', (string) $p->id))>
                                <label class="form-check-label small" for="plan-{{ $p->id }}">{{ $p->nombre }}{{ $p->activo ? '' : ' (de baja)' }}</label>
                            </div>
                        @endforeach
                    </fieldset>
                @endif
            </details>
        @endif

        <button type="submit" class="btn-guardar-identidad"><i class="bi bi-save2-fill me-2" aria-hidden="true"></i>Guardar</button>
    </form>
@endsection
