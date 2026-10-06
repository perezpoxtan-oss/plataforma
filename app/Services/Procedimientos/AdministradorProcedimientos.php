<?php

namespace App\Services\Procedimientos;

use App\Mail\AvisoProcedimiento;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Procedimiento;
use App\Models\ProcedimientoAcuse;
use App\Models\ProcedimientoAdjunto;
use App\Models\ProcedimientoAplicacion;
use App\Models\ProcedimientoCategoria;
use App\Models\ProcedimientoEvento;
use App\Models\ProcedimientoPaso;
use App\Models\ProcedimientoRecordatorio;
use App\Models\ProcedimientoVersion;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Firmas\Firmas;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\ImagenSegura;
use App\Support\Tenancy\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de Procedimientos (manual de procedimientos operativos; no existe en
 * SEGCAT). Ver docs/tecnico/procedimientos.md.
 *
 * Circuito de cada versión: Borrador → En revisión → Publicada. Quien la
 * escribe la envía; la aprueba (con firma) alguien con «Aprobar» que no sea
 * su autor ni quien la envió. Rechazar regresa a Borrador con comentario
 * obligatorio. Una versión publicada no se edita: «Nueva versión» crea la
 * siguiente como borrador y la anterior sigue vigente hasta que se aprueba
 * la nueva. «Retirar» deja el procedimiento obsoleto.
 *
 * Acuse «Leí y entendí»: cada usuario activo cuyo colaborador entra en la
 * aplicación de la versión vigente (sedes, departamentos o puestos) la firma
 * una vez; una versión nueva pide firmar otra vez.
 *
 * Alcance: con alcance de sede se ven los procedimientos que aplican a todas
 * las sedes o a alguna de las suyas; para editarlos o aprobarlos, la versión
 * debe aplicar SOLO a sus sedes («todas las sedes» pide alcance de empresa).
 * Quien solo tiene «Ver» consulta los publicados.
 */
class AdministradorProcedimientos
{
    public const POR_PAGINA = 30;

    public const MAX_PASOS = 60;

    public const MAX_ADJUNTOS = 8;

    /** KB por adjunto. */
    public const MAX_KB_ADJUNTO = 5120;

    /** Días entre un recordatorio de acuses pendientes y el siguiente. */
    public const RECORDATORIO_CADA_DIAS = 3;

    /** Filtros de la lista => texto de su píldora. */
    public const FILTROS = [
        'todos' => 'Todos',
        'por_leer' => 'Por leer',
        'por_aprobar' => 'Por aprobar',
        'publicados' => 'Publicados',
        'revision' => 'En revisión',
        'borradores' => 'Borradores',
        'retirados' => 'Retirados',
    ];

