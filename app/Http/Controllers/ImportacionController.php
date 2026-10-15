<?php

namespace App\Http\Controllers;

use App\Models\Importacion;
use App\Services\Importacion\AdministradorImportaciones;
use App\Services\Importacion\Importador;
use App\Support\Csv;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
        $empresaId = $this->empresaDeTrabajo($request);
        $actor = $request->user();

        return $this->tenant->conEmpresa($empresaId, fn () => view('importaciones.create', [
            'importador' => $importador,
            'columnas' => $this->importaciones->columnasVisibles($actor, $importador),
            'ocultasPersonales' => ! $this->importaciones->vePersonales($actor, $importador),
            'anteriores' => Importacion::with('autor:id,name')->where('tipo', $tipo)->latest('id')->limit(10)->get(),
            'puedeCrear' => $actor->can($importador->modulo().'.crear'),
            'puedeEditar' => $importador->actualizable() && $actor->can($importador->modulo().'.editar'),
            'maxFilas' => AdministradorImportaciones::MAX_FILAS,
            'maxMb' => AdministradorImportaciones::MAX_KB / 1024,
        ]));
    }

    public function plantilla(Request $request, string $tipo): StreamedResponse
    {
        $importador = $this->importador($tipo);
        Gate::authorize($importador->modulo().'.importar');
        $empresaId = $this->empresaDeTrabajo($request);

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
