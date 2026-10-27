<?php

namespace App\Services\PasesSalida;

use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\PaseSalida;
use App\Models\PaseSalidaAprobacion;
use App\Models\PaseSalidaPaso;
use App\Models\Rol;
use App\Models\User;
use App\Services\Autorizaciones\CadenaDelegaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Relojes\AjustesRelojes;
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
 * definido por el jefe del departamento del solicitante, un rol, usuarios
 * específicos o "quien tenga Aprobar", con o sin filtro de departamento,
 * obligatorio u opcional, para todos los motivos o solo algunos. Sin
 * configuración se usa el circuito predeterminado (PREDETERMINADO).
 *
 * Paso "Jefe del departamento del solicitante" (tipo jefe_departamento,
 * decisión del dueño 2026-10-23): lo firman los responsables de
 * Estructura → Departamentos del departamento del colaborador solicitante en
 * la sede de origen (titular; si no hay, sus suplentes; con su delegado de
 * «No molestar», como las autorizaciones de visitas). Si ninguno puede
 * firmar, quien tenga "Aprobar" y sea de ese departamento (la regla anterior
 * del paso «Jefe de Departamento»); si tampoco, la regla general: cualquiera
 * con "Aprobar" en la sede. Ver jefesDelDepartamento().
 *
 * Quién firma un paso (puedeAprobar):
 *  1. tiene "pases_salida.aprobar" con alcance en la sede de ORIGEN;
 *  2. no es el solicitante (un usuario ligado a ese colaborador);
 *  3. cumple la regla del paso (rol, usuario, departamento). Si NADIE la
 *     cumple, firma cualquiera con "Aprobar" en la sede (el pase no se atora);
 *  4. no firmó ya otro paso de la misma ronda, salvo que no haya nadie más
 *     que pueda firmarlo.
 * Los pasos van en orden: solo se firma el primero pendiente.
 *
 * Ajustes de la empresa (empresas.preferencias['pases_salida'], ADR-0012):
 *  - firmas_caseta: qué firmas pide cada movimiento de caseta (dos, solo
 *    Seguridad o ninguna), para todos los motivos con excepciones por motivo.
 *    Se copia al pase al enviarlo (los pases en curso conservan la suya).
 *  - rechazo: al corregir y reenviar, empezar desde el primer paso (omisión,
 *    como siempre) o retomar desde el paso que rechazó.
 * Cada paso puede pedirse solo según el valor total del pase (valor_minimo /
 * valor_maximo); sin valor conocido, el paso se pide.
 * Sin configurar, todo se comporta como el circuito original.
 */
class CircuitoPasesSalida
{
    public const MAX_PASOS = 8;

    /** Nombre del paso del jefe del departamento (predeterminado y «Agregar el paso…»). */
    public const NOMBRE_PASO_JEFE = 'Jefe del departamento del solicitante';

    /** Nombre del mismo paso en el circuito anterior (para «Retomar» entre rondas). */
    public const NOMBRE_PASO_JEFE_ANTERIOR = 'Jefe de Departamento';

    /**
     * Circuito predeterminado (cadena de SEGCAT, pases_salida_modal_aprobar.php):
     * Jefe del departamento del solicitante, Contraloría y Gerencia,
     * obligatorios y para todos los motivos. El primer paso lo resuelven los
     * responsables de Departamentos (decisión del dueño 2026-10-23; antes era
     * «quien tenga Aprobar del mismo departamento», que queda como respaldo).
     */
    public const PREDETERMINADO = [
        ['nombre' => self::NOMBRE_PASO_JEFE, 'tipo' => PaseSalidaPaso::JEFE_DEPARTAMENTO, 'departamento' => 'solicitante'],
        ['nombre' => 'Contraloría', 'tipo' => 'permiso', 'departamento' => 'cualquiera'],
        ['nombre' => 'Gerencia', 'tipo' => 'permiso', 'departamento' => 'cualquiera'],
    ];

    /** Al corregir y reenviar un pase rechazado. */
    public const RECHAZO = [
        'inicio' => 'Empezar desde el primer paso',
        'retomar' => 'Retomar desde el paso que rechazó',
    ];

    public const RECHAZO_OMISION = 'inicio';

    /** Mejoras Incidentes (ADR-0013): unidades de los tiempos de espera de las firmas. */
    public const UNIDADES_ESPERA = ['horas' => 'horas', 'dias' => 'días'];

