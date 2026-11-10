{{--
    Diálogos de alta y edición de un catálogo de reservas (montajes, tipos o
    servicios). La edición la llena data-accion="editar-registro" (plataforma.js).
    Parámetros: $clave, $titulo (del alta), $servicio (bool), $departamentos.
--}}
@php use App\Models\ReservaServicio; @endphp
@foreach (['nuevo' => $titulo, 'editar' => 'Editar'] as $modo => $tituloDialogo)
    @php $id = ($modo === 'nuevo' ? 'dialogoNuevo-' : 'dialogoEditar-').$clave; $p = $modo.'-'.$clave; @endphp
    <dialog id="{{ $id }}" class="dialogo" aria-labelledby="t-{{ $id }}">
        <div class="dialogo-cabecera">
            <h2 id="t-{{ $id }}"><i class="bi {{ $modo === 'nuevo' ? 'bi-plus-circle' : 'bi-pencil' }} me-2 text-success" aria-hidden="true"></i>{{ $tituloDialogo }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form method="POST" action="{{ $modo === 'nuevo' ? route('reservas.configuracion.catalogo.crear') : '' }}" autocomplete="off">
                @csrf
                @if ($modo === 'nuevo')
                    <input type="hidden" name="catalogo" value="{{ $clave }}">
                @else
                    @method('PUT')
                @endif
                <label class="campo-etiqueta" for="{{ $p }}-nombre">Nombre <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" id="{{ $p }}-nombre" name="nombre" class="campo" maxlength="{{ $servicio ? 80 : 60 }}" required>
                @if ($servicio)
                    <div class="campo-grupo mt-2">
                        <div>
                            <label class="campo-etiqueta" for="{{ $p }}-categoria">Categoría</label>
                            <select id="{{ $p }}-categoria" name="categoria" class="campo">
                                @foreach (ReservaServicio::CATEGORIAS as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="campo-etiqueta" for="{{ $p }}-unidad">Se cobra / se pide</label>
                            <select id="{{ $p }}-unidad" name="unidad" class="campo">
                                @foreach (ReservaServicio::UNIDADES as $k => [$n])<option value="{{ $k }}">{{ $n }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <label class="campo-etiqueta mt-2" for="{{ $p }}-costo">Costo para clientes <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="number" id="{{ $p }}-costo" name="costo" class="campo" min="0" step="0.01">
                    <label class="campo-etiqueta mt-2" for="{{ $p }}-depto">Departamento que lo atiende</label>
                    <select id="{{ $p }}-depto" name="departamento_id" class="campo">
                        <option value="">Ninguno</option>
                        @foreach ($departamentos as $d)<option value="{{ $d->id }}">{{ $d->nombre }}</option>@endforeach
                    </select>
                    <label class="d-flex gap-2 align-items-center small mt-2"><input type="checkbox" name="requiere_aprobacion" value="1" @checked($modo === 'nuevo')> El departamento lo aprueba antes de confirmar</label>
                @endif
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-verde">Guardar</button>
                </div>
            </form>
        </div>
    </dialog>
@endforeach
