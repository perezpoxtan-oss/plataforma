<?php

namespace App\Services\Candidatos;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Empresa;
use App\Models\Postulacion;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Firmas\Firmas;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Candidatos, fase 3 (ADR-0011): caducidad y privacidad.
 *
 *  - Las postulaciones en Considerar o Rechazado se ARCHIVAN a los
 *    archivar_meses (6 por omisión) de su decisión: salen de la lista de
 *    Candidatos (filtro «Archivadas») y la ficha lo muestra.
 *  - Las fichas sin contratación se BORRAN con sus documentos y su firma a
 *    los borrar_meses (12) del último movimiento. Quince días antes se avisa
 *    a RR. HH. de la sede (campana y correo, una vez) con el botón «Conservar
 *    6 meses más»; conservar corre la fecha y, cuando vuelva a tocar, se
 *    avisa de nuevo.
 *  - Las fichas de contratados nunca se borran: su expediente ya es del colaborador.
 *
 * Todo queda en la bitácora sin datos personales (ids, sede, etapa y conteos).
 */
class CaducidadCandidatos
{
    public const DIAS_AVISO = 15;

    public const MESES_CONSERVAR = 6;

    public function __construct(
        private readonly CentroNotificaciones $notificaciones,
        private readonly AdministradorRoles $auditoria,
        private readonly HoraLocal $hora,
    ) {}

    /**
     * Archiva, avisa y borra lo que ya tocaba en la empresa activa (el tenant
     * ya está puesto). Lo llama PlazosCandidatos::correrEmpresa().
     *
     * @param  array<string, int>  $plazos
     * @return array{archivadas: int, avisos_borrado: int, borradas: int}
     */
    public function correrEmpresa(Empresa $empresa, array $plazos): array
    {
        return [
            'archivadas' => $this->archivar($plazos['archivar_meses']),
            'avisos_borrado' => $this->avisarBorrado($empresa, $plazos['borrar_meses']),
            'borradas' => $this->borrar(),
        ];
    }

    /**
     * @param  array<string, int>  $plazos
     */
    public function toca(Empresa $empresa, array $plazos): bool
    {
        return $this->porArchivar($plazos['archivar_meses'])->exists()
            || $this->porAvisar($plazos['borrar_meses'])->exists()
            || $this->porBorrar()->exists();
    }

    // -------------------------------------------------------------- Archivar

    /** @return Builder<Postulacion> */
    private function porArchivar(int $meses): Builder
    {
        $limite = now()->subMonths($meses);

        return Postulacion::whereIn('etapa', ['considerar', 'rechazado'])->whereNull('archivada_en')
            ->where(fn ($q) => $q->where('decision_en', '<=', $limite)->orWhere(fn ($r) => $r->whereNull('decision_en')->where('updated_at', '<=', $limite)));
    }

    private function archivar(int $meses): int
    {
        $n = 0;
        $postulaciones = app(Postulaciones::class);
        $candidatos = app(AdministradorCandidatos::class);
        foreach ($this->porArchivar($meses)->with('candidato:id,empresa_id,sede_id,etapa,updated_at')->orderBy('id')->limit(300)->get() as $p) {
            if ($p->candidato === null) {
                continue;
            }
            // Sin tocar updated_at: archivar no es un movimiento (la ficha se borra contando desde el último real)
            if (Postulacion::whereKey($p->id)->where('etapa', $p->etapa)->whereNull('archivada_en')->update(['archivada_en' => now(), 'updated_at' => $p->updated_at]) === 0) {
                continue;
            }
            $postulaciones->reflejar($p->refresh());
            Candidato::whereKey($p->candidato_id)->update(['updated_at' => $p->candidato->updated_at]);
            $candidatos->evento($p->candidato, 'archivada', null, null, 'Postulación archivada: llevaba '.$meses.' meses en «'.$p->etiquetaEtapa().'».', null);
            $this->auditarPostulacion($p, 'archivada', ['meses' => $meses, 'etapa' => $p->etapa]);
            $n++;
        }

        return $n;
    }

    // ---------------------------------------------------------------- Aviso

