<?php

namespace App\Services\Activos;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\Notificacion;
use App\Models\PaseSalida;
use App\Models\PaseSalidaArticulo;
use App\Models\PrestamoLlave;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\EstadoActivo;
use App\Support\Receptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Plan de correcciones, C-14: candados cruzados de activos. Cada catálogo
 * (Llaves, Gafetes, Equipos) pregunta aquí en qué estado está el objeto en
 * los demás módulos antes de darlo de baja, y la ficha muestra quién lo tiene:
 * «EN USO por Juan Pérez (Préstamo de llaves)», «FUERA en PS-000007».
 *
 * También propone a quién cargarle el voucher (quien lo tenía) y avisa en la
 * campana cuando algo «No regresó» y quien lo marcó no puede darlo de baja.
 *
 * Corre con la empresa de trabajo ya fijada en el Tenant.
 */
class CustodiaActivos
{
    public const TIPO_AVISO = 'activo_no_regreso';

    /** origen => [permiso para darlo de baja, ruta de su catálogo, ancla] */
    private const CATALOGO = [
        'llave' => ['llaves.eliminar', 'llaves.index', 'llave-'],
        'gafete' => ['gafetes.eliminar', 'gafetes.index', 'gafete-'],
        'equipo' => ['equipos.eliminar', 'equipos.index', 'equipo-'],
    ];

    public function __construct(private readonly CentroNotificaciones $centro) {}

    // ------------------------------------------------------------- Estado

    /**
     * Estado de la máquina única y quién lo tiene.
     *
     * @return array{estado: string, texto: string, quien: ?string, donde: ?string, url: ?string}
     */
    public function estado(Model $activo): array
    {
        return match (true) {
            $activo instanceof Llave => $this->deLlave($activo),
            $activo instanceof Gafete => $this->deGafete($activo),
            $activo instanceof Equipo => $this->deEquipo($activo),
            default => $this->resultado(EstadoActivo::DISPONIBLE),
        };
    }

    /**
     * @return array{estado: string, texto: string, quien: ?string, donde: ?string, url: ?string}
     */
    private function deLlave(Llave $llave): array
    {
        if (! $llave->activo) {
            return $this->resultado(EstadoActivo::BAJA);
        }
        $prestamo = PrestamoLlave::vigentes()->where('llave_id', $llave->id)->latest('prestado_en')->first();
        if ($prestamo !== null) {
            return $this->resultado(EstadoActivo::EN_USO, $this->receptorDePrestamo($prestamo), 'Préstamo de llaves',
                route('prestamo_llaves.index').'#prestamo-'.$prestamo->id);
        }
        if ($llave->noRegreso()) {
            $ultimo = PrestamoLlave::where('llave_id', $llave->id)->where('estado', PrestamoLlave::NO_REGRESO)->where('anulado', false)->latest('prestado_en')->first();

            return $this->resultado(EstadoActivo::NO_REGRESO, $ultimo ? $this->receptorDePrestamo($ultimo) : null, 'Préstamo de llaves',
                $ultimo ? route('prestamo_llaves.index').'#prestamo-'.$ultimo->id : null);
        }
        if ($llave->colaborador_id !== null) {
            return $this->resultado(EstadoActivo::ASIGNADO, $llave->colaborador?->nombreCompleto(), 'Responsable permanente', null);
        }

        return $this->resultado(EstadoActivo::DISPONIBLE);
    }

