<?php

namespace App\Services\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aviso de privacidad con versiones (docs/tecnico/privacidad.md).
 *
 *  - De cada empresa: el texto vive en preferencias.recepcion.aviso_privacidad
 *    (lo que ya mostraban el kiosco, la solicitud de empleo y la bolsa de
 *    trabajo) y cada texto distinto que se publica queda como versión 1, 2, 3…
 *  - De la plataforma (empresa nula): el de la página pública de planes
 *    (Catálogo comercial → Ajustes). Si el texto cambió desde la última
 *    versión, la siguiente aceptación crea la versión nueva sola.
 *  - Donde una persona acepta el aviso se guarda la versión aceptada
 *    (candidatos.aviso_privacidad_id, solicitudes_compra.aviso_privacidad_id).
 */
class AvisosPrivacidad
{
    public const MAX_CARACTERES = 20000;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /** @return Builder<AvisoPrivacidad> */
    private function de(?int $empresaId): Builder
    {
        $q = AvisoPrivacidad::query()->withoutGlobalScope(EmpresaScope::class);

        return $empresaId === null ? $q->whereNull('empresa_id') : $q->where('empresa_id', $empresaId);
    }

    /** Texto vigente: el de la empresa (o el borrador) o el de la plataforma. */
    public function textoActual(?Empresa $empresa): string
    {
        return $empresa !== null
            ? app(AjustesRecepcion::class)->textoPrivacidad($empresa)
            : trim((string) app(AjustesComerciales::class)->get('aviso_privacidad'));
    }

    /**
     * Versión vigente. Si todavía no hay ninguna, o el texto cambió por otra
     * pantalla, se crea la siguiente con el texto actual. Null solo para la
     * plataforma sin aviso escrito.
     */
    public function vigente(?Empresa $empresa, ?User $actor = null): ?AvisoPrivacidad
    {
        $texto = $this->textoActual($empresa);
        if (trim($texto) === '') {
            return null;
        }
        $ultima = $this->de($empresa?->id)->orderByDesc('numero')->first();
        if ($ultima !== null && hash_equals($ultima->huella, hash('sha256', $texto))) {
            return $ultima;
        }

        return $this->crearVersion($empresa?->id, $texto, $actor);
    }

    /**
     * «Publicar nueva versión» (Configuración → Privacidad): guarda el texto
     * como el aviso de la empresa y crea la versión. Un texto igual al
     * vigente no crea versión.
     */
    public function publicar(User $actor, Empresa $empresa, string $texto): AvisoPrivacidad
    {
        $texto = trim(str_replace("\r\n", "\n", $texto));
        if ($texto === '' || mb_strlen($texto) > self::MAX_CARACTERES) {
            throw ValidationException::withMessages(['texto' => 'Escribe el aviso (máximo '.number_format(self::MAX_CARACTERES).' caracteres).']);
        }
        $anterior = $this->vigente($empresa);
        if ($anterior !== null && hash_equals($anterior->huella, hash('sha256', $texto))) {
            throw ValidationException::withMessages(['texto' => 'Es el mismo texto de la versión '.$anterior->numero.': cambia algo para publicar una nueva.']);
        }
        $preferencias = $empresa->preferencias ?? [];
        $recepcion = is_array($preferencias['recepcion'] ?? null) ? $preferencias['recepcion'] : [];
        $recepcion['aviso_privacidad'] = $texto;
        $preferencias['recepcion'] = $recepcion;
        $empresa->forceFill(['preferencias' => $preferencias])->save();

        $nueva = $this->crearVersion($empresa->id, $texto, $actor);
        $this->auditoria->auditar($actor, 'privacidad.aviso_publicado', $nueva, ['version' => $anterior?->numero], ['version' => $nueva->numero, 'huella' => $nueva->huella]);

        return $nueva;
    }

    /** Lo llama Recepción → Ajustes al guardar otro texto del aviso: queda como versión con su autor. */
    public function registrarCambio(User $actor, Empresa $empresa): void
    {
        $antes = $this->de($empresa->id)->max('numero');
        $nueva = $this->vigente($empresa->refresh(), $actor);
        if ($nueva !== null && (int) $nueva->numero !== (int) $antes) {
            $this->auditoria->auditar($actor, 'privacidad.aviso_publicado', $nueva, ['version' => $antes], ['version' => $nueva->numero, 'huella' => $nueva->huella]);
        }
    }

    /** @return Collection<int, AvisoPrivacidad> */
    public function historial(?int $empresaId, int $cuantas = 20): Collection
    {
        return $this->de($empresaId)->with('publicador:id,name')->orderByDesc('numero')->limit($cuantas)->get();
    }

    public function version(?int $empresaId, int $id): ?AvisoPrivacidad
    {
        return $this->de($empresaId)->find($id);
    }

    /** Código público de la empresa para /privacidad/{codigo} (se crea la primera vez). */
    public function codigoPublico(Empresa $empresa): string
    {
        if (is_string($empresa->privacidad_codigo) && $empresa->privacidad_codigo !== '') {
            return $empresa->privacidad_codigo;
        }
        do {
            $codigo = Str::lower(Str::random(12));
        } while (! preg_match('/^[a-z0-9]{12}$/', $codigo) || Empresa::withTrashed()->where('privacidad_codigo', $codigo)->exists());
        $empresa->forceFill(['privacidad_codigo' => $codigo])->saveQuietly();

        return $codigo;
    }

    public function empresaPorCodigo(string $codigo): ?Empresa
    {
        return preg_match('/^[a-z0-9]{12}$/', $codigo) ? Empresa::where('privacidad_codigo', $codigo)->where('activo', true)->first() : null;
    }

    private function crearVersion(?int $empresaId, string $texto, ?User $actor): AvisoPrivacidad
    {
        return $this->tenant->conEmpresa($empresaId, function () use ($empresaId, $texto, $actor) {
            $aviso = new AvisoPrivacidad([
                'numero' => (int) $this->de($empresaId)->max('numero') + 1,
                'texto' => $texto,
                'huella' => hash('sha256', $texto),
                'publicado_en' => now(),
                'publicado_por' => $actor?->id,
            ]);
            $aviso->forceFill(['empresa_id' => $empresaId])->save();

            return $aviso;
        });
    }
}
