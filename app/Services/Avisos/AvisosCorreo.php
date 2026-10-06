<?php

namespace App\Services\Avisos;

use App\Mail\AltaPorVerificarRegistrada;
use App\Mail\AltaProvisionalRegistrada;
use App\Mail\AvisoPaseSalida;
use App\Mail\AvisoProcedimiento;
use App\Mail\ValeTaxiRegistrado;
use App\Mail\VoucherConCobro;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\MovimientoTransporte;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\CorreoPlataforma;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Model;

use function Illuminate\Support\defer;

/**
 * Avisos por correo. Se envían DESPUÉS de responder al usuario (defer): el
 * guardia no espera al servidor de correo, y si el correo falla la captura ya
 * quedó guardada.
 */
class AvisosCorreo
{
    public function __construct(
        private readonly CorreoPlataforma $correo,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * A quien puede validar altas provisionales (colaboradores.aprobar) en la
     * sede del colaborador.
     */
    public function altaProvisional(Colaborador $colaborador, User $registro): void
    {
        $empresa = Empresa::find($colaborador->empresa_id);
        if ($empresa === null || ! $empresa->aviso('alta_provisional') || ! $this->correo->configurado()) {
            return;
        }

        $destinatarios = $this->conPermiso($empresa->id, 'colaboradores.aprobar', $colaborador->sede_id);
        if ($destinatarios === []) {
            return;
        }

        $mensaje = new AltaProvisionalRegistrada(
            $colaborador->nombreCompleto(),
            $colaborador->sede?->nombre,
            $registro->name,
            app(HoraLocal::class)->formatear(now()),
            route('colaboradores.index', ['registro' => 'provisional']),
        );

        defer(fn () => $this->correo->enviar($destinatarios, $mensaje));
    }

    /**
     * Bitácora de transporte: cada vale de taxi (SEGCAT: un correo por vale a
     * "destinatarios_vouchers"). Va a la lista capturada en Configuración; si
     * está vacía, a quien puede autorizar vales (transporte.aprobar) en esa sede.
     */
    public function valeTaxi(MovimientoTransporte $vale, User $registro): void
    {
        $empresa = Empresa::find($vale->empresa_id);
        if ($empresa === null || ! $empresa->aviso('vale_taxi') || ! $this->correo->configurado()) {
            return;
        }

        $destinatarios = $empresa->destinatariosAviso('vale_taxi') ?: $this->conPermiso($empresa->id, 'transporte.aprobar', $vale->sede_id);
        if ($destinatarios === []) {
            return;
        }

        $vale->loadMissing(['sede:id,nombre', 'ruta:id,nombre', 'chofer:id,nombre_completo', 'paradero:id,nombre']);
        $mensaje = new ValeTaxiRegistrado(
            $vale->folio(),
            (float) $vale->monto,
            $vale->sede?->nombre,
            $vale->ruta?->nombre,
            $vale->chofer?->nombre_completo,
            $vale->paradero?->nombre,
            (int) $vale->cantidad_pax,
            $vale->justificacion,
            $registro->name,
            app(HoraLocal::class)->formatear($vale->created_at ?? now()),
            route('transporte.vale', $vale->id),
        );

        defer(fn () => $this->correo->enviar($destinatarios, $mensaje));
    }

    /**
     * Correos de los usuarios activos de la empresa con un permiso que alcance esa sede.
     *
     * @return list<string>
     */
    public function conPermiso(int $empresaId, string $permiso, ?int $sedeId): array
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->whereNotNull('email')->get()
            ->filter(function (User $u) use ($permiso, $sedeId) {
                $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

                return $efectivo !== null && ($efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
                    || ($sedeId !== null && in_array($sedeId, $efectivo->sedes, true)));
            })
            ->pluck('email')->unique()->values()->all();
    }

    // Pases de salida (circuito de aprobación; ver docs/tecnico/pases-salida.md)

    /**
     * Pases de salida: aviso del circuito a una lista de correos ya resuelta
     * por el módulo (quien debe aprobar, el solicitante, los responsables de
     * un vencido). $clave es el aviso de Empresa::AVISOS que lo enciende.
     * $diferido = false para enviarlo en el momento (comando de recordatorios).
     *
     * @param  list<string>  $destinatarios
     */
    public function paseSalida(int $empresaId, string $clave, array $destinatarios, AvisoPaseSalida $mensaje, bool $diferido = true): bool
    {
        $empresa = Empresa::find($empresaId);
        $destinatarios = array_values(array_unique(array_filter($destinatarios)));
        if ($empresa === null || ! $empresa->aviso($clave) || ! $this->correo->configurado() || $destinatarios === []) {
            return false;
        }

        if ($diferido) {
            defer(fn () => $this->correo->enviar($destinatarios, $mensaje));

            return true;
        }

        return $this->correo->enviar($destinatarios, $mensaje);
    }
    // Fin Pases de salida

    // Ronda 5 (LL-04): copias del voucher con cobro