    /**
     * @return array{estado: string, texto: string, quien: ?string, donde: ?string, url: ?string}
     */
    private function deGafete(Gafete $gafete): array
    {
        if (! $gafete->activo) {
            return $this->resultado(EstadoActivo::BAJA);
        }
        $acceso = Acceso::where('gafete_id', $gafete->id)->whereIn('estado', Acceso::ESTADOS_ABIERTOS)->latest('entrada_at')->first();
        $acompanante = $acceso === null ? AcompananteAcceso::where('gafete_id', $gafete->id)->whereNull('salida_at')
            ->whereHas('acceso', fn ($q) => $q->whereIn('estado', Acceso::ESTADOS_ABIERTOS))->latest('id')->first() : null;
        if ($acceso !== null || $acompanante !== null) {
            $nombre = $acceso?->nombre ?? $acompanante?->nombreVisible();

            return $this->resultado(EstadoActivo::EN_USO, $nombre, 'Bitácora de accesos',
                route('accesos.index', ['salida' => $gafete->nomenclatura]));
        }
        if ($gafete->noRegreso()) {
            $ultimo = $this->ultimoPrestamoGafete($gafete);

            return $this->resultado(EstadoActivo::NO_REGRESO, $ultimo['nombre'] ?? null, 'Bitácora de accesos', null);
        }

        return $this->resultado(EstadoActivo::DISPONIBLE);
    }

    /**
     * @return array{estado: string, texto: string, quien: ?string, donde: ?string, url: ?string}
     */
    private function deEquipo(Equipo $equipo): array
    {
        $estado = $equipo->estado ?? EstadoActivo::DISPONIBLE;
        if (in_array($estado, [...EstadoActivo::EN_RESGUARDO, EstadoActivo::NO_REGRESO], true)) {
            $fila = EquipoResponsiva::with('responsiva')->where('equipo_id', $equipo->id)->whereNull('devuelto_en')->latest('id')->first();
            if ($fila?->responsiva !== null) {
                return $this->resultado($estado, Receptor::nombreDe($fila->responsiva->receptor()), 'Responsiva '.$fila->responsiva->folio,
                    route('responsivas.index').'#responsiva-'.$fila->responsiva->id);
            }
        }
        if (in_array($estado, [EstadoActivo::FUERA, EstadoActivo::NO_REGRESO], true)) {
            $pase = $this->paseDeEquipo($equipo);
            if ($pase !== null) {
                return $this->resultado($estado, $pase->nombreDestino(), $pase->folio, route('pases-salida.show', $pase->id));
            }
        }

        return $this->resultado($estado);
    }

    /** Último pase en que salió el equipo y no ha regresado (o se cerró con faltantes). */
    public function paseDeEquipo(Equipo $equipo): ?PaseSalida
    {
        $articulo = PaseSalidaArticulo::with('pase')->where('equipo_id', $equipo->id)
            ->whereColumn('cantidad_regresada', '<', 'cantidad')
            ->whereHas('pase', fn ($q) => $q->whereNotNull('salio_en'))
            ->latest('id')->first();

        return $articulo?->pase;
    }

    /**
     * @return array{estado: string, texto: string, quien: ?string, donde: ?string, url: ?string}
     */
    private function resultado(string $estado, ?string $quien = null, ?string $donde = null, ?string $url = null): array
    {
        return ['estado' => $estado, 'texto' => EstadoActivo::texto($estado), 'quien' => $quien, 'donde' => $donde, 'url' => $url];
    }

    private function receptorDePrestamo(PrestamoLlave $p): string
    {
        return Receptor::nombreDe($p->receptor());
    }

    // ----------------------------------------------------------- Candados

    /**
     * Regla 2 de la máquina: lo que alguien tiene (EN USO, ASIGNADO en una
     * responsiva, FUERA) no se da de baja desde su catálogo; el mensaje manda
     * al módulo que lo tiene. NO REGRESÓ y la llave con responsable
     * permanente sí se dan de baja (el voucher propone a quien la tenía).
     */
    public function exigirBajaPermitida(Model $activo): void
    {
        $e = $this->estado($activo);
        $quien = $e['quien'] !== null ? " {$e['quien']}" : '';
        $mensaje = match (true) {
            $activo instanceof Llave && $e['estado'] === EstadoActivo::EN_USO => "La llave {$activo->nomenclatura} está prestada a{$quien}: recíbela o márcala «No regresó» en Préstamo de llaves.",
            $activo instanceof Gafete && $e['estado'] === EstadoActivo::EN_USO => "El gafete {$activo->nomenclatura} está EN SITIO con{$quien}: dale salida en la Bitácora de accesos (ahí se marca si no lo devolvió).",
            $activo instanceof Equipo && in_array($e['estado'], EstadoActivo::EN_RESGUARDO, true) => "El equipo {$activo->numero_serie} está ".EstadoActivo::texto($e['estado'])." a cargo de{$quien} ({$e['donde']}): recíbelo en Responsivas (si no regresó, «Faltante»).",
            $activo instanceof Equipo && $e['estado'] === EstadoActivo::FUERA => "El equipo {$activo->numero_serie} está FUERA en el pase {$e['donde']}: regístralo en Pases de salida.",
            default => null,
        };
        if ($mensaje !== null) {
            throw ValidationException::withMessages(['motivo' => $mensaje]);
        }
    }

