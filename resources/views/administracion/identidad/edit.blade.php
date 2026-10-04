@extends('layouts.app')

@section('titulo', 'Identidad de la plataforma')

@php
    $v = fn (string $campo) => old($campo, $valores[$campo] ?? '');
    // Solo colores válidos entran al estilo de la vista previa
    $color = fn (string $campo) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v($campo)) ? $v($campo) : $valores[$campo];
@endphp

@section('contenido')
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-4">
        <div class="icono"><i class="bi bi-palette-fill text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Identidad de la plataforma</h1>
            <p>Nombre, colores y símbolo que ven todos los usuarios. Solo el Super Administrador puede cambiarlos.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <form action="{{ route('identidad.update') }}" method="POST" enctype="multipart/form-data" class="tarjeta p-4" autocomplete="off"
                  data-confirmar="¿Guardar la identidad? El cambio se ve de inmediato en todas las pantallas, incluida la de acceso.">
                @csrf
                @method('PUT')
                <fieldset @disabled(! $puedeEditar)>
                    <h2 class="h6 fw-bold mb-3"><i class="bi bi-type me-1 text-primary" aria-hidden="true"></i> Nombre</h2>
                    <div class="row">
                        <div class="col-md-7">
                            <label class="campo-etiqueta" for="nombre">Nombre de la plataforma</label>
                            <input type="text" id="nombre" name="nombre" class="campo" maxlength="60" value="{{ $v('nombre') }}" required data-vista-previa="nombre">
                        </div>
                        <div class="col-md-5">
                            <label class="campo-etiqueta" for="nombre_corto">Nombre corto (barra y celular)</label>
                            <input type="text" id="nombre_corto" name="nombre_corto" class="campo" maxlength="20" value="{{ $v('nombre_corto') }}" required data-vista-previa="nombre_corto">
                        </div>
                    </div>
                    <label class="campo-etiqueta" for="eslogan">Eslogan (debajo del nombre en el acceso)</label>
                    <input type="text" id="eslogan" name="eslogan" class="campo" maxlength="120" value="{{ $v('eslogan') }}" data-vista-previa="eslogan">

                    <label class="campo-etiqueta" for="titular">Titular (aparece en el pie: © {{ date('Y') }} …)</label>
                    <input type="text" id="titular" name="titular" class="campo" maxlength="60" value="{{ $v('titular') }}" data-vista-previa="titular">

                    <h2 class="h6 fw-bold mb-3 mt-2"><i class="bi bi-droplet-half me-1 text-primary" aria-hidden="true"></i> Colores</h2>
                    <div class="row">
                        @foreach (['color_primario' => 'Color principal (botones, menú activo)', 'color_acento' => 'Color de acento'] as $campo => $etiqueta)
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $campo }}">{{ $etiqueta }}</label>
                                <div class="d-flex gap-2 align-items-start">
                                    <input type="color" class="form-control form-control-color" value="{{ $color($campo) }}" aria-label="Elegir {{ $etiqueta }}" data-color-de="{{ $campo }}">
                                    <input type="text" id="{{ $campo }}" name="{{ $campo }}" class="campo" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="{{ $v($campo) }}" required data-vista-previa="{{ $campo }}">
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="campo-ayuda mb-3">Usa colores oscuros o intensos para el principal: el texto de los botones es blanco y debe leerse bien.</p>

                    <h2 class="h6 fw-bold mb-3 mt-2"><i class="bi bi-image me-1 text-primary" aria-hidden="true"></i> Imágenes</h2>
                    @foreach (['simbolo' => ['Símbolo (cuadrado, PNG/JPG/WEBP, máx. 512 KB)', 'Aparece en la barra, en el acceso y como ícono.'], 'favicon' => ['Ícono de pestaña (PNG, máx. 256 KB)', 'El pequeño ícono de la pestaña del navegador.']] as $campo => [$etiqueta, $ayuda])
                        <div class="d-flex gap-3 align-items-start mb-3">
                            <div class="vista-imagen">
                                @if ($valores[$campo])
                                    <img src="{{ asset($valores[$campo]) }}" alt="">
                                @else
                                    <i class="bi bi-image text-muted" aria-hidden="true"></i>
                                @endif
                            </div>
                            <div class="flex-grow-1">
                                <label class="campo-etiqueta" for="{{ $campo }}">{{ $etiqueta }}</label>
                                <input type="file" id="{{ $campo }}" name="{{ $campo }}" class="form-control mb-1" accept="image/png,image/jpeg,image/webp" @if ($campo === 'simbolo') data-vista-previa-imagen @endif>
                                <div class="campo-ayuda mt-0">{{ $ayuda }}</div>
                                @if ($valores[$campo])
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="quitar_{{ $campo }}" name="quitar_{{ $campo }}" value="1">
                                        <label class="form-check-label small" for="quitar_{{ $campo }}">Quitar y usar el predeterminado</label>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <h2 class="h6 fw-bold mb-3 mt-2"><i class="bi bi-life-preserver me-1 text-primary" aria-hidden="true"></i> Soporte</h2>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="correo_soporte">Correo de soporte</label>
                            <input type="email" id="correo_soporte" name="correo_soporte" class="campo" maxlength="150" value="{{ $v('correo_soporte') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="telefono_soporte">Teléfono de soporte</label>
                            <input type="text" id="telefono_soporte" name="telefono_soporte" class="campo" maxlength="30" value="{{ $v('telefono_soporte') }}">
                        </div>
                    </div>
                </fieldset>

                @if ($puedeEditar)
                    <button type="submit" class="btn-guardar-identidad"><i class="bi bi-save2-fill me-2" aria-hidden="true"></i>Guardar Identidad</button>
                @endif
            </form>
        </div>

        {{-- Vista previa en vivo (se actualiza al escribir, antes de guardar) --}}
        <div class="col-lg-5">
            <div class="vista-previa" id="vistaPrevia" style="--vp-primario: {{ $color('color_primario') }}; --vp-acento: {{ $color('color_acento') }};">
                <div class="campo-etiqueta mb-2">Vista previa</div>
                <div class="vp-barra">
                    <span class="vp-simbolo" data-vp-simbolo>
                        @if ($valores['simbolo'])<img src="{{ asset($valores['simbolo']) }}" alt="">@else<i class="bi bi-shield-lock-fill" aria-hidden="true"></i>@endif
                    </span>
                    <span class="vp-nombre-corto" data-vp="nombre_corto">{{ $v('nombre_corto') }}</span>
                    <span class="vp-menu"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i> Inicio</span>
                </div>
                <div class="vp-acceso">
                    <span class="vp-simbolo grande" data-vp-simbolo>
                        @if ($valores['simbolo'])<img src="{{ asset($valores['simbolo']) }}" alt="">@else<i class="bi bi-shield-check" aria-hidden="true"></i>@endif
                    </span>
                    <div class="vp-titulo" data-vp="nombre">{{ $v('nombre') }}</div>
                    <div class="vp-eslogan" data-vp="eslogan">{{ $v('eslogan') }}</div>
                    <div class="vp-campo"></div>
                    <div class="vp-campo"></div>
                    <div class="vp-boton">AUTENTICAR INGRESO <i class="bi bi-arrow-right-short" aria-hidden="true"></i></div>
                    <div class="vp-pie">© {{ date('Y') }} <span data-vp="titular">{{ $v('titular') }}</span></div>
                </div>
            </div>
        </div>
    </div>
@endsection
