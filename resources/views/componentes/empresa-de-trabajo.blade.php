{{--
    Solo el Super Administrador (ADR-0016): en qué empresa está parado, en
    todas las pantallas (no solo en las de Estructura). Lleva a Empresas,
    donde la ficha de cada empresa y el selector permiten cambiarla.
--}}
@if ($usuario->es_superadmin)
    @php
        $trabajoId = app(\App\Support\Tenancy\EmpresaDeTrabajo::class)->id($usuario);
        $trabajoNombre = $trabajoId === null ? 'Plantillas de la plataforma' : (\App\Models\Empresa::whereKey($trabajoId)->value('nombre_comercial') ?? 'Empresa');
    @endphp
    <a href="{{ route('empresas.index') }}{{ $trabajoId ? '#empresa-'.$trabajoId : '' }}" class="chip-empresa-trabajo {{ $clase ?? '' }}" title="Empresa de trabajo: {{ $trabajoNombre }}. Cámbiala con el selector «Empresa de trabajo».">
        <i class="bi bi-buildings" aria-hidden="true"></i><span>{{ $trabajoNombre }}</span>
    </a>
@endif
