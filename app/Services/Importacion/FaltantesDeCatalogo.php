<?php

namespace App\Services\Importacion;

use App\Models\Departamento;
use App\Models\Importacion;
use App\Models\Puesto;
use App\Models\Turno;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 7 · C-43 (A-19): al importar colaboradores, los departamentos,
 * puestos y turnos que el archivo trae y no existen en el catálogo se pueden
 * crear con un clic («Crear los que faltan») en vez de dejar esas filas con
 * error y tener que ir a cada catálogo.
 *
 *  - Departamento: nace para todas las sedes (se ajusta después en Estructura).
 *  - Puesto: nace ligado al departamento si todas las filas que lo usan traen
 *    el mismo departamento.
 *  - Turno: solo si su nombre trae el horario («Matutino 07:00-15:00»,
 *    «07:00 a 15:00»); sin horario no se puede crear y se dice así.
 *
 * Cada catálogo pide su permiso «crear» con alcance de empresa (son de toda
 * la empresa, como en sus pantallas). Debe correr con la empresa de trabajo
 * fijada en el Tenant.
 */
class FaltantesDeCatalogo
{
    /** columna del archivo => [modelo, permiso, nombre en plural] */
    public const CATALOGOS = [
        'departamento' => [Departamento::class, 'departamentos.crear', 'departamentos'],
        'puesto' => [Puesto::class, 'puestos.crear', 'puestos'],
        'turno' => [Turno::class, 'turnos.crear', 'turnos'],
    ];

    public function __construct(
        private readonly AdministradorImportaciones $importaciones,
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /** Solo la importación de colaboradores trae catálogos. */
    public function aplica(Importacion $importacion): bool
    {
        return $importacion->tipo === 'colaboradores' && $importacion->estado === Importacion::VISTA_PREVIA;
    }

    /**
     * Lo que falta en cada catálogo: columna => [nombre como viene en el archivo, …].
     *
     * @return array<string, list<string>>
     */
    public function faltantes(User $actor, Importacion $importacion): array
    {
        if (! $this->aplica($importacion)) {
            return [];
        }
        $valores = $this->importaciones->valoresDe($actor, $importacion);
        $faltan = [];
        foreach (self::CATALOGOS as $columna => [$modelo]) {
            $existentes = $modelo::query()->pluck('nombre')->map(fn ($n) => Columna::normalizar((string) $n))->flip();
            $vistos = [];
            foreach ($valores as $fila) {
                $texto = trim((string) ($fila[$columna] ?? ''));
                $clave = Columna::normalizar($texto);
                if ($texto === '' || isset($existentes[$clave]) || isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;
                $faltan[$columna][] = $texto;
            }
        }

        return $faltan;
    }

    /** ¿Puede crear en ese catálogo? (permiso «crear» con alcance de empresa) */
    public function puede(User $actor, string $columna): bool
    {
        $permiso = self::CATALOGOS[$columna][1];

        return $actor->can($permiso) && $this->autorizador->alcanceDeEmpresa($actor, $permiso);
    }

    /** «Matutino 07:00-15:00» → ['07:00', '15:00']; sin horario, null. */
    public static function horario(string $nombre): ?array
    {
        if (! preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\s*(?:-|–|a)\s*([01]?\d|2[0-3]):([0-5]\d)\b/u', $nombre, $m)) {
            return null;
        }

        return [sprintf('%02d:%s', (int) $m[1], $m[2]), sprintf('%02d:%s', (int) $m[3], $m[4])];
    }

    /**
     * Crea lo que falta y vuelve a revisar el archivo.
     *
     * @return array{creados: array<string, list<string>>, sin_permiso: list<string>, sin_horario: list<string>}
     */
    public function crear(User $actor, int $empresaId, Importacion $importacion): array
    {
        $faltan = $this->faltantes($actor, $importacion);
        $valores = $this->importaciones->valoresDe($actor, $importacion);
        $resultado = ['creados' => [], 'sin_permiso' => [], 'sin_horario' => []];

        DB::transaction(function () use ($actor, $empresaId, $faltan, $valores, $importacion, &$resultado) {
            foreach (['departamento', 'puesto', 'turno'] as $columna) {
                if (($faltan[$columna] ?? []) === []) {
                    continue;
                }
                if (! $this->puede($actor, $columna)) {
                    $resultado['sin_permiso'][] = self::CATALOGOS[$columna][2];

                    continue;
                }
                foreach ($faltan[$columna] as $nombre) {
                    $limpio = mb_substr(trim((string) preg_replace('/\s+/', ' ', $nombre)), 0, 100);
                    $modelo = match ($columna) {
                        'departamento' => $this->departamento($empresaId, $limpio),
                        'puesto' => $this->puesto($empresaId, $limpio, $valores),
                        'turno' => $this->turno($empresaId, $limpio),
                    };
                    if ($modelo === null) {
                        $resultado['sin_horario'][] = $limpio;

                        continue;
                    }
                    $this->auditoria->auditar($actor, self::CATALOGOS[$columna][2].'.creado', $modelo, null,
                        ['nombre' => $modelo->nombre, 'origen' => 'importacion', 'importacion_id' => $importacion->id]);
                    $resultado['creados'][$columna][] = $modelo->nombre;
                }
            }
        });

        // La vista previa se vuelve a calcular con los catálogos completos
        $importador = $this->importaciones->importador($importacion->tipo);
        $filas = collect($valores)->map(fn ($v, $n) => ['fila' => (int) $n, 'valores' => $v])->values()->all();
        $revisadas = $this->importaciones->procesar($actor, $empresaId, $importador, $filas, null, false);
        $importacion->forceFill([
            'filas' => $revisadas,
            'resumen' => $this->importaciones->contar($revisadas) + ['ignoradas' => $importacion->resumen['ignoradas'] ?? []],
        ])->save();

        return $resultado;
    }

    private function departamento(int $empresaId, string $nombre): Departamento
    {
        return Departamento::create(['empresa_id' => $empresaId, 'nombre' => $nombre, 'todas_las_sedes' => true, 'activo' => true]);
    }

    /**
     * @param  array<int|string, array<string, string>>  $valores
     */
    private function puesto(int $empresaId, string $nombre, array $valores): Puesto
    {
        $puesto = Puesto::create(['empresa_id' => $empresaId, 'nombre' => $nombre, 'activo' => true]);
        $deptos = collect($valores)->filter(fn ($f) => Columna::normalizar((string) ($f['puesto'] ?? '')) === Columna::normalizar($nombre))
            ->map(fn ($f) => Columna::normalizar((string) ($f['departamento'] ?? '')))->unique()->values();
        if ($deptos->count() === 1 && $deptos->first() !== '') {
            $id = Departamento::query()->get(['id', 'nombre'])->first(fn ($d) => Columna::normalizar((string) $d->nombre) === $deptos->first())?->id;
            if ($id !== null) {
                $puesto->departamentos()->sync([$id]);
            }
        }

        return $puesto;
    }

    private function turno(int $empresaId, string $nombre): ?Turno
    {
        $horas = self::horario($nombre);
        if ($horas === null) {
            return null;
        }

        return Turno::create(['empresa_id' => $empresaId, 'nombre' => $nombre, 'hora_inicio' => $horas[0], 'hora_fin' => $horas[1], 'todas_las_sedes' => true, 'activo' => true]);
    }
}
