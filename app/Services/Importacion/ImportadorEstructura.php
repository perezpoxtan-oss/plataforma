<?php

namespace App\Services\Importacion;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\Limites;
use App\Support\Espacios\Etiquetas;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * «Importar estructura» (docs/tecnico/importar-estructura.md): la estructura
 * física completa de una empresa desde un solo archivo jerárquico, un
 * renglón por lugar:
 *
 *   Sede → Zona o edificio → Piso → Habitación (Oficina, Vivienda, Casa)
 *        → Área de la habitación
 *
 * Es el mismo árbol de Zonas y edificios (Espacio::PADRES); no hay modelo
 * nuevo: el «área dentro de la habitación» es el nivel `subarea`.
 *
 *  - Lo que no existe se crea (sede, zona, piso, habitación, área y tipo de
 *    espacio); lo que ya existe (mismo nombre, sin importar mayúsculas,
 *    acentos ni espacios de más, dentro del mismo lugar) se reutiliza.
 *  - Nunca borra, da de baja ni cambia nada: subir el mismo archivo dos
 *    veces no crea nada nuevo.
 *  - Las sedes pasan por las reglas del alta de Sedes (código único,
 *    ciudad, estado, límite del plan) y los lugares por AdministradorEspacios.
 *  - Todo o nada: con un error en cualquier renglón no se guarda nada
 *    (AdministradorImportaciones::procesar con todoONada()).
 */
class ImportadorEstructura extends Importador
{
    public const MAX_FILAS = 5000;

    /** Giros donde el nivel «habitación» puede ser vivienda o área común. */
    public const RESIDENCIALES = ['condominio', 'fraccionamiento'];

    /** @var array<string, int> lo que se crea (o se crearía) en esta corrida */
    private array $conteo = ['sedes' => 0, 'zonas' => 0, 'pisos' => 0, 'especificas' => 0, 'areas' => 0, 'tipos' => 0];

    private string $detalle = '';

    /** @var array<int, Sede>|null */
    private ?array $sedes = null;

    /** @var array<string, array<string, Espacio>> "sede|padre" => nombre normalizado => hijo */
    private array $hijos = [];

    /** @var array<string, array<string, TipoEspacio>> nivel => nombre normalizado => tipo */
    private array $tipos = [];

    private ?Empresa $empresa = null;

    public function __construct(
        private readonly AdministradorEspacios $espacios,
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Limites $limites,
    ) {}

    public function tipo(): string
    {
        return 'estructura';
    }

    public function modulo(): string
    {
        return 'sedes';
    }

    public function titulo(): string
    {
        return 'Estructura';
    }

    public function registro(): string
    {
        return 'lugar';
    }

    public function rutaLista(): string
    {
        return route('espacios.index');
    }

    public function actualizable(): bool
    {
        return false;
    }

    public function maxFilas(): int
    {
        return self::MAX_FILAS;
    }

    public function todoONada(): bool
    {
        return true;
    }

    public function plantillaXlsx(): bool
    {
        return true;
    }

    public function exportable(): bool
    {
        return true;
    }

    public function puedeCrear(User $actor): bool
    {
        return $actor->can('sedes.crear') || $actor->can('espacios.crear');
    }

    public function detalleCreado(): string
    {
        return $this->detalle;
    }

    public function resumenExtra(): array
    {
        return ['crea' => $this->conteo];
    }

    public function nombreClave(): string
    {
        return 'Sede + Zona o edificio + Piso + '.$this->especifica().' + '.$this->tituloArea();
    }

