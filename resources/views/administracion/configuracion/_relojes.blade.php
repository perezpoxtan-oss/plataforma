{{--
    Configuración → General → «Reloj y suplente» (C-22, DD-10). Antes «Reloj del visto bueno».
    Todo lo que espera respuesta muestra cuánto lleva y cambia de color; a las horas de
    recordatorio se le recuerda a quien debe responder y a las de suplente pasa a otra persona.
    Lo de cada módulo va en «Avanzado». Variables: $empresa, $puedeEditar.
--}}
@php
    $ajustesRelojes = app(\App\Services\Relojes\AjustesRelojes::class);
    $generales = $ajustesRelojes->generales($empresa);
    $motorRelojes = app(\App\Services\Relojes\MotorRelojes::class);
    $relojesEmpresa = $motorRelojes->relojesDe((int) $empresa->id);
    $guardadoModulos = (array) ($empresa->preferencias[\App\Services\Relojes\AjustesRelojes::CLAVE]['modulos'] ?? []);
    $rolesEmpresa = \App\Models\Rol::where('empresa_id', $empresa->id)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
    $minCaseta = \App\Support\AjustesCaseta::MIN;
    $maxCaseta = \App\Support\AjustesCaseta::MAX_HORAS;
    $distintoRelojes = $generales['ambar'] !== \App\Support\AjustesCaseta::OMISION['vobo_horas_amarillo'] || $generales['suplente'] !== \App\Support\AjustesCaseta::OMISION['vobo_horas_rojo']
        || ! $generales['encendido'] || $guardadoModulos !== [];
