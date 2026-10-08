<?php

namespace App\Services\Recepcion;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;

/**
 * Lo que la Bitácora de accesos agrega para Recepción (ADR-0007), en un solo
 * lugar para no mezclarlo con las reglas de SEGCAT de RegistroAccesos:
 *
 *  - Personal externo → Recursos Humanos → «Viene como candidato»: puesto al
 *    que aplica y departamento; al guardar se crea su ficha y se avisa a RR. HH.
 *  - Personal externo → Visita a Departamento (o a un colaborador): si la
 *    empresa lo pide y el departamento tiene responsable, el acceso nace
 *    PENDIENTE («Esperando autorización») y se avisa al responsable.
 *  - Foto de la persona y de su identificación (opcionales, privadas).
 */
class RecepcionEnCaseta
{
    public function __construct(
        private readonly AjustesRecepcion $ajustes,
        private readonly Autorizaciones $autorizaciones,
    ) {}

    /**
     * Valida los datos de Recepción y decide si la visita espera autorización.
     *
     * @param  array<string, mixed>  $entrada  lo que llegó del formulario (incluye archivos)
     * @param  array<string, string>  $errores  se agregan aquí los mensajes
     * @return array{candidato: bool, departamento_id: ?int, puesto_id: ?int, vacante: ?string, esperar: bool, fotos: array<string, UploadedFile>}
     */
    public function preparar(string $tipo, ?string $motivo, array $entrada, int $sedeId, ?Colaborador $visita, array &$errores): array
    {
        $r = ['candidato' => false, 'departamento_id' => null, 'puesto_id' => null, 'vacante' => null, 'esperar' => false, 'fotos' => []];

        foreach (['foto_persona', 'foto_identificacion'] as $campo) {
            $archivo = $entrada[$campo] ?? null;
            if ($archivo instanceof UploadedFile) {
                $r['fotos'][$campo] = $archivo;
            } elseif ($archivo !== null && $archivo !== '') {
                $errores[$campo] = 'No se pudo recibir la foto. Tómala de nuevo.';
            }
        }
        if ($tipo !== 'visitante') {
            return $r;
        }

        $departamento = $this->departamento($entrada['departamento_id'] ?? null, $sedeId);
        if ($motivo === 'rh' && filter_var($entrada['es_candidato'] ?? false, FILTER_VALIDATE_BOOL)) {
            $r['candidato'] = true;
            $vacante = is_string($entrada['vacante'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $entrada['vacante'])) : '';
            $r['vacante'] = $vacante === '' ? null : mb_substr($vacante, 0, 150);
            if (! empty($entrada['puesto_id'])) {
                $r['puesto_id'] = Puesto::where('activo', true)->whereKey((int) $entrada['puesto_id'])->value('id');
                if ($r['puesto_id'] === null) {
                    $errores['puesto_id'] = 'Elige un puesto activo de la lista.';
                }
            }
            if (! empty($entrada['departamento_id']) && $departamento === null) {
                $errores['departamento_id'] = 'Elige un departamento activo de esta sede.';
            }
            $r['departamento_id'] = $departamento?->id;
            // Vacantes (lección 36): la vacante publicada que eligió la caseta
            $r = $this->vacante($r, $entrada['vacante_id'] ?? null, $sedeId, $errores);
            // Fin Vacantes

            return $r;
        }

        if ($motivo === 'departamento') {
            if ($departamento === null) {
                $errores['departamento_id'] = empty($entrada['departamento_id']) ? 'Indica a qué departamento va.' : 'Elige un departamento activo de esta sede.';

                return $r;
            }
            $r['departamento_id'] = $departamento->id;
        } elseif ($motivo === 'colaborador' && $visita?->departamento_id !== null) {
            $r['departamento_id'] = (int) $visita->departamento_id;
        }

        $empresa = Empresa::find(app(Tenant::class)->empresaId());
        $r['esperar'] = $r['departamento_id'] !== null && $empresa !== null && $this->ajustes->visitasRequierenAutorizacion($empresa)
            && $this->autorizaciones->tieneResponsables($r['departamento_id'], $sedeId);

        return $r;
    }

    /**
     * Después de guardar el acceso: fotos, ficha del candidato y solicitud al departamento.
     *
     * @param  array{candidato: bool, departamento_id: ?int, puesto_id: ?int, vacante: ?string, esperar: bool, fotos: array<string, UploadedFile>}  $r
     */
    public function despues(User $actor, Acceso $acceso, array $r): void
    {
        $cambios = [];
        $documentos = app(DocumentosCandidato::class);
        foreach ($r['fotos'] as $campo => $archivo) {
            $cambios[$campo] = $documentos->guardarFoto((int) $acceso->empresa_id, $archivo, $campo);
        }
        if ($r['esperar']) {
            $cambios['autorizacion'] = 'esperando';
        }
        if ($r['departamento_id'] !== null && $acceso->departamento_id === null) {
            $cambios['departamento_id'] = $r['departamento_id'];
        }
        if ($cambios !== []) {
            $acceso->forceFill($cambios)->saveQuietly();
        }

        if ($r['candidato']) {
            app(AdministradorCandidatos::class)->desdeAcceso($actor, $acceso, $r);
        }
        if ($r['esperar']) {
            $this->autorizaciones->solicitarVisita($actor, $acceso, (int) $r['departamento_id']);
        }
    }

    // Vacantes (lección 36)

    /**
     * «Viene como candidato» → vacante publicada y vigente de esa sede. Si la
     * caseta no eligió puesto o departamento, se toman de la vacante.
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, string>  $errores
     * @return array<string, mixed>
     */
    private function vacante(array $r, mixed $id, int $sedeId, array &$errores): array
    {
        if (empty($id) || ! is_numeric($id)) {
            return $r;
        }
        $empresa = Empresa::find(app(Tenant::class)->empresaId());
        $vacante = $empresa === null ? null : Vacante::vigentes(AdministradorVacantes::hoy($empresa))->aplicanEn([$sedeId])->whereKey((int) $id)->first();
        if ($vacante === null) {
            $errores['vacante_id'] = 'Elige una vacante publicada de esta sede.';

            return $r;
        }

        return array_merge($r, [
            'vacante_id' => $vacante->id,
            'vacante' => $r['vacante'] ?? mb_substr($vacante->titulo, 0, 150),
            'puesto_id' => $r['puesto_id'] ?? $vacante->puesto_id,
            'departamento_id' => $r['departamento_id'] ?? $vacante->departamento_id,
        ]);
    }
    // Fin Vacantes

    private function departamento(mixed $id, int $sedeId): ?Departamento
    {
        if (empty($id) || ! is_numeric($id)) {
            return null;
        }

        return Departamento::where('activo', true)->aplicanEn([$sedeId])->whereKey((int) $id)->first();
    }
}
