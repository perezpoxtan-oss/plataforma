{{--
    «Mis pendientes» (junto a la campana): un botón con el total que abre una
    lista corta de lo que el usuario puede resolver (autorizaciones, firmas de
    pases, procedimientos por leer, altas por verificar). Solo existe si al
    usuario le aplica algún renglón, y se oculta mientras el total es 0.
    El número se refresca con la misma consulta de la campana (bloque
    «Mis pendientes» de plataforma.js).
--}}
@php
    $misPendientes = app(\App\Support\Menu\MisPendientes::class)->para($usuario);
    $totalPendientes = $misPendientes['total'];
@endphp
@if ($misPendientes['items'] !== [])
    <div class="mis-pendientes" data-mis-pendientes @if ($totalPendientes === 0) hidden @endif>
        <button type="button" class="btn-mis-pendientes" data-mis-pendientes-boton aria-expanded="false" aria-haspopup="true"
                title="Mis pendientes" aria-label="Mis pendientes: {{ $totalPendientes }}">
            <i class="bi bi-list-check" aria-hidden="true"></i>
            <span class="mis-pendientes-texto">Mis pendientes</span>
            <span class="mis-pendientes-contador" data-mis-pendientes-contador>{{ $totalPendientes > 99 ? '99+' : $totalPendientes }}</span>
        </button>
        <div class="mis-pendientes-panel" data-mis-pendientes-panel hidden>
            <div class="mis-pendientes-cabeza"><strong>Mis pendientes</strong><small>Lo que espera tu respuesta</small></div>
            <ul class="mis-pendientes-lista" data-mis-pendientes-lista>
                @foreach ($misPendientes['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" class="mis-pendientes-item {{ $item['total'] > 0 ? '' : 'al-dia' }}">
                            <i class="bi {{ $item['icono'] }}" aria-hidden="true"></i>
                            <span>{{ $item['titulo'] }}</span>
                            <span class="mis-pendientes-numero">{{ $item['total'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
