@extends('layouts.app')

@section('titulo', 'Configuración')

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-4">
        <div class="icono"><i class="bi bi-sliders text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Configuración</h1>
            <p>Correo, avisos, respaldos y ajustes de los módulos.</p>
        </div>
    </div>

    <div class="secciones-config">
        {{-- ================= Avisos por correo (empresa) ================= --}}
        @if ($empresa && $verConfiguracion)
            <section class="tarjeta p-4" aria-labelledby="t-avisos">
                <h2 id="t-avisos" class="h5 fw-bold"><i class="bi bi-bell me-2 text-primary" aria-hidden="true"></i>Avisos por correo de {{ $empresa->nombre_comercial }}</h2>
                <p class="small text-muted">Se envían a los usuarios con permiso para atender cada asunto, al correo de su cuenta.</p>
                @unless ($correoListo)
                    <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>El correo de la plataforma aún no está configurado: los avisos se activarán cuando el Super Administrador lo configure.</div>
                @endunless
                <form action="{{ route('configuracion.avisos') }}" method="POST">
                    @csrf
                    @method('PUT')
                    @foreach ($avisos as $clave => [$etiqueta, $porDefecto])
                        <label class="opcion-todas">
                            <input type="checkbox" name="avisos[{{ $clave }}]" value="1" @checked($empresa->aviso($clave)) @disabled(! $puedeEditar)>
                            <span>{{ $etiqueta }}</span>
                        </label>
                        @if (in_array($clave, \App\Models\Empresa::AVISOS_CON_DESTINATARIOS, true))
                            <div class="ms-4 mb-3">
                                <label class="campo-etiqueta" for="dest_{{ $clave }}">Correos que lo reciben <span class="text-lowercase fw-normal">(uno por línea o separados por coma)</span></label>
                                <textarea id="dest_{{ $clave }}" name="destinatarios[{{ $clave }}]" class="campo mb-1" rows="2" maxlength="3500" placeholder="finanzas@tuempresa.com" @disabled(! $puedeEditar)>{{ old("destinatarios.$clave", implode("\n", $empresa->destinatariosAviso($clave))) }}</textarea>
                                <p class="campo-ayuda mt-0">Si lo dejas vacío, se envía a los usuarios que pueden autorizar vales de taxi en esa sede.</p>
                            </div>
                        @endif
                        {{-- Ronda 5 (LL-04): una lista por copia del voucher con cobro --}}
                        @if ($clave === 'voucher_cobro')
                            <div class="ms-4 mb-3 destinatarios-voucher">
                                @foreach (\App\Models\Empresa::DESTINATARIOS_VOUCHER as $dest => $copia)
                                    <label class="campo-etiqueta" for="dest_{{ $dest }}">{{ $copia }} <span class="text-lowercase fw-normal">(correos, uno por línea o separados por coma)</span></label>
                                    <textarea id="dest_{{ $dest }}" name="destinatarios[{{ $dest }}]" class="campo mb-2" rows="2" maxlength="3500" placeholder="{{ \Illuminate\Support\Str::after($dest, 'voucher_') }}@tuempresa.com" @disabled(! $puedeEditar)>{{ old("destinatarios.$dest", implode("\n", $empresa->destinatariosAviso($dest))) }}</textarea>
                                @endforeach
                                <p class="campo-ayuda mt-0">El colaborador responsable no recibe copia. Si dejas las tres listas vacías, se envía a los usuarios que pueden imprimir vouchers en esa sede.</p>
                            </div>
                        @endif
                    @endforeach
                    @if ($puedeEditar)
                        <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar avisos</button>
                    @endif
                </form>
            </section>
        @endif

        {{-- ================= Lost & Found: días de resguardo (empresa) ================= --}}
        @if ($lostFound)
            <section class="tarjeta p-4 config-lost-found" id="lost-found" aria-labelledby="t-lost-found">
                <h2 id="t-lost-found" class="h5 fw-bold"><i class="bi bi-bag-fill me-2 text-warning" aria-hidden="true"></i>Lost &amp; Found: días de resguardo</h2>
                <p class="small text-muted">Cuántos días puede estar un artículo en resguardo, según su clasificación, antes de que el semáforo lo marque para atención. Aplica a todas las sedes de {{ $empresa->nombre_comercial }}.</p>
                @unless ($lostFound['puedeEditar'])
                    <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
                @endunless
                <form action="{{ route('configuracion.lost_found') }}" method="POST">
                    @csrf
                    @method('PUT')
                    @foreach (\App\Models\LostFoundArticulo::TIPOS_VALOR as $tipo => $nombre)
                        @php $guardado = $lostFound['guardados'][$tipo] ?? null; $dias = $lostFound['dias'][$tipo]; @endphp
                        <div class="umbral-card">
                            <div>
                                <label class="fw-bold" for="dias_{{ $tipo }}">{{ $nombre }}</label>
                                <div class="small text-muted">Amarillo a partir del día {{ (int) ceil($dias * 0.7) }}; rojo (vencido) desde el día {{ $dias }}.</div>
                                @if ($guardado && $guardado->updated_at)
                                    <div class="texto-traza">Editado por {{ $guardado->editor?->name ?? 'el sistema' }} · @fecha($guardado->updated_at)</div>
                                @endif
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <input type="number" id="dias_{{ $tipo }}" name="dias[{{ $tipo }}]" class="campo campo-dias-lf" min="1" max="{{ \App\Services\Novedades\ArchivoLostFound::MAX_DIAS }}" inputmode="numeric" required
                                       value="{{ old('dias.'.$tipo, $dias) }}" @disabled(! $lostFound['puedeEditar'])
                                       data-mensaje-min="Debe ser al menos 1 día: un artículo no puede vencer el mismo día que se encuentra.">
                                <span class="text-muted small">día(s)</span>
                            </div>
                        </div>
                    @endforeach
                    @if ($lostFound['puedeEditar'])
                        <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar días de resguardo</button>
                    @endif
                </form>
            </section>
        @endif
        {{-- Pases de salida: circuito de aprobación (ver docs/tecnico/pases-salida.md) --}}
        @if ($empresa && auth()->user()->can('pases_salida.configurar'))
            @include('seguridad.pases-salida._config-resumen', ['empresa' => $empresa])
        @endif
        {{-- Fin Pases de salida --}}
        {{-- Recepción de candidatos: aviso de privacidad y kiosco (ADR-0007) --}}
        @if ($empresa && auth()->user()->can('candidatos.configurar'))
            @include('rh.recepcion._ajustes', ['empresa' => $empresa])
        @endif
        {{-- Fin Recepción de candidatos --}}

        @if ($esSuperadmin)
            {{-- ================= Correo saliente (plataforma) ================= --}}
            <section class="tarjeta p-4" aria-labelledby="t-correo">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <h2 id="t-correo" class="h5 fw-bold m-0"><i class="bi bi-envelope-at me-2 text-primary" aria-hidden="true"></i>Correo de la plataforma</h2>
                    <span class="etiqueta-estado {{ $correoListo ? 'activo' : 'inactivo' }}">{{ $correoListo ? 'CONFIGURADO' : 'SIN CONFIGURAR' }}</span>
                </div>
                <p class="small text-muted mt-2">Cuenta desde la que salen los avisos. Los datos los da tu proveedor de correo (en cPanel: <em>Cuentas de correo → Conectar dispositivos</em>). Solo tú (Super Administrador) ves esta sección.</p>
                @if ($correo['ultimo_error'])
                    <div class="alert alert-danger small py-2"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Último error: {{ $correo['ultimo_error'] }}</div>
                @elseif ($correo['ultimo_envio'])
                    <p class="small text-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Último envío correcto: @fecha($correo['ultimo_envio'])</p>
                @endif

                <form action="{{ route('configuracion.correo') }}" method="POST" autocomplete="off" data-conservar-al-cerrar>
                    @csrf
                    @method('PUT')
                    <div class="campo-grupo">
                        <div style="flex:3"><label class="campo-etiqueta" for="c_host">Servidor SMTP</label><input type="text" id="c_host" name="host" class="campo" maxlength="150" placeholder="mail.tudominio.com" value="{{ old('host', $correo['host']) }}" required></div>
                        <div style="flex:1"><label class="campo-etiqueta" for="c_puerto">Puerto</label><input type="number" id="c_puerto" name="puerto" class="campo" min="1" max="65535" value="{{ old('puerto', $correo['puerto']) }}" required></div>
                    </div>
                    <label class="campo-etiqueta" for="c_cifrado">Cifrado</label>
                    <select id="c_cifrado" name="cifrado" class="campo">
                        @foreach ($cifrados as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(old('cifrado', $correo['cifrado']) === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <div class="campo-grupo">
                        <div style="flex:1"><label class="campo-etiqueta" for="c_usuario">Usuario</label><input type="text" id="c_usuario" name="usuario" class="campo" maxlength="150" placeholder="avisos@tudominio.com" value="{{ old('usuario', $correo['usuario']) }}" autocomplete="off"></div>
                        <div style="flex:1">
                            <label class="campo-etiqueta" for="c_contrasena">Contraseña</label>
                            <input type="password" id="c_contrasena" name="contrasena" class="campo" maxlength="200" autocomplete="new-password"
                                   placeholder="{{ $tieneContrasena ? '•••••••• (guardada; escribe solo para cambiarla)' : 'Contraseña de la cuenta' }}">
                        </div>
                    </div>
                    @if ($tieneContrasena)
                        <label class="small d-flex align-items-center gap-2 mb-2"><input type="checkbox" name="quitar_contrasena" value="1"> Quitar la contraseña guardada</label>
                    @endif
                    <div class="campo-grupo">
                        <div style="flex:1"><label class="campo-etiqueta" for="c_rem">Correo del remitente</label><input type="email" id="c_rem" name="remitente_correo" class="campo" maxlength="150" placeholder="avisos@tudominio.com" value="{{ old('remitente_correo', $correo['remitente_correo']) }}" required></div>
                        <div style="flex:1"><label class="campo-etiqueta" for="c_nom">Nombre del remitente <span class="text-lowercase fw-normal">(opcional)</span></label><input type="text" id="c_nom" name="remitente_nombre" class="campo" maxlength="80" placeholder="Avisos de seguridad" value="{{ old('remitente_nombre', $correo['remitente_nombre']) }}"></div>
                    </div>
                    <p class="campo-ayuda"><i class="bi bi-shield-lock" aria-hidden="true"></i> La contraseña se guarda cifrada y nunca se vuelve a mostrar.</p>
                    <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar correo</button>
                </form>

                @if ($correoListo)
                    <hr class="my-4">
                    <form action="{{ route('configuracion.correo.prueba') }}" method="POST" class="campo-grupo align-items-end">
                        @csrf
                        <div style="flex:3"><label class="campo-etiqueta" for="c_para">Enviar correo de prueba a</label><input type="email" id="c_para" name="para" class="campo mb-0" value="{{ auth()->user()->email }}" required></div>
                        <div><button type="submit" class="btn btn-outline-dark fw-bold" style="min-height:44px;border-radius:10px;"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar prueba</button></div>
                    </form>
                @endif
            </section>

            {{-- ================= Respaldos (plataforma) ================= --}}
            <section class="tarjeta p-4" aria-labelledby="t-respaldos">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <h2 id="t-respaldos" class="h5 fw-bold m-0"><i class="bi bi-database-check me-2 text-primary" aria-hidden="true"></i>Respaldos de la base de datos</h2>
                    <form action="{{ route('configuracion.respaldos') }}" method="POST" class="m-0" data-confirmar="¿Crear un respaldo ahora? Tarda unos segundos.">
                        @csrf
                        <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1rem;"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Respaldar ahora</button>
                    </form>
                </div>
                <p class="small text-muted mt-2">Se hace uno automático cada día después de las 3:00 y otro antes de instalar cada versión nueva. Se conservan {{ \App\Services\Respaldos\Respaldos::DIAS }} días. Para restaurar: descarga el archivo y en cPanel → phpMyAdmin → tu base → <em>Importar</em>.</p>

                @if ($respaldos->isEmpty())
                    <p class="text-muted small m-0">Todavía no hay respaldos.</p>
                @else
                    <div class="lista-respaldos">
                        @foreach ($respaldos as $r)
                            <div class="fila-respaldo">
                                <span><i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i><strong>@fecha($r['fecha'])</strong></span>
                                <span class="chip-catalogo">{{ ['diario' => 'Diario', 'antes-de-actualizar' => 'Antes de actualizar', 'manual' => 'Manual'][$r['motivo']] ?? $r['motivo'] }}</span>
                                <span class="small text-muted">{{ number_format($r['bytes'] / 1024, 1) }} KB</span>
                                <a href="{{ route('configuracion.respaldos.descargar', $r['archivo']) }}" class="btn-icono editar" title="Descargar" aria-label="Descargar respaldo del @fecha($r['fecha'])"><i class="bi bi-download" aria-hidden="true"></i></a>
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="campo-ayuda mt-2 mb-0"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Un respaldo contiene TODA la información (incluidos datos personales). Guárdalo en un lugar seguro; cada descarga queda en la Bitácora de auditoría.</p>
            </section>
        @elseif (! $empresa)
            <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la empresa de trabajo.</p></div>
        @endif
    </div>
</div>
@endsection
