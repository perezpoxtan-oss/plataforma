<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Giros (docs/tecnico/giros.md): en una empresa que no es hotel, las páginas
 * dicen las palabras de su giro (Visitante, Residente, Oficina, Departamento…)
 * aunque la pantalla las tenga escritas como en un hotel. Solo actúa sobre
 * páginas HTML con una empresa activa; en hotel (o sin empresa) no toca nada.
 */
class AplicarTerminos
{
    public function __construct(private readonly Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if (! $this->tenant->activo() || $respuesta instanceof StreamedResponse || $respuesta instanceof BinaryFileResponse) {
            return $respuesta;
        }
        if (! str_starts_with(strtolower((string) $respuesta->headers->get('Content-Type', 'text/html')), 'text/html')) {
            return $respuesta;
        }

        $terminos = Terminos::deEmpresaId($this->tenant->empresaId());
        $contenido = $respuesta->getContent();
        if ($terminos->esHotel() || ! is_string($contenido) || $contenido === '') {
            return $respuesta;
        }
        $respuesta->setContent($terminos->html($contenido));

        return $respuesta;
    }
}
