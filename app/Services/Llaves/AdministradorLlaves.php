<?php

namespace App\Services\Llaves;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Llave;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Inventarios\Vouchers;
use App\Services\Lector\Identificacion;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Etiqueta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del Catálogo de llaves (réplica de llave_proceso.php de SEGCAT).
 *
 * Cada llave es de una sede. Con alcance de empresa se ve y administra todo;
 * con alcance de sede, solo las llaves de sus sedes (y solo se registran en
 * ellas); con alcance "propios", además, solo las que él dio de alta.
 *
 * Todo corre con la empresa de trabajo ya fijada en el Tenant: el filtro de
 * empresa lo pone el modelo, no el programador.
 */
class AdministradorLlaves
{
    /** Máximo de horarios por llave (evita formularios inflados a mano). */
    public const MAX_HORARIOS = 12;

    private const HORA = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Vouchers $vouchers,
    ) {}

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
     * Limita una consulta a las llaves que el actor puede tocar con un permiso.
     *
     * @param  Builder<Llave>  $consulta
     * @return Builder<Llave>
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
            ->when($sedes !== null, fn ($q) => $q->whereIn('llaves.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('llaves.creado_por', $actor->id));
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todas las que ve.
     *
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin) {
            return null;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso];
        if ($efectivo->alcance === Alcance::Empresa || ($efectivo->sedes === null && $efectivo->alcance !== Alcance::Propios)) {
            return null;
        }

        return $this->limitar(Llave::query(), $actor, $permiso)->pluck('llaves.id')->map(fn ($id) => (int) $id)->all();
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Llave
    {
        [$datos, $espacios, $grupos, $horarios] = $this->validar($actor, $entrada, null);

        $llave = DB::transaction(function () use ($datos, $espacios, $grupos, $horarios) {
            $llave = Llave::create($datos);
            $this->guardarRelaciones($llave, $espacios, $grupos, $horarios);

            return $llave;
        });

        $this->auditoria->auditar($actor, 'llaves.creado', $llave, null, $this->foto($llave));

        return $llave;
    }

    /**
     * Datos generales. El estado no se cambia aquí: la baja va con voucher y
     * la reactivación tiene su propio botón (SEGCAT permitía "EXTRAVIADA /
     * ROTA" desde la edición, sin voucher).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Llave $llave, array $entrada): Llave
    {
        $antes = $this->foto($llave);
        [$datos, $espacios, $grupos, $horarios] = $this->validar($actor, $entrada, $llave);

        DB::transaction(function () use ($llave, $datos, $espacios, $grupos, $horarios) {
            $llave->fill($datos)->save();
            $this->guardarRelaciones($llave, $espacios, $grupos, $horarios);
        });

        $despues = $this->foto($llave);
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'llaves.actualizado', $llave, $antes, $despues);
        }

        return $llave;
    }

    /**
     * Baja con voucher de reposición (Extraviada, Dañada o Robada), en una
     * sola transacción con el servicio común de Vouchers.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function darDeBaja(User $actor, Llave $llave, array $entrada): VoucherReposicion
    {
        if (! $llave->activo) {
            throw ValidationException::withMessages(['motivo' => "La llave {$llave->nomenclatura} ya está dada de baja."]);
        }

        // Ronda 5 (LL-05): con costo fijo el monto no se captura; con costo variable sí
        if ($llave->costoFijo() !== null && filter_var($entrada['aplica_cobro'] ?? false, FILTER_VALIDATE_BOOL)) {
            $entrada['monto'] = $llave->costoFijo();
        }

        $datos = $this->vouchers->validar($entrada);
        $voucher = $this->vouchers->darDeBaja($actor, $llave, 'llave', $llave->nomenclatura, $datos,
            fn () => $llave->forceFill(['activo' => false])->save(),
            referenciaCosto: $llave->etiquetaTipo());

        $this->auditoria->auditar($actor, 'llaves.desactivado', $llave, ['activo' => true], [
            'activo' => false, 'voucher' => $voucher->folio, 'motivo' => $voucher->motivo,
        ]);

        return $voucher;
    }

    /**
     * Reactivación simple (auditada). El voucher de la baja se conserva.
     */
    public function reactivar(User $actor, Llave $llave): void
    {
        if ($llave->activo) {
            return;
        }
        $llave->forceFill(['activo' => true])->save();
        $this->auditoria->auditar($actor, 'llaves.reactivado', $llave, ['activo' => false], ['activo' => true]);
    }

    /**
     * Costo sugerido por tipo de dispositivo (último cobrado), para el
     * diálogo de baja.
     *
     * @return array<string, string|null>
     */
    public function costosSugeridos(): array
    {
        $costos = [];
        foreach (Llave::TIPOS_DISPOSITIVO as $clave => $etiqueta) {
            $costos[$clave] = $this->vouchers->costoSugerido('llave', $etiqueta);
        }

        return $costos;
    }

    /**
     * @param  list<int>  $espacios
     * @param  list<int>  $grupos
     * @param  list<array{nombre: string, hora_inicio: string, hora_fin: string}>  $horarios
     */
    private function guardarRelaciones(Llave $llave, array $espacios, array $grupos, array $horarios): void
    {
        $llave->espacios()->sync($espacios);
        $llave->grupos()->sync($grupos);

        // Se reemplazan completos (como SEGCAT): son pocos por llave
        $llave->horarios()->delete();
        foreach ($horarios as $h) {
            $llave->horarios()->create($h);
        }
        $llave->unsetRelation('espacios')->unsetRelation('grupos')->unsetRelation('horarios');
    }

    // --------------------------------------------------------------- Validación

    /**
     * Valida y deja listos los datos, los lugares que abre y los horarios.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: array<string, mixed>, 1: list<int>, 2: list<int>, 3: list<array{nombre: string, hora_inicio: string, hora_fin: string}>}
     */
    public function validar(User $actor, array $entrada, ?Llave $actual): array
    {
        $entrada = $this->normalizar($entrada);

        $validados = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'departamento_id' => ['nullable', 'integer'],
            'puesto_id' => ['nullable', 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
            'nomenclatura' => ['required', 'string', 'max:50'],
            'descripcion' => ['required', 'string', 'max:255'],
            'tipo_dispositivo' => ['required', 'string', Rule::in(array_keys(Llave::TIPOS_DISPOSITIVO))],
            'alcance' => ['required', 'string', Rule::in(array_keys(Llave::ALCANCES))],
            'alcance_otro' => ['nullable', 'required_if:alcance,otra', 'string', 'max:150'],
            'espacios' => ['nullable', 'array', 'max:500'],
            'espacios.*' => ['integer'],
            'grupos' => ['nullable', 'array', 'max:200'],
            'grupos.*' => ['integer'],
            'id_externo' => ['nullable', 'string', 'max:60'],
            'plataforma_externa' => ['nullable', 'string', 'max:80'],
            'fecha_caducidad' => ['nullable', 'date_format:Y-m-d'],
            'costo_reposicion' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'costo_variable' => ['nullable', 'boolean'],
            'etiqueta_nfc' => ['nullable', 'string', 'max:'.Etiqueta::MAXIMO],
            'horario_nombre' => ['nullable', 'array', 'max:'.self::MAX_HORARIOS],
            'horario_nombre.*' => ['nullable', 'string', 'max:60'],
            'horario_inicio' => ['nullable', 'array', 'max:'.self::MAX_HORARIOS],
            'horario_inicio.*' => ['nullable', 'string'],
            'horario_fin' => ['nullable', 'array', 'max:'.self::MAX_HORARIOS],
            'horario_fin.*' => ['nullable', 'string'],
        ], [
            'sede_id.required' => 'Elige la sede de la llave.',
            'nomenclatura.required' => 'Escribe el nombre (nomenclatura) de la llave.',
            'nomenclatura.max' => 'El nombre de la llave es muy largo (máximo 50 caracteres).',
            'descripcion.required' => 'Describe qué abre la llave (por ejemplo: Site central de TI).',
            'tipo_dispositivo.in' => 'Elige el tipo de dispositivo de la lista.',
            'alcance.in' => 'Elige el alcance de apertura de la lista.',
            'alcance_otro.required_if' => 'Escribe qué espacio abre (por ejemplo: Cuarto de máquinas).',
            'fecha_caducidad.date_format' => 'Revisa la fecha de caducidad.',
            'costo_reposicion.numeric' => 'El costo de reposición debe ser una cantidad (por ejemplo 350 o 350.50).',
            'costo_reposicion.min' => 'El costo de reposición no puede ser negativo.',
            'costo_reposicion.max' => 'El costo de reposición es demasiado alto.',
            'horario_nombre.max' => 'Una llave puede tener hasta '.self::MAX_HORARIOS.' horarios.',
            'espacios.max' => 'Son demasiados lugares marcados para una sola llave.',
        ], [
            'sede_id' => 'sede',
            'departamento_id' => 'departamento',
            'puesto_id' => 'puesto objetivo',
            'colaborador_id' => 'responsable',
            'descripcion' => 'descripción de accesos',
            'tipo_dispositivo' => 'tipo de dispositivo',
            'alcance' => 'alcance de apertura',
            'id_externo' => 'ID externo',
            'plataforma_externa' => 'plataforma',
            'fecha_caducidad' => 'fecha de caducidad',
            'costo_reposicion' => 'costo de reposición',
            'etiqueta_nfc' => 'etiqueta NFC / RFID',
        ])->validate();

        $sede = $this->sedeValida($actor, (int) $validados['sede_id'], $actual);
        $tipo = $validados['tipo_dispositivo'];
        $alcance = $validados['alcance'];
        $nomenclatura = $validados['nomenclatura'];

        // Ronda 5 (LL-03): el nombre no se repite en la MISMA sede; otra sede sí puede usarlo
        $otra = Llave::where('sede_id', $sede->id)->where('nomenclatura', $nomenclatura)->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->first();
        if ($otra !== null) {
            throw ValidationException::withMessages(['nomenclatura' => "Ya existe una llave con el nombre «{$nomenclatura}» en la sede {$sede->nombre}"
                .($otra->activo ? '.' : ' (dada de baja: reactívala en lugar de registrarla otra vez).')]);
        }

        $departamentoId = $this->departamentoValido($validados['departamento_id'] ?? null, $sede, $actual);
        $puestoId = $this->puestoValido($validados['puesto_id'] ?? null, $departamentoId, $actual);

        [$espacios, $grupos] = $this->lugaresValidos($alcance, $sede, $validados['espacios'] ?? [], $validados['grupos'] ?? [], $actual);

        $conIdExterno = in_array($tipo, Llave::CON_ID_EXTERNO, true);
        $idExterno = $conIdExterno ? ($validados['id_externo'] ?? null) : null;
        $plataforma = $conIdExterno && $idExterno !== null ? ($validados['plataforma_externa'] ?? null) : null;
        if ($idExterno !== null) {
            $this->idExternoLibre($sede, $idExterno, $plataforma, $actual);
        }

        $etiqueta = Etiqueta::normalizar($validados['etiqueta_nfc'] ?? null);
        // Ronda 8: única en toda la empresa y entre todos los tipos del lector
        app(Identificacion::class)->exigirEtiquetaLibre($etiqueta, $actual ?? new Llave);

        $datos = [
            'sede_id' => $sede->id,
            'departamento_id' => $departamentoId,
            'puesto_id' => $puestoId,
            'colaborador_id' => $this->colaboradorValido($validados['colaborador_id'] ?? null, $actual),
            'nomenclatura' => $nomenclatura,
            'descripcion' => $validados['descripcion'],
            'tipo_dispositivo' => $tipo,
            'alcance' => $alcance,
            'alcance_otro' => $alcance === 'otra' ? $validados['alcance_otro'] : null,
            'id_externo' => $idExterno,
            'plataforma_externa' => $plataforma,
            'fecha_caducidad' => $validados['fecha_caducidad'] ?? null,
            'costo_reposicion' => isset($validados['costo_reposicion']) ? number_format((float) $validados['costo_reposicion'], 2, '.', '') : null,
            'costo_variable' => (bool) ($validados['costo_variable'] ?? false),
            'etiqueta_nfc' => $etiqueta === '' ? null : $etiqueta,
        ];

        return [$datos, $espacios, $grupos, $this->horariosValidos($validados)];
    }

    /**
     * Espacios dobles fuera, vacíos como nulos; nomenclatura e ID externo en
     * mayúsculas (como SEGCAT).
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada): array
    {
        $entrada = array_intersect_key($entrada, array_flip([
            'sede_id', 'departamento_id', 'puesto_id', 'colaborador_id', 'nomenclatura', 'descripcion', 'tipo_dispositivo',
            'alcance', 'alcance_otro', 'espacios', 'grupos', 'id_externo', 'plataforma_externa', 'fecha_caducidad',
            'etiqueta_nfc', 'horario_nombre', 'horario_inicio', 'horario_fin', 'costo_reposicion', 'costo_variable',
        ]));

        foreach ($entrada as $campo => $valor) {
            if (is_string($valor)) {
                $valor = trim((string) preg_replace('/\s+/u', ' ', $valor));
                $entrada[$campo] = $valor === '' ? null : $valor;
            }
        }
        foreach (['nomenclatura', 'id_externo'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = mb_strtoupper($entrada[$campo]);
            }
        }

        return $entrada;
    }

    /**
     * La sede debe ser de la empresa, estar activa (o ser la que ya tenía) y
     * estar entre las sedes donde el actor puede crear o editar.
     */
    private function sedeValida(User $actor, int $sedeId, ?Llave $actual): Sede
    {
        $sede = Sede::find($sedeId);
        $permitidas = $this->sedes($actor, $actual === null ? 'llaves.crear' : 'llaves.editar');
        $esLaMisma = $actual !== null && $sedeId === (int) $actual->sede_id;

        if ($sede === null || (! $sede->activo && ! $esLaMisma) || ($permitidas !== null && ! in_array($sedeId, $permitidas, true))) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una de tus sedes activas.']);
        }

        return $sede;
    }

    private function departamentoValido(mixed $id, Sede $sede, ?Llave $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        if ($actual !== null && $id === (int) $actual->departamento_id && (int) $actual->sede_id === $sede->id) {
            return $id;
        }
        if (! Departamento::whereKey($id)->where('activo', true)->aplicanEn([$sede->id])->exists()) {
            throw ValidationException::withMessages(['departamento_id' => 'El departamento no existe, está desactivado o no aplica en esa sede.']);
        }

        return $id;
    }

    /**
     * Un puesto ligado a departamentos solo vale con uno de ellos; sin
     * departamentos aplica en cualquiera (regla de Puestos).
     */
    private function puestoValido(mixed $id, ?int $departamentoId, ?Llave $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $puesto = Puesto::with('departamentos:departamentos.id')->find($id);
        $esElMismo = $actual !== null && $id === (int) $actual->puesto_id;
        if ($puesto === null || (! $puesto->activo && ! $esElMismo)) {
            throw ValidationException::withMessages(['puesto_id' => 'El puesto no existe en esta empresa o está desactivado.']);
        }
        $suyos = $puesto->departamentos->pluck('id')->map(fn ($d) => (int) $d)->all();
        if ($departamentoId !== null && $suyos !== [] && ! in_array($departamentoId, $suyos, true)) {
            throw ValidationException::withMessages(['puesto_id' => "El puesto «{$puesto->nombre}» no corresponde al departamento elegido."]);
        }

        return $id;
    }

    private function colaboradorValido(mixed $id, ?Llave $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $colaborador = Colaborador::find($id);
        $esElMismo = $actual !== null && $id === (int) $actual->colaborador_id;
        if ($colaborador === null || ((! $colaborador->activo || $colaborador->fusionado_en_id !== null) && ! $esElMismo)) {
            throw ValidationException::withMessages(['colaborador_id' => 'El responsable no existe en esta empresa o está dado de baja.']);
        }

        return $id;
    }

    /**
     * Revalida en el servidor cada lugar marcado contra la sede elegida y el
     * nivel del alcance (SEGCAT descartaba en silencio los que no encajaban).
     * Los lugares que ya tenía la llave se conservan aunque hoy estén
     * desactivados.
     *
     * @param  list<mixed>  $espacios
     * @param  list<mixed>  $grupos
     * @return array{0: list<int>, 1: list<int>}
     */
    private function lugaresValidos(string $alcance, Sede $sede, array $espacios, array $grupos, ?Llave $actual): array
    {
        if ($alcance === 'seccion') {
            $pedidos = array_values(array_unique(array_map('intval', $grupos)));
            $previos = $actual?->grupos()->pluck('grupos_espacio.id')->map(fn ($id) => (int) $id)->all() ?? [];
            $validos = GrupoEspacio::where('sede_id', $sede->id)->whereIn('id', $pedidos)
                ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $previos))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->exigirLugares($pedidos, $validos, 'grupos', 'Marca al menos una sección.');

            return [[], $validos];
        }

        $nivel = Llave::NIVEL_DEL_ALCANCE[$alcance] ?? null;
        if ($nivel === null) {
            return [[], []];
        }

        $pedidos = array_values(array_unique(array_map('intval', $espacios)));
        $previos = $actual?->espacios()->pluck('espacios.id')->map(fn ($id) => (int) $id)->all() ?? [];
        $validos = Espacio::where('sede_id', $sede->id)->where('nivel', $nivel)->whereIn('id', $pedidos)
            ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $previos))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $faltan = ['zona' => 'Marca al menos una zona o edificio.', 'piso' => 'Marca al menos un piso.', 'area' => 'Marca al menos un área específica o cuarto.'];
        $this->exigirLugares($pedidos, $validos, 'espacios', $faltan[$alcance]);

        return [$validos, []];
    }

    /**
     * @param  list<int>  $pedidos
     * @param  list<int>  $validos
     */
    private function exigirLugares(array $pedidos, array $validos, string $campo, string $siVacio): void
    {
        if (count($validos) !== count($pedidos)) {
            throw ValidationException::withMessages([$campo => 'Algunos lugares marcados no pertenecen a la sede elegida o están desactivados. Revisa la lista.']);
        }
        if ($validos === []) {
            throw ValidationException::withMessages([$campo => $siVacio]);
        }
    }

    /**
     * SEGCAT: no se repite "este ID en esta plataforma, en esta sede"; dos
     * plataformas distintas sí pueden coincidir en el mismo ID.
     */
    private function idExternoLibre(Sede $sede, string $idExterno, ?string $plataforma, ?Llave $actual): void
    {
        $otra = Llave::where('sede_id', $sede->id)->where('id_externo', $idExterno)
            ->when($plataforma === null, fn ($q) => $q->whereNull('plataforma_externa'), fn ($q) => $q->whereRaw('LOWER(plataforma_externa) = ?', [mb_strtolower($plataforma)]))
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))
            ->first();
        if ($otra !== null) {
            throw ValidationException::withMessages(['id_externo' => "El ID externo «{$idExterno}»".($plataforma ? " de {$plataforma}" : '')
                ." ya está asignado a la llave «{$otra->nomenclatura}» en esta sede."]);
        }
    }

    /**
     * Horarios: filas vacías se ignoran; las incompletas se rechazan con un
     * mensaje claro (SEGCAT las descartaba en silencio). Se permite que
     * terminen al día siguiente (fin menor que inicio).
     *
     * @param  array<string, mixed>  $validados
     * @return list<array{nombre: string, hora_inicio: string, hora_fin: string}>
     */
    private function horariosValidos(array $validados): array
    {
        $nombres = array_values($validados['horario_nombre'] ?? []);
        $inicios = array_values($validados['horario_inicio'] ?? []);
        $fines = array_values($validados['horario_fin'] ?? []);
        $horarios = [];

        $total = max(count($nombres), count($inicios), count($fines));
        for ($i = 0; $i < $total; $i++) {
            $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) ($nombres[$i] ?? '')));
            $inicio = trim((string) ($inicios[$i] ?? ''));
            $fin = trim((string) ($fines[$i] ?? ''));
            if ($nombre === '' && $inicio === '' && $fin === '') {
                continue;
            }
            $cual = $nombre !== '' ? "«{$nombre}»" : 'número '.($i + 1);
            if ($nombre === '' || $inicio === '' || $fin === '') {
                throw ValidationException::withMessages(['horario_nombre' => "Completa el horario {$cual}: nombre, hora de inicio y hora de fin (o quítalo)."]);
            }
            if (! preg_match(self::HORA, $inicio) || ! preg_match(self::HORA, $fin)) {
                throw ValidationException::withMessages(['horario_inicio' => "Revisa las horas del horario {$cual}: se escriben como HH:MM (24 horas)."]);
            }
            if (substr($inicio, 0, 5) === substr($fin, 0, 5)) {
                throw ValidationException::withMessages(['horario_fin' => "En el horario {$cual} la hora de fin debe ser distinta a la de inicio."]);
            }
            $horarios[] = ['nombre' => mb_substr($nombre, 0, 60), 'hora_inicio' => substr($inicio, 0, 5).':00', 'hora_fin' => substr($fin, 0, 5).':00'];
        }

        return $horarios;
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(Llave $llave): array
    {
        $llave->load(['espacios:id', 'grupos:id', 'horarios']);

        return $llave->only([
            'sede_id', 'departamento_id', 'puesto_id', 'colaborador_id', 'nomenclatura', 'descripcion', 'tipo_dispositivo',
            'alcance', 'alcance_otro', 'id_externo', 'plataforma_externa', 'etiqueta_nfc', 'activo', 'costo_variable',
        ]) + [
            'costo_reposicion' => $llave->costo_reposicion === null ? null : (string) $llave->costo_reposicion,
            'fecha_caducidad' => $llave->fecha_caducidad?->format('Y-m-d'),
            'espacios' => $llave->espacios->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'grupos' => $llave->grupos->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'horarios' => $llave->horarios->map(fn ($h) => $h->nombre.' '.$h->inicio().'-'.$h->fin())->all(),
        ];
    }
}