    public function columnas(): array
    {
        $especifica = $this->especifica();
        $minuscula = mb_strtolower($especifica);
        $todasEspecificas = ['habitacion', 'oficina', 'vivienda', 'casa', 'departamento', 'area especifica', 'espacio'];

        $columnas = [
            new Columna('sede', 'Sede', true, 'Hotel Centro', 'Nombre o código de la sede. Si no existe, se da de alta (llena también su ciudad y su estado).',
                alias: ['nombre de la sede']),
            new Columna('sede_codigo', 'Código de sede', false, 'HCEN', 'Solo para una sede nueva: letras, números y guiones, hasta 10. Si lo dejas vacío, lo armamos con su nombre.',
                alias: ['codigo sede', 'clave de sede']),
            new Columna('ciudad', 'Ciudad', false, 'Cancún', 'Obligatoria solo cuando la sede es nueva.'),
            new Columna('entidad', 'Estado', false, 'Quintana Roo', 'Obligatorio solo cuando la sede es nueva (el estado de la república).', alias: ['entidad', 'estado de la republica']),
            new Columna('zona', 'Zona o edificio', false, 'Torre A', 'Vacío si el renglón es solo la sede.', alias: ['zona', 'edificio', 'zona edificio', 'zona nivel 1']),
            new Columna('piso', 'Piso', false, 'Piso 1', 'Vacío si la '.$minuscula.' cuelga directo de la zona.', alias: ['nivel', 'zona nivel 2']),
            new Columna('especifica', $especifica, false, '101', 'Vacío si solo quieres crear la zona o el piso.',
                alias: [...$todasEspecificas, 'espacio'], campos: ['nombre', 'nivel', 'padre_id', 'area_especifica']),
            new Columna('tipo_especifica', 'Tipo de '.$minuscula, false, $this->residencial() ? 'Departamento' : 'Suite',
                'Opcional. Si el tipo no existe en tu lista, se agrega.',
                alias: array_map(fn ($p) => 'tipo de '.$p, $todasEspecificas), campos: ['tipo_espacio_id']),
        ];
        if ($this->residencial()) {
            $columnas[] = new Columna('area_comun', 'Área común', false, 'No', 'Escribe «Sí» si es alberca, salón o casa club; vacío o «No» si es una '.$minuscula.'.',
                alias: ['es area comun']);
        }

        return [
            ...$columnas,
            new Columna('area', $this->tituloArea(), false, 'Terraza', 'Una parte de la '.$minuscula.' (Baño, Terraza, Recámara…). Vacío si no aplica.',
                alias: [...array_map(fn ($p) => 'area de la '.$p, $todasEspecificas), ...array_map(fn ($p) => 'area dentro de la '.$p, $todasEspecificas), 'area', 'area dentro del espacio']),
            new Columna('tipo_area', 'Tipo de área', false, 'Terraza', 'Opcional. Si el tipo no existe en tu lista, se agrega.'),
            new Columna('codigo', 'Código', false, 'T1-101', 'Opcional: clave o número del último lugar del renglón (hasta 20).', alias: ['clave', 'numero', 'clave numero']),
        ];
    }

    public function clave(array $fila): ?string
    {
        $partes = array_map(fn ($c) => self::normal((string) $this->valor($fila, $c)), ['sede', 'zona', 'piso', 'especifica', 'area']);

        return $partes[0] === '' ? null : implode('|', $partes);
    }

    public function existente(array $fila): ?Model
    {
        $this->validar($fila);
        $sede = $this->sedeExistente($fila);
        if ($sede === null) {
            return null;
        }
        $nodo = null;
        foreach ($this->camino($fila) as [$nivel, $nombre]) {
            $nodo = $this->hijo($sede, $nodo, $nombre);
            if ($nodo === null) {
                return null;
            }
        }

        return $nodo ?? $sede;
    }

    public function describir(Model $registro): string
    {
        if ($registro instanceof Sede) {
            return 'sede «'.$registro->nombre.'»'.($registro->activo ? '' : ' (dada de baja)');
        }
        /** @var Espacio $registro */
        $nombres = Espacio::whereIn('id', $registro->idsAncestros())->pluck('nombre', 'id');
        $ruta = collect($registro->idsAncestros())->map(fn ($id) => $nombres[$id] ?? null)->filter()->push($registro->nombre)->implode(' › ');
        $sede = $this->sedesPorId()[(int) $registro->sede_id]->nombre ?? '';

        return ($sede !== '' ? $sede.' › ' : '').$ruta.($registro->activo ? '' : ' (desactivado)');
    }

