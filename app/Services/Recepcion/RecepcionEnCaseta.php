<?php

namespace App\Services\Recepcion;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\Entrada;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;

/**
 * Lo que la Bitácora de accesos agrega para Recepción (ADR-0007), en un solo
 * lugar para no mezclarlo con las reglas de SEGCAT de RegistroAccesos:
 *
 *  - Personal externo → Recursos Humanos → «¿A qué viene?»: Busca empleo (con
 *    «¿A qué vacante?»), Entrevista, Entrega de documentos, Firma de contrato,
 *    Informes / ver vacantes u Otro trámite. Las cuatro primeras crean o usan
 *    la ficha del candidato (una por persona); las dos últimas no.
 *    Si la empresa lo pide (ajuste «La caseta espera a que RR. HH. diga «Que
 *    pase»») y hay quien atienda en esa sede, el acceso nace PENDIENTE
 *    («Esperando autorización» de RR. HH.) y RR. HH. responde Que pase / Que
 *    espere / No puede pasar. La caseta nunca ve etapas ni datos del CV.
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
     * @return array{candidato: bool, viene_a: ?string, vacante_datos: array<string, mixed>, departamento_id: ?int, esperar: bool, esperar_rh: bool, fotos: array<string, UploadedFile>}
     */
    public function preparar(string $tipo, ?string $motivo, array $entrada, int $sedeId, ?Colaborador $visita, array &$errores): array
    {
        $r = ['candidato' => false, 'viene_a' => null, 'vacante_datos' => [], 'departamento_id' => null, 'esperar' => false, 'esperar_rh' => false, 'fotos' => []];

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

        if ($motivo === 'rh') {
            $vieneA = Entrada::texto($entrada['viene_a'] ?? null);
            if ($vieneA === '') {
                // Formularios de antes (casilla «Viene como candidato»)
                $vieneA = filter_var($entrada['es_candidato'] ?? false, FILTER_VALIDATE_BOOL) ? 'busca_empleo' : 'tramite';
            }
            if (! array_key_exists($vieneA, Acceso::VIENE_A)) {
                $errores['viene_a'] = 'Elige a qué viene de la lista.';

                return $r;
            }
            $r['viene_a'] = $vieneA;
            $r['candidato'] = $vieneA === 'busca_empleo' || in_array($vieneA, Acceso::VIENE_CON_FICHA, true);
            if ($vieneA === 'busca_empleo') {
                // La vacante deduce el puesto y el departamento (o los pone RR. HH. después)
                $r['vacante_datos'] = $this->vacante($entrada['vacante_id'] ?? null, $sedeId, $errores);
            }
            $empresa = Empresa::find(app(Tenant::class)->empresaId());
            $r['esperar_rh'] = $empresa !== null && $this->ajustes->rhAutorizaPaso($empresa)
                && $this->autorizaciones->destinatariosRecepcion((int) $empresa->id, $sedeId)->isNotEmpty();
            $r['esperar'] = $r['esperar_rh'];

            return $r;
        }

        $departamento = $this->departamento($entrada['departamento_id'] ?? null, $sedeId);
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
     * Después de guardar el acceso: fotos, ficha del candidato y la solicitud
     * de autorización (a RR. HH. o al departamento).
     *
     * @param  array{candidato: bool, viene_a: ?string, vacante_datos: array<string, mixed>, departamento_id: ?int, esperar: bool, esperar_rh: bool, fotos: array<string, UploadedFile>}  $r
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
        if ($r['viene_a'] !== null) {
            $cambios['viene_a'] = $r['viene_a'];
        }
        if ($r['departamento_id'] !== null && $acceso->departamento_id === null) {
            $cambios['departamento_id'] = $r['departamento_id'];
        }
        if ($cambios !== []) {
            $acceso->forceFill($cambios)->saveQuietly();
        }

        $candidato = null;
        if ($r['candidato']) {
            $candidato = app(AdministradorCandidatos::class)->desdeAcceso($actor, $acceso,
                $r['vacante_datos'] + ['viene_a' => $r['viene_a'], 'esperar_rh' => $r['esperar_rh']]);
            $acceso->refresh();
        }
        if ($r['esperar_rh']) {
            $this->autorizaciones->solicitarRecepcion($actor, $acceso, $candidato);
        } elseif ($r['esperar']) {
            $this->autorizaciones->solicitarVisita($actor, $acceso, (int) $r['departamento_id']);
        }
    }

    // Vacantes (lección 36)

    /**
     * «Busca empleo» → «¿A qué vacante?»: vacante publicada y vigente de esa
     * sede (o «No sabe / otra»). El puesto y el departamento salen de ella.
     *
     * @param  array<string, string>  $errores
     * @return array<string, mixed>
     */
    private function vacante(mixed $id, int $sedeId, array &$errores): array
    {
        if (empty($id) || ! is_numeric($id)) {
            return [];
        }
        $empresa = Empresa::find(app(Tenant::class)->empresaId());
        $vacante = $empresa === null ? null : Vacante::vigentes(AdministradorVacantes::hoy($empresa))->aplicanEn([$sedeId])->whereKey((int) $id)->first();
        if ($vacante === null) {
            $errores['vacante_id'] = 'Elige una vacante publicada de esta sede.';

            return [];
        }

        return ['vacante_id' => $vacante->id, 'vacante' => mb_substr($vacante->titulo, 0, 150), 'puesto_id' => $vacante->puesto_id,
            'departamento_id' => $vacante->departamento_id];
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
