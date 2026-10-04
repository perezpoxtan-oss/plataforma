{{-- Franja que distingue QA y desarrollo de Produccion (en Produccion no aparece) --}}
@unless (app()->isProduction())
    @php
        $version = is_file(base_path('VERSION')) ? trim(strtok((string) file_get_contents(base_path('VERSION')), "\n")) : 'local';
        $etiqueta = app()->environment('qa') ? 'Ambiente de pruebas (QA)' : 'Desarrollo';
    @endphp
    <div class="franja-ambiente" role="note">
        <i class="bi bi-cone-striped" aria-hidden="true"></i> {{ $etiqueta }} — los datos son ficticios · versión {{ $version }}
    </div>
@endunless