    // ------------------------------------------- Responsable propuesto

    /**
     * A quién cargarle el voucher: quien lo tenía (préstamo de llave, último
     * acceso con el gafete, responsiva del equipo) o el responsable permanente.
     *
     * @return array{tipo: string, id: int, titulo: string}|null
     */
    public function responsablePropuesto(Model $activo): ?array
    {
        if ($activo instanceof Llave) {
            $p = PrestamoLlave::where('llave_id', $activo->id)->where('anulado', false)
                ->whereIn('estado', [PrestamoLlave::EN_USO, PrestamoLlave::NO_REGRESO])->latest('prestado_en')->first();

            return Receptor::propuesta($p?->receptor() ?? $activo->colaborador);
        }
        if ($activo instanceof Gafete) {
            return $this->ultimoPrestamoGafete($activo)['propuesta'] ?? null;
        }
        if ($activo instanceof Equipo) {
            $fila = EquipoResponsiva::with('responsiva')->where('equipo_id', $activo->id)->whereNull('devuelto_en')->latest('id')->first();

            return Receptor::propuesta($fila?->responsiva?->receptor());
        }

        return null;
    }

    /**
     * Último acceso (titular o acompañante) con ese gafete: quién, cuándo y en
     * qué caseta, y a quién proponer en el voucher (la persona del padrón o, si
     * no hay, su empresa externa).
     *
     * @return array{nombre: string, cuando: ?\DateTimeInterface, sede: ?string, propuesta: ?array}|null
     */
    public function ultimoPrestamoGafete(Gafete $gafete): ?array
    {
        $acceso = Acceso::with(['sede:id,nombre', 'persona', 'proveedor'])->where('gafete_id', $gafete->id)->latest('entrada_at')->first();
        $acompanante = AcompananteAcceso::with(['acceso.sede:id,nombre', 'acceso.proveedor'])->where('gafete_id', $gafete->id)->latest('id')->first();
        if ($acompanante !== null && ($acceso === null || $acompanante->acceso?->entrada_at?->gt($acceso->entrada_at))) {
            return [
                'nombre' => $acompanante->nombreVisible().' (acompañante de '.$acompanante->acceso?->nombre.')',
                'cuando' => $acompanante->acceso?->entrada_at,
                'sede' => $acompanante->acceso?->sede?->nombre,
                'propuesta' => Receptor::propuesta($acompanante->acceso?->proveedor),
            ];
        }
        if ($acceso === null) {
            return null;
        }

        return [
            'nombre' => $acceso->nombre,
            'cuando' => $acceso->entrada_at,
            'sede' => $acceso->sede?->nombre,
            'propuesta' => Receptor::propuesta($acceso->persona ?? $acceso->proveedor ?? $acceso->colaborador),
        ];
    }

