<?php

namespace App\Services\Activos;

use App\Models\MovimientoTransporte;
use App\Models\Notificacion;
use App\Models\PrestamoLlave;
use App\Models\Procedimiento;
use App\Models\ProcedimientoVersion;
use App\Models\Responsiva;
use App\Models\Sede;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Avisos en la campana de Transporte, Préstamo de llaves, Procedimientos y
 * Responsivas (ADR-0014; criterios 1 y 3 de docs/criterios-ux.md):
 *
 *  - «Vale de taxi por autorizar» a quien da el Vo.Bo. en la sede; se resuelve
 *    al autorizarlo o anularlo.
 *  - «Procedimiento por aprobar» a quien puede aprobarlo (no a su autor); se
 *    resuelve al aprobarlo o pedir cambios. El autor se entera del resultado.
 *  - «Procedimiento por leer» a quien debe firmar «Leí y entendí»; queda
 *    leído para cada persona cuando firma.
 *  - «Llave fuera desde hace X h» a la caseta (ámbar) y después al jefe
 *    (rojo), según Configuración → Caseta y activos; se resuelve al recibirla.
 *  - «Equipo de turno sin regresar» a la caseta (ámbar) y al supervisor
 *    (rojo); se resuelve cuando regresan los equipos de turno.
 *
 * Referencias (para resolver): transporte_vale (movimiento), procedimiento_revision
 * y procedimiento_lectura (versión), prestamo_llave_aviso / _escalada (préstamo),
 * responsiva_turno_aviso / _escalada (responsiva).
 */
