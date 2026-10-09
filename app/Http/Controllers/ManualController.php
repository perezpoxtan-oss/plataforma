<?php

namespace App\Http\Controllers;

use App\Services\Manual\Manual;
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

        // Sin JavaScript la búsqueda también funciona (?q=…); con JavaScript filtra al escribir
        if ($buscar !== '') {
            $aguja = Str::lower(Str::ascii($buscar));
            $grupos = array_filter(array_map(fn (array $paginas) => array_values(array_filter($paginas,
                fn ($p) => str_contains(Str::lower(Str::ascii($p->titulo.' '.$p->resumen)), $aguja))), $grupos));
        }

        return view('manual.index', [
            'grupos' => $grupos,
            'buscar' => $buscar,
            'iconos' => Manual::ICONOS,
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

        return view('manual.ver', [
            'pagina' => $encontrada,
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
