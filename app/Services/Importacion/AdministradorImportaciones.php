<?php

namespace App\Services\Importacion;

use App\Models\Colaborador;
use App\Models\Importacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Importacion\ArchivoNoValido;
use App\Support\Importacion\LectorArchivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * «Importar desde Excel» (ver docs/tecnico/importacion.md):
 *
 *  1. subir(): guarda el archivo original en el disco privado, lo lee y arma
 *     la VISTA PREVIA corriendo cada fila por el servicio del módulo dentro
 *     de una transacción que al final se deshace: nada se guarda todavía.
 *  2. aplicar(): repite lo mismo de verdad, por lotes en transacción (cada
 *     fila en su propio punto de guardado: una fila con error no tumba a las
 *     demás), y registra un evento de auditoría con el resumen.
 *
 * Permisos: «modulo.importar» para entrar; dar de alta pide «modulo.crear» y
 * actualizar lo que ya existe pide «modulo.editar» (con su alcance de sede o
 * «solo los propios», que deciden los mismos servicios).
 */
class AdministradorImportaciones
{
    /** Máximo de filas de datos por archivo (sin el encabezado). */
    public const MAX_FILAS = 2000;

    /** Tamaño máximo del archivo, en KB. */
    public const MAX_KB = 5120;

    /** Filas por transacción al guardar. */
    public const LOTE = 200;

    /** @var array<string, class-string<Importador>> */
    public const TIPOS = [
        'colaboradores' => ImportadorColaboradores::class,
        'personas' => ImportadorPersonas::class,
        'proveedores' => ImportadorProveedores::class,
        'vehiculos' => ImportadorVehiculos::class,
        'zonas' => ImportadorZonas::class,
    ];

    public function __construct(
        private readonly Tenant $tenant,
        private readonly AdministradorRoles $auditoria,
    ) {}

    public function importador(string $tipo): ?Importador
    {
        return isset(self::TIPOS[$tipo]) ? app(self::TIPOS[$tipo]) : null;
    }

    /**
     * ¿Ve CURP, RFC, NSS y demás datos personales de este archivo?
     */
    public function vePersonales(User $actor, Importador $importador): bool
    {
        return $importador->tipo() !== 'colaboradores' || $actor->can('colaboradores.datos_personales');
    }

    /**
     * Columnas que este usuario ve (y que van en su plantilla).
     *
     * @return list<Columna>
     */
    public function columnasVisibles(User $actor, Importador $importador): array
    {
        $ve = $this->vePersonales($actor, $importador);

        return array_values(array_filter($importador->columnas(), fn (Columna $c) => $ve || ! $c->personal));
    }

    // ---------------------------------------------------------------- Plantilla

    /**
     * Plantilla CSV (UTF-8 con BOM para que Excel respete los acentos):
     * encabezados en español y una fila de ejemplo.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function plantilla(User $actor, Importador $importador): array
    {
        $columnas = $this->columnasVisibles($actor, $importador);
        $sede = Sede::where('activo', true)->orderBy('id')->value('nombre');

        return [
            array_map(fn (Columna $c) => $c->encabezado(), $columnas),
            array_map(fn (Columna $c) => $c->clave === 'sede' && $sede !== null ? $sede : $c->ejemplo, $columnas),
        ];
    }

    // ------------------------------------------------------------- Subir y ver

    /**
     * Guarda el archivo, lo lee y arma la vista previa (sin guardar nada en el módulo).
     */
    public function subir(User $actor, int $empresaId, Importador $importador, UploadedFile $archivo): Importacion
    {
        $extension = mb_strtolower($archivo->getClientOriginalExtension());
        $formato = $extension === 'xlsx' ? 'xlsx' : 'csv';
        $nombre = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', $archivo->getClientOriginalName()) ?: 'archivo.'.$formato, 0, 200);
        $ruta = $archivo->storeAs("importaciones/{$empresaId}", Str::uuid()->toString().'.'.$formato, 'local');
        if ($ruta === false) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo. Intenta de nuevo.']);
        }

