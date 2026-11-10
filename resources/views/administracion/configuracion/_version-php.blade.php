{{-- Versión de PHP del servidor (solo Super Administrador; App\Services\Plataforma\VersionPhp) --}}
@php
    $requiere = ! $php['web']['al_dia'] || ($php['cron'] !== null && ! $php['cron']['al_dia']);
@endphp
<section class="tarjeta p-4 mt-3 version-php" id="cfg-php" aria-labelledby="t-cfg-php">
    <h3 id="t-cfg-php" class="h6 fw-bold"><i class="bi bi-cpu me-2 text-primary" aria-hidden="true"></i>Versión de PHP del servidor</h3>
    <p class="small text-muted">PHP es el programa del servidor que hace funcionar la plataforma. Solo tú (Super Administrador) ves estos datos.</p>
    <dl class="datos-php mb-0">
        @foreach ([['Páginas (web)', $php['web']], ['Tareas programadas', $php['cron']]] as [$nombre, $v])
            <div class="fila-php">
                <dt>{{ $nombre }}</dt>
                <dd>
                    @if ($v === null)
                        <span class="text-muted">Sin dato todavía</span>
                    @else
                        <strong class="me-2">PHP {{ $v['version'] }}</strong>
                        @if ($v['al_dia'])
                            <span class="estado-php al-dia"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Al día</span>
                        @else
                            <span class="estado-php requiere"><i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i>Requiere actualización</span>
                        @endif
                    @endif
                </dd>
            </div>
        @endforeach
        <div class="fila-php">
            <dt>Aceleración (OPcache)</dt>
            <dd>{{ $php['opcache'] ? 'Activa' : 'Inactiva' }}</dd>
        </div>
        <div class="fila-php">
            <dt>Memoria máxima</dt>
            <dd>{{ $php['memoria'] }}</dd>
        </div>
        <div class="fila-php">
            <dt>Tiempo máximo por página</dt>
            <dd>{{ $php['tiempo'] }}</dd>
        </div>
    </dl>
    @if ($requiere)
        <p class="aviso-php mt-3 mb-0" role="alert"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>{{ $php['aviso'] }}</p>
    @endif
    @if ($php['cron'] === null)
        <p class="campo-ayuda mt-2 mb-0">«Tareas programadas» se muestra cuando la instalación automática del servidor anota su versión de PHP.</p>
    @endif
</section>
