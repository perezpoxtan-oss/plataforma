{{--
    Aviso de los diálogos de alta rápida (vehículo, persona, empresa externa)
    cuando quien registra no puede editar ese padrón: lo que registre se usa de
    inmediato, pero queda "Pendiente de verificar" (ADR-0006).
    Sin la clase "alert": el cierre genérico de diálogos borra los .alert.
--}}
@if (auth()->user() && ! app(\App\Services\Padrones\AltasPorVerificar::class)->puedeVerificar(auth()->user(), $padron))
    <p class="aviso-alta-pendiente">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <span>Lo que registres aquí se usa <strong>de inmediato</strong> y queda <strong>pendiente de verificar</strong> hasta que quien administra el padrón lo revise.</span>
    </p>
@endif