    /** @var array<int, Collection<int, User>> personal de cada empresa (ya filtrado: activo, con colaborador y con «Ver») */
    private array $personal = [];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Firmas $firmas,
        private readonly AdministradorPasesSalida $pases,
        private readonly AvisosCorreo $avisos,
    ) {}

    // ================================================================ Alcance

    /**
     * Sedes en las que usa el permiso: null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    /** ¿Solo consulta? (sin crear, editar ni aprobar): ve únicamente lo publicado. */
    public function soloLectura(User $actor): bool
    {
        return ! $actor->can('procedimientos.crear') && ! $actor->can('procedimientos.editar') && ! $actor->can('procedimientos.aprobar');
    }

    /** ¿Ve la pestaña Acuses (cumplimiento) y su exportación? */
    public function veAcuses(User $actor): bool
    {
        return $actor->can('procedimientos.editar') || $actor->can('procedimientos.aprobar');
    }

    /**
     * Procedimientos que el actor ve con un permiso: los que aplican a todas
     * las sedes o a alguna de las suyas (en su versión vigente o la de trabajo).
     *
     * @param  Builder<Procedimiento>  $consulta
     * @return Builder<Procedimiento>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso = 'procedimientos.ver'): Builder
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
            ->when($sedes !== null, fn ($q) => $q->whereHas('versiones', fn ($v) => $v
                ->whereIn('procedimiento_versiones.estado', [ProcedimientoVersion::BORRADOR, ProcedimientoVersion::EN_REVISION, ProcedimientoVersion::PUBLICADA])
                ->where(fn ($a) => $a->where('procedimiento_versiones.aplica_todas_sedes', true)
                    ->orWhereHas('aplicaciones', fn ($x) => $x->where('tipo', 'sede')->whereIn('sede_id', $sedes)))))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('procedimientos.creado_por', $actor->id))
            ->when($this->soloLectura($actor), fn ($q) => $q->where('procedimientos.estado', Procedimiento::PUBLICADO));
    }

    /**
     * ¿El permiso del actor cubre TODA la aplicación de la versión? (todas las
     * sedes pide alcance de empresa; sedes elegidas, que todas sean suyas).
     */
    public function cubre(User $actor, string $permiso, ProcedimientoVersion $version, ?Procedimiento $procedimiento = null): bool
    {
        if ($actor->es_superadmin) {
            return true;
        }
        if (! $actor->can($permiso)) {
            return false;
        }
        if ($this->autorizador->soloPropios($actor, $permiso)
            && (int) ($procedimiento ?? $version->procedimiento)->creado_por !== (int) $actor->id) {
            return false;
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
        if ($sedes === null) {
            return true;
        }
        $elegidas = $version->idsDe('sede');

        return ! $version->aplica_todas_sedes && $elegidas !== [] && array_diff($elegidas, $sedes) === [];
    }

    /** ¿Puede corregir el borrador? «Editar», o «Crear» si es su autor. */
    public function puedeEditar(User $actor, Procedimiento $p, ?ProcedimientoVersion $v): bool
    {
        if ($v === null || ! $v->editable() || $p->estado === Procedimiento::RETIRADO) {
            return false;
        }

        return $this->cubre($actor, 'procedimientos.editar', $v, $p)
            || ((int) $v->creado_por === (int) $actor->id && $this->cubre($actor, 'procedimientos.crear', $v, $p));
    }

    /**
     * Por qué el actor NO puede aprobar (o rechazar) la versión en revisión; null si puede.
     */
    public function motivoNoAprueba(User $actor, Procedimiento $p, ?ProcedimientoVersion $v): ?string
    {
        if ($v === null || $v->estado !== ProcedimientoVersion::EN_REVISION) {
            return 'Esta versión no está en revisión.';
        }
        if (! $actor->can('procedimientos.aprobar')) {
            return 'Tu usuario no tiene el permiso «Aprobar» de Procedimientos.';
        }
        if ((int) $v->creado_por === (int) $actor->id || (int) $v->enviado_por === (int) $actor->id) {
            return 'Tú escribiste o enviaste esta versión: debe aprobarla otra persona con el permiso «Aprobar».';
        }
        if (! $this->cubre($actor, 'procedimientos.aprobar', $v, $p)) {
            return $v->aplica_todas_sedes
                ? 'Aplica a todas las sedes: la aprueba alguien con «Aprobar» en toda la empresa.'
                : 'Aplica a sedes fuera de tu alcance: la aprueba alguien con «Aprobar» en todas ellas.';
        }

        return null;
    }

    // ============================================================== Categorías

    /**
     * Categorías de la empresa activa; la primera vez se crean las predeterminadas.
     *
     * @return Collection<int, ProcedimientoCategoria>
     */
    public function categorias(bool $soloActivas = false): Collection
    {
        if (! ProcedimientoCategoria::exists()) {
            foreach (ProcedimientoCategoria::PREDETERMINADAS as $nombre => [$color, $orden]) {
                ProcedimientoCategoria::firstOrCreate(['nombre' => $nombre], ['color' => $color, 'orden' => $orden]);
            }
        }

        return ProcedimientoCategoria::query()->when($soloActivas, fn ($q) => $q->where('activo', true))
            ->orderBy('orden')->orderBy('nombre')->get();
    }

    /**
     * Alta o cambio de una categoría (Editar con alcance de empresa: el
     * catálogo es de toda la empresa).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function guardarCategoria(User $actor, ?ProcedimientoCategoria $categoria, array $entrada): ProcedimientoCategoria
    {
        if (! $this->autorizador->alcanceDeEmpresa($actor, 'procedimientos.editar')) {
            throw new AuthorizationException('Las categorías son de toda la empresa: las cambia quien edita procedimientos en toda la empresa.');
        }
        $datos = Validator::make([
            'nombre' => trim(preg_replace('/\s+/u', ' ', Entrada::texto($entrada['nombre'] ?? '')) ?? ''),
            'color' => Entrada::texto($entrada['color'] ?? ''),
            'orden' => $entrada['orden'] ?? null,
            'activo' => $categoria === null ? true : filter_var($entrada['activo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], [
            'nombre' => ['required', 'string', 'max:80', function ($atributo, $valor, $falla) use ($categoria) {
                // Sin importar mayúsculas (la base de QA puede distinguirlas)
                if (is_string($valor) && ProcedimientoCategoria::whereRaw('LOWER(nombre) = ?', [mb_strtolower($valor)])->whereKeyNot($categoria?->id ?? 0)->exists()) {
                    $falla('Ya existe una categoría con ese nombre.');
                }
            }],
            'color' => ['required', Rule::in(array_keys(ProcedimientoCategoria::COLORES))],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
            'activo' => ['boolean'],
        ], [
            'nombre.required' => 'Escribe el nombre de la categoría.',
            'color.in' => 'Elige un color de la lista.',
            'orden.integer' => 'El orden debe ser un número.',
        ])->validate();
        $datos['orden'] = (int) ($datos['orden'] ?? ($categoria?->orden ?? ((int) ProcedimientoCategoria::max('orden') + 1)));

        $antes = $categoria?->only(['nombre', 'color', 'orden', 'activo']);
        $categoria ??= new ProcedimientoCategoria;
        $categoria->fill($datos)->save();
        $this->auditoria->auditar($actor, $antes === null ? 'procedimientos.categoria_creada' : 'procedimientos.categoria_actualizada', $categoria, $antes,
            $categoria->only(['nombre', 'color', 'orden', 'activo']));

        return $categoria;
    }

    // ============================================================ Lista

    /**
     * @param  Builder<Procedimiento>  $consulta
     * @return Builder<Procedimiento>
     */
    public function buscar(Builder $consulta, string $texto): Builder
    {
        $texto = trim($texto);
        if ($texto === '') {
            return $consulta;
        }
        $comodin = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto).'%';

        return $consulta->where(fn ($q) => $q->where('procedimientos.clave', 'like', $comodin)
            ->orWhere('procedimientos.titulo', 'like', $comodin)
            ->orWhereHas('versiones', fn ($v) => $v->where('procedimiento_versiones.estado', '!=', ProcedimientoVersion::DESCARTADA)
                ->where(fn ($w) => $w->where('titulo', 'like', $comodin)->orWhere('objetivo', 'like', $comodin)
                    ->orWhereHas('pasos', fn ($p) => $p->where('texto', 'like', $comodin)))));
    }

    /**
     * Aplica un filtro de la lista.
     *
     * @param  Builder<Procedimiento>  $consulta
     * @return Builder<Procedimiento>
     */
    public function filtrar(Builder $consulta, string $filtro, User $actor): Builder
    {
        return match ($filtro) {
            'por_leer' => $consulta->whereIn('procedimientos.id', $this->pendientesDe($actor)->pluck('id')->all() ?: [0]),
            'por_aprobar' => $consulta->whereIn('procedimientos.id', $this->idsPorAprobar($actor) ?: [0]),
            'publicados' => $consulta->where('procedimientos.estado', Procedimiento::PUBLICADO),
            'revision' => $consulta->where('procedimientos.estado_trabajo', Procedimiento::EN_REVISION),
            'borradores' => $consulta->where('procedimientos.estado_trabajo', Procedimiento::BORRADOR),
            'retirados' => $consulta->where('procedimientos.estado', Procedimiento::RETIRADO),
            default => $consulta,
        };
    }

    /**
     * Ids de los procedimientos con una versión en revisión que el actor puede aprobar.
     *
     * @return list<int>
     */
    public function idsPorAprobar(User $actor): array
    {
        if (! $actor->can('procedimientos.aprobar')) {
            return [];
        }

        return $this->limitar(Procedimiento::query(), $actor, 'procedimientos.aprobar')
            ->where('estado_trabajo', Procedimiento::EN_REVISION)
            ->with(['trabajo.aplicaciones', 'trabajo.procedimiento'])->get()
            ->filter(fn (Procedimiento $p) => $this->motivoNoAprueba($actor, $p, $p->trabajo) === null)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Siguiente clave sugerida: PRO-001, PRO-002… */
    public function claveSugerida(): string
    {
        $mayor = Procedimiento::pluck('clave')->map(fn ($c) => preg_match('/(\d+)\s*$/', (string) $c, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return 'PRO-'.str_pad((string) ($mayor + 1), 3, '0', STR_PAD_LEFT);
    }

    // ===================================================== Personal y acuses

    /**
     * Personal que puede tener que firmar: usuarios activos de la empresa
     * activa con colaborador activo y permiso de ver Procedimientos.
     *
     * @return Collection<int, User>
     */
    public function personal(): Collection
    {
        $empresaId = (int) app(Tenant::class)->empresaId();

        return $this->personal[$empresaId] ??= User::where('empresa_id', $empresaId)->where('activo', true)->where('es_superadmin', false)
            ->whereNotNull('colaborador_id')
            ->whereHas('colaborador', fn ($c) => $c->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id'))
            ->with(['colaborador:id,empresa_id,sede_id,departamento_id,puesto_id,num_empleado,nombre,apellido_paterno,apellido_materno',
                'colaborador.sedesAdicionales:sedes.id', 'colaborador.sede:id,nombre', 'colaborador.departamento:id,nombre', 'colaborador.puesto:id,nombre'])
            ->orderBy('name')->get()
            ->filter(fn (User $u) => $this->autorizador->puede($u, 'procedimientos.ver'))
            ->values();
    }

    /**
     * ¿La versión le aplica a este colaborador? Sedes: todas, o su sede física
     * o alguna adicional entre las elegidas. Departamentos y puestos: sin
     * ninguno elegido aplica a todos; si hay, basta con estar en alguno de los
     * departamentos O tener alguno de los puestos.
     */
    public function aplica(ProcedimientoVersion $version, ?Colaborador $colaborador): bool
    {
        if ($colaborador === null) {
            return false;
        }
        if (! $version->aplica_todas_sedes) {
            $suyas = array_filter([(int) $colaborador->sede_id, ...$colaborador->sedesAdicionales->pluck('id')->map(fn ($id) => (int) $id)->all()]);
            if (array_intersect($version->idsDe('sede'), $suyas) === []) {
                return false;
            }
        }
        $deptos = $version->idsDe('departamento');
        $puestos = $version->idsDe('puesto');
        if ($deptos === [] && $puestos === []) {
            return true;
        }

        return in_array((int) $colaborador->departamento_id, $deptos, true) || in_array((int) $colaborador->puesto_id, $puestos, true);
    }

    /**
     * Quiénes deben firmar el acuse de una versión.
     *
     * @return Collection<int, User>
     */
    public function obligados(ProcedimientoVersion $version): Collection
    {
        $version->loadMissing('aplicaciones');

        return $this->personal()->filter(fn (User $u) => $this->aplica($version, $u->colaborador))->values();
    }

    /**
     * Procedimientos publicados que el usuario debe leer y firmar y aún no firma.
     *
     * @return Collection<int, Procedimiento>
     */
    public function pendientesDe(User $actor): Collection
    {
        $colaboradorId = $actor->getAttributes()['colaborador_id'] ?? null;
        if ($colaboradorId === null || ! $actor->can('procedimientos.ver')) {
            return collect();
        }
        $colaborador = Colaborador::with('sedesAdicionales:sedes.id')->where('activo', true)->find($colaboradorId);
        if ($colaborador === null) {
            return collect();
        }
        $publicados = $this->limitar(Procedimiento::query(), $actor)->where('procedimientos.estado', Procedimiento::PUBLICADO)
            ->with(['vigente.aplicaciones', 'categoria'])->get()
            ->filter(fn (Procedimiento $p) => $p->vigente !== null && $this->aplica($p->vigente, $colaborador));
        $firmadas = ProcedimientoAcuse::where('user_id', $actor->id)->whereIn('version_id', $publicados->pluck('vigente.id')->all())
            ->pluck('version_id')->map(fn ($id) => (int) $id)->all();

        return $publicados->reject(fn (Procedimiento $p) => in_array((int) $p->vigente->id, $firmadas, true))
            ->sortBy(fn (Procedimiento $p) => sprintf('%05d %s', (int) ($p->categoria?->orden ?? 999), $p->clave))->values();
    }

    /**
     * % de cumplimiento de varias versiones (para las fichas): [version_id => [firmaron, deben]].
     *
     * @param  Collection<int, ProcedimientoVersion>  $versiones
     * @return array<int, array{0: int, 1: int}>
     */
    public function cumplimientos(Collection $versiones): array
    {
        if ($versiones->isEmpty()) {
            return [];
        }
        $firmas = ProcedimientoAcuse::whereIn('version_id', $versiones->pluck('id'))->get(['version_id', 'user_id'])
            ->groupBy('version_id')->map(fn ($g) => $g->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $resultado = [];
        foreach ($versiones as $v) {
            $deben = $this->obligados($v)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $resultado[(int) $v->id] = [count(array_intersect($deben, $firmas[$v->id] ?? [])), count($deben)];
        }

        return $resultado;
    }

    /**
     * Pestaña Acuses: personal que debe firmar la versión (dentro del alcance
     * del actor y con los filtros), con su acuse si ya firmó, más quien firmó
     * sin estar obligado.
     *
     * @return array{filas: Collection<int, array{usuario: User, acuse: ?ProcedimientoAcuse}>, extra: Collection<int, ProcedimientoAcuse>, firmaron: int, deben: int}
     */
    public function cumplimiento(User $actor, ProcedimientoVersion $version, ?int $sede = null, ?int $departamento = null): array
    {
        $acuses = $version->acuses()->with('usuario:id,name')->get()->keyBy('user_id');
        $sedes = $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, $actor->can('procedimientos.editar') ? 'procedimientos.editar' : 'procedimientos.aprobar');
        $presencia = fn (User $u) => array_filter([(int) $u->colaborador->sede_id, ...$u->colaborador->sedesAdicionales->pluck('id')->map(fn ($id) => (int) $id)->all()]);

        $filas = $this->obligados($version)
            ->filter(fn (User $u) => $sedes === null || array_intersect($presencia($u), $sedes) !== [])
            ->filter(fn (User $u) => $sede === null || in_array($sede, $presencia($u), true))
            ->filter(fn (User $u) => $departamento === null || (int) $u->colaborador->departamento_id === $departamento)
            ->map(fn (User $u) => ['usuario' => $u, 'acuse' => $acuses->get($u->id)])
            ->sortBy(fn ($f) => ($f['acuse'] ? '1' : '0').mb_strtolower($f['usuario']->name))->values();

        // Quien firmó sin estar obligado (cambió de puesto, o lo leyó por su cuenta): solo con alcance de empresa y sin filtros
        $todos = $this->obligados($version)->pluck('id')->all();
        $extra = $sedes === null && $sede === null && $departamento === null
            ? $acuses->reject(fn ($a) => in_array($a->user_id, $todos, true))->values()
            : collect();

        return ['filas' => $filas, 'extra' => $extra, 'firmaron' => $filas->whereNotNull('acuse')->count(), 'deben' => $filas->count()];
    }

    // ================================================================ Altas

    /**
     * Procedimiento nuevo con su versión 1 en borrador. Con enviar=1 se manda
     * de una vez a revisión.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Procedimiento
    {
        $datos = $this->validar($actor, $entrada, null, 'procedimientos.crear');
        $guardados = [];

        try {
            $procedimiento = DB::transaction(function () use ($actor, $datos, &$guardados) {
                $p = new Procedimiento(['clave' => $datos['clave'], 'categoria_id' => $datos['categoria_id'], 'titulo' => $datos['titulo']]);
                $p->save();
                $v = new ProcedimientoVersion(['procedimiento_id' => $p->id, 'numero' => 1] + $this->camposVersion($datos));
                $v->save();
                $this->guardarDetalle($v, $datos, $guardados);
                $this->anotar($p, $v, $actor, 'creado');
                $this->sincronizar($p);

                return $p;
            });
        } catch (\Throwable $e) {
            foreach ($guardados as $ruta) {
                Storage::disk('local')->delete($ruta);
            }
            throw $e;
        }

        $this->auditoria->auditar($actor, 'procedimientos.creado', $procedimiento, null, ['clave' => $procedimiento->clave, 'titulo' => $procedimiento->titulo, 'version' => 1]);

        if (filter_var($entrada['enviar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->enviar($actor, $procedimiento->refresh(), $entrada);
        }

        return $procedimiento->refresh();
    }

    /**
     * Corregir el borrador de trabajo. Una versión publicada no se edita.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Procedimiento $procedimiento, array $entrada): void
    {
        $version = $procedimiento->trabajo()->with('aplicaciones', 'adjuntos', 'procedimiento')->first();
        if ($version === null || ! $version->editable()) {
            throw ValidationException::withMessages(['procedimiento' => $procedimiento->estaPublicado() && $version === null
                ? 'La versión publicada no se edita: usa «Nueva versión» para proponer cambios.'
                : 'Esta versión ya no es un borrador: no se puede editar.']);
        }
        if (! $this->puedeEditar($actor, $procedimiento, $version)) {
            throw new AuthorizationException('No puedes editar este borrador: necesitas «Editar» en todas las sedes a las que aplica (o ser su autor con «Crear»).');
        }
        $datos = $this->validar($actor, $entrada, $procedimiento, $actor->can('procedimientos.editar') ? 'procedimientos.editar' : 'procedimientos.crear', $version);
        $antes = $this->foto($version);
        $guardados = [];
        $quitados = [];

        try {
            DB::transaction(function () use ($actor, $procedimiento, $version, $datos, &$guardados, &$quitados) {
                $actual = $this->bloquear($procedimiento);
                $fresca = ProcedimientoVersion::whereKey($version->id)->lockForUpdate()->first();
                if ($fresca === null || ! $fresca->editable()) {
                    throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya no es un borrador: no se puede editar.']);
                }
                if ($fresca->numero === 1) {
                    $actual->forceFill(['clave' => $datos['clave']])->save();
                }
                $fresca->fill($this->camposVersion($datos))->save();
                $quitados = $fresca->adjuntos()->whereIn('id', $datos['quitar_adjuntos'])->pluck('ruta')->all();
                $fresca->adjuntos()->whereIn('id', $datos['quitar_adjuntos'])->delete();
                $fresca->pasos()->delete();
                $fresca->aplicaciones()->delete();
                $this->guardarDetalle($fresca, $datos, $guardados);
                $this->anotar($actual, $fresca, $actor, 'editado');
                $actual->touch();
                $this->sincronizar($actual);
            });
        } catch (\Throwable $e) {
            foreach ($guardados as $ruta) {
                Storage::disk('local')->delete($ruta);
            }
            throw $e;
        }
        foreach ($quitados as $ruta) {
            Storage::disk('local')->delete($ruta);
        }

        $this->auditoria->auditar($actor, 'procedimientos.actualizado', $procedimiento, $antes, $this->foto($version->refresh()));

        if (filter_var($entrada['enviar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->enviar($actor, $procedimiento->refresh(), $entrada);
        }
    }

    /**
     * Nueva versión de un procedimiento publicado: copia la vigente como
     * borrador (pasos, aplicación y adjuntos). La vigente sigue en uso.
     */
    public function nuevaVersion(User $actor, Procedimiento $procedimiento): ProcedimientoVersion
    {
        $vigente = $procedimiento->vigente()->with('pasos', 'aplicaciones', 'adjuntos')->first();
        if ($vigente === null || $procedimiento->estado !== Procedimiento::PUBLICADO) {
            throw ValidationException::withMessages(['procedimiento' => 'Solo un procedimiento publicado (y no retirado) puede tener una versión nueva.']);
        }
        if (! $this->cubre($actor, 'procedimientos.editar', $vigente, $procedimiento)) {
            throw new AuthorizationException('Para hacer una versión nueva necesitas «Editar» en todas las sedes a las que aplica.');
        }
        $copiados = [];

        try {
            $nueva = DB::transaction(function () use ($actor, $procedimiento, $vigente, &$copiados) {
                $actual = $this->bloquear($procedimiento);
                if ($actual->trabajo()->exists()) {
                    throw ValidationException::withMessages(['procedimiento' => 'Ya hay una versión en borrador o en revisión: termínala o descártala primero.']);
                }
                $numero = (int) ProcedimientoVersion::where('procedimiento_id', $actual->id)->max('numero') + 1;
                $v = new ProcedimientoVersion(['procedimiento_id' => $actual->id, 'numero' => $numero] + $vigente->only([
                    'categoria_id', 'titulo', 'objetivo', 'alcance', 'responsables', 'notas', 'aplica_todas_sedes',
                ]));
                $v->save();
                foreach ($vigente->pasos as $paso) {
                    ProcedimientoPaso::create(['version_id' => $v->id] + $paso->only(['orden', 'texto', 'responsable', 'critico']));
                }
                foreach ($vigente->aplicaciones as $a) {
                    ProcedimientoAplicacion::create(['version_id' => $v->id] + $a->only(['tipo', 'sede_id', 'departamento_id', 'puesto_id']));
                }
                foreach ($vigente->adjuntos as $adjunto) {
                    $ruta = $this->rutaAdjunto(pathinfo($adjunto->ruta, PATHINFO_EXTENSION));
                    if (Storage::disk('local')->exists($adjunto->ruta) && Storage::disk('local')->copy($adjunto->ruta, $ruta)) {
                        $copiados[] = $ruta;
                        ProcedimientoAdjunto::create(['version_id' => $v->id, 'ruta' => $ruta] + $adjunto->only(['nombre', 'tipo', 'mime', 'tamano']));
                    }
                }
                $this->anotar($actual, $v, $actor, 'creado', "Versión {$numero} a partir de la versión {$vigente->numero}.");
                $this->sincronizar($actual);

                return $v;
            });
        } catch (\Throwable $e) {
            foreach ($copiados as $ruta) {
                Storage::disk('local')->delete($ruta);
            }
            throw $e;
        }

        $this->auditoria->auditar($actor, 'procedimientos.version_creada', $procedimiento, ['version_vigente' => $vigente->numero], ['version_nueva' => $nueva->numero]);

        return $nueva;
    }

    // ============================================================= Circuito

    /**
     * Borrador → En revisión. Desde la versión 2 pide el resumen de cambios.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function enviar(User $actor, Procedimiento $procedimiento, array $entrada): void
    {
        $version = $procedimiento->trabajo()->with('aplicaciones', 'procedimiento')->first();
        if ($version === null || $version->estado !== ProcedimientoVersion::BORRADOR) {
            throw ValidationException::withMessages(['procedimiento' => 'No hay un borrador por enviar a revisión.']);
        }
        if (! $this->puedeEditar($actor, $procedimiento, $version)) {
            throw new AuthorizationException('No puedes enviar este borrador: necesitas «Editar» en todas sus sedes (o ser su autor con «Crear»).');
        }
        $resumen = $this->texto($entrada['resumen_cambios'] ?? null, 1000, 'resumen_cambios', 'El resumen de cambios') ?? $version->resumen_cambios;
        if ($version->numero > 1 && ($resumen === null || mb_strlen($resumen) < 5)) {
            throw ValidationException::withMessages(['resumen_cambios' => 'Escribe qué cambió respecto a la versión anterior (es obligatorio desde la versión 2).']);
        }

        DB::transaction(function () use ($actor, $procedimiento, $version, $resumen) {
            $actual = $this->bloquear($procedimiento);
            $fresca = ProcedimientoVersion::whereKey($version->id)->lockForUpdate()->first();
            if ($fresca === null || $fresca->estado !== ProcedimientoVersion::BORRADOR) {
                throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya se envió a revisión.']);
            }
            if (! $fresca->pasos()->exists()) {
                throw ValidationException::withMessages(['pasos' => 'Agrega al menos un paso antes de enviarlo a revisión.']);
            }
            $fresca->forceFill([
                'estado' => ProcedimientoVersion::EN_REVISION, 'resumen_cambios' => $resumen,
                'enviado_en' => now(), 'enviado_por' => $actor->id,
            ])->save();
            $this->anotar($actual, $fresca, $actor, 'enviado', $resumen);
            $this->sincronizar($actual);
        });

        $this->auditoria->auditar($actor, 'procedimientos.enviado', $procedimiento, ['estado' => 'borrador'], ['estado' => 'en_revision', 'version' => $version->numero]);
    }

    /**
     * En revisión → Publicada, con la firma de quien aprueba (no su autor ni
     * quien la envió). La versión anterior queda Reemplazada y se avisa al
     * personal que debe firmar el acuse.
     *
     * @param  array<string, mixed>  $entrada  version_id, firma_modo (guardada|nueva), firma, guardar_firma, comentario
     */
    public function aprobar(User $actor, Procedimiento $procedimiento, array $entrada): ProcedimientoVersion
    {
        $version = $this->versionEsperada($procedimiento, $entrada);
        if (($motivo = $this->motivoNoAprueba($actor, $procedimiento, $version)) !== null) {
            if ($version->estado !== ProcedimientoVersion::EN_REVISION) {
                throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya no está en revisión (¿alguien más ya la aprobó o la rechazó?).']);
            }
            throw new AuthorizationException($motivo);
        }
        $comentario = $this->texto($entrada['comentario'] ?? null, 1000, 'comentario', 'El comentario');
        [$ruta, $guardar] = $this->firmaDelActor($actor, $entrada, 'firma');

        try {
            DB::transaction(function () use ($actor, $procedimiento, $version, $comentario, $ruta) {
                $actual = $this->bloquear($procedimiento);
                $fresca = ProcedimientoVersion::whereKey($version->id)->lockForUpdate()->first();
                if ($fresca === null || $fresca->estado !== ProcedimientoVersion::EN_REVISION) {
                    throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya no está en revisión (¿alguien más ya la aprobó o la rechazó?).']);
                }
                $anterior = ProcedimientoVersion::where('procedimiento_id', $actual->id)->where('estado', ProcedimientoVersion::PUBLICADA)->lockForUpdate()->first();
                if ($anterior !== null) {
                    $anterior->forceFill(['estado' => ProcedimientoVersion::REEMPLAZADA, 'reemplazada_en' => now()])->save();
                    $this->anotar($actual, $anterior, $actor, 'reemplazada', "Ahora rige la versión {$fresca->numero}.");
                }
                $fresca->forceFill([
                    'estado' => ProcedimientoVersion::PUBLICADA, 'aprobado_en' => now(), 'aprobado_por' => $actor->id,
                    'aprobador_nombre' => $actor->name, 'aprobador_cargo' => $this->cargo($actor), 'firma_ruta' => $ruta,
                    'comentario_aprobacion' => $comentario,
                ])->save();
                $this->anotar($actual, $fresca, $actor, 'aprobado', $comentario);
                $this->sincronizar($actual);
            });
        } catch (\Throwable $e) {
            $this->firmas->borrar($ruta);
            throw $e;
        }

        $this->pases->guardarFirmaUsuario($actor, $guardar);
        $version->refresh();
        $this->auditoria->auditar($actor, 'procedimientos.aprobado', $procedimiento, ['estado' => 'en_revision'],
            ['estado' => 'publicado', 'clave' => $procedimiento->clave, 'version' => $version->numero, 'comentario' => $comentario]);
        $this->avisarPublicado($procedimiento->refresh(), $version);

        return $version;
    }

    /**
     * En revisión → Borrador, con comentario obligatorio.
     *
     * @param  array<string, mixed>  $entrada  version_id, comentario
     */
    public function rechazar(User $actor, Procedimiento $procedimiento, array $entrada): void
    {
        $version = $this->versionEsperada($procedimiento, $entrada);
        if (($motivo = $this->motivoNoAprueba($actor, $procedimiento, $version)) !== null) {
            if ($version->estado !== ProcedimientoVersion::EN_REVISION) {
                throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya no está en revisión.']);
            }
            throw new AuthorizationException($motivo);
        }
        $comentario = $this->texto($entrada['comentario'] ?? null, 1000, 'comentario', 'El comentario');
        if ($comentario === null || mb_strlen($comentario) < 5) {
            throw ValidationException::withMessages(['comentario' => 'Escribe por qué lo rechazas y qué hay que corregir (es obligatorio).']);
        }

        DB::transaction(function () use ($actor, $procedimiento, $version, $comentario) {
            $actual = $this->bloquear($procedimiento);
            $fresca = ProcedimientoVersion::whereKey($version->id)->lockForUpdate()->first();
            if ($fresca === null || $fresca->estado !== ProcedimientoVersion::EN_REVISION) {
                throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya no está en revisión.']);
            }
            $fresca->forceFill([
                'estado' => ProcedimientoVersion::BORRADOR, 'rechazado_en' => now(), 'rechazado_por' => $actor->id, 'motivo_rechazo' => $comentario,
            ])->save();
            $this->anotar($actual, $fresca, $actor, 'rechazado', $comentario);
            $this->sincronizar($actual);
        });

        $this->auditoria->auditar($actor, 'procedimientos.rechazado', $procedimiento, ['estado' => 'en_revision'],
            ['estado' => 'borrador', 'version' => $version->numero, 'comentario' => $comentario]);
    }

    /**
     * Descartar el borrador de una versión nueva (la vigente sigue igual).
     */
    public function descartar(User $actor, Procedimiento $procedimiento): void
    {
        $version = $procedimiento->trabajo()->with('aplicaciones', 'procedimiento')->first();
        if ($version === null || $version->estado !== ProcedimientoVersion::BORRADOR || $version->numero === 1) {
            throw ValidationException::withMessages(['procedimiento' => 'Solo se descarta el borrador de una versión nueva. Un procedimiento que nunca se publicó se elimina con «Eliminar definitivamente».']);
        }
        if (! $this->cubre($actor, 'procedimientos.editar', $version, $procedimiento)) {
            throw new AuthorizationException('Para descartar el borrador necesitas «Editar» en todas las sedes a las que aplica.');
        }

        DB::transaction(function () use ($actor, $procedimiento, $version) {
            $actual = $this->bloquear($procedimiento);
            ProcedimientoVersion::whereKey($version->id)->where('estado', ProcedimientoVersion::BORRADOR)
                ->update(['estado' => ProcedimientoVersion::DESCARTADA, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
            $this->anotar($actual, $version, $actor, 'descartado');
            $this->sincronizar($actual);
        });

        $this->auditoria->auditar($actor, 'procedimientos.version_descartada', $procedimiento, ['version' => $version->numero, 'estado' => 'borrador'], ['estado' => 'descartada']);
    }

    /**
     * Retirar (obsoleto): ya no se consulta ni se pide firmar. Un borrador o
     * revisión en curso queda descartado.
     *
     * @param  array<string, mixed>  $entrada  motivo
     */
    public function retirar(User $actor, Procedimiento $procedimiento, array $entrada): void
    {
        $vigente = $procedimiento->vigente()->with('aplicaciones', 'procedimiento')->first();
        if ($procedimiento->estado !== Procedimiento::PUBLICADO || $vigente === null) {
            throw ValidationException::withMessages(['procedimiento' => $procedimiento->estado === Procedimiento::RETIRADO
                ? 'Este procedimiento ya está retirado.' : 'Solo se retira un procedimiento publicado. Un borrador que nunca se publicó se elimina.']);
        }
        if (! $this->cubre($actor, 'procedimientos.eliminar', $vigente, $procedimiento)) {
            throw new AuthorizationException('Para retirarlo necesitas «Eliminar» en todas las sedes a las que aplica.');
        }
        $motivo = $this->texto($entrada['motivo'] ?? null, 1000, 'motivo', 'El motivo');
        if ($motivo === null || mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Escribe por qué se retira (por ejemplo: «Lo sustituye PRO-SEG-010»).']);
        }

        DB::transaction(function () use ($actor, $procedimiento, $motivo) {
            $actual = $this->bloquear($procedimiento);
            if ($actual->estado !== Procedimiento::PUBLICADO) {
                throw ValidationException::withMessages(['procedimiento' => 'Este procedimiento ya está retirado.']);
            }
            foreach (ProcedimientoVersion::where('procedimiento_id', $actual->id)->whereIn('estado', [ProcedimientoVersion::BORRADOR, ProcedimientoVersion::EN_REVISION])->get() as $trabajo) {
                $trabajo->forceFill(['estado' => ProcedimientoVersion::DESCARTADA])->save();
                $this->anotar($actual, $trabajo, $actor, 'descartado', 'Se descartó al retirar el procedimiento.');
            }
            $actual->forceFill(['estado' => Procedimiento::RETIRADO, 'retirado_en' => now(), 'retirado_por' => $actor->id, 'motivo_retiro' => $motivo])->save();
            $this->anotar($actual, null, $actor, 'retirado', $motivo);
            $this->sincronizar($actual);
        });

        $this->auditoria->auditar($actor, 'procedimientos.retirado', $procedimiento, ['estado' => 'publicado'], ['estado' => 'retirado', 'motivo' => $motivo]);
    }

    /**
     * Retirado → Publicado (vuelve a regir su última versión publicada).
     */
    public function reactivar(User $actor, Procedimiento $procedimiento): void
    {
        $vigente = $procedimiento->vigente()->with('aplicaciones', 'procedimiento')->first();
        if ($procedimiento->estado !== Procedimiento::RETIRADO || $vigente === null) {
            throw ValidationException::withMessages(['procedimiento' => 'Solo se reactiva un procedimiento retirado.']);
        }
        if (! $this->cubre($actor, 'procedimientos.eliminar', $vigente, $procedimiento)) {
            throw new AuthorizationException('Para reactivarlo necesitas «Eliminar» en todas las sedes a las que aplica.');
        }

        DB::transaction(function () use ($actor, $procedimiento) {
            $actual = $this->bloquear($procedimiento);
            $actual->forceFill(['estado' => Procedimiento::PUBLICADO, 'retirado_en' => null, 'retirado_por' => null, 'motivo_retiro' => null])->save();
            $this->anotar($actual, null, $actor, 'reactivado');
            $this->sincronizar($actual);
        });

        $this->auditoria->auditar($actor, 'procedimientos.reactivado', $procedimiento, ['estado' => 'retirado'], ['estado' => 'publicado']);
    }

    // ================================================================ Acuse

    /**
     * «Leí y entendí este procedimiento»: firma del usuario sobre la versión vigente.
     *
     * @param  array<string, mixed>  $entrada  version_id, entendido, firma_modo, firma, guardar_firma
     */
    public function acusar(User $actor, Procedimiento $procedimiento, array $entrada): ProcedimientoAcuse
    {
        $vigente = $procedimiento->vigente;
        if ($procedimiento->estado !== Procedimiento::PUBLICADO || $vigente === null) {
            throw ValidationException::withMessages(['procedimiento' => 'Solo se firma de enterado un procedimiento publicado.']);
        }
        if ((int) ($entrada['version_id'] ?? 0) !== (int) $vigente->id) {
            throw ValidationException::withMessages(['procedimiento' => "Se publicó la versión {$vigente->numero} mientras leías: léela de nuevo antes de firmar."]);
        }
        if (! filter_var($entrada['entendido'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['entendido' => 'Marca la casilla «Leí y entendí este procedimiento».']);
        }
        if (ProcedimientoAcuse::where('version_id', $vigente->id)->where('user_id', $actor->id)->exists()) {
            throw ValidationException::withMessages(['procedimiento' => "Ya firmaste de enterado la versión {$vigente->numero}."]);
        }
        [$ruta, $guardar] = $this->firmaDelActor($actor, $entrada, 'firma');

        try {
            $acuse = DB::transaction(function () use ($actor, $procedimiento, $vigente, $ruta) {
                $this->bloquear($procedimiento);

                return ProcedimientoAcuse::create([
                    'version_id' => $vigente->id, 'user_id' => $actor->id,
                    'colaborador_id' => $actor->getAttributes()['colaborador_id'] ?? null, 'nombre' => $actor->name,
                    'firma_ruta' => $ruta, 'ip' => request()->ip(), 'leido_en' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->firmas->borrar($ruta);
            throw ValidationException::withMessages(['procedimiento' => "Ya firmaste de enterado la versión {$vigente->numero}."]);
        } catch (\Throwable $e) {
            $this->firmas->borrar($ruta);
            throw $e;
        }

        $this->pases->guardarFirmaUsuario($actor, $guardar);
        $this->auditoria->auditar($actor, 'procedimientos.acuse_firmado', $acuse, null, ['clave' => $procedimiento->clave, 'version' => $vigente->numero]);

        return $acuse;
    }

    // ================================================================ Avisos

    /**
     * Correo a quien debe firmar el acuse de la versión recién publicada.
     */
    private function avisarPublicado(Procedimiento $procedimiento, ProcedimientoVersion $version): void
    {
        $correos = $this->obligados($version)->pluck('email')->filter()->unique()->values()->all();
        $this->avisos->procedimiento($procedimiento->empresa_id, 'procedimiento_publicado', $correos, new AvisoProcedimiento('publicado', [[
            'clave' => $procedimiento->clave, 'titulo' => $version->titulo, 'version' => $version->numero,
            'enlace' => route('procedimientos.leer', $procedimiento->id),
        ]], $version->numero > 1 ? $version->resumen_cambios : null, route('procedimientos.por-leer')));
    }

    /**
     * Recordatorio de acuses pendientes (comando plataforma:procedimientos-pendientes,
     * en proceso desde desplegar.sh). Cada usuario recibe uno cada
     * RECORDATORIO_CADA_DIAS días como máximo, con las versiones publicadas
     * antes de hoy. Con $siToca, solo después de las 8:00 de la empresa.
     *
     * @return int correos enviados
     */
    public function recordatorios(bool $siToca = true): int
    {
        $enviados = 0;
        foreach (Empresa::where('activo', true)->get(['id', 'preferencias', 'zona_horaria']) as $empresa) {
            if (! $empresa->aviso('procedimiento_recordatorio')) {
                continue;
            }
            $ahora = now($empresa->zona_horaria ?: config('app.timezone'));
            if ($siToca && (int) $ahora->format('G') < 8) {
                continue;
            }
            $hoy = $ahora->format('Y-m-d');
            $enviados += app(Tenant::class)->conEmpresa($empresa->id, function () use ($empresa, $ahora, $hoy) {
                $cuenta = 0;
                $inicioHoy = $ahora->copy()->startOfDay()->utc();
                $ultimos = ProcedimientoRecordatorio::pluck('ultimo_envio', 'user_id');
                foreach ($this->personal() as $usuario) {
                    $ultimo = $ultimos[$usuario->id] ?? null;
                    if ($usuario->email === null || ($ultimo !== null && $ultimo->format('Y-m-d') > $ahora->copy()->subDays(self::RECORDATORIO_CADA_DIAS)->format('Y-m-d'))) {
                        continue;
                    }
                    $pendientes = $this->pendientesDe($usuario)->filter(fn (Procedimiento $p) => $p->publicado_en !== null && $p->publicado_en->lt($inicioHoy));
                    if ($pendientes->isEmpty()) {
                        continue;
                    }
                    $lista = $pendientes->map(fn (Procedimiento $p) => ['clave' => $p->clave, 'titulo' => $p->titulo, 'version' => (int) $p->version_vigente,
                        'enlace' => route('procedimientos.leer', $p->id)])->values()->all();
                    $enviado = $this->avisos->procedimiento($empresa->id, 'procedimiento_recordatorio', [$usuario->email],
                        new AvisoProcedimiento('recordatorio', $lista, null, route('procedimientos.por-leer')), false);
                    ProcedimientoRecordatorio::updateOrCreate(['user_id' => $usuario->id], ['ultimo_envio' => $hoy]);
                    if ($enviado) {
                        $cuenta++;
                    }
                }

                return $cuenta;
            });
        }

        return $enviados;
    }

    // ================================================================ Apoyo

    /**
     * La versión que el formulario dice aprobar o rechazar (version_id) debe
     * ser la que está en revisión ahora.
     *
     * @param  array<string, mixed>  $entrada
     */
    private function versionEsperada(Procedimiento $procedimiento, array $entrada): ProcedimientoVersion
    {
        $version = ProcedimientoVersion::with('aplicaciones', 'procedimiento')->where('procedimiento_id', $procedimiento->id)
            ->find((int) Entrada::texto($entrada['version_id'] ?? '0'));
        if ($version === null) {
            throw ValidationException::withMessages(['procedimiento' => 'Esta versión ya no está en revisión.']);
        }

        return $version;
    }

    private function bloquear(Procedimiento $procedimiento): Procedimiento
    {
        $actual = Procedimiento::whereKey($procedimiento->id)->lockForUpdate()->first();
        abort_if($actual === null, 404);

        return $actual;
    }

    /**
     * Recalcula las copias de estado del procedimiento a partir de sus versiones.
     */
    public function sincronizar(Procedimiento $procedimiento): void
    {
        $versiones = ProcedimientoVersion::where('procedimiento_id', $procedimiento->id)->get();
        $vigente = $versiones->firstWhere('estado', ProcedimientoVersion::PUBLICADA);
        $trabajo = $versiones->first(fn ($v) => in_array($v->estado, [ProcedimientoVersion::BORRADOR, ProcedimientoVersion::EN_REVISION], true));
        $cabeza = $vigente ?? $trabajo;
        $estado = $procedimiento->estado === Procedimiento::RETIRADO
            ? Procedimiento::RETIRADO
            : ($vigente !== null ? Procedimiento::PUBLICADO : ($trabajo?->estado === ProcedimientoVersion::EN_REVISION ? Procedimiento::EN_REVISION : Procedimiento::BORRADOR));

        $procedimiento->forceFill(array_filter([
            'estado' => $estado,
            'version_vigente' => $vigente?->numero,
            'publicado_en' => $vigente?->aprobado_en,
            'version_trabajo' => $trabajo?->numero,
            'estado_trabajo' => $trabajo?->estado,
            'titulo' => $cabeza?->titulo,
            'categoria_id' => $cabeza?->categoria_id,
        ], fn ($v, $k) => $v !== null || in_array($k, ['version_vigente', 'publicado_en', 'version_trabajo', 'estado_trabajo'], true), ARRAY_FILTER_USE_BOTH))->save();
    }

    private function anotar(Procedimiento $procedimiento, ?ProcedimientoVersion $version, ?User $actor, string $evento, ?string $comentario = null): void
    {
        ProcedimientoEvento::create([
            'procedimiento_id' => $procedimiento->id, 'version_id' => $version?->id, 'evento' => $evento, 'comentario' => $comentario,
            'user_id' => $actor?->id, 'usuario_nombre' => $actor?->name, 'ip' => $actor !== null ? request()->ip() : null,
        ]);
    }

    /** Cargo de quien aprueba: el puesto de su colaborador o su rol. */
    private function cargo(User $actor): string
    {
        $colaboradorId = $actor->getAttributes()['colaborador_id'] ?? null;
        $puesto = $colaboradorId ? Colaborador::with('puesto:id,nombre')->find($colaboradorId)?->puesto?->nombre : null;

        return $puesto ?: $actor->nombreRolPrincipal();
    }

    /**
     * Firma del usuario en sesión: la guardada (se copia) o la que dibujó
     * ahora. Devuelve [ruta en el disco privado, firma a guardar como suya o null].
     * Reutiliza la firma guardada de Pases de salida (firmas_usuarios).
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: string, 1: ?string}
     */
    private function firmaDelActor(User $actor, array $entrada, string $campo): array
    {
        if (($entrada['firma_modo'] ?? null) === 'guardada') {
            $guardada = $this->pases->firmaGuardada($actor);
            if ($guardada === null || ! Storage::disk('local')->exists($guardada->firma_ruta)) {
                throw ValidationException::withMessages([$campo => 'No tienes una firma guardada: firma en el recuadro.']);
            }
            $tipo = str_ends_with($guardada->firma_ruta, '.png') ? 'png' : 'jpeg';
            $dataUrl = 'data:image/'.$tipo.';base64,'.base64_encode((string) Storage::disk('local')->get($guardada->firma_ruta));

            return [$this->firmas->guardar($dataUrl, 'procedimientos', $campo, 'tu firma'), null];
        }
        $dibujada = is_string($entrada['firma'] ?? null) ? $entrada['firma'] : null;
        $ruta = $this->firmas->guardar($dibujada, 'procedimientos', $campo, 'tu firma');

        return [$ruta, filter_var($entrada['guardar_firma'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $dibujada : null];
    }

    private function texto(mixed $valor, int $max, string $campo, string $etiqueta): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $valor = trim($valor);
        if (mb_strlen($valor) > $max) {
            throw ValidationException::withMessages([$campo => "{$etiqueta} admite máximo {$max} caracteres."]);
        }

        return $valor === '' ? null : $valor;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function camposVersion(array $datos): array
    {
        return [
            'categoria_id' => $datos['categoria_id'], 'titulo' => $datos['titulo'], 'objetivo' => $datos['objetivo'],
            'alcance' => $datos['alcance'], 'responsables' => $datos['responsables'], 'notas' => $datos['notas'],
            'resumen_cambios' => $datos['resumen_cambios'], 'aplica_todas_sedes' => $datos['aplica'] === 'todas',
        ];
    }

    /**
     * Pasos, aplicación y adjuntos nuevos de una versión.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>  $guardados  rutas de archivos guardados (para limpiarlas si algo falla)
     */
    private function guardarDetalle(ProcedimientoVersion $version, array $datos, array &$guardados): void
    {
        foreach (array_values($datos['pasos']) as $i => $paso) {
            ProcedimientoPaso::create(['version_id' => $version->id, 'orden' => $i + 1, 'texto' => $paso['texto'],
                'responsable' => $paso['responsable'], 'critico' => $paso['critico']]);
        }
        if ($datos['aplica'] === 'sedes') {
            foreach ($datos['sedes'] as $id) {
                ProcedimientoAplicacion::create(['version_id' => $version->id, 'tipo' => 'sede', 'sede_id' => $id]);
            }
        }
        foreach ($datos['departamentos'] as $id) {
            ProcedimientoAplicacion::create(['version_id' => $version->id, 'tipo' => 'departamento', 'departamento_id' => $id]);
        }
        foreach ($datos['puestos'] as $id) {
            ProcedimientoAplicacion::create(['version_id' => $version->id, 'tipo' => 'puesto', 'puesto_id' => $id]);
        }
        foreach ($datos['adjuntos'] as $archivo) {
            $adjunto = $this->guardarAdjunto($archivo);
            $guardados[] = $adjunto['ruta'];
            ProcedimientoAdjunto::create(['version_id' => $version->id] + $adjunto);
        }
    }

    /**
     * Guarda un adjunto en el disco privado: un PDF real (encabezado %PDF-) o
     * una imagen re-dibujada con ImagenSegura (sin metadatos ni código pegado).
     *
     * @return array{nombre: string, ruta: string, tipo: string, mime: string, tamano: int}
     */
    private function guardarAdjunto(UploadedFile $archivo): array
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $nombre = trim((string) preg_replace('/[^\pL\pN\s._\-()]/u', '', pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME))) ?: 'adjunto';
        $nombre = mb_substr($nombre, 0, 120);

        if (str_starts_with($contenido, '%PDF-')) {
            $ruta = $this->rutaAdjunto('pdf');
            Storage::disk('local')->put($ruta, $contenido);

            return ['nombre' => $nombre.'.pdf', 'ruta' => $ruta, 'tipo' => 'pdf', 'mime' => 'application/pdf', 'tamano' => strlen($contenido)];
        }

        [$limpio, $extension] = ImagenSegura::recodificar($contenido, 'adjuntos', null, 'Un adjunto no es válido: sube PDF, JPG, PNG o WEBP.');
        $ruta = $this->rutaAdjunto($extension);
        Storage::disk('local')->put($ruta, $limpio);

        return ['nombre' => $nombre.'.'.$extension, 'ruta' => $ruta, 'tipo' => 'imagen',
            'mime' => ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension] ?? 'image/jpeg', 'tamano' => strlen($limpio)];
    }

    private function rutaAdjunto(string $extension): string
    {
        $empresa = app(Tenant::class)->empresaId() ?? 0;
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return "procedimientos/{$empresa}/adjuntos/".now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(ProcedimientoVersion $v): array
    {
        $v->loadMissing('pasos', 'aplicaciones', 'adjuntos');

        return [
            'version' => $v->numero, 'titulo' => $v->titulo, 'categoria_id' => $v->categoria_id, 'objetivo' => $v->objetivo,
            'alcance' => $v->alcance, 'responsables' => $v->responsables, 'notas' => $v->notas,
            'aplica_todas_sedes' => $v->aplica_todas_sedes,
            'sedes' => $v->idsDe('sede'), 'departamentos' => $v->idsDe('departamento'), 'puestos' => $v->idsDe('puesto'),
            'pasos' => $v->pasos->map(fn ($p) => ($p->critico ? '[CRÍTICO] ' : '').$p->texto)->all(),
            'adjuntos' => $v->adjuntos->pluck('nombre')->all(),
        ];
    }

    /**
     * Valida el formulario del procedimiento (alta o borrador).
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(User $actor, array $entrada, ?Procedimiento $procedimiento, string $permiso, ?ProcedimientoVersion $version = null): array
    {
        $empresaId = app(Tenant::class)->empresaId();
        $limpiar = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : null;
        $ids = fn ($v) => is_array($v) ? array_values(array_unique(array_map('intval', array_filter($v, 'is_numeric')))) : [];

        // Pasos: se ignoran los renglones vacíos
        $pasos = [];
        foreach (is_array($entrada['pasos'] ?? null) ? $entrada['pasos'] : [] as $paso) {
            if (! is_array($paso)) {
                continue;
            }
            $texto = $limpiar($paso['texto'] ?? null);
            $responsable = $limpiar($paso['responsable'] ?? null);
            if ($texto === null && $responsable === null) {
                continue;
            }
            $pasos[] = ['texto' => $texto, 'responsable' => $responsable, 'critico' => filter_var($paso['critico'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }

        $datos = [
            'clave' => mb_strtoupper((string) preg_replace('/\s+/u', '', Entrada::texto($entrada['clave'] ?? '')), 'UTF-8'),
            'titulo' => $limpiar(Entrada::texto($entrada['titulo'] ?? '')),
            'categoria_id' => $entrada['categoria_id'] ?? null,
            'objetivo' => $limpiar(Entrada::texto($entrada['objetivo'] ?? '')),
            'alcance' => $limpiar(Entrada::texto($entrada['alcance'] ?? '')),
            'responsables' => $limpiar(Entrada::texto($entrada['responsables'] ?? '')),
            'notas' => $limpiar(Entrada::texto($entrada['notas'] ?? '')),
            'resumen_cambios' => $limpiar(Entrada::texto($entrada['resumen_cambios'] ?? '')),
            'aplica' => Entrada::texto($entrada['aplica'] ?? 'todas'),
            'sedes' => $ids($entrada['sedes'] ?? []),
            'departamentos' => $ids($entrada['departamentos'] ?? []),
            'puestos' => $ids($entrada['puestos'] ?? []),
            'pasos' => $pasos,
            'adjuntos' => array_values(array_filter(is_array($entrada['adjuntos'] ?? null) ? $entrada['adjuntos'] : [], fn ($a) => $a instanceof UploadedFile)),
            'quitar_adjuntos' => $ids($entrada['quitar_adjuntos'] ?? []),
        ];
        if ($version !== null && $version->numero > 1) {
            $datos['clave'] = $procedimiento->clave; // la clave no cambia después de la versión 1
        }
        $categoriaActual = $version?->categoria_id;
        $existentes = $version ? $version->adjuntos()->whereNotIn('id', $datos['quitar_adjuntos'])->count() : 0;

        $validador = Validator::make($datos, [
            'clave' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9\-_.\/]*$/u',
                Rule::unique('procedimientos', 'clave')->where('empresa_id', $empresaId)->ignore($procedimiento?->id)],
            'titulo' => ['required', 'string', 'max:150'],
            'categoria_id' => ['required', 'integer', Rule::exists('procedimiento_categorias', 'id')->where('empresa_id', $empresaId)
                ->where(fn ($q) => $q->where('activo', true)->orWhere('id', $categoriaActual ?? 0))],
            'objetivo' => ['required', 'string', 'max:2000'],
            'alcance' => ['nullable', 'string', 'max:2000'],
            'responsables' => ['nullable', 'string', 'max:1000'],
            'notas' => ['nullable', 'string', 'max:4000'],
            'resumen_cambios' => ['nullable', 'string', 'max:1000'],
            'aplica' => ['required', Rule::in(['todas', 'sedes'])],
            'sedes' => ['array', 'required_if:aplica,sedes'],
            'sedes.*' => ['integer', Rule::exists('sedes', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at')],
            'departamentos' => ['array', 'max:50'],
            'departamentos.*' => ['integer', Rule::exists('departamentos', 'id')->where('empresa_id', $empresaId)],
            'puestos' => ['array', 'max:80'],
            'puestos.*' => ['integer', Rule::exists('puestos', 'id')->where('empresa_id', $empresaId)],
            'pasos' => ['array', 'min:1', 'max:'.self::MAX_PASOS],
            'pasos.*.texto' => ['required', 'string', 'max:1000'],
            'pasos.*.responsable' => ['nullable', 'string', 'max:120'],
            'adjuntos' => ['array', 'max:'.max(0, self::MAX_ADJUNTOS - $existentes)],
            'adjuntos.*' => ['file', 'max:'.self::MAX_KB_ADJUNTO, 'mimes:pdf,jpg,jpeg,png,webp', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ], [
            'clave.required' => 'Escribe la clave del procedimiento (por ejemplo PRO-SEG-001).',
            'clave.regex' => 'La clave solo lleva letras, números y guiones (por ejemplo PRO-SEG-001).',
            'clave.unique' => 'Ya existe un procedimiento con esa clave.',
            'clave.max' => 'La clave admite máximo 30 caracteres.',
            'titulo.required' => 'Escribe el título del procedimiento.',
            'titulo.max' => 'El título admite máximo 150 caracteres.',
            'categoria_id.required' => 'Elige la categoría.',
            'categoria_id.exists' => 'Elige una categoría de la lista.',
            'objetivo.required' => 'Escribe el objetivo: para qué sirve este procedimiento.',
            'objetivo.max' => 'El objetivo admite máximo 2000 caracteres.',
            'alcance.max' => 'El alcance admite máximo 2000 caracteres.',
            'responsables.max' => 'Los responsables admiten máximo 1000 caracteres.',
            'notas.max' => 'Las notas admiten máximo 4000 caracteres.',
            'resumen_cambios.max' => 'El resumen de cambios admite máximo 1000 caracteres.',
            'aplica.in' => 'Elige si aplica a todas las sedes o a sedes elegidas.',
            'sedes.required_if' => 'Elige al menos una sede.',
            'sedes.*.exists' => 'Elige sedes de la lista.',
            'departamentos.*.exists' => 'Elige departamentos de la lista.',
            'puestos.*.exists' => 'Elige puestos de la lista.',
            'pasos.min' => 'Agrega al menos un paso.',
            'pasos.max' => 'Máximo '.self::MAX_PASOS.' pasos.',
            'pasos.*.texto.required' => 'El paso :position no tiene texto: escribe qué hay que hacer o quítalo.',
            'pasos.*.texto.max' => 'Cada paso admite máximo 1000 caracteres.',
            'pasos.*.responsable.max' => 'El responsable de un paso admite máximo 120 caracteres.',
            'adjuntos.max' => 'Máximo '.self::MAX_ADJUNTOS.' adjuntos por versión.',
            'adjuntos.*.max' => 'Cada adjunto puede pesar máximo '.(self::MAX_KB_ADJUNTO / 1024).' MB.',
            'adjuntos.*.mimes' => 'Los adjuntos solo pueden ser PDF, JPG, PNG o WEBP.',
            'adjuntos.*.mimetypes' => 'Los adjuntos solo pueden ser PDF, JPG, PNG o WEBP.',
            'adjuntos.*.file' => 'Un adjunto no se pudo subir: vuelve a elegirlo.',
        ]);
        $validador->after(function ($v) use ($actor, $permiso, $datos) {
            // Alcance: quien trabaja solo en algunas sedes no puede publicar para todas ni para otras
            $sedes = $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, $permiso);
            if ($sedes === null) {
                return;
            }
            if ($datos['aplica'] === 'todas') {
                $v->errors()->add('sedes', 'Solo puedes hacer procedimientos para tus sedes: elige «Sedes elegidas» y marca las tuyas.');
            } elseif (array_diff($datos['sedes'], $sedes) !== []) {
                $v->errors()->add('sedes', 'Solo puedes elegir las sedes en las que trabajas.');
            }
        });
        $validado = $validador->validate();

        return array_merge($datos, ['categoria_id' => (int) $validado['categoria_id']]);
    }

    /**
     * Opciones del formulario.
     *
     * @return array<string, mixed>
     */
    public function opcionesFormulario(User $actor, string $permiso): array
    {
        $sedes = $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, $permiso);

        return [
            'categorias' => $this->categorias(true),
            'sedes' => Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                ->filter(fn ($s) => $sedes === null || in_array((int) $s->id, $sedes, true))->values(),
            'todasPermitido' => $sedes === null,
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'puestos' => Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
