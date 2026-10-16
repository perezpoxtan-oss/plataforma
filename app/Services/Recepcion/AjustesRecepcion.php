<?php

namespace App\Services\Recepcion;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Privacidad\AvisosPrivacidad;
use App\Support\Entrada;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de Recepción por empresa (empresas.preferencias['recepcion']):
 *  - aviso de privacidad que el candidato acepta antes de guardar su CV;
 *  - quién espera la autorización del responsable del departamento: visita,
 *    proveedor y contratista (ESPERAN_DEPARTAMENTO). Sin el dato = sí; a las
 *    empresas que ya existían la migración les dejó guardado su valor de
 *    visitas de antes (era «no» sin el dato);
 *  - si la caseta espera a que RR. HH. diga «Que pase» a quien viene con
 *    Recursos Humanos (rh_autoriza_paso). Sin el dato = encendido: las
 *    empresas nuevas lo tienen encendido; a las que ya existían la migración
 *    de postulaciones lo dejó apagado (su caseta sigue igual hasta que lo enciendan);
 *  - duración y usos del enlace del kiosco;
 *  - criterios con los que se califican las entrevistas (fase 2): una lista
 *    editable de hasta MAX_CRITERIOS; sin el dato, CRITERIOS_DEFECTO;
 *  - escalamiento de las autorizaciones que tardan: a los «recordar_minutos»
 *    se reenvía el aviso a quien debe responder y a los «escalar_minutos» se
 *    avisa a los suplentes y al Jefe de seguridad de la sede (para RR. HH., al
 *    resto de Recursos Humanos y al Jefe de seguridad). Sin el dato, los
 *    umbrales del panel de Recepción (ámbar a los 10, rojo a los 20).
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

    /** Criterios de la evaluación de entrevistas (1 a 5 estrellas cada uno). */
    public const CRITERIOS_DEFECTO = ['Presentación', 'Experiencia para el puesto', 'Actitud y comunicación',
        'Disponibilidad (turnos, horario, fecha de inicio)', 'Expectativa económica'];

    public const MAX_CRITERIOS = 8;

    /** Escalamiento: minutos máximos que admite cada umbral (4 horas). */
    public const MAX_MINUTOS_ESCALAMIENTO = 240;

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

    /**
     * Tipos de persona que pueden esperar la autorización del departamento:
     * tipo de acceso => [clave del ajuste, texto de la casilla].
     */
    public const ESPERAN_DEPARTAMENTO = [
        'visitante' => ['visitas_requieren_autorizacion', 'Visita'],
        'proveedor' => ['proveedores_requieren_autorizacion', 'Proveedor'],
        'contratista' => ['contratistas_requieren_autorizacion', 'Contratista'],
    ];

    public function visitasRequierenAutorizacion(Empresa $empresa): bool
    {
        return $this->esperaAlDepartamento($empresa, 'visitante');
    }

    /** ¿Este tipo de persona espera la autorización del responsable del departamento? (por omisión, sí) */
    public function esperaAlDepartamento(Empresa $empresa, string $tipo): bool
    {
        $clave = self::ESPERAN_DEPARTAMENTO[$tipo][0] ?? null;

        return $clave !== null && (bool) ($this->datos($empresa)[$clave] ?? true);
    }

    /** ¿La caseta espera a que RR. HH. diga «Que pase»? (por omisión, sí) */
    public function rhAutorizaPaso(Empresa $empresa): bool
    {
        return (bool) ($this->datos($empresa)['rh_autoriza_paso'] ?? true);
    }

    /** Escalamiento: minutos de espera tras los que se reenvía el aviso a quien debe responder. */
    public function recordarMinutos(Empresa $empresa): int
    {
        return (int) ($this->datos($empresa)['recordar_minutos'] ?? PanelRecepcion::ESPERA_AMBAR);
    }

    /** Escalamiento: minutos de espera tras los que se avisa al suplente, al Jefe de seguridad o a todo RR. HH. */
    public function escalarMinutos(Empresa $empresa): int
    {
        return (int) ($this->datos($empresa)['escalar_minutos'] ?? PanelRecepcion::ESPERA_ROJA);
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
     * Criterios para calificar las entrevistas (RR. HH. y departamento).
     *
     * @return list<string>
     */
    public function criterios(Empresa $empresa): array
    {
        $lista = $this->datos($empresa)['criterios'] ?? null;
        $lista = is_array($lista) ? array_values(array_filter($lista, fn ($c) => is_string($c) && trim($c) !== '')) : [];

        return $lista === [] ? self::CRITERIOS_DEFECTO : array_slice($lista, 0, self::MAX_CRITERIOS);
    }

    /** Clave de un criterio en el formulario («Actitud y comunicación» → actitud_y_comunicacion). */
    public static function claveCriterio(string $nombre): string
    {
        return Str::slug($nombre, '_') ?: 'criterio_'.substr(md5($nombre), 0, 8);
    }

    /**
     * Lista capturada en Ajustes → criterios limpios (sin vacíos ni repetidos).
     *
     * @return list<string>
     */
    private function limpiarCriterios(mixed $entrada): array
    {
        $limpios = [];
        $claves = [];
        foreach (is_array($entrada) ? $entrada : [] as $c) {
            $texto = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($c)));
            if ($texto === '') {
                continue;
            }
            if (mb_strlen($texto) > 80) {
                throw ValidationException::withMessages(['criterios' => 'Cada criterio admite máximo 80 caracteres.']);
            }
            $clave = self::claveCriterio($texto);
            if (in_array($clave, $claves, true)) {
                throw ValidationException::withMessages(['criterios' => "El criterio «{$texto}» está repetido."]);
            }
            $claves[] = $clave;
            $limpios[] = $texto;
        }
        if ($limpios === []) {
            throw ValidationException::withMessages(['criterios' => 'Escribe al menos un criterio para calificar las entrevistas.']);
        }
        if (count($limpios) > self::MAX_CRITERIOS) {
            throw ValidationException::withMessages(['criterios' => 'Máximo '.self::MAX_CRITERIOS.' criterios.']);
        }

        return $limpios;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    /**
     * Guarda las partes que vienen en $partes (cada formulario manda solo la
     * suya; ADR-0010): privacidad (aviso y kiosco; por compatibilidad también
     * el paso de RR. HH. y los criterios si vienen), criterios (Candidatos →
     * Criterios de entrevista), esperas (Responsables por departamento:
     * autorización de visitas y «Que pase» de RR. HH.) y escalamiento (minutos).
     *
     * @param  array<string, mixed>  $entrada
     * @param  list<string>  $partes
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada, array $partes): void
    {
        $privacidad = in_array('privacidad', $partes, true);
        $criterios = in_array('criterios', $partes, true);
        $esperas = in_array('esperas', $partes, true);
        $escalamiento = in_array('escalamiento', $partes, true);
        $maximo = self::MAX_MINUTOS_ESCALAMIENTO;
        $d = Validator::make($entrada, [
            'aviso_privacidad' => ['nullable', 'string', 'max:6000'],
            'visitas_requieren_autorizacion' => ['nullable', 'boolean'],
            'proveedores_requieren_autorizacion' => ['nullable', 'boolean'],
            'contratistas_requieren_autorizacion' => ['nullable', 'boolean'],
            'kiosco_horas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'kiosco_usos' => ['nullable', 'integer', 'min:1', 'max:10'],
            'rh_autoriza_paso' => ['nullable', 'boolean'],
            'recordar_minutos' => [$escalamiento ? 'required' : 'nullable', 'integer', 'min:1', 'max:'.$maximo],
            'escalar_minutos' => [$escalamiento ? 'required' : 'nullable', 'integer', 'min:2', 'max:'.$maximo, 'gt:recordar_minutos'],
        ], [
            'aviso_privacidad.max' => 'El aviso de privacidad admite máximo 6000 caracteres.',
            'kiosco_horas.*' => 'El enlace del kiosco dura de 1 a 24 horas.',
            'kiosco_usos.*' => 'El enlace del kiosco se puede abrir de 1 a 10 veces.',
            'recordar_minutos.*' => "«Recordar a los … minutos» va de 1 a {$maximo}.",
            'escalar_minutos.gt' => 'El aviso al suplente y al jefe debe ir después del recordatorio.',
            'escalar_minutos.*' => "«Avisar al suplente y al jefe a los … minutos» va de 2 a {$maximo}.",
        ])->validate();

        $antes = $this->datos($empresa);
        $nuevos = $antes;
        if ($privacidad) {
            $texto = trim(str_replace("\r\n", "\n", (string) ($d['aviso_privacidad'] ?? '')));
            $nuevos['aviso_privacidad'] = $texto === '' ? null : $texto;
            $nuevos['kiosco_horas'] = (int) ($d['kiosco_horas'] ?? self::HORAS_KIOSCO);
            $nuevos['kiosco_usos'] = (int) ($d['kiosco_usos'] ?? self::USOS_KIOSCO);
        }
        if (($privacidad || $esperas) && array_key_exists('rh_autoriza_paso', $d)) {
            $nuevos['rh_autoriza_paso'] = (bool) $d['rh_autoriza_paso'];
        }
        if (($privacidad || $criterios) && array_key_exists('criterios', $entrada)) {
            $lista = $this->limpiarCriterios($entrada['criterios']);
            // Los de siempre no se guardan (así, si cambian en la plataforma, la empresa los recibe)
            $nuevos['criterios'] = $lista === self::CRITERIOS_DEFECTO ? null : $lista;
        }
        foreach (self::ESPERAN_DEPARTAMENTO as [$clave]) {
            if ($esperas && array_key_exists($clave, $d)) {
                $nuevos[$clave] = (bool) ($d[$clave] ?? false);
            }
        }
        if ($escalamiento) {
            $nuevos['recordar_minutos'] = (int) $d['recordar_minutos'];
            $nuevos['escalar_minutos'] = (int) $d['escalar_minutos'];
        }

        $empresa->forceFill(['preferencias' => array_merge($empresa->preferencias ?? [], ['recepcion' => $nuevos])])->save();
        $resumen = fn (array $x) => [
            'visitas_requieren_autorizacion' => (bool) ($x['visitas_requieren_autorizacion'] ?? true),
            'proveedores_requieren_autorizacion' => (bool) ($x['proveedores_requieren_autorizacion'] ?? true),
            'contratistas_requieren_autorizacion' => (bool) ($x['contratistas_requieren_autorizacion'] ?? true),
            'aviso_privacidad' => isset($x['aviso_privacidad']) ? 'versión '.substr(hash('sha256', (string) $x['aviso_privacidad']), 0, 12) : 'borrador de la plataforma',
            'kiosco_horas' => (int) ($x['kiosco_horas'] ?? self::HORAS_KIOSCO),
            'kiosco_usos' => (int) ($x['kiosco_usos'] ?? self::USOS_KIOSCO),
            'rh_autoriza_paso' => (bool) ($x['rh_autoriza_paso'] ?? true),
            'criterios' => is_array($x['criterios'] ?? null) ? $x['criterios'] : self::CRITERIOS_DEFECTO,
            'recordar_minutos' => (int) ($x['recordar_minutos'] ?? PanelRecepcion::ESPERA_AMBAR),
            'escalar_minutos' => (int) ($x['escalar_minutos'] ?? PanelRecepcion::ESPERA_ROJA),
        ];
        $this->auditoria->auditar($actor, 'candidatos.configurado', $empresa, $resumen($antes), $resumen($nuevos));
        // Privacidad (docs/tecnico/privacidad.md): un texto distinto del aviso queda como versión nueva, con su autor
        if ($privacidad && ($antes['aviso_privacidad'] ?? null) !== ($nuevos['aviso_privacidad'] ?? null)) {
            app(AvisosPrivacidad::class)->registrarCambio($actor, $empresa);
        }
    }
}
