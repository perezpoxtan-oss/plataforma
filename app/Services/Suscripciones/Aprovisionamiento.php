<?php

namespace App\Services\Suscripciones;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Promocion;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\SolicitudCompra;
use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use App\Models\SuscripcionHistorial;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Arranque\AsistenteArranque;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Comercial\Calculadora;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Cuenta\RecuperacionContrasena;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\DependenciasModulos;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aprovisionamiento de clientes: el ÚNICO punto de entrada para activar,
 * cambiar, renovar, suspender y reactivar suscripciones. Lo usan el botón
 * «Activar cliente» / «Marcar como pagada» del Super Administrador y, cuando
 * se conecte una pasarela en línea, su aviso de pago (webhook): mismo
 * resultado por cualquier camino.
 *
 * Todo es idempotente: activar dos veces la misma solicitud (doble clic, la
 * pasarela que reintenta) devuelve la misma suscripción sin duplicar empresa,
 * usuario ni usos de la promoción. Cambiar el plan recalcula empresa_modulos
 * sin borrar nada: lo apagado solo se oculta.
 */
class Aprovisionamiento
{
    /** Datos de ejemplo: crear al Administrador sin mandarle el correo de bienvenida. */
    public bool $enviarBienvenida = true;

    public function __construct(
        private readonly ProvisionarEmpresa $provisionar,
        private readonly AjustesComerciales $ajustes,
        private readonly Calculadora $calculadora,
        private readonly ModulosEfectivos $modulos,
        private readonly Promociones $promociones,
        private readonly AvisosSuscripcion $avisos,
        private readonly RecuperacionContrasena $recuperacion,
        private readonly CentroNotificaciones $centro,
        private readonly Tenant $tenant,
    ) {}

    // ------------------------------------------------------------------ Entradas

    /**
     * La solicitud quedó PAGADA (a mano hoy; con la pasarela, su aviso de pago).
     *  - Cliente nuevo: se activa solo y queda en «Clientes nuevos por revisar».
     *  - Cliente que ya existe (renovar, ampliar o una prueba que ahora paga): se registra el pago.
     */
    public function confirmarPago(SolicitudCompra $solicitud, ?User $actor, ?string $referencia = null): Suscripcion
    {
        return $this->tenant->conEmpresa(null, fn () => DB::transaction(function () use ($solicitud, $actor, $referencia) {
            /** @var SolicitudCompra $s */
            $s = SolicitudCompra::query()->lockForUpdate()->findOrFail($solicitud->id);
            if ($s->estado === 'pagada' && $s->activada_en !== null && ($ya = $this->deSolicitud($s)) !== null) {
                return $ya; // ya se procesó (doble clic o aviso repetido)
            }

            $suscripcion = $s->empresa_id !== null ? Suscripcion::where('empresa_id', $s->empresa_id)->first() : null;
            if ($suscripcion === null) {
                $suscripcion = $this->activarCliente($s, $actor, prueba: false);
            } else {
                if ($s->tipo === 'ampliacion') {
                    $this->aplicarSolicitud($suscripcion, $s, $actor);
                }
                $vencida = $suscripcion->es_prueba || $suscripcion->diasParaVencer() < 0;
                if ($s->tipo !== 'ampliacion' || $vencida) {
                    $this->registrarPago($suscripcion, $actor, $referencia ?? $s->referencia_pago, $s);
                }
            }

            $s->forceFill([
                'estado' => 'pagada',
                'referencia_pago' => $referencia ?? $s->referencia_pago,
                'empresa_id' => $suscripcion->empresa_id,
                'activada_en' => now(),
                'atendida_en' => $s->atendida_en ?? now(),
            ])->save();
            $this->centro->resolver('solicitud_compra', $s->id);

            return $suscripcion->fresh();
        }));
    }

