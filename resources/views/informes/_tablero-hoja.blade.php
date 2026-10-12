{{-- Fichas del Tablero en la hoja imprimible (sin enlaces ni diálogos) --}}
@if ($fichas === [])
    <p class="text-muted">Tu rol no puede consultar ninguno de los módulos de donde salen las fichas.</p>
@else
    <div class="cifras-informe">
        @foreach ($fichas as $f)
            <div class="cifra-informe {{ ! empty($f['nivel']) ? 'nivel-'.$f['nivel'] : '' }}">
                <span class="cifra-informe-valor">{{ $f['valor'] }}</span>
                <span class="cifra-informe-titulo"><i class="bi {{ $f['icono'] }}" aria-hidden="true"></i> {{ $f['titulo'] }}</span>
                @if ($f['nota'])<span class="cifra-informe-nota">{{ $f['nota'] }}</span>@endif
            </div>
        @endforeach
    </div>
@endif
