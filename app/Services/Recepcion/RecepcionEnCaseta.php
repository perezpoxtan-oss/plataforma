<?php

namespace App\Services\Recepcion;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Accesos\AQuienViene;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Permisos\Autorizador;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\Entrada;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;

/**
 * Lo que la Bitácora de accesos agrega para Recepción (ADR-0007), en un solo
 * lugar para no mezclarlo con las reglas de SEGCAT de RegistroAccesos:
 *
 *  - Personal externo → Recursos Humanos → «¿A qué viene?» (tres opciones):
 *    Busca empleo (con «¿A qué vacante?» si hay vacantes publicadas), Ya tiene
 *    cita (la plataforma busca su ficha y deduce si viene a su entrevista, a
 *    entregar documentos o a firmar; sin ficha, queda como Busca empleo) u
 *    Otro trámite de RR. HH. (sin ficha). El enlace a las vacantes publicadas
 *    se muestra siempre que haya y la caseta pueda verlas.
 *    Si la empresa lo pide (ajuste «La caseta espera a que RR. HH. diga «Que
 *    pase»») y hay quien atienda en esa sede, el acceso nace PENDIENTE
 *    («Esperando autorización» de RR. HH.) y RR. HH. responde Que pase / Que
 *    espere / No puede pasar. La caseta nunca ve etapas ni datos del CV.
 *  - Personal externo → Visita a Departamento (o a un colaborador): si la
 *    empresa lo pide y el departamento tiene quien autorice en la sede
 *    (C-08: Cobertura), el acceso nace PENDIENTE («Esperando autorización»)
 *    y se avisa al responsable. Si no tiene (DD-11), también nace pendiente
 *    y espera al supervisor o al jefe de seguridad de la sede, que reciben
 *    el aviso; la empresa puede elegir «La visita pasa sin esperar».
 *  - Proveedor y contratista: «¿A qué departamento viene?»; si la empresa lo
 *    pide para ese tipo, espera al responsable igual que una visita (sin
 *    responsable en la sede, la caseta lo pasa con «Autorizar por excepción»).
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
     * @return array{candidato: bool, viene_a: ?string, vacante_datos: array<string, mixed>, departamento_id: ?int, esperar: bool, esperar_rh: bool, sin_responsable: bool, fotos: array<string, UploadedFile>}
     */
    public function preparar(string $tipo, ?string $motivo, array $entrada, int $sedeId, ?Colaborador $visita, array &$errores, bool $conLlamada = false, ?Colaborador $host = null): array
    {
        $r = ['candidato' => false, 'viene_a' => null, 'vacante_datos' => [], 'departamento_id' => null, 'esperar' => false, 'esperar_rh' => false,
            'sin_responsable' => false, 'fotos' => [], 'persona' => null, 'cuenta' => null, 'avisar_visitado' => null];

        foreach (['foto_persona', 'foto_identificacion'] as $campo) {
            $archivo = $entrada[$campo] ?? null;
            if ($archivo instanceof UploadedFile) {
                $r['fotos'][$campo] = $archivo;
            } elseif ($archivo !== null && $archivo !== '') {
                $errores[$campo] = 'No se pudo recibir la foto. Tómala de nuevo.';
            }
        }
        // C-09: la caseta ya llamó al residente o a la habitación (constancia en el registro): no espera a nadie más
        if ($conLlamada) {
            return $r;
        }
        if (in_array($tipo, Acceso::CON_AUTORIZACION, true)) {
            // C-09 (DD-12): quien lo citó y tiene cuenta autoriza; su departamento es el suplente
            if (($cuenta = AQuienViene::cuentaDe($host)) !== null) {
                $departamento = $this->departamento($entrada['departamento_id'] ?? null, $sedeId);

                return ['departamento_id' => $departamento?->id, 'esperar' => true, 'persona' => $host, 'cuenta' => $cuenta] + $r;
            }

            return $this->proveedor($tipo, $entrada, $sedeId, $r, $errores);
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
            // Tres opciones en la caseta; los valores de antes (entrevista, documentos, firma) se siguen aceptando
            if (! array_key_exists($vieneA, Acceso::OPCIONES_CASETA) && ! in_array($vieneA, Acceso::VIENE_CON_FICHA, true)) {
                $errores['viene_a'] = 'Elige a qué viene de la lista.';

                return $r;
            }
            $r['viene_a'] = $vieneA;
            $r['candidato'] = in_array($vieneA, Acceso::VIENE_COMO_CANDIDATO, true);
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
        // C-09 (DD-12): la persona citada con cuenta autoriza (su departamento es el suplente); si la empresa no pide autorización, solo se le avisa
        if ($motivo === 'colaborador' && ($cuenta = AQuienViene::cuentaDe($visita)) !== null) {
            if ($empresa !== null && $this->ajustes->visitasRequierenAutorizacion($empresa)) {
                return ['esperar' => true, 'persona' => $visita, 'cuenta' => $cuenta] + $r;
            }

            return ['avisar_visitado' => $cuenta] + $r;
        }
        if ($r['departamento_id'] === null || $empresa === null || ! $this->ajustes->visitasRequierenAutorizacion($empresa)) {
            return $r;
        }
        $r['esperar'] = $this->autorizaciones->tieneResponsables($r['departamento_id'], $sedeId);
        // C-08 / DD-11: sin quien autorice en esta sede, espera al supervisor o al jefe de seguridad (salvo que la empresa diga «pasa sin esperar»)
        $r['sin_responsable'] = ! $r['esperar'] && $this->ajustes->sinCoberturaEsperaSupervisor($empresa);

        return $r;
    }

    /**
     * Después de guardar el acceso: fotos, ficha del candidato y la solicitud
     * de autorización (a RR. HH. o al departamento).
     *
     * @param  array{candidato: bool, viene_a: ?string, vacante_datos: array<string, mixed>, departamento_id: ?int, esperar: bool, esperar_rh: bool, sin_responsable: bool, fotos: array<string, UploadedFile>}  $r
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
        } elseif ($r['sin_responsable']) {
            $cambios['autorizacion'] = Acceso::SIN_RESPONSABLE; // Proveedor/contratista: la caseta lo pasa por excepción
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
        // C-04: ningún flujo crea registros en un módulo apagado (la ficha de candidato solo con Candidatos contratado)
        if ($r['candidato'] && app(Autorizador::class)->moduloActivo((int) $acceso->empresa_id, 'candidatos')) {
            $candidato = app(AdministradorCandidatos::class)->desdeAcceso($actor, $acceso,
                $r['vacante_datos'] + ['viene_a' => $r['viene_a'], 'esperar_rh' => $r['esperar_rh']]);
            $acceso->refresh();
        }
        if ($r['esperar_rh']) {
            $this->autorizaciones->solicitarRecepcion($actor, $acceso, $candidato);
        } elseif ($r['esperar'] && ($r['persona'] ?? null) !== null) {
            $this->autorizaciones->solicitarVisita($actor, $acceso, $r['departamento_id'] === null ? null : (int) $r['departamento_id'], $r['persona']);
        } elseif ($r['esperar']) {
            $this->autorizaciones->solicitarVisita($actor, $acceso, (int) $r['departamento_id']);
        }
        if (($r['avisar_visitado'] ?? null) !== null) {
            $this->autorizaciones->avisarVisitado($actor, $acceso, $r['avisar_visitado']);
        }
        if (! $r['esperar'] && ! $r['esperar_rh'] && $r['sin_responsable']) {
            // C-08 / DD-11: nadie del departamento puede responder en esta sede → avisa al supervisor o jefe de seguridad
            $this->autorizaciones->avisarSinCobertura($actor, $acceso->refresh());
        }
    }

    /**
     * Proveedor y contratista: si la empresa pide que esperen al departamento
     * (por tipo), «¿A qué departamento viene?» es obligatorio. Con responsable
     * en esa sede, la solicitud va al departamento (como una visita); sin
     * responsable, queda pendiente para «Autorizar por excepción». Si el tipo
     * no espera al departamento, sigue como siempre (se confirma con el host).
     *
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $r
     * @param  array<string, string>  $errores
     * @return array<string, mixed>
     */
    private function proveedor(string $tipo, array $entrada, int $sedeId, array $r, array &$errores): array
    {
        $empresa = Empresa::find(app(Tenant::class)->empresaId());
        if ($empresa === null || ! $this->ajustes->esperaAlDepartamento($empresa, $tipo)) {
            return $r;
        }
        $departamento = $this->departamento($entrada['departamento_id'] ?? null, $sedeId);
        if ($departamento === null) {
            // Un departamento que no aplica ya lo marca el registro («Elige un departamento activo de la lista.»)
            if (empty($entrada['departamento_id'])) {
                $errores['departamento_id'] = 'Indica a qué departamento viene.';
            }

            return $r;
        }
        $r['departamento_id'] = (int) $departamento->id;
        $r['esperar'] = $this->autorizaciones->tieneResponsables($r['departamento_id'], $sedeId);
        $r['sin_responsable'] = ! $r['esperar'];

        return $r;
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