    /**
     * Prueba gratis: NO se activa sola. El Super Administrador la aprueba con «Activar prueba».
     */
    public function activarPrueba(SolicitudCompra $solicitud, User $actor): Suscripcion
    {
        return $this->tenant->conEmpresa(null, fn () => DB::transaction(function () use ($solicitud, $actor) {
            $s = SolicitudCompra::query()->lockForUpdate()->findOrFail($solicitud->id);
            if ($s->activada_en !== null && ($ya = $this->deSolicitud($s)) !== null) {
                return $ya;
            }
            if ($s->tipo !== 'prueba') {
                throw new DomainException('Esta solicitud no es de prueba gratis: márcala como pagada para activarla.');
            }
            if ($s->estado === 'cancelada') {
                throw new DomainException('La solicitud está cancelada: cámbiala a «Nueva» si quieres activarla.');
            }
            $suscripcion = $this->activarCliente($s, $actor, prueba: true);
            $s->forceFill(['estado' => 'en_prueba', 'empresa_id' => $suscripcion->empresa_id, 'activada_en' => now(), 'atendida_en' => $s->atendida_en ?? now()])->save();
            $this->centro->resolver('solicitud_compra', $s->id);

            return $suscripcion->fresh();
        }));
    }

    // ------------------------------------------------------------------ Alta del cliente

    private function activarCliente(SolicitudCompra $s, ?User $actor, bool $prueba): Suscripcion
    {
        // C-05: el giro que eligió al contratar (las solicitudes de antes no lo tienen: hotel, como siempre)
        $rubro = ($s->rubro_id ? Rubro::where('activo', true)->find($s->rubro_id) : null)
            ?? Rubro::where('clave', 'hotel')->where('activo', true)->first() ?? Rubro::where('activo', true)->orderBy('id')->firstOrFail();
        $nombre = trim((string) ($s->empresa ?: $s->nombre));
        $empresa = $this->provisionar->crear($rubro, ['nombre_comercial' => mb_substr($nombre, 0, 150), 'razon_social' => mb_substr($nombre, 0, 200)], []);
        AsistenteArranque::marcarNueva($empresa); // «Configura tu empresa» aparece en su Inicio

        $hoy = Suscripcion::hoy();
        $diasPrueba = (int) $this->ajustes->get('dias_prueba');
        $meses = $s->periodicidad === 'anual' ? 12 : 1;
        $vence = $prueba ? $hoy->copy()->addDays($diasPrueba - 1) : $this->finDePeriodo($hoy, $meses);
        $plan = $s->plan_id !== null ? Plan::find($s->plan_id) : null;
        $promocion = $s->promocion_id !== null ? Promocion::find($s->promocion_id) : null;

        $suscripcion = Suscripcion::create([
            'empresa_id' => $empresa->id,
            'plan_id' => $plan?->id,
            'solicitud_compra_id' => $s->id,
            'sedes_contratadas' => max(1, (int) ($s->detalle['sedes_cobradas'] ?? $s->sedes)),
            'periodicidad' => $s->periodicidad === 'anual' ? 'anual' : 'mensual',
            'moneda' => $s->moneda,
            'iva_porcentaje' => $s->iva_porcentaje,
            'precio_subtotal' => $s->periodo_subtotal,
            'precio_iva' => $s->periodo_iva,
            'precio_total' => $s->periodo_total,
            'detalle' => ($s->detalle ?? []) + ['usuarios_por_sede' => $plan?->usuarios_por_sede, 'meses' => $meses],
            'promocion_id' => $promocion?->id,
            'promocion_hasta' => ! $prueba && $promocion?->meses !== null ? $this->finDePeriodo($hoy, min($meses === 12 ? 12 : 999, (int) $promocion->meses)) : null,
            'estado' => $prueba ? Suscripcion::PRUEBA : Suscripcion::ACTIVA,
            'es_prueba' => $prueba,
            'inicio_en' => $hoy,
            'vence_en' => $vence,
            'gracia_hasta' => $vence->copy()->addDays((int) $this->ajustes->get('dias_gracia')),
        ]);
        // Prueba aprobada por el Super Administrador: ya la revisó. Pagada: queda por revisar.
        if ($prueba && $actor !== null) {
            $suscripcion->forceFill(['revisado_en' => now(), 'revisado_por' => $actor->id])->save();
        }
        $paquetes = collect($s->detalle['renglones'] ?? [])->where('tipo', 'paquete')->pluck('id')->filter()->all();
        $suscripcion->paquetes()->sync(Paquete::whereIn('id', $paquetes)->pluck('id'));
        if ($promocion !== null) {
            $this->promociones->contarUso($promocion);
        }

        $modulos = $this->modulos->sincronizar($suscripcion);
        $this->tenant->conEmpresa($empresa->id, fn () => Sede::create(['codigo' => 'S1', 'nombre' => 'Sede principal']));
        [$admin, $enviado, $nota] = $this->crearAdministrador($empresa, $s, $actor);

        $estado = $suscripcion->estado;
        $this->historial($suscripcion, $prueba ? 'prueba_activada' : 'activada', null, $estado,
            ($prueba ? "Prueba gratis de {$diasPrueba} días" : 'Activada al pagar').' desde la solicitud '.$s->folio.'. '.$nota,
            ['solicitud' => $s->folio, 'modulos' => $modulos['despues'], 'administrador' => $admin?->username, 'bienvenida_enviada' => $enviado], $actor);
        $this->auditar($actor, 'suscripciones.activada', $suscripcion, null, ['empresa' => $empresa->nombre_comercial, 'estado' => $estado, 'solicitud' => $s->folio]);

        if (! $prueba) {
            $this->avisos->alSuperadmin($suscripcion, 'cliente_por_revisar', "Cliente nuevo por revisar: {$empresa->nombre_comercial}",
                "Pagó la solicitud {$s->folio} y ya está activo (".($plan?->nombre ?? 'sin plan').", {$suscripcion->sedes_contratadas} ".($suscripcion->sedes_contratadas === 1 ? 'sede' : 'sedes').'). Revisa sus datos y márcalo como revisado.');
        }

        return $suscripcion;
    }

