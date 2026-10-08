<?php

namespace App\Services\Vacantes;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bolsa de trabajo pública por empresa (/empleos/{slug}), SIN sesión.
 *
 *  - Se enciende por empresa (Configuración o Vacantes → «Bolsa de trabajo en
 *    internet», vacantes.configurar con alcance de empresa) con un texto de
 *    presentación y la opción de dejar que los buscadores la indexen
 *    (apagada por omisión). Apagada → 404.
 *  - La dirección lleva el nombre de la empresa y 6 letras al azar
 *    (empresas.bolsa_slug): no se puede adivinar la de otra empresa. Cada
 *    vacante tiene su propio código aleatorio.
 *  - Solo se ven las vacantes PUBLICADAS y vigentes (fechas en la zona de la
 *    empresa); nunca datos de candidatos.
 *  - «Postularme» usa la misma solicitud de empleo del kiosco (completa, con
 *    declaración y firma) + CV + aviso de privacidad y crea un candidato
 *    origen «web», etapa Registrado, «Por revisar», ligado a la vacante, y
 *    avisa a Recursos Humanos (campana y correo).
 */
class BolsaTrabajo
{
    public function __construct(
        private readonly AdministradorCandidatos $candidatos,
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
    ) {}

    // ------------------------------------------------------------------ Ajustes

    /** @return array{activa: bool, presentacion: ?string, indexar: bool} */
    public function ajustes(Empresa $empresa): array
    {
        $d = $empresa->preferencias['bolsa_trabajo'] ?? [];
        $d = is_array($d) ? $d : [];

        return [
            'activa' => (bool) ($d['activa'] ?? false),
            'presentacion' => is_string($d['presentacion'] ?? null) && trim($d['presentacion']) !== '' ? $d['presentacion'] : null,
            'indexar' => (bool) ($d['indexar'] ?? false),
        ];
    }

