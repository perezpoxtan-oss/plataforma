<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\EquipoPc;
use App\Models\Gafete;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Lector\Identificacion;
use App\Services\Lector\Lector;
use App\Services\Padrones\AdministradorProveedores;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Padrones\AvisoDuplicado;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ronda 6 de ajustes: avisos de duplicado EN VIVO de los padrones que tenían
 * su propio aviso (Proveedores, Vehículos, Colaboradores, Gafetes, Equipos y
 * Equipos de Protección Civil; y Candidatos: teléfono o CURP de otra ficha) y de la etiqueta NFC / RFID de cualquier tipo
 * del lector (GV-03), con el mecanismo único de Ronda 5 (parte 2):
 * <input data-duplicado="URL"> + App\Services\Padrones\AvisoDuplicado.
 * Ver docs/tecnico/avisos-duplicado.md.
 *
 * Todas: GET ?campo=…&valor=…[&excluir=ID][&…], con la empresa de trabajo, el
 * permiso de crear o editar del módulo y sin revelar datos de registros de
 * sedes que el usuario no tiene a cargo («… en una sede que no tienes a cargo»).
 * El servidor vuelve a revisar todo al guardar: esto solo avisa antes.
 */
class DuplicadoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AvisoDuplicado $avisos,
        private readonly Autorizador $autorizador,
    ) {}

    // ------------------------------------------------------------ Proveedores

    /** Razón social: igual (sin mayúsculas ni espacios dobles) = existe; parecida (sin «S.A. de C.V.» y similares) = parecido. */
    public function proveedores(Request $request): JsonResponse
    {
        return $this->responder($request, 'proveedores', function (User $actor, string $valor, ?int $excluir) {
            if (mb_strlen($valor) < 3) {
                return $this->avisos->nada();
            }
            $reactivar = fn (Proveedor $p) => $actor->can('proveedores.eliminar') ? route('proveedores.estado', $p->id) : null;
            $mias = app(AdministradorProveedores::class)->sedes($actor, 'proveedores.crear');
            $igual = app(AdministradorProveedores::class)->buscarPorNombre($valor);
            if ($igual !== null && $igual->id !== $excluir) {
                $coincidencia = $this->avisos->coincidencia($igual->nombre, Proveedor::CATEGORIAS[$igual->categoria] ?? null, ! $igual->activo, $reactivar($igual));
                if (! $igual->activo) {
                    return $this->avisos->existe("Ya existe «{$igual->nombre}», pero está desactivada. ¿La reactivas en lugar de registrarla otra vez?", [$coincidencia]);
                }
                if ($excluir === null && $mias !== null) {
                    // Con alcance de sede no se rechaza: al guardar se agrega a su sede
                    return $this->avisos->parecido("Ya existe «{$igual->nombre}»: al guardar no se crea otra, solo se agrega a tu sede.", [$coincidencia]);
                }

                return $this->avisos->existe("Ya existe «{$igual->nombre}» en esta empresa. Búscala en la lista para editarla.", [$coincidencia]);
            }

            $parecidas = Proveedor::query()->whereKey(array_column(app(AltasPorVerificar::class)->parecidos('proveedores', ['nombre' => $valor], null, $excluir, 3), 'id'))->get();
            if ($parecidas->isEmpty()) {
                return $this->avisos->libre('Nombre disponible.');
            }

            return $this->avisos->parecido('Se parece a una empresa que ya está registrada (no cuentan «S.A. de C.V.» ni los signos). Revisa que no sea la misma:',
                $parecidas->map(fn (Proveedor $p) => $this->avisos->coincidencia($p->nombre, Proveedor::CATEGORIAS[$p->categoria] ?? null, ! $p->activo, $reactivar($p)))->all());
        });
    }

    // -------------------------------------------------------------- Vehículos

    /** Placas: iguales sin espacios ni guiones = existe; con O/0, I/1 o una letra de diferencia = parecido. */
    public function vehiculos(Request $request): JsonResponse
    {
        return $this->responder($request, 'vehiculos', function (User $actor, string $valor, ?int $excluir) {
            $placas = Vehiculo::normalizarPlacas($valor);
            if (mb_strlen($placas) < 3) {
                return $this->avisos->nada();
            }
            $reactivar = fn (Vehiculo $v) => $actor->can('vehiculos.eliminar') && ! $v->estaRechazado() ? route('vehiculos.estado', $v->id) : null;
            $detalle = fn (Vehiculo $v) => implode(' · ', array_filter([trim($v->marca.' '.$v->modelo), $v->color, Vehiculo::PROPIEDADES[$v->propiedad] ?? null, $v->activo ? null : 'Dado de baja']));

            $igual = Vehiculo::where('placas', $placas)->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
            if ($igual !== null) {
                return $this->avisos->existe($igual->activo
                    ? "Las placas {$placas} ya están registradas en el padrón. No se pueden repetir."
                    : "Las placas {$placas} ya están registradas, pero el vehículo está dado de baja. ¿Lo reactivas en lugar de registrarlo otra vez?",
                    [$this->avisos->coincidencia($igual->placas, $detalle($igual), ! $igual->activo, $reactivar($igual))]);
            }

            $parecidos = Vehiculo::query()->whereKey(array_column(app(AltasPorVerificar::class)->parecidos('vehiculos', ['placas' => $placas], null, $excluir, 3), 'id'))->get();
            if ($parecidos->isEmpty()) {
                return $this->avisos->libre("Se guardarán como {$placas}.");
            }

            return $this->avisos->parecido('Se parecen a placas ya registradas (la O y el 0, o la I y el 1, se confunden). Revisa que no sea el mismo vehículo:',
                $parecidos->map(fn (Vehiculo $v) => $this->avisos->coincidencia($v->placas, $detalle($v), ! $v->activo, $reactivar($v)))->all());
        });
    }

    // ---------------------------------------------------------- Colaboradores

    /** Número de empleado (exacto, sin mayúsculas) = existe; nombre + apellidos iguales sin acentos = parecido. */
    public function colaboradores(Request $request): JsonResponse
    {
        return $this->responder($request, 'colaboradores', function (User $actor, string $valor, ?int $excluir) use ($request) {
            $sedes = $this->sedesUnion($actor, ['colaboradores.crear', 'colaboradores.editar', 'colaboradores.aprobar']);
            $reactivar = fn (Colaborador $c) => $actor->can('colaboradores.eliminar') && $c->fusionado_en_id === null ? route('colaboradores.estado', $c->id) : null;
            $visible = fn (Colaborador $c) => $sedes === null || $c->sede_id === null || in_array((int) $c->sede_id, $sedes, true);

            if (Entrada::texto($request->query('campo')) === 'num_empleado') {
                $numero = mb_strtoupper(trim($valor));
                if ($numero === '') {
                    return $this->avisos->nada();
                }
                $otro = Colaborador::whereRaw('UPPER(num_empleado) = ?', [$numero])->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
                if ($otro === null) {
                    return $this->avisos->libre('Número disponible.');
                }
                if (! $visible($otro)) {
                    return $this->avisos->existe("Ya existe otro colaborador con el número «{$numero}» en una sede que no tienes a cargo. Usa otro número o pide ayuda a Recursos Humanos.");
                }

                return $this->avisos->existe("Ya existe un colaborador con el número «{$numero}» en esta empresa. No se puede repetir.",
                    [$this->avisos->coincidencia($otro->nombreCompleto(), $this->detalleColaborador($otro), ! $otro->activo, $reactivar($otro))]);
            }

            $paterno = mb_substr(trim(Entrada::texto($request->query('apellido_paterno'))), 0, 60);
            $materno = mb_substr(trim(Entrada::texto($request->query('apellido_materno'))), 0, 60);
            if (mb_strlen($valor) < 2 || $paterno === '') {
                return $this->avisos->nada();
            }
            $todos = collect(app(AdministradorColaboradores::class)->parecidos($valor, $paterno, $materno === '' ? null : $materno))
                ->reject(fn (array $c) => $excluir !== null && (int) $c['id'] === $excluir);
            if ($todos->isEmpty()) {
                return $this->avisos->nada();
            }
            $modelos = Colaborador::with('puesto:id,nombre', 'sede:id,nombre')->whereKey($todos->pluck('id'))->get();
            $mios = $modelos->filter($visible);
            $otros = $modelos->count() - $mios->count();

            return $this->avisos->parecido(($modelos->count() === 1 ? 'Ya existe un colaborador con ese nombre' : 'Ya existen colaboradores con ese nombre')
                .($otros > 0 ? ($otros === 1 ? ' (uno en una sede que no tienes a cargo)' : " ({$otros} en sedes que no tienes a cargo)") : '')
                .'. ¿Es la misma persona? Si lo es, no lo des de alta otra vez: búscalo en la lista.',
                $mios->map(fn (Colaborador $c) => $this->avisos->coincidencia($c->nombreCompleto(), $this->detalleColaborador($c), false))->values()->all());
        }, ['colaboradores.crear', 'colaboradores.editar', 'colaboradores.aprobar']);
    }

    // ------------------------------------------------------------- Candidatos

    /**
     * «Nuevo candidato» de RR. HH. (fase 1: una ficha por persona): teléfono o
     * CURP que ya tiene otra ficha de la empresa. No se rechaza: al guardar se
     * usa su ficha. Se ofrece «Abrir su ficha» si está en sus sedes.
     */
    public function candidatos(Request $request): JsonResponse
    {
        return $this->responder($request, 'candidatos', function (User $actor, string $valor) use ($request) {
            $campo = Entrada::texto($request->query('campo'));
            if (! in_array($campo, ['telefono', 'curp'], true)) {
                return $this->avisos->nada();
            }
            $administrador = app(AdministradorCandidatos::class);
            $ficha = $campo === 'telefono' ? $administrador->buscarFicha(null, $valor, null) : $administrador->buscarFicha(null, null, $valor);
            if ($ficha === null) {
                return $this->avisos->nada();
            }
            $dato = $campo === 'telefono' ? 'ese teléfono' : 'ese CURP';
            $visible = $actor->can('candidatos.ver') && $administrador->limitar(Candidato::query(), $actor, 'candidatos.ver')->whereKey($ficha->id)->exists();
            if (! $visible) {
                return $this->avisos->parecido("Ya existe la ficha de un candidato con {$dato} en una sede que no tienes a cargo. Al guardar no se crea otra.", []);
            }

            return $this->avisos->parecido("Ya existe la ficha de «{$ficha->nombre_completo}» con {$dato}. Al guardar no se crea otra: se usa su ficha (si no tiene una postulación en proceso, se le abre una nueva).",
                [$this->avisos->coincidencia($ficha->nombre_completo, ($ficha->etiquetaEtapa()).' · '.($ficha->sede?->nombre ?? ''), false, null, route('candidatos.show', $ficha->id))]);
        }, ['candidatos.crear']);
    }

    // ---------------------------------------------------------------- Gafetes

    /** Folio (nomenclatura): igual = existe; igual sin guiones ni espacios = parecido. */
    public function gafetes(Request $request): JsonResponse
    {
        return $this->responder($request, 'gafetes', function (User $actor, string $valor, ?int $excluir) {
            $folio = AdministradorGafetes::normalizarNomenclatura($valor);
            if (mb_strlen($folio) < 3) {
                return $this->avisos->nada();
            }
            $sedes = $this->sedesUnion($actor, ['gafetes.crear', 'gafetes.editar']);
            $reactivar = fn (Gafete $g) => $actor->can('gafetes.eliminar') ? route('gafetes.reactivar', $g->id) : null;
            $describir = fn (Gafete $g) => $this->avisos->coincidencia($g->nomenclatura, $g->resumenLector()['detalle'], ! $g->activo, $reactivar($g));

            $igual = Gafete::with(['tipo:id,nombre', 'sede:id,nombre'])->where('nomenclatura', $folio)->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
            if ($igual !== null) {
                if (! $this->enSedes($igual->sede_id, $sedes)) {
                    return $this->avisos->existe("Ya existe un gafete «{$folio}» en una sede que no tienes a cargo. Usa otro folio.");
                }

                return $this->avisos->existe($igual->activo
                    ? "Ya existe un gafete con el folio «{$folio}» en esta empresa. No se puede repetir."
                    : "Ya existe el gafete «{$folio}», pero está dado de baja. ¿Lo reactivas en lugar de repetir el folio?", [$describir($igual)]);
            }

            $parecidos = Gafete::with(['tipo:id,nombre', 'sede:id,nombre'])->whereRaw($this->sinSignos('nomenclatura').' = ?', [$this->avisos->clave($folio)])
                ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->limit(3)->get()
                ->filter(fn (Gafete $g) => $this->enSedes($g->sede_id, $sedes));
            if ($parecidos->isEmpty()) {
                return $this->avisos->libre('Folio disponible.');
            }

            return $this->avisos->parecido('Se parece a un folio ya registrado (los guiones y espacios no cuentan):', $parecidos->map($describir)->values()->all());
        });
    }

    // ---------------------------------------------------------------- Equipos

    /** Número de serie: igual = existe (en toda la empresa); igual sin guiones ni espacios = parecido. */
    public function equipos(Request $request): JsonResponse
    {
        return $this->responder($request, 'equipos', function (User $actor, string $valor, ?int $excluir) {
            $serie = Equipo::normalizarSerie($valor);
            if (mb_strlen($serie) < 3) {
                return $this->avisos->nada();
            }
            $sedes = $this->sedesUnion($actor, ['equipos.crear', 'equipos.editar']);
            $reactivar = fn (Equipo $e) => $actor->can('equipos.eliminar') && $e->estado === 'baja' ? route('equipos.reactivar', $e->id) : null;
            $describir = fn (Equipo $e) => $this->avisos->coincidencia('Serie: '.$e->numero_serie, $e->resumenLector()['detalle'], $e->estado === 'baja', $reactivar($e));

            $igual = Equipo::with(['tipo:id,nombre', 'sede:id,nombre'])->where('numero_serie', $serie)->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
            if ($igual !== null) {
                if (! $this->enSedes($igual->sede_id, $sedes)) {
                    return $this->avisos->existe("El número de serie «{$serie}» ya está registrado en una sede que no tienes a cargo.");
                }

                return $this->avisos->existe($igual->estaActivo()
                    ? "El número de serie «{$serie}» ya está registrado en esta empresa. No se puede repetir."
                    : "El número de serie «{$serie}» ya está registrado, pero el equipo está de baja. ¿Lo reactivas en lugar de registrarlo otra vez?", [$describir($igual)]);
            }

            $parecidos = Equipo::with(['tipo:id,nombre', 'sede:id,nombre'])->whereRaw($this->sinSignos('numero_serie').' = ?', [$this->avisos->clave($serie)])
                ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->limit(3)->get()
                ->filter(fn (Equipo $e) => $this->enSedes($e->sede_id, $sedes));
            if ($parecidos->isEmpty()) {
                return $this->avisos->libre('Número de serie disponible.');
            }

            return $this->avisos->parecido('Se parece a un número de serie ya registrado (los guiones y espacios no cuentan):', $parecidos->map($describir)->values()->all());
        });
    }

    /** Equipos de Protección Civil: el Núm. de Serie / ID es único POR SEDE (necesita sede_id). */
    public function equiposPc(Request $request): JsonResponse
    {
        return $this->responder($request, 'equipos_pc', function (User $actor, string $valor, ?int $excluir) use ($request) {
            $serie = EquipoPc::normalizarSerie($valor);
            $sedeId = ctype_digit(Entrada::texto($request->query('sede_id'))) ? (int) $request->query('sede_id') : null;
            if (mb_strlen($serie) < 2 || $sedeId === null || ! $this->enSedes($sedeId, $this->sedesUnion($actor, ['equipos_pc.crear', 'equipos_pc.editar']))) {
                return $this->avisos->nada();
            }
            $reactivar = fn (EquipoPc $e) => $actor->can('equipos_pc.eliminar') ? route('equipos_pc.reactivar', $e->id) : null;
            $describir = fn (EquipoPc $e) => $this->avisos->coincidencia('ID: '.$e->numero_serie, $e->etiquetaCategoria(), ! $e->activo, $reactivar($e));

            $igual = EquipoPc::where('sede_id', $sedeId)->where('numero_serie', $serie)->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->first();
            if ($igual !== null) {
                return $this->avisos->existe($igual->activo
                    ? "El Núm. de Serie / ID «{$serie}» ya está registrado en esta sede. No se puede repetir."
                    : "El Núm. de Serie / ID «{$serie}» ya está registrado en esta sede, pero está desactivado. ¿Lo reactivas?", [$describir($igual)]);
            }
            $parecidos = EquipoPc::where('sede_id', $sedeId)->whereRaw($this->sinSignos('numero_serie').' = ?', [$this->avisos->clave($serie)])
                ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir))->limit(3)->get();
            if ($parecidos->isEmpty()) {
                return $this->avisos->libre('ID disponible en esta sede.');
            }

            return $this->avisos->parecido('Se parece a un ID ya registrado en esta sede (los guiones y espacios no cuentan):', $parecidos->map($describir)->values()->all());
        });
    }

    // ------------------------------------------------- Etiqueta NFC / RFID (GV-03)

    /**
     * ¿La tarjeta o etiqueta leída ya la tiene otro registro de la empresa, de
     * cualquier tipo? (el lector no sabría cuál abrir). {tipo} es el tipo del
     * registro (config/lector.php) y {id} el registro que se edita (0 = alta
     * nueva; en una edición genérica también llega como ?excluir=).
     */
    public function etiqueta(Request $request, string $tipo, int $id): JsonResponse
    {
        $identificacion = app(Identificacion::class);
        $modulo = $identificacion->modulo($tipo);
        $actor = $request->user();
        abort_unless($actor->can($modulo.'.crear') || $actor->can($modulo.'.editar'), 403);
        if ($id > 0) {
            // El registro de la dirección debe ser de la empresa de trabajo y estar a su alcance (si no, 404)
            $empresaId = $this->empresa->id($actor);
            abort_if($empresaId === null, 404);
            $this->tenant->conEmpresa($empresaId, fn () => $identificacion->buscar($actor, $tipo, $id, 'editar'));
            $request->query->set('excluir', (string) $id);
        }

        return $this->responder($request, $modulo, function (User $actor, string $valor, ?int $excluir) use ($tipo, $identificacion) {
            $limpia = Etiqueta::normalizar($valor);
            if (mb_strlen($limpia) < 4) {
                return $this->avisos->nada();
            }
            if ($excluir !== null) {
                // El registro que se edita debe estar a su alcance; si no, no se dice nada
                $propio = rescue(fn () => $identificacion->buscar($actor, $tipo, $excluir, 'editar'), null, false);
                if ($propio === null) {
                    return $this->avisos->nada();
                }
            }

            $clasePropia = $identificacion->clase($tipo);
            foreach (app(Lector::class)->tipos() as $otroTipo => $clase) {
                $ocupada = $clase::etiquetaOcupada($limpia, $clase === $clasePropia ? $excluir : null);
                if ($ocupada === null) {
                    continue;
                }
                $resumen = $ocupada->resumenLector();
                $nombre = Identificacion::NOMBRES[$otroTipo] ?? 'otro registro';
                if (! $this->visibleParaVer($actor, $clase, $resumen['sede_id'] ?? null)) {
                    return $this->avisos->existe("Esa tarjeta o etiqueta ya la tiene {$nombre} de una sede que no tienes a cargo. Usa otra.");
                }

                return $this->avisos->existe("Esa tarjeta o etiqueta ya la tiene {$nombre} «{$resumen['titulo']}». Quítasela primero ahí o usa otra.",
                    [$this->avisos->coincidencia((string) $resumen['titulo'], $resumen['detalle'] ?? null)]);
            }

            return $this->avisos->libre('Etiqueta libre: se puede asignar.');
        });
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Permiso (crear o editar del módulo), empresa de trabajo y lectura de
     * valor / excluir comunes a todos los avisos.
     *
     * @param  Closure(User, string, ?int): JsonResponse  $revisar
     * @param  list<string>|null  $permisos
     */
    private function responder(Request $request, string $modulo, Closure $revisar, ?array $permisos = null): JsonResponse
    {
        $actor = $request->user();
        $permisos ??= [$modulo.'.crear', $modulo.'.editar'];
        abort_unless(collect($permisos)->contains(fn ($p) => $actor->can($p)), 403);
        $empresaId = $this->empresa->id($actor);
        $valor = mb_substr(trim(Entrada::texto($request->query('valor'))), 0, 150);
        $excluir = ctype_digit(Entrada::texto($request->query('excluir'))) ? (int) $request->query('excluir') : null;
        if ($empresaId === null || $valor === '') {
            return $this->avisos->nada();
        }

        return $this->tenant->conEmpresa($empresaId, fn () => $revisar($actor, $valor, $excluir));
    }

    /**
     * Sedes donde el usuario usa ALGUNO de los permisos (null = todas).
     *
     * @param  list<string>  $permisos
     * @return list<int>|null
     */
    private function sedesUnion(User $actor, array $permisos): ?array
    {
        $todas = [];
        foreach ($permisos as $permiso) {
            if (! $actor->can($permiso)) {
                continue;
            }
            $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
            if ($sedes === null) {
                return null;
            }
            $todas = [...$todas, ...$sedes];
        }

        return array_values(array_unique(array_map('intval', $todas)));
    }

    /** @param  list<int>|null  $sedes */
    private function enSedes(?int $sedeId, ?array $sedes): bool
    {
        return $sedes === null || $sedeId === null || in_array($sedeId, $sedes, true);
    }

    /** ¿Puede ver los datos de ese registro? (el «ver» de su módulo, en su sede) */
    private function visibleParaVer(User $actor, string $clase, ?int $sedeId): bool
    {
        $permiso = $clase::permisoLector();
        if (! $actor->can($permiso)) {
            return false;
        }

        return $this->enSedes($sedeId, $this->autorizador->sedesPermitidas($actor, $permiso));
    }

    /** Columna en mayúsculas y sin espacios, guiones, puntos ni diagonales (SQLite y MariaDB). */
    private function sinSignos(string $columna): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER({$columna}), ' ', ''), '-', ''), '.', ''), '/', '')";
    }

    private function detalleColaborador(Colaborador $c): string
    {
        $c->loadMissing('puesto:id,nombre', 'sede:id,nombre');

        return implode(' · ', array_filter([
            $c->num_empleado ? 'Núm. '.$c->num_empleado : 'Alta provisional',
            $c->puesto?->nombre,
            $c->sede?->nombre ?? 'Corporativo',
            $c->activo ? null : 'Dado de baja',
        ]));
    }
}