    /**
     * Administrador del cliente con enlace de bienvenida (la persona crea su contraseña).
     *
     * @return array{0: ?User, 1: bool, 2: string}
     */
    private function crearAdministrador(Empresa $empresa, SolicitudCompra $s, ?User $actor): array
    {
        $correo = mb_strtolower(trim($s->correo));
        if (User::where('email', $correo)->exists()) {
            return [null, false, "El correo {$correo} ya tiene una cuenta en la plataforma: da de alta al Administrador con otro correo en Usuarios."];
        }
        $rol = Rol::where('empresa_id', $empresa->id)->where('nombre', 'Administrador')->first()
            ?? Rol::where('empresa_id', $empresa->id)->orderByDesc('nivel_jerarquia')->first();
        if ($rol === null) {
            return [null, false, 'La empresa no tiene el rol Administrador: da de alta al Administrador en Usuarios.'];
        }
        $base = Str::of(Str::before($correo, '@'))->lower()->ascii()->replaceMatches('/[^a-z0-9._-]/', '')->limit(40, '')->value() ?: 'administrador';
        $usuario = $base;
        $n = 2;
        while (User::where('username', $usuario)->exists()) {
            $usuario = $base.$n++;
        }

        $cuenta = new User;
        $cuenta->fill(['name' => mb_substr($s->nombre, 0, 150), 'username' => $usuario, 'email' => $correo, 'password' => Str::password(40), 'activo' => true]);
        $cuenta->empresa_id = $empresa->id;
        $cuenta->forceFill(['creado_por' => $actor?->id, 'actualizado_por' => $actor?->id, 'debe_cambiar_contrasena' => true])->save();
        UsuarioRol::create(['user_id' => $cuenta->id, 'rol_id' => $rol->id, 'sede_id' => null]);
        $enviado = $this->enviarBienvenida && $this->recuperacion->enviarEnlace($cuenta, RecuperacionContrasena::BIENVENIDA, $actor);

        return [$cuenta, $enviado, $enviado
            ? "Administrador «{$usuario}» creado; le enviamos el correo de bienvenida."
            : "Administrador «{$usuario}» creado, pero el correo de bienvenida no salió: envíaselo con «Restablecer contraseña» en Usuarios."];
    }

