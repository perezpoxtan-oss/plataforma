<?php

namespace App\Services\Recepcion;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Facades\Validator;

/**
 * Ajustes de Recepción por empresa (empresas.preferencias['recepcion']):
 *  - aviso de privacidad que el candidato acepta antes de guardar su CV;
 *  - si las visitas a un departamento esperan la autorización de su responsable;
 *  - duración y usos del enlace del kiosco.
 *
 * El texto del aviso NO es un texto legal definitivo: el que trae la
 * plataforma está marcado como borrador para que la empresa lo valide con
 * su abogado. Cada aceptación guarda la huella (SHA-256) del texto aceptado.
 */
class AjustesRecepcion
{
    public const MARCA_BORRADOR = 'Borrador — validar con su abogado';

    public const PRIVACIDAD_BORRADOR = self::MARCA_BORRADOR.".\n\n"
        .'[NOMBRE DE LA EMPRESA], con domicilio en [DOMICILIO], usará los datos personales que nos compartes en esta solicitud '
        .'(datos de contacto, escolaridad, experiencia laboral, referencias, documentos y fotografía) para evaluar tu candidatura '
        ."a un puesto de trabajo y, si así lo decides, conservarlos en nuestra cartera de candidatos.\n\n"
        ."Puedes ejercer tus derechos de acceso, rectificación, cancelación u oposición (ARCO) escribiendo a [CORREO DE CONTACTO].\n\n"
        .'Consulta el aviso de privacidad integral en [DIRECCIÓN O LUGAR DONDE SE PUEDE CONSULTAR].';

    public const HORAS_KIOSCO = 2;

    public const USOS_KIOSCO = 3;

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /** @return array<string, mixed> */
    private function datos(Empresa $empresa): array
    {
        $d = $empresa->preferencias['recepcion'] ?? [];

        return is_array($d) ? $d : [];
    }

    public function textoPrivacidad(Empresa $empresa): string
    {
        $texto = $this->datos($empresa)['aviso_privacidad'] ?? null;

        return is_string($texto) && trim($texto) !== '' ? $texto : self::PRIVACIDAD_BORRADOR;
    }

    /** Huella del texto vigente: lo que queda como «versión aceptada». */
    public function versionPrivacidad(Empresa $empresa): string
    {
        return hash('sha256', $this->textoPrivacidad($empresa));
    }

    public function esBorrador(Empresa $empresa): bool
    {
        return str_contains($this->textoPrivacidad($empresa), self::MARCA_BORRADOR);
    }

    public function visitasRequierenAutorizacion(Empresa $empresa): bool
    {
        return (bool) ($this->datos($empresa)['visitas_requieren_autorizacion'] ?? false);
    }

    public function horasKiosco(Empresa $empresa): int
    {
        return (int) ($this->datos($empresa)['kiosco_horas'] ?? self::HORAS_KIOSCO);
    }

    public function usosKiosco(Empresa $empresa): int
    {
        return (int) ($this->datos($empresa)['kiosco_usos'] ?? self::USOS_KIOSCO);
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada, bool $privacidad, bool $autorizaciones): void
    {
        $d = Validator::make($entrada, [
            'aviso_privacidad' => ['nullable', 'string', 'max:6000'],
            'visitas_requieren_autorizacion' => ['nullable', 'boolean'],
            'kiosco_horas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'kiosco_usos' => ['nullable', 'integer', 'min:1', 'max:10'],
        ], [
            'aviso_privacidad.max' => 'El aviso de privacidad admite máximo 6000 caracteres.',
            'kiosco_horas.*' => 'El enlace del kiosco dura de 1 a 24 horas.',
            'kiosco_usos.*' => 'El enlace del kiosco se puede abrir de 1 a 10 veces.',
        ])->validate();

        $antes = $this->datos($empresa);
        $nuevos = $antes;
        if ($privacidad) {
            $texto = trim(str_replace("\r\n", "\n", (string) ($d['aviso_privacidad'] ?? '')));
            $nuevos['aviso_privacidad'] = $texto === '' ? null : $texto;
            $nuevos['kiosco_horas'] = (int) ($d['kiosco_horas'] ?? self::HORAS_KIOSCO);
            $nuevos['kiosco_usos'] = (int) ($d['kiosco_usos'] ?? self::USOS_KIOSCO);
        }
        if ($autorizaciones) {
            $nuevos['visitas_requieren_autorizacion'] = (bool) ($d['visitas_requieren_autorizacion'] ?? false);
        }

        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['recepcion' => $nuevos])])->save();
        $resumen = fn (array $x) => [
            'visitas_requieren_autorizacion' => (bool) ($x['visitas_requieren_autorizacion'] ?? false),
            'aviso_privacidad' => isset($x['aviso_privacidad']) ? 'versión '.substr(hash('sha256', (string) $x['aviso_privacidad']), 0, 12) : 'borrador de la plataforma',
            'kiosco_horas' => (int) ($x['kiosco_horas'] ?? self::HORAS_KIOSCO),
            'kiosco_usos' => (int) ($x['kiosco_usos'] ?? self::USOS_KIOSCO),
        ];
        $this->auditoria->auditar($actor, 'candidatos.configurado', $empresa, $resumen($antes), $resumen($nuevos));
    }
}