    public function editable(User $actor, Model $existente): bool
    {
        return false;
    }

    public function crear(User $actor, int $empresaId, array $fila): Model
    {
        $this->validar($fila);
        $this->detalle = '';
        $hechos = [];
        $conteo = $this->conteo;
        $cache = [$this->sedes, $this->hijos, $this->tipos];

        try {
            $sede = $this->sedeExistente($fila);
            if ($sede === null) {
                $sede = $this->crearSede($actor, $empresaId, $fila);
                $conteo['sedes']++;
                $hechos[] = 'la sede «'.$sede->nombre.'»';
            } elseif (! $sede->activo) {
                throw ValidationException::withMessages(['sede' => "La sede «{$sede->nombre}» está dada de baja: reactívala en Estructura → Sedes y vuelve a subir el archivo."]);
            }

            $camino = $this->camino($fila);
            $ultimo = array_key_last($camino);
            $nodo = null;
            foreach ($camino as $i => [$nivel, $nombre, $columna]) {
                $existente = $this->hijo($sede, $nodo, $nombre);
                if ($existente !== null) {
                    $nodo = $existente;

                    continue;
                }
                $this->exigirPermisoLugares($actor, $sede);
                $datos = ['nombre' => $nombre, 'codigo' => $i === $ultimo ? $this->valor($fila, 'codigo') : null];
                if ($nivel === Espacio::AREA_ESPECIFICA) {
                    $datos['tipo_espacio_id'] = $this->tipoId($actor, $empresaId, $nivel, $this->valor($fila, 'tipo_especifica'), 'tipo_especifica', $conteo);
                    if ($this->residencial() && $this->valor($fila, 'area_comun') !== null) {
                        $datos['area_comun'] = $this->siNo((string) $this->valor($fila, 'area_comun'));
                    }
                } elseif ($nivel === Espacio::SUBAREA) {
                    $datos['tipo_espacio_id'] = $this->tipoId($actor, $empresaId, $nivel, $this->valor($fila, 'tipo_area'), 'tipo_area', $conteo);
                }
                try {
                    $nodo = $this->espacios->crear($actor, $sede, $nodo, $nivel, $datos);
                } catch (ValidationException $e) {
                    // El error es de la celda de este nivel (lugar desactivado, nombre repetido…)
                    throw ValidationException::withMessages([$columna => (string) collect($e->errors())->flatten()->first()]);
                }
                $this->hijos[$this->llave($sede, $nodo->padre_id)][self::normal($nombre)] = $nodo;
                $conteo[self::CONTEO[$nivel]]++;
                $hechos[] = $nombre;
            }
        } catch (\Throwable $e) {
            // La fila se deshace: lo que se guardó en memoria también
            [$this->sedes, $this->hijos, $this->tipos] = $cache;

            throw $e;
        }

        $this->conteo = $conteo;
        $this->detalle = $hechos === [] ? '' : 'Se crea: '.implode(' › ', $hechos).'.';

        return $nodo ?? $sede;
    }

    public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model
    {
        return $existente;
    }

    // --------------------------------------------------------------- Exportar

