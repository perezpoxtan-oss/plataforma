<?php

namespace App\Http\Controllers;

use App\Models\Colaborador;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PanelController extends Controller
{
    /**
     * Consola de Monitoreo Central. Las fichas (colaboradores, personas en
     * sitio, llaves...) se agregan conforme se migra cada modulo; arriba van
     * los pendientes que requieren atención de quien entra.
     */
    public function __invoke(Request $request, EmpresaDeTrabajo $empresa, Tenant $tenant, AdministradorColaboradores $colaboradores): View
    {
        $actor = $request->user();
        $empresaId = $empresa->id($actor);
        $pendientes = [];

        // Recursos Humanos: altas provisionales de la caseta por validar
        if ($empresaId !== null && $actor->can('colaboradores.aprobar')) {
            $total = $tenant->conEmpresa($empresaId, fn () => $colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.aprobar')
                ->where('colaboradores.provisional', true)->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')->count());
            if ($total > 0) {
                $pendientes[] = [
                    'icono' => 'bi-person-exclamation',
                    'titulo' => $total === 1 ? '1 alta provisional por validar' : "{$total} altas provisionales por validar",
                    'texto' => 'La caseta registró colaboradores que aún no estaban en el directorio. Valídalos o únelos con su registro correcto.',
                    'ruta' => route('colaboradores.index', ['registro' => 'provisional']),
                    'boton' => 'Revisar ahora',
                ];
            }
        }

        // Pases de salida: aprobaciones que esperan la firma de quien entra (bandeja de firmas)
        if ($empresaId !== null && $actor->can('pases_salida.aprobar')) {
            $porAprobar = count($tenant->conEmpresa($empresaId, fn () => app(AdministradorPasesSalida::class)->idsPorAprobar($actor)));
            if ($porAprobar > 0) {
                $pendientes[] = [
                    'icono' => 'bi-pen',
                    'titulo' => $porAprobar === 1 ? '1 pase de salida espera tu aprobación' : "{$porAprobar} pases de salida esperan tu aprobación",
                    'texto' => 'Revisa los artículos y firma o rechaza con tus comentarios. El siguiente paso no avanza hasta que firmes.',
                    'ruta' => route('pases-salida.pendientes'),
                    'boton' => 'Ir a mi bandeja',
                ];
            }
        }
        // Fin Pases de salida
        // Altas por verificar: lo que la caseta registró desde Operación en los padrones que el usuario edita (ADR-0006)
        if ($empresaId !== null) {
            $grupos = $tenant->conEmpresa($empresaId, fn () => app(AltasPorVerificar::class)->pendientesPorPadron($actor));
            $total = array_sum(array_column($grupos, 'total'));
            if ($total > 0) {
                $pendientes[] = [
                    'icono' => 'bi-patch-question',
                    'titulo' => $total === 1 ? '1 alta por verificar' : "{$total} altas por verificar",
                    'texto' => 'La caseta registró desde Operación algo que no estaba en el padrón. Acéptalo, recházalo o únelo con el registro correcto.',
                    'ruta' => $grupos[0]['ruta'],
                    'boton' => 'Revisar ahora',
                    'grupos' => $grupos,
                ];
            }
        }
        // Fin Altas por verificar

        // Procedimientos: versiones por aprobar y procedimientos por leer y firmar («Leí y entendí»)
        if ($empresaId !== null && $actor->can('procedimientos.ver')) {
            $procedimientos = app(AdministradorProcedimientos::class);
            [$porAprobar, $porLeer] = $tenant->conEmpresa($empresaId, fn () => [count($procedimientos->idsPorAprobar($actor)), $procedimientos->pendientesDe($actor)->count()]);
            if ($porAprobar > 0) {
                $pendientes[] = [
                    'icono' => 'bi-patch-check',
                    'titulo' => $porAprobar === 1 ? '1 procedimiento espera tu aprobación' : "{$porAprobar} procedimientos esperan tu aprobación",
                    'texto' => 'Revisa los pasos y apruébalo con tu firma, o recházalo con tus comentarios para que lo corrijan.',
                    'ruta' => route('procedimientos.index', ['filtro' => 'por_aprobar']),
                    'boton' => 'Revisar',
                ];
            }
            if ($porLeer > 0) {
                $pendientes[] = [
                    'icono' => 'bi-book',
                    'titulo' => $porLeer === 1 ? 'Tienes 1 procedimiento por leer y firmar' : "Tienes {$porLeer} procedimientos por leer y firmar",
                    'texto' => 'Léelos con calma y firma «Leí y entendí». Así sabrás qué hacer en cada situación.',
                    'ruta' => route('procedimientos.por-leer'),
                    'boton' => 'Leer ahora',
                ];
            }
        }
        // Fin Procedimientos

        return view('panel.index', ['pendientes' => $pendientes]);
    }
}