class CampanaActivos
{
    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly Autorizador $autorizador,
    ) {}

    // ------------------------------------------------------------ Transporte

    public function valePorAutorizar(MovimientoTransporte $vale, ?User $quien): void
    {
        $usuarios = $this->conPermiso((int) $vale->empresa_id, 'transporte.aprobar', (int) $vale->sede_id)
            ->reject(fn (int $id) => $quien !== null && $id === (int) $quien->id);
        $vale->loadMissing('sede:id,nombre', 'ruta:id,nombre', 'paradero:id,nombre');
        $this->centro->avisar((int) $vale->empresa_id, $usuarios, 'vale_taxi_por_autorizar', [
            'titulo' => 'Vale de taxi por autorizar: '.$vale->folio(),
            'texto' => trim(($vale->sede?->nombre ?? '').' · '.($vale->ruta?->nombre ?? '').' · $'.number_format((float) $vale->monto, 2)
                .' a '.($vale->paradero?->nombre ?? '—').'. Revísalo y dale el Vo.Bo.'),
            'url' => route('transporte.show', $vale->id),
            'referencia_tipo' => 'transporte_vale',
            'referencia_id' => (int) $vale->id,
            'acciones' => [['etiqueta' => 'Abrir vale', 'abrir' => true]],
        ]);
    }

    /** Autorizado o anulado: ya no espera el Vo.Bo. */
    public function resolverVale(MovimientoTransporte $vale): void
    {
        $this->centro->resolver('transporte_vale', (int) $vale->id);
    }

    // ------------------------------------------------------- Procedimientos

    /**
     * @param  Collection<int, User>  $aprobadores
     */
    public function procedimientoPorAprobar(Procedimiento $p, ProcedimientoVersion $v, Collection $aprobadores): void
    {
        $this->centro->avisar((int) $p->empresa_id, $aprobadores->pluck('id'), 'procedimiento_revision', [
            'titulo' => "Procedimiento por aprobar: {$p->clave}",
            'texto' => "{$v->titulo} (versión {$v->numero}). Revísalo: «Aprobar y publicar» o «Pedir cambios».",
            'url' => route('procedimientos.show', $p->id),
            'referencia_tipo' => 'procedimiento_revision',
            'referencia_id' => (int) $v->id,
            'acciones' => [['etiqueta' => 'Abrir procedimiento', 'abrir' => true]],
        ]);
    }

    /**
     * Ya se aprobó o se pidieron cambios: el aviso de revisión pierde su botón
     * y el autor (y quien lo envió) se entera del resultado.
     */
    public function procedimientoRevisado(Procedimiento $p, ProcedimientoVersion $v, bool $aprobado, ?string $comentario, User $quien): void
    {
        $this->centro->resolver('procedimiento_revision', (int) $v->id);
        $duenos = collect([$v->creado_por, $v->enviado_por])->filter()->map(fn ($id) => (int) $id)->unique()
            ->reject(fn (int $id) => $id === (int) $quien->id);
        $activos = User::where('empresa_id', $p->empresa_id)->where('activo', true)->whereIn('id', $duenos)->pluck('id');
        $this->centro->avisar((int) $p->empresa_id, $activos, $aprobado ? 'procedimiento_aprobado' : 'procedimiento_rechazado', [
            'titulo' => "Tu procedimiento {$p->clave} ".($aprobado ? 'se publicó' : 'necesita cambios'),
            'texto' => $aprobado
                ? "Versión {$v->numero} aprobada por {$quien->name}. Ya rige y se pidió «Leí y entendí» al personal."
                : 'Comentario de '.$quien->name.': '.($comentario ?? '—').' · Corrígelo y envíalo otra vez a revisión.',
            'url' => route('procedimientos.show', $p->id),
            'referencia_tipo' => 'procedimiento_resultado',
            'referencia_id' => (int) $v->id,
        ]);
    }

    /**
     * @param  Collection<int, User>  $obligados
     */
    public function procedimientoPorLeer(Procedimiento $p, ProcedimientoVersion $v, Collection $obligados): void
    {
        // Lo de la versión anterior ya no aplica
        Notificacion::where('referencia_tipo', 'procedimiento_lectura')->where('url', route('procedimientos.leer', $p->id))
            ->whereNull('leida_en')->update(['leida_en' => now(), 'updated_at' => now()]);
        $this->centro->avisar((int) $p->empresa_id, $obligados->pluck('id'), 'procedimiento_publicado', [
            'titulo' => "Procedimiento por leer: {$p->clave}",
            'texto' => $v->titulo.($v->numero > 1 ? " (versión {$v->numero}: ".($v->resumen_cambios ?? 'cambió').')' : '').'. Léelo y marca «Leí y entendí».',
            'url' => route('procedimientos.leer', $p->id),
            'referencia_tipo' => 'procedimiento_lectura',
            'referencia_id' => (int) $v->id,
            'acciones' => [['etiqueta' => 'Leer ahora', 'abrir' => true]],
        ]);
    }

    /** Ya firmó: su aviso de esa versión queda leído (los de los demás siguen). */
    public function procedimientoLeido(ProcedimientoVersion $v, User $usuario): void
    {
        Notificacion::where('user_id', $usuario->id)->where('referencia_tipo', 'procedimiento_lectura')->where('referencia_id', $v->id)
            ->update(['acciones' => null, 'leida_en' => now(), 'updated_at' => now()]);
    }

    // ---------------------------------------------------- Préstamo de llaves

    /**
     * Recordatorio (ámbar) a la caseta o escalamiento (rojo) a quien anula
     * préstamos en la sede. Una sola vez por nivel.
     *
     * @return int usuarios avisados
     */
    public function llaveFuera(PrestamoLlave $p, string $nivel, float $horas): int
    {
        $referencia = $nivel === 'rojo' ? 'prestamo_llave_escalada' : 'prestamo_llave_aviso';
        if (Notificacion::where('referencia_tipo', $referencia)->where('referencia_id', $p->id)->exists()) {
            return 0;
        }
        $p->loadMissing('llave:id,nomenclatura,descripcion', 'colaborador:id,nombre,apellido_paterno,apellido_materno', 'sede');
        $usuarios = $this->conPermiso((int) $p->empresa_id, $nivel === 'rojo' ? 'prestamo_llaves.eliminar' : 'prestamo_llaves.editar', (int) $p->sede_id);
        if ($usuarios->isEmpty()) {
            // Sin a quién escalar: que al menos lo sepa la caseta
            $usuarios = $this->conPermiso((int) $p->empresa_id, 'prestamo_llaves.editar', (int) $p->sede_id);
        }

        return count($this->centro->avisar((int) $p->empresa_id, $usuarios, 'llave_fuera', [
            'titulo' => 'Llave '.($p->llave?->nomenclatura ?? '').' fuera desde hace '.self::textoHoras($horas),
            'texto' => 'La tiene '.($p->colaborador?->nombreCompleto() ?? '—').' desde las '.$this->enSede($p->sede, $p->prestado_en)
                .' ('.($p->sede?->nombre ?? '').'). '.($nivel === 'rojo' ? 'Sigue sin regresar: dale seguimiento.' : 'Pide que la regresen o recíbela en caseta.'),
            'url' => route('prestamo_llaves.index').'#prestamo-'.$p->id,
            'referencia_tipo' => $referencia,
            'referencia_id' => (int) $p->id,
        ]));
    }

    /** Recibida o anulada: no hay nada que recordar. */
    public function resolverLlave(PrestamoLlave $p): void
    {
        $this->centro->resolver('prestamo_llave_aviso', (int) $p->id);
        $this->centro->resolver('prestamo_llave_escalada', (int) $p->id);
    }

    // ------------------------------------------------------------ Responsivas

    /**
     * Equipos de turno que debían regresar: a la caseta (ámbar) o al
     * supervisor que da de baja equipos (rojo). Una sola vez por nivel.
     *
     * @return int usuarios avisados
     */
    public function turnoSinRegresar(Responsiva $r, string $nivel, int $equipos, \DateTimeInterface $debia): int
    {
        $referencia = $nivel === 'rojo' ? 'responsiva_turno_escalada' : 'responsiva_turno_aviso';
        if (Notificacion::where('referencia_tipo', $referencia)->where('referencia_id', $r->id)->exists()) {
            return 0;
        }
        $r->loadMissing('colaborador:id,nombre,apellido_paterno,apellido_materno', 'sede');
        $usuarios = $this->conPermiso((int) $r->empresa_id, 'responsivas.editar', (int) $r->sede_id);
        if ($nivel === 'rojo') {
            $supervisores = $usuarios->intersect($this->conPermiso((int) $r->empresa_id, 'equipos.eliminar', (int) $r->sede_id))->values();
            $usuarios = $supervisores->isNotEmpty() ? $supervisores : $usuarios;
        }

        return count($this->centro->avisar((int) $r->empresa_id, $usuarios, 'equipo_turno', [
            'titulo' => "Responsiva {$r->folio}: ".($equipos === 1 ? '1 equipo de turno sin regresar' : "{$equipos} equipos de turno sin regresar"),
            'texto' => ($r->colaborador?->nombreCompleto() ?? '—').' debía regresarlos a las '.$this->enSede($r->sede, $debia)
                .' ('.($r->sede?->nombre ?? '').'). '.($nivel === 'rojo' ? 'Siguen sin regresar: dale seguimiento.' : 'Pídeselos o recíbelos en la responsiva.'),
            'url' => route('responsivas.index').'#responsiva-'.$r->id,
            'referencia_tipo' => $referencia,
            'referencia_id' => (int) $r->id,
        ]));
    }

    public function resolverTurno(Responsiva $r): void
    {
        $this->centro->resolver('responsiva_turno_aviso', (int) $r->id);
        $this->centro->resolver('responsiva_turno_escalada', (int) $r->id);
    }

    // ----------------------------------------------------------------- Apoyo

    /** «12/10 07:30» en la hora local de la sede (los recordatorios corren sin sesión). */
    private function enSede(?Sede $sede, ?\DateTimeInterface $fecha): string
    {
        return $fecha === null ? '—' : CarbonImmutable::instance($fecha)->setTimezone($sede?->zonaHoraria() ?? config('app.timezone'))->format('d/m H:i');
    }

    /** «3 h 20 min», «2 días 4 h». */
    public static function textoHoras(float $horas): string
    {
        $minutos = (int) floor($horas * 60);
        if ($minutos < 60) {
            return $minutos.' min';
        }
        $h = intdiv($minutos, 60);
        if ($h >= 24) {
            return intdiv($h, 24).' '.(intdiv($h, 24) === 1 ? 'día' : 'días').($h % 24 ? ' '.($h % 24).' h' : '');
        }

        return $h.' h'.($minutos % 60 ? ' '.($minutos % 60).' min' : '');
    }

    /**
     * Usuarios activos de la empresa con un permiso que alcance esa sede.
     *
     * @return Collection<int, int>
     */
    public function conPermiso(int $empresaId, string $permiso, ?int $sedeId): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->get()
            ->filter(function (User $u) use ($permiso, $sedeId) {
                $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

                return $efectivo !== null && ($efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
                    || ($sedeId !== null && in_array($sedeId, $efectivo->sedes, true)));
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}