    /**
     * Para la lista de Gafetes (sin una consulta por ficha): con quién está
     * cada gafete EN USO y su último préstamo (quién, cuándo y en qué sede).
     *
     * @param  list<int>  $ids
     * @return array{en_uso: array<int, string>, ultimo: array<int, array{nombre: string, cuando: mixed, sede: ?string}>}
     */
    public function gafetesEnLista(array $ids): array
    {
        if ($ids === []) {
            return ['en_uso' => [], 'ultimo' => []];
        }
        $enUso = [];
        $ultimo = [];
        $ultimos = Acceso::query()->whereIn('gafete_id', $ids)->groupBy('gafete_id')->selectRaw('MAX(id) as id')->pluck('id');
        foreach (Acceso::with('sede:id,nombre')->whereKey($ultimos)->get(['id', 'gafete_id', 'nombre', 'entrada_at', 'estado', 'sede_id']) as $a) {
            $ultimo[(int) $a->gafete_id] = ['nombre' => $a->nombre, 'cuando' => $a->entrada_at, 'sede' => $a->sede?->nombre];
            if (in_array($a->estado, Acceso::ESTADOS_ABIERTOS, true)) {
                $enUso[(int) $a->gafete_id] = $a->nombre;
            }
        }
        $ultimosAc = AcompananteAcceso::query()->whereIn('gafete_id', $ids)->groupBy('gafete_id')->selectRaw('MAX(id) as id')->pluck('id');
        foreach (AcompananteAcceso::with('acceso:id,nombre,entrada_at,estado,sede_id', 'acceso.sede:id,nombre')->whereKey($ultimosAc)->get() as $ac) {
            $g = (int) $ac->gafete_id;
            $cuando = $ac->acceso?->entrada_at;
            if (! isset($ultimo[$g]) || ($cuando !== null && $ultimo[$g]['cuando'] !== null && $cuando->gt($ultimo[$g]['cuando']))) {
                $ultimo[$g] = ['nombre' => $ac->nombreVisible().' (acompañante de '.$ac->acceso?->nombre.')', 'cuando' => $cuando, 'sede' => $ac->acceso?->sede?->nombre];
            }
            if ($ac->salida_at === null && in_array($ac->acceso?->estado, Acceso::ESTADOS_ABIERTOS, true)) {
                $enUso[$g] = $ac->nombreVisible();
            }
        }

        return ['en_uso' => $enUso, 'ultimo' => $ultimo];
    }

    // ----------------------------------------------------------- Avisos

    /**
     * «No regresó»: quien puede darlo de baja en esa sede lo ve en la campana
     * con el botón «Dar de baja» (abre el catálogo con el voucher propuesto).
     * No avisa a quien lo marcó si él mismo puede darlo de baja.
     *
     * @return list<int> avisados
     */
    public function avisarNoRegreso(Model $activo, string $origen, string $descripcion, string $detalle, ?User $quien): array
    {
        [$permiso, $ruta, $ancla] = self::CATALOGO[$origen];
        $usuarios = app(CampanaActivos::class)->conPermiso((int) $activo->getAttribute('empresa_id'), $permiso, $activo->getAttribute('sede_id'))
            ->reject(fn (int $id) => $quien !== null && $id === (int) $quien->id);
        $url = route($ruta, ['baja' => $activo->getKey()]).'#'.$ancla.$activo->getKey();

        return $this->centro->avisar((int) $activo->getAttribute('empresa_id'), $usuarios, self::TIPO_AVISO, [
            'titulo' => "No regresó: {$descripcion}",
            'texto' => $detalle.' Si aparece, recíbelo; si no, dalo de baja con su voucher.',
            'url' => $url,
            'referencia_tipo' => self::TIPO_AVISO.'_'.$origen,
            'referencia_id' => (int) $activo->getKey(),
            'acciones' => [['etiqueta' => 'Dar de baja', 'abrir' => true]],
        ]);
    }

    /** Se recibió o se dio de baja: el aviso pierde su botón. */
    public function resolverNoRegreso(Model $activo, string $origen): void
    {
        if (Notificacion::where('referencia_tipo', self::TIPO_AVISO.'_'.$origen)->where('referencia_id', $activo->getKey())->exists()) {
            $this->centro->resolver(self::TIPO_AVISO.'_'.$origen, (int) $activo->getKey());
        }
    }
}