    /**
     * Fichas sin contratar cuyo último movimiento ya es viejo: candidatas a
     * borrarse en los próximos DIAS_AVISO (o antes) y sin aviso todavía.
     *
     * @return Builder<Candidato>
     */
    private function porAvisar(int $meses): Builder
    {
        $limite = now()->subMonths($meses)->addDays(self::DIAS_AVISO);

        return $this->sinContratar()->whereNull('aviso_borrado_en')
            ->where('updated_at', '<=', $limite)
            ->where(fn ($q) => $q->whereNull('conservar_hasta')->orWhere('conservar_hasta', '<=', now()->addDays(self::DIAS_AVISO)->toDateString()))
            ->whereDoesntHave('postulaciones', fn ($q) => $q->where('updated_at', '>', $limite))
            ->whereDoesntHave('documentos', fn ($q) => $q->where('created_at', '>', $limite));
    }

    /** @return Builder<Candidato> */
    private function sinContratar(): Builder
    {
        return Candidato::whereNull('colaborador_id')->where('etapa', '!=', 'contratado')
            ->whereDoesntHave('postulaciones', fn ($q) => $q->where('etapa', 'contratado')->orWhereNotNull('colaborador_id'));
    }

    /** Día en que se borrará: último movimiento + meses, o «Conservar hasta» si es después. */
    public function fechaBorrado(Candidato $c, int $meses): Carbon
    {
        $ultimo = collect([$c->updated_at, Postulacion::where('candidato_id', $c->id)->max('updated_at'), CandidatoDocumento::where('candidato_id', $c->id)->max('created_at')])
            ->filter()->map(fn ($f) => Carbon::parse($f))->max() ?? now();
        $fecha = $ultimo->copy()->addMonths($meses)->startOfDay();
        if ($c->conservar_hasta !== null && $c->conservar_hasta->gt($fecha)) {
            $fecha = $c->conservar_hasta->copy()->startOfDay();
        }

        return $fecha;
    }

    private function avisarBorrado(Empresa $empresa, int $meses): int
    {
        $n = 0;
        $plazos = app(PlazosCandidatos::class);
        foreach ($this->porAvisar($meses)->with('sede:id,nombre')->orderBy('id')->limit(200)->get() as $c) {
            $fecha = $this->fechaBorrado($c, $meses);
            if ($fecha->gt(now()->addDays(self::DIAS_AVISO))) {
                continue; // Todavía no toca (tuvo movimiento que la consulta no ve)
            }
            // Nunca antes de DIAS_AVISO después del aviso
            $fecha = $fecha->max(now()->addDays(self::DIAS_AVISO)->startOfDay());
            Candidato::whereKey($c->id)->update(['aviso_borrado_en' => now(), 'borrar_en' => $fecha->toDateString(), 'updated_at' => $c->updated_at]); // el aviso no es un movimiento
            $usuarios = $plazos->rhDe($empresa, (int) $c->sede_id);
            $titulo = 'Se borrará la ficha de '.$c->nombre_completo.' el '.$this->hora->formatear($fecha, 'd/m/Y');
            $texto = 'Lleva '.$meses.' meses sin movimiento y no fue contratado: por privacidad, su ficha y sus documentos se borrarán ese día. Si aún lo necesitas, consérvalo '.self::MESES_CONSERVAR.' meses más. Sede: '.($c->sede?->nombre ?? '').'.';
            if ($usuarios->isNotEmpty()) {
                $this->notificaciones->avisar((int) $empresa->id, $usuarios->pluck('id'), 'caducidad_candidato', [
                    'titulo' => $titulo, 'texto' => $texto, 'url' => route('candidatos.show', $c->id),
                    'referencia_tipo' => PlazosCandidatos::REFERENCIA_CADUCIDAD, 'referencia_id' => $c->id,
                    'acciones' => [['etiqueta' => 'Conservar '.self::MESES_CONSERVAR.' meses más', 'url' => route('candidatos.conservar', $c->id), 'campos' => [], 'estilo' => 'aceptar']],
                ]);
                app(AvisosCorreo::class)->recepcion((int) $empresa->id, 'plazo_candidato', $usuarios->pluck('email')->filter()->values()->all(),
                    new AvisoRecepcion($titulo, [$texto], [['Abrir su ficha', route('candidatos.show', $c->id)]]));
            }
            app(AdministradorCandidatos::class)->evento($c, 'aviso_borrado', null, null, 'Aviso a Recursos Humanos: la ficha se borrará el '.$this->hora->formatear($fecha, 'd/m/Y').' si no se conserva.', null);
            $plazos->auditar($c, 'aviso_borrado', ['borrar_en' => $fecha->toDateString(), 'meses' => $meses, 'avisados' => $usuarios->pluck('id')->all()]);
            $n++;
        }

        return $n;
    }