    public function activa(Empresa $empresa): bool
    {
        return $this->ajustes($empresa)['activa'] && $empresa->bolsa_slug !== null;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function guardarAjustes(User $actor, Empresa $empresa, array $entrada): void
    {
        $d = Validator::make($entrada, [
            'bolsa_activa' => ['nullable', 'boolean'],
            'bolsa_presentacion' => ['nullable', 'string', 'max:2000'],
            'bolsa_indexar' => ['nullable', 'boolean'],
        ], [
            'bolsa_presentacion.max' => 'El texto de presentación admite máximo 2000 caracteres.',
            '*.boolean' => 'Elige «Sí» o «No».',
        ])->validate();

        $antes = $this->ajustes($empresa);
        $texto = trim(str_replace("\r\n", "\n", (string) ($d['bolsa_presentacion'] ?? '')));
        $nuevos = [
            'activa' => filter_var($d['bolsa_activa'] ?? false, FILTER_VALIDATE_BOOL),
            'presentacion' => $texto === '' ? null : $texto,
            'indexar' => filter_var($d['bolsa_indexar'] ?? false, FILTER_VALIDATE_BOOL),
        ];
        $cambios = ['preferencias' => array_merge($empresa->preferencias ?? [], ['bolsa_trabajo' => $nuevos])];
        if ($nuevos['activa'] && $empresa->bolsa_slug === null) {
            $cambios['bolsa_slug'] = $this->slugNuevo($empresa);
        }
        $empresa->forceFill($cambios)->save();
        $this->auditoria->auditar($actor, 'vacantes.bolsa_configurada', $empresa, $antes, $nuevos + ['direccion' => $empresa->bolsa_slug]);
    }

    /** nombre-de-la-empresa-k3x9q2 (6 caracteres al azar: no se adivina). */
    private function slugNuevo(Empresa $empresa): string
    {
        $base = mb_substr(Str::slug($empresa->nombre_comercial) ?: 'empresa', 0, 60);
        do {
            $slug = trim($base, '-').'-'.Str::lower(Str::random(6));
        } while (Empresa::withTrashed()->where('bolsa_slug', $slug)->exists());

        return $slug;
    }

    // ------------------------------------------------------------------ Consulta pública

    /** Empresa con la bolsa encendida por su dirección (o null → 404). */
    public function empresaPorSlug(string $slug): ?Empresa
    {
        if (! preg_match('/^[a-z0-9-]{3,90}$/', $slug)) {
            return null;
        }
        $empresa = Empresa::where('bolsa_slug', $slug)->where('activo', true)->first();

        return $empresa !== null && $this->activa($empresa) ? $empresa : null;
    }

    /** Vacante publicada y vigente de esa empresa por su código (o null → 404). La empresa activa ya debe ser la de la bolsa. */
    public function vacantePublica(Empresa $empresa, string $codigo): ?Vacante
    {
        if (! preg_match('/^[a-z0-9]{10}$/', $codigo)) {
            return null;
        }

        return Vacante::with(['sedes:id,nombre', 'puesto:id,nombre', 'departamento:id,nombre', 'turno:id,nombre,hora_inicio,hora_fin'])
            ->where('codigo', $codigo)->vigentes(AdministradorVacantes::hoy($empresa))->first();
    }

    // ------------------------------------------------------------------ Postularse

    /**
     * El candidato se postula por internet (empresa activa = la de la bolsa).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function postular(Empresa $empresa, Vacante $vacante, array $entrada, ?UploadedFile $cv, string $ip): Candidato
    {
        $entrada['medio_vacante'] = $entrada['medio_vacante'] ?? 'bolsa_web';
        // Todos los errores juntos (la sede también)
        $errorSede = null;
        try {
            $sedeId = $this->sede($vacante, $entrada['sede_id'] ?? null);
        } catch (ValidationException $e) {
            $errorSede = $e->errors();
        }
        try {
            $d = $this->candidatos->validarCv($entrada, true, true);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(array_merge($e->errors(), $errorSede ?? []));
        }
        if ($errorSede !== null) {
            throw ValidationException::withMessages($errorSede);
        }

        $candidato = DB::transaction(function () use ($vacante, $d, $sedeId, $ip, $entrada, $cv) {
            $datos = $this->candidatos->soloCv($d);
            $candidato = new Candidato(array_merge($datos, [
                'sede_id' => $sedeId, 'departamento_id' => $vacante->departamento_id, 'puesto_id' => $vacante->puesto_id, 'vacante' => mb_substr($vacante->titulo, 0, 150),
                'origen' => 'web', 'llegada_en' => now(),
            ]));
            $candidato->forceFill(['vacante_id' => $vacante->id, 'autocaptura_pendiente' => true, 'autocaptura_en' => now()]);
            $this->candidatos->aceptarPrivacidad($candidato, $ip, 'web');
            $this->candidatos->firmar($candidato, is_string($entrada['firma'] ?? null) ? $entrada['firma'] : null, 'web', null, true);
            $candidato->save();
            if ($cv !== null) {
                app(DocumentosCandidato::class)->subir($candidato, $cv, 'cv', 'web', 'cv');
            }

            return $candidato;
        });

        $this->candidatos->evento($candidato, 'postulacion', null, 'registrado', 'Se postuló por internet a «'.$vacante->titulo.'».', null);
        Auditoria::create([
            'empresa_id' => $empresa->id, 'user_id' => null, 'evento' => 'candidatos.postulacion',
            'auditable_type' => Candidato::class, 'auditable_id' => $candidato->id, 'antes' => null,
            'despues' => ['origen' => 'web', 'vacante_id' => $vacante->id, 'sede_id' => $sedeId], 'ip' => mb_substr($ip, 0, 45),
        ]);
        $this->avisarRh($candidato, $vacante);

        return $candidato;
    }

    /** Sede de la postulación: la única de la vacante o la que eligió (de las de la vacante). */
    private function sede(Vacante $vacante, mixed $elegida): int
    {
        $opciones = $vacante->todas_las_sedes ? Sede::where('activo', true)->pluck('id')->all() : $vacante->sedes()->where('sedes.activo', true)->pluck('sedes.id')->all();
        if (count($opciones) === 1) {
            return (int) $opciones[0];
        }
        if (! is_numeric($elegida) || ! in_array((int) $elegida, array_map('intval', $opciones), true)) {
            throw ValidationException::withMessages(['sede_id' => 'Elige en qué sede te interesa trabajar.']);
        }

        return (int) $elegida;
    }

    /** Campana y correo a quien atiende candidatos en esa sede. Sin datos sensibles. */
    private function avisarRh(Candidato $candidato, Vacante $vacante): void
    {
        $candidato->loadMissing('sede:id,nombre');
        $rh = $this->destinatarios->conPermiso((int) $candidato->empresa_id, 'candidatos.editar', (int) $candidato->sede_id);
        $titulo = 'Nueva postulación por internet: '.$candidato->nombre_completo;
        $texto = 'Vacante: '.$vacante->titulo.'. Sede: '.$candidato->sede?->nombre.'. Revisa su solicitud y márcala como revisada.';
        $this->notificaciones->avisar((int) $candidato->empresa_id, $rh->pluck('id'), 'candidato_postulacion', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('candidatos.show', $candidato->id),
            'referencia_tipo' => 'candidato', 'referencia_id' => $candidato->id,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $candidato->empresa_id, 'candidato_llegada', $rh->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir su ficha', route('candidatos.show', $candidato->id)]]));
    }
}