    // ------------------------------------------------------------------ Cambios

    /**
     * Cambiar plan, paquetes, sedes o periodicidad: precio con los precios de hoy
     * (y la promoción que tenga, si sigue en sus meses) y módulos recalculados.
     *
     * @param  list<int>  $paquetes
     */
    public function cambiarPlan(Suscripcion $s, ?Plan $plan, int $sedes, array $paquetes, string $periodicidad, ?User $actor, string $motivo = ''): Suscripcion
    {
        $antes = $this->foto($s);
        $paquetesModelo = Paquete::whereIn('id', $paquetes)->with('planes:id')->get();
        $promocion = $s->promocion_id !== null && ($s->promocion_hasta === null || $s->promocion_hasta->gte(Suscripcion::hoy())) ? $s->promocion : null;
        $calculo = $this->calculadora->calcular($plan, $sedes, $paquetesModelo, $periodicidad, $promocion?->descriptor());
        if ($calculo['incompatibles'] !== []) {
            throw new DomainException('No se puede sumar al plan elegido: '.implode(', ', $calculo['incompatibles']).'.');
        }

        DB::transaction(function () use ($s, $plan, $calculo, $paquetesModelo) {
            $s->fill([
                'plan_id' => $plan?->id,
                'sedes_contratadas' => $calculo['sedes_cobradas'],
                'periodicidad' => $calculo['periodicidad'],
            ] + $this->precio($calculo, $plan))->save();
            $s->paquetes()->sync($paquetesModelo->pluck('id'));
        });
        $s->unsetRelation('plan')->unsetRelation('paquetes');
        $modulos = $this->modulos->sincronizar($s);

        $this->historial($s, 'plan_cambiado', $s->estado, $s->estado, trim('Plan, sedes o periodicidad cambiados. '.$motivo),
            ['antes' => $antes, 'despues' => $this->foto($s), 'modulos' => $modulos['despues']], $actor);
        $this->auditar($actor, 'suscripciones.plan_cambiado', $s, $antes, $this->foto($s));

        return $s->fresh();
    }

    /** Ampliación pagada: el plan, sedes y paquetes de la solicitud con el precio que se le cotizó. */
    private function aplicarSolicitud(Suscripcion $s, SolicitudCompra $sol, ?User $actor): void
    {
        $antes = $this->foto($s);
        $plan = $sol->plan_id !== null ? Plan::find($sol->plan_id) : $s->plan;
        $s->fill([
            'plan_id' => $plan?->id,
            'sedes_contratadas' => max(1, (int) ($sol->detalle['sedes_cobradas'] ?? $sol->sedes)),
            'periodicidad' => $sol->periodicidad === 'anual' ? 'anual' : 'mensual',
            'precio_subtotal' => $sol->periodo_subtotal, 'precio_iva' => $sol->periodo_iva, 'precio_total' => $sol->periodo_total,
            'detalle' => ($sol->detalle ?? []) + ['usuarios_por_sede' => $plan?->usuarios_por_sede, 'meses' => $sol->periodicidad === 'anual' ? 12 : 1],
        ])->save();
        $s->paquetes()->sync(Paquete::whereIn('id', collect($sol->detalle['renglones'] ?? [])->where('tipo', 'paquete')->pluck('id')->filter())->pluck('id'));
        $s->unsetRelation('plan')->unsetRelation('paquetes');
        $modulos = $this->modulos->sincronizar($s);
        $this->historial($s, 'plan_cambiado', $s->estado, $s->estado, 'Ampliación pagada (solicitud '.$sol->folio.').',
            ['antes' => $antes, 'despues' => $this->foto($s), 'modulos' => $modulos['despues']], $actor);
    }

