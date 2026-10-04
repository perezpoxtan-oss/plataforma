{{--
    Registro Rápido de Colaborador (SEGCAT: colaborador_registro_rapido_modal.php).
    Para registrar a alguien de paso sin salir de otro módulo (Pases de salida, Accesos...).

    Uso desde cualquier pantalla:
        @include('organizacion.colaboradores._registro-rapido', ['sedeSugerida' => $sedeId])
        <button type="button" data-abrir-dialogo="dialogoRegistroRapidoColaborador">Registrar colaborador</button>

    Al guardar, plataforma.js lanza en document el evento "colaborador:registrado"
    con detail = {id, num_empleado, nombre_completo, puesto, departamento, sede_id, sede, provisional}.
    Ver docs/tecnico/colaboradores.md.

    Quien tiene "colaboradores.crear" (Recursos Humanos) registra un colaborador
    normal. Quien solo tiene "colaboradores.provisional" (la caseta) hace un ALTA
    PROVISIONAL: el número de empleado es opcional, se usa de inmediato y
    Recursos Humanos la valida. Si ya hay alguien con ese nombre, se le ofrece
    usarlo antes de duplicarlo.
--}}
@php
    $tenantRapido = app(\App\Support\Tenancy\Tenant::class);
@endphp
@php
    $completoRapido = (bool) auth()->user()?->can('colaboradores.crear');
    $provisionalRapido = ! $completoRapido && (bool) auth()->user()?->can('colaboradores.provisional');
@endphp
@if (($completoRapido || $provisionalRapido) && $tenantRapido->activo())
    @php
        $catRapido = app(\App\Services\Colaboradores\AdministradorColaboradores::class)->catalogos(auth()->user(), $completoRapido ? 'colaboradores.crear' : 'colaboradores.provisional');
        $permitidasRapido = $catRapido['sedesPermitidas'];
        $sedesRapido = $catRapido['sedes']->filter(fn ($s) => $s->activo && ($permitidasRapido === null || in_array($s->id, $permitidasRapido, true)));
        $sugerida = (int) ($sedeSugerida ?? ($sedesRapido->count() === 1 ? $sedesRapido->first()->id : 0));
    @endphp
    <dialog id="dialogoRegistroRapidoColaborador" class="dialogo ancho tema-esmeralda" aria-labelledby="titulo-co-rapido">
        <div class="dialogo-cabecera">
            <h2 id="titulo-co-rapido"><i class="bi bi-person-plus-fill me-2 text-primary" aria-hidden="true"></i>{{ $provisionalRapido ? 'Alta Provisional de Colaborador' : 'Registro Rápido de Colaborador' }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            @if ($provisionalRapido)
                <div class="alert alert-warning py-2 px-3 small"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Úsalo solo si la persona <strong>no aparece</strong> al buscarla. Podrás usarla de inmediato; <strong>Recursos Humanos</strong> revisará y validará el alta.</div>
            @else
                <div class="alert alert-secondary py-2 px-3 small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Estos son los datos esenciales. Para más detalle (CURP, RFC, fecha de nacimiento...), complétalo después desde el módulo de Colaboradores.</div>
            @endif
            <form action="{{ route('colaboradores.rapido') }}" method="POST" autocomplete="off" data-registro-rapido-colaborador data-form-colaborador>
                @csrf
                <input type="hidden" name="confirmar_nuevo" value="0" data-confirmar-nuevo>
                <div class="alert alert-danger small py-2" role="alert" data-errores-rapido hidden></div>
                <div class="caja-parecidos" role="alert" data-parecidos-rapido hidden></div>
                <div class="row">
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="rapido_co_num">Número de Empleado @if ($provisionalRapido)<span class="text-lowercase fw-normal">(si lo sabe)</span>@endif</label>
                        <input type="text" id="rapido_co_num" name="num_empleado" class="campo" maxlength="20" autocapitalize="characters" @unless ($provisionalRapido) required @endunless>
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="rapido_co_sede">Sede</label>
                        <select id="rapido_co_sede" name="sede_id" class="campo" data-colab-sede required>
                            <option value="">-- Seleccionar --</option>
                            @foreach ($sedesRapido as $s)
                                <option value="{{ $s->id }}" @selected($sugerida === $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_co_nombre">Nombre(s)</label>
                        <input type="text" id="rapido_co_nombre" name="nombre" class="campo" maxlength="60" required>
                    </div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_co_paterno">Apellido Paterno</label>
                        <input type="text" id="rapido_co_paterno" name="apellido_paterno" class="campo" maxlength="60" required>
                    </div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="rapido_co_materno">Apellido Materno</label>
                        <input type="text" id="rapido_co_materno" name="apellido_materno" class="campo" maxlength="60">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="rapido_co_depto">Departamento <span class="text-lowercase fw-normal">(área)</span></label>
                        <select id="rapido_co_depto" name="departamento_id" class="campo" data-colab-depto>
                            <option value="">-- Sin Departamento --</option>
                            @foreach ($catRapido['departamentos']->where('activo', true) as $dep)
                                <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}">{{ $dep->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="rapido_co_puesto">Puesto <span class="text-lowercase fw-normal">(rango)</span></label>
                        <select id="rapido_co_puesto" name="puesto_id" class="campo" data-colab-puesto>
                            <option value="">-- Sin Puesto --</option>
                            @foreach ($catRapido['puestos']->where('activo', true) as $pu)
                                <option value="{{ $pu->id }}" data-deps="{{ $pu->departamentos->pluck('id')->join(',') }}">{{ $pu->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label class="campo-etiqueta" for="rapido_co_tel">Teléfono <span class="text-lowercase fw-normal">(opcional)</span></label>
                <input type="tel" inputmode="tel" id="rapido_co_tel" name="telefono" class="campo" maxlength="20" placeholder="10 dígitos">

                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-registro-rapido" data-texto-original="{{ $provisionalRapido ? 'Registrar provisional' : 'Registrar' }}">{{ $provisionalRapido ? 'Registrar provisional' : 'Registrar' }}</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
