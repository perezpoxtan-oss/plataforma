<?php

namespace App\Http\Controllers;

use App\Services\Manual\Manual;
use App\Services\Novedades\AjustesNovedades;
use App\Support\Entrada;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Manual de usuario (ayuda dentro de la plataforma). Toda sesión puede abrirlo,
 * pero cada página solo se muestra a quien puede «ver» alguno de sus módulos
 * (las páginas generales, a todos). Ver docs/tecnico/manual.md.
 */
class ManualController extends Controller
{
    public function __construct(private readonly Manual $manual) {}

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = Str::limit(trim(Entrada::texto($request->query('q'))), 80, '');
        $grupos = $this->manual->indice($usuario);

        // Con JavaScript el índice se filtra al escribir (título, resumen y palabras del contenido);
        // al buscar (?q=…) se busca en todo el contenido y cada resultado lleva a su apartado (ADR-0016)
        $resultados = $buscar === '' ? [] : $this->manual->buscar($usuario, $buscar);

        return view('manual.index', [
            'grupos' => $buscar === '' ? $grupos : [],
            'resultados' => $resultados,
            'buscar' => $buscar,
            'iconos' => Manual::ICONOS,
            'manual' => $this->manual,
        ]);
    }

    public function ver(Request $request, string $pagina): View
    {
        $usuario = $request->user();
        $encontrada = $this->manual->pagina($pagina);
        abort_if($encontrada === null || ! $this->manual->puedeVer($usuario, $encontrada), 404);

        $contenido = $this->manual->contenido($encontrada, $usuario);

        // Páginas vecinas de la misma sección (para «Anterior / Siguiente»)
        $seccion = $this->manual->indice($usuario)[$encontrada->seccion] ?? [];
        $posicion = array_search($encontrada->slug, array_map(fn ($p) => $p->slug, $seccion), true);

        // Manual dinámico: si la empresa le dio otro nombre a la bitácora («Incidentes»), la página lo dice
        $ajustes = app(AjustesNovedades::class);
        $empresaId = $ajustes->empresaActual();
        $nombreBitacora = in_array('novedades', $encontrada->modulos, true) && $ajustes->nombrePropio($empresaId) ? $ajustes->nombreMenu($empresaId) : null;

        return view('manual.ver', [
            'pagina' => $encontrada,
            'titulo' => $nombreBitacora !== null && $encontrada->slug === 'novedades' ? $ajustes->bitacora($empresaId) : $encontrada->titulo,
            'nombreBitacora' => $nombreBitacora,
            'html' => $contenido['html'],
            'apartados' => $contenido['apartados'],
            'anterior' => $posicion !== false && $posicion > 0 ? $seccion[$posicion - 1] : null,
            'siguiente' => $posicion !== false ? ($seccion[$posicion + 1] ?? null) : null,
            'icono' => Manual::ICONOS[$encontrada->seccion] ?? 'bi-question-circle',
        ]);
    }

    /**
     * Imágenes del manual: solo archivos de imagen dentro de docs/usuario/img.
     */
    public function imagen(Request $request, string $carpeta, string $archivo): BinaryFileResponse
    {
        $ruta = $this->manual->rutaImagen($carpeta, $archivo);
        abort_if($ruta === null, 404);

        $tipo = Manual::TIPOS_IMAGEN[strtolower(pathinfo($ruta, PATHINFO_EXTENSION))] ?? null;
        $real = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);
        abort_if($tipo === null || $real !== $tipo, 404);

        $respuesta = new BinaryFileResponse($ruta, 200, [
            'Content-Type' => $tipo,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], false);
        $respuesta->setPrivate();
        $respuesta->setMaxAge(604800);
        $respuesta->setAutoEtag();
        $respuesta->setAutoLastModified();
        $respuesta->isNotModified($request);

        return $respuesta;
    }
}
