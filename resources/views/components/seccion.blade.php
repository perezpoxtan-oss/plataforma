{{--
    Ronda 8 (NV-06): sección plegable de un formulario largo.

    <x-seccion clave="robo-sospechoso" :abierta="true">
        <x-slot:titulo><i class="bi bi-person me-2" aria-hidden="true"></i>2. Sospechoso</x-slot:titulo>
        …campos…
    </x-seccion>

    - <details>/<summary> nativo: funciona sin JavaScript y con teclado.
    - clave: identifica la sección; el navegador recuerda si quedó abierta o
      cerrada (por usuario y por formulario, ver public/js/plataforma.js,
      bloque «Ronda 8»).
    - abierta: cómo aparece la primera vez (la primera sección del formulario).
    - Se abre sola si alguno de sus campos trae un error de validación (aquí
      en el servidor) o si el navegador marca un campo obligatorio vacío.
--}}
@props(['clave', 'abierta' => false, 'titulo'])
@php
    // ¿Algún campo de esta sección tiene error? (nombres "a.0.b" → name="a[0][b]")
    $conError = false;
    if (isset($errors) && $errors->any()) {
        $html = (string) $slot;
        foreach ($errors->keys() as $campo) {
            $partes = explode('.', $campo);
            $nombre = array_shift($partes).implode('', array_map(fn ($p) => '['.$p.']', $partes));
            if (str_contains($html, 'name="'.e($nombre).'"')) {
                $conError = true;
                break;
            }
        }
    }
@endphp
<details {{ $attributes->merge(['class' => 'seccion-plegable']) }} data-seccion="{{ $clave }}" @if ($abierta || $conError) open @endif @if ($conError) data-seccion-con-error @endif>
    <summary class="seccion-plegable-titulo">
        <span class="seccion-plegable-texto">{{ $titulo }}</span>
        @if ($conError)<span class="seccion-plegable-aviso"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> Revisar</span>@endif
        <i class="bi bi-chevron-down seccion-plegable-flecha" aria-hidden="true"></i>
    </summary>
    <div class="seccion-plegable-cuerpo">
        {{ $slot }}
    </div>
</details>