    /**
     * Pago registrado: un periodo más desde el vencimiento (o desde hoy si ya
     * venció). Sale de la prueba, de la gracia y de solo consulta.
     */
    public function registrarPago(Suscripcion $s, ?User $actor, ?string $referencia = null, ?SolicitudCompra $solicitud = null): Suscripcion
    {
        $antes = $s->estadoEfectivo(null, $this->ajustes->diasPorVencer());
        $meses = $s->periodicidad === 'anual' ? 12 : 1;
        $hoy = Suscripcion::hoy();
        $inicio = ! $s->es_prueba && $s->vence_en->gte($hoy) ? $s->vence_en->copy()->addDay() : $hoy;
        $vence = $this->finDePeriodo($inicio, $meses);
        $cambios = [
            'vence_en' => $vence,
            'gracia_hasta' => $vence->copy()->addDays((int) $this->ajustes->get('dias_gracia')),
            'es_prueba' => false,
            'suspendida' => false,
            'avisos_enviados' => [],
        ];
        // Primer pago de una prueba: los «primeros N meses» de la promoción empiezan hoy
        if ($s->es_prueba && $s->promocion_id !== null && $s->promocion?->meses !== null) {
            $cambios['promocion_hasta'] = $this->finDePeriodo($inicio, min($meses === 12 ? 12 : 999, (int) $s->promocion->meses));
            $cambios['inicio_en'] = $hoy;
        }
        // Terminó la promoción: el siguiente periodo ya va con el precio regular
        $nota = '';
        if ($s->promocion_hasta !== null && $inicio->gt($s->promocion_hasta) && isset($s->detalle['periodo_regular'])) {
            $regular = $s->detalle['periodo_regular'];
            $cambios += [
                'precio_subtotal' => number_format($regular['subtotal'] / 100, 2, '.', ''),
                'precio_iva' => number_format($regular['iva'] / 100, 2, '.', ''),
                'precio_total' => number_format($regular['total'] / 100, 2, '.', ''),
            ];
            $nota = ' Terminó la promoción: desde este periodo se cobra el precio regular.';
        }
        $s->forceFill($cambios)->save();
        $s->forceFill(['estado' => $s->estadoEfectivo(null, $this->ajustes->diasPorVencer())])->save();
        app(Autorizador::class)->olvidar();

        $evento = in_array($antes, [Suscripcion::SOLO_CONSULTA, Suscripcion::CANCELADA], true) ? 'reactivada' : 'pago_registrado';
        $this->historial($s, $evento, $antes, $s->estado,
            'Pago registrado'.($referencia ? " (referencia {$referencia})" : '').($solicitud ? " con la solicitud {$solicitud->folio}" : '').'. Vence el '.$vence->format('d/m/Y').'.'.$nota,
            ['referencia' => $referencia, 'vence_en' => $vence->toDateString()], $actor);
        $this->auditar($actor, 'suscripciones.pago_registrado', $s, ['estado' => $antes], ['estado' => $s->estado, 'vence_en' => $vence->toDateString(), 'referencia' => $referencia]);
        $this->avisos->alCliente($s, 'Recibimos tu pago', 'Tu suscripción está activa hasta el '.$vence->format('d/m/Y').'. Gracias.');

        return $s->fresh();
    }

    /** «Dar de baja»: solo consulta a mano (por ejemplo, falta de pago acordada). Se deshace con «Reactivar». */
    public function suspender(Suscripcion $s, User $actor, string $motivo): Suscripcion
    {
        $antes = $s->estadoEfectivo(null, $this->ajustes->diasPorVencer());
        $s->forceFill(['suspendida' => true, 'estado' => Suscripcion::SOLO_CONSULTA])->save();
        app(Autorizador::class)->olvidar(); // solo consulta: los permisos de escritura se ocultan (A-17)
        $this->historial($s, 'suspendida', $antes, Suscripcion::SOLO_CONSULTA, 'Pasó a solo consulta a mano: '.$motivo, ['motivo' => $motivo], $actor);
        $this->auditar($actor, 'suscripciones.suspendida', $s, ['estado' => $antes], ['estado' => Suscripcion::SOLO_CONSULTA, 'motivo' => $motivo]);
        $this->avisos->alCliente($s, 'Tu suscripción pasó a solo consulta',
            'Puedes ver y exportar tu información, pero no registrar nada nuevo. Para reactivarla contacta a '.$this->ajustes->contacto().'.');

        return $s->fresh();
    }

