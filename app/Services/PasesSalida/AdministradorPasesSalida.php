<?php

namespace App\Services\PasesSalida;

use App\Mail\AvisoPaseSalida;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\FirmaUsuario;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaBitacora;
use App\Models\PaseSalidaFirma;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los Pases de salida (réplica de pases_salida_proceso.php,
 * pases_salida_firmar.php y pases_salida_rechazar.php de SEGCAT, con el
 * circuito de aprobación profesional v2).
 *
 * Alcance: con alcance de empresa se ve todo; con alcance de sede, los
 * pases que SALEN de sus sedes o que VAN a ellas (la sede destino recibe y
 * autoriza la salida de regreso); con alcance "propios", además, solo los que
 * él registró. Todo corre con la empresa de trabajo fijada en el Tenant.
 *
 * Permisos:
 *   - Aprobar, rechazar y omitir un paso opcional: pases_salida.aprobar (ver CircuitoPasesSalida)
 *   - Salida, recepción en destino, salida de regreso y regreso: pases_salida.firmar
 *   - Registrar, corregir y reenviar, cancelar: pases_salida.crear (su dueño)
 *   - Configurar el circuito: pases_salida.configurar con alcance de empresa
 */
class AdministradorPasesSalida
{
    public const POR_PAGINA = 20;

    public const MAX_ARTICULOS = 50;

    /** Filtros de la lista (SEGCAT: ?filtro=), con sus píldoras. */
    public const FILTROS = [
        'todos' => 'Todos',
        'mi_firma' => 'Pendientes de mi firma',
        'pendientes' => 'Pendientes de Aprobación',
        'aprobados' => 'Aprobados, listos para salir',
        'fuera' => 'Fuera de la propiedad',
        'espera_regreso' => 'Esperando Regreso',
        'vencidos' => 'Vencidos',
        'cerrados' => 'Cerrados',
        'rechazados' => 'Rechazados',
    ];

    /** paso físico => [estado nuevo, columna de fecha, evento de auditoría, texto de la bitácora] */
    private const AVANCE = [
        'salida' => [PaseSalida::SALIO, 'salio_en', 'pases_salida.salida_registrada', 'Registró la salida'],
        'recepcion' => [PaseSalida::EN_DESTINO, 'recibido_destino_en', 'pases_salida.recibido_en_destino', 'Confirmó la llegada a destino'],
        'salida_regreso' => [PaseSalida::EN_TRANSITO_REGRESO, 'salio_regreso_en', 'pases_salida.salida_de_regreso', 'Autorizó la salida de regreso'],
        'regreso' => [PaseSalida::REGRESADO, 'regreso_en', 'pases_salida.regresado', 'Registró el regreso'],
    ];

    private const TELEFONO = '/^\+?[0-9][0-9 ()\-]{6,19}$/';

    /** @var array<int, string>|null fecha local "Y-m-d" de cada sede */
    private ?array $hoy = null;

