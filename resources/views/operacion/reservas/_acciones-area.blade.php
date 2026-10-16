{{--
    Botones de una ficha de área reservable (Configuración y fichas por sede/zona):
    Editar (lápiz, como Zonas y edificios) y Eliminar de reservas (bote de basura).
    La confirmación dice exactamente qué pasará según sus reservas ($resumen de
    CatalogosReservas::resumenParaEliminar): con próximas no se elimina; con
    pasadas se archiva con su historial; sin reservas se borra su configuración.
    Nunca toca Zonas y edificios. Parámetros: $a (ReservaArea), $resumen, $volver ('sede' o null)
--}}
@php
    $resumen = $resumen ?? ['proximas' => 0, 'historial' => false];
    $conInteriores = $a->porInteriores() ? ' y sus espacios interiores' : '';
    $confirmar = match (true) {
        $resumen['proximas'] > 0 => '«'.$a->nombre().'»'.$conInteriores.' '.($resumen['proximas'] === 1 ? 'tiene 1 reserva próxima' : 'tiene '.$resumen['proximas'].' reservas próximas')
            .', así que todavía no se puede eliminar de reservas: primero cancélalas o reprográmalas. ¿Continuar para ver el aviso?',
        $resumen['historial'] => '¿Eliminar «'.$a->nombre().'»'.$conInteriores.' de reservas? Ya tiene reservas pasadas: dejará de verse y de ofrecerse, pero guardamos su historial y su configuración (la ves con «Mostrar quitadas» y la puedes reactivar). El espacio sigue en Zonas y edificios.',
        default => '¿Eliminar «'.$a->nombre().'»'.$conInteriores.' de reservas? Nunca se ha reservado: se borra su configuración (aforo, montajes, horario, tarifas y foto). El espacio sigue en Zonas y edificios.',
    };
@endphp
<a href="{{ route('reservas.configuracion.area', $a->id) }}" class="btn-icono editar" title="Editar" aria-label="Editar {{ $a->nombre() }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
<form method="POST" action="{{ route('reservas.configuracion.area.quitar', $a->id) }}" class="m-0" data-confirmar="{{ $confirmar }}">
    @csrf
    @method('DELETE')
    @if (($volver ?? null) === 'sede')<input type="hidden" name="volver" value="sede">@endif
    <button type="submit" class="btn-icono eliminar" title="Eliminar de reservas" aria-label="Eliminar {{ $a->nombre() }} de reservas"><i class="bi bi-trash3" aria-hidden="true"></i></button>
</form>
