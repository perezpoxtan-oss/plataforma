{{--
    Botón discreto "Eliminar definitivamente" (borrado físico controlado; ver docs/tecnico/borrado.md).
    Solo aparece a quien tiene "<modulo>.borrar"; el servidor revisa además el alcance de cada registro.

    @include('componentes.borrar', [
        'registro' => 'sedes',   // clave de App\Services\Borrado\RegistroBorrado
        'id' => $sede->id,       // opcional: en los diálogos de edición compartidos lo pone el JS
                                 // al abrir "Editar" (data-accion="editar-registro" / "editar-rol")
        'compacto' => true,      // opcional: solo el ícono (para listas)
        'nombre' => 'VIP',       // opcional, con compacto: para el texto accesible
    ])

    Al presionarlo se pregunta al servidor qué lo usa: si algo depende de él se
    explica y se ofrece "Dar de baja"; si no, se pide teclear su nombre o
    identificador para confirmar. El diálogo de confirmación es uno por página.
--}}
@php
    $definicionBorrado = \App\Services\Borrado\RegistroBorrado::de($registro);
    $puedeBorrar = $definicionBorrado !== null && auth()->user()?->can($definicionBorrado['modulo'].'.borrar');
@endphp
@if ($puedeBorrar)
    @if ($compacto ?? false)
        <button type="button" class="btn-icono borrar-definitivo" title="Eliminar definitivamente"
                aria-label="Eliminar definitivamente {{ $nombre ?? '' }}"
                data-borrar-definitivo data-registro="{{ $registro }}" data-id="{{ $id ?? '' }}" data-url="{{ url('borrar/'.$registro) }}">
            <i class="bi bi-trash3" aria-hidden="true"></i>
        </button>
    @else
        <div class="zona-borrar-definitivo">
            <button type="button" class="btn-borrar-definitivo" data-borrar-definitivo data-registro="{{ $registro }}"
                    data-id="{{ $id ?? '' }}" data-url="{{ url('borrar/'.$registro) }}">
                <i class="bi bi-trash3" aria-hidden="true"></i> Eliminar definitivamente
            </button>
        </div>
    @endif

    @once
        @push('scripts')
            <dialog id="dialogoBorrarDefinitivo" class="dialogo dialogo-borrar" aria-labelledby="tituloBorrarDefinitivo">
                <div class="dialogo-cabecera">
                    <h2 id="tituloBorrarDefinitivo"><i class="bi bi-trash3 me-2 text-danger" aria-hidden="true"></i>Eliminar definitivamente</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <p class="text-muted m-0" data-borrar-paso="cargando" role="status">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Revisando si algo lo usa…
                    </p>

                    <div class="alert alert-danger m-0" data-borrar-paso="error" role="alert" hidden>
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <span data-borrar-error></span>
                    </div>

                    {{-- Algo depende del registro: no se borra; se ofrece la baja --}}
                    <div data-borrar-paso="dependencias" hidden>
                        <div class="borrar-aviso borrar-aviso-bloqueado" role="alert">
                            <i class="bi bi-link-45deg" aria-hidden="true"></i>
                            <div>
                                <strong class="d-block mb-1"><span data-borrar-tipo-mayuscula></span> «<span data-borrar-nombre></span>» está en uso.</strong>
                                <span data-borrar-mensaje></span>
                            </div>
                        </div>
                        <p class="campo-ayuda mt-2 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Darlo de baja lo quita de las listas para nuevas capturas y conserva su historial. Se puede reactivar cuando quieras.</p>
                        <p class="borrar-indicacion" data-borrar-baja-texto hidden></p>
                        <form method="POST" class="m-0" data-borrar-baja-form>
                            @csrf
                            <input type="hidden" name="_method" value="PATCH" data-borrar-baja-metodo>
                            <div data-borrar-baja-campos></div>
                            <div class="dialogo-acciones">
                                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cerrar</button>
                                <button type="submit" class="btn-ambar" data-borrar-baja-boton hidden><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Dar de baja</button>
                            </div>
                        </form>
                    </div>

                    {{-- Nada lo usa: confirmar tecleando su nombre o identificador --}}
                    <form method="POST" class="m-0" data-borrar-paso="confirmar" data-borrar-form autocomplete="off" hidden>
                        @csrf
                        @method('DELETE')
                        <div class="borrar-aviso">
                            <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                            <div>
                                <strong class="d-block mb-1">Esto no se puede deshacer.</strong>
                                Se borrará <span data-borrar-tipo></span> «<span data-borrar-nombre></span>» de la base de datos. Nada lo usa todavía.
                                Solo queda una copia en la Bitácora de auditoría.
                            </div>
                        </div>
                        <label class="campo-etiqueta mt-3" for="borrarConfirmacion">Para confirmar escribe: <span class="borrar-clave" data-borrar-confirmar></span></label>
                        <input type="text" id="borrarConfirmacion" name="confirmacion" class="campo" maxlength="200" required
                               autocomplete="off" autocapitalize="off" spellcheck="false" data-borrar-entrada>
                        <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> No importan mayúsculas ni minúsculas.</p>
                        <div class="alert alert-danger small mb-0" data-borrar-error-form role="alert" hidden></div>
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-rojo" data-borrar-enviar disabled><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Eliminar</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endpush
    @endonce
@endif