    /**
     * Voucher de reposición con cobro (CXC): cada copia (Seguridad, Recepción,
     * Administración) va a su lista de Configuración. Si las tres están
     * vacías, a quien puede imprimir vouchers en esa sede. Nunca al
     * colaborador responsable.
     *
     * @return array<string, list<string>> copia => destinatarios (lo que se envió)
     */
    public function voucherConCobro(VoucherReposicion $voucher, User $registro): array
    {
        $empresa = Empresa::find($voucher->empresa_id);
        if ($empresa === null || ! $empresa->aviso('voucher_cobro') || ! $this->correo->configurado()) {
            return [];
        }

        $envios = [];
        foreach (Empresa::DESTINATARIOS_VOUCHER as $clave => $copia) {
            $lista = $empresa->destinatariosAviso($clave);
            if ($lista !== []) {
                $envios[$copia] = $lista;
            }
        }
        if ($envios === []) {
            $porPermiso = $this->conPermiso($empresa->id, 'vouchers.imprimir', $voucher->sede_id);
            if ($porPermiso === []) {
                return [];
            }
            $envios = ['Copias Seguridad, Recepción y Administración' => $porPermiso];
        }

        $voucher->loadMissing(['sede:id,nombre', 'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno']);
        $fecha = app(HoraLocal::class)->formatear($voucher->created_at ?? now());
        $firma = match ($voucher->estadoFirma()) {
            'digital' => 'Firmado digitalmente en la plataforma',
            'pendiente' => 'Firma física: se imprime y se firma a mano',
            default => null,
        };

        foreach ($envios as $copia => $destinatarios) {
            $mensaje = new VoucherConCobro(
                $copia,
                $voucher->folio,
                $voucher->origen_descripcion,
                $voucher->etiquetaOrigen(),
                VoucherReposicion::MOTIVOS[$voucher->motivo] ?? $voucher->motivo,
                (float) $voucher->monto,
                $voucher->sede?->nombre,
                $voucher->colaborador ? $voucher->colaborador->nombreCompleto().' (Núm. '.$voucher->colaborador->num_empleado.')' : null,
                $firma,
                $registro->name,
                $fecha,
                route('vouchers.imprimir', $voucher->id),
            );
            defer(fn () => $this->correo->enviar($destinatarios, $mensaje));
        }

        return $envios;
    }
    // Fin Ronda 5

    // Altas por verificar (ver docs/tecnico/altas-por-verificar.md)

    /**
     * La caseta registró desde Operación un vehículo, una empresa externa o
     * una persona que no estaba en su padrón: aviso a quien puede verificarlo
     * ("<padrón>.editar") en la sede donde se registró.
     */
    public function altaPorVerificar(string $padron, Model $registro, User $registradoPor): void
    {
        $empresa = Empresa::find($registro->empresa_id);
        if ($empresa === null || ! $empresa->aviso('alta_por_verificar') || ! $this->correo->configurado()) {
            return;
        }

        $altas = app(AltasPorVerificar::class);
        $definicion = $altas->definicion($padron);
        $sedeId = $registro->sede_alta_id === null ? null : (int) $registro->sede_alta_id;
        $destinatarios = $this->conPermiso($empresa->id, $definicion['permiso'].'.editar', $sedeId);
        if ($destinatarios === []) {
            return;
        }

        $mensaje = new AltaPorVerificarRegistrada(
            $definicion['singular'],
            $altas->titulo($padron, $registro),
            $definicion['padron'],
            $sedeId === null ? null : Sede::whereKey($sedeId)->value('nombre'),
            $registro->origen_alta !== null ? (AltasPorVerificar::ORIGENES[$registro->origen_alta]['nombre'] ?? null) : null,
            $registradoPor->name,
            app(HoraLocal::class)->formatear(now()),
            route($definicion['ruta'], ['verificacion' => 'pendiente']),
        );

        defer(fn () => $this->correo->enviar($destinatarios, $mensaje));
    }
    // Fin Altas por verificar
    // Procedimientos (ver docs/tecnico/procedimientos.md)

    /**
     * Procedimientos: aviso de una versión publicada o recordatorio de acuses
     * pendientes, a una lista ya resuelta por el módulo. $clave es el aviso de
     * Empresa::AVISOS que lo enciende; $diferido = false en el comando diario.
     *
     * @param  list<string>  $destinatarios
     */
    public function procedimiento(int $empresaId, string $clave, array $destinatarios, AvisoProcedimiento $mensaje, bool $diferido = true): bool
    {
        $empresa = Empresa::find($empresaId);
        $destinatarios = array_values(array_unique(array_filter($destinatarios)));
        if ($empresa === null || ! $empresa->aviso($clave) || ! $this->correo->configurado() || $destinatarios === []) {
            return false;
        }

        if ($diferido) {
            defer(fn () => $this->correo->enviar($destinatarios, $mensaje));

            return true;
        }

        return $this->correo->enviar($destinatarios, $mensaje);
    }
    // Fin Procedimientos
}
