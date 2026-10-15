<?php

namespace App\Services\Traslado;

use App\Http\Controllers\Administracion\ConfiguracionController;
use App\Models\Auditoria;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Respaldos\Respaldos;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Paquetes de traslado y de exportación de una empresa (docs/tecnico/traslado.md).
 *
 * Un paquete es un ZIP con un manifest.json (versión, última actualización de
 * la base, conteos por tabla y la lista de archivos con su SHA-256) y un
 * LEEME.txt. Se arma por partes —un «trabajo» con su estado en
 * storage/app/private/traslados/trabajos/<id>.json— para no pasarse del tiempo
 * de ejecución del hospedaje compartido:
 *   - desde la pantalla, cada petición avanza unos segundos (la página pide la
 *     siguiente parte sola; sin JavaScript hay un botón «Continuar»);
 *   - la tarea programada (plataforma:traslado --si-toca, en proceso, sin exec)
 *     termina lo que quede aunque se cierre el navegador.
 *
 * Tipos de trabajo: «traslado» (toda la plataforma: base + archivos),
 * «empresa» (todo lo de una empresa: JSON por tabla, CSV y sus archivos) y
 * «verificacion» (revisa la integridad de un paquete sin restaurarlo).
 *
 * Seguridad: todo vive en storage/app/private (fuera de la carpeta pública),
 * archivos 0600 y carpetas 0700; se conservan pocos paquetes.
 */
class Paquetes
{
    public const FORMATO = 'paquete-plataforma';

    public const VERSION_FORMATO = 1;

    /** Paquetes de traslado que se conservan (y por empresa, sus exportaciones). */
    public const CONSERVAR = 3;

    /** Segundos por parte desde la pantalla (el hospedaje suele cortar a los 30). */
    public const SEGUNDOS_WEB = 15;

    /** Intentos de una parte que se cortó antes de dejarla a la tarea programada. */
    public const INTENTOS_WEB = 2;

    /** Días que se conservan los resultados de verificación y los trabajos que fallaron. */
    public const DIAS_TRABAJOS = 7;

    /** Margen de disco libre que se exige además del tamaño estimado. */
    public const MARGEN_DISCO = 52428800;

    public const CLAVE_AJUSTES = 'traslado';

    /** Carpetas de storage/app/private que NO viajan (respaldos y los propios paquetes). */
    public const EXCLUIR_PRIVADO = ['respaldos', 'traslados'];

    public function __construct(
        private readonly Respaldos $respaldos,
        private readonly ExportacionEmpresa $exportacion,
        private readonly VerificadorPaquete $verificador,
    ) {}

    // ------------------------------------------------------------------ rutas