@endphp
<div class="tarjeta p-4 config-caseta" id="cfg-vobo">
    <h3 class="h6 fw-bold"><i class="bi bi-alarm me-2 text-primary" aria-hidden="true"></i>Reloj y suplente</h3>
    <p class="small text-muted">Todo lo que espera una respuesta (altas por verificar, vales de taxi, procedimientos, tickets, cuentas, vacantes…) muestra cuánto lleva esperando y cambia de color. A las horas del recordatorio se le recuerda a quien debe responder; a las del suplente, pasa a otra persona y ese aviso no se puede silenciar.</p>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.relojes') }}" method="POST">
        @csrf
        @method('PUT')
        <div class="campo-grupo">
            <div class="flex-fill">
                <label class="campo-etiqueta" for="cfg_vobo_horas_amarillo">Recordatorio y ámbar a las</label>
                <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_vobo_horas_amarillo" name="vobo_horas_amarillo" class="campo campo-horas-caseta" min="{{ $minCaseta['vobo_horas_amarillo'] }}" max="{{ $maxCaseta['vobo_horas_amarillo'] }}" inputmode="numeric" value="{{ old('vobo_horas_amarillo', $generales['ambar']) }}" @disabled(! $puedeEditar)><span class="small text-muted">horas de espera</span></div>
            </div>
            <div class="flex-fill">
                <label class="campo-etiqueta" for="cfg_vobo_horas_rojo">Suplente y rojo a las</label>
                <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_vobo_horas_rojo" name="vobo_horas_rojo" class="campo campo-horas-caseta" min="{{ $minCaseta['vobo_horas_rojo'] }}" max="{{ $maxCaseta['vobo_horas_rojo'] }}" inputmode="numeric" value="{{ old('vobo_horas_rojo', $generales['suplente']) }}" @disabled(! $puedeEditar)><span class="small text-muted">horas de espera</span></div>
            </div>
        </div>
        @foreach (['vobo_horas_amarillo', 'vobo_horas_rojo'] as $campo)
            @error($campo)<p class="text-danger small mb-1">{{ $message }}</p>@enderror
        @endforeach
        <label class="fila-check mt-2">
            <input type="checkbox" name="encendido" value="1" @checked(old('encendido', $generales['encendido'])) @disabled(! $puedeEditar)>
            <span class="small">Recordar y pasar al suplente (si lo apagas, el reloj y el color siguen, pero no salen avisos)</span>
        </label>
        <p class="campo-ayuda mt-1">El color siempre va con su texto («lleva 2 días»). <span class="valor-original">Valor original: 24 y 72 horas, encendido.</span></p>

        <details class="mt-2" @if ($errors->has('modulos.*')) open @endif>
            <summary class="small fw-bold">Avanzado: horas y suplente de cada módulo</summary>
            <p class="small text-muted mt-2">Deja las horas vacías para usar las generales. Algunos módulos tienen su propio reloj y se configuran donde se indica.</p>
            <table class="tabla-relojes">
                <thead><tr><th>Lo que espera</th><th>Recordatorio</th><th>Suplente</th><th>A quién pasa</th><th>Avisos</th></tr></thead>
                <tbody>
                @foreach ($relojesEmpresa as $clave => $reloj)
                    @php $fila = (array) ($guardadoModulos[$clave] ?? []); $propio = $reloj->dondeSeConfigura(); @endphp
                    <tr>
                        <td><strong>{{ $reloj->nombre() }}</strong></td>
                        @if ($propio !== null && ! isset($fila['ambar']))
                            <td colspan="2" data-etiqueta="Horas"><span class="small text-muted">{{ $propio }}</span></td>
                        @else
                            <td data-etiqueta="Recordatorio (h)"><input type="number" name="modulos[{{ $clave }}][ambar]" class="campo campo-horas-caseta" min="1" max="{{ \App\Services\Relojes\AjustesRelojes::MAX_HORAS }}" inputmode="numeric" value="{{ old('modulos.'.$clave.'.ambar', $fila['ambar'] ?? '') }}" placeholder="{{ $generales['ambar'] }}" aria-label="{{ $reloj->nombre() }}: horas del recordatorio" @disabled(! $puedeEditar)></td>
                            <td data-etiqueta="Suplente (h)"><input type="number" name="modulos[{{ $clave }}][suplente]" class="campo campo-horas-caseta" min="1" max="{{ \App\Services\Relojes\AjustesRelojes::MAX_HORAS }}" inputmode="numeric" value="{{ old('modulos.'.$clave.'.suplente', $fila['suplente'] ?? '') }}" placeholder="{{ $generales['suplente'] }}" aria-label="{{ $reloj->nombre() }}: horas para pasar al suplente" @disabled(! $puedeEditar)></td>
                        @endif
                        <td data-etiqueta="A quién pasa">
                            <select name="modulos[{{ $clave }}][rol_id]" class="campo" aria-label="{{ $reloj->nombre() }}: a quién pasa" @disabled(! $puedeEditar)>
                                <option value="">De fábrica: {{ $reloj->suplenteOmision() }}</option>
                                @foreach ($rolesEmpresa as $rol)
                                    <option value="{{ $rol->id }}" @selected((int) old('modulos.'.$clave.'.rol_id', $fila['rol_id'] ?? 0) === $rol->id)>Rol «{{ $rol->nombre }}»</option>
                                @endforeach
                            </select>
                        </td>
                        <td data-etiqueta="Avisos">
                            <input type="hidden" name="modulos[{{ $clave }}][encendido]" value="0">
                            <label class="fila-check"><input type="checkbox" name="modulos[{{ $clave }}][encendido]" value="1" @checked($fila['encendido'] ?? true) @disabled(! $puedeEditar)><span class="small">Encendido</span></label>
                        </td>
                    </tr>
                    @foreach (['ambar', 'suplente', 'rol_id'] as $campo)
                        @error('modulos.'.$clave.'.'.$campo)<tr><td colspan="5"><p class="text-danger small mb-1">{{ $message }}</p></td></tr>@enderror
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </details>

        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2 btn-guardar-caseta">Guardar</button>
        @endif
    </form>
    @if ($puedeEditar && $distintoRelojes)
        @include('administracion.configuracion._restablecer', ['ajuste' => 'vobo', 'que' => '«Reloj y suplente»'])
    @endif
</div>