    /**
     * La estructura actual (solo lo activo) con las mismas columnas de la
     * plantilla: un renglón por lugar, de lo general a lo particular. Sirve
     * de respaldo y para editarla y volver a subirla. Se llama con la
     * empresa fijada en el Tenant.
     *
     * @return list<list<string>>
     */
    public function exportar(): array
    {
        $columnas = $this->columnas();
        $renglones = [array_map(fn (Columna $c) => $c->encabezado(), $columnas)];
        $empresaId = (int) app(Tenant::class)->empresaId();
        $tipos = TipoEspacio::query()->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresaId))->pluck('nombre', 'id');

        foreach (Sede::query()->where('activo', true)->orderBy('nombre')->get() as $sede) {
            $base = ['sede' => $sede->nombre, 'sede_codigo' => (string) $sede->codigo, 'ciudad' => (string) $sede->ciudad, 'entidad' => (string) $sede->entidad];
            $nodos = Espacio::query()->where('sede_id', $sede->id)->where('activo', true)
                ->whereIn('nivel', array_keys(self::CONTEO))
                ->orderBy('profundidad')->orderBy('orden')->orderBy('nombre')->get();
            $porId = $nodos->keyBy('id');
            $filas = [];
            foreach ($nodos as $nodo) {
                // Solo si toda su ruta está activa y es de los niveles que se importan
                $ruta = [];
                foreach ([...$nodo->idsAncestros(), $nodo->id] as $id) {
                    if (! isset($porId[$id])) {
                        continue 2;
                    }
                    $ruta[] = $porId[$id];
                }
                $valores = $base;
                foreach ($ruta as $paso) {
                    $valores[self::COLUMNA[$paso->nivel]] = $paso->nombre;
                }
                if ($nodo->nivel === Espacio::AREA_ESPECIFICA) {
                    $valores['tipo_especifica'] = (string) ($tipos[$nodo->tipo_espacio_id] ?? '');
                    if ($this->residencial()) {
                        $valores['area_comun'] = $nodo->esAreaComun() ? 'Sí' : '';
                    }
                } elseif ($nodo->nivel === Espacio::SUBAREA) {
                    $valores['tipo_area'] = (string) ($tipos[$nodo->tipo_espacio_id] ?? '');
                }
                $valores['codigo'] = (string) $nodo->codigo;
                $filas[] = [implode("\x1F", array_map(fn ($p) => mb_strtolower($p->nombre), $ruta)), $valores];
            }
            if ($filas === []) {
                $renglones[] = array_map(fn (Columna $c) => $base[$c->clave] ?? '', $columnas);

                continue;
            }
            // Orden natural por ruta: el padre siempre antes que sus hijos (101, 102… 110)
            usort($filas, fn ($a, $b) => strnatcasecmp($a[0], $b[0]));
            foreach ($filas as [, $valores]) {
                $renglones[] = array_map(fn (Columna $c) => $valores[$c->clave] ?? '', $columnas);
            }
        }

        return $renglones;
    }

    /**
     * Ejemplos ficticios para la plantilla según el giro (hotel, corporativo
     * o residencial).
     *
     * @return list<array<string, string>>
     */
    public function ejemplos(): array
    {
        $clave = $this->empresa()?->rubro?->clave;
        $sede = ['ciudad' => 'Ciudad Ejemplo', 'entidad' => 'Jalisco'];

        return match (true) {
            $clave === 'corporativo' => [
                ['sede' => 'Planta Norte', 'sede_codigo' => 'PNOR'] + $sede,
                ['sede' => 'Planta Norte', 'zona' => 'Edificio Administrativo'],
                ['sede' => 'Planta Norte', 'zona' => 'Edificio Administrativo', 'piso' => 'Piso 1', 'especifica' => 'Recursos Humanos', 'tipo_especifica' => 'Oficina', 'codigo' => 'ADM-101'],
                ['sede' => 'Planta Norte', 'zona' => 'Edificio Administrativo', 'piso' => 'Piso 1', 'especifica' => 'Sala de juntas', 'tipo_especifica' => 'Sala de juntas'],
                ['sede' => 'Planta Norte', 'zona' => 'Edificio Administrativo', 'piso' => 'Piso 1', 'especifica' => 'Recursos Humanos', 'area' => 'Archivo', 'tipo_area' => 'Archivo'],
                ['sede' => 'Planta Norte', 'zona' => 'Nave 1', 'especifica' => 'Almacén', 'tipo_especifica' => 'Bodega'],
            ],
            in_array($clave, self::RESIDENCIALES, true) => [
                ['sede' => 'Residencial Los Pinos', 'sede_codigo' => 'RPIN'] + $sede,
                ['sede' => 'Residencial Los Pinos', 'zona' => 'Torre 1', 'piso' => 'Piso 1', 'especifica' => '101', 'tipo_especifica' => 'Departamento', 'codigo' => 'T1-101'],
                ['sede' => 'Residencial Los Pinos', 'zona' => 'Torre 1', 'piso' => 'Piso 1', 'especifica' => '102', 'tipo_especifica' => 'Departamento', 'codigo' => 'T1-102'],
                ['sede' => 'Residencial Los Pinos', 'zona' => 'Torre 1', 'piso' => 'Piso 1', 'especifica' => '101', 'area' => 'Terraza', 'tipo_area' => 'Terraza'],
                ['sede' => 'Residencial Los Pinos', 'zona' => 'Áreas comunes', 'especifica' => 'Alberca', 'area_comun' => 'Sí'],
                ['sede' => 'Residencial Los Pinos', 'zona' => 'Áreas comunes', 'especifica' => 'Salón de eventos', 'area_comun' => 'Sí'],
            ],
            default => [
                ['sede' => 'Hotel Centro', 'sede_codigo' => 'HCEN'] + $sede,
                ['sede' => 'Hotel Centro', 'zona' => 'Torre A', 'piso' => 'Piso 1', 'especifica' => '101', 'tipo_especifica' => 'Habitación', 'codigo' => 'TA-101'],
                ['sede' => 'Hotel Centro', 'zona' => 'Torre A', 'piso' => 'Piso 1', 'especifica' => '102', 'tipo_especifica' => 'Suite', 'codigo' => 'TA-102'],
                ['sede' => 'Hotel Centro', 'zona' => 'Torre A', 'piso' => 'Piso 1', 'especifica' => '102', 'area' => 'Terraza', 'tipo_area' => 'Terraza'],
                ['sede' => 'Hotel Centro', 'zona' => 'Torre A', 'piso' => 'Piso 1', 'especifica' => '102', 'area' => 'Baño', 'tipo_area' => 'Baño'],
                ['sede' => 'Hotel Centro', 'zona' => 'Zona exterior', 'especifica' => 'Alberca principal', 'tipo_especifica' => 'Alberca'],
            ],
        };
    }

    /**
     * Tipos que ya tiene la empresa por nivel (para la hoja de instrucciones).
     *
     * @return array{especifica: list<string>, area: list<string>}
     */
    public function tiposDisponibles(): array
    {
        $empresaId = (int) app(Tenant::class)->empresaId();

        return [
            'especifica' => TipoEspacio::disponiblesPara($empresaId, Espacio::AREA_ESPECIFICA)->pluck('nombre')->all(),
            'area' => TipoEspacio::disponiblesPara($empresaId, Espacio::SUBAREA)->pluck('nombre')->all(),
        ];
    }

    public function especifica(): string
    {
        return Etiquetas::para($this->empresa())[Espacio::AREA_ESPECIFICA]['singular'];
    }

    public function tituloArea(): string
    {
        return 'Área de la '.mb_strtolower($this->especifica());
    }

    public function residencial(): bool
    {
        return in_array($this->empresa()?->rubro?->clave, self::RESIDENCIALES, true);
    }

    // ------------------------------------------------------------------ Apoyo

    /** Nivel del árbol => contador del resumen. */
    private const CONTEO = [
        Espacio::EDIFICIO => 'zonas',
        Espacio::AREA => 'pisos',
        Espacio::AREA_ESPECIFICA => 'especificas',
        Espacio::SUBAREA => 'areas',
    ];

    /** Nivel del árbol => columna del archivo. */
    private const COLUMNA = [
        Espacio::EDIFICIO => 'zona',
        Espacio::AREA => 'piso',
        Espacio::AREA_ESPECIFICA => 'especifica',
        Espacio::SUBAREA => 'area',
    ];

    /** Largo máximo de cada columna (el de su campo en la base). */
    private const LARGOS = [
        'sede' => 150, 'sede_codigo' => 10, 'ciudad' => 100, 'entidad' => 100,
        'zona' => 100, 'piso' => 100, 'especifica' => 100, 'area' => 100,
        'tipo_especifica' => 60, 'tipo_area' => 60, 'codigo' => 20,
    ];

    /**
     * Mismo nombre «normalizado»: sin mayúsculas, acentos ni espacios de más.
     */
    public static function normal(string $texto): string
    {
        $texto = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto)));

        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }

    private function empresa(): ?Empresa
    {
        $id = app(Tenant::class)->empresaId();
        if ($id === null) {
            return null;
        }
        if ($this->empresa?->id !== $id) {
            $this->empresa = Empresa::with('rubro')->find($id);
        }

        return $this->empresa;
    }

    /**
     * Revisa la fila completa y junta TODOS sus errores: largos, niveles
     * vacíos en medio, tipos sin su lugar y «Área común» que no es Sí/No.
     */
    private function validar(array $fila): void
    {
        $errores = [];
        if ($this->valor($fila, 'sede') === null) {
            $errores['sede'] = ['Escribe la sede.'];
        }
        $titulos = collect($this->columnas())->mapWithKeys(fn (Columna $c) => [$c->clave => $c->titulo]);
        foreach (self::LARGOS as $columna => $largo) {
            $valor = $this->valor($fila, $columna);
            if ($valor !== null && mb_strlen($valor) > $largo) {
                $errores[$columna] = ["«{$titulos[$columna]}» admite hasta {$largo} caracteres y trae ".mb_strlen($valor).': acórtalo.'];
            }
        }
        $codigoSede = $this->valor($fila, 'sede_codigo');
        if ($codigoSede !== null && ! isset($errores['sede_codigo']) && preg_match('/^[A-Za-z0-9-]+$/', $codigoSede) !== 1) {
            $errores['sede_codigo'] = ['El código de sede solo puede tener letras, números y guiones (sin espacios ni acentos).'];
        }

        $especifica = mb_strtolower($this->especifica());
        $vacioEnMedio = [
            'piso' => ['zona', 'Hay piso pero falta la zona o edificio: escribe en qué zona está.'],
            'especifica' => ['zona', "Hay {$especifica} pero falta la zona o edificio: escribe en qué zona está (una {$especifica} no cuelga directo de la sede)."],
            'area' => ['especifica', "Hay área pero falta la {$especifica}: escribe de qué {$especifica} es."],
            'tipo_especifica' => ['especifica', "Hay tipo de {$especifica} pero falta la {$especifica}."],
            'tipo_area' => ['area', 'Hay tipo de área pero falta el área.'],
            'area_comun' => ['especifica', "«Área común» va con una {$especifica}: escribe su nombre."],
        ];
        foreach ($vacioEnMedio as $columna => [$requiere, $mensaje]) {
            if ($this->valor($fila, $columna) !== null && $this->valor($fila, $requiere) === null) {
                $errores[$columna] ??= [$mensaje];
            }
        }
        if ($this->valor($fila, 'codigo') !== null && $this->valor($fila, 'zona') === null) {
            $errores['codigo'] ??= ['El código es del último lugar del renglón: para la sede usa «Código de sede».'];
        }
        if ($this->residencial() && ($comun = $this->valor($fila, 'area_comun')) !== null && $this->siNo($comun) === null) {
            $errores['area_comun'] ??= ["«{$comun}» no se entiende en «Área común»: escribe Sí o No."];
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    private function siNo(string $texto): ?bool
    {
        return match (Columna::normalizar($texto)) {
            'si', 's', 'x', '1', 'verdadero', 'true' => true,
            'no', 'n', '0', 'falso', 'false' => false,
            default => null,
        };
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [nivel, nombre, columna] de arriba abajo
     */
    private function camino(array $fila): array
    {
        $camino = [];
        foreach (self::COLUMNA as $nivel => $columna) {
            if (($nombre = $this->valor($fila, $columna)) !== null) {
                $camino[] = [$nivel, trim((string) preg_replace('/\s+/u', ' ', $nombre)), $columna];
            }
        }

        return $camino;
    }

    /** @return array<int, Sede> */
    private function sedesPorId(): array
    {
        if ($this->sedes === null) {
            $this->sedes = Sede::query()->orderBy('id')->get()->keyBy('id')->all();
        }

        return $this->sedes;
    }

    /**
     * La sede de la fila por nombre o código; si la columna «Código de
     * sede» no coincide con la que ya existe, es error (para no mezclarlas).
     */
    private function sedeExistente(array $fila): ?Sede
    {
        $texto = (string) $this->valor($fila, 'sede');
        $nombre = self::normal($texto);
        $codigo = Columna::normalizar($texto);
        $sede = collect($this->sedesPorId())->first(fn (Sede $s) => self::normal($s->nombre) === $nombre)
            ?? collect($this->sedesPorId())->first(fn (Sede $s) => $codigo !== '' && Columna::normalizar((string) $s->codigo) === $codigo);

        $columnaCodigo = $this->valor($fila, 'sede_codigo');
        if ($sede !== null && $columnaCodigo !== null && Columna::normalizar($columnaCodigo) !== Columna::normalizar((string) $sede->codigo)) {
            throw ValidationException::withMessages(['sede_codigo' => "La sede «{$sede->nombre}» ya existe con el código «{$sede->codigo}»: deja vacía esta columna o escribe «{$sede->codigo}»."]);
        }

        return $sede;
    }

    /**
     * Alta de la sede con las reglas de Estructura → Sedes: nombre, código
     * único en la empresa, ciudad, estado y el límite de sedes del plan.
     */
    private function crearSede(User $actor, int $empresaId, array $fila): Sede
    {
        $nombre = (string) $this->valor($fila, 'sede');
        if (! $actor->can('sedes.crear')) {
            throw ValidationException::withMessages(['sede' => "No existe la sede «{$nombre}» y tu rol no puede dar de alta sedes: pide a tu administrador que la registre o el permiso «Crear» de Sedes."]);
        }

        $errores = [];
        foreach (['ciudad' => 'su ciudad', 'entidad' => 'su estado'] as $columna => $que) {
            if ($this->valor($fila, $columna) === null) {
                $errores[$columna] = ["La sede «{$nombre}» es nueva: escribe {$que} para darla de alta."];
            }
        }
        $codigo = $this->valor($fila, 'sede_codigo');
        $codigo = $codigo === null ? $this->codigoLibre($nombre) : mb_strtoupper($codigo);
        $ocupada = collect($this->sedesPorId())->first(fn (Sede $s) => mb_strtoupper((string) $s->codigo) === $codigo);
        if ($ocupada !== null) {
            $errores['sede_codigo'] = ["El código «{$codigo}» ya es de la sede «{$ocupada->nombre}»: usa otro."];
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        try {
            $this->limites->exigirSede($empresaId);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['sede' => (string) collect($e->errors())->flatten()->first()]);
        }

        $sede = Sede::create([
            'empresa_id' => $empresaId,
            'nombre' => trim((string) preg_replace('/\s+/u', ' ', $nombre)),
            'codigo' => $codigo,
            'ciudad' => $this->valor($fila, 'ciudad'),
            'entidad' => $this->valor($fila, 'entidad'),
        ]);
        $this->auditoria->auditar($actor, 'sedes.creada', $sede, null, $sede->only(['codigo', 'nombre', 'ciudad', 'entidad', 'activo']) + ['importada' => true]);
        $this->sedes[(int) $sede->id] = $sede;

        return $sede;
    }

    /** «Hotel Centro» → «HOTELCENTR» (o «HOTELCEN-2» si ya está ocupado). */
    private function codigoLibre(string $nombre): string
    {
        $base = mb_strtoupper(mb_substr(Columna::normalizar($nombre), 0, 10)) ?: 'SEDE';
        $usados = collect($this->sedesPorId())->map(fn (Sede $s) => mb_strtoupper((string) $s->codigo))->all();
        $codigo = $base;
        for ($n = 2; in_array($codigo, $usados, true); $n++) {
            $codigo = mb_substr($base, 0, 10 - strlen('-'.$n)).'-'.$n;
        }

        return $codigo;
    }

    private function exigirPermisoLugares(User $actor, Sede $sede): void
    {
        $permitidas = $this->autorizador->sedesPermitidas($actor, 'espacios.crear');
        if (! $actor->can('espacios.crear') || ($permitidas !== null && ! in_array((int) $sede->id, $permitidas, true))) {
            throw ValidationException::withMessages(['zona' => $actor->can('espacios.crear')
                ? "No puedes crear zonas en «{$sede->nombre}»: solo en tus sedes."
                : 'Tu rol no puede crear zonas ni '.mb_strtolower(Etiquetas::plural($this->especifica())).': pide el permiso «Crear» de Zonas y edificios.']);
        }
    }

    private function llave(Sede $sede, ?int $padreId): string
    {
        return $sede->id.'|'.($padreId ?? 0);
    }

    /** Hijo con ese nombre normalizado en ese lugar de la sede (los hijos de cada lugar se leen una vez). */
    private function hijo(Sede $sede, ?Espacio $padre, string $nombre): ?Espacio
    {
        $llave = $this->llave($sede, $padre?->id);
        if (! isset($this->hijos[$llave])) {
            $this->hijos[$llave] = [];
            $hijos = Espacio::query()->where('sede_id', $sede->id)
                ->when($padre === null, fn ($q) => $q->whereNull('padre_id'), fn ($q) => $q->where('padre_id', $padre->id))
                ->orderBy('id')->get();
            foreach ($hijos as $hijo) {
                $this->hijos[$llave][self::normal($hijo->nombre)] ??= $hijo;
            }
        }

        return $this->hijos[$llave][self::normal($nombre)] ?? null;
    }

    /**
     * Tipo de espacio por nombre (los del sistema y los de la empresa); si no
     * existe se agrega como tipo propio de la empresa.
     *
     * @param  array<string, int>  $conteo
     */
    private function tipoId(User $actor, int $empresaId, string $nivel, ?string $nombre, string $columna, array &$conteo): ?int
    {
        if ($nombre === null) {
            return null;
        }
        if (! isset($this->tipos[$nivel])) {
            $this->tipos[$nivel] = [];
            foreach (TipoEspacio::query()->where('nivel', $nivel)->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresaId))
                ->orderByRaw('empresa_id IS NULL DESC')->orderBy('id')->get() as $tipo) {
                $this->tipos[$nivel][self::normal($tipo->nombre)] ??= $tipo;
            }
        }
        $existente = $this->tipos[$nivel][self::normal($nombre)] ?? null;
        if ($existente !== null) {
            return (int) $existente->id;
        }
        if (! $actor->can('espacios.crear')) {
            throw ValidationException::withMessages([$columna => "El tipo «{$nombre}» no está en tu lista y tu rol no puede agregar tipos."]);
        }
        $tipo = $this->espacios->crearTipo($actor, $empresaId, $nivel, $nombre);
        $this->tipos[$nivel][self::normal($nombre)] = $tipo;
        $conteo['tipos']++;

        return (int) $tipo->id;
    }
}