    /** Quita la suspensión o la baja. Si su vigencia ya terminó, hay que registrar el pago. */
    public function reactivar(Suscripcion $s, User $actor, string $motivo = ''): Suscripcion
    {
        $antes = $s->estadoEfectivo(null, $this->ajustes->diasPorVencer());
        $probar = $s->replicate()->forceFill(['suspendida' => false, 'estado' => Suscripcion::ACTIVA, 'es_prueba' => $s->es_prueba]);
        if ($probar->estadoEfectivo(null, $this->ajustes->diasPorVencer()) === Suscripcion::SOLO_CONSULTA) {
            throw new DomainException('Su vigencia terminó el '.$s->vence_en->format('d/m/Y').': registra el pago para reactivarla.');
        }
        $s->forceFill(['suspendida' => false, 'estado' => Suscripcion::ACTIVA])->save();
        app(Autorizador::class)->olvidar();
        $s->forceFill(['estado' => $s->estadoEfectivo(null, $this->ajustes->diasPorVencer())])->save();
        $this->historial($s, 'reactivada', $antes, $s->estado, trim('Reactivada. '.$motivo), ['motivo' => $motivo], $actor);
        $this->auditar($actor, 'suscripciones.reactivada', $s, ['estado' => $antes], ['estado' => $s->estado]);

        return $s->fresh();
    }

    public function marcarRevisado(Suscripcion $s, User $actor, ?string $notas): Suscripcion
    {
        $antes = ['revisado_en' => $s->revisado_en?->toDateTimeString(), 'notas' => $s->notas];
        $s->forceFill(['revisado_en' => $s->revisado_en ?? now(), 'revisado_por' => $s->revisado_por ?? $actor->id, 'notas' => $notas])->save();
        $this->historial($s, 'revisada', $s->estado, $s->estado, 'Revisado por el Super Administrador.', [], $actor);
        $this->auditar($actor, 'suscripciones.revisada', $s, $antes, ['revisado_en' => $s->revisado_en->toDateTimeString(), 'notas' => $notas]);
        $this->centro->resolver('suscripcion_revision', $s->id);

        return $s->fresh();
    }

