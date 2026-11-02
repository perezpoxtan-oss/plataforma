<?php

namespace App\Services\Credenciales;

use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Notificacion;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Activos\CampanaActivos;
use App\Services\Activos\CustodiaActivos;
use App\Services\Activos\HistorialActivos;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Inventarios\Vouchers;
use App\Services\Lector\Identificacion;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Support\EstadoActivo;
use App\Support\Receptor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * C-44 (Etapa 8): credencialización. La credencial es un activo más (ver
 * App\Models\Credencial): se le da salida al colaborador, se recibe (OK,
 * dañada o «No regresó», la recepción común de C-45), se da de baja con
 * voucher de origen «credencial» y se da de baja sola cuando se da de baja
 * al colaborador («Antes de dar de baja», C-20).
 *
 * Permiso: «Colaboradores → Credencial» (colaboradores.credencial), con el
 * alcance de sedes del colaborador. Debe correr dentro de la empresa de trabajo.
 */
class AdministradorCredenciales
{
    public const PERMISO = 'colaboradores.credencial';

    public function __construct(
        private readonly AdministradorColaboradores $colaboradores,
        private readonly AdministradorRoles $auditoria,
        private readonly Vouchers $vouchers,
        private readonly HistorialActivos $historial,
    ) {}

    /** El colaborador en el alcance del permiso (si no, 404). */
    public function colaborador(User $actor, int $id): Colaborador
    {
        abort_unless($actor->can(self::PERMISO), 403);
        $c = $this->colaboradores->limitar(Colaborador::query(), $actor, self::PERMISO)->whereKey($id)->first();
        abort_if($c === null, 404);

        return $c;
    }

    /** La credencial en el alcance del permiso (por su colaborador; si no, 404). */
    public function credencial(User $actor, int $id): Credencial
    {
        abort_unless($actor->can(self::PERMISO), 403);
        $cred = Credencial::query()->whereKey($id)
            ->whereIn('colaborador_id', $this->colaboradores->limitar(Colaborador::query(), $actor, self::PERMISO)->select('colaboradores.id'))->first();
        abort_if($cred === null, 404);

        return $cred;
    }

    /** La que no está de baja (a lo más una). */
    public function vigenteDe(Colaborador $c): ?Credencial
    {
        return Credencial::where('colaborador_id', $c->id)->vigentes()->latest('id')->first();
    }

