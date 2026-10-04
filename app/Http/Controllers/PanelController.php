<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PanelController extends Controller
{
    /**
     * Consola de Monitoreo Central. Las fichas (colaboradores, personas en
     * sitio, llaves...) se agregan conforme se migra cada modulo.
     */
    public function __invoke(): View
    {
        return view('panel.index');
    }
}
