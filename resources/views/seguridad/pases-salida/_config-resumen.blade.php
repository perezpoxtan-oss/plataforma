{{-- Configuración → sección "Pases de salida": resumen del circuito de aprobación y enlace para cambiarlo. $empresa --}}
@php
    $resumenCircuito = app(\App\Support\Tenancy\Tenant::class)->conEmpresa($empresa->id, fn () => app(\App\Services\PasesSalida\CircuitoPasesSalida::class)->resumen());
@endphp
<section class="tarjeta p-4" aria-labelledby="t-pases-salida">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <h2 id="t-pases-salida" class="h5 fw-bold m-0"><i class="bi bi-box-arrow-up-right me-2 text-primary" aria-hidden="true"></i>Pases de salida</h2>
        <a href="{{ route('pases-salida.circuito') }}" class="btn-azul d-inline-flex align-items-center" style="min-height:44px;border-radius:10px;padding:0 1rem;text-decoration:none;"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Configurar circuito</a>
    </div>
    <p class="small text-muted mt-2">Circuito de aprobación: quién firma cada pase y en qué orden. Los avisos por correo del circuito se encienden o apagan arriba, en «Avisos por correo».</p>
    <ol class="small mb-0">
        @foreach ($resumenCircuito as $linea)
            <li>{{ preg_replace('/^\d+\.\s*/', '', $linea) }}</li>
        @endforeach
    </ol>
</section>
