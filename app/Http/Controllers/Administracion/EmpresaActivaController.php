<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El Super Administrador elige la empresa con la que trabaja
 * (o las plantillas de la plataforma).
 */
class EmpresaActivaController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->es_superadmin, 403);

        $datos = $request->validate(['empresa_id' => ['nullable', 'integer']]);
        $id = $datos['empresa_id'] ?? null;

        if ($id !== null) {
            Empresa::findOrFail($id);
        }

        $request->session()->put(EmpresaDeTrabajo::SESION, $id);

        // Volver a la pantalla anterior (solo de este sitio y sin parametros de
        // la empresa anterior, p. ej. ?rol=)
        $anterior = (string) ($request->headers->get('referer') ?: $request->session()->previousUrl());
        $host = parse_url($anterior, PHP_URL_HOST);
        $mismoSitio = $host === $request->getHost()
            || ($host === null && str_starts_with($anterior, '/') && ! str_starts_with($anterior, '//'));

        return redirect()->to($mismoSitio ? strtok($anterior, '?') : route('panel'));
    }
}
