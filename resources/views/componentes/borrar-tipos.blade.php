{{--
    Tipos (de gafete, de equipo) que se capturaron por error con "+ Nuevo tipo...":
    lista plegada, solo para quien tiene "<modulo>.borrar", con los tipos que no
    usa ningún registro de la lista. El servidor vuelve a revisar al eliminar.

    @include('componentes.borrar-tipos', ['registro' => 'tipos_gafete', 'tipos' => $tipos, 'conteo' => $conteoTipo, 'titulo' => 'tipos de gafete'])
--}}
@php
    $definicionTipos = \App\Services\Borrado\RegistroBorrado::de($registro);
    $sinUso = $tipos->reject(fn ($t) => ($conteo[$t->id] ?? 0) > 0);
@endphp
@if ($definicionTipos !== null && $sinUso->isNotEmpty() && auth()->user()?->can($definicionTipos['modulo'].'.borrar'))
    <details class="tipos-borrables">
        <summary><i class="bi bi-trash3" aria-hidden="true"></i> Eliminar {{ $titulo }} sin usar ({{ $sinUso->count() }})</summary>
        <p class="campo-ayuda mt-1 mb-2">Para los que se capturaron por error con «+ Nuevo tipo...». Solo se eliminan si ningún registro los usa.</p>
        <ul>
            @foreach ($sinUso as $t)
                <li>
                    <span>{{ $t->nombre }}</span>
                    @include('componentes.borrar', ['registro' => $registro, 'id' => $t->id, 'compacto' => true, 'nombre' => $t->nombre])
                </li>
            @endforeach
        </ul>
    </details>
@endif
