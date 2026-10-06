<?php

namespace App\Services\PasesSalida;

use App\Models\Departamento;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaPaso;
use App\Models\Rol;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Circuito de aprobación de los pases de salida.
 *
 * Configuración por empresa (Configuración → Pases de salida, permiso
 * "pases_salida.configurar" con alcance de empresa): pasos ordenados, cada uno
 * definido por un rol, usuarios específicos o "quien tenga Aprobar", con o sin
 * filtro de departamento, obligatorio u opcional, para todos los motivos o
 * solo algunos. Sin configuración se usa la cadena de SEGCAT.
 *
 * Quién firma un paso (puedeAprobar):
 *  1. tiene "pases_salida.aprobar" con alcance en la sede de ORIGEN;
 *  2. no es el solicitante (un usuario ligado a ese colaborador);
 *  3. cumple la regla del paso (rol, usuario, departamento). Si NADIE la
 *     cumple, firma cualquiera con "Aprobar" en la sede (el pase no se atora);
 *  4. no firmó ya otro paso de la misma ronda, salvo que no haya nadie más
 *     que pueda firmarlo.
 * Los pasos van en orden: solo se firma el primero pendiente.
 */
class CircuitoPasesSalida
{
    public const MAX_PASOS = 8;

    /**
     * Cadena de SEGCAT (pases_salida_modal_aprobar.php): Jefe de Departamento,
     * Contraloría y Gerencia, obligatorios y para todos los motivos.
     */
    public const PREDETERMINADO = [
        ['nombre' => 'Jefe de Departamento', 'tipo' => 'permiso', 'departamento' => 'solicitante'],
        ['nombre' => 'Contraloría', 'tipo' => 'permiso', 'departamento' => 'cualquiera'],
        ['nombre' => 'Gerencia', 'tipo' => 'permiso', 'departamento' => 'cualquiera'],
    ];

    /** @var array<string, Collection<int, User>> usuarios con "aprobar" por sede */
    private array $candidatos = [];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ----------------------------------------------------------- Configuración

    /**
     * Pasos activos de la empresa de trabajo, en orden. Sin configuración, los
     * de SEGCAT (sin guardar: modelos nuevos).
     *
     * @return Collection<int, PaseSalidaPaso>
     */
    public function pasos(): Collection
    {
        $guardados = PaseSalidaPaso::with(['rol:id,nombre', 'departamentoFijo:id,nombre', 'usuarios:id,name'])
            ->where('activo', true)->orderBy('orden')->get();

        return $guardados->isNotEmpty() ? $guardados : $this->predeterminados();
    }

    public function configurado(): bool
    {
        return PaseSalidaPaso::where('activo', true)->exists();
    }

    /**
     * @return Collection<int, PaseSalidaPaso>
     */
    public function predeterminados(): Collection
    {
        return collect(self::PREDETERMINADO)->values()->map(function (array $p, int $i) {
            $paso = new PaseSalidaPaso(['orden' => $i + 1, 'obligatorio' => true, 'motivos' => null] + $p);
            $paso->setRelation('usuarios', collect());

            return $paso;
        });
    }

