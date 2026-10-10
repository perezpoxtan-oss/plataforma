<?php

namespace App\Services\Candidatos;

use App\Mail\AvisoRecepcion;
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
     * @return array{enlace: EnlaceKiosco, token: string, url: string}
     */
    public function generar(User $actor, Candidato $candidato): array
    {
        if (in_array($candidato->etapa, ['contratado', 'descartado'], true)) {
            throw new CambioNoPermitido('Este candidato ya no está en proceso: no se le puede generar un enlace.');
        }
        $empresa = Empresa::findOrFail($candidato->empresa_id);
        $token = Str::random(48);

        $enlace = DB::transaction(function () use ($candidato, $empresa, $token) {
            EnlaceKiosco::where('candidato_id', $candidato->id)->whereNull('revocado_en')->update(['revocado_en' => now()]);

            return EnlaceKiosco::create([
                'candidato_id' => $candidato->id, 'token_hash' => hash('sha256', $token), 'codigo' => $this->codigoNuevo(),
                'expira_en' => now()->addHours($this->ajustes->horasKiosco($empresa)), 'usos_maximos' => $this->ajustes->usosKiosco($empresa),
            ]);
        });
        $this->auditoria->auditar($actor, 'candidatos.enlace_kiosco', $candidato, null,
            ['codigo' => $enlace->codigo, 'expira_en' => $enlace->expira_en->toIso8601String(), 'usos_maximos' => $enlace->usos_maximos]);

        return ['enlace' => $enlace, 'token' => $token, 'url' => route('kiosco.mostrar', $token)];
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
        // Solicitud completa: declaración, firma y 2 referencias personales (lección 36)
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
            $candidato->forceFill(['autocaptura_pendiente' => true, 'autocaptura_en' => now()])->save();
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
