<?php

namespace App\Services\Lector;

use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Identificable;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ronda 6 (LL-06): impresión masiva de etiquetas QR de todo lo que se
 * identifica con el lector universal (config/lector.php): llaves, gafetes,
 * equipos, equipos de Protección Civil, vehículos, colaboradores, Lost &
 * Found, procedimientos… y cualquier tipo que se agregue después.
 *
 * Cada tipo se ofrece solo si el usuario puede «<módulo>.ver» y además la
 * acción de imprimir de ese módulo («<módulo>.imprimir»; si el módulo no la
 * tiene, como Colaboradores, «<módulo>.editar»). Los registros se limitan a
 * las sedes de ambos permisos y, con alcance «propios», a los que dio de alta.
 *
 * La etiqueta individual sigue en el diálogo «Código e identificación».
 * Debe correr con la empresa de trabajo ya fijada en el Tenant.
 */
class EtiquetasMasivas
{
    /** Máximo de etiquetas por impresión (una hoja de 50×25 mm lleva ~30). */
    public const MAXIMO = 200;

    /** Registros por tipo en la lista (se acota con búsqueda y filtros). */
    public const POR_TIPO = 500;

    /** Nombre en plural de cada tipo para la pantalla. */
    public const NOMBRES = [
        'llave' => 'Llaves',
        'gafete' => 'Gafetes',
        'equipo' => 'Equipos de seguridad',
        'equipo_pc' => 'Equipos de Protección Civil',
        'vehiculo' => 'Vehículos',
        'colaborador' => 'Colaboradores',
        'lost_found' => 'Lost & Found',
        'procedimiento' => 'Procedimientos',
    ];

    /** Ícono de cada tipo. */
    public const ICONOS = [
        'llave' => 'bi-key-fill', 'gafete' => 'bi-person-vcard', 'equipo' => 'bi-tools', 'equipo_pc' => 'bi-fire',
        'vehiculo' => 'bi-car-front-fill', 'colaborador' => 'bi-person-badge', 'lost_found' => 'bi-box-seam', 'procedimiento' => 'bi-book',
    ];

    /** Relaciones que usa resumenLector() de cada tipo (sin N+1). */
    private const CARGAR = [
        'llave' => ['sede:id,nombre'],
        'gafete' => ['tipo:id,nombre', 'sede:id,nombre'],
        'equipo' => ['tipo:id,nombre', 'sede:id,nombre'],
        'equipo_pc' => ['sede:id,nombre', 'espacio:id,nombre,ruta'],
        'colaborador' => ['puesto:id,nombre'],
        'lost_found' => ['sede:id,nombre'],
    ];

    /**
     * Tamaños de etiqueta: clave => [nombre, ancho mm, alto mm, descripción].
     */
    public const TAMANOS = [
        'llavero' => ['Llavero pequeño', 40, 25, '40 × 25 mm · para llaveros y micas chicas'],
        'etiqueta' => ['Etiqueta 50 × 25 mm', 50, 25, 'La de las impresoras de etiquetas más comunes'],
        'gafete' => ['Gafete', 86, 54, '86 × 54 mm · tamaño credencial'],
        'calcomania' => ['Calcomanía vehicular', 100, 70, '100 × 70 mm · para el parabrisas'],
    ];

    /** @var array<string, string> */
    private array $acciones = [];

    public function __construct(
        private readonly Lector $lector,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * Tipos que el usuario puede imprimir: tipo => [nombre, ícono, permisos].
     *
     * @return array<string, array{nombre: string, icono: string, permisos: list<string>}>
     */
    public function tipos(User $actor): array
    {
        $tipos = [];
        foreach ($this->lector->tipos() as $tipo => $clase) {
            $permisos = $this->permisos($clase);
            if (collect($permisos)->every(fn ($p) => $actor->can($p))) {
                $tipos[$tipo] = [
                    'nombre' => self::NOMBRES[$tipo] ?? Str::headline($tipo),
                    'icono' => self::ICONOS[$tipo] ?? 'bi-qr-code',
                    'permisos' => $permisos,
                ];
            }
        }

        return $tipos;
    }

    /**
     * Registros para la lista, ya filtrados.
     *
     * @param  array{tipo: ?string, sede: ?int, estado: string, q: string}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    public function lista(User $actor, array $filtros): Collection
    {
        $tipos = $this->tipos($actor);
        if ($filtros['tipo'] !== null) {
            $tipos = array_intersect_key($tipos, [$filtros['tipo'] => true]);
        }
        $texto = mb_strtolower($filtros['q']);

        $filas = collect();
        foreach ($tipos as $tipo => $info) {
            $consulta = $this->consulta($actor, $tipo, $info['permisos']);
            if ($filtros['sede'] !== null) {
                if (! $this->tieneSede($tipo)) {
                    continue; // vehículos y procedimientos son de toda la empresa
                }
                $consulta->where($consulta->getModel()->qualifyColumn('sede_id'), $filtros['sede']);
            }
            foreach ($consulta->limit(self::POR_TIPO)->get() as $registro) {
                $fila = $this->fila($tipo, $info, $registro);
                if ($filtros['estado'] === 'activos' && ! $fila['activo']) {
                    continue;
                }
                if ($texto !== '' && ! str_contains(mb_strtolower($fila['titulo'].' '.$fila['detalle'].' '.$fila['codigo']), $texto)) {
                    continue;
                }
                $filas->push($fila);
            }
        }

        return $filas->values();
    }

    /**
     * Lo marcado para imprimir ("llave-12", "vehiculo-3"…), en ese orden y
     * solo lo que el usuario puede imprimir. Con el QR ya dibujado.
     *
     * @param  list<string>  $seleccion
     * @return Collection<int, array<string, mixed>>
     */
    public function paraImprimir(User $actor, array $seleccion): Collection
    {
        $tipos = $this->tipos($actor);
        $pedidos = [];
        foreach (array_slice(array_values(array_unique($seleccion)), 0, self::MAXIMO) as $clave) {
            if (preg_match('/^([a-z_]+)-(\d{1,10})$/', $clave, $m) && isset($tipos[$m[1]])) {
                $pedidos[$m[1]][] = (int) $m[2];
            }
        }

        $encontrados = [];
        foreach ($pedidos as $tipo => $ids) {
            $consulta = $this->consulta($actor, $tipo, $tipos[$tipo]['permisos']);
            foreach ($consulta->whereKey($ids)->get() as $registro) {
                $encontrados[$tipo.'-'.$registro->getKey()] = $this->fila($tipo, $tipos[$tipo], $registro);
            }
        }

        $escritor = new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd));

