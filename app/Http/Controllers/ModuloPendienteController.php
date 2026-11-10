<?php

namespace App\Http\Controllers;

use App\Models\Modulo;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Pantalla provisional de un modulo del menu que aun no se migra.
 */
class ModuloPendienteController extends Controller
{
    public function __invoke(string $clave): View
    {
        $modulo = Modulo::where('clave', $clave)->where('activo', true)->firstOrFail();

        Gate::authorize($modulo->clave.'.ver');

        return view('modulos.pendiente', ['modulo' => $modulo]);
    }
}