    public function agregarExcepcion(Suscripcion $s, User $actor, string $tipo, ?int $cantidad, ?int $moduloId, string $motivo): SuscripcionExcepcion
    {
        if (! isset(SuscripcionExcepcion::TIPOS[$tipo])) {
            throw new DomainException('Elige qué quieres ajustar.');
        }
        if (in_array($tipo, ['modulo_agregado', 'modulo_quitado'], true)) {
            $modulo = Modulo::query()->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->find($moduloId);
            if ($modulo === null) {
                throw new DomainException('Elige el módulo.');
            }
            if ($tipo === 'modulo_quitado' && $modulo->area?->clave === 'direccion') {
                throw new DomainException('Los módulos de Dirección (empresa, sedes, usuarios, roles, colaboradores, departamentos…) siempre se quedan.');
            }
            // Lista de dependencias (C-02): no se quita lo que otro módulo activo necesita
            if ($tipo === 'modulo_quitado') {
                $activos = Modulo::whereIn('id', $this->modulos->calcular($s))->pluck('nombre', 'clave');
                $necesitan = DependenciasModulos::quienesLoNecesitan($modulo->clave, $activos->keys()->all());
                if ($necesitan !== []) {
                    throw new DomainException("No se puede quitar {$modulo->nombre}: lo necesita ".collect($necesitan)->map(fn ($c) => $activos[$c])->join(', ', ' y ')
                        .'. Quita primero ese módulo o deja los dos.');
                }
            }
            $cantidad = null;
        } else {
            if ($cantidad === null || $cantidad === 0) {
                throw new DomainException('Escribe cuántos de más (o de menos, con signo menos).');
            }
            $moduloId = null;
        }
        $e = SuscripcionExcepcion::create([
            'suscripcion_id' => $s->id, 'empresa_id' => $s->empresa_id, 'tipo' => $tipo, 'cantidad' => $cantidad, 'modulo_id' => $moduloId, 'motivo' => $motivo,
        ]);
        $e->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();
        $s->unsetRelation('excepciones');
        $modulos = $tipo === 'modulo_agregado' || $tipo === 'modulo_quitado' ? $this->modulos->sincronizar($s) : null;
        $this->historial($s, 'excepcion_agregada', $s->estado, $s->estado, 'Excepción: '.$e->load('modulo')->texto().'. Motivo: '.$motivo,
            ['tipo' => $tipo, 'cantidad' => $cantidad, 'modulo' => $e->modulo?->clave, 'modulos' => $modulos['despues'] ?? null], $actor);
        $this->auditar($actor, 'suscripciones.excepcion_agregada', $s, null, ['excepcion' => $e->texto(), 'motivo' => $motivo]);

        return $e;
    }

    public function quitarExcepcion(Suscripcion $s, SuscripcionExcepcion $e, User $actor): void
    {
        abort_unless((int) $e->suscripcion_id === (int) $s->id, 404);
        if (! $e->activo) {
            return;
        }
        $e->forceFill(['activo' => false, 'dado_de_baja_en' => now(), 'dado_de_baja_por' => $actor->id])->save();
        $s->unsetRelation('excepciones');
        if (in_array($e->tipo, ['modulo_agregado', 'modulo_quitado'], true)) {
            $this->modulos->sincronizar($s);
        }
        $this->historial($s, 'excepcion_quitada', $s->estado, $s->estado, 'Excepción dada de baja: '.$e->texto().'.', ['excepcion' => $e->id], $actor);
        $this->auditar($actor, 'suscripciones.excepcion_quitada', $s, ['excepcion' => $e->texto()], null);
    }

    /** Aplicar a mano una promoción a un cliente: el precio se recalcula y se congela. */
    public function aplicarPromocion(Suscripcion $s, Promocion $promocion, User $actor): Suscripcion
    {
        $promocion->loadMissing('planes:id,nombre');
        $motivo = $promocion->motivoNoAplica($s->plan, $s->periodicidad);
        if ($motivo !== null) {
            throw new DomainException($motivo);
        }
        $antes = $this->foto($s);
        $paquetes = $s->paquetes()->with('planes:id')->get();
        $calculo = $this->calculadora->calcular($s->plan, $s->sedes_contratadas, $paquetes, $s->periodicidad, $promocion->descriptor());
        $inicio = $s->vence_en->gte(Suscripcion::hoy()) && ! $s->es_prueba ? $s->vence_en->copy()->addDay() : Suscripcion::hoy();
        $meses = $s->periodicidad === 'anual' ? 12 : 1;
        $s->fill($this->precio($calculo, $s->plan) + [
            'promocion_id' => $promocion->id,
            'promocion_hasta' => $promocion->meses === null ? null : $this->finDePeriodo($inicio, min($meses === 12 ? 12 : 999, $promocion->meses)),
        ])->save();
        $this->promociones->contarUso($promocion);
        $this->historial($s, 'promocion_aplicada', $s->estado, $s->estado, "Promoción «{$promocion->nombre}» aplicada: ".$promocion->resumen().'. Vale desde el siguiente periodo.',
            ['antes' => $antes, 'despues' => $this->foto($s)], $actor);
        $this->auditar($actor, 'suscripciones.promocion_aplicada', $s, $antes, $this->foto($s));

        return $s->fresh();
    }