    /**
     * Guarda el circuito completo (reemplaza al anterior). Los pases en curso
     * conservan su copia.
     *
     * @param  array<string, mixed>  $entrada  pasos[i][nombre|tipo|rol_id|usuarios[]|departamento|departamento_id|obligatorio|motivos[]]
     */
    public function guardar(User $actor, array $entrada): void
    {
        $pasos = collect(is_array($entrada['pasos'] ?? null) ? $entrada['pasos'] : [])->filter(fn ($p) => is_array($p))->values()->all();

        $datos = Validator::make(['pasos' => $pasos], [
            'pasos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PASOS],
            'pasos.*.nombre' => ['required', 'string', 'max:120'],
            'pasos.*.tipo' => ['required', Rule::in(array_keys(PaseSalidaPaso::TIPOS))],
            'pasos.*.rol_id' => ['nullable', 'required_if:pasos.*.tipo,rol', 'integer'],
            'pasos.*.usuarios' => ['nullable', 'required_if:pasos.*.tipo,usuarios', 'array', 'max:20'],
            'pasos.*.usuarios.*' => ['integer'],
            'pasos.*.departamento' => ['nullable', Rule::in(array_keys(PaseSalidaPaso::DEPARTAMENTOS))],
            'pasos.*.departamento_id' => ['nullable', 'required_if:pasos.*.departamento,especifico', 'integer'],
            'pasos.*.obligatorio' => ['nullable'],
            'pasos.*.motivos' => ['nullable', 'array'],
            'pasos.*.motivos.*' => [Rule::in(array_keys(PaseSalida::MOTIVOS))],
        ], [
            'pasos.required' => 'El circuito necesita al menos un paso de aprobación.',
            'pasos.min' => 'El circuito necesita al menos un paso de aprobación.',
            'pasos.max' => 'El circuito admite máximo '.self::MAX_PASOS.' pasos.',
            'pasos.*.nombre.required' => 'Escribe el nombre de cada paso (por ejemplo «Contraloría»).',
            'pasos.*.nombre.max' => 'El nombre de un paso admite máximo 120 caracteres.',
            'pasos.*.tipo.required' => 'Elige quién firma cada paso.',
            'pasos.*.tipo.in' => 'Elige quién firma cada paso.',
            'pasos.*.rol_id.required_if' => 'Elige el rol que firma el paso.',
            'pasos.*.usuarios.required_if' => 'Elige al menos un usuario que firme el paso.',
            'pasos.*.usuarios.max' => 'Elige máximo 20 usuarios por paso.',
            'pasos.*.departamento_id.required_if' => 'Elige el departamento del paso.',
            'pasos.*.motivos.*.in' => 'Motivo de salida no válido.',
        ])->validate();

        $empresaId = app(Tenant::class)->empresaId();
        $roles = Rol::where('empresa_id', $empresaId)->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $departamentos = Departamento::where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $usuariosEmpresa = User::where('empresa_id', $empresaId)->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $limpios = [];
        foreach ($datos['pasos'] as $i => $p) {
            $tipo = $p['tipo'];
            $departamento = $p['departamento'] ?? 'cualquiera';
            if ($tipo === 'rol' && ! in_array((int) $p['rol_id'], $roles, true)) {
                throw ValidationException::withMessages(["pasos.{$i}.rol_id" => 'Elige un rol activo de la empresa.']);
            }
            $usuarios = $tipo === 'usuarios' ? array_values(array_unique(array_map('intval', $p['usuarios'] ?? []))) : [];
            if (array_diff($usuarios, $usuariosEmpresa) !== []) {
                throw ValidationException::withMessages(["pasos.{$i}.usuarios" => 'Elige usuarios activos de la empresa.']);
            }
            if ($departamento === 'especifico' && ! in_array((int) $p['departamento_id'], $departamentos, true)) {
                throw ValidationException::withMessages(["pasos.{$i}.departamento_id" => 'Elige un departamento activo de la empresa.']);
            }
            $motivos = array_values(array_unique($p['motivos'] ?? []));
            $limpios[] = [
                'orden' => $i + 1,
                'nombre' => trim((string) preg_replace('/\s+/u', ' ', $p['nombre'])),
                'tipo' => $tipo,
                'rol_id' => $tipo === 'rol' ? (int) $p['rol_id'] : null,
                'departamento' => $departamento,
                'departamento_id' => $departamento === 'especifico' ? (int) $p['departamento_id'] : null,
                // Sin motivos marcados, o con todos marcados, aplica a todos
                'motivos' => $motivos === [] || count($motivos) === count(PaseSalida::MOTIVOS) ? null : $motivos,
                'obligatorio' => filter_var($p['obligatorio'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'usuarios' => $usuarios,
            ];
        }
        // Al menos un paso obligatorio para cada motivo
        foreach (array_keys(PaseSalida::MOTIVOS) as $motivo) {
            $obligatorios = array_filter($limpios, fn ($p) => $p['obligatorio'] && ($p['motivos'] === null || in_array($motivo, $p['motivos'], true)));
            if ($obligatorios === []) {
                throw ValidationException::withMessages(['pasos' => 'Cada motivo necesita al menos un paso obligatorio: «'.PaseSalida::MOTIVOS[$motivo].'» no tiene ninguno.']);
            }
        }

        $antes = $this->resumen();
        DB::transaction(function () use ($limpios) {
            PaseSalidaPaso::query()->delete();
            foreach ($limpios as $p) {
                $usuarios = $p['usuarios'];
                unset($p['usuarios']);
                $paso = PaseSalidaPaso::create($p);
                foreach ($usuarios as $id) {
                    $paso->usuarios()->attach($id, ['empresa_id' => $paso->empresa_id]);
                }
            }
        });

        $primero = PaseSalidaPaso::orderBy('orden')->first();
        $this->auditoria->auditar($actor, 'pases_salida.circuito_actualizado', $primero, ['pasos' => $antes], ['pasos' => $this->resumen()]);
    }

    /**
     * Vuelve a la cadena de SEGCAT (borra la configuración de la empresa).
     */
    public function restablecer(User $actor): void
    {
        $antes = $this->resumen();
        $primero = PaseSalidaPaso::orderBy('orden')->first();
        PaseSalidaPaso::query()->delete();
        if ($primero !== null) {
            $this->auditoria->auditar($actor, 'pases_salida.circuito_restablecido', $primero, ['pasos' => $antes], ['pasos' => $this->resumen()]);
        }
    }

    /**
     * Circuito en palabras (para la bitácora de auditoría y la pantalla de Configuración).
     *
     * @return list<string>
     */
    public function resumen(): array
    {
        return $this->pasos()->map(fn (PaseSalidaPaso $p) => $p->orden.'. '.$p->nombre.' — '.$this->quienFirma($p)
            .($p->obligatorio ? '' : ' (opcional)')
            .($p->motivos ? ' · solo '.implode(', ', array_map(fn ($m) => PaseSalida::MOTIVOS[$m] ?? $m, $p->motivos)) : ''))->values()->all();
    }

    public function quienFirma(PaseSalidaPaso $p): string
    {
        $quien = match ($p->tipo) {
            'rol' => 'rol «'.($p->rol?->nombre ?? '—').'»',
            'usuarios' => $p->usuarios->pluck('name')->implode(', ') ?: 'usuarios específicos',
            default => 'quien tenga «Aprobar»',
        };

        return $quien.match ($p->departamento) {
            'solicitante' => ' del departamento del solicitante',
            'especifico' => ' de '.($p->departamentoFijo?->nombre ?? 'un departamento'),
            default => '',
        };
    }

    // ------------------------------------------------- Aprobaciones de un pase

    /**
     * Copia el circuito vigente al pase (ronda actual). Devuelve cuántos pasos aplican.
     */
    public function instanciar(PaseSalida $pase): int
    {
        $pase->loadMissing('solicitante:id,departamento_id');
        $orden = 0;
        foreach ($this->pasos() as $paso) {
            if (! $paso->aplicaA($pase->motivo)) {
                continue;
            }
            $departamento = match ($paso->departamento) {
                'solicitante' => $pase->solicitante?->departamento_id,
                'especifico' => $paso->departamento_id,
                default => null,
            };
            PaseSalidaAprobacion::create([
                'empresa_id' => $pase->empresa_id,
                'pase_salida_id' => $pase->id,
                'ronda' => $pase->ronda,
                'orden' => ++$orden,
                'paso_id' => $paso->exists ? $paso->id : null,
                'nombre' => $paso->nombre,
                'tipo' => $paso->tipo,
                'rol_id' => $paso->tipo === 'rol' ? $paso->rol_id : null,
                'departamento_id' => $departamento,
                'usuarios' => $paso->tipo === 'usuarios' ? $paso->usuarios->pluck('id')->map(fn ($id) => (int) $id)->values()->all() : null,
                'obligatorio' => $paso->obligatorio,
            ]);
        }

        return $orden;
    }

    /**
     * Aprobaciones de la ronda actual del pase, en orden.
     *
     * @return Collection<int, PaseSalidaAprobacion>
     */
    public function rondaActual(PaseSalida $pase): Collection
    {
        $todas = $pase->relationLoaded('aprobaciones') ? $pase->aprobaciones : $pase->aprobaciones()->get();

        return $todas->where('ronda', $pase->ronda)->sortBy('orden')->values();
    }

    /** El primer paso pendiente de la ronda actual (el que toca firmar). */
    public function actual(PaseSalida $pase): ?PaseSalidaAprobacion
    {
        if ($pase->estado !== PaseSalida::PENDIENTE) {
            return null;
        }

        return $this->rondaActual($pase)->firstWhere('estado', PaseSalidaAprobacion::PENDIENTE);
    }

    // ------------------------------------------------------- Quién puede firmar

    /**
     * Usuarios que pueden firmar un paso de un pase (para avisarles por correo
     * y para revisar si alguien más puede hacerlo).
     *
     * @return Collection<int, User>
     */
    public function aprobadores(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): Collection
    {
        $candidatos = $this->candidatos($pase);
        $porRegla = $candidatos->filter(fn (User $u) => $this->cumpleRegla($u, $aprobacion))->values();

        return $porRegla->isNotEmpty() ? $porRegla : $candidatos;
    }

    /** ¿Alguien cumple la regla del paso? Si no, firma cualquiera con "Aprobar" en la sede. */
    public function reglaSinFirmantes(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): bool
    {
        return $this->candidatos($pase)->filter(fn (User $u) => $this->cumpleRegla($u, $aprobacion))->isEmpty();
    }

    public function puedeAprobar(User $actor, PaseSalida $pase, ?PaseSalidaAprobacion $aprobacion = null): bool
    {
        return $this->motivoNoPuede($actor, $pase, $aprobacion) === null;
    }

    /**
     * Por qué el actor no puede firmar el paso actual (null = sí puede).
     */
    public function motivoNoPuede(User $actor, PaseSalida $pase, ?PaseSalidaAprobacion $aprobacion = null): ?string
    {
        $aprobacion ??= $this->actual($pase);
        if ($aprobacion === null || $pase->estado !== PaseSalida::PENDIENTE || $aprobacion->estado !== PaseSalidaAprobacion::PENDIENTE) {
            return 'Este pase no espera aprobación.';
        }
        if (! $this->tienePermisoEnSede($actor, 'pases_salida.aprobar', $pase, $pase->sede_id)) {
            return 'Para aprobar se necesita el permiso «Aprobar» de Pases de salida en la sede '.($pase->sede?->nombre ?? 'de origen').'.';
        }
        if (($actor->getAttributes()['colaborador_id'] ?? null) !== null && (int) $actor->getAttributes()['colaborador_id'] === (int) $pase->colaborador_id) {
            return 'Eres el solicitante de este pase: no puedes aprobarlo tú.';
        }
        if ($actor->es_superadmin) {
            return null;
        }

        $aprobadores = $this->aprobadores($pase, $aprobacion);
        if (! $aprobadores->contains('id', $actor->id)) {
            return 'Este paso lo firma: '.$aprobacion->quienFirma().'.';
        }

        // Una persona, un paso por ronda (salvo que nadie más pueda firmarlo)
        $firmantes = $this->rondaActual($pase)->where('estado', PaseSalidaAprobacion::APROBADO)->pluck('resuelto_por')->map(fn ($id) => (int) $id)->all();
        if (in_array((int) $actor->id, $firmantes, true) && $aprobadores->reject(fn (User $u) => in_array((int) $u->id, $firmantes, true))->isNotEmpty()) {
            return 'Ya firmaste otro paso de este pase: este lo debe firmar otra persona.';
        }

        return null;
    }

    /**
     * ¿Tiene el permiso con alcance en esa sede? (también revisa "solo los propios").
     */
    public function tienePermisoEnSede(User $actor, string $permiso, PaseSalida $pase, ?int $sede): bool
    {
        if (! $actor->can($permiso)) {
            return false;
        }
        if ($actor->es_superadmin) {
            return true;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $efectivo !== null
            && ($sedes === null || ($sede !== null && in_array($sede, $sedes, true)))
            && ($efectivo->alcance !== Alcance::Propios || (int) $pase->creado_por === (int) $actor->id);
    }

    /**
     * Usuarios activos de la empresa con "Aprobar" en la sede de origen, sin el solicitante.
     *
     * @return Collection<int, User>
     */
    private function candidatos(PaseSalida $pase): Collection
    {
        $clave = $pase->empresa_id.'-'.$pase->sede_id;
        $todos = $this->candidatos[$clave] ??= User::where('empresa_id', $pase->empresa_id)->where('activo', true)
            ->with(['colaborador:id,departamento_id', 'roles:id'])->get()
            ->filter(fn (User $u) => $this->tienePermisoEnSede($u, 'pases_salida.aprobar', $pase, $pase->sede_id))->values();

        return $todos->reject(fn (User $u) => $u->colaborador_id !== null && (int) $u->colaborador_id === (int) $pase->colaborador_id)->values();
    }

    private function cumpleRegla(User $u, PaseSalidaAprobacion $a): bool
    {
        $regla = match ($a->tipo) {
            'rol' => $a->rol_id !== null && $u->roles->contains('id', $a->rol_id),
            'usuarios' => in_array((int) $u->id, array_map('intval', $a->usuarios ?? []), true),
            default => true,
        };

        return $regla && ($a->departamento_id === null || (int) $u->colaborador?->departamento_id === (int) $a->departamento_id);
    }
}