    // --------------------------------------------------------------- Borrar

    /** @return Builder<Candidato> */
    private function porBorrar(): Builder
    {
        return $this->sinContratar()->whereNotNull('aviso_borrado_en')->whereNotNull('borrar_en')
            ->where('borrar_en', '<=', now()->toDateString())
            ->where('aviso_borrado_en', '<=', now()->subDays(self::DIAS_AVISO))
            ->where(fn ($q) => $q->whereNull('conservar_hasta')->orWhere('conservar_hasta', '<=', now()->toDateString()));
    }

    private function borrar(): int
    {
        $n = 0;
        $documentos = app(DocumentosCandidato::class);
        $firmas = app(Firmas::class);
        foreach ($this->porBorrar()->orderBy('id')->limit(100)->get() as $c) {
            $docs = $c->documentos;
            $resumen = ['sede_id' => $c->sede_id, 'etapa' => $c->etapa, 'origen' => $c->origen, 'documentos' => $docs->count(),
                'postulaciones' => Postulacion::where('candidato_id', $c->id)->count(), 'borrar_en' => $c->borrar_en?->toDateString()];
            DB::transaction(function () use ($c, $docs, $documentos, $firmas) {
                foreach ($docs as $doc) {
                    $documentos->borrar($doc->ruta);
                }
                $firmas->borrar($c->firma_ruta);
                $c->delete();
            });
            Auditoria::create([
                'empresa_id' => $c->empresa_id, 'user_id' => null, 'evento' => 'candidatos.borrado_por_caducidad',
                'auditable_type' => Candidato::class, 'auditable_id' => $c->id, 'antes' => $resumen, 'despues' => null, 'ip' => null,
            ]);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------ Conservar

    /**
     * «Conservar 6 meses más» desde la ficha o la campana: corre la fecha,
     * quita el aviso (cuando vuelva a tocar, se avisa otra vez) y lo anota.
     */
    public function conservar(User $actor, Candidato $c): Carbon
    {
        if ($c->contratado()) {
            throw new CambioNoPermitido('La ficha de un contratado no caduca: su expediente ya es del colaborador.');
        }
        $desde = $c->borrar_en ?? $c->conservar_hasta ?? now();
        $hasta = Carbon::parse($desde)->max(now())->addMonths(self::MESES_CONSERVAR)->startOfDay();
        Candidato::whereKey($c->id)->update(['conservar_hasta' => $hasta->toDateString(), 'aviso_borrado_en' => null, 'borrar_en' => null,
            'actualizado_por' => $actor->id, 'updated_at' => now()]);
        $this->notificaciones->resolver(PlazosCandidatos::REFERENCIA_CADUCIDAD, $c->id);
        app(AdministradorCandidatos::class)->evento($c, 'conservada', null, null, 'Se conservará hasta el '.$this->hora->formatear($hasta, 'd/m/Y').' ('.self::MESES_CONSERVAR.' meses más).', $actor);
        $this->auditoria->auditar($actor, 'candidatos.conservada', $c, ['borrar_en' => $c->borrar_en?->toDateString()], ['conservar_hasta' => $hasta->toDateString()]);

        return $hasta;
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    private function auditarPostulacion(Postulacion $p, string $accion, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $p->empresa_id, 'user_id' => null, 'evento' => 'candidatos.'.$accion,
            'auditable_type' => Postulacion::class, 'auditable_id' => $p->id, 'antes' => ['etapa' => $p->etapa, 'sede_id' => $p->sede_id], 'despues' => $despues, 'ip' => null,
        ]);
    }
}
