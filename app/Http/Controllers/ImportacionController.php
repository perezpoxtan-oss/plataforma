<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Importacion;
use App\Services\Importacion\AdministradorImportaciones;
use App\Services\Importacion\Columna;
use App\Services\Importacion\FaltantesDeCatalogo;
use App\Services\Importacion\Importador;
use App\Services\Importacion\ImportadorEstructura;
use App\Support\Csv;
use App\Support\Espacios\Etiquetas;
use App\Support\Importacion\EscritorXlsx;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Importar desde Excel» (docs/tecnico/importacion.md): plantilla, subir el
 * archivo, vista previa (sin guardar nada) y guardar. Cada pantalla exige
 * «modulo.importar» del módulo al que se importa.
 */
class ImportacionController extends Controller
{
    /** Filas por página en la vista previa. */
    private const POR_PAGINA = 200;

    public function __construct(
        private readonly AdministradorImportaciones $importaciones,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
    ) {}

    /**
     * Paso 1: plantilla, qué va en cada columna y subir el archivo; abajo, las anteriores.
     */
    public function create(Request $request, string $tipo): View
    {
        $importador = $this->importador($tipo);
        Gate::authorize($importador->modulo().'.importar');
        $actor = $request->user();
        // Importar estructura: el Super Administrador sin empresa elegida ve el selector «Empresa de trabajo»
        if ($tipo === 'estructura' && $this->empresa->id($actor) === null) {
            return view('importaciones.create', ['importador' => $importador, 'sinEmpresa' => true]);
        }
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, fn () => view('importaciones.create', [
            'importador' => $importador,
            'columnas' => $this->importaciones->columnasVisibles($actor, $importador),
            'ocultasPersonales' => ! $this->importaciones->vePersonales($actor, $importador),
            'anteriores' => Importacion::with('autor:id,name')->where('tipo', $tipo)->latest('id')->limit(10)->get(),
            'puedeCrear' => $actor->can($importador->modulo().'.crear'),
            'puedeEditar' => $importador->actualizable() && $actor->can($importador->modulo().'.editar'),
            'maxFilas' => $importador->maxFilas(),
            'sinEmpresa' => false,
            'empresa' => Empresa::with('rubro')->find($empresaId),
            'maxMb' => AdministradorImportaciones::MAX_KB / 1024,
        ]));
    }

    public function plantilla(Request $request, string $tipo): StreamedResponse
    {
        $importador = $this->importador($tipo);
        Gate::authorize($importador->modulo().'.importar');
        $empresaId = $this->empresaDeTrabajo($request);

        if ($importador instanceof ImportadorEstructura && EscritorXlsx::disponible()) {
            return $this->libro($empresaId, $importador, false);
        }

        [$encabezados, $ejemplo] = $this->tenant->conEmpresa($empresaId, fn () => $this->importaciones->plantilla($request->user(), $importador));

        return response()->streamDownload(function () use ($encabezados, $ejemplo) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, $encabezados);
            Csv::fila($salida, $ejemplo);
            fclose($salida);
        }, 'Plantilla_'.str_replace(' ', '_', $importador->titulo()).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Importar estructura: la estructura actual en el mismo formato de la
     * plantilla (respaldo; se puede editar y volver a subir). Pide el mismo
     * permiso de importar y además poder ver las zonas y edificios.
     */
    public function exportar(Request $request, string $tipo): StreamedResponse
    {
        $importador = $this->importador($tipo);
        abort_unless($importador instanceof ImportadorEstructura, 404);
        Gate::authorize($importador->modulo().'.importar');
        Gate::authorize('espacios.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->libro($empresaId, $importador, true);
    }

    /**
     * Libro de Excel de Importar estructura: hoja «Estructura» (la que se
     * lee al subir) con ejemplos ficticios o con lo que ya hay, y hoja
     * «Instrucciones». Sin ZipArchive, CSV UTF-8 con BOM.
     */
    private function libro(int $empresaId, ImportadorEstructura $importador, bool $actual): StreamedResponse
    {
        [$renglones, $instrucciones, $empresa] = $this->tenant->conEmpresa($empresaId, function () use ($importador, $actual, $empresaId) {
            $columnas = $importador->columnas();
            $renglones = $actual
                ? $importador->exportar()
                : [array_map(fn (Columna $c) => $c->encabezado(), $columnas), ...array_map(fn ($e) => array_map(fn (Columna $c) => $e[$c->clave] ?? '', $columnas), $importador->ejemplos())];

            return [$renglones, $this->instrucciones($importador, $columnas, $actual), Empresa::find($empresaId)];
        });
        $nombre = ($actual ? 'Estructura_' : 'Plantilla_Estructura_')
            .(preg_replace('/[^A-Za-z0-9]+/', '_', (string) Str::ascii((string) $empresa?->nombre_comercial)) ?: 'empresa')
            .($actual ? '_'.now()->format('Y-m-d') : '');

        if (! EscritorXlsx::disponible()) {
            return response()->streamDownload(function () use ($renglones) {
                $salida = fopen('php://output', 'w');
                fwrite($salida, "\xEF\xBB\xBF");
                foreach ($renglones as $renglon) {
                    Csv::fila($salida, $renglon);
                }
                fclose($salida);
            }, $nombre.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $anchos = array_map(fn ($t) => max(12, mb_strlen($t) + 4), $renglones[0]);
        $ruta = tempnam(sys_get_temp_dir(), 'estructura');
        (new EscritorXlsx)
            ->hoja('Estructura', $renglones, $anchos)
            ->hoja('Instrucciones', $instrucciones, [28, 90], [0 => EscritorXlsx::TITULO], false)
            ->guardar($ruta);

        return response()->streamDownload(function () use ($ruta) {
            readfile($ruta);
            @unlink($ruta);
        }, $nombre.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Hoja «Instrucciones» del libro.
     *
     * @param  list<Columna>  $columnas
     * @return list<list<string>>
     */
    private function instrucciones(ImportadorEstructura $importador, array $columnas, bool $actual): array
    {
        $especifica = mb_strtolower($importador->especifica());
        $tipos = $importador->tiposDisponibles();
        $renglones = [
            ['Cómo llenar la hoja «Estructura»'],
            [''],
            ['Un renglón por lugar', 'Escribe un renglón por cada sede, zona, piso, '.$especifica.' o área. Cada renglón lleva el camino completo desde la sede (por ejemplo: Hotel Centro · Torre A · Piso 1 · 101).'],
            ['Lo que ya existe', 'Si la sede, la zona, el piso, la '.$especifica.' o el área ya existen con el mismo nombre en el mismo lugar, se usan los que hay: no se duplican. No importan mayúsculas, acentos ni espacios de más.'],
            ['Nada se borra', 'Importar nunca borra, da de baja ni cambia lo que ya tienes. Subir el mismo archivo dos veces no crea nada nuevo.'],
            ['Todo o nada', 'Primero ves una vista previa. Si un renglón tiene error, no se guarda nada: corrígelo y vuelve a subir el archivo.'],
            ['Sin huecos', 'No dejes vacío un nivel de en medio: un piso necesita su zona y un área necesita su '.$especifica.'. El piso sí puede quedar vacío si la '.$especifica.' está directo en la zona.'],
            ['Solo esta hoja', 'Se lee solo la primera hoja («Estructura»). Hasta '.number_format($importador->maxFilas()).' renglones y 5 MB por archivo.'],
            [''],
            ['Columna', 'Qué escribir'],
        ];
        foreach ($columnas as $c) {
            $renglones[] = [$c->encabezado(), trim($c->ayuda.($c->ejemplo !== '' ? ' Ejemplo: '.$c->ejemplo.'.' : ''))];
        }
        $renglones[] = [''];
        $renglones[] = ['Tipos de '.$especifica.' que ya tienes', implode(', ', $tipos['especifica']) ?: '—'];
        $renglones[] = ['Tipos de área que ya tienes', implode(', ', $tipos['area']) ?: '—'];
        $renglones[] = [''];
        $renglones[] = [$actual
            ? 'Este archivo trae la estructura que tienes hoy (solo lo activo). Puedes agregar renglones y subirlo de nuevo: lo que ya existe se deja igual.'
            : 'Los renglones de la hoja «Estructura» son ejemplos ficticios: bórralos y escribe los tuyos.'];

        return $renglones;
    }

    /**
     * Paso 2: sube el archivo y arma la vista previa.
     */
    public function store(Request $request, string $tipo): RedirectResponse
    {
        $importador = $this->importador($tipo);
        Gate::authorize($importador->modulo().'.importar');
        $empresaId = $this->empresaDeTrabajo($request);

        $request->validate([
            'archivo' => ['required', 'file', 'max:'.AdministradorImportaciones::MAX_KB, 'extensions:csv,txt,xlsx'],
        ], [
            'archivo.required' => 'Elige el archivo que vas a importar.',
            'archivo.uploaded' => 'No se pudo subir el archivo: revisa que pese menos de '.(AdministradorImportaciones::MAX_KB / 1024).' MB.',
            'archivo.max' => 'El archivo pesa más de '.(AdministradorImportaciones::MAX_KB / 1024).' MB: divídelo en varios.',
            'archivo.extensions' => 'Sube un archivo de Excel (.xlsx) o un archivo CSV (.csv).',
        ]);

        $importacion = $this->importaciones->subir($request->user(), $empresaId, $importador, $request->file('archivo'));

        return redirect()->route('importaciones.show', $importacion);
    }

    /**
     * Vista previa (o el resultado, si ya se guardó).
     */
    public function show(Request $request, int $importacion): View
    {
        [$modelo, $importador, $empresaId] = $this->buscar($request, $importacion);
        $actor = $request->user();
        $ver = in_array($request->query('ver'), ['nuevas', 'existen', 'errores'], true) ? $request->query('ver') : 'todas';

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $modelo, $importador, $actor, $ver) {
            $resultados = collect($modelo->aplicada() ? $modelo->resultado : $modelo->filas);
            $filtradas = $resultados->filter(fn ($r) => match ($ver) {
                'nuevas' => in_array($r['estado'], ['nueva', 'creada'], true),
                'existen' => in_array($r['estado'], ['existe', 'actualizada', 'omitida'], true),
                'errores' => $r['estado'] === 'error',
                default => true,
            })->values();
            $paginas = max(1, (int) ceil($filtradas->count() / self::POR_PAGINA));
            $pagina = min($paginas, max(1, (int) $request->query('pagina', 1)));
            $valores = $this->importaciones->valoresDe($actor, $modelo);

            return view('importaciones.show', [
                'importacion' => $modelo,
                'importador' => $importador,
                'columnas' => $this->importaciones->columnasVisibles($actor, $importador),
                'ocultasPersonales' => ! $this->importaciones->vePersonales($actor, $importador),
                'filas' => $filtradas->forPage($pagina, self::POR_PAGINA)->values(),
                'valores' => $valores,
                'ver' => $ver,
                'pagina' => $pagina,
                'paginas' => $paginas,
                'conteo' => $modelo->resumen ?? [],
                'puedeEditar' => $importador->actualizable() && $actor->can($importador->modulo().'.editar'),
                'puedeOriginal' => $this->importaciones->vePersonales($actor, $importador),
                // C-43 (A-19): lo que el archivo trae y no existe en los catálogos, y si puede crearlo
                'faltantes' => $faltantes = app(FaltantesDeCatalogo::class)->faltantes($actor, $modelo),
                'puedeFaltantes' => collect(array_keys($faltantes))->mapWithKeys(fn ($c) => [$c => app(FaltantesDeCatalogo::class)->puede($actor, $c)])->all(),
            ]);
        });
    }

    /**
     * Paso 3: guardar.
     */
    public function aplicar(Request $request, int $importacion): RedirectResponse
    {
        [$modelo, $importador, $empresaId] = $this->buscar($request, $importacion);
        $datos = $request->validate(['existentes' => ['nullable', 'in:actualizar,omitir']]);

        $modelo = $this->importaciones->aplicar($request->user(), $empresaId, $modelo, $datos['existentes'] ?? 'omitir');
        $r = $modelo->resumen;

        $partes = array_filter([
            $r['creada'] > 0 ? $r['creada'].' '.($r['creada'] === 1 ? 'nuevo' : 'nuevos') : null,
            $r['actualizada'] > 0 ? $r['actualizada'].' '.($r['actualizada'] === 1 ? 'actualizado' : 'actualizados') : null,
            $r['omitida'] > 0 ? $r['omitida'].' sin cambios (ya existían)' : null,
        ]);
        $texto = ($partes === [] ? 'No se guardó ningún registro' : 'Listo: '.implode(', ', $partes)).'.'
            .($r['error'] > 0 ? " {$r['error']} ".($r['error'] === 1 ? 'fila tiene' : 'filas tienen').' error y no se guardaron: descarga el resultado, corrígelas y súbelas de nuevo.' : '');

        if ($importador instanceof ImportadorEstructura) {
            $texto = $this->tenant->conEmpresa($empresaId, fn () => 'Listo: '.self::loQueSeCrea($importador, $r['crea'] ?? []).'.'
                .($r['omitida'] > 0 ? ' '.$r['omitida'].' '.($r['omitida'] === 1 ? 'renglón ya existía' : 'renglones ya existían').' y se dejaron igual.' : ''));

            return redirect()->route('importaciones.show', $modelo)->with('ok', $texto)->with('siguientes', [
                ['Ir a Zonas y edificios', route('espacios.index'), 'bi-arrow-right'],
                ['Ir a Sedes', route('sedes.index'), 'bi-geo-alt'],
                ['Descargar resultado', route('importaciones.resultado', $modelo), 'bi-download'],
            ]);
        }

        return redirect()->route('importaciones.show', $modelo)->with('ok', $texto)->with('siguientes', array_values(array_filter([
            ['Ir a '.$importador->titulo(), $importador->rutaLista(), 'bi-arrow-right'],
            ['Descargar resultado', route('importaciones.resultado', $modelo), 'bi-download'],
            $r['error'] > 0 ? ['Importar otro archivo', route('importaciones.create', $importador->tipo()), 'bi-upload'] : null,
        ])));
    }

    /**
     * El archivo tal como se subió. En Colaboradores puede traer CURP, RFC
     * y NSS: solo lo descarga quien ve datos personales.
     */
    public function original(Request $request, int $importacion): BinaryFileResponse
    {
        [$modelo, $importador] = $this->buscar($request, $importacion);
        abort_unless($this->importaciones->vePersonales($request->user(), $importador), 403);
        $ruta = Storage::disk('local')->path($modelo->archivo_ruta);
        abort_unless(is_file($ruta), 404);

        return response()->download($ruta, $modelo->archivo_nombre);
    }

    /**
     * Resultado: cada fila con su columna «resultado».
     */
    public function resultado(Request $request, int $importacion): StreamedResponse
    {
        [$modelo, $importador, $empresaId] = $this->buscar($request, $importacion);
        $renglones = $this->tenant->conEmpresa($empresaId, fn () => $this->importaciones->renglonesResultado($request->user(), $modelo));

        return response()->streamDownload(function () use ($renglones) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            foreach ($renglones as $renglon) {
                Csv::fila($salida, $renglon);
            }
            fclose($salida);
        }, 'Resultado_'.str_replace(' ', '_', $importador->titulo()).'_'.$modelo->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * «2 sedes, 3 zonas, 40 habitaciones y 1 tipo nuevo» (o «nada nuevo»).
     *
     * @param  array<string, int>  $crea
     */
    public static function loQueSeCrea(ImportadorEstructura $importador, array $crea): string
    {
        $especifica = mb_strtolower($importador->especifica());
        $nombres = [
            'sedes' => ['sede', 'sedes'], 'zonas' => ['zona o edificio', 'zonas o edificios'], 'pisos' => ['piso', 'pisos'],
            'especificas' => [$especifica, mb_strtolower(Etiquetas::plural($importador->especifica()))],
            'areas' => ['área', 'áreas'], 'tipos' => ['tipo nuevo', 'tipos nuevos'],
        ];
        $partes = [];
        foreach ($nombres as $clave => [$uno, $varios]) {
            $n = (int) ($crea[$clave] ?? 0);
            if ($n > 0) {
                $partes[] = number_format($n).' '.($n === 1 ? $uno : $varios);
            }
        }

        return match (count($partes)) {
            0 => 'nada nuevo',
            1 => $partes[0],
            default => implode(', ', array_slice($partes, 0, -1)).' y '.end($partes),
        };
    }

    private function importador(string $tipo): Importador
    {
        $importador = $this->importaciones->importador($tipo);
        abort_if($importador === null, 404);

        return $importador;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Una importación de otra empresa responde 404; sin el permiso de su módulo, 403.
     *
     * @return array{0: Importacion, 1: Importador, 2: int}
     */
    /**
     * C-43 (A-19): crea los departamentos, puestos y turnos que faltan y
     * vuelve a revisar el archivo (todavía no se guarda ningún colaborador).
     */
    public function crearFaltantes(Request $request, int $importacion): RedirectResponse
    {
        [$modelo, , $empresaId] = $this->buscar($request, $importacion);
        $faltantes = app(FaltantesDeCatalogo::class);
        abort_unless($faltantes->aplica($modelo), 404);

        $r = $this->tenant->conEmpresa($empresaId, fn () => $faltantes->crear($request->user(), $empresaId, $modelo));
        $nombres = ['departamento' => 'departamentos', 'puesto' => 'puestos', 'turno' => 'turnos'];
        $creados = collect($r['creados'])->map(fn ($lista, $c) => count($lista).' '.$nombres[$c].' ('.implode(', ', $lista).')')->values()->all();
        $texto = ($creados === [] ? 'No se creó nada.' : 'Listo: se crearon '.implode('; ', $creados).'. Revisamos el archivo otra vez.')
            .($r['sin_horario'] !== [] ? ' Sin crear (el nombre no trae su horario, por ejemplo «Matutino 07:00-15:00»): '.implode(', ', $r['sin_horario']).'. Créalos en Estructura → Turnos.' : '')
            .($r['sin_permiso'] !== [] ? ' Tu rol no puede crear '.implode(' ni ', $r['sin_permiso']).': pide a tu administrador que los cree.' : '');

        return redirect()->route('importaciones.show', $modelo)->with($creados === [] ? 'aviso' : 'ok', $texto);
    }

    private function buscar(Request $request, int $id): array
    {
        $empresaId = $this->empresaDeTrabajo($request);
        $modelo = $this->tenant->conEmpresa($empresaId, fn () => Importacion::find($id));
        abort_if($modelo === null, 404);
        $importador = $this->importador($modelo->tipo);
        Gate::authorize($importador->modulo().'.importar');

        return [$modelo, $importador, $empresaId];
    }
}
