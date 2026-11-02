<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Novedad;
use App\Models\PaseSalida;
use App\Models\PaseSalidaArticulo;
use App\Models\Postulacion;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Candidatos\DocumentosParaContratar;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\EstadoActivo;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ficha del colaborador, en cuatro pestañas: Datos, Expediente, Código y
 * etiqueta, Historial (nació en Candidatos, fase 3, como «expediente»; la
 * dirección /colaboradores/{id}/expediente se conserva y #expediente,
 * #codigo o #historial abren su pestaña). Reúne en una pantalla lo que la
 * plataforma ya sabe de la persona: sus datos de Colaboradores, su paso por Candidatos (postulación
 * contratada y evaluaciones), los documentos que entregó al contratarse
 * (candidato_documentos.colaborador_id) y resúmenes con enlace de sus
 * accesos recientes, llaves en uso, pases de salida, resguardos y
 * procedimientos por leer.
 *
 * Cada bloque se muestra solo si el usuario puede «ver» ese módulo; la
 * pestaña Expediente (datos legales y documentos) son datos personales:
 * solo aparece con colaboradores.datos_personales (dentro de su alcance);
 * la ficha de candidato, con candidatos.ver. Otra empresa u otra sede → 404.
 */
class ExpedienteController extends Controller
{
    /**
     * Movimientos del registro que se cuentan en la pestaña Historial (solo
     * qué pasó, quién y cuándo; el detalle completo está en la Bitácora de
     * auditoría para quien puede verla).
     */
    public const MOVIMIENTOS = [
        'colaboradores.creado' => ['Alta', 'bi-person-plus'],
        'colaboradores.provisional' => ['Registro rápido en la caseta (por verificar)', 'bi-hourglass-split'],
        'colaboradores.validado' => ['Verificado (alta provisional)', 'bi-patch-check'],
        'colaboradores.fusionado' => ['Unido con su registro correcto (era un duplicado)', 'bi-people'],
        'colaboradores.desactivado' => ['Baja', 'bi-slash-circle'],
        'colaboradores.reactivado' => ['Reingreso', 'bi-arrow-counterclockwise'],
        'colaboradores.actualizado' => ['Edición de datos', 'bi-pencil-square'],
        'colaboradores.sedes' => ['Cambio de sedes adicionales', 'bi-signpost-split'],
        'colaboradores.etiqueta_asignada' => ['Etiqueta NFC / RFID asignada', 'bi-broadcast-pin'],
        'colaboradores.etiqueta_quitada' => ['Etiqueta NFC / RFID quitada', 'bi-broadcast-pin'],
    ];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorColaboradores $colaboradores,
        private readonly DocumentosCandidato $documentos,
    ) {}

    public function index(Request $request, int $colaborador): View
    {
        Gate::authorize('colaboradores.ver');
        $empresaId = $this->empresaDeTrabajo($request);
        $actor = $request->user();

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $colaborador, $empresaId) {
            $c = $this->buscar($actor, $colaborador, 'colaboradores.ver');
            $c->load(['sede:id,nombre', 'sedesAdicionales:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'turno:id,nombre,hora_inicio,hora_fin,activo',
                'usuario:id,colaborador_id,name,username,activo', 'fusionadoEn:id,num_empleado,nombre,apellido_paterno,apellido_materno']);
            $personales = $actor->can('colaboradores.datos_personales')
                && $this->colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.datos_personales')->whereKey($c->id)->exists();

            // Documentos de contratación (datos personales)
            $documentos = $personales
                ? CandidatoDocumento::where('colaborador_id', $c->id)->with('registradoPor:id,name')->orderBy('tipo')->orderBy('id')->get()
                : collect();

            // Su paso por Candidatos: la postulación contratada y sus evaluaciones (solo con candidatos.ver)
            $ficha = null;
            $postulacion = null;
            if ($actor->can('candidatos.ver')) {
                $ficha = Candidato::where('colaborador_id', $c->id)->orderByDesc('id')->first(['id', 'nombre_completo', 'origen', 'llegada_en', 'created_at']);
                $postulacion = Postulacion::where('colaborador_id', $c->id)->with(['evaluaciones.evaluador:id,name', 'evaluaciones.titular:id,name', 'vacantePublicada:id,titulo', 'departamento:id,nombre'])
                    ->orderByDesc('id')->first();
            }

            // Resúmenes operativos: cada uno solo si puede ver ese módulo
            $accesos = $actor->can('accesos.ver')
                ? Acceso::where('colaborador_id', $c->id)->with('sede:id,nombre')->orderByDesc('entrada_at')->limit(5)->get()
                : null;
            // Revisión Etapa 2 (A-5): también las que no regresaron
            $llaves = $actor->can('prestamo_llaves.ver')
                ? PrestamoLlave::where('colaborador_id', $c->id)->whereIn('estado', [PrestamoLlave::EN_USO, PrestamoLlave::NO_REGRESO])->where('anulado', false)
                    ->with(['llave:id,nomenclatura,descripcion', 'sede:id,nombre'])->orderByDesc('id')->get()
                : null;
            $noRegresaron = $this->noRegresaron($actor, $c);
            $pases = $actor->can('pases_salida.ver')
                ? PaseSalida::where('colaborador_id', $c->id)->with('sede:id,nombre')->orderByDesc('id')->limit(5)->get()
                : null;
            $responsivas = $actor->can('responsivas.ver')
                ? Responsiva::where('colaborador_id', $c->id)->where('estado', Responsiva::EN_CAMPO)->withCount('equipos')->with('sede:id,nombre')->orderByDesc('id')->get()
                : null;
            $procedimientos = null;
            if ($actor->can('procedimientos.ver') && $c->usuario !== null) {
                $usuario = User::find($c->usuario->id);
                $procedimientos = $usuario !== null && $usuario->activo ? app(AdministradorProcedimientos::class)->pendientesDe($usuario) : collect();
            }

            // C-33 (D-14): sus accidentes, en solo lectura y con su permiso propio (el formato de Accidente no cambia)
            $accidentes = $actor->can('colaboradores.accidentes')
                && $this->colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.accidentes')->whereKey($c->id)->exists()
                ? Novedad::where('categoria', 'accidente')->whereHas('accidenteColaborador', fn ($q) => $q->where('colaborador_id', $c->id))
                    ->with(['sede:id,nombre', 'accidenteColaborador:id,novedad_id,fecha_accidente,hora_accidente,turno', 'accidenteIncapacidad:id,novedad_id,dias_incapacidad,fecha_presenta',
                        'accidenteDictamen:id,novedad_id,tipos_herida'])
                    ->orderByDesc('created_at')->limit(50)->get()
                : null;
            $novedadesVisibles = $accidentes !== null && $accidentes->isNotEmpty()
                ? app(AdministradorNovedades::class)->limitar(Novedad::query(), $actor, 'ver')->whereIn('novedades.id', $accidentes->pluck('id'))->pluck('novedades.id')->all()
                : [];

            // Historial: altas, bajas, reingresos y cambios del registro (sin el detalle de los datos)
            $movimientos = Auditoria::query()
                ->leftJoin('users', 'users.id', '=', 'auditoria.user_id')
                ->where('auditoria.empresa_id', $c->empresa_id)
                ->where('auditoria.auditable_type', Colaborador::class)
                ->where('auditoria.auditable_id', $c->id)
                ->whereIn('auditoria.evento', array_keys(self::MOVIMIENTOS))
                ->orderByDesc('auditoria.id')->limit(50)
                ->get(['auditoria.id', 'auditoria.evento', 'auditoria.creado_en', 'users.name as autor']);
            $autores = User::whereIn('id', array_filter([$c->creado_por, $c->actualizado_por, $c->validado_por]))->pluck('name', 'id');

            // Código y etiqueta: asignar la etiqueta e imprimir la credencial, como en la lista
            $pendiente = $c->provisional && $c->fusionado_en_id === null && $c->activo;
            $editable = $actor->can('colaboradores.editar')
                && $this->colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.editar')->whereKey($c->id)->exists();

            return view('organizacion.colaboradores.expediente', [
                'c' => $c,
                'movimientos' => $movimientos,
                'autores' => $autores,
                'pendiente' => $pendiente,
                'identEditable' => $editable && ! $pendiente,
                'imprimirCredencial' => $actor->can('etiquetas_qr.ver') && $actor->can('colaboradores.editar') && ! $pendiente
                    ? route('etiquetas.index', ['tipo' => 'colaborador', 'q' => $c->num_empleado ?: $c->nombreCompleto()])
                    : null,
                'personales' => $personales,
                'documentos' => $documentos,
                'nombres' => app(DocumentosParaContratar::class)->nombres($empresaId),
                'ficha' => $ficha,
                'postulacion' => $postulacion,
                'accesos' => $accesos,
                'llaves' => $llaves,
                'noRegresaron' => $noRegresaron,
                'pases' => $pases,
                'responsivas' => $responsivas,
                'procedimientos' => $procedimientos,
                'accidentes' => $accidentes,
                'accidentesVisibles' => $novedadesVisibles,
            ]);
        });
    }

    public function documento(Request $request, int $colaborador, int $documento): StreamedResponse
    {
        Gate::authorize('colaboradores.datos_personales');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $colaborador, $documento) {
            $c = $this->buscar($request->user(), $colaborador, 'colaboradores.datos_personales');
            $doc = CandidatoDocumento::where('colaborador_id', $c->id)->whereNotNull('ruta')->find($documento);
            abort_if($doc === null, 404);

            return $this->documentos->respuesta($doc->ruta, $doc->nombre_original, ! $doc->esImagen());
        });
    }

    private function buscar(User $actor, int $id, string $permiso): Colaborador
    {
        $c = $this->colaboradores->limitar(Colaborador::query(), $actor, $permiso)->find($id);
        abort_if($c === null, 404);

        return $c;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Revisión Etapa 2 (A-5): gafetes y equipos que el colaborador no regresó
     * (los equipos de sus responsivas o de pases que pidió; los gafetes de sus
     * accesos). Las llaves ya vienen en «Llaves a su cargo». null = no puede ver ninguno.
     *
     * @return list<array{texto: string, url: ?string}>|null
     */
    private function noRegresaron(User $actor, Colaborador $c): ?array
    {
        $verEquipos = $actor->can('equipos.ver');
        $verGafetes = $actor->can('gafetes.ver') || $actor->can('accesos.ver');
        if (! $verEquipos && ! $verGafetes) {
            return null;
        }
        $lista = [];
        if ($verEquipos) {
            $deResponsivas = EquipoResponsiva::whereNull('devuelto_en')
                ->whereHas('responsiva', fn ($q) => $q->where('colaborador_id', $c->id))->pluck('equipo_id');
            $dePases = PaseSalidaArticulo::whereNotNull('equipo_id')->whereColumn('cantidad_regresada', '<', 'cantidad')
                ->whereHas('pase', fn ($q) => $q->where('colaborador_id', $c->id)->whereNotNull('salio_en'))->pluck('equipo_id');
            foreach (Equipo::whereIn('id', $deResponsivas->merge($dePases)->unique())->where('estado', EstadoActivo::NO_REGRESO)->orderBy('numero_serie')->get(['id', 'numero_serie']) as $e) {
                $lista[] = ['texto' => 'Equipo '.$e->numero_serie, 'url' => route('equipos.index').'#equipo-'.$e->id];
            }
        }
        if ($verGafetes) {
            foreach (Acceso::where('colaborador_id', $c->id)->where('gafete_no_regreso', true)->orderByDesc('id')->limit(20)->get(['id', 'gafete_texto', 'salida_at']) as $a) {
                $lista[] = ['texto' => 'Gafete '.($a->gafete_texto ?? '—'), 'url' => $actor->can('gafetes.ver') ? route('gafetes.index') : null];
            }
        }

        return $lista;
    }
}