    /** Tope de un tiempo de espera (30 días). */
    public const MAX_HORAS_ESPERA = 720;

    /** Tope de una condición de valor (pesos). */
    public const VALOR_MAXIMO = 9999999999.99;

    /** @var array<string, Collection<int, User>> usuarios con "aprobar" por sede */
    private array $candidatos = [];

    /** @var array<string, array<string, array{0: Collection<int, int>, 1: Collection<int, int>}>> responsables por departamento y sede */
    private array $responsables = [];

    /** @var Collection<int, DepartamentoResponsable>|null responsables activos de la empresa (una lectura por petición) */
    private ?Collection $responsablesEmpresa = null;

    /** C-12: delegaciones activas, directas o en cadena (una lectura por petición). */
    private ?CadenaDelegaciones $cadena = null;

    private function cadena(): CadenaDelegaciones
    {
        return $this->cadena ??= app(CadenaDelegaciones::class); // una sola cadena por petición (revisión Etapa 4, D-04)
    }

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
        return PaseSalidaPaso::where('activo', true)->exists() || $this->ajustesGuardados() !== [];
    }

    /** ¿Algún paso vigente depende del valor? (el formulario pide entonces el valor de cada artículo) */
    public function usaValor(): bool
    {
        return $this->pasos()->contains(fn (PaseSalidaPaso $p) => $p->tieneCondicionDeValor());
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
     * Si trae firmas_caseta o rechazo, guarda también esos ajustes.
     *
     * @param  array<string, mixed>  $entrada  pasos[i][nombre|tipo|rol_id|usuarios[]|departamento|departamento_id|obligatorio|motivos[]|valor_minimo|valor_maximo],
     *                                         firmas_caseta[movimiento][todos|motivos[motivo]], rechazo
     */
    public function guardar(User $actor, array $entrada): void
    {
        $conAjustes = array_key_exists('firmas_caseta', $entrada) || array_key_exists('rechazo', $entrada) || array_key_exists('espera', $entrada);
        $ajustes = $conAjustes ? $this->validarAjustes($entrada) : null;

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
            'pasos.*.valor_minimo' => ['nullable'], // se revisan abajo: admiten «$50,000»
            'pasos.*.valor_maximo' => ['nullable'],
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
            // El jefe del departamento siempre es el del departamento del solicitante
            $departamento = $tipo === PaseSalidaPaso::JEFE_DEPARTAMENTO ? 'solicitante' : ($p['departamento'] ?? 'cualquiera');
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
            $nombre = trim((string) preg_replace('/\s+/u', ' ', $p['nombre']));
            $minimo = $this->valor($p['valor_minimo'] ?? null, "pasos.{$i}.valor_minimo", $nombre);
            $maximo = $this->valor($p['valor_maximo'] ?? null, "pasos.{$i}.valor_maximo", $nombre);
            if ($minimo !== null && $maximo !== null && $maximo <= $minimo) {
                throw ValidationException::withMessages(["pasos.{$i}.valor_maximo" => "En el paso «{$nombre}», el valor «menor a» debe ser mayor que el valor «mayor o igual a»."]);
            }
            $limpios[] = [
                'orden' => $i + 1,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'rol_id' => $tipo === 'rol' ? (int) $p['rol_id'] : null,
                'departamento' => $departamento,
                'departamento_id' => $departamento === 'especifico' ? (int) $p['departamento_id'] : null,
                // Sin motivos marcados, o con todos marcados, aplica a todos
                'motivos' => $motivos === [] || count($motivos) === count(PaseSalida::MOTIVOS) ? null : $motivos,
                'obligatorio' => filter_var($p['obligatorio'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'valor_minimo' => $minimo,
                'valor_maximo' => $maximo,
                'usuarios' => $usuarios,
            ];
        }
        // Al menos un paso obligatorio para cada motivo
        foreach (array_keys(PaseSalida::MOTIVOS) as $motivo) {
            $obligatorios = array_filter($limpios, fn ($p) => $p['obligatorio'] && ($p['motivos'] === null || in_array($motivo, $p['motivos'], true)));
            if ($obligatorios === []) {
                throw ValidationException::withMessages(['pasos' => 'Cada motivo necesita al menos un paso obligatorio: «'.PaseSalida::MOTIVOS[$motivo].'» no tiene ninguno.']);
            }
            // Un paso que depende del valor puede no aplicar: cada motivo necesita uno que siempre aplique
            if (array_filter($obligatorios, fn ($p) => $p['valor_minimo'] === null && $p['valor_maximo'] === null) === []) {
                throw ValidationException::withMessages(['pasos' => 'Cada motivo necesita al menos un paso obligatorio sin condición de valor: «'.PaseSalida::MOTIVOS[$motivo].'» solo tiene pasos que dependen del valor.']);
            }
        }

        $antes = $this->resumen();
        $ajustesAntes = $this->resumenAjustes();
        $esperaAntes = $this->resumenEspera();
        DB::transaction(function () use ($limpios, $ajustes) {
            PaseSalidaPaso::query()->delete();
            foreach ($limpios as $p) {
                $usuarios = $p['usuarios'];
                unset($p['usuarios']);
                $paso = PaseSalidaPaso::create($p);
                foreach ($usuarios as $id) {
                    $paso->usuarios()->attach($id, ['empresa_id' => $paso->empresa_id]);
                }
            }
            if ($ajustes !== null) {
                $this->escribirAjustes($ajustes);
            }
        });

        $primero = PaseSalidaPaso::orderBy('orden')->first();
        $this->auditoria->auditar($actor, 'pases_salida.circuito_actualizado', $primero, ['pasos' => $antes], ['pasos' => $this->resumen()]);
        if ($ajustes !== null && $ajustesAntes !== ($despues = $this->resumenAjustes())) {
            $this->auditoria->auditar($actor, 'pases_salida.ajustes_actualizados', $this->empresa(), ['ajustes' => $ajustesAntes], ['ajustes' => $despues]);
        }
        if ($ajustes !== null && $esperaAntes !== ($esperaDespues = $this->resumenEspera())) {
            $this->auditoria->auditar($actor, 'pases_salida.tiempos_espera', $this->empresa(), ['espera' => $esperaAntes], ['espera' => $esperaDespues]);
        }
    }

    /**
     * Vuelve a la cadena de SEGCAT (borra la configuración de la empresa).
     */
    public function restablecer(User $actor): void
    {
        $antes = ['pasos' => $this->resumen(), 'ajustes' => $this->resumenAjustes()];
        $primero = PaseSalidaPaso::orderBy('orden')->first();
        $conAjustes = $this->ajustesGuardados() !== [];
        DB::transaction(function () {
            PaseSalidaPaso::query()->delete();
            $this->escribirAjustes(null);
        });
        if ($primero !== null || $conAjustes) {
            $this->auditoria->auditar($actor, 'pases_salida.circuito_restablecido', $primero ?? $this->empresa(), $antes,
                ['pasos' => $this->resumen(), 'ajustes' => $this->resumenAjustes()]);
        }
    }

    /** ¿El circuito vigente ya tiene el paso del jefe del departamento del solicitante? */
    public function tienePasoJefe(): bool
    {
        return $this->pasos()->contains(fn (PaseSalidaPaso $p) => $p->tipo === PaseSalidaPaso::JEFE_DEPARTAMENTO);
    }

    /**
     * «Agregar el paso del jefe del departamento» (empresas con circuito
     * propio): lo pone como paso 1, obligatorio y para todos los motivos, y
     * recorre los demás sin cambiarlos. Las empresas sin circuito propio ya lo
     * tienen (predeterminado). Los pases en curso conservan su copia.
     */
    public function agregarPasoJefe(User $actor): void
    {
        if (! PaseSalidaPaso::where('activo', true)->exists()) {
            throw ValidationException::withMessages(['pasos' => 'Tu circuito es el predeterminado: ya empieza con el paso «'.self::NOMBRE_PASO_JEFE.'».']);
        }
        if ($this->tienePasoJefe()) {
            throw ValidationException::withMessages(['pasos' => 'El circuito ya tiene el paso «'.self::NOMBRE_PASO_JEFE.'».']);
        }
        if (PaseSalidaPaso::where('activo', true)->count() >= self::MAX_PASOS) {
            throw ValidationException::withMessages(['pasos' => 'El circuito ya tiene '.self::MAX_PASOS.' pasos (el máximo): quita uno antes de agregar el del jefe del departamento.']);
        }

        $antes = $this->resumen();
        DB::transaction(function () {
            // De atrás hacia adelante, sin chocar con ningún índice de orden
            PaseSalidaPaso::orderByDesc('orden')->get()->each(fn (PaseSalidaPaso $p) => $p->forceFill(['orden' => $p->orden + 1])->save());
            PaseSalidaPaso::create(['orden' => 1, 'nombre' => self::NOMBRE_PASO_JEFE, 'tipo' => PaseSalidaPaso::JEFE_DEPARTAMENTO,
                'departamento' => 'solicitante', 'obligatorio' => true, 'motivos' => null]);
        });
        $primero = PaseSalidaPaso::orderBy('orden')->first();
        $this->auditoria->auditar($actor, 'pases_salida.circuito_actualizado', $primero, ['pasos' => $antes], ['pasos' => $this->resumen()]);
    }

    // --------------------------------------------- Firmas en caseta y rechazo

    /**
     * Ajustes guardados de la empresa de trabajo (vacío = los de omisión).
     *
     * @return array{rechazo?: string, firmas_caseta?: array<string, array{todos?: string, motivos?: array<string, string>}>}
     */
    public function ajustesGuardados(): array
    {
        $guardados = $this->empresa()?->preferencias['pases_salida'] ?? [];

        return is_array($guardados) ? $guardados : [];
    }

    /** ¿Al reenviar un rechazado se retoma desde el paso que rechazó? */
    public function rechazo(): string
    {
        $valor = $this->ajustesGuardados()['rechazo'] ?? null;

        return isset(self::RECHAZO[$valor]) ? $valor : self::RECHAZO_OMISION;
    }

    /**
     * Firmas en caseta configuradas: movimiento => [todos => modo, motivos => [motivo => modo]].
     *
     * @return array<string, array{todos: string, motivos: array<string, string>}>
     */
    public function firmasCaseta(): array
    {
        $guardadas = $this->ajustesGuardados()['firmas_caseta'] ?? [];
        $tabla = [];
        foreach (array_keys(PaseSalida::PASOS_FISICOS) as $movimiento) {
            $g = is_array($guardadas[$movimiento] ?? null) ? $guardadas[$movimiento] : [];
            $todos = isset(PaseSalida::FIRMAS_CASETA[$g['todos'] ?? null]) ? $g['todos'] : PaseSalida::FIRMAS_CASETA_OMISION;
            $motivos = [];
            foreach ((array) ($g['motivos'] ?? []) as $motivo => $modo) {
                if (isset(PaseSalida::MOTIVOS[$motivo]) && isset(PaseSalida::FIRMAS_CASETA[$modo]) && self::movimientoAplica($movimiento, $motivo)) {
                    $motivos[$motivo] = $modo;
                }
            }
            $tabla[$movimiento] = ['todos' => $todos, 'motivos' => $motivos];
        }

        return $tabla;
    }

    /**
     * Lo que se copia al pase: movimiento => modo, para su motivo.
     *
     * @return array<string, string>
     */
    public function firmasCasetaPara(string $motivo): array
    {
        return collect($this->firmasCaseta())->map(fn (array $f) => $f['motivos'][$motivo] ?? $f['todos'])->all();
    }

    /** ¿Ese movimiento de caseta existe para ese motivo? (venta y traspaso definitivo solo tienen salida) */
    public static function movimientoAplica(string $movimiento, string $motivo): bool
    {
        return $movimiento === 'salida' || PaseSalida::requiereRegreso($motivo);
    }

    // ------------------------------- Mejoras Incidentes: firmas que tardan (criterio 3)

    /**
     * Horas para recordar al firmante y para avisar al siguiente nivel (null = apagado, como siempre).
     *
     * @return array{recordar: ?int, escalar: ?int}
     */
    /** Las horas que la empresa escribió en el circuito (sin las de «Reloj y suplente»), para el formulario. */
    public function esperaGuardada(): array
    {
        $g = $this->ajustesGuardados()['espera'] ?? [];
        $horas = fn ($v) => is_numeric($v) && (int) $v >= 1 && (int) $v <= self::MAX_HORAS_ESPERA ? (int) $v : null;

        return ['recordar' => $horas($g['recordar_horas'] ?? null), 'escalar' => $horas($g['escalar_horas'] ?? null)];
    }

    public function espera(): array
    {
        $g = $this->ajustesGuardados()['espera'] ?? [];
        $horas = fn ($v) => is_numeric($v) && (int) $v >= 1 && (int) $v <= self::MAX_HORAS_ESPERA ? (int) $v : null;
        $espera = ['recordar' => $horas($g['recordar_horas'] ?? null), 'escalar' => $horas($g['escalar_horas'] ?? null)];
        // C-22 (DD-10): sin horas propias, las de «Reloj y suplente» (24 h y 72 h de fábrica), salvo que la empresa apague los avisos
        if ($espera['recordar'] === null && $espera['escalar'] === null) {
            $general = app(AjustesRelojes::class)->generales($this->empresa());
            if ($general['encendido']) {
                return ['recordar' => $general['ambar'], 'escalar' => $general['suplente']];
            }
        }

        return $espera;
    }

    /** «3 días» o «8 horas» (para pantalla y bitácora). */
    public static function textoHoras(?int $horas): ?string
    {
        if ($horas === null) {
            return null;
        }

        return $horas % 24 === 0 ? ($horas / 24).' '.($horas === 24 ? 'día' : 'días') : $horas.' '.($horas === 1 ? 'hora' : 'horas');
    }

    /**
     * Tiempos de espera en palabras ([] si están apagados).
     *
     * @return list<string>
     */
    public function resumenEspera(): array
    {
        $e = $this->espera();
        $lineas = [];
        if ($e['recordar'] !== null) {
            $lineas[] = 'Recordar a quien debe firmar después de '.self::textoHoras($e['recordar']);
        }
        if ($e['escalar'] !== null) {
            $lineas[] = 'Avisar al siguiente nivel (o al administrador) después de '.self::textoHoras($e['escalar']);
        }

        return $lineas;
    }

    /**
     * @param  array<string, mixed>  $entrada  espera[recordar|recordar_unidad|escalar|escalar_unidad]
     * @return array{recordar_horas?: int, escalar_horas?: int}
     */
    private function validarEspera(array $entrada): array
    {
        $e = is_array($entrada['espera'] ?? null) ? $entrada['espera'] : [];
        $horas = function (string $campo, string $texto) use ($e): ?int {
            $valor = $e[$campo] ?? null;
            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                return null;
            }
            $unidad = $e[$campo.'_unidad'] ?? 'horas';
            if (! is_numeric($valor) || (int) $valor < 1 || (string) (int) $valor !== trim((string) $valor) || ! isset(self::UNIDADES_ESPERA[$unidad])) {
                throw ValidationException::withMessages(["espera.{$campo}" => "En «{$texto}» escribe un número entero de horas o días (por ejemplo 4), o déjalo vacío para no avisar."]);
            }
            $total = (int) $valor * ($unidad === 'dias' ? 24 : 1);
            if ($total > self::MAX_HORAS_ESPERA) {
                throw ValidationException::withMessages(["espera.{$campo}" => "En «{$texto}» el máximo es 30 días."]);
            }

            return $total;
        };
        $recordar = $horas('recordar', 'Recordar a quien debe firmar');
        $escalar = $horas('escalar', 'Avisar al siguiente nivel');
        if ($recordar !== null && $escalar !== null && $escalar <= $recordar) {
            throw ValidationException::withMessages(['espera.escalar' => 'El aviso al siguiente nivel debe ir después del recordatorio (más horas o días).']);
        }

        return array_filter(['recordar_horas' => $recordar, 'escalar_horas' => $escalar], fn ($v) => $v !== null);
    }

    /**
     * Ajustes en palabras (bitácora de auditoría y pantalla de Configuración).
     *
     * @return list<string>
     */
    public function resumenAjustes(): array
    {
        $lineas = [];
        foreach ($this->firmasCaseta() as $movimiento => $f) {
            $lineas[] = 'Firmas en caseta · '.PaseSalida::PASOS_FISICOS[$movimiento][1].': '.PaseSalida::FIRMAS_CASETA[$f['todos']]
                .collect($f['motivos'])->map(fn ($modo, $motivo) => ' · '.PaseSalida::MOTIVOS[$motivo].': '.PaseSalida::FIRMAS_CASETA[$modo])->implode('');
        }
        $lineas[] = 'Al corregir y reenviar un rechazado: '.self::RECHAZO[$this->rechazo()];

        return $lineas;
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{rechazo: string, firmas_caseta: array<string, array{todos?: string, motivos?: array<string, string>}>}
     */
    private function validarAjustes(array $entrada): array
    {
        $rechazo = $entrada['rechazo'] ?? self::RECHAZO_OMISION;
        if (! is_string($rechazo) || ! isset(self::RECHAZO[$rechazo])) {
            throw ValidationException::withMessages(['rechazo' => 'Elige qué pasa al corregir y reenviar un pase rechazado.']);
        }

        $entradaFirmas = is_array($entrada['firmas_caseta'] ?? null) ? $entrada['firmas_caseta'] : [];
        $firmas = [];
        foreach (array_keys(PaseSalida::PASOS_FISICOS) as $movimiento) {
            $e = is_array($entradaFirmas[$movimiento] ?? null) ? $entradaFirmas[$movimiento] : [];
            $todos = $e['todos'] ?? PaseSalida::FIRMAS_CASETA_OMISION;
            if (! is_string($todos) || ! isset(PaseSalida::FIRMAS_CASETA[$todos])) {
                throw ValidationException::withMessages(["firmas_caseta.{$movimiento}.todos" => 'Elige las firmas de «'.PaseSalida::PASOS_FISICOS[$movimiento][1].'».']);
            }
            $motivos = [];
            foreach ((is_array($e['motivos'] ?? null) ? $e['motivos'] : []) as $motivo => $modo) {
                if ($modo === null || $modo === '') {
                    continue; // «Igual que todos los motivos»
                }
                if (! is_string($modo) || ! isset(PaseSalida::FIRMAS_CASETA[$modo]) || ! isset(PaseSalida::MOTIVOS[$motivo])) {
                    throw ValidationException::withMessages(["firmas_caseta.{$movimiento}.motivos" => 'Elige las firmas de «'.PaseSalida::PASOS_FISICOS[$movimiento][1].'» para cada motivo.']);
                }
                if ($modo !== $todos && self::movimientoAplica($movimiento, (string) $motivo)) {
                    $motivos[$motivo] = $modo;
                }
            }
            // Se guarda solo lo distinto de la omisión: sin cambios no queda nada
            if ($todos !== PaseSalida::FIRMAS_CASETA_OMISION || $motivos !== []) {
                $firmas[$movimiento] = array_filter(['todos' => $todos, 'motivos' => $motivos]);
            }
        }

        return ['rechazo' => $rechazo, 'firmas_caseta' => $firmas, 'espera' => $this->validarEspera($entrada)];
    }

    /**
     * @param  array{rechazo: string, firmas_caseta: array<string, mixed>}|null  $ajustes  null = volver a la omisión
     */
    private function escribirAjustes(?array $ajustes): void
    {
        $empresa = Empresa::query()->lockForUpdate()->find(app(Tenant::class)->empresaId());
        if ($empresa === null) {
            return;
        }
        $preferencias = is_array($empresa->preferencias) ? $empresa->preferencias : [];
        $limpios = $ajustes === null ? [] : array_filter([
            'rechazo' => $ajustes['rechazo'] !== self::RECHAZO_OMISION ? $ajustes['rechazo'] : null,
            'firmas_caseta' => $ajustes['firmas_caseta'],
            'espera' => $ajustes['espera'] ?? [],
        ]);
        if ($limpios === []) {
            unset($preferencias['pases_salida']);
        } else {
            $preferencias['pases_salida'] = $limpios;
        }
        $empresa->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
    }

    /** La empresa de trabajo (se lee cada vez: los ajustes pueden cambiar en la misma petición). */
    private function empresa(): ?Empresa
    {
        $id = app(Tenant::class)->empresaId();

        return $id === null ? null : Empresa::find($id);
    }

    /**
     * Un monto en pesos escrito por el administrador ("50,000", "$ 50000.50"); vacío = sin condición.
     */
    private function valor(mixed $texto, string $campo, string $paso): ?float
    {
        if ($texto === null || (is_string($texto) && trim($texto) === '')) {
            return null;
        }
        $limpio = is_scalar($texto) ? str_replace(['$', ',', ' '], '', (string) $texto) : '';
        if (! is_numeric($limpio) || (float) $limpio < 0 || (float) $limpio > self::VALOR_MAXIMO) {
            throw ValidationException::withMessages([$campo => "En el paso «{$paso}», escribe el valor en pesos (solo números, por ejemplo 50000)."]);
        }

        return round((float) $limpio, 2);
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
            .($p->motivos ? ' · solo '.implode(', ', array_map(fn ($m) => PaseSalida::MOTIVOS[$m] ?? $m, $p->motivos)) : '')
            .(($v = PaseSalidaPaso::textoCondicionValor($p->valor_minimo, $p->valor_maximo)) !== null ? ' · solo con '.$v : ''))->values()->all();
    }

    public function quienFirma(PaseSalidaPaso $p): string
    {
        if ($p->tipo === PaseSalidaPaso::JEFE_DEPARTAMENTO) {
            return 'el jefe del departamento del solicitante (responsable en Departamentos; si no hay, su suplente)';
        }
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
     * Copia el circuito vigente al pase (ronda actual): los pasos que aplican
     * a su motivo y a su valor. Con $retomar (rechazo configurado «Retomar
     * desde el paso que rechazó»), los pasos que ya se habían aprobado u
     * omitido en la ronda anterior y siguen aplicando conservan esa firma; el
     * que rechazó y los que ahora aplican de nuevo quedan pendientes.
     *
     * @return array{aplican: int, pendientes: int, conservadas: list<string>}
     */
    public function instanciar(PaseSalida $pase, bool $retomar = false): array
    {
        $pase->loadMissing('solicitante:id,departamento_id');
        $valor = $pase->valorTotal();
        $previas = ! $retomar ? collect() : PaseSalidaAprobacion::where('pase_salida_id', $pase->id)->where('ronda', $pase->ronda - 1)
            ->whereIn('estado', [PaseSalidaAprobacion::APROBADO, PaseSalidaAprobacion::OMITIDO])->orderBy('orden')->get()
            ->unique(fn (PaseSalidaAprobacion $a) => self::claveDePaso($a->nombre))->keyBy(fn (PaseSalidaAprobacion $a) => self::claveDePaso($a->nombre));
        $orden = 0;
        $pendientes = 0;
        $conservadas = [];
        foreach ($this->pasos() as $paso) {
            if (! $paso->aplicaA($pase->motivo, $valor)) {
                continue;
            }
            $departamento = match ($paso->departamento) {
                'solicitante' => $pase->solicitante?->departamento_id,
                'especifico' => $paso->departamento_id,
                default => null,
            };
            $aprobacion = PaseSalidaAprobacion::create([
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
                'valor_minimo' => $paso->valor_minimo,
                'valor_maximo' => $paso->valor_maximo,
            ]);
            $previa = $previas->pull(self::claveDePaso($paso->nombre));
            if ($previa !== null) {
                $aprobacion->forceFill([
                    'estado' => $previa->estado, 'resuelto_por' => $previa->resuelto_por, 'resuelto_en' => $previa->resuelto_en,
                    'comentario' => $previa->comentario, 'firma_id' => $previa->firma_id,
                    'conservada_de_ronda' => $previa->conservada_de_ronda ?? $previa->ronda,
                ])->save();
                $conservadas[] = $paso->nombre;
            } else {
                $pendientes++;
            }
        }

        return ['aplican' => $orden, 'pendientes' => $pendientes, 'conservadas' => $conservadas];
    }

    /**
     * El mismo paso entre rondas: por su nombre (el circuito se guarda de nuevo
     * con otros ids). «Jefe de Departamento» (nombre anterior) y «Jefe del
     * departamento del solicitante» son el mismo paso.
     */
    private static function claveDePaso(string $nombre): string
    {
        $clave = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nombre)), 'UTF-8');

        return $clave === mb_strtolower(self::NOMBRE_PASO_JEFE_ANTERIOR, 'UTF-8') ? mb_strtolower(self::NOMBRE_PASO_JEFE, 'UTF-8') : $clave;
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
        $porRegla = $this->porRegla($pase, $aprobacion);

        return $porRegla->isNotEmpty() ? $porRegla : $candidatos;
    }

    /**
     * C-12 «No molestar»: a quién se AVISA de un paso. Quien delegó sus
     * aprobaciones y tiene a su delegado entre los que pueden firmar no
     * recibe el aviso (le llega a su delegado); sigue pudiendo firmar si entra.
     *
     * @return Collection<int, User>
     */
    public function aQuienAvisar(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): Collection
    {
        $usuarios = $this->aprobadores($pase, $aprobacion);
        $ids = $usuarios->pluck('id')->map(fn ($id) => (int) $id);
        $cadena = $this->cadena();

        // Directa o en cadena (A→B→C): no se avisa a quien tiene en su cadena a alguien que sí puede firmar
        return $usuarios->reject(fn (User $u) => $ids->intersect($cadena->cadena((int) $u->id))->isNotEmpty())->values();
    }

    /** ¿Alguien cumple la regla del paso? Si no, firma cualquiera con "Aprobar" en la sede. */
    public function reglaSinFirmantes(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): bool
    {
        return $this->porRegla($pase, $aprobacion)->isEmpty();
    }

    /**
     * Quién firma un paso «Jefe del departamento del solicitante», en orden:
     *  1. titular: los responsables (no suplentes) del departamento del
     *     solicitante en la sede de origen (o en todas sus sedes), y el
     *     delegado de cada uno si tiene «No molestar» activo;
     *  2. suplente: si no hay titular que pueda firmar, los suplentes (y sus delegados);
     *  3. departamento: si no hay responsables que puedan firmar, quien tenga
     *     "Aprobar" y su colaborador sea de ese departamento (regla anterior);
     *  4. sede: nadie de lo anterior → regla general del circuito (cualquiera
     *     con "Aprobar" en la sede; reglaSinFirmantes() es verdadero).
     * Siempre entre los candidatos: "Aprobar" en la sede de origen, activos y
     * sin el solicitante (si la jefa pide un pase a su nombre, firma su suplente).
     *
     * @return array{como: string, usuarios: Collection<int, User>} como: titular | suplente | departamento | sede
     */
    public function jefesDelDepartamento(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): array
    {
        $candidatos = $this->candidatos($pase);
        $departamentoId = $aprobacion->departamento_id !== null ? (int) $aprobacion->departamento_id : null;
        if ($departamentoId !== null) {
            foreach ($this->responsablesPorNivel($pase, $departamentoId) as $como => [$ids, $delegados]) {
                $usuarios = $candidatos->filter(fn (User $u) => $ids->contains((int) $u->id) || $delegados->contains((int) $u->id))
                    ->sortBy(fn (User $u) => $ids->contains((int) $u->id) ? 0 : 1)->values();
                if ($usuarios->isNotEmpty()) {
                    return ['como' => $como, 'usuarios' => $usuarios];
                }
            }
            $delDepartamento = $candidatos->filter(fn (User $u) => (int) $u->colaborador?->departamento_id === $departamentoId)->values();
            if ($delDepartamento->isNotEmpty()) {
                return ['como' => 'departamento', 'usuarios' => $delDepartamento];
            }
        }

        return ['como' => 'sede', 'usuarios' => collect()];
    }

    /**
     * Ids de titulares y de suplentes del departamento en la sede de origen, con
     * los delegados activos de cada nivel (se recuerdan durante la petición).
     *
     * @return array{titular: array{0: Collection<int, int>, 1: Collection<int, int>}, suplente: array{0: Collection<int, int>, 1: Collection<int, int>}}
     */
    private function responsablesPorNivel(PaseSalida $pase, int $departamentoId): array
    {
        $sede = $pase->sede_id !== null ? (int) $pase->sede_id : null;

        return $this->responsables[$pase->empresa_id.'-'.$departamentoId.'-'.$sede] ??= (function () use ($departamentoId, $sede) {
            // Una sola lectura de responsables y de delegaciones activas por petición (Mis pendientes revisa muchos pases)
            $this->responsablesEmpresa ??= DepartamentoResponsable::query()
                ->join('users', 'users.id', '=', 'departamento_responsables.user_id')->where('users.activo', true)
                ->orderBy('departamento_responsables.es_suplente')->orderBy('departamento_responsables.id')
                ->get(['departamento_responsables.id', 'departamento_responsables.departamento_id', 'departamento_responsables.user_id',
                    'departamento_responsables.sede_id', 'departamento_responsables.es_suplente']);
            $cadena = $this->cadena();
            $responsables = $this->responsablesEmpresa->filter(fn ($r) => (int) $r->departamento_id === $departamentoId
                && ($r->sede_id === null || ($sede !== null && (int) $r->sede_id === $sede)));
            $niveles = [];
            foreach (['titular' => false, 'suplente' => true] as $como => $suplente) {
                $ids = $responsables->filter(fn ($r) => (bool) $r->es_suplente === $suplente)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();
                $delegados = $ids->flatMap(fn (int $id) => $cadena->cadena($id))->unique()->values(); // C-12: directa o en cadena
                $niveles[$como] = [$ids, $delegados];
            }

            return $niveles;
        })();
    }

    /**
     * Quienes cumplen la regla del paso (vacío = nadie: firma cualquiera con "Aprobar").
     *
     * @return Collection<int, User>
     */
    private function porRegla(PaseSalida $pase, PaseSalidaAprobacion $aprobacion): Collection
    {
        if ($aprobacion->tipo === PaseSalidaPaso::JEFE_DEPARTAMENTO) {
            return $this->jefesDelDepartamento($pase, $aprobacion)['usuarios'];
        }

        return $this->candidatos($pase)->filter(fn (User $u) => $this->cumpleRegla($u, $aprobacion))->values();
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
