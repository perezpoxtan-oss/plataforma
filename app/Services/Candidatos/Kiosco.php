<?php

namespace App\Services\Candidatos;

use App\Mail\AvisoRecepcion;
use App\Mail\AvisoSolicitudCandidato;
use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\EnlaceKiosco;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Recepcion\Destinatarios;
use App\Support\CorreoPlataforma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kiosco de auto-registro: Recursos Humanos o la caseta generan un enlace
 * (QR y código corto) para UN candidato; él lo abre en su celular, sin
 * iniciar sesión, y llena su CV. Lo que envía llega a su ficha marcado
 * «Por revisar» para Recursos Humanos.
 *
 * Seguridad:
 *  - token aleatorio de 48 caracteres; en la base solo su huella SHA-256;
 *  - un enlace por candidato (generar otro anula el anterior), vence en
 *    pocas horas (2 por defecto) y se puede ENVIAR pocas veces (3 por defecto);
 *  - la página no se indexa, no usa JavaScript en línea y el formulario
 *    lleva token CSRF; las rutas tienen límite de peticiones por equipo;
 *  - el candidato no ve nada de nadie más: solo su propio formulario.
 */
class Kiosco
{
    /** Letras sin confusiones (sin 0/O, 1/I/L) para el código corto. */
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AjustesRecepcion $ajustes,
        private readonly AdministradorRoles $auditoria,
        private readonly AdministradorCandidatos $candidatos,
        private readonly CentroNotificaciones $notificaciones,
        private readonly Destinatarios $destinatarios,
    ) {}

    /**
     * Horas que vale el enlace cuando se manda por correo (ADR-0010): el
     * candidato lo abre desde su casa, no en la sala de espera.
     */
    public const HORAS_ENLACE_CORREO = 72;

    /**
     * Genera el enlace (anula el anterior). $actor null = lo generó la
     * plataforma sola (postulación corta por internet). $horas: cuánto vale;
     * por omisión, las horas del kiosco de la empresa.
     *
     * @return array{enlace: EnlaceKiosco, token: string, url: string}
     */
    public function generar(?User $actor, Candidato $candidato, ?int $horas = null): array
    {
        if (in_array($candidato->etapa, ['contratado', 'rechazado'], true)) {
            throw new CambioNoPermitido('Este candidato ya no está en proceso: no se le puede generar un enlace.');
        }
        $empresa = Empresa::findOrFail($candidato->empresa_id);
        $token = Str::random(48);
        $horas = max(1, $horas ?? $this->ajustes->horasKiosco($empresa));

        $enlace = DB::transaction(function () use ($candidato, $empresa, $token, $horas) {
            EnlaceKiosco::where('candidato_id', $candidato->id)->whereNull('revocado_en')->update(['revocado_en' => now()]);

            return EnlaceKiosco::create([
                'candidato_id' => $candidato->id, 'token_hash' => hash('sha256', $token), 'codigo' => $this->codigoNuevo(),
                'expira_en' => now()->addHours($horas), 'usos_maximos' => $this->ajustes->usosKiosco($empresa),
            ]);
        });
        $despues = ['codigo' => $enlace->codigo, 'expira_en' => $enlace->expira_en->toIso8601String(), 'usos_maximos' => $enlace->usos_maximos];
        if ($actor !== null) {
            $this->auditoria->auditar($actor, 'candidatos.enlace_kiosco', $candidato, null, $despues);
        } else {
            Auditoria::create(['empresa_id' => $candidato->empresa_id, 'user_id' => null, 'evento' => 'candidatos.enlace_kiosco',
                'auditable_type' => Candidato::class, 'auditable_id' => $candidato->id, 'antes' => null, 'despues' => $despues + ['origen' => 'web']]);
        }

        return ['enlace' => $enlace, 'token' => $token, 'url' => route('kiosco.mostrar', $token)];
    }

    /**
     * Le manda al candidato, por correo, un enlace nuevo (HORAS_ENLACE_CORREO)
     * para que llene su solicitud completa desde su celular o computadora.
     * Devuelve false si no tiene correo o la plataforma no tiene correo
     * configurado; en ambos casos queda anotado en su historial.
     */
    public function enviarEnlacePorCorreo(?User $actor, Candidato $candidato, ?Empresa $empresa = null): bool
    {
        if ($candidato->correo === null || trim((string) $candidato->correo) === '') {
            $this->candidatos->evento($candidato, 'correo_candidato', null, null, 'No se le pudo mandar el enlace de su solicitud: no tiene correo capturado.', $actor);

            return false;
        }
        if (! app(CorreoPlataforma::class)->configurado()) {
            $this->candidatos->evento($candidato, 'correo_candidato', null, null, 'No se le pudo mandar el enlace de su solicitud: la plataforma no tiene correo configurado.', $actor);

            return false;
        }
        $empresa ??= Empresa::findOrFail($candidato->empresa_id);
        $generado = $this->generar($actor, $candidato, self::HORAS_ENLACE_CORREO);
        app(AvisosCorreo::class)->alCandidato($candidato->correo, new AvisoSolicitudCandidato(
            (string) ($candidato->partesNombre()['nombre'] ?: $candidato->nombre_completo),
            (string) ($empresa->nombre_comercial ?? ''),
            $generado['url'],
            self::HORAS_ENLACE_CORREO,
        ));
        $this->candidatos->evento($candidato, 'correo_candidato', null, null,
            'Se le mandó por correo el enlace para completar su solicitud (vale '.self::HORAS_ENLACE_CORREO.' horas).', $actor);
        if ($actor !== null) {
            $this->auditoria->auditar($actor, 'candidatos.correo_candidato', $candidato, null, ['enlace_solicitud' => $generado['enlace']->codigo]);
        }

        return true;
    }

    public function revocar(User $actor, Candidato $candidato): int
    {
        $total = EnlaceKiosco::where('candidato_id', $candidato->id)->whereNull('revocado_en')->where('expira_en', '>', now())->update(['revocado_en' => now()]);
        if ($total > 0) {
            $this->auditoria->auditar($actor, 'candidatos.enlace_revocado', $candidato, null, ['enlaces' => $total]);
        }

        return $total;
    }

    /** Enlace por su token (de cualquier empresa: aún no hay sesión ni empresa activa). */
    public function porToken(string $token): ?EnlaceKiosco
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        return EnlaceKiosco::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    /** Enlace vigente por el código corto que muestra la tableta de recepción. */
    public function porCodigo(string $codigo): ?EnlaceKiosco
    {
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');
        if (strlen($codigo) !== 6) {
            return null;
        }
        $enlace = EnlaceKiosco::withoutGlobalScopes()->where('codigo', $codigo)->whereNull('revocado_en')->where('expira_en', '>', now())
            ->orderByDesc('id')->first();

        return $enlace?->vigente() ? $enlace : null;
    }

    /**
     * Para canjear el código: un token nuevo para ese mismo enlace (el código
     * se teclea; el token viaja solo en la dirección).
     */
    public function tokenParaCodigo(EnlaceKiosco $enlace): string
    {
        $token = Str::random(48);
        $enlace->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /**
     * El candidato envía su CV (empresa activa = la del enlace).
     *
     * @param  array<string, mixed>  $entrada
     * @param  array<string, UploadedFile|null>  $archivos  cv, ine, comprobante
     */
    public function guardar(EnlaceKiosco $enlace, array $entrada, array $archivos, string $ip): Candidato
    {
        $candidato = Candidato::findOrFail($enlace->candidato_id);
        // Solicitud completa: declaración, firma y las referencias personales mínimas (AdministradorCandidatos::REFERENCIAS_MINIMAS)
        $d = $this->candidatos->validarCv($entrada, true, true);
        foreach ($archivos as $campo => $archivo) {
            if ($archivo !== null && ! $archivo instanceof UploadedFile) {
                throw ValidationException::withMessages([$campo => 'No se pudo recibir el archivo.']);
            }
        }

        DB::transaction(function () use ($enlace, $candidato, $d, $ip, $archivos, $entrada) {
            // Un uso más, solo si el enlace sigue vigente (dos envíos a la vez no pasan del límite)
            $hecho = EnlaceKiosco::withoutGlobalScopes()->whereKey($enlace->id)->whereNull('revocado_en')->where('expira_en', '>', now())
                ->whereColumn('usos', '<', 'usos_maximos')->update(['usos' => DB::raw('usos + 1'), 'ultimo_uso_en' => now(), 'updated_at' => now()]);
            if ($hecho === 0) {
                throw new CambioNoPermitido('Este enlace ya venció o ya se usó. Pide uno nuevo en recepción.');
            }
            $cv = $this->candidatos->soloCv($d);
            // El departamento y el puesto los decide Recursos Humanos (el kiosco no los cambia)
            unset($cv['departamento_id'], $cv['puesto_id']);
            $cv['vacante'] = $cv['vacante'] ?? $candidato->vacante;
            $candidato->fill($cv);
            $this->candidatos->aceptarPrivacidad($candidato, $ip, 'kiosco');
            $this->candidatos->firmar($candidato, $entrada['firma'], 'kiosco', null, true);
            // Ya llenó la solicitud completa: deja de estar «Solicitud incompleta» (postulación corta por internet)
            $candidato->forceFill(['autocaptura_pendiente' => true, 'autocaptura_en' => now(), 'solicitud_corta' => false])->save();
            app(Postulaciones::class)->desdeFicha($candidato); // A qué aplica: lo manda la postulación activa

            $documentos = app(DocumentosCandidato::class);
            foreach (['cv', 'ine', 'comprobante'] as $tipo) {
                if (($archivos[$tipo] ?? null) instanceof UploadedFile) {
                    $documentos->subir($candidato, $archivos[$tipo], $tipo, 'kiosco', $tipo);
                }
            }
        });

        $this->candidatos->evento($candidato, 'autocaptura', null, null, 'El candidato llenó su CV en el kiosco.', null);
        Auditoria::create([
            'empresa_id' => $candidato->empresa_id, 'user_id' => null, 'evento' => 'candidatos.autocaptura',
            'auditable_type' => Candidato::class, 'auditable_id' => $candidato->id, 'antes' => null,
            'despues' => ['origen' => 'kiosco', 'codigo' => $enlace->codigo], 'ip' => $ip,
        ]);

        $rh = $this->destinatarios->conPermiso((int) $candidato->empresa_id, 'candidatos.editar', (int) $candidato->sede_id);
        $this->notificaciones->avisar((int) $candidato->empresa_id, $rh->pluck('id'), 'candidato_autocaptura', [
            'titulo' => $candidato->nombre_completo.' llenó su CV en el kiosco',
            'texto' => 'Revisa lo que capturó y márcalo como revisado.',
            'url' => route('candidatos.show', $candidato->id), 'referencia_tipo' => 'candidato', 'referencia_id' => $candidato->id,
        ]);
        app(AvisosCorreo::class)->recepcion((int) $candidato->empresa_id, 'candidato_llegada', $rh->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($candidato->nombre_completo.' llenó su CV en el kiosco', ['Revisa lo que capturó y márcalo como revisado.'],
                [['Abrir su ficha', route('candidatos.show', $candidato->id)]]));

        return $candidato;
    }

    private function codigoNuevo(): string
    {
        do {
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }
        } while (EnlaceKiosco::withoutGlobalScopes()->where('codigo', $codigo)->where('expira_en', '>', now())->exists());

        return $codigo;
    }
}
