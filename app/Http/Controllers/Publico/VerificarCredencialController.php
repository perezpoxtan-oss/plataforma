<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Services\Credenciales\AdministradorCredenciales;
use App\Support\EstadoActivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * C-44 (Etapa 8), propuesta de credencialización: el QR impreso en la
 * credencial lleva /credencial/{código}. Con cualquier celular, sin sesión,
 * abre «Vigente» con la foto, el nombre y la empresa (y hasta cuándo vale), o
 * «No vigente». Nunca muestra datos personales (CURP, RFC, NSS, domicilio,
 * teléfono, número de empleado) ni dice por qué no es vigente.
 *
 * Con sesión iniciada lleva a la acción de la caseta (/e/{código}: Nuevo
 * ingreso o Dar salida). Límite de 30 por minuto por IP; la respuesta no se
 * guarda en caché ni se indexa.
 */
class VerificarCredencialController extends Controller
{
    public function show(Request $request, string $codigo): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('lector.ir', $codigo);
        }

        $codigo = strtolower($codigo);
        $colaborador = Colaborador::withoutGlobalScopes()->where('codigo_qr', $codigo)->first();
        $credencial = $colaborador === null ? null : Credencial::withoutGlobalScopes()->where('colaborador_id', $colaborador->id)
            ->where('codigo_qr', $codigo)->whereIn('estado', [EstadoActivo::DISPONIBLE, EstadoActivo::ASIGNADO])->latest('id')->first();
        $empresa = $colaborador === null ? null : Empresa::query()->whereKey($colaborador->empresa_id)->first(['id', 'nombre_comercial', 'activo']);
        $vigente = $credencial !== null && $empresa !== null && (bool) $empresa->activo && AdministradorCredenciales::esVigente($credencial, $colaborador);

        return response()->view('publico.credencial', $vigente ? [
            'vigente' => true,
            'nombre' => $colaborador->nombreCompleto(),
            'empresa' => $empresa->nombre_comercial,
            'foto' => AdministradorCredenciales::fotoDataUri($colaborador),
            'vence' => $credencial->vence_en?->format('d/m/Y'),
        ] : ['vigente' => false])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }
}