        return collect($seleccion)->unique()->filter(fn ($c) => isset($encontrados[$c]))->take(self::MAXIMO)
            ->map(fn ($c) => $encontrados[$c] + [
                'qr' => (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $escritor->writeString(route('lector.ir', $encontrados[$c]['codigo_qr']))),
            ])->values();
    }

    /**
     * @param  class-string<Model&Identificable>  $clase
     * @return list<string>
     */
    public function permisos(string $clase): array
    {
        $ver = $clase::permisoLector();
        $modulo = Str::before($ver, '.');

        return [$ver, $modulo.'.'.$this->accionImprimir($modulo)];
    }

    /** «imprimir» si el módulo la tiene; si no (Colaboradores, Procedimientos), «editar». */
    private function accionImprimir(string $modulo): string
    {
        return $this->acciones[$modulo] ??= DB::table('modulo_acciones as ma')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', $modulo)->where('a.clave', 'imprimir')->exists() ? 'imprimir' : 'editar';
    }

    /**
     * Registros del tipo que el usuario alcanza con todos los permisos.
     *
     * @param  list<string>  $permisos
     * @return Builder<Model>
     */
    private function consulta(User $actor, string $tipo, array $permisos): Builder
    {
        /** @var class-string<Model&Identificable> $clase */
        $clase = $this->lector->tipos()[$tipo];
        $consulta = $clase::query()->with(self::CARGAR[$tipo] ?? [])->whereNotNull((new $clase)->qualifyColumn('codigo_qr'))->orderBy((new $clase)->qualifyColumn((new $clase)->getKeyName()));
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $modelo = $consulta->getModel();
        foreach ($permisos as $permiso) {
            $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
            if ($sedes !== null && $this->tieneSede($tipo)) {
                // Como en «Código e identificación»: lo que no es de una sede (corporativo) se ve
                $consulta->where(fn ($q) => $q->whereIn($modelo->qualifyColumn('sede_id'), $sedes)->orWhereNull($modelo->qualifyColumn('sede_id')));
            }
            $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
            if ($efectivo?->alcance === Alcance::Propios && Schema::hasColumn($modelo->getTable(), 'creado_por')) {
                $consulta->where($modelo->qualifyColumn('creado_por'), $actor->id);
            }
        }

        return $consulta;
    }

    private function tieneSede(string $tipo): bool
    {
        $clase = $this->lector->tipos()[$tipo];

        return Schema::hasColumn((new $clase)->getTable(), 'sede_id');
    }

    /**
     * @param  array{nombre: string, icono: string, permisos: list<string>}  $info
     * @return array<string, mixed>
     */
    private function fila(string $tipo, array $info, Model $registro): array
    {
        /** @var Model&Identificable $registro */
        $resumen = $registro->resumenLector();
        $codigo = (string) $registro->getAttribute('codigo_qr');

        return [
            'clave' => $tipo.'-'.$registro->getKey(),
            'tipo' => $tipo,
            'tipo_nombre' => $info['nombre'],
            'icono' => $info['icono'],
            'titulo' => (string) $resumen['titulo'],
            'detalle' => (string) ($resumen['detalle'] ?? ''),
            'activo' => (bool) $resumen['activo'],
            'sede_id' => $resumen['sede_id'] ?? null,
            'codigo_qr' => $codigo,
            'codigo' => trim(chunk_split($codigo, 4, ' ')),
        ];
    }
}
