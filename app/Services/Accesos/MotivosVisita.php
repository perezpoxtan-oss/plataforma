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
 *  - C-09 (DD-12): «Visita a colaborador» y «Visita a departamento» se
 *    juntan en «Visita a persona o área» («Visita» en residenciales, donde
 *    es el único motivo y viene marcado); la caseta elige en «¿A quién viene?».
 *    El registro guarda «colaborador» o «departamento» según lo elegido.
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
        // C-09 (DD-12): «Visita a colaborador» y «Visita a departamento» son un solo motivo con «¿A quién viene?»
        if ($terminos->esResidencial()) {
            return ['colaborador' => $terminos->motivoAQuien()];
        }
        $conRh = $this->autorizador->moduloActivo($empresaId, 'candidatos');
        $motivos = $terminos->giro === Terminos::CORPORATIVO
            ? ['colaborador', 'departamento', 'general', 'rh']
            : ['general', 'huesped', 'colaborador', 'departamento', 'rh'];
        $huesped = $terminos->esHotel() && AjustesCaseta::valor($empresaId, 'visita_a_huesped') === 'si';

        return collect($motivos)
            ->reject(fn ($m) => ($m === 'rh' && ! $conRh) || ($m === 'huesped' && ! $huesped) || $m === 'departamento')
            ->mapWithKeys(fn ($m) => [$m => $m === 'colaborador' ? $terminos->motivoAQuien() : $terminos->etiquetaMotivo($m)])->all();
    }

    /** Motivo que viene marcado (null = ninguno, de fábrica). Con un solo motivo (residenciales), ese. */
    public function porOmision(int $empresaId): ?string
    {
        $motivos = $this->para($empresaId);
        if (count($motivos) === 1) {
            return (string) array_key_first($motivos);
        }
        $elegido = (string) AjustesCaseta::valor($empresaId, 'motivo_visita_omision');
        $elegido = $elegido === 'departamento' ? 'colaborador' : $elegido; // C-09: ahora es el mismo motivo

        return $elegido !== '' && array_key_exists($elegido, $this->para($empresaId)) ? $elegido : null;
    }

    /** Todos los motivos que puede traer un registro (también los de antes), para validar el formulario. */
    public static function claves(): array
    {
        return array_keys(Acceso::MOTIVOS);
    }
}