        try {
            $datos = $this->tenant->conEmpresa($empresaId, fn () => $this->leer($actor, $importador, Storage::disk('local')->path($ruta), $formato));
            if ($datos['filas'] === []) {
                throw new ArchivoNoValido('El archivo no trae datos: solo el renglón de encabezados. Copia tus datos desde el renglón 2.');
            }
            if (count($datos['filas']) > self::MAX_FILAS) {
                throw new ArchivoNoValido('El archivo trae '.number_format(count($datos['filas'])).' filas y el máximo es '.number_format(self::MAX_FILAS)
                    .' por archivo. Divídelo en varios archivos y súbelos uno por uno.');
            }
        } catch (ArchivoNoValido $e) {
            Storage::disk('local')->delete($ruta);
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $importador, $ruta, $formato, $nombre, $datos) {
            $filas = $this->procesar($actor, $empresaId, $importador, $datos['filas'], null, false);

            return Importacion::create([
                'empresa_id' => $empresaId,
                'tipo' => $importador->tipo(),
                'archivo_nombre' => $nombre,
                'archivo_ruta' => $ruta,
                'formato' => $formato,
                'total_filas' => count($datos['filas']),
                'filas' => $filas,
                'resumen' => $this->contar($filas) + ['ignoradas' => $datos['ignoradas']],
            ]);
        });
    }

    /**
     * Lee el archivo y acomoda cada fila por columna de la plantilla.
     *
     * @return array{columnas: list<Columna>, encabezados: list<string>, filas: list<array{fila: int, valores: array<string, string>, celdas: list<string>}>, ignoradas: list<string>}
     */
    public function leer(User $actor, Importador $importador, string $ruta, string $formato): array
    {
        $leido = LectorArchivo::leer($ruta, $formato);
        $columnas = $importador->columnas();

        $indice = [];
        foreach ($columnas as $columna) {
            foreach ([$columna->clave, $columna->titulo, ...$columna->alias] as $nombre) {
                $indice[Columna::normalizar($nombre)] ??= $columna->clave;
            }
        }

        $posiciones = [];
        $ignoradas = [];
        foreach ($leido['encabezados'] as $i => $encabezado) {
            $clave = $indice[Columna::normalizar($encabezado)] ?? null;
            if ($clave === null || in_array($clave, $posiciones, true)) {
                if ($encabezado !== '') {
                    $ignoradas[] = $encabezado;
                }

                continue;
            }
            $posiciones[$i] = $clave;
        }

        $faltan = array_values(array_filter($columnas, fn (Columna $c) => $c->obligatoria && ! in_array($c->clave, $posiciones, true)));
        if ($faltan !== []) {
            throw new ArchivoNoValido((count($faltan) === 1 ? 'Falta la columna ' : 'Faltan las columnas ')
                .implode(', ', array_map(fn (Columna $c) => '«'.$c->titulo.'»', $faltan))
                .'. El renglón 1 debe llevar los encabezados de la plantilla: descárgala y copia tus datos en ella.');
        }

        $filas = [];
        foreach ($leido['filas'] as $fila) {
            $valores = [];
            foreach ($posiciones as $i => $clave) {
                $valores[$clave] = mb_substr((string) ($fila['celdas'][$i] ?? ''), 0, 500);
            }
            $filas[] = ['fila' => $fila['fila'], 'valores' => $valores];
        }

        return ['columnas' => $columnas, 'filas' => $filas, 'ignoradas' => $ignoradas];
    }

    /**
     * Las filas del archivo de una importación (para la vista previa y el resultado).
     *
     * @return array<int, array<string, string>> renglón => valores por columna
     */
    public function valoresDe(User $actor, Importacion $importacion): array
    {
        $importador = $this->importador($importacion->tipo);
        $ruta = Storage::disk('local')->path($importacion->archivo_ruta);
        if ($importador === null || ! is_file($ruta)) {
            return [];
        }
        try {
            $datos = $this->leer($actor, $importador, $ruta, $importacion->formato);
        } catch (ArchivoNoValido) {
            return [];
        }

        return collect($datos['filas'])->mapWithKeys(fn ($f) => [$f['fila'] => $f['valores']])->all();
    }

    // ------------------------------------------------------------------- Guardar

    /**
     * Guarda de verdad. $existentes: «actualizar» u «omitir» lo que ya existe.
     */
    public function aplicar(User $actor, int $empresaId, Importacion $importacion, string $existentes): Importacion
    {
        $importador = $this->importador($importacion->tipo);
        abort_if($importador === null, 404);
        if (! $importador->actualizable() || ! $actor->can($importador->modulo().'.editar')) {
            $existentes = 'omitir';
        }

        // Una sola vez aunque se toque dos veces el botón
        $tomada = Importacion::whereKey($importacion->id)->where('estado', Importacion::VISTA_PREVIA)->update(['estado' => 'guardando']);
        if ($tomada !== 1) {
            throw ValidationException::withMessages(['archivo' => 'Este archivo ya se guardó (o se está guardando). Revisa el resultado abajo.']);
        }

        try {
            $valores = $this->tenant->conEmpresa($empresaId, fn () => $this->valoresDe($actor, $importacion));
            $filas = collect($valores)->map(fn ($v, $n) => ['fila' => (int) $n, 'valores' => $v])->values()->all();
            $resultado = $this->procesar($actor, $empresaId, $importador, $filas, $existentes, true);
        } catch (\Throwable $e) {
            Importacion::whereKey($importacion->id)->update(['estado' => Importacion::VISTA_PREVIA]);
            throw $e;
        }

        $resumen = $this->contar($resultado);
        $importacion->forceFill([
            'estado' => Importacion::APLICADA,
            'existentes' => $existentes,
            'resultado' => $resultado,
            'resumen' => $resumen + ['ignoradas' => $importacion->resumen['ignoradas'] ?? []],
            'aplicada_en' => now(),
        ])->save();

        // Un evento por importación, con el resumen (cada alta o cambio deja además el suyo)
        $this->auditoria->auditar($actor, $importador->modulo().'.importado', $importacion, null, [
            'archivo' => $importacion->archivo_nombre,
            'filas' => $importacion->total_filas,
            'creadas' => $resumen['creada'],
            'actualizadas' => $resumen['actualizada'],
            'omitidas' => $resumen['omitida'],
            'con_error' => $resumen['error'],
            'ya_existentes' => $existentes,
        ]);

        return $importacion;
    }

    /**
     * Corre cada fila por el servicio del módulo. Vista previa ($guardar
     * false): todo en una transacción que se deshace al final.
     *
     * @param  list<array{fila: int, valores: array<string, string>}>  $filas
     * @return list<array<string, mixed>>
     */
    public function procesar(User $actor, int $empresaId, Importador $importador, array $filas, ?string $existentes, bool $guardar): array
    {
        @set_time_limit(600);
        $estado = ['vistas' => [], 'puedeCrear' => $actor->can($importador->modulo().'.crear'),
            'puedeEditar' => $importador->actualizable() && $actor->can($importador->modulo().'.editar')];

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $importador, $filas, $existentes, $guardar, &$estado) {
            $resultados = [];
            if (! $guardar) {
                DB::beginTransaction();
                try {
                    foreach ($filas as $fila) {
                        $resultados[] = $this->fila($actor, $empresaId, $importador, $fila, null, false, $estado);
                    }
                } finally {
                    DB::rollBack();
                }

                return $resultados;
            }

            foreach (array_chunk($filas, self::LOTE) as $lote) {
                $resultados = [...$resultados, ...DB::transaction(function () use ($actor, $empresaId, $importador, $lote, $existentes, &$estado) {
                    $hechos = [];
                    foreach ($lote as $fila) {
                        $hechos[] = $this->fila($actor, $empresaId, $importador, $fila, $existentes, true, $estado);
                    }

                    return $hechos;
                })];
            }

            return $resultados;
        });
    }

    /**
     * @param  array{fila: int, valores: array<string, string>}  $fila
     * @param  array{vistas: array<string, int>, puedeCrear: bool, puedeEditar: bool}  $estado
     * @return array<string, mixed>
     */
    private function fila(User $actor, int $empresaId, Importador $importador, array $fila, ?string $existentes, bool $guardar, array &$estado): array
    {
        $valores = $fila['valores'];
        $r = ['fila' => $fila['fila'], 'estado' => 'error', 'mensaje' => '', 'errores' => []];
        $clave = $importador->clave($valores);

        if ($clave !== null && isset($estado['vistas'][$clave])) {
            $columna = $importador->columnas()[0]->clave;

            return ['errores' => [$columna => "Repetido en el archivo: es el mismo {$importador->registro()} de la fila {$estado['vistas'][$clave]}."],
                'mensaje' => "Repetido en el archivo (igual a la fila {$estado['vistas'][$clave]})."] + $r;
        }
        if ($clave !== null) {
            $estado['vistas'][$clave] = $fila['fila'];
        }

        try {
            $existente = $importador->existente($valores);
            if ($existente === null) {
                if (! $estado['puedeCrear']) {
                    return ['mensaje' => "No tienes permiso para dar de alta {$this->plural($importador)}: pide a tu administrador el permiso «Crear»."] + $r;
                }
                $this->punto(fn () => $importador->crear($actor, $empresaId, $valores));

                return ['estado' => $guardar ? 'creada' : 'nueva'] + $r;
            }

            $descripcion = $importador->describir($existente);
            $r['estado'] = $guardar ? 'omitida' : 'existe';
            $r['mensaje'] = 'Ya existe'.($descripcion !== '' ? ': '.$descripcion : '').'.';
            if (! $importador->actualizable() || ($guardar && $existentes !== 'actualizar')) {
                return $r;
            }
            $impedimento = ! $estado['puedeEditar']
                ? 'No tienes permiso para editar: se dejará como está.'
                : (! $importador->editable($actor, $existente) ? 'Está fuera de tus sedes o de lo que puedes editar: se dejará como está.' : null);
            if ($impedimento !== null) {
                return $guardar ? ['mensaje' => $r['mensaje'].' '.$impedimento] + $r : ['no_actualiza' => $impedimento] + $r;
            }

            try {
                $this->punto(fn () => $importador->actualizar($actor, $empresaId, $existente, $valores));
            } catch (ValidationException $e) {
                [$errores, $mensaje] = $this->errores($importador, $e);
                if ($guardar) {
                    return ['estado' => 'error', 'errores' => $errores, 'mensaje' => 'No se pudo actualizar: '.$mensaje] + $r;
                }

                return ['no_actualiza' => 'No se puede actualizar: '.$mensaje, 'errores' => $errores] + $r;
            }

            return $guardar ? ['estado' => 'actualizada'] + $r : ['actualizable' => true] + $r;
        } catch (ValidationException $e) {
            [$errores, $mensaje] = $this->errores($importador, $e);

            return ['estado' => 'error', 'errores' => $errores, 'mensaje' => $mensaje] + $r;
        } catch (HttpExceptionInterface|\DomainException $e) {
            return ['estado' => 'error', 'mensaje' => $e->getMessage() ?: 'No tienes permiso para esta fila.'] + $r;
        } catch (QueryException $e) {
            Log::warning('Importación: fila '.$fila['fila'].' no se pudo guardar', ['tipo' => $importador->tipo(), 'error' => $e->getMessage()]);

            return ['estado' => 'error', 'mensaje' => 'Esta fila no se pudo guardar: revisa que no repita un dato que ya tiene otro registro.'] + $r;
        }
    }

    /** Cada fila en su propio punto de guardado: si falla, solo se deshace ella. */
    private function punto(\Closure $accion): mixed
    {
        return DB::transaction($accion);
    }

    /**
     * Errores del servicio repartidos por columna.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function errores(Importador $importador, ValidationException $e): array
    {
        $porColumna = [];
        $mensajes = [];
        foreach ($e->errors() as $campo => $lista) {
            $mensaje = (string) ($lista[0] ?? '');
            $columna = $importador->columnaDeCampo((string) $campo) ?? 'general';
            if (! isset($porColumna[$columna])) {
                // Un motivo por celda (el primero, el más claro)
                $porColumna[$columna] = $mensaje;
                $mensajes[] = $mensaje;
            }
        }

        return [$porColumna, implode(' ', array_unique($mensajes))];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, int>
     */
    public function contar(array $filas): array
    {
        $conteo = ['nueva' => 0, 'existe' => 0, 'error' => 0, 'creada' => 0, 'actualizada' => 0, 'omitida' => 0, 'por_actualizar' => 0];
        foreach ($filas as $f) {
            $conteo[$f['estado']] = ($conteo[$f['estado']] ?? 0) + 1;
            if (! empty($f['actualizable'])) {
                $conteo['por_actualizar']++;
            }
        }

        return $conteo;
    }

    private function plural(Importador $importador): string
    {
        return match ($importador->tipo()) {
            'colaboradores' => 'colaboradores', 'personas' => 'personas', 'proveedores' => 'empresas externas',
            'vehiculos' => 'vehículos', default => 'zonas y edificios',
        };
    }

    // ------------------------------------------------------------------ Resultado

    /**
     * Archivo de resultado: las columnas del archivo más «resultado» y
     * «detalle». Sin permiso de datos personales, CURP, RFC, NSS… salen
     * enmascarados. Se llama con la empresa fijada en el Tenant.
     *
     * @return list<list<string|int>>
     */
    public function renglonesResultado(User $actor, Importacion $importacion): array
    {
        $importador = $this->importador($importacion->tipo);
        if ($importador === null) {
            return [];
        }
        $vePersonales = $this->vePersonales($actor, $importador);
        $columnas = $importador->columnas();
        $resultados = collect($importacion->aplicada() ? $importacion->resultado : $importacion->filas)->keyBy('fila');

        $renglones = [['Fila', ...array_map(fn (Columna $c) => $c->titulo, $columnas), 'resultado', 'detalle']];
        foreach ($this->valoresDe($actor, $importacion) as $numero => $fila) {
            $r = $resultados->get($numero, []);
            $celdas = [];
            foreach ($columnas as $c) {
                $valor = $fila[$c->clave] ?? '';
                $celdas[] = $c->personal && ! $vePersonales ? (string) Colaborador::enmascarar($valor) : $valor;
            }
            $renglones[] = [$numero, ...$celdas, self::ETIQUETAS[$r['estado'] ?? ''] ?? '', trim(($r['mensaje'] ?? '').' '.($r['no_actualiza'] ?? ''))];
        }

        return $renglones;
    }

    /** Cómo se dice cada estado en pantalla y en el archivo de resultado. */
    public const ETIQUETAS = [
        'nueva' => 'Nueva', 'existe' => 'Ya existe', 'error' => 'Con error',
        'creada' => 'Creada', 'actualizada' => 'Actualizada', 'omitida' => 'Omitida (ya existía)',
    ];
}
