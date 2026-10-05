<?php

namespace App\Services\Avisos;

use App\Mail\AltaProvisionalRegistrada;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\CorreoPlataforma;
use App\Support\HoraLocal;

use function Illuminate\Support\defer;

/**
 * Avisos por correo. Se envían DESPUÉS de responder al usuario (defer): el
 * guardia no espera al servidor de correo, y si el correo falla la captura ya
 * quedó guardada.
 */
class AvisosCorreo
{
    public function __construct(
        private readonly CorreoPlataforma $correo,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * A quien puede validar altas provisionales (colaboradores.aprobar) en la
     * sede del colaborador.
     */
    public function altaProvisional(Colaborador $colaborador, User $registro): void
    {
        $empresa = Empresa::find($colaborador->empresa_id);
        if ($empresa === null || ! $empresa->aviso('alta_provisional') || ! $this->correo->configurado()) {
            return;
        }

        $destinatarios = $this->conPermiso($empresa->id, 'colaboradores.aprobar', $colaborador->sede_id);
        if ($destinatarios === []) {
            return;
        }

        $mensaje = new AltaProvisionalRegistrada(
            $colaborador->nombreCompleto(),
            $colaborador->sede?->nombre,
            $registro->name,
            app(HoraLocal::class)->formatear(now()),
            route('colaboradores.index', ['registro' => 'provisional']),
        );

        defer(fn () => $this->correo->enviar($destinatarios, $mensaje));
    }

    /**
     * Correos de los usuarios activos de la empresa con un permiso que alcance esa sede.
     *
     * @return list<string>
     */
    public function conPermiso(int $empresaId, string $permiso, ?int $sedeId): array
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->whereNotNull('email')->get()
            ->filter(function (User $u) use ($permiso, $sedeId) {
                $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

                return $efectivo !== null && ($efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
                    || ($sedeId !== null && in_array($sedeId, $efectivo->sedes, true)));
            })
            ->pluck('email')->unique()->values()->all();
    }
}
