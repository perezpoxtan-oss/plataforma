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
use App\Services\Candidatos\Kiosco;
use App\Services\Candidatos\Postulaciones;
use App\Services\Firmas\Firmas;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use App\Support\Entrada;
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

        $firma = is_string($entrada['firma'] ?? null) ? $entrada['firma'] : null;
        if (! app(Firmas::class)->viene($firma)) {
            throw ValidationException::withMessages(['firma' => 'Falta tu firma: firma en el recuadro con el dedo antes de enviar.']);
        }

        // Una ficha por persona: mismo teléfono o CURP = su ficha de siempre. Seguridad (BOL-04): un envío
        // sin sesión NUNCA cambia los datos de una ficha que ya existía (nombre, correo, domicilio, firma,
        // aviso aceptado): solo se le suma la postulación y RR. HH. ve en el historial lo que escribió distinto.
        $existente = $this->candidatos->buscarFicha(null, $d['telefono'] ?? null, $d['curp'] ?? null);
        $postulaciones = app(Postulaciones::class);
        $siguioAbierta = false;
        $distintos = $existente === null ? null : $this->datosDistintos($existente, $d);

        $candidato = DB::transaction(function () use ($vacante, $d, $sedeId, $ip, $firma, $cv, $existente, $postulaciones, &$siguioAbierta) {
            if ($existente === null) {
                $datos = $this->candidatos->soloCv($d);
                unset($datos['departamento_id'], $datos['puesto_id'], $datos['vacante']);
                $candidato = new Candidato(['sede_id' => $sedeId, 'origen' => 'web', 'llegada_en' => now()]);
                $candidato->fill($datos);
                $candidato->forceFill(['autocaptura_pendiente' => true, 'autocaptura_en' => now(), 'llegada_en' => now()]);
                $this->candidatos->aceptarPrivacidad($candidato, $ip, 'web');
                $this->candidatos->firmar($candidato, $firma, 'web', null, true);
                $candidato->save();
            } else {
                $candidato = $existente;
                $candidato->forceFill(['llegada_en' => now()])->save();
            }

            $aVacante = ['sede_id' => $sedeId, 'vacante_id' => $vacante->id, 'departamento_id' => $vacante->departamento_id, 'puesto_id' => $vacante->puesto_id,
                'vacante' => mb_substr($vacante->titulo, 0, 150)];
            $abierta = $existente === null ? null : $postulaciones->abierta($candidato);
            if ($abierta === null) {
                $postulaciones->crear($candidato, $aVacante, 'web', null);
            } else {
                $siguioAbierta = true;
                if ($abierta->vacante_id === null) {
                    // Estaba en proceso «a lo que haya»: ahora dice a qué vacante
                    $abierta->forceFill(array_diff_key($aVacante, ['sede_id' => true]))->save();
                    $postulaciones->reflejar($abierta);
                }
            }
            if ($cv !== null) {
                app(DocumentosCandidato::class)->subir($candidato, $cv, 'cv', 'web', 'cv');
            }

            return $candidato->refresh();
        });

        $this->candidatos->evento($candidato, 'postulacion', null, $siguioAbierta ? null : 'registrado', match (true) {
            $existente === null => 'Se postuló por internet a «'.$vacante->titulo.'».',
            $siguioAbierta => 'Se volvió a postular por internet a «'.$vacante->titulo.'» (ya tenía un proceso abierto: sigue en el mismo).',
            default => 'Se volvió a postular por internet a «'.$vacante->titulo.'»: nueva postulación en su misma ficha.',
        }.($existente === null ? '' : ' '.$this->notaSinCambios($distintos, true)), null);
        Auditoria::create([
            'empresa_id' => $empresa->id, 'user_id' => null, 'evento' => 'candidatos.postulacion',
            'auditable_type' => Candidato::class, 'auditable_id' => $candidato->id, 'antes' => null,
            'despues' => ['origen' => 'web', 'vacante_id' => $vacante->id, 'sede_id' => $sedeId, 'ficha_existente' => $existente !== null]
                + ($distintos ? ['datos_no_aplicados' => array_keys($distintos)] : []), 'ip' => mb_substr($ip, 0, 45),
        ]);
        $this->avisarRh($candidato, $vacante, false, (bool) $distintos);

        return $candidato;
    }

    /**
     * Postulación corta por internet (ADR-0010): nombre, teléfono, correo, CV
     * opcional y el aviso de privacidad. Crea (o reutiliza) la ficha y la
     * postulación, deja la ficha «Solicitud incompleta» (solicitud_corta) y
     * le manda al candidato, por correo, el enlace del kiosco para que
     * complete su solicitud cuando pueda. Recursos Humanos recibe el aviso.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function postularCorta(Empresa $empresa, Vacante $vacante, array $entrada, ?UploadedFile $cv, string $ip): Candidato
    {
        $errorSede = null;
        try {
            $sedeId = $this->sede($vacante, $entrada['sede_id'] ?? null);
        } catch (ValidationException $e) {
            $errorSede = $e->errors();
        }
        $entrada = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $entrada);
        if (isset($entrada['telefono']) && is_string($entrada['telefono'])) {
            $entrada['telefono'] = preg_replace('/[\s\-().]+/', '', $entrada['telefono']);
        }
        try {
            $d = Validator::make($entrada, [
                'nombre_completo' => ['required', 'string', 'min:5', 'max:150'],
                'telefono' => ['required', 'regex:/^\d{10,15}$/'],
                'correo' => ['required', 'email:rfc', 'max:150'],
                'acepta_privacidad' => ['accepted'],
            ], [
                'nombre_completo.required' => 'Escribe tu nombre completo.',
                'nombre_completo.min' => 'Escribe tu nombre completo (nombre y apellidos).',
                'telefono.required' => 'Escribe tu teléfono celular.',
                'telefono.regex' => 'El teléfono lleva de 10 a 15 dígitos, sin letras.',
                'correo.required' => 'Escribe tu correo: ahí te mandaremos el enlace para completar tu solicitud.',
                'correo.email' => 'Revisa tu correo: parece incompleto.',
                'acepta_privacidad.accepted' => 'Para enviar, marca «Acepto el aviso de privacidad».',
            ])->validate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(array_merge($e->errors(), $errorSede ?? []));
        }
        if ($errorSede !== null) {
            throw ValidationException::withMessages($errorSede);
        }
        $d['nombre_completo'] = Entrada::texto($d['nombre_completo']);
        $d['correo'] = mb_strtolower(trim((string) $d['correo']));

        // Seguridad (BOL-04): igual que la solicitud completa, una ficha que ya existía no se toca
        $existente = $this->candidatos->buscarFicha(null, $d['telefono'], null);
        $postulaciones = app(Postulaciones::class);
        $siguioAbierta = false;
        $distintos = $existente === null ? null : $this->datosDistintos($existente, $d);

        $candidato = DB::transaction(function () use ($vacante, $d, $sedeId, $ip, $cv, $existente, $postulaciones, &$siguioAbierta) {
            if ($existente === null) {
                $candidato = new Candidato(['sede_id' => $sedeId, 'origen' => 'web', 'llegada_en' => now()]);
                $candidato->fill(['nombre_completo' => $d['nombre_completo'], 'telefono' => $d['telefono'], 'correo' => $d['correo'], 'medio_vacante' => 'bolsa_web']);
                $candidato->forceFill(['llegada_en' => now(), 'solicitud_corta' => true]);
                $this->candidatos->aceptarPrivacidad($candidato, $ip, 'web');
                $candidato->save();
            } else {
                $candidato = $existente;
                $candidato->forceFill(['llegada_en' => now()])->save();
            }

            $aVacante = ['sede_id' => $sedeId, 'vacante_id' => $vacante->id, 'departamento_id' => $vacante->departamento_id, 'puesto_id' => $vacante->puesto_id,
                'vacante' => mb_substr($vacante->titulo, 0, 150)];
            $abierta = $existente === null ? null : $postulaciones->abierta($candidato);
            if ($abierta === null) {
                $postulaciones->crear($candidato, $aVacante, 'web', null);
            } else {
                $siguioAbierta = true;
                if ($abierta->vacante_id === null) {
                    $abierta->forceFill(array_diff_key($aVacante, ['sede_id' => true]))->save();
                    $postulaciones->reflejar($abierta);
                }
            }
            if ($cv !== null) {
                app(DocumentosCandidato::class)->subir($candidato, $cv, 'cv', 'web', 'cv');
            }

            return $candidato->refresh();
        });

        $this->candidatos->evento($candidato, 'postulacion', null, $siguioAbierta ? null : 'registrado', match (true) {
            $existente === null => 'Se postuló por internet a «'.$vacante->titulo.'» con la solicitud corta (falta la solicitud completa).',
            $siguioAbierta => 'Se volvió a postular por internet a «'.$vacante->titulo.'» (ya tenía un proceso abierto: sigue en el mismo).',
            default => 'Se volvió a postular por internet a «'.$vacante->titulo.'»: nueva postulación en su misma ficha.',
        }.($existente === null ? '' : ' '.$this->notaSinCambios($distintos, false)), null);
        Auditoria::create([
            'empresa_id' => $empresa->id, 'user_id' => null, 'evento' => 'candidatos.postulacion',
            'auditable_type' => Candidato::class, 'auditable_id' => $candidato->id, 'antes' => null,
            'despues' => ['origen' => 'web', 'corta' => true, 'vacante_id' => $vacante->id, 'sede_id' => $sedeId, 'ficha_existente' => $existente !== null]
                + ($distintos ? ['datos_no_aplicados' => array_keys($distintos)] : []), 'ip' => mb_substr($ip, 0, 45),
        ]);
        // El enlace para completar su solicitud le llega por correo (si la plataforma tiene correo configurado).
        // A una ficha que ya existía se le manda SIEMPRE al correo que ya tenía, nunca al que se escribió ahora.
        if ($candidato->solicitud_corta) {
            app(Kiosco::class)->enviarEnlacePorCorreo(null, $candidato, $empresa);
        }
        $this->avisarRh($candidato, $vacante, (bool) $candidato->solicitud_corta, (bool) $distintos);

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

    /**
     * Datos que se escribieron en el envío público y no coinciden con la ficha
     * que ya existía (solo nombre y correo, los que identifican a la persona).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, string>
     */
    private function datosDistintos(Candidato $ficha, array $d): array
    {
        $comparar = fn (?string $v) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii((string) $v))));
        $distintos = [];
        foreach (['nombre_completo' => 'nombre', 'correo' => 'correo'] as $campo => $etiqueta) {
            $nuevo = is_string($d[$campo] ?? null) ? trim($d[$campo]) : '';
            if ($nuevo !== '' && $comparar($nuevo) !== $comparar($ficha->getAttribute($campo))) {
                $distintos[$etiqueta] = mb_substr($nuevo, 0, 120);
            }
        }

        return $distintos;
    }

    /** Renglón del historial: el envío no cambió la ficha (y qué escribió distinto). */
    private function notaSinCambios(?array $distintos, bool $completa): string
    {
        $nota = $completa
            ? 'Los datos de la solicitud que envió no se aplicaron a su ficha (un envío por internet no cambia una ficha existente): si cambiaron, actualízalos con «Editar solicitud» o mándale el enlace para llenarla.'
            : 'Los datos que escribió no se aplicaron a su ficha (un envío por internet no cambia una ficha existente).';
        if ($distintos) {
            $nota .= ' Escribió distinto: '.implode(', ', array_map(fn ($k, $v) => $k.' «'.$v.'»', array_keys($distintos), $distintos))
                .'. Confírmalo con la persona antes de usarlo.';
        }

        return $nota;
    }

    /** Campana y correo a quien atiende candidatos en esa sede. Sin datos sensibles. */
    private function avisarRh(Candidato $candidato, Vacante $vacante, bool $corta = false, bool $datosDistintos = false): void
    {
        $candidato->loadMissing('sede:id,nombre');
        $rh = $this->destinatarios->conPermiso((int) $candidato->empresa_id, 'candidatos.editar', (int) $candidato->sede_id);
        $titulo = 'Nueva postulación por internet: '.$candidato->nombre_completo;
        $texto = 'Vacante: '.$vacante->titulo.'. Sede: '.$candidato->sede?->nombre.'. '.($corta
            ? 'Solicitud incompleta: le mandamos el enlace para completarla; también puedes dárselo desde su ficha.'
            : 'Revisa su solicitud y márcala como revisada.')
            .($datosDistintos ? ' Ojo: escribió un nombre o correo distinto al de su ficha; revisa su historial.' : '');
        $this->notificaciones->avisar((int) $candidato->empresa_id, $rh->pluck('id'), 'candidato_postulacion', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('candidatos.show', $candidato->id),
            'referencia_tipo' => 'candidato', 'referencia_id' => $candidato->id,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $candidato->empresa_id, 'candidato_llegada', $rh->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Abrir su ficha', route('candidatos.show', $candidato->id)]]));
    }
}
