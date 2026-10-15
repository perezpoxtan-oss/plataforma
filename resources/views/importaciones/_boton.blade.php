{{-- Importar desde Excel: botón de la lista (solo con «modulo.importar»). Parámetros: $tipo, $modulo --}}
@can($modulo.'.importar')
    <a href="{{ route('importaciones.create', $tipo) }}" class="btn-importar" title="Cargar muchos registros de una vez desde un archivo de Excel">
        <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i><span>Importar</span>
    </a>
@endcan
