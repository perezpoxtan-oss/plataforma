<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cambios de estado de la Bitácora de accesos (réplica de acceso_proceso.php
 * de SEGCAT: autorizar, salida, salida/regreso de acompañante, reasignar zona,
 * salida a tour y regreso de tour).
 *
 *   pendiente ──autorizar──▶ en_sitio ──salida──▶ finalizado
 *   en_sitio ──salida temporal──▶ (fila "salida_temporal" en sitio) ──regreso──▶ finalizada + fila "regreso"
 *
 * Cada cambio se hace con una actualización condicionada al estado esperado:
 * un doble clic o el botón "atrás" del navegador ya no "reabre y vuelve a
 * cerrar" un registro (SEGCAT lo corrigió a mano en cada acción).
 */
class MovimientosAccesos
{
    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly RegistroAccesos $registro,
    ) {}

    /**
     * Pendiente → En sitio: el host confirmó que la persona puede pasar.
     */
    public function autorizar(User $actor, Acceso $acceso): void
    {
        $cambio = Acceso::whereKey($acceso->id)->where('estado', 'pendiente')
            ->update(['estado' => 'en_sitio', 'autorizado_at' => now(), 'autorizado_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
        if ($cambio === 0) {
            throw new MovimientoNoPermitido('Este acceso ya no está pendiente de autorización (alguien más lo atendió). Revisa la lista.');
        }

        $this->auditoria->auditar($actor, 'accesos.autorizado', $acceso, ['estado' => 'pendiente'], ['estado' => 'en_sitio']);
        app(Autorizaciones::class)->resueltaEnCaseta($actor, $acceso); // Recepción: la visita que esperaba al departamento (ADR-0007)
    }

    /**
     * En sitio → Finalizado. Los acompañantes que seguían dentro salen con él
     * (sus gafetes quedan libres) y, si estaba fuera en tour, el tour se cierra.
     */
    public function salida(User $actor, Acceso $acceso): void
    {
        if ($acceso->estado === 'pendiente') {
            throw new MovimientoNoPermitido('Este acceso sigue pendiente de autorización: confírmalo antes de registrar su salida.');
        }
        if ($acceso->movimiento !== 'entrada') {
            throw new MovimientoNoPermitido('Para cerrar una salida temporal registra su regreso.');
        }

        $acompanantes = DB::transaction(function () use ($actor, $acceso) {
            $ahora = now();
            $cambio = Acceso::whereKey($acceso->id)->where('estado', 'en_sitio')
                ->update(['estado' => 'finalizado', 'salida_at' => $ahora, 'salida_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => $ahora]);
            if ($cambio === 0) {
                throw new MovimientoNoPermitido('A esta persona ya se le dio salida. Revisa el historial.');
            }
            // Si estaba fuera en tour, ese tour también termina aquí
            Acceso::where('acceso_origen_id', $acceso->id)->where('movimiento', 'salida_temporal')->where('estado', 'en_sitio')
                ->update(['estado' => 'finalizado', 'salida_at' => $ahora, 'salida_por' => $actor->id, 'updated_at' => $ahora]);

            return AcompananteAcceso::where('acceso_id', $acceso->id)->whereNull('salida_at')
                ->update(['salida_at' => $ahora, 'salida_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => $ahora]);
        });

        $this->auditoria->auditar($actor, 'accesos.salida', $acceso, ['estado' => 'en_sitio'],
            ['estado' => 'finalizado', 'gafete' => $acceso->gafete_texto, 'acompanantes_con_salida' => $acompanantes]);
    }

    /**
     * Cambia (o libera) la zona de estacionamiento de un vehículo que sigue en sitio.
     */
    public function cambiarZona(User $actor, Acceso $acceso, mixed $zonaId): void
    {
        if ($acceso->estado !== 'en_sitio' || $acceso->movimiento !== 'entrada') {
            throw new MovimientoNoPermitido('Solo se cambia la zona de un vehículo que sigue en sitio.');
        }
        if ($acceso->placas === null) {
            throw new MovimientoNoPermitido('Este acceso no trae vehículo.');
        }

        $nueva = null;
        if ($zonaId !== null && $zonaId !== '') {
            $nueva = ZonaEstacionamiento::where('activo', true)->where('sede_id', $acceso->sede_id)->whereKey((int) $zonaId)->value('id');
            if ($nueva === null) {
                throw ValidationException::withMessages(['zona_estacionamiento_id' => 'Elige una zona activa de la sede del acceso (o «Sin asignar» para liberar).']);
            }
        }

        $antes = $acceso->zona_estacionamiento_id;
        $acceso->forceFill(['zona_estacionamiento_id' => $nueva])->save();
        $this->auditoria->auditar($actor, 'accesos.zona_cambiada', $acceso, ['zona_estacionamiento_id' => $antes], ['zona_estacionamiento_id' => $nueva]);
    }

    /**
     * Salida a Tour (huésped) / Salida Temporal (proveedor y contratista): sale
     * un rato sin cerrar su visita. Se guarda el vehículo y conductor con que sale.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function salidaTemporal(User $actor, Acceso $acceso, array $entrada): Acceso
    {
        if (! $acceso->admiteSalidaTemporal()) {
            throw new MovimientoNoPermitido('La salida temporal solo aplica a huéspedes, proveedores y contratistas.');
        }
        if ($acceso->estado !== 'en_sitio' || $acceso->movimiento !== 'entrada') {
            throw new MovimientoNoPermitido('Solo puede salir temporalmente alguien que está en sitio.');
        }
        $datos = $this->validarVehiculo($entrada);

        $salida = DB::transaction(function () use ($actor, $acceso, $datos) {
            // Bloquea el ingreso: dos clics a la vez no abren dos tours
            Acceso::whereKey($acceso->id)->lockForUpdate()->first();
            if ($acceso->salidaTemporalAbierta()->exists()) {
                throw new MovimientoNoPermitido('Esta persona ya está fuera: registra primero su regreso.');
            }

            return Acceso::create([
                'sede_id' => $acceso->sede_id, 'tipo' => $acceso->tipo, 'movimiento' => 'salida_temporal', 'acceso_origen_id' => $acceso->id,
                'estado' => 'en_sitio', 'nombre' => $acceso->nombre, 'proveedor_id' => $acceso->proveedor_id,
                'empresa_procedencia' => $acceso->empresa_procedencia, 'host_colaborador_id' => $acceso->host_colaborador_id,
                'habitacion' => $acceso->habitacion, 'entrada_at' => now(),
                ...$this->vehiculoDeMovimiento($actor, $acceso, $datos),
            ]);
        });

        $this->auditoria->auditar($actor, 'accesos.salida_temporal', $acceso, null, ['salida_temporal_id' => $salida->id, 'placas' => $salida->placas, 'conductor' => $salida->conductor]);

        return $salida;
    }

    /**
     * Regreso de Tour / de la salida temporal: cierra la salida (queda cuánto
     * tiempo estuvo fuera) y deja constancia del regreso con su propio vehículo.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function regreso(User $actor, Acceso $acceso, array $entrada): Acceso
    {
        $datos = $this->validarVehiculo($entrada);

        $regreso = DB::transaction(function () use ($actor, $acceso, $datos) {
            $abierta = $acceso->salidaTemporalAbierta()->first();
            $ahora = now();
            $cerrada = $abierta === null ? 0 : Acceso::whereKey($abierta->id)->where('estado', 'en_sitio')
                ->update(['estado' => 'finalizado', 'salida_at' => $ahora, 'salida_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => $ahora]);
            if ($cerrada === 0) {
                throw new MovimientoNoPermitido('Esta persona no tiene una salida temporal abierta (ya regresó).');
            }

            $regreso = Acceso::create([
                'sede_id' => $acceso->sede_id, 'tipo' => $acceso->tipo, 'movimiento' => 'regreso', 'acceso_origen_id' => $acceso->id,
                'estado' => 'finalizado', 'nombre' => $acceso->nombre, 'proveedor_id' => $acceso->proveedor_id,
                'empresa_procedencia' => $acceso->empresa_procedencia, 'host_colaborador_id' => $acceso->host_colaborador_id,
                'habitacion' => $acceso->habitacion, 'entrada_at' => $ahora,
                ...$this->vehiculoDeMovimiento($actor, $acceso, $datos),
            ]);
            $regreso->forceFill(['salida_at' => $ahora, 'salida_por' => $actor->id])->save();

            return $regreso;
        });

        $this->auditoria->auditar($actor, 'accesos.regreso', $acceso, null, ['regreso_id' => $regreso->id, 'placas' => $regreso->placas, 'conductor' => $regreso->conductor]);

        return $regreso;
    }

    /**
     * Salida individual de un acompañante (su gafete queda libre). Si salió un
     * rato, primero se registra su regreso (como en SEGCAT).
     */
    public function salidaAcompanante(User $actor, AcompananteAcceso $acompanante): void
    {
        $this->acompananteEnSitio($acompanante);
        if ($acompanante->estaFueraTemporal()) {
            throw new MovimientoNoPermitido('Este acompañante tiene una salida temporal abierta: registra primero su regreso.');
        }
        $this->actualizarAcompanante($acompanante, fn ($q) => $q->whereNull('salida_at')->where(fn ($t) => $t->whereNull('salida_temporal_at')->orWhereNotNull('regreso_temporal_at')),
            ['salida_at' => now(), 'salida_por' => $actor->id], 'A este acompañante ya se le dio salida.');
        $this->auditoria->auditar($actor, 'accesos.salida_acompanante', $acompanante->acceso, null, ['acompanante' => $acompanante->nombreVisible(), 'gafete' => $acompanante->gafete_texto]);
    }

    /**
     * Salida temporal de un acompañante de proveedor o contratista: su gafete sigue reservado.
     */
    public function salidaTemporalAcompanante(User $actor, AcompananteAcceso $acompanante): void
    {
        $acceso = $this->acompananteEnSitio($acompanante);
        if (! in_array($acceso->tipo, Acceso::CON_AUTORIZACION, true)) {
            throw new MovimientoNoPermitido('La salida temporal de acompañantes solo aplica a proveedores y contratistas.');
        }
        // Puede salir y volver varias veces: cada salida y regreso queda en la auditoría
        $this->actualizarAcompanante($acompanante, fn ($q) => $q->whereNull('salida_at')->where(fn ($t) => $t->whereNull('salida_temporal_at')->orWhereNotNull('regreso_temporal_at')),
            ['salida_temporal_at' => now(), 'salida_temporal_por' => $actor->id, 'regreso_temporal_at' => null, 'regreso_temporal_por' => null],
            'Este acompañante ya está fuera (o ya salió).');
        $this->auditoria->auditar($actor, 'accesos.salida_temporal_acompanante', $acceso, null, ['acompanante' => $acompanante->nombreVisible(), 'gafete' => $acompanante->gafete_texto]);
    }

    public function regresoAcompanante(User $actor, AcompananteAcceso $acompanante): void
    {
        $acceso = $this->acompananteEnSitio($acompanante);
        $this->actualizarAcompanante($acompanante, fn ($q) => $q->whereNull('salida_at')->whereNotNull('salida_temporal_at')->whereNull('regreso_temporal_at'),
            ['regreso_temporal_at' => now(), 'regreso_temporal_por' => $actor->id], 'Este acompañante no tiene una salida temporal abierta.');
        $this->auditoria->auditar($actor, 'accesos.regreso_acompanante', $acceso, null, ['acompanante' => $acompanante->nombreVisible()]);
    }

    // ------------------------------------------------------------------ Ayudas

    private function acompananteEnSitio(AcompananteAcceso $acompanante): Acceso
    {
        $acceso = $acompanante->acceso;
        if ($acceso === null || $acceso->estado !== 'en_sitio') {
            throw new MovimientoNoPermitido('El titular de este acompañante ya no está en sitio.');
        }
        if ($acompanante->salida_at !== null) {
            throw new MovimientoNoPermitido('A este acompañante ya se le dio salida.');
        }

        return $acceso;
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function actualizarAcompanante(AcompananteAcceso $acompanante, \Closure $condicion, array $cambios, string $siNoAplica): void
    {
        $consulta = AcompananteAcceso::whereKey($acompanante->id);
        $condicion($consulta);
        if ($consulta->update($cambios + ['updated_at' => now()]) === 0) {
            throw new MovimientoNoPermitido($siNoAplica);
        }
        $acompanante->refresh();
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validarVehiculo(array $entrada): array
    {
        $entrada = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, array_intersect_key($entrada, array_flip(['placas', 'marca', 'conductor'])));

        $datos = Validator::make($entrada, [
            'placas' => ['nullable', 'string', 'max:25'],
            'marca' => ['nullable', 'string', 'max:50'],
            'conductor' => ['nullable', 'string', 'max:150'],
        ], [
            'placas.max' => 'Revisa las placas: son muy largas.',
            'marca.max' => 'La marca admite máximo 50 caracteres.',
            'conductor.max' => 'El nombre del conductor admite máximo 150 caracteres.',
        ])->validate();

        $placas = Vehiculo::normalizarPlacas($datos['placas'] ?? null);
        if ($placas !== '' && ! preg_match('/^[A-Z0-9Ñ]{2,20}$/u', $placas)) {
            throw ValidationException::withMessages(['placas' => 'Las placas solo llevan letras y números (de 2 a 20).']);
        }

        return ['placas' => $placas, 'marca' => $datos['marca'] ?? null, 'conductor' => $datos['conductor'] ?? null];
    }

    /**
     * Vehículo (al padrón) y conductor de una salida temporal o de un regreso.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function vehiculoDeMovimiento(User $actor, Acceso $acceso, array $datos): array
    {
        $vehiculo = null;
        if ($datos['placas'] !== '') {
            $propiedad = $acceso->tipo === 'huesped' ? 'propio_huesped' : 'empresa_proveedor';
            $vehiculo = $this->registro->vehiculo($actor, $datos['placas'], 'sedan', ['marca' => $datos['marca']], $propiedad,
                $propiedad === 'empresa_proveedor' ? $acceso->proveedor_id : null);
        }
        $conductor = $datos['conductor'] === null ? null : mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $datos['conductor'])));

        return [
            'modo_arribo' => $vehiculo ? 'auto' : null,
            'vehiculo_id' => $vehiculo?->id,
            'placas' => $vehiculo?->placas,
            'conductor' => $conductor,
        ];
    }
}