    /**
     * Guarda el estado que dicen las fechas (lo llama la tarea diaria). Devuelve el estado anterior si cambió.
     */
    public function sincronizarEstado(Suscripcion $s): ?string
    {
        $efectivo = $s->estadoEfectivo(null, $this->ajustes->diasPorVencer());
        if ($efectivo === $s->estado) {
            return null;
        }
        $antes = $s->estado;
        $s->forceFill(['estado' => $efectivo])->save();
        app(Autorizador::class)->olvidar();
        $texto = match ($efectivo) {
            Suscripcion::POR_VENCER => 'Vence el '.$s->vence_en->format('d/m/Y').'.',
            Suscripcion::EN_GRACIA => 'Venció el '.$s->vence_en->format('d/m/Y').'; periodo de gracia hasta el '.$s->gracia_hasta?->format('d/m/Y').'.',
            Suscripcion::SOLO_CONSULTA => 'Terminó el periodo de gracia: pasa a solo consulta.',
            default => 'Estado según las fechas.',
        };
        $this->historial($s, 'estado_cambiado', $antes, $efectivo, $texto, [], null);

        return $antes;
    }

    // ------------------------------------------------------------------ Ayudas

    public function deSolicitud(SolicitudCompra $s): ?Suscripcion
    {
        return $s->empresa_id !== null ? Suscripcion::where('empresa_id', $s->empresa_id)->first() : Suscripcion::where('solicitud_compra_id', $s->id)->first();
    }

    /** Último día cubierto: inicio + N meses − 1 día. */
    public function finDePeriodo(CarbonInterface $inicio, int $meses): Carbon
    {
        return Carbon::instance($inicio)->copy()->startOfDay()->addMonthsNoOverflow($meses)->subDay();
    }

    /**
     * @param  array<string, mixed>  $calculo
     * @return array<string, mixed>
     */
    private function precio(array $calculo, ?Plan $plan): array
    {
        $peso = fn (int $c) => number_format($c / 100, 2, '.', '');

        return [
            'moneda' => $calculo['moneda'],
            'iva_porcentaje' => $calculo['iva_porcentaje'],
            'precio_subtotal' => $peso($calculo['periodo']['subtotal']),
            'precio_iva' => $peso($calculo['periodo']['iva']),
            'precio_total' => $peso($calculo['periodo']['total']),
            'detalle' => CatalogoPublico::detalle($calculo) + ['usuarios_por_sede' => $plan?->usuarios_por_sede, 'requiere_cotizacion' => $calculo['requiere_cotizacion']],
        ];
    }

    /** @return array<string, mixed> */
    private function foto(Suscripcion $s): array
    {
        return [
            'plan' => $s->plan_id !== null ? Plan::whereKey($s->plan_id)->value('nombre') : null,
            'sedes' => $s->sedes_contratadas,
            'periodicidad' => $s->periodicidad,
            'paquetes' => $s->paquetes()->pluck('paquetes.nombre')->all(),
            'precio_total' => (string) $s->precio_total,
        ];
    }

    /** @param  array<string, mixed>  $datos */
    public function historial(Suscripcion $s, string $evento, ?string $antes, ?string $despues, string $descripcion, array $datos, ?User $actor): void
    {
        SuscripcionHistorial::create([
            'suscripcion_id' => $s->id, 'empresa_id' => $s->empresa_id, 'evento' => $evento, 'estado_antes' => $antes, 'estado_despues' => $despues,
            'descripcion' => mb_substr($descripcion, 0, 255), 'datos' => $datos, 'user_id' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    private function auditar(?User $actor, string $evento, Suscripcion $s, ?array $antes, ?array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $s->empresa_id, 'user_id' => $actor?->id, 'evento' => $evento,
            'auditable_type' => Suscripcion::class, 'auditable_id' => $s->id, 'antes' => $antes, 'despues' => $despues, 'ip' => request()?->ip(),
        ]);
    }
}