    public function carpeta(string $sub = ''): string
    {
        $carpeta = storage_path('app/private/traslados'.($sub !== '' ? '/'.$sub : ''));
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0700, true);
        }
        @chmod($carpeta, 0700);

        return $carpeta;
    }

    /**
     * Solo nombres que este servicio genera (nada de rutas ni «..»).
     */
    public function ruta(string $archivo): ?string
    {
        if (! preg_match('/^(traslado_[a-z0-9]+|empresa_\d+)_\d{8}_\d{6}\.zip$/', $archivo)) {
            return null;
        }
        $ruta = $this->carpeta().'/'.$archivo;

        return is_file($ruta) ? $ruta : null;
    }

    /**
     * Paquetes terminados. $empresaId null = paquetes de traslado; un id = las exportaciones de esa empresa.
     *
     * @return Collection<int, array{archivo: string, ruta: string, bytes: int, fecha: Carbon, tipo: string, empresa_id: ?int, datos: array<string, mixed>}>
     */
    public function listar(?int $empresaId = null): Collection
    {
        $patron = $empresaId === null ? 'traslado_*.zip' : "empresa_{$empresaId}_*.zip";

        return collect(glob($this->carpeta().'/'.$patron) ?: [])
            ->filter(fn (string $ruta) => $this->ruta(basename($ruta)) !== null)
            ->map(function (string $ruta) use ($empresaId) {
                preg_match('/_(\d{8}_\d{6})\.zip$/', $ruta, $m);
                $datos = json_decode((string) @file_get_contents($ruta.'.json'), true);

                return [
                    'archivo' => basename($ruta),
                    'ruta' => $ruta,
                    'bytes' => (int) filesize($ruta),
                    'fecha' => Carbon::createFromFormat('Ymd_His', $m[1], 'UTC'),
                    'tipo' => $empresaId === null ? 'traslado' : 'empresa',
                    'empresa_id' => $empresaId,
                    'datos' => is_array($datos) ? $datos : [],
                ];
            })
            ->sortByDesc('fecha')->values();
    }

    public function borrar(string $archivo): bool
    {
        $ruta = $this->ruta($archivo);
        if ($ruta === null) {
            return false;
        }
        @unlink($ruta);
        @unlink($ruta.'.json');

        return true;
    }

    /**
     * Conserva los más recientes (traslado: 3; cada empresa: 3).
     */
    public function podar(?int $empresaId = null): int
    {
        $borrados = 0;
        foreach ($this->listar($empresaId)->slice(self::CONSERVAR) as $p) {
            $this->borrar($p['archivo']) && $borrados++;
        }

        // Trabajos viejos (verificaciones y los que fallaron) y sus restos
        $limite = time() - self::DIAS_TRABAJOS * 86400;
        foreach (glob($this->carpeta('trabajos').'/*.json') ?: [] as $estado) {
            $t = json_decode((string) @file_get_contents($estado), true);
            if (! is_array($t) || (($t['estado'] ?? '') !== 'en_proceso' && filemtime($estado) < $limite)) {
                $this->limpiarRestos(is_array($t) ? $t : ['id' => basename($estado, '.json')]);
                @unlink($estado);
            }
        }

        return $borrados;
    }

    // --------------------------------------------------------------- ajustes

    /**
     * @return array{semanal: bool}
     */
    public function ajustes(): array
    {
        $valor = ConfiguracionPlataforma::where('clave', self::CLAVE_AJUSTES)->value('valor');
        $valor = is_array($valor) ? $valor : (is_string($valor) ? (json_decode($valor, true) ?: []) : []);

        return ['semanal' => (bool) ($valor['semanal'] ?? false)];
    }

    public function registroAjustes(): ConfiguracionPlataforma
    {
        return ConfiguracionPlataforma::firstOrCreate(['clave' => self::CLAVE_AJUSTES], ['valor' => ['semanal' => false]]);
    }

    /**
     * ¿Toca el paquete automático semanal? Encendido en la pantalla, después
     * de las 3:00, sin otro en proceso y sin uno de los últimos 7 días.
     */
    public function tocaSemanal(?Carbon $ahora = null): bool
    {
        if (! $this->ajustes()['semanal']) {
            return false;
        }
        $ahora ??= now();
        if ($ahora->copy()->setTimezone(HoraLocal::ZONA_PLATAFORMA)->hour < 3 || $this->activo('traslado') !== null) {
            return false;
        }
        $ultimo = $this->listar()->first();

        return $ultimo === null || $ultimo['fecha']->lt($ahora->copy()->subDays(7)->addHour());
    }

    // -------------------------------------------------------------- trabajos

    /**
     * @return array<string, mixed>|null
     */
    public function trabajo(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $id)) {
            return null;
        }
        $t = json_decode((string) @file_get_contents($this->carpeta('trabajos')."/{$id}.json"), true);

        return is_array($t) ? $t : null;
    }

    /**
     * El trabajo en proceso de ese tipo (y empresa), si hay.
     *
     * @return array<string, mixed>|null
     */
    public function activo(string $tipo, ?int $empresaId = null): ?array
    {
        return $this->trabajos($tipo, $empresaId)->first(fn ($t) => $t['estado'] === 'en_proceso');
    }

    /**
     * Trabajos recientes (sin sus listas internas), del más nuevo al más viejo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trabajos(?string $tipo = null, ?int $empresaId = null): Collection
    {
        return collect(glob($this->carpeta('trabajos').'/*.json') ?: [])
            ->map(fn ($ruta) => json_decode((string) @file_get_contents($ruta), true))
            ->filter(fn ($t) => is_array($t) && ($tipo === null || $t['tipo'] === $tipo) && ($empresaId === null || (int) ($t['empresa_id'] ?? 0) === $empresaId))
            ->map(fn ($t) => self::resumen($t))
            ->sortByDesc('inicio')->values();
    }

    /**
     * Lo que la pantalla necesita de un trabajo (sin listas de tareas ni índices).
     *
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    public static function resumen(array $t): array
    {
        $total = max(1, count($t['tareas'] ?? []));
        $hechas = min($total, (int) ($t['indice'] ?? 0));
        if (($t['tipo'] ?? '') === 'verificacion') {
            $total = max(1, (int) ($t['resultado']['total'] ?? 1));
            $hechas = min($total, (int) ($t['resultado']['revisados'] ?? 0));
        }

        return [
            'id' => $t['id'],
            'tipo' => $t['tipo'],
            'motivo' => $t['motivo'] ?? 'manual',
            'estado' => $t['estado'],
            'inicio' => $t['inicio'],
            'fin' => $t['fin'] ?? null,
            'empresa_id' => $t['empresa_id'] ?? null,
            'empresa_nombre' => $t['empresa_nombre'] ?? null,
            'creado_por' => $t['creado_por'] ?? null,
            'creado_por_nombre' => $t['creado_por_nombre'] ?? null,
            'paquete' => $t['paquete'] ?? null,
            'nombre' => $t['nombre'] ?? null,
            'bytes' => (int) ($t['offset'] ?? 0),
            'avance' => $t['estado'] === 'listo' ? 100 : (int) floor($hechas * 100 / $total),
            'paso' => $t['paso'] ?? null,
            'error' => $t['error'] ?? null,
            'aviso' => $t['aviso'] ?? null,
            'resultado' => $t['resultado'] ?? null,
        ];
    }

    /**
     * Arranca el paquete de traslado completo (o devuelve el que ya está en proceso).
     *
     * @return array<string, mixed>
     */
    public function iniciarTraslado(?User $actor, string $motivo = 'manual'): array
    {
        if ($activo = $this->activo('traslado')) {
            return $activo;
        }
        $estimado = $this->tamanoArchivos() + (int) ($this->respaldos->listar()->first()['bytes'] ?? 0);
        $this->exigirDisco($estimado);

        return $this->crearTrabajo('traslado', $actor, [
            'motivo' => $motivo,
            'tareas' => [['base'], ['listar'], ['cierre']],
        ]);
    }

    /**
     * Arranca la exportación de una empresa (o devuelve la que ya está en proceso).
     *
     * @return array<string, mixed>
     */
    public function iniciarEmpresa(User $actor, Empresa $empresa): array
    {
        if ($activo = $this->activo('empresa', $empresa->id)) {
            return $activo;
        }
        $this->exigirDisco(0);

        return $this->crearTrabajo('empresa', $actor, [
            'empresa_id' => $empresa->id,
            'empresa_nombre' => $empresa->nombre_comercial,
            'tareas' => [['plan'], ['cierre']],
        ]);
    }

    /**
     * Verifica un paquete (uno de la lista o uno subido) sin restaurarlo.
     *
     * @return array<string, mixed>
     */
    public function iniciarVerificacion(User $actor, string $ruta, string $nombre, bool $borrarAlTerminar): array
    {
        return $this->crearTrabajo('verificacion', $actor, [
            'origen' => $ruta,
            'nombre' => $nombre,
            'borrar_origen' => $borrarAlTerminar,
            'tareas' => [['verificar']],
            'resultado' => ['revisados' => 0, 'total' => 0, 'problemas' => [], 'avisos' => [], 'valido' => null],
        ]);
    }

    /**
     * Avanza un trabajo durante $segundos como máximo. Devuelve su resumen.
     * $web = true: si una parte se cortó varias veces (archivo o tabla muy
     * grande para el tiempo de la página), la deja a la tarea programada.
     *
     * @return array<string, mixed>
     */
    public function avanzar(string $id, float $segundos = self::SEGUNDOS_WEB, bool $web = true): array
    {
        $ruta = $this->carpeta('trabajos')."/{$id}.json";
        $candado = @fopen($ruta.'.candado', 'c');
        if ($candado === false || ! flock($candado, LOCK_EX | LOCK_NB)) {
            // Otra petición (o la tarea programada) ya está avanzando este trabajo
            $t = $this->trabajo($id) ?? throw new RuntimeException('El trabajo ya no existe.');

            return self::resumen($t);
        }

        try {
            $t = $this->trabajo($id) ?? throw new RuntimeException('El trabajo ya no existe.');
            if ($t['estado'] !== 'en_proceso') {
                return self::resumen($t);
            }
            // Intentar ampliar el tiempo de la petición (algunos hospedajes lo permiten); sin límite (consola) no se toca
            $maximo = (int) ini_get('max_execution_time');
            if ($maximo > 0 && $maximo < (int) ceil($segundos) + 60) {
                @set_time_limit((int) ceil($segundos) + 60);
            }
            $limite = microtime(true) + $segundos;
            $marca = $ruta.'.paso';

            // ¿La parte anterior se cortó a la mitad? (la marca sigue ahí)
            if (is_file($marca)) {
                $t['intentos'] = (int) ($t['intentos'] ?? 0) + 1;
                $this->guardar($t); // se anota ya: si esta parte también se corta, el intento cuenta
                if ($web && $t['intentos'] > self::INTENTOS_WEB) {
                    $t['aviso'] = 'La parte que sigue es grande para hacerse desde la pantalla; la tarea automática la terminará en los próximos minutos.';
                    $this->guardar($t);

                    return self::resumen($t);
                }
            }

            $zip = null;
            try {
                $primera = true; // al menos una parte por petición, aunque el tiempo sea muy corto
                while ($t['estado'] === 'en_proceso' && ($primera || microtime(true) < $limite)) {
                    $primera = false;
                    $tarea = $t['tareas'][$t['indice']] ?? null;
                    if ($tarea === null) {
                        break;
                    }
                    file_put_contents($marca, (string) $t['indice']);
                    if ($t['tipo'] !== 'verificacion' && $zip === null && $tarea[0] !== 'base' && $tarea[0] !== 'plan' && $tarea[0] !== 'listar') {
                        $zip = new ZipPorPartes($this->carpeta().'/'.$t['parcial'], (int) $t['offset'], $t['centrales']);
                    }
                    $t = $this->ejecutar($t, $tarea, $zip, $limite);
                    if ($zip !== null && $t['estado'] === 'en_proceso') {
                        $t['offset'] = $zip->offset();
                        $t['centrales'] = $zip->centrales();
                    }
                    @unlink($marca);
                    $t['intentos'] = 0;
                    $t['aviso'] = null;
                }
                $zip?->soltar();
            } catch (Throwable $e) {
                $zip?->soltar();
                @unlink($marca);
                report($e);
                $t['estado'] = 'error';
                $t['error'] = $e->getMessage();
                $t['fin'] = now()->utc()->toIso8601String();
                $this->limpiarRestos($t);
            }
            $this->guardar($t);

            return self::resumen($t);
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
    }

    /**
     * Avanza hasta terminar (consola y pruebas).
     *
     * @return array<string, mixed>
     */
    public function completar(string $id): array
    {
        do {
            $r = $this->avanzar($id, 300, false);
        } while ($r['estado'] === 'en_proceso');

        return $r;
    }

    // ----------------------------------------------------------- las tareas

    /**
     * @param  array<string, mixed>  $t
     * @param  list<mixed>  $tarea
     * @return array<string, mixed>
     */
    private function ejecutar(array $t, array $tarea, ?ZipPorPartes $zip, float $limite): array
    {
        switch ($tarea[0]) {
            case 'base':
                // Respaldo de la base con el mismo servicio de Respaldos (un .sql.gz importable)
                $t['paso'] = 'Respaldando la base de datos';
                $r = $this->respaldos->crear('traslado');
                $conteos = $this->respaldos->ultimosConteos();
                $zip = new ZipPorPartes($this->carpeta().'/'.$t['parcial'], (int) $t['offset'], $t['centrales']);
                $meta = $zip->agregarArchivo('base/base.sql.gz', $r['ruta']);
                $zip->soltar();
                $t['offset'] = $zip->offset();
                $t['centrales'] = $zip->centrales();
                @unlink($r['ruta']); // ya viaja dentro del paquete; no se duplica en Respaldos
                $t['archivos'][] = ['ruta' => 'base/base.sql.gz', 'bytes' => $meta['bytes'], 'sha256' => $meta['sha256']];
                $t['base'] = ['ruta' => 'base/base.sql.gz', 'motor' => DB::connection()->getDriverName(), 'tablas' => $conteos, 'filas' => array_sum($conteos)];
                $t['plataforma'] = self::plataforma();
                break;

            case 'listar':
                $t['paso'] = 'Revisando los archivos guardados';
                $nuevas = [];
                foreach ($this->archivosDeLaPlataforma() as [$disco, $relativa]) {
                    $nuevas[] = ['archivo', $disco, $relativa];
                }
                array_splice($t['tareas'], $t['indice'] + 1, 0, $nuevas);
                break;

            case 'plan':
                $t['paso'] = 'Preparando la lista de datos de la empresa';
                $t['plataforma'] = self::plataforma();
                $nuevas = $this->exportacion->plan((int) $t['empresa_id']);
                array_splice($t['tareas'], $t['indice'] + 1, 0, $nuevas);
                break;

            case 'tabla':
                $t['paso'] = 'Exportando '.$tarea[1];
                $temporal = $this->carpeta('temporal')."/{$t['id']}.json";
                $r = $this->exportacion->tablaJson((int) $t['empresa_id'], $tarea[1], $tarea[2], $temporal);
                $meta = $zip->agregarArchivo("datos/{$tarea[1]}.json", $temporal, true);
                @unlink($temporal);
                $t['archivos'][] = ['ruta' => "datos/{$tarea[1]}.json", 'bytes' => $meta['bytes'], 'sha256' => $meta['sha256']];
                $t['tablas'][$tarea[1]] = $r['filas'];
                foreach ($r['archivos'] as $clave) {
                    $t['por_copiar'][$clave] = true;
                }
                break;

            case 'csv':
                $t['paso'] = 'Preparando la hoja '.$tarea[1];
                $temporal = $this->carpeta('temporal')."/{$t['id']}.csv";
                $this->exportacion->csv((int) $t['empresa_id'], $tarea[1], $temporal);
                $meta = $zip->agregarArchivo("hojas/{$tarea[1]}.csv", $temporal, true);
                @unlink($temporal);
                $t['archivos'][] = ['ruta' => "hojas/{$tarea[1]}.csv", 'bytes' => $meta['bytes'], 'sha256' => $meta['sha256']];
                break;

            case 'archivos-empresa':
                $nuevas = [];
                foreach (array_keys($t['por_copiar'] ?? []) as $clave) {
                    [$disco, $relativa] = explode(':', $clave, 2);
                    $nuevas[] = ['archivo', $disco, $relativa];
                }
                unset($t['por_copiar']);
                array_splice($t['tareas'], $t['indice'] + 1, 0, $nuevas);
                break;

            case 'archivo':
                $t['paso'] = 'Copiando archivos';
                $origen = self::raizDisco($tarea[1]).'/'.$tarea[2];
                if (! is_file($origen)) {
                    $t['omitidos'][] = "{$tarea[1]}/{$tarea[2]}"; // se borró entre la lista y la copia
                    break;
                }
                $nombre = "archivos/{$tarea[1]}/{$tarea[2]}";
                $meta = $zip->agregarArchivo($nombre, $origen);
                $t['archivos'][] = ['ruta' => $nombre, 'bytes' => $meta['bytes'], 'sha256' => $meta['sha256']];
                break;

            case 'cierre':
                $t['paso'] = 'Cerrando el paquete';
                $manifest = $this->manifest($t);
                $zip->agregarTexto('LEEME.txt', $this->leeme($t['tipo']));
                $zip->agregarTexto('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $zip->cerrar();
                $t['offset'] = $zip->offset();
                $zip = null;
                $final = $this->carpeta().'/'.$t['paquete'];
                rename($this->carpeta().'/'.$t['parcial'], $final);
                @chmod($final, 0600);
                file_put_contents($final.'.json', json_encode([
                    'creado_por' => $t['creado_por'], 'creado_por_nombre' => $t['creado_por_nombre'], 'motivo' => $t['motivo'] ?? 'manual',
                    'archivos' => count($t['archivos']), 'filas' => $t['tipo'] === 'traslado' ? ($t['base']['filas'] ?? 0) : array_sum($t['tablas'] ?? []),
                    'empresa_id' => $t['empresa_id'] ?? null, 'empresa_nombre' => $t['empresa_nombre'] ?? null,
                ], JSON_UNESCAPED_UNICODE));
                @chmod($final.'.json', 0600);
                $t['estado'] = 'listo';
                $t['fin'] = now()->utc()->toIso8601String();
                $t['paso'] = null;
                // Ya no hacen falta las listas internas
                $t['centrales'] = [];
                $t['archivos_total'] = count($t['archivos']);
                $t['archivos'] = [];
                $t['tareas'] = array_fill(0, count($t['tareas']), 0);
                $this->auditarFin($t, (int) filesize($final));
                $this->podar($t['tipo'] === 'empresa' ? (int) $t['empresa_id'] : null);
                break;

            case 'verificar':
                $t['paso'] = 'Revisando el paquete';
                $t['resultado'] = $this->verificador->avanzar($t['origen'], $t['resultado'], $limite);
                if ($t['resultado']['valido'] === null) {
                    return $t; // falta: la siguiente parte sigue donde se quedó
                }
                $t['estado'] = 'listo';
                $t['fin'] = now()->utc()->toIso8601String();
                $t['paso'] = null;
                if ($t['borrar_origen'] ?? false) {
                    @unlink($t['origen']);
                }
                $this->auditar($t['creado_por'] ?? null, 'configuracion.traslado_verificado', $this->registroAjustes(), [
                    'paquete' => $t['nombre'], 'valido' => $t['resultado']['valido'], 'revisados' => $t['resultado']['revisados'],
                    'problemas' => array_slice($t['resultado']['problemas'], 0, 10),
                ]);
                break;

            default:
                throw new RuntimeException('Parte desconocida del paquete.');
        }
        $t['indice']++;

        return $t;
    }

    // ------------------------------------------------------------- apoyo

    /**
     * Versión de la plataforma y última actualización aplicada a la base.
     *
     * @return array{version: string, ultima_migracion: ?string, migraciones: int, motor: string}
     */
    public static function plataforma(): array
    {
        $migraciones = Schema::hasTable('migrations') ? DB::table('migrations') : null;

        return [
            'version' => ConfiguracionController::versionInstalada()['numero'] ?? 'sin número',
            'ultima_migracion' => $migraciones?->orderByDesc('id')->value('migration'),
            'migraciones' => $migraciones ? (int) DB::table('migrations')->count() : 0,
            'motor' => DB::connection()->getDriverName(),
        ];
    }

    public static function raizDisco(string $disco): string
    {
        return match ($disco) {
            'private' => storage_path('app/private'),
            'public' => storage_path('app/public'),
            default => throw new RuntimeException('Disco no válido.'),
        };
    }

    /**
     * Todos los archivos que la plataforma guarda: storage/app/private (menos
     * respaldos y paquetes) y storage/app/public. No viajan caché, sesiones,
     * vistas compiladas ni bitácoras (están fuera de storage/app).
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    public function archivosDeLaPlataforma(): \Generator
    {
        foreach (['private', 'public'] as $disco) {
            $raiz = self::raizDisco($disco);
            if (! is_dir($raiz)) {
                continue;
            }
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS));
            $lista = [];
            foreach ($iterador as $archivo) {
                if (! $archivo->isFile() || $archivo->isLink()) {
                    continue;
                }
                $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));
                $primera = explode('/', $relativa)[0];
                if (($disco === 'private' && in_array($primera, self::EXCLUIR_PRIVADO, true))
                    || $archivo->getFilename() === '.gitignore' || str_ends_with($relativa, '.parcial')
                    || ! ZipPorPartes::nombreSeguro($relativa)) {
                    continue;
                }
                $lista[] = $relativa;
            }
            sort($lista, SORT_STRING);
            foreach ($lista as $relativa) {
                yield [$disco, $relativa];
            }
        }
    }

    public function tamanoArchivos(): int
    {
        $total = 0;
        foreach ($this->archivosDeLaPlataforma() as [$disco, $relativa]) {
            $total += (int) @filesize(self::raizDisco($disco).'/'.$relativa);
        }

        return $total;
    }

    public static function legible(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 2).' GB',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            default => number_format($bytes / 1024, 1).' KB',
        };
    }

    /**
     * Seguridad del servidor: no arrancar si el disco no alcanza (un disco lleno tira toda la plataforma).
     */
    private function exigirDisco(int $estimado): void
    {
        $libre = @disk_free_space($this->carpeta());
        if ($libre !== false && $libre < $estimado + self::MARGEN_DISCO) {
            throw new RuntimeException(sprintf('No hay espacio suficiente en el servidor: el paquete ocuparía cerca de %s y quedan %s libres. Borra paquetes anteriores o pide más espacio a tu proveedor de hospedaje.',
                self::legible($estimado), self::legible((int) $libre)));
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function crearTrabajo(string $tipo, ?User $actor, array $extra): array
    {
        $this->podar();
        $id = bin2hex(random_bytes(8));
        $momento = now()->utc()->format('Ymd_His');
        $ambiente = preg_replace('/[^a-z0-9]/', '', strtolower((string) config('app.env'))) ?: 'local';
        $paquete = $tipo === 'empresa' ? "empresa_{$extra['empresa_id']}_{$momento}.zip" : "traslado_{$ambiente}_{$momento}.zip";
        // Dos en el mismo segundo no se pisan
        while ($tipo !== 'verificacion' && file_exists($this->carpeta().'/'.$paquete)) {
            $paquete = preg_replace_callback('/_(\d{8}_\d{6})\.zip$/', fn ($m) => '_'.Carbon::createFromFormat('Ymd_His', $m[1], 'UTC')->addSecond()->format('Ymd_His').'.zip', $paquete);
        }

        $t = array_merge([
            'id' => $id,
            'tipo' => $tipo,
            'motivo' => 'manual',
            'estado' => 'en_proceso',
            'inicio' => now()->utc()->toIso8601String(),
            'fin' => null,
            'creado_por' => $actor?->id,
            'creado_por_nombre' => $actor?->name ?? (($extra['motivo'] ?? '') === 'consola' ? 'Consola del servidor' : 'Tarea automática'),
            'empresa_id' => null,
            'empresa_nombre' => null,
            'paquete' => $tipo === 'verificacion' ? null : $paquete,
            'parcial' => $tipo === 'verificacion' ? null : $paquete.'.parcial',
            'offset' => 0,
            'centrales' => [],
            'tareas' => [],
            'indice' => 0,
            'intentos' => 0,
            'archivos' => [],
            'tablas' => [],
            'error' => null,
            'aviso' => null,
            'paso' => 'En espera',
        ], $extra);
        $this->guardar($t);

        return self::resumen($t);
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function guardar(array $t): void
    {
        $ruta = $this->carpeta('trabajos')."/{$t['id']}.json";
        file_put_contents($ruta.'.tmp', json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($ruta.'.tmp', 0600);
        rename($ruta.'.tmp', $ruta);
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function limpiarRestos(array $t): void
    {
        if (! empty($t['parcial'])) {
            @unlink($this->carpeta().'/'.$t['parcial']);
        }
        foreach (glob($this->carpeta('temporal').'/'.$t['id'].'.*') ?: [] as $f) {
            @unlink($f);
        }
        $base = $this->carpeta('trabajos').'/'.$t['id'].'.json';
        @unlink($base.'.paso');
        @unlink($base.'.candado');
        if (($t['tipo'] ?? '') === 'verificacion' && ($t['borrar_origen'] ?? false) && ! empty($t['origen'])) {
            @unlink($t['origen']);
        }
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function manifest(array $t): array
    {
        $m = [
            'formato' => self::FORMATO,
            'version_formato' => self::VERSION_FORMATO,
            'tipo' => $t['tipo'],
            'generado_en' => now()->utc()->toIso8601String(),
            'generado_por' => $t['creado_por_nombre'],
            'plataforma' => $t['plataforma'] ?? self::plataforma(),
        ];
        if ($t['tipo'] === 'traslado') {
            $m['base'] = $t['base'];
        } else {
            $empresa = DB::table('empresas')->where('id', $t['empresa_id'])->first(['id', 'nombre_comercial', 'razon_social', 'rfc']);
            $m['empresa'] = (array) $empresa;
            $m['tablas'] = $t['tablas'];
            $m['no_incluye'] = ExportacionEmpresa::NO_INCLUYE;
        }
        $m['archivos'] = $t['archivos'];
        $m['totales'] = ['archivos' => count($t['archivos']), 'bytes' => array_sum(array_column($t['archivos'], 'bytes'))];
        if (! empty($t['omitidos'])) {
            $m['omitidos'] = $t['omitidos'];
        }

        return $m;
    }

    private function leeme(string $tipo): string
    {
        $comun = "Este paquete contiene datos personales. Guárdalo en un lugar seguro, no lo envíes por correo\n"
            ."ni lo compartas en carpetas públicas, y bórralo cuando ya no lo necesites.\n\n"
            ."Contenido:\n"
            ."  manifest.json  Lista de todo lo que trae el paquete, con su huella SHA-256 para comprobar\n"
            ."                 que nada cambió, la versión de la plataforma y cuántos registros hay por tabla.\n";

        if ($tipo === 'empresa') {
            return "EXPORTACIÓN DE UNA EMPRESA\n==========================\n\n".$comun
                ."  datos/         Un archivo JSON por tabla con todos los registros de la empresa.\n"
                ."  hojas/         Archivos de Excel (CSV) con lo principal: sedes, colaboradores, usuarios,\n"
                ."                 padrones y configuración. Ábrelos con Excel o con cualquier hoja de cálculo.\n"
                ."  archivos/      Fotos, identificaciones, documentos y firmas de la empresa.\n\n"
                ."No incluye contraseñas, enlaces de acceso ni sesiones.\n"
                ."Este paquete es para consulta o para entregar al cliente; todavía no se puede importar\n"
                ."en otra instalación.\n";
        }

        return "PAQUETE DE TRASLADO\n===================\n\n".$comun
            ."  base/base.sql.gz   Respaldo completo de la base de datos.\n"
            ."  archivos/private/  Archivos privados (fotos, identificaciones, documentos, firmas, evidencias).\n"
            ."  archivos/public/   Archivos públicos (logotipos e imágenes de la identidad).\n\n"
            ."Cómo restaurar en un servidor nuevo\n"
            ."-----------------------------------\n"
            ."1. Instala en el servidor nuevo la MISMA versión de la plataforma que indica manifest.json\n"
            ."   (plataforma.version) y configura su archivo .env con una base de datos VACÍA.\n"
            ."2. Sube este paquete al servidor, fuera de la carpeta pública del sitio.\n"
            ."3. Desde la terminal del panel de tu hospedaje, en la carpeta de la plataforma, ejecuta:\n"
            ."     php artisan plataforma:restaurar-traslado RUTA/DEL/PAQUETE.zip\n"
            ."   Revisa el paquete (huellas SHA-256), copia los archivos a su lugar y te dice cómo importar\n"
            ."   la base. Para que importe también la base (solo si está vacía), agrega --importar-base.\n"
            ."4. Si prefieres importar la base por tu cuenta, usa el archivo base/base.sql.gz con la\n"
            ."   herramienta de bases de datos de tu hospedaje o pídeselo a tu proveedor.\n"
            ."5. Al terminar, entra con tu cuenta de Super Administrador y revisa que estén tus empresas,\n"
            ."   sedes, usuarios y fotos. Los conteos por tabla del manifest deben coincidir.\n\n"
            ."Si algo no coincide, no borres el servidor anterior hasta resolverlo.\n";
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function auditarFin(array $t, int $bytes): void
    {
        if ($t['tipo'] === 'empresa') {
            $empresa = Empresa::find($t['empresa_id']);
            if ($empresa !== null) {
                $this->auditar($t['creado_por'], 'empresas.exportada', $empresa, [
                    'paquete' => $t['paquete'], 'bytes' => $bytes, 'archivos' => $t['archivos_total'], 'tablas' => count($t['tablas'] ?? []),
                ]);
            }

            return;
        }
        $this->auditar($t['creado_por'], 'configuracion.traslado_generado', $this->registroAjustes(), [
            'paquete' => $t['paquete'], 'motivo' => $t['motivo'] ?? 'manual', 'bytes' => $bytes, 'archivos' => $t['archivos_total'],
        ]);
    }

    /**
     * Bitácora de auditoría (también desde la tarea programada, sin usuario).
     *
     * @param  array<string, mixed>  $despues
     */
    public function auditar(?int $usuarioId, string $evento, Model $sujeto, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $sujeto->getAttributes()['empresa_id'] ?? ($sujeto instanceof Empresa ? $sujeto->id : null),
            'user_id' => $usuarioId,
            'evento' => $evento,
            'auditable_type' => $sujeto::class,
            'auditable_id' => $sujeto->getKey(),
            'antes' => null,
            'despues' => $despues,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
