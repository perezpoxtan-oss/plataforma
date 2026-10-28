@extends('layouts.app')

@section('titulo', 'Reserva '.$reserva->folioTexto())

@php
    use App\Models\Reserva;
    use App\Models\ReservaAprobacion;
    use App\Models\ReservaServicio;
    use App\Services\Reservas\Cotizador;
    $u = auth()->user();
    $navPuede = ['crear' => $u->can('reservas.crear'), 'ver' => $u->can('reservas.ver'), 'aprobar' => $u->can('reservas.aprobar'),
        'configurar' => $u->can('reservas.configurar'), 'exportar' => $u->can('reservas.exportar')];
    $local = fn ($fecha, $formato = 'd/m/Y H:i') => $fecha?->copy()->setTimezone($zona)->format($formato);
    $estadoClase = $reserva->terminada() ? 'terminada' : $reserva->estado;
    $listas = $ordenes->where('estado', 'lista')->count();
    $dialogo = old('_dialogo');
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Reserva '.$reserva->folioTexto(), 'descripcion' => $reserva->titulo, 'icono' => 'bi-calendar-event',
        'actual' => (int) $reserva->solicitante_id === (int) $u->id ? 'mias' : 'calendario', 'puede' => $navPuede])

    <div class="res-tarjeta mb-3">
        <div class="res-tarjeta-fila">
            <div>
                <span class="res-estado estado-{{ $estadoClase }}">{{ $reserva->estadoTexto() }}</span>
                <h2 class="mt-2 mb-1">{{ $reserva->titulo }}</h2>
                <div class="res-meta"><i class="bi bi-door-open" aria-hidden="true"></i> {{ $reserva->area->rutaTexto() }} · {{ $reserva->sede?->nombre }} · <strong>{{ $textos->cuando($reserva) }}</strong></div>
                @if ($reserva->estado === Reserva::CAMBIO_PEDIDO)
                    <div class="alert alert-warning small mt-2 mb-0" role="status"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                        Piden un cambio: {{ $vigentes->firstWhere('estado', ReservaAprobacion::CAMBIO)?->comentario }}.
                        @if ($puede['modificar']) Toca <strong>Modificar</strong> para corregirla y enviarla de nuevo.@endif
                    </div>
                @elseif ($reserva->estado === Reserva::CANCELADA)
                    <div class="res-meta mt-2">Cancelada por {{ $reserva->canceladaPor?->name ?? '—' }} el @fecha($reserva->cancelada_en): {{ $reserva->motivo_cancelacion }}</div>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($puede['modificar'])
                    <a href="{{ route('reservas.edit', $reserva->id) }}" class="btn-secundario-rh"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Modificar</a>
                @endif
                @if ($puede['imprimir'])
                    <a href="{{ route('reservas.imprimir', $reserva->id) }}" class="btn-secundario-rh" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir</a>
                @endif
                @if ($puede['costo'])
                    <button type="button" class="btn-secundario-rh" data-abrir-dialogo="dialogoCosto"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Ajustar costo</button>
                @endif
                @if ($puede['cancelar'])
                    <button type="button" class="btn-rojo btn-accion-rh" data-abrir-dialogo="dialogoCancelar"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>Cancelar reserva</button>
                @endif
            </div>
        </div>
    </div>

    <div class="res-ficha">
        <div class="d-grid gap-3">
            <section class="res-tarjeta" aria-labelledby="t-datos">
                <h3 id="t-datos">Datos</h3>
                <div class="res-datos">
                    <div><div class="res-dato-etiqueta">Área</div><div class="res-dato-valor">{{ $reserva->area->nombre() }}</div></div>
                    <div><div class="res-dato-etiqueta">Cuándo</div><div class="res-dato-valor">{{ $textos->cuando($reserva) }}</div></div>
                    <div><div class="res-dato-etiqueta">Montaje y desmontaje</div><div class="res-dato-valor">{{ $textos->tiemposMontaje($reserva) }}</div></div>
                    <div><div class="res-dato-etiqueta">El área queda ocupada</div><div class="res-dato-valor">{{ $local($reserva->bloqueo_desde, 'H:i') }} a {{ $local($reserva->bloqueo_hasta, 'H:i') }}</div></div>
                    <div><div class="res-dato-etiqueta">Personas</div><div class="res-dato-valor">{{ $aforo ? $reserva->asistentes.' de '.$aforo.' permitidos' : $reserva->asistentes }}
                        @if ($reserva->excede_aforo)<span class="res-estado estado-cambio_pedido ms-1">Excede aforo</span>@endif</div></div>
                    <div><div class="res-dato-etiqueta">Montaje</div><div class="res-dato-valor">{{ $reserva->montaje?->nombre ?? 'Sin montaje especial' }}</div></div>
                    <div><div class="res-dato-etiqueta">Tipo de uso</div><div class="res-dato-valor">{{ $reserva->tipoUso?->nombre ?? '—' }}</div></div>
                    <div><div class="res-dato-etiqueta">Para</div><div class="res-dato-valor">{{ $reserva->residente_id ? 'Residente: '.($reserva->residente?->nombreCompleto() ?? '—').($reserva->vivienda ? ' · '.\App\Support\Terminos::actual()->espacio().' '.$reserva->vivienda->nombre : '') : ($reserva->para_cliente ? 'Cliente: '.trim($reserva->cliente_nombre.($reserva->cliente_empresa ? ' · '.$reserva->cliente_empresa : '')) : 'Uso interno (sin costo)') }}</div></div>
                    @if ($reserva->para_cliente && ($reserva->cliente_correo || $reserva->cliente_telefono))
                        <div><div class="res-dato-etiqueta">Contacto del cliente</div><div class="res-dato-valor">{{ collect([$reserva->cliente_correo, $reserva->cliente_telefono])->filter()->join(' · ') }}</div></div>
                    @endif
                    <div><div class="res-dato-etiqueta">Solicitado por</div><div class="res-dato-valor">{{ $reserva->solicitadoPorTexto() }}</div></div>
                    <div><div class="res-dato-etiqueta">Fecha de solicitud</div><div class="res-dato-valor">@fecha($reserva->created_at)</div></div>
                </div>
                @if ($reserva->excede_aforo)
                    <div class="res-excepcion mt-3" role="note"><i class="bi bi-exclamation-triangle-fill text-warning me-1" aria-hidden="true"></i><strong>Excepción de aforo:</strong> {{ $reserva->motivo_excepcion_aforo }}</div>
                @endif
                @if ($reserva->indicaciones_montaje)
                    <div class="res-indicaciones mt-3" role="note"><i class="bi bi-megaphone-fill me-1" aria-hidden="true"></i>{{ $reserva->indicaciones_montaje }}</div>
                @endif
                @if ($reserva->notas)<p class="res-meta mt-3 mb-0"><strong>Notas:</strong> {{ $reserva->notas }}</p>@endif
            </section>

            <section class="res-tarjeta" aria-labelledby="t-servicios">
                <h3 id="t-servicios">Servicios por departamento{{ $reserva->para_cliente ? ' y costo' : '' }}</h3>
                @if ($reserva->items->isEmpty() && ! $reserva->para_cliente)
                    <p class="res-meta m-0">Sin servicios. Uso interno, sin costo.</p>
                @else
                    <div class="table-responsive">
                        <table class="res-tabla">
                            <thead><tr><th>Concepto</th><th class="num">Cantidad</th>@if ($reserva->para_cliente)<th class="num">Precio</th><th class="num">Importe</th>@endif</tr></thead>
                            <tbody>
                                @if ($reserva->para_cliente)
                                    <tr><td>Área: {{ $reserva->area->nombre() }} <span class="res-meta">({{ \App\Models\ReservaArea::TARIFAS[$reserva->tarifa][1] ?? 'sin tarifa' }}{{ $reserva->importe_area_manual !== null ? ', importe fijado' : '' }})</span></td><td class="num">1</td><td class="num"></td><td class="num">{{ Cotizador::dinero($reserva->importe_area, $reserva->moneda) }}</td></tr>
                                @endif
                                @foreach ($porDepartamento as $depto => $renglones)
                                    <tr class="res-tabla-grupo"><th colspan="{{ $reserva->para_cliente ? 4 : 2 }}">{{ $depto }}</th></tr>
                                    @foreach ($renglones as $i)
                                        <tr>
                                            <td><i class="bi bi-check2-square text-success me-1" aria-hidden="true"></i>{{ $i->nombre }}@if ($i->notas) <span class="res-nota">{{ $i->notas }}</span>@endif</td>
                                            <td class="num">{{ $i->cantidadTexto() }} {{ ReservaServicio::UNIDADES[$i->unidad][1] ?? '' }}</td>
                                            @if ($reserva->para_cliente)<td class="num">{{ Cotizador::dinero($i->costo_unitario, $reserva->moneda) }}</td><td class="num">{{ Cotizador::dinero($i->importe, $reserva->moneda) }}</td>@endif
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            @if ($reserva->para_cliente)
                                <tfoot>
                                    @if ((float) $reserva->descuento > 0)
                                        <tr><td colspan="3">{{ $reserva->cortesia ? 'Cortesía' : 'Descuento' }}{{ $reserva->ajuste_motivo ? ': '.$reserva->ajuste_motivo : '' }}</td><td class="num">− {{ Cotizador::dinero($reserva->descuento, $reserva->moneda) }}</td></tr>
                                    @endif
                                    @if ((float) $reserva->iva_porcentaje > 0)
                                        <tr><td colspan="3">IVA {{ rtrim(rtrim(number_format((float) $reserva->iva_porcentaje, 2), '0'), '.') }} %</td><td class="num">{{ Cotizador::dinero($reserva->iva, $reserva->moneda) }}</td></tr>
                                    @endif
                                    <tr><td colspan="3">Total</td><td class="num">{{ Cotizador::dinero($reserva->total, $reserva->moneda) }}</td></tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="d-grid gap-3 align-content-start">
            <section class="res-tarjeta" id="aprobaciones" aria-labelledby="t-aprobaciones">
                <h3 id="t-aprobaciones">Aprobaciones</h3>
                @if ($mostrar->isEmpty())
                    <p class="res-meta m-0"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Confirmación inmediata: esta reserva no necesitó aprobación.</p>
                @endif
                @foreach ($mostrar as $a)
                    <div class="res-aprobacion">
                        <div>
                            <div class="fw-semibold">{{ $a->tipo === 'area' ? 'Responsable del área' : $a->departamento?->nombre }}</div>
                            <div class="res-meta">{{ $designados[$a->id] ?: 'Sin responsable: quien aprueba reservas en la sede' }}</div>
                            @if ($a->respondida_en)
                                <div class="res-meta">{{ $a->respondidaPor?->name }} · @fecha($a->respondida_en){{ $a->comentario ? ' · «'.$a->comentario.'»' : '' }}</div>
                            @endif
                            @if ($relojes[$a->id] ?? null)
                                @php $r = $relojes[$a->id]; @endphp
                                <span class="reloj-vobo espera-{{ $r['nivel'] }} mt-1"><i class="bi {{ $r['nivel'] === 'normal' ? 'bi-clock' : 'bi-exclamation-triangle-fill' }}" aria-hidden="true"></i> <span>Esperando desde {{ $r['hace'] }} · <strong>{{ $r['etiqueta'] }}</strong></span></span>
                            @endif
                        </div>
                        <div class="text-end">
                            <span class="res-estado estado-{{ $a->estado }}">{{ ReservaAprobacion::ESTADOS[$a->estado] }}</span>
                            @if (in_array($a->id, $responder, true))
                                <div class="d-flex flex-wrap gap-2 mt-2 justify-content-end">
                                    <form method="POST" action="{{ route('reservas.aprobaciones.responder', $a->id) }}" class="m-0">
                                        @csrf
                                        <input type="hidden" name="respuesta" value="aprobar">
                                        <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Aprobar</button>
                                    </form>
                                    <button type="button" class="btn-rojo btn-accion-rh" data-abrir-dialogo="dialogoRechazar{{ $a->id }}"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Rechazar</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>

            @if ($ordenes->isNotEmpty())
                <section class="res-tarjeta" aria-labelledby="t-ordenes">
                    <h3 id="t-ordenes">Órdenes de servicio</h3>
                    <div class="res-meta">Montaje: {{ $listas }} de {{ $ordenes->count() }} {{ $ordenes->count() === 1 ? 'departamento listo' : 'departamentos listos' }}</div>
                    <div class="res-avance" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $ordenes->count() }}" aria-valuenow="{{ $listas }}" aria-label="Avance del montaje">
                        <span style="width: {{ $ordenes->count() ? round($listas * 100 / $ordenes->count()) : 0 }}%"></span>
                    </div>
                    @foreach ($ordenes as $o)
                        <div class="res-aprobacion">
                            <div>
                                <div class="fw-semibold">{{ $o->departamento?->nombre }}</div>
                                <div class="res-meta">{{ $textos->serviciosDe($reserva, (int) $o->departamento_id) }}</div>
                                @if ($o->lista_en)<div class="res-meta">Listo · {{ $o->listaPor?->name }} · @fecha($o->lista_en){{ $o->comentario ? ' · «'.$o->comentario.'»' : '' }}</div>@endif
                            </div>
                            <div class="text-end">
                                <span class="res-estado estado-{{ $o->estado }}">{{ $o->estado === 'lista' ? 'Listo' : 'Pendiente' }}</span>
                                @if (in_array($o->id, $atiende, true))
                                    <form method="POST" action="{{ route('reservas.ordenes.listo', $o->id) }}" class="mt-2">
                                        @csrf
                                        <input type="hidden" name="volver" value="reserva">
                                        <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Listo</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="res-tarjeta" aria-labelledby="t-historial">
                <h3 id="t-historial">Historial</h3>
                <ul class="res-historial">
                    @foreach ($reserva->eventos->take(30) as $ev)
                        <li><time>@fecha($ev->created_at)</time>{{ $ev->titulo }}</li>
                    @endforeach
                </ul>
                @if ($puede['historial'])
                    <div class="mt-2">@include('componentes.ver-historial', ['registro' => Reserva::class, 'id' => $reserva->id, 'nombre' => $reserva->folioTexto()])</div>
                @endif
            </section>
        </div>
    </div>

    {{-- ===== Diálogos ===== --}}
    @foreach ($vigentes as $a)
        @continue(! in_array($a->id, $responder, true))
        <dialog id="dialogoRechazar{{ $a->id }}" class="dialogo" aria-labelledby="t-rechazar-{{ $a->id }}" @if ($dialogo === 'rechazar'.$a->id) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="t-rechazar-{{ $a->id }}"><i class="bi bi-x-octagon me-2 text-danger" aria-hidden="true"></i>Rechazar — {{ $a->tipo === 'area' ? 'área' : $a->departamento?->nombre }}</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form method="POST" action="{{ route('reservas.aprobaciones.responder', $a->id) }}">
                    @csrf
                    <input type="hidden" name="_dialogo" value="rechazar{{ $a->id }}">
                    <fieldset class="mb-2">
                        <legend class="campo-etiqueta">¿Qué pasa con la reserva?</legend>
                        <label class="res-opcion mb-2"><input type="radio" name="respuesta" value="cambio" @checked(old('respuesta', 'cambio') === 'cambio')> <span><strong>Se puede, con un cambio</strong><br><span class="res-meta">Regresa a quien reservó para que la modifique</span></span></label>
                        <label class="res-opcion"><input type="radio" name="respuesta" value="rechazar" @checked(old('respuesta') === 'rechazar')> <span><strong>No se puede</strong><br><span class="res-meta">La reserva queda rechazada y el área libre</span></span></label>
                    </fieldset>
                    <label class="campo-etiqueta" for="comentario{{ $a->id }}">Motivo o cambio que necesitas <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea id="comentario{{ $a->id }}" name="comentario" class="campo" rows="3" maxlength="1000" required placeholder="Ej. Ese día no hay personal de cocina; cambia el coffee break a las 11:00">{{ $dialogo === 'rechazar'.$a->id ? old('comentario') : '' }}</textarea>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-confirmar-rechazo">Rechazar</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endforeach

    @if ($puede['cancelar'])
        <dialog id="dialogoCancelar" class="dialogo" aria-labelledby="t-cancelar" @if ($dialogo === 'cancelar') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="t-cancelar"><i class="bi bi-calendar-x me-2 text-danger" aria-hidden="true"></i>Cancelar {{ $reserva->folioTexto() }}</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form method="POST" action="{{ route('reservas.cancelar', $reserva->id) }}" data-confirmar="¿Cancelar la reserva {{ $reserva->folioTexto() }}? El área queda libre y avisamos a quien la aprueba o la atiende.">
                    @csrf
                    <input type="hidden" name="_dialogo" value="cancelar">
                    <label class="campo-etiqueta" for="motivo_cancelacion">¿Por qué se cancela? <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea id="motivo_cancelacion" name="motivo" class="campo" rows="3" maxlength="255" required>{{ $dialogo === 'cancelar' ? old('motivo') : '' }}</textarea>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Regresar</button>
                        <button type="submit" class="btn-confirmar-rechazo">Cancelar reserva</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif

    @if ($puede['costo'])
        <dialog id="dialogoCosto" class="dialogo" aria-labelledby="t-costo" @if ($dialogo === 'costo') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="t-costo"><i class="bi bi-cash-coin me-2 text-success" aria-hidden="true"></i>Ajustar costo</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form method="POST" action="{{ route('reservas.costo', $reserva->id) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_dialogo" value="costo">
                    <p class="small">Hoy: área {{ Cotizador::dinero($reserva->importe_area, $reserva->moneda) }} + servicios {{ Cotizador::dinero($reserva->importe_servicios, $reserva->moneda) }}. No vuelve a pedir aprobación.</p>
                    <label class="campo-etiqueta" for="importe_area_manual">Importe del área para este evento <span class="text-lowercase fw-normal">(vacío = según la tarifa)</span></label>
                    <input type="number" id="importe_area_manual" name="importe_area_manual" class="campo" min="0" step="0.01" value="{{ old('importe_area_manual', $reserva->importe_area_manual) }}">
                    <label class="campo-etiqueta mt-2" for="descuento">Descuento</label>
                    <input type="number" id="descuento" name="descuento" class="campo" min="0" step="0.01" value="{{ old('descuento', $reserva->cortesia ? '' : ((float) $reserva->descuento ?: '')) }}">
                    <label class="d-flex gap-2 align-items-center mt-2"><input type="checkbox" name="cortesia" value="1" @checked(old('cortesia', $reserva->cortesia))> Cortesía (sin costo para el cliente)</label>
                    <label class="campo-etiqueta mt-2" for="ajuste_motivo">Motivo <span class="text-lowercase fw-normal">(obligatorio si hay ajuste)</span></label>
                    <input type="text" id="ajuste_motivo" name="ajuste_motivo" class="campo" maxlength="255" value="{{ old('ajuste_motivo', $reserva->ajuste_motivo) }}" placeholder="Ej. Cliente frecuente">
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-verde">Guardar</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
</div>
@endsection