    /**
     * «Dar salida»: entrega la credencial. Si Recursos Humanos guarda la que
     * ya tenía (DISPONIBLE), se le vuelve a dar esa; si no, nace una nueva con
     * su folio. Opcional: la tarjeta NFC/RFID del plástico (la misma regla
     * única de «Código e identificación»).
     */
    public function darSalida(User $actor, Colaborador $c, ?string $etiqueta = null): Credencial
    {
        if (! $c->activo || $c->fusionado_en_id !== null) {
            throw ValidationException::withMessages(['credencial' => 'Solo se entrega credencial a un colaborador activo.']);
        }
        if ($c->provisional) {
            throw ValidationException::withMessages(['credencial' => 'Este colaborador es un alta provisional: Recursos Humanos debe validarlo antes de entregarle su credencial.']);
        }
        if ($etiqueta !== null && trim($etiqueta) !== '') {
            app(Identificacion::class)->asignarEtiqueta($actor, 'colaborador', $c, $etiqueta);
            $c->refresh();
        }

        $credencial = DB::transaction(function () use ($actor, $c) {
            Colaborador::whereKey($c->id)->lockForUpdate()->first();
            $vigente = $this->vigenteDe($c);
            if ($vigente !== null && $vigente->estado !== EstadoActivo::DISPONIBLE) {
                throw ValidationException::withMessages(['credencial' => "Ya tiene la credencial {$vigente->folio} ({$vigente->textoEstado()}): recíbela o dala de baja antes de entregar otra."]);
            }
            $credencial = $vigente ?? new Credencial(['colaborador_id' => $c->id]);
            if (! $credencial->exists) {
                $consecutivo = (int) Credencial::lockForUpdate()->max('consecutivo') + 1;
                $credencial->forceFill(['consecutivo' => $consecutivo, 'folio' => 'CRED-'.str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT)]);
            }
            $credencial->forceFill([
                'sede_id' => $c->sede_id, 'estado' => EstadoActivo::ASIGNADO, 'etiqueta_nfc' => $c->etiqueta_nfc,
                'entregada_en' => now(), 'entregada_por' => $actor->id, 'recibida_en' => null, 'recibida_por' => null,
            ])->save();

            return $credencial;
        });

        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'salida', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $credencial->folio]);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_entregada', $c, null, ['credencial' => $credencial->folio]);

        return $credencial;
    }

    /**
     * Recepción común (C-45): OK → DISPONIBLE (la guarda RR. HH.); Dañado →
     * DE BAJA sin cobro (se imprime otra); No regresó → NO REGRESÓ, con aviso
     * en la campana y en Mis pendientes a quien la da de baja.
     */
    public function recibir(User $actor, Credencial $credencial, string $resultado): string
    {
        $c = Colaborador::find($credencial->colaborador_id);
        $permitido = match ($resultado) {
            'ok' => [EstadoActivo::ASIGNADO, EstadoActivo::NO_REGRESO],
            'danado' => [EstadoActivo::ASIGNADO, EstadoActivo::NO_REGRESO],
            'no_regreso' => [EstadoActivo::ASIGNADO],
            default => [],
        };
        if (! in_array($credencial->estado, $permitido, true)) {
            throw ValidationException::withMessages(['credencial' => "La credencial {$credencial->folio} está {$credencial->textoEstado()}: no se puede marcar así."]);
        }
        $antes = $credencial->estado;
        $base = ['sede_id' => $credencial->sede_id, 'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $credencial->folio];

        if ($resultado === 'no_regreso') {
            $credencial->forceFill(['estado' => EstadoActivo::NO_REGRESO])->save();
            $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'no_regreso', $base);
            $this->auditoria->auditar($actor, 'colaboradores.credencial_no_regreso', $c ?? $credencial, ['estado' => $antes], ['estado' => EstadoActivo::NO_REGRESO, 'credencial' => $credencial->folio]);
            $this->avisarNoRegreso($credencial, $c, $actor);

            return "La credencial {$credencial->folio} quedó «No regresó». Si aparece, recíbela; si no, dala de baja con su voucher.";
        }

        DB::transaction(function () use ($actor, $credencial, $resultado) {
            $credencial->forceFill(['recibida_en' => now(), 'recibida_por' => $actor->id] + ($resultado === 'danado'
                ? ['estado' => EstadoActivo::BAJA, 'baja_motivo' => 'reposicion', 'baja_en' => now()]
                : ['estado' => EstadoActivo::DISPONIBLE]))->save();
        });
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'recepcion', $base + ['resultado' => $resultado]);
        if ($resultado === 'danado') {
            $this->apagarIdentificacion($actor, $c, 'Credencial dañada');
            $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', $base);
        }
        $this->auditoria->auditar($actor, 'colaboradores.credencial_recibida', $c ?? $credencial, ['estado' => $antes], ['estado' => $credencial->estado, 'credencial' => $credencial->folio, 'resultado' => $resultado]);
        $this->resolverAviso($credencial);

        return $resultado === 'danado'
            ? "Credencial {$credencial->folio} recibida dañada: quedó DE BAJA y su código ya no lo identifica. Imprime y dale salida a una nueva."
            : "Credencial {$credencial->folio} recibida: queda DISPONIBLE con Recursos Humanos.";
    }

    /**
     * «Dar de baja» con voucher (extraviada, dañada o robada), con cobro
     * opcional al colaborador. Su tarjeta NFC se quita y su QR cambia: el
     * plástico perdido ya no lo identifica.
     *
     * @param  array<string, mixed>  $entrada  motivo, descripcion, aplica_cobro, monto, firma_modo…
     */
    public function darDeBaja(User $actor, Credencial $credencial, array $entrada): VoucherReposicion
    {
        if ($credencial->estado === EstadoActivo::BAJA) {
            throw ValidationException::withMessages(['motivo' => "La credencial {$credencial->folio} ya está dada de baja."]);
        }
        $c = Colaborador::find($credencial->colaborador_id);
        // El responsable del cobro es el colaborador (no se elige)
        $entrada = ['responsable_tipo' => Receptor::COLABORADOR, 'responsable_id' => $credencial->colaborador_id, 'colaborador_id' => $credencial->colaborador_id]
            + array_intersect_key($entrada, array_flip(['motivo', 'descripcion', 'aplica_cobro', 'monto', 'firma_modo', 'firma_seguridad', 'firma_responsable']));
        $entrada['firma_modo'] ??= 'fisica';
        $datos = $this->vouchers->validar($entrada);
        $antes = $credencial->estado;

        $voucher = $this->vouchers->darDeBaja($actor, $credencial, 'credencial', $credencial->folio.' · '.($c?->nombreCompleto() ?? ''), $datos, function () use ($credencial) {
            $credencial->forceFill(['estado' => EstadoActivo::BAJA, 'baja_motivo' => 'reposicion', 'baja_en' => now()])->save();
        }, 'credencial');
        $credencial->forceFill(['voucher_id' => $voucher->id])->save();

        $this->apagarIdentificacion($actor, $c, 'Baja de su credencial');
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => $voucher->folio.' · '.(VoucherReposicion::MOTIVOS[$voucher->motivo] ?? $voucher->motivo)]);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_baja', $c ?? $credencial, ['estado' => $antes], ['estado' => EstadoActivo::BAJA, 'credencial' => $credencial->folio, 'voucher' => $voucher->folio]);
        $this->resolverAviso($credencial);

        return $voucher;
    }

    /**
     * «Antes de dar de baja» (C-20): al dar de baja al colaborador su
     * credencial vigente se da de baja sola (su etiqueta NFC ya se apaga ahí).
     */
    public function bajaPorColaborador(User $actor, Colaborador $c): ?Credencial
    {
        $credencial = $this->vigenteDe($c);
        if ($credencial === null) {
            return null;
        }
        $antes = $credencial->estado;
        $credencial->forceFill(['estado' => EstadoActivo::BAJA, 'baja_motivo' => 'baja_colaborador', 'baja_en' => now()])->save();
        $this->historial->registrar((int) $credencial->empresa_id, 'credencial', (int) $credencial->id, 'baja', ['sede_id' => $credencial->sede_id,
            'origen_tipo' => 'credencial', 'origen_id' => $credencial->id, 'receptor' => $c, 'detalle' => 'Baja del colaborador']);
        $this->auditoria->auditar($actor, 'colaboradores.credencial_baja', $c, ['estado' => $antes], ['estado' => EstadoActivo::BAJA, 'credencial' => $credencial->folio, 'motivo' => 'Baja del colaborador']);
        $this->resolverAviso($credencial);

        return $credencial;
    }

    /** Quita la tarjeta NFC y cambia el QR del colaborador (el plástico ya no lo identifica). */
    private function apagarIdentificacion(User $actor, ?Colaborador $c, string $motivo): void
    {
        if ($c === null) {
            return;
        }
        $antes = ['etiqueta_nfc' => $c->etiqueta_nfc];
        $c->forceFill(['etiqueta_nfc' => null, 'codigo_qr' => Str::lower(Str::random(24))])->save();
        $this->auditoria->auditar($actor, 'colaboradores.etiqueta_quitada', $c, $antes, ['etiqueta_nfc' => null, 'codigo_qr' => 'nuevo', 'motivo' => $motivo]);
    }

    private function avisarNoRegreso(Credencial $credencial, ?Colaborador $c, User $actor): void
    {
        $usuarios = app(CampanaActivos::class)->conPermiso((int) $credencial->empresa_id, self::PERMISO, $credencial->sede_id)
            ->reject(fn (int $id) => $id === (int) $actor->id);
        app(CentroNotificaciones::class)->avisar((int) $credencial->empresa_id, $usuarios, CustodiaActivos::TIPO_AVISO, [
            'titulo' => "No regresó: credencial {$credencial->folio}",
            'texto' => 'Es de '.($c?->nombreCompleto() ?? 'un colaborador').'; la marcó '.$actor->name.'. Si aparece, recíbela; si no, dala de baja con su voucher.',
            'url' => route('colaboradores.expediente', $credencial->colaborador_id).'#codigo',
            'referencia_tipo' => CustodiaActivos::TIPO_AVISO.'_credencial',
            'referencia_id' => (int) $credencial->id,
            'acciones' => [['etiqueta' => 'Dar de baja', 'abrir' => true]],
        ]);
    }

    private function resolverAviso(Credencial $credencial): void
    {
        if (Notificacion::where('referencia_tipo', CustodiaActivos::TIPO_AVISO.'_credencial')->where('referencia_id', $credencial->id)->exists()) {
            app(CentroNotificaciones::class)->resolver(CustodiaActivos::TIPO_AVISO.'_credencial', (int) $credencial->id);
        }
    }
}
