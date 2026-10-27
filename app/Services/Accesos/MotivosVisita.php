<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Services\Permisos\Autorizador;
use App\Support\AjustesCaseta;
use App\Support\Terminos;

/**
 * Motivos de visita que ofrece la caseta (plan de correcciones C-04, DD-23):
 *
 *  - Según el giro: hotel y corporativo tienen «Visita general»; el hotel,
 *    además, «Visita a huésped» (se puede apagar en Configuración); en
 *    condominio y fraccionamiento, «Visita a Residente» y «Visita a
 *    Administración».
 *  - Según lo contratado: «Recursos Humanos» solo si la empresa tiene
 *    Candidatos (ningún flujo crea registros en un módulo apagado).
 *  - Corporativo ordena primero «Visita a colaborador».
 *  - Ninguno viene marcado de fábrica; la empresa puede elegir uno en
 *    Configuración → Caseta y accesos.
 */
class MotivosVisita
{
    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * @return array<string, string> clave => texto, en el orden de la caseta
     */
    public function para(int $empresaId): array
    {
        $terminos = Terminos::deEmpresaId($empresaId);
        if ($terminos->esResidencial()) {
            return $terminos->motivos();
        }
        $conRh = $this->autorizador->moduloActivo($empresaId, 'candidatos');
        $motivos = $terminos->giro === Terminos::CORPORATIVO
            ? ['colaborador', 'departamento', 'general', 'rh']
            : ['general', 'huesped', 'colaborador', 'departamento', 'rh'];
        $huesped = $terminos->esHotel() && AjustesCaseta::valor($empresaId, 'visita_a_huesped') === 'si';

        return collect($motivos)
            ->reject(fn ($m) => ($m === 'rh' && ! $conRh) || ($m === 'huesped' && ! $huesped))
            ->mapWithKeys(fn ($m) => [$m => $terminos->etiquetaMotivo($m)])->all();
    }

    /** Motivo que viene marcado (null = ninguno, de fábrica). */
    public function porOmision(int $empresaId): ?string
    {
        $elegido = (string) AjustesCaseta::valor($empresaId, 'motivo_visita_omision');

        return $elegido !== '' && array_key_exists($elegido, $this->para($empresaId)) ? $elegido : null;
    }

    /** Todos los motivos que puede traer un registro (también los de antes), para validar el formulario. */
    public static function claves(): array
    {
        return array_keys(Acceso::MOTIVOS);
    }
}