    /** @var array<string, list<int>> */
    private array $porFirmar = [];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Firmas $firmas,
        private readonly CircuitoPasesSalida $circuito,
        private readonly AvisosCorreo $avisos,
    ) {}

    public function circuito(): CircuitoPasesSalida
    {
        return $this->circuito;
    }

    // ------------------------------------------------------------------ Alcance

    /**
     * Sedes en las que el usuario usa el permiso: null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    /**
     * Limita una consulta a los pases que el actor puede ver con un permiso:
     * los que salen de sus sedes o van a ellas.
     *
     * @param  Builder<PaseSalida>  $consulta
     * @return Builder<PaseSalida>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }

        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->where(fn ($s) => $s->whereIn('pases_salida.sede_id', $sedes)
                ->orWhereIn('pases_salida.sede_destino_id', $sedes)))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('pases_salida.creado_por', $actor->id));
    }

    /** ¿Puede firmar el paso físico (salida, recepción, salida de regreso, regreso) en la sede que le toca? */
    public function puedeFirmarPaso(User $actor, PaseSalida $pase, string $paso): bool
    {
        return isset(PaseSalida::PASOS_FISICOS[$paso])
            && $this->circuito->tienePermisoEnSede($actor, 'pases_salida.firmar', $pase, $pase->sedeDelPaso($paso));
    }

    /** Quien registró el pase o el propio solicitante (si tiene usuario). */
    public function esDueno(User $actor, PaseSalida $pase): bool
    {
        return (int) $pase->creado_por === (int) $actor->id
            || (($actor->getAttributes()['colaborador_id'] ?? null) !== null && (int) $actor->getAttributes()['colaborador_id'] === (int) $pase->colaborador_id);
    }

    /**
     * Corregir y reenviar, o cancelar: su dueño con "crear" en la sede de
     * origen, o quien edita pases de toda la empresa.
     */
    public function puedeGestionar(User $actor, PaseSalida $pase): bool
    {
        if (! in_array($pase->estado, [PaseSalida::PENDIENTE, PaseSalida::RECHAZADO], true)) {
            return false;
        }

        return ($this->esDueno($actor, $pase) && $this->circuito->tienePermisoEnSede($actor, 'pases_salida.crear', $pase, $pase->sede_id))
            || ($actor->can('pases_salida.editar') && $this->autorizador->alcanceDeEmpresa($actor, 'pases_salida.editar'));
    }

    // ------------------------------------------------------------------- Fechas

    /**
     * "Hoy" en cada sede (su zona horaria o la de la empresa).
     *
     * @return array<int, string>
     */
    public function hoyPorSede(): array
    {
        return $this->hoy ??= Sede::withTrashed()->with('empresa:id,zona_horaria')->get(['id', 'empresa_id', 'zona_horaria'])
            ->mapWithKeys(fn (Sede $s) => [(int) $s->id => now($s->zonaHoraria())->format('Y-m-d')])->all();
    }

    public function hoyDe(PaseSalida $pase): string
    {
        return $this->hoyPorSede()[(int) $pase->sede_id] ?? now()->format('Y-m-d');
    }

    public function vencido(PaseSalida $pase): bool
    {
        return $pase->vencido($this->hoyDe($pase));
    }

    // ------------------------------------------------------------------- Lista

    /**
     * Aplica un filtro de la lista. "Vencidos" compara contra la fecha local
     * de la sede de origen de cada pase; "Pendientes de mi firma", contra lo
     * que el actor puede firmar ahora.
     *
     * @param  Builder<PaseSalida>  $consulta
     * @return Builder<PaseSalida>
     */
    public function filtrar(Builder $consulta, string $filtro, ?User $actor = null): Builder
    {
        $fuera = fn ($q) => $q->whereIn('pases_salida.estado', PaseSalida::FUERA);

        return match ($filtro) {
            'mi_firma' => $consulta->whereIn('pases_salida.id', $actor === null ? [] : $this->idsPorFirmar($actor)),
            'pendientes' => $consulta->where('pases_salida.estado', PaseSalida::PENDIENTE),
            'aprobados' => $consulta->where('pases_salida.estado', PaseSalida::APROBADO),
            'fuera' => $fuera($consulta),
            'espera_regreso' => $fuera($consulta)->where('pases_salida.requiere_regreso', true),
            'vencidos' => $fuera($consulta)->where('pases_salida.requiere_regreso', true)->whereNotNull('pases_salida.fecha_tentativa_regreso')
                ->where(function ($q) {
                    $q->whereRaw('1 = 0');
                    foreach ($this->hoyPorSede() as $sede => $hoy) {
                        $q->orWhere(fn ($s) => $s->where('pases_salida.sede_id', $sede)->where('pases_salida.fecha_tentativa_regreso', '<', $hoy));
                    }
                }),
            'cerrados' => $consulta->where(fn ($q) => $q->where('pases_salida.estado', PaseSalida::REGRESADO)
                ->orWhere(fn ($s) => $s->where('pases_salida.estado', PaseSalida::SALIO)->where('pases_salida.requiere_regreso', false))),
            'rechazados' => $consulta->where('pases_salida.estado', PaseSalida::RECHAZADO),
            default => $consulta,
        };
    }

    /**
     * Búsqueda por folio, solicitante, lo que sale o el código del QR de la
     * hoja (se puede escanear la hoja con un lector USB en el buscador).
     *
     * @param  Builder<PaseSalida>  $consulta
     * @return Builder<PaseSalida>
     */
    public function buscar(Builder $consulta, string $texto): Builder
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if ($texto === '') {
            return $consulta;
        }
        $codigo = preg_match('#verificar/([A-Za-z0-9]{8,32})#', $texto, $m) ? $m[1] : $texto;
        $comodin = fn (string $t) => '%'.addcslashes(mb_strtolower($t), '%_\\').'%';

        return $consulta->where(function ($q) use ($texto, $comodin, $codigo) {
            $q->whereRaw('LOWER(pases_salida.folio) LIKE ?', [$comodin($texto)])
                ->orWhere('pases_salida.codigo_verificacion', mb_strtoupper($codigo))
                ->orWhereHas('solicitante', function ($c) use ($texto, $comodin) {
                    $c->whereRaw('LOWER(colaboradores.num_empleado) LIKE ?', [$comodin($texto)])
                        ->orWhere(function ($n) use ($texto, $comodin) {
                            foreach (explode(' ', $texto) as $palabra) {
                                $n->where(fn ($p) => $p->whereRaw('LOWER(colaboradores.nombre) LIKE ?', [$comodin($palabra)])
                                    ->orWhereRaw('LOWER(colaboradores.apellido_paterno) LIKE ?', [$comodin($palabra)])
                                    ->orWhereRaw('LOWER(colaboradores.apellido_materno) LIKE ?', [$comodin($palabra)]));
                            }
                        });
                })
                ->orWhereHas('articulos', fn ($a) => $a->whereRaw('LOWER(pases_salida_articulos.equipo) LIKE ?', [$comodin($texto)])
                    ->orWhereRaw('LOWER(pases_salida_articulos.serie) LIKE ?', [$comodin($texto)]));
        });
    }

    /**
     * Pases que esperan una APROBACIÓN del actor (bandeja de firmas y aviso en Inicio).
     *
     * @return list<int>
     */
    public function idsPorAprobar(User $actor): array
    {
        if (! $actor->can('pases_salida.aprobar')) {
            return [];
        }

        return $this->porFirmar['aprobar-'.$actor->id] ??= $this->limitar(PaseSalida::query(), $actor, 'pases_salida.ver')
            ->where('pases_salida.estado', PaseSalida::PENDIENTE)
            ->with(['aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre', 'sede:id,nombre'])->get()
            ->filter(fn (PaseSalida $p) => $this->circuito->puedeAprobar($actor, $p))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Pases donde el actor puede firmar AHORA: una aprobación o un paso de caseta.
     *
     * @return list<int>
     */
    public function idsPorFirmar(User $actor): array
    {
        return $this->porFirmar['firmar-'.$actor->id] ??= (function () use ($actor) {
            $fisicos = ! $actor->can('pases_salida.firmar') ? [] : $this->limitar(PaseSalida::query(), $actor, 'pases_salida.ver')
                ->whereIn('pases_salida.estado', [PaseSalida::APROBADO, ...PaseSalida::FUERA])
                ->get(['id', 'empresa_id', 'sede_id', 'sede_destino_id', 'estado', 'requiere_regreso', 'destino_tipo', 'creado_por'])
                ->filter(fn (PaseSalida $p) => ($paso = $p->pasoFisico()) !== null && $this->puedeFirmarPaso($actor, $p, $paso))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            return array_values(array_unique([...$this->idsPorAprobar($actor), ...$fisicos]));
        })();
    }

    // ---------------------------------------------------------------- Escritura

    /**
     * Alta del pase: folio PS-000123 consecutivo por empresa, copia del
     * circuito de aprobación vigente y aviso al primer aprobador.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): PaseSalida
    {
        [$datos, $articulos] = $this->validar($actor, $entrada);

        for ($intento = 1; ; $intento++) {
            try {
                $pase = DB::transaction(function () use ($datos, $articulos, $actor) {
                    $numero = (int) PaseSalida::query()->lockForUpdate()->max('folio_numero') + 1;
                    $pase = new PaseSalida($datos);
                    $pase->forceFill([
                        'folio_numero' => $numero,
                        'folio' => PaseSalida::folioDe($numero),
                        'codigo_verificacion' => $this->codigoNuevo(),
                        'requiere_regreso' => PaseSalida::requiereRegreso($datos['motivo']),
                        'estado' => PaseSalida::PENDIENTE,
                        'ronda' => 1,
                    ])->save();
                    foreach ($articulos as $articulo) {
                        $pase->articulos()->create($articulo + ['empresa_id' => $pase->empresa_id]);
                    }
                    $this->anotar($pase, $actor, 'creado', 'Solicitud registrada y enviada a aprobación');
                    $this->iniciarRonda($pase);

                    return $pase;
                });
                break;
            } catch (UniqueConstraintViolationException $e) {
                // Dos altas al mismo tiempo tomaron el mismo folio: se intenta con el siguiente
                if ($intento >= 3) {
                    throw $e;
                }
            }
        }

        $this->auditoria->auditar($actor, 'pases_salida.creado', $pase, null, $this->foto($pase->load('articulos')));
        $this->avisarSiguiente($pase);

        return $pase;
    }

    /**
     * Corregir y reenviar un pase rechazado: se guardan los cambios, empieza
     * una ronda nueva de aprobaciones (desde el primer paso) y se avisa.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function reenviar(User $actor, PaseSalida $pase, array $entrada): void
    {
        if ($pase->estado !== PaseSalida::RECHAZADO) {
            throw ValidationException::withMessages(['estado' => 'Solo un pase rechazado se corrige y se reenvía.']);
        }
        if (! $this->puedeGestionar($actor, $pase)) {
            throw new AuthorizationException('Solo quien registró el pase o el solicitante pueden corregirlo y reenviarlo.');
        }
        [$datos, $articulos] = $this->validar($actor, $entrada);
        $respuesta = $this->comentario($entrada['comentario'] ?? null);
        $antes = $this->foto($pase->load('articulos'));

        DB::transaction(function () use ($pase, $datos, $articulos, $actor, $respuesta) {
            $actual = PaseSalida::query()->lockForUpdate()->findOrFail($pase->id);
            if ($actual->estado !== PaseSalida::RECHAZADO) {
                throw ValidationException::withMessages(['estado' => 'Este pase ya no está rechazado: recarga la pantalla.']);
            }
            $actual->fill($datos)->forceFill([
                'requiere_regreso' => PaseSalida::requiereRegreso($datos['motivo']),
                'estado' => PaseSalida::PENDIENTE,
                'ronda' => $actual->ronda + 1,
            ])->save();
            $actual->articulos()->delete();
            foreach ($articulos as $articulo) {
                $actual->articulos()->create($articulo + ['empresa_id' => $actual->empresa_id]);
            }
            $this->anotar($actual, $actor, 'reenviado', 'Corrigió y reenvió el pase a aprobación (ronda '.$actual->ronda.')', $respuesta);
            $this->iniciarRonda($actual);
        });

        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.reenviado', $pase, $antes, $this->foto($pase->load('articulos')));
        $this->avisarSiguiente($pase);
    }

    /**
     * Aprobar el paso que toca (con firma). Si era el último, el pase queda
     * "Aprobado, listo para salir". Devuelve el estado nuevo si avanzó.
     *
     * @param  array<string, mixed>  $entrada  aprobacion_id, comentario, firma_modo (guardada|nueva), firma, guardar_firma
     */
    public function aprobar(User $actor, PaseSalida $pase, array $entrada): ?string
    {
        $aprobacion = $this->aprobacionEsperada($pase, $entrada);
        if (($motivo = $this->circuito->motivoNoPuede($actor, $pase, $aprobacion)) !== null) {
            throw new AuthorizationException($motivo);
        }
        $comentario = $this->comentario($entrada['comentario'] ?? null);
        [$ruta, $guardar] = $this->firmaDelActor($actor, $entrada);

        try {
            $completo = DB::transaction(function () use ($pase, $aprobacion, $actor, $comentario, $ruta) {
                $actual = $this->bloquearEnPaso($pase, $aprobacion);
                $total = $this->circuito->rondaActual($actual)->count();
                $bitacora = $this->anotar($actual, $actor, 'aprobado', "Aprobó: {$aprobacion->nombre} (paso {$aprobacion->orden} de {$total})", $comentario);
                $firma = $this->crearFirma($actual, $bitacora, 'aprobacion', 'aprobacion', $actor->name, $actor->id, $aprobacion->nombre, $ruta);
                $aprobacion->forceFill([
                    'estado' => PaseSalidaAprobacion::APROBADO, 'resuelto_por' => $actor->id, 'resuelto_en' => now(),
                    'comentario' => $comentario, 'firma_id' => $firma->id,
                ])->save();

                return $this->avanzarSiCompleto($actual);
            });
        } catch (\Throwable $e) {
            $this->firmas->borrar($ruta);
            throw $e;
        }

        $this->guardarFirmaUsuario($actor, $guardar);
        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.firmado', $pase, null, [
            'folio' => $pase->folio, 'paso' => $aprobacion->nombre, 'orden' => $aprobacion->orden, 'ronda' => $pase->ronda, 'comentario' => $comentario,
        ]);
        $this->despuesDeResolver($actor, $pase, $completo);

        return $completo ? PaseSalida::APROBADO : null;
    }

    /**
     * Omitir un paso OPCIONAL (con comentario). Lo hace quien podría firmarlo.
     *
     * @param  array<string, mixed>  $entrada  aprobacion_id, comentario
     */
    public function omitir(User $actor, PaseSalida $pase, array $entrada): ?string
    {
        $aprobacion = $this->aprobacionEsperada($pase, $entrada);
        if ($aprobacion->obligatorio) {
            throw ValidationException::withMessages(['comentario' => 'Este paso es obligatorio: no se puede omitir.']);
        }
        if (($motivo = $this->circuito->motivoNoPuede($actor, $pase, $aprobacion)) !== null) {
            throw new AuthorizationException($motivo);
        }
        $comentario = $this->comentario($entrada['comentario'] ?? null);
        if ($comentario === null) {
            throw ValidationException::withMessages(['comentario' => 'Escribe por qué se omite este paso.']);
        }

        $completo = DB::transaction(function () use ($pase, $aprobacion, $actor, $comentario) {
            $actual = $this->bloquearEnPaso($pase, $aprobacion);
            $this->anotar($actual, $actor, 'omitido', "Omitió el paso opcional: {$aprobacion->nombre}", $comentario);
            $aprobacion->forceFill([
                'estado' => PaseSalidaAprobacion::OMITIDO, 'resuelto_por' => $actor->id, 'resuelto_en' => now(), 'comentario' => $comentario,
            ])->save();

            return $this->avanzarSiCompleto($actual);
        });

        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.paso_omitido', $pase, null, ['folio' => $pase->folio, 'paso' => $aprobacion->nombre, 'comentario' => $comentario]);
        $this->despuesDeResolver($actor, $pase, $completo);

        return $completo ? PaseSalida::APROBADO : null;
    }

    /**
     * Rechazo con motivo obligatorio: el pase vuelve al solicitante, que lo
     * corrige y reenvía o lo cancela (SEGCAT: pases_salida_rechazar.php).
     *
     * @param  array<string, mixed>  $entrada  aprobacion_id, motivo_rechazo
     */
    public function rechazar(User $actor, PaseSalida $pase, array $entrada): void
    {
        $motivo = $this->comentario($entrada['motivo_rechazo'] ?? null);
        if ($motivo === null) {
            throw ValidationException::withMessages(['motivo_rechazo' => 'Escribe el motivo del rechazo: el solicitante lo verá para corregir su pase.']);
        }
        if ($pase->estado !== PaseSalida::PENDIENTE) {
            throw ValidationException::withMessages(['motivo_rechazo' => 'Este pase ya no está pendiente de aprobación.']);
        }
        $aprobacion = $this->aprobacionEsperada($pase, $entrada, 'motivo_rechazo');
        if (($razon = $this->circuito->motivoNoPuede($actor, $pase, $aprobacion)) !== null) {
            throw new AuthorizationException($razon);
        }

        DB::transaction(function () use ($pase, $aprobacion, $actor, $motivo) {
            $actual = $this->bloquearEnPaso($pase, $aprobacion, 'motivo_rechazo');
            $this->anotar($actual, $actor, 'rechazado', "Rechazó en el paso «{$aprobacion->nombre}» y lo devolvió al solicitante", $motivo);
            $aprobacion->forceFill([
                'estado' => PaseSalidaAprobacion::RECHAZADO, 'resuelto_por' => $actor->id, 'resuelto_en' => now(), 'comentario' => $motivo,
            ])->save();
            $actual->forceFill([
                'estado' => PaseSalida::RECHAZADO, 'motivo_rechazo' => $motivo, 'rechazado_en' => now(), 'rechazado_por' => $actor->id,
            ])->save();
        });

        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.rechazado', $pase, ['estado' => PaseSalida::ESTADOS[PaseSalida::PENDIENTE][0]],
            ['folio' => $pase->folio, 'estado' => PaseSalida::ESTADOS[PaseSalida::RECHAZADO][0], 'paso' => $aprobacion->nombre, 'motivo_rechazo' => $motivo]);
        $this->avisarResultado($pase, 'rechazado', $aprobacion->nombre, $motivo);
    }

    /**
     * Cancelar: solo antes de aprobarse (pendiente o rechazado), su dueño.
     *
     * @param  array<string, mixed>  $entrada  motivo_cancelacion (opcional)
     */
    public function cancelar(User $actor, PaseSalida $pase, array $entrada): void
    {
        if (! in_array($pase->estado, [PaseSalida::PENDIENTE, PaseSalida::RECHAZADO], true)) {
            throw ValidationException::withMessages(['motivo_cancelacion' => 'Solo se cancela un pase que aún no se aprueba.']);
        }
        if (! $this->puedeGestionar($actor, $pase)) {
            throw new AuthorizationException('Solo quien registró el pase o el solicitante pueden cancelarlo.');
        }
        $motivo = $this->comentario($entrada['motivo_cancelacion'] ?? null);
        $anterior = $pase->estado;

        DB::transaction(function () use ($pase, $actor, $motivo) {
            $actual = PaseSalida::query()->lockForUpdate()->findOrFail($pase->id);
            if (! in_array($actual->estado, [PaseSalida::PENDIENTE, PaseSalida::RECHAZADO], true)) {
                throw ValidationException::withMessages(['motivo_cancelacion' => 'Este pase ya se aprobó: no se puede cancelar.']);
            }
            $actual->aprobaciones()->where('ronda', $actual->ronda)->where('estado', PaseSalidaAprobacion::PENDIENTE)
                ->update(['estado' => PaseSalidaAprobacion::OMITIDO, 'comentario' => 'Pase cancelado', 'updated_at' => now()]);
            $actual->forceFill(['estado' => PaseSalida::CANCELADO, 'cancelado_en' => now(), 'cancelado_por' => $actor->id, 'motivo_cancelacion' => $motivo])->save();
            $this->anotar($actual, $actor, 'cancelado', 'Canceló el pase', $motivo);
        });

        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.cancelado', $pase, ['estado' => PaseSalida::ESTADOS[$anterior][0]],
            ['folio' => $pase->folio, 'estado' => PaseSalida::ESTADOS[PaseSalida::CANCELADO][0], 'motivo' => $motivo]);
    }

    /**
     * Paso físico de caseta: salida, recepción en destino, salida de regreso o
     * regreso. Verifica los artículos (escaneados o marcados), guarda la firma
     * de la persona que entrega o se lleva el equipo y la de Seguridad.
     * Devuelve el estado nuevo.
     *
     * @param  array<string, mixed>  $entrada  paso, persona_nombre, firma_persona, firma_modo, firma, guardar_firma,
     *                                         verificados[], escaneados[], regresa[id], cerrar_con_faltantes, comentario
     */
    public function registrarPaso(User $actor, PaseSalida $pase, array $entrada): string
    {
        $paso = is_string($entrada['paso'] ?? null) ? $entrada['paso'] : '';
        if (! isset(PaseSalida::PASOS_FISICOS[$paso]) || $pase->pasoFisico() !== $paso) {
            throw ValidationException::withMessages(['paso' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        }
        if (! $this->puedeFirmarPaso($actor, $pase, $paso)) {
            throw new AuthorizationException('No tienes permiso para firmar esta parte del pase.');
        }

        $datos = Validator::make($entrada, [
            'persona_nombre' => ['required', 'string', 'max:150'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'verificados' => ['nullable', 'array'],
            'verificados.*' => ['integer'],
            'escaneados' => ['nullable', 'array'],
            'escaneados.*' => ['integer'],
            'regresa' => ['nullable', 'array'],
            'regresa.*' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'persona_nombre.required' => $paso === 'salida' || $paso === 'salida_regreso'
                ? 'Escribe el nombre de quien se lleva el equipo.' : 'Escribe el nombre de quien entrega el equipo.',
            'persona_nombre.max' => 'El nombre admite máximo 150 caracteres.',
            'comentario.max' => 'El comentario admite máximo 1000 caracteres.',
            'regresa.*.integer' => 'La cantidad que regresa debe ser un número entero.',
            'regresa.*.min' => 'La cantidad que regresa no puede ser negativa.',
        ])->validate();

        $pase->load('articulos');
        $articulos = $pase->articulos->keyBy('id');
        $comentario = $this->comentario($datos['comentario'] ?? null);
        $verificados = array_values(array_intersect(array_map('intval', $datos['verificados'] ?? []), $articulos->keys()->map(fn ($k) => (int) $k)->all()));
        $delPadron = $articulos->filter(fn ($a) => $a->equipo_id !== null)->keys()->map(fn ($k) => (int) $k)->all();
        $escaneados = array_values(array_intersect(array_map('intval', $datos['escaneados'] ?? []), $verificados, $delPadron));
        $regresan = [];
        $cerrarConFaltantes = false;

        if ($paso === 'regreso') {
            foreach ($articulos as $id => $a) {
                $cantidad = (int) ($datos['regresa'][$id] ?? 0);
                if ($cantidad > $a->pendientes()) {
                    throw ValidationException::withMessages(['regresa' => "De «{$a->equipo}» solo faltan {$a->pendientes()} por regresar."]);
                }
                if ($cantidad > 0) {
                    $regresan[(int) $id] = $cantidad;
                }
            }
            if ($regresan === []) {
                throw ValidationException::withMessages(['regresa' => 'Indica cuántos artículos regresan (al menos uno).']);
            }
            $faltan = $articulos->sum(fn ($a) => $a->pendientes()) - array_sum($regresan);
            $cerrarConFaltantes = $faltan > 0 && filter_var($entrada['cerrar_con_faltantes'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($faltan > 0 && $comentario === null) {
                throw ValidationException::withMessages(['comentario' => 'Faltan artículos por regresar: anota en el comentario qué pasó con ellos.']);
            }
        } elseif ($paso === 'salida') {
            $faltan = $articulos->count() - count($verificados);
            if ($faltan > 0) {
                throw ValidationException::withMessages(['verificados' => $faltan === 1
                    ? 'Marca o escanea cada artículo que sale: falta 1.' : "Marca o escanea cada artículo que sale: faltan {$faltan}."]);
            }
        } elseif (count($verificados) < $articulos->count() && $comentario === null) {
            throw ValidationException::withMessages(['comentario' => 'Hay artículos sin marcar: anota en el comentario qué falta o qué llegó diferente.']);
        }

        $persona = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $datos['persona_nombre'])), 'UTF-8');
        $rutaPersona = $this->firmas->guardar($entrada['firma_persona'] ?? null, 'pases-salida', 'firma_persona', 'la firma de '.($paso === 'salida' || $paso === 'salida_regreso' ? 'quien se lleva el equipo' : 'quien entrega el equipo'));
        try {
            [$rutaSeguridad, $guardar] = $this->firmaDelActor($actor, $entrada);
        } catch (\Throwable $e) {
            $this->firmas->borrar($rutaPersona);
            throw $e;
        }

        [$estado, $columna, $evento, $texto] = self::AVANCE[$paso];
        if ($paso === 'regreso' && $faltan > 0 && ! $cerrarConFaltantes) {
            $estado = PaseSalida::REGRESO_PARCIAL;
        }

        try {
            DB::transaction(function () use ($pase, $paso, $actor, $persona, $rutaPersona, $rutaSeguridad, $comentario, $verificados, $escaneados, $regresan, $articulos, $estado, $columna, $texto, $cerrarConFaltantes) {
                $actual = PaseSalida::query()->lockForUpdate()->findOrFail($pase->id);
                if ($actual->pasoFisico() !== $paso) {
                    throw ValidationException::withMessages(['paso' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
                }
                [$titulo, , , $roles] = PaseSalida::PASOS_FISICOS[$paso];
                [$rolPersona, $rolSeguridad] = array_keys($roles);
                $detalle = ['paso' => $paso, 'persona' => $persona];
                if ($paso === 'regreso') {
                    $detalle['regresan'] = collect($regresan)->map(fn ($c, $id) => $c.'x '.$articulos[$id]->equipo)->values()->all();
                    $detalle['faltan'] = $articulos->sum(fn ($a) => $a->pendientes()) - array_sum($regresan);
                } else {
                    $detalle['verificados'] = count($verificados).' de '.$articulos->count();
                    $detalle['con_lector'] = count($escaneados);
                }
                $tituloBitacora = match (true) {
                    $estado === PaseSalida::REGRESO_PARCIAL => 'Registró un regreso parcial',
                    $cerrarConFaltantes => 'Registró el regreso y cerró el pase con faltantes',
                    default => $texto,
                };
                $bitacora = $this->anotar($actual, $actor, $estado === PaseSalida::REGRESO_PARCIAL ? 'regreso_parcial' : $paso, $tituloBitacora, $comentario, $detalle);
                $this->crearFirma($actual, $bitacora, $paso, $rolPersona, $persona, null, $roles[$rolPersona], $rutaPersona);
                $this->crearFirma($actual, $bitacora, $paso, $rolSeguridad, $actor->name, $actor->id, $roles[$rolSeguridad], $rutaSeguridad);

                if ($paso === 'salida') {
                    foreach ($verificados as $id) {
                        $articulos[$id]->forceFill(['verificado_salida_en' => now(), 'verificado_con_lector' => in_array($id, $escaneados, true)])->save();
                    }
                }
                foreach ($regresan as $id => $cantidad) {
                    $articulos[$id]->forceFill(['cantidad_regresada' => $articulos[$id]->cantidad_regresada + $cantidad])->save();
                }

                $cambios = ['estado' => $estado];
                if ($estado !== PaseSalida::REGRESO_PARCIAL) {
                    $cambios[$columna] = now();
                }
                if ($cerrarConFaltantes) {
                    $cambios['cerrado_con_faltantes'] = true;
                }
                $actual->forceFill($cambios)->save();
            });
        } catch (\Throwable $e) {
            $this->firmas->borrar($rutaPersona);
            $this->firmas->borrar($rutaSeguridad);
            throw $e;
        }

        $this->guardarFirmaUsuario($actor, $guardar);
        $pase->refresh();
        $this->auditoria->auditar($actor, $estado === PaseSalida::REGRESO_PARCIAL ? 'pases_salida.regreso_parcial' : self::AVANCE[$paso][2], $pase,
            null, ['folio' => $pase->folio, 'estado' => $pase->insignia(false)[0], 'persona' => $persona, 'comentario' => $comentario]);

        return $estado;
    }

    // ------------------------------------------------------------ Firma propia

    public function firmaGuardada(User $actor): ?FirmaUsuario
    {
        return FirmaUsuario::where('user_id', $actor->id)->first();
    }

    /**
     * Guarda (o reemplaza) la firma del usuario, solo si él lo pidió.
     */
    public function guardarFirmaUsuario(User $actor, ?string $dataUrl): void
    {
        if ($dataUrl === null) {
            return;
        }
        $ruta = $this->firmas->guardar($dataUrl, 'usuarios', 'firma', 'tu firma');
        $anterior = $this->firmaGuardada($actor);
        if ($anterior !== null) {
            $this->firmas->borrar($anterior->firma_ruta);
            $anterior->forceFill(['firma_ruta' => $ruta])->save();
        } else {
            FirmaUsuario::create(['user_id' => $actor->id, 'firma_ruta' => $ruta]);
        }
        $this->auditoria->auditar($actor, 'pases_salida.firma_guardada', $this->firmaGuardada($actor), null, ['usuario' => $actor->name]);
    }

    public function borrarFirmaUsuario(User $actor): bool
    {
        $firma = $this->firmaGuardada($actor);
        if ($firma === null) {
            return false;
        }
        $this->firmas->borrar($firma->firma_ruta);
        $this->auditoria->auditar($actor, 'pases_salida.firma_borrada', $firma, ['usuario' => $actor->name], null);
        $firma->delete();

        return true;
    }

    // ------------------------------------------------------------- Presentación

    /**
     * Pasos del indicador (Solicitud → Aprobaciones → Salida → En destino /
     * Fuera → Regreso → Cerrado), cada uno con su estado: hecho, actual,
     * pendiente, error (rechazado o vencido) o cancelado.
     *
     * @return list<array{clave: string, texto: string, estado: string, detalle: ?string}>
     */
    public function etapas(PaseSalida $pase, bool $vencido = false): array
    {
        $ronda = $this->circuito->rondaActual($pase);
        $resueltas = $ronda->filter->resuelta()->count();
        $orden = ['solicitud' => 0, 'aprobaciones' => 1, 'salida' => 2, 'fuera' => 3, 'regreso' => 4, 'cerrado' => 5];
        $actual = match ($pase->estado) {
            PaseSalida::RECHAZADO => 'solicitud',
            PaseSalida::PENDIENTE => 'aprobaciones',
            PaseSalida::APROBADO => 'salida',
            PaseSalida::SALIO => $pase->requiere_regreso ? 'fuera' : 'cerrado',
            PaseSalida::EN_DESTINO => 'fuera',
            PaseSalida::EN_TRANSITO_REGRESO, PaseSalida::REGRESO_PARCIAL => 'regreso',
            PaseSalida::CANCELADO => $pase->aprobado_en ? 'salida' : ($ronda->contains('estado', PaseSalidaAprobacion::APROBADO) ? 'aprobaciones' : 'solicitud'),
            default => 'cerrado',
        };
        $claves = $pase->requiere_regreso ? array_keys($orden) : ['solicitud', 'aprobaciones', 'salida', 'cerrado'];
        $textos = [
            'solicitud' => 'Solicitud', 'aprobaciones' => 'Aprobaciones', 'salida' => 'Salida',
            'fuera' => $pase->destino_tipo === 'sede' ? 'En destino' : 'Fuera', 'regreso' => 'Regreso',
            'cerrado' => $pase->estado === PaseSalida::CANCELADO ? 'Cancelado' : 'Cerrado',
        ];
        $terminado = $pase->cerrado() && $pase->estado !== PaseSalida::CANCELADO;

        return array_map(function (string $clave) use ($orden, $actual, $textos, $pase, $vencido, $resueltas, $ronda, $terminado) {
            $estado = match (true) {
                $terminado => 'hecho',
                $pase->estado === PaseSalida::CANCELADO && $clave === 'cerrado' => 'cancelado',
                $pase->estado === PaseSalida::CANCELADO && $orden[$clave] >= $orden[$actual] => 'pendiente',
                $orden[$clave] < $orden[$actual] => 'hecho',
                $clave === $actual && $pase->estado === PaseSalida::RECHAZADO => 'error',
                $clave === $actual => $vencido ? 'error' : 'actual',
                $clave === 'aprobaciones' && $pase->estado === PaseSalida::RECHAZADO => 'error',
                default => 'pendiente',
            };
            $detalle = match (true) {
                $clave === 'aprobaciones' && $ronda->isNotEmpty() => $resueltas.' de '.$ronda->count(),
                $clave === 'solicitud' && $pase->estado === PaseSalida::RECHAZADO => 'Devuelto',
                $clave === 'regreso' && $pase->estado === PaseSalida::REGRESO_PARCIAL => 'Parcial',
                $clave === $actual && $vencido => 'Vencido',
                default => null,
            };

            return ['clave' => $clave, 'texto' => $textos[$clave], 'estado' => $estado, 'detalle' => $detalle];
        }, $claves);
    }

    /**
     * Quién debe actuar ahora, en palabras ("Espera la firma de: Contraloría…").
     */
    public function siguiente(PaseSalida $pase): ?string
    {
        $sede = fn (?int $id) => $id === (int) $pase->sede_id ? ($pase->sede?->nombre ?? 'origen') : ($pase->sedeDestino?->nombre ?? 'destino');

        return match ($pase->estado) {
            PaseSalida::PENDIENTE => ($a = $this->circuito->actual($pase)) === null ? null
                : "Espera la firma de: {$a->nombre} (paso {$a->orden} de {$this->circuito->rondaActual($pase)->count()}) · {$a->quienFirma()}",
            PaseSalida::RECHAZADO => 'Devuelto al solicitante: corregir y reenviar, o cancelar.',
            PaseSalida::APROBADO => 'Caseta de '.$sede((int) $pase->sede_id).': registrar la salida.',
            default => match ($pase->pasoFisico()) {
                'recepcion' => 'Caseta de '.$sede($pase->sede_destino_id).': confirmar la llegada.',
                'salida_regreso' => 'Caseta de '.$sede($pase->sede_destino_id).': autorizar la salida de regreso.',
                'regreso' => 'Caseta de '.$sede((int) $pase->sede_id).': registrar el regreso'
                    .($pase->estado === PaseSalida::REGRESO_PARCIAL ? ' de lo que falta.' : '.'),
                default => null,
            },
        };
    }

    /**
     * Nombre que se propone para quien entrega o se lleva el equipo, para
     * teclear lo menos posible: al salir, el colaborador destino o el
     * solicitante; después, quien lo llevó.
     */
    public function personaSugerida(PaseSalida $pase): string
    {
        $ultima = $pase->relationLoaded('bitacora')
            ? $pase->bitacora->reverse()->first(fn ($b) => isset($b->detalle['persona']))
            : $pase->bitacora()->whereIn('evento', ['salida', 'recepcion', 'salida_regreso', 'regreso_parcial'])->orderByDesc('id')->first();
        $nombre = $ultima?->detalle['persona'] ?? ($pase->colaboradorDestino?->nombreCompleto() ?? $pase->solicitante?->nombreCompleto() ?? '');

        return mb_strtoupper((string) $nombre, 'UTF-8');
    }

    // ------------------------------------------------------------------ Avisos

    /**
     * Recordatorio diario de los pases vencidos (lo llama el comando
     * plataforma:pases-vencidos desde desplegar.sh; corre en proceso, sin exec).
     * Un recordatorio por pase y por día local de su sede. Con $siToca, solo
     * después de las 8:00 de la sede.
     *
     * @return int correos enviados
     */
    public function recordatoriosVencidos(bool $siToca = true): int
    {
        $enviados = 0;
        foreach (Empresa::where('activo', true)->get(['id', 'preferencias']) as $empresa) {
            if (! $empresa->aviso('pase_salida_vencido')) {
                continue;
            }
            $enviados += app(Tenant::class)->conEmpresa($empresa->id, function () use ($siToca) {
                $this->hoy = null;
                $horas = Sede::withTrashed()->with('empresa:id,zona_horaria')->get(['id', 'empresa_id', 'zona_horaria'])
                    ->mapWithKeys(fn (Sede $s) => [(int) $s->id => (int) now($s->zonaHoraria())->format('G')])->all();
                $cuenta = 0;
                $pases = $this->filtrar(PaseSalida::query(), 'vencidos')
                    ->with(['sede:id,nombre', 'sedeDestino:id,nombre', 'proveedor:id,nombre', 'colaboradorDestino:id,nombre,apellido_paterno,apellido_materno',
                        'solicitante:id,nombre,apellido_paterno,apellido_materno'])->withCount('articulos')->get();
                foreach ($pases as $pase) {
                    $hoy = $this->hoyDe($pase);
                    if ($pase->recordatorio_vencido_en?->format('Y-m-d') === $hoy || ($siToca && ($horas[(int) $pase->sede_id] ?? 0) < 8)) {
                        continue;
                    }
                    $correos = [...$this->correosDuenos($pase), ...$this->avisos->conPermiso($pase->empresa_id, 'pases_salida.aprobar', $pase->sede_id)];
                    $enviado = $this->avisos->paseSalida($pase->empresa_id, 'pase_salida_vencido', $correos,
                        $this->mensaje($pase, 'vencido', null, null), false);
                    $pase->forceFill(['recordatorio_vencido_en' => $hoy])->saveQuietly();
                    if ($enviado) {
                        $cuenta++;
                        $this->anotar($pase, null, 'recordatorio', 'Se envió el recordatorio de vencido por correo');
                    }
                }

                return $cuenta;
            });
        }

        return $enviados;
    }

    private function avisarSiguiente(PaseSalida $pase): void
    {
        $pase->load(['aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre', 'sede:id,nombre', 'sedeDestino:id,nombre', 'proveedor:id,nombre', 'colaboradorDestino:id,nombre,apellido_paterno,apellido_materno',
            'solicitante:id,nombre,apellido_paterno,apellido_materno,departamento_id'])->loadCount('articulos');
        $aprobacion = $this->circuito->actual($pase);
        if ($aprobacion === null) {
            return;
        }
        $correos = $this->circuito->aprobadores($pase, $aprobacion)->pluck('email')->filter()->values()->all();
        $this->avisos->paseSalida($pase->empresa_id, 'pase_salida_aprobacion', $correos, $this->mensaje($pase, 'por_aprobar', $aprobacion->nombre, null));
    }

    private function avisarResultado(PaseSalida $pase, string $tipo, ?string $paso, ?string $comentario): void
    {
        $pase->load(['sede:id,nombre', 'sedeDestino:id,nombre', 'proveedor:id,nombre', 'colaboradorDestino:id,nombre,apellido_paterno,apellido_materno',
            'solicitante:id,nombre,apellido_paterno,apellido_materno'])->loadCount('articulos');
        $this->avisos->paseSalida($pase->empresa_id, 'pase_salida_resultado', $this->correosDuenos($pase), $this->mensaje($pase, $tipo, $paso, $comentario));
    }

    /**
     * Correo de quien registró el pase y de los usuarios ligados al solicitante.
     *
     * @return list<string>
     */
    private function correosDuenos(PaseSalida $pase): array
    {
        return User::where('empresa_id', $pase->empresa_id)->where('activo', true)->whereNotNull('email')
            ->where(fn ($q) => $q->where('id', $pase->creado_por)->orWhere('colaborador_id', $pase->colaborador_id))
            ->pluck('email')->unique()->values()->all();
    }

    private function mensaje(PaseSalida $pase, string $tipo, ?string $paso, ?string $comentario): AvisoPaseSalida
    {
        $tentativa = PaseSalida::dia($pase->fecha_tentativa_regreso);

        return new AvisoPaseSalida(
            $tipo, $pase->folio, $pase->etiquetaMotivo(), $pase->solicitante?->nombreCompleto(), $pase->sede?->nombre, $pase->nombreDestino(),
            (int) ($pase->articulos_count ?? $pase->articulos()->count()), $paso, $comentario,
            $tentativa ? app(HoraLocal::class)->formatear($tentativa, 'd/m/Y') : null,
            route('pases-salida.show', $pase->id),
        );
    }

    private function despuesDeResolver(User $actor, PaseSalida $pase, bool $completo): void
    {
        if ($completo) {
            $this->auditoria->auditar($actor, 'pases_salida.aprobado', $pase, ['estado' => PaseSalida::ESTADOS[PaseSalida::PENDIENTE][0]],
                ['folio' => $pase->folio, 'estado' => PaseSalida::ESTADOS[PaseSalida::APROBADO][0]]);
            $this->avisarResultado($pase, 'aprobado', null, null);
        } else {
            $this->avisarSiguiente($pase);
        }
    }

    // --------------------------------------------------------------- Opciones

    /**
     * Sedes de origen donde puede registrar pases (activas y en su alcance).
     *
     * @return Collection<int, Sede>
     */
    public function sedesOrigen(User $actor)
    {
        $permitidas = $this->sedes($actor, 'pases_salida.crear');

        return Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre', 'direccion', 'colonia', 'ciudad', 'telefono']);
    }

    /**
     * Datos del equipo escaneado para llenar un renglón de artículos.
     *
     * @return array{equipo_id: int, equipo: string, marca: ?string, modelo: ?string, serie: string, descripcion: ?string}
     */
    public function articuloDeEquipo(Equipo $equipo): array
    {
        $equipo->loadMissing('tipo:id,nombre');

        return [
            'equipo_id' => $equipo->id,
            'equipo' => $equipo->tipo?->nombre ?? 'Equipo',
            'marca' => $equipo->marca,
            'modelo' => $equipo->modelo,
            'serie' => $equipo->numero_serie,
            'descripcion' => null,
        ];
    }

    // ---------------------------------------------------------------- Internos

    /**
     * Copia el circuito al pase; si ningún paso aplica a su motivo, queda aprobado.
     */
    private function iniciarRonda(PaseSalida $pase): void
    {
        if ($this->circuito->instanciar($pase) === 0) {
            $pase->forceFill(['estado' => PaseSalida::APROBADO, 'aprobado_en' => now()])->save();
            $this->anotar($pase, null, 'aprobado', 'Sin pasos de aprobación para este motivo: queda aprobado');
        }
    }

    /**
     * El paso que el formulario quería firmar debe ser el que toca.
     *
     * @param  array<string, mixed>  $entrada
     */
    private function aprobacionEsperada(PaseSalida $pase, array $entrada, string $campo = 'aprobacion_id'): PaseSalidaAprobacion
    {
        $actual = $this->circuito->actual($pase->load(['aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre']));
        if ($actual === null || (isset($entrada['aprobacion_id']) && (int) $entrada['aprobacion_id'] !== (int) $actual->id)) {
            throw ValidationException::withMessages([$campo => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        }

        return $actual;
    }

    /**
     * Bloquea el pase (dos firmas simultáneas no se saltan el circuito) y
     * confirma que el paso sigue siendo el que toca.
     */
    private function bloquearEnPaso(PaseSalida $pase, PaseSalidaAprobacion $aprobacion, string $campo = 'aprobacion_id'): PaseSalida
    {
        $actual = PaseSalida::query()->lockForUpdate()->findOrFail($pase->id);
        $vigente = $this->circuito->actual($actual->load(['aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre']));
        if ($vigente === null || (int) $vigente->id !== (int) $aprobacion->id) {
            throw ValidationException::withMessages([$campo => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        }

        return $actual;
    }

    private function avanzarSiCompleto(PaseSalida $pase): bool
    {
        $pendientes = $pase->aprobaciones()->where('ronda', $pase->ronda)->where('estado', PaseSalidaAprobacion::PENDIENTE)->exists();
        if ($pendientes) {
            return false;
        }
        $pase->forceFill(['estado' => PaseSalida::APROBADO, 'aprobado_en' => now()])->save();

        return true;
    }

    /**
     * Firma del usuario en sesión: la guardada (se copia al pase) o la que
     * dibujó ahora. Devuelve [ruta en el pase, firma a guardar como suya o null].
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: string, 1: ?string}
     */
    private function firmaDelActor(User $actor, array $entrada): array
    {
        if (($entrada['firma_modo'] ?? null) === 'guardada') {
            $guardada = $this->firmaGuardada($actor);
            if ($guardada === null || ! Storage::disk('local')->exists($guardada->firma_ruta)) {
                throw ValidationException::withMessages(['firma' => 'No tienes una firma guardada: firma en el recuadro.']);
            }
            $tipo = str_ends_with($guardada->firma_ruta, '.png') ? 'png' : 'jpeg';
            $dataUrl = 'data:image/'.$tipo.';base64,'.base64_encode((string) Storage::disk('local')->get($guardada->firma_ruta));

            return [$this->firmas->guardar($dataUrl, 'pases-salida', 'firma', 'tu firma'), null];
        }

        $dibujada = is_string($entrada['firma'] ?? null) ? $entrada['firma'] : null;
        $ruta = $this->firmas->guardar($dibujada, 'pases-salida', 'firma', 'tu firma');

        return [$ruta, filter_var($entrada['guardar_firma'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $dibujada : null];
    }

    private function crearFirma(PaseSalida $pase, PaseSalidaBitacora $bitacora, string $grupo, string $rol, string $nombre, ?int $usuario, string $cargo, string $ruta): PaseSalidaFirma
    {
        return PaseSalidaFirma::create([
            'empresa_id' => $pase->empresa_id, 'pase_salida_id' => $pase->id, 'bitacora_id' => $bitacora->id, 'grupo' => $grupo, 'rol' => $rol,
            'nombre_firma' => mb_strtoupper(trim($nombre), 'UTF-8'), 'user_id' => $usuario, 'cargo' => $cargo, 'firma_ruta' => $ruta,
        ]);
    }

    /**
     * Una línea en la bitácora del pase (quién, cuándo, comentario e IP).
     *
     * @param  array<string, mixed>|null  $detalle
     */
    private function anotar(PaseSalida $pase, ?User $actor, string $evento, string $titulo, ?string $comentario = null, ?array $detalle = null): PaseSalidaBitacora
    {
        return PaseSalidaBitacora::create([
            'empresa_id' => $pase->empresa_id, 'pase_salida_id' => $pase->id, 'evento' => $evento, 'titulo' => mb_substr($titulo, 0, 150),
            'comentario' => $comentario, 'detalle' => $detalle, 'user_id' => $actor?->id, 'usuario_nombre' => $actor?->name,
            'ip' => $actor !== null ? request()->ip() : null,
        ]);
    }

    private function comentario(mixed $texto): ?string
    {
        if (! is_string($texto)) {
            return null;
        }
        $texto = trim($texto);
        if (mb_strlen($texto) > 1000) {
            throw ValidationException::withMessages(['comentario' => 'El comentario admite máximo 1000 caracteres.']);
        }

        return $texto === '' ? null : $texto;
    }

    private function codigoNuevo(): string
    {
        do {
            $codigo = Str::upper(Str::random(20));
        } while (PaseSalida::withoutGlobalScopes()->where('codigo_verificacion', $codigo)->exists());

        return $codigo;
    }

    // ------------------------------------------------------------- Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validar(User $actor, array $entrada): array
    {
        // Renglones vacíos (sin "Equipo") se ignoran, como en SEGCAT
        $articulos = collect(is_array($entrada['articulos'] ?? null) ? $entrada['articulos'] : [])
            ->filter(fn ($a) => is_array($a) && trim((string) ($a['equipo'] ?? '')) !== '')
            ->values()->all();
        $entrada['articulos'] = $articulos;
        foreach (['destino_direccion', 'destino_telefono'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = trim($entrada[$campo]);
            }
        }

        $datos = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', Rule::in(array_keys(PaseSalida::MOTIVOS))],
            'colaborador_id' => ['required', 'integer'],
            'destino_tipo' => ['required', 'string', Rule::in(array_keys(PaseSalida::DESTINOS))],
            'sede_destino_id' => ['nullable', 'required_if:destino_tipo,sede', 'integer'],
            'proveedor_id' => ['nullable', 'required_if:destino_tipo,proveedor', 'integer'],
            'colaborador_destino_id' => ['nullable', 'required_if:destino_tipo,colaborador', 'integer'],
            'destino_direccion' => ['nullable', 'string', 'max:255'],
            'destino_telefono' => ['nullable', 'string', 'regex:'.self::TELEFONO],
            'fecha_salida_programada' => ['nullable', 'date_format:Y-m-d'],
            'fecha_tentativa_regreso' => ['nullable', 'date_format:Y-m-d',
                ...(is_string($entrada['fecha_salida_programada'] ?? null) && $entrada['fecha_salida_programada'] !== '' ? ['after_or_equal:fecha_salida_programada'] : [])],
            'articulos' => ['required', 'array', 'max:'.self::MAX_ARTICULOS],
            'articulos.*.cantidad' => ['required', 'integer', 'min:1', 'max:9999'],
            'articulos.*.equipo' => ['required', 'string', 'max:150'],
            'articulos.*.marca' => ['nullable', 'string', 'max:100'],
            'articulos.*.modelo' => ['nullable', 'string', 'max:100'],
            'articulos.*.serie' => ['nullable', 'string', 'max:100'],
            'articulos.*.descripcion' => ['nullable', 'string', 'max:500'],
            'articulos.*.equipo_id' => ['nullable', 'integer'],
        ], [
            'sede_id.required' => 'Elige la sede de origen (de donde sale el equipo).',
            'motivo.required' => 'Elige el motivo de salida.',
            'motivo.in' => 'Motivo de salida no válido.',
            'colaborador_id.required' => 'Elige al solicitante: escanea su gafete, busca su nombre o regístralo con «Nuevo Colaborador».',
            'destino_tipo.required' => 'Elige el tipo de destino.',
            'destino_tipo.in' => 'Tipo de destino no válido.',
            'sede_destino_id.required_if' => 'Elige la sede destino.',
            'proveedor_id.required_if' => 'Elige el proveedor destino (o regístralo con «Nuevo Proveedor»).',
            'colaborador_destino_id.required_if' => 'Elige el colaborador que se lleva el equipo.',
            'destino_direccion.max' => 'La dirección de destino admite máximo 255 caracteres.',
            'destino_telefono.regex' => 'Revisa el teléfono: solo números (7 a 20), con "+" si es de otro país.',
            'fecha_salida_programada.date_format' => 'La fecha de salida programada no es válida.',
            'fecha_tentativa_regreso.date_format' => 'La fecha tentativa de regreso no es válida.',
            'fecha_tentativa_regreso.after_or_equal' => 'La fecha tentativa de regreso no puede ser antes de la fecha de salida.',
            'articulos.required' => 'Agrega al menos un artículo que salga en el pase.',
            'articulos.max' => 'Un pase admite máximo '.self::MAX_ARTICULOS.' artículos.',
            'articulos.*.cantidad.required' => 'Escribe la cantidad de cada artículo.',
            'articulos.*.cantidad.integer' => 'La cantidad debe ser un número entero.',
            'articulos.*.cantidad.min' => 'La cantidad mínima es 1.',
            'articulos.*.cantidad.max' => 'Revisa la cantidad: es demasiado alta.',
            'articulos.*.equipo.max' => 'El nombre del equipo admite máximo 150 caracteres.',
            'articulos.*.marca.max' => 'La marca admite máximo 100 caracteres.',
            'articulos.*.modelo.max' => 'El modelo admite máximo 100 caracteres.',
            'articulos.*.serie.max' => 'El número de serie admite máximo 100 caracteres.',
            'articulos.*.descripcion.max' => 'La descripción de un artículo admite máximo 500 caracteres.',
        ])->validate();

        // Sede de origen: activa y dentro de su alcance para crear
        $origen = $this->sedesOrigen($actor)->firstWhere('id', (int) $datos['sede_id']);
        if ($origen === null) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede de origen activa en la que puedas registrar pases.']);
        }

        // Solicitante: cualquier colaborador activo de la empresa (también el corporativo, sin sede)
        $solicitante = Colaborador::where('activo', true)->whereNull('fusionado_en_id')->find((int) $datos['colaborador_id']);
        if ($solicitante === null) {
            throw ValidationException::withMessages(['colaborador_id' => 'Solicitante no válido: búscalo o regístralo antes de continuar.']);
        }

        $destino = ['sede_destino_id' => null, 'proveedor_id' => null, 'colaborador_destino_id' => null];
        switch ($datos['destino_tipo']) {
            case 'sede':
                $sedeDestino = (int) $datos['sede_destino_id'];
                if ($sedeDestino === $origen->id) {
                    throw ValidationException::withMessages(['sede_destino_id' => 'La sede de destino no puede ser la misma que la sede de origen: elige una distinta.']);
                }
                if (! Sede::where('activo', true)->whereKey($sedeDestino)->exists()) {
                    throw ValidationException::withMessages(['sede_destino_id' => 'Elige una sede destino activa de la empresa.']);
                }
                $destino['sede_destino_id'] = $sedeDestino;
                break;
            case 'proveedor':
                if (! Proveedor::where('activo', true)->whereKey((int) $datos['proveedor_id'])->exists()) {
                    throw ValidationException::withMessages(['proveedor_id' => 'Elige un proveedor activo de la empresa.']);
                }
                $destino['proveedor_id'] = (int) $datos['proveedor_id'];
                break;
            default:
                if (! Colaborador::where('activo', true)->whereNull('fusionado_en_id')->whereKey((int) $datos['colaborador_destino_id'])->exists()) {
                    throw ValidationException::withMessages(['colaborador_destino_id' => 'Elige el colaborador que se lleva el equipo.']);
                }
                $destino['colaborador_destino_id'] = (int) $datos['colaborador_destino_id'];
        }

        $conRegreso = PaseSalida::requiereRegreso($datos['motivo']);

        // Equipos escaneados: solo se ligan si son de la empresa (si no, se guarda solo el texto)
        $idsEquipo = collect($datos['articulos'])->pluck('equipo_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $equiposValidos = $idsEquipo === [] ? [] : Equipo::whereIn('id', $idsEquipo)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $limpio = fn ($v) => ($t = trim((string) preg_replace('/\s+/u', ' ', (string) $v))) === '' ? null : $t;
        $renglones = array_map(fn (array $a) => [
            'equipo_id' => isset($a['equipo_id']) && in_array((int) $a['equipo_id'], $equiposValidos, true) ? (int) $a['equipo_id'] : null,
            'cantidad' => (int) $a['cantidad'],
            'equipo' => $limpio($a['equipo']),
            'marca' => $limpio($a['marca'] ?? null),
            'modelo' => $limpio($a['modelo'] ?? null),
            'serie' => $limpio($a['serie'] ?? null),
            'descripcion' => isset($a['descripcion']) && trim((string) $a['descripcion']) !== '' ? trim((string) $a['descripcion']) : null,
        ], $datos['articulos']);

        return [[
            'sede_id' => $origen->id,
            'motivo' => $datos['motivo'],
            'colaborador_id' => $solicitante->id,
            'destino_tipo' => $datos['destino_tipo'],
            ...$destino,
            'destino_direccion' => ($datos['destino_direccion'] ?? '') !== '' ? $datos['destino_direccion'] : null,
            'destino_telefono' => ($datos['destino_telefono'] ?? '') !== '' ? $datos['destino_telefono'] : null,
            'fecha_salida_programada' => $datos['fecha_salida_programada'] ?? null,
            'fecha_tentativa_regreso' => $conRegreso ? ($datos['fecha_tentativa_regreso'] ?? null) : null,
        ], $renglones];
    }

    /**
     * Lo que se guarda en la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(PaseSalida $p): array
    {
        return [
            'folio' => $p->folio,
            'sede_id' => $p->sede_id,
            'motivo' => $p->motivo,
            'requiere_regreso' => $p->requiere_regreso,
            'colaborador_id' => $p->colaborador_id,
            'destino_tipo' => $p->destino_tipo,
            'sede_destino_id' => $p->sede_destino_id,
            'proveedor_id' => $p->proveedor_id,
            'colaborador_destino_id' => $p->colaborador_destino_id,
            'destino_direccion' => $p->destino_direccion,
            'destino_telefono' => $p->destino_telefono,
            'fecha_salida_programada' => $p->fecha_salida_programada?->format('Y-m-d'),
            'fecha_tentativa_regreso' => $p->fecha_tentativa_regreso?->format('Y-m-d'),
            'estado' => $p->estado,
            'ronda' => $p->ronda,
            'articulos' => $p->articulos->map(fn ($a) => $a->resumen())->values()->all(),
        ];
    }
}
