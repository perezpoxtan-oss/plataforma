<?php

namespace App\Services\Vacantes;

use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Postulacion;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vacantes (bolsa de trabajo): alta, edición, estados y alcance de sede.
 *
 * Reglas:
 *  - una vacante aplica en «todas las sedes» o en las elegidas; con alcance de
 *    sede se ven las que aplican en sus sedes y solo se crean o editan las que
 *    son únicamente de sus sedes («todas las sedes» pide alcance de empresa);
 *  - quien solo consulta (sin vacantes.editar: la caseta) ve las publicadas;
 *  - no hay dos vacantes abiertas (no cerradas) con el mismo título;
 *  - los estados siguen Vacante::TRANSICIONES y cada cambio se condiciona al
 *    estado esperado (doble clic o dos personas: el segundo recibe aviso);
 *  - publicar pide al menos una sede y que la fecha de cierre no haya pasado;
 *  - eliminar solo si nadie se ha postulado (si no, se cierra).
 */
class AdministradorVacantes
{
    public const MAX_RENGLONES = 15;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * @param  Builder<Vacante>  $consulta
     * @return Builder<Vacante>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso = 'vacantes.ver'): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }
        if (! $actor->can($permiso)) {
            return $consulta->whereRaw('1 = 0');
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->aplicanEn($sedes))
            // Quien solo consulta (la caseta) ve lo que está publicado; quien pide
            // vacantes sin poder editarlas (Jefe de departamento) ve además las que él pidió
            ->when(! $actor->can('vacantes.editar'), fn ($q) => $q->where(fn ($e) => $e->where('vacantes.estado', 'publicada')
                ->when($actor->can('vacantes.crear'), fn ($p) => $p->orWhere('vacantes.creado_por', $actor->id))));
    }

    /**
     * Sedes que el usuario puede elegir para una vacante.
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permiso): Collection
    {
        $permitidas = $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];

        return Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))->orderBy('nombre')->get(['id', 'nombre']);
    }

    /** ¿Puede modificar esta vacante? (sus sedes deben estar todas en su alcance) */
    public function puedeModificar(User $actor, Vacante $v, string $permiso): bool
    {
        if (! $actor->can($permiso)) {
            return false;
        }
        if ($this->autorizador->alcanceDeEmpresa($actor, $permiso)) {
            return true;
        }
        if ($v->todas_las_sedes) {
            return false;
        }
        $mias = $this->autorizador->sedesPermitidas($actor, $permiso) ?? [];
        $suyas = $v->relationLoaded('sedes') ? $v->sedes->pluck('id')->all() : $v->sedes()->pluck('sedes.id')->all();

        return $suyas !== [] && array_diff($suyas, $mias) === [];
    }

    // -------------------------------------------------------------- Altas y cambios

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Vacante
    {
        [$datos, $todas, $sedes] = $this->validar($actor, $entrada, null, 'vacantes.crear');

        $vacante = DB::transaction(function () use ($datos, $todas, $sedes) {
            $v = new Vacante($datos + ['todas_las_sedes' => $todas]);
            $v->forceFill(['codigo' => $this->codigoNuevo(), 'estado' => 'borrador'])->save();
            $v->sedes()->sync($sedes);

            return $v;
        });
        $this->auditoria->auditar($actor, 'vacantes.creado', $vacante, null, $this->foto($vacante));

        return $vacante;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Vacante $vacante, array $entrada): Vacante
    {
        if (! $this->puedeModificar($actor, $vacante, 'vacantes.editar')) {
            throw new CambioNoPermitido('Esta vacante también es de otras sedes: solo la modifica quien tiene alcance de empresa.');
        }
        [$datos, $todas, $sedes] = $this->validar($actor, $entrada, $vacante, 'vacantes.editar');
        $antes = $this->foto($vacante);
        DB::transaction(function () use ($vacante, $datos, $todas, $sedes) {
            $vacante->fill($datos + ['todas_las_sedes' => $todas])->save();
            $vacante->sedes()->sync($sedes);
        });
        $vacante->refresh();
        $this->auditoria->auditar($actor, 'vacantes.actualizado', $vacante, $antes, $this->foto($vacante));

        return $vacante;
    }

    /**
     * Cambia de estado. «Cerrada» pide el motivo (cubierta o cancelada; un
     * borrador solo se cancela). «Publicada» pide sede y fecha de cierre vigente.
     */
    public function cambiarEstado(User $actor, Vacante $vacante, string $estado, ?string $motivo, string $hoy): Vacante
    {
        if (! $this->puedeModificar($actor, $vacante, 'vacantes.editar')) {
            throw new CambioNoPermitido('Esta vacante también es de otras sedes: solo la modifica quien tiene alcance de empresa.');
        }
        if (! array_key_exists($estado, Vacante::ESTADOS)) {
            throw new CambioNoPermitido('Elige un estado válido.');
        }
        if (! $vacante->puedePasarA($estado)) {
            throw new CambioNoPermitido('Una vacante «'.$vacante->etiquetaEstado().'» no puede pasar a «'.Vacante::ESTADOS[$estado].'».');
        }
        $cambios = ['estado' => $estado, 'actualizado_por' => $actor->id, 'updated_at' => now()];
        if ($estado === 'cerrada') {
            if (! array_key_exists((string) $motivo, Vacante::MOTIVOS_CIERRE)) {
                throw ValidationException::withMessages(['cierre_motivo' => 'Indica si la vacante se cubrió o se canceló.']);
            }
            if ($vacante->estado === 'borrador' && $motivo !== 'cancelada') {
                throw ValidationException::withMessages(['cierre_motivo' => 'Un borrador nunca se publicó: solo se puede cancelar.']);
            }
            $cambios += ['cierre_motivo' => $motivo, 'cerrada_en' => now()];
        }
        if ($estado === 'publicada') {
            if (! $vacante->todas_las_sedes && ! $vacante->sedes()->exists()) {
                throw ValidationException::withMessages(['estado' => 'Antes de publicar, elige en qué sede(s) es la vacante.']);
            }
            if ($vacante->fecha_cierre !== null && $vacante->fecha_cierre->format('Y-m-d') < $hoy) {
                throw ValidationException::withMessages(['estado' => 'La fecha de cierre ya pasó: cámbiala en «Editar» antes de publicar.']);
            }
            $cambios += ['publicada_en' => $vacante->publicada_en ?? now()]
                + ($vacante->fecha_publicacion === null ? ['fecha_publicacion' => $hoy] : []);
        }
        if ($estado === 'borrador') {
            $cambios += ['cierre_motivo' => null, 'cerrada_en' => null];
            $this->tituloLibre($vacante->titulo, $vacante->id);
        }

        $anterior = $vacante->estado;
        if (Vacante::whereKey($vacante->id)->where('estado', $anterior)->update($cambios) === 0) {
            throw new CambioNoPermitido('Otra persona acaba de cambiar esta vacante. Revisa su estado actual.');
        }
        $vacante->refresh();
        $evento = match ($estado) {
            'publicada' => $anterior === 'pausada' ? 'vacantes.reanudada' : 'vacantes.publicada',
            'pausada' => 'vacantes.pausada',
            'cerrada' => 'vacantes.cerrada',
            'borrador' => 'vacantes.reabierta',
        };
        $this->auditoria->auditar($actor, $evento, $vacante, ['estado' => $anterior], ['estado' => $estado] + ($estado === 'cerrada' ? ['cierre_motivo' => $motivo] : []));

        return $vacante;
    }

    public function eliminar(User $actor, Vacante $vacante): void
    {
        if (! $this->puedeModificar($actor, $vacante, 'vacantes.eliminar')) {
            throw new CambioNoPermitido('Esta vacante también es de otras sedes: solo la elimina quien tiene alcance de empresa.');
        }
        $postulados = max(Candidato::where('vacante_id', $vacante->id)->count(), Postulacion::where('vacante_id', $vacante->id)->count());
        if ($postulados > 0) {
            throw new CambioNoPermitido("Tiene {$postulados} candidato(s): ciérrala en lugar de eliminarla (así se conserva su historia).");
        }
        $antes = $this->foto($vacante);
        $vacante->delete();
        $this->auditoria->auditar($actor, 'vacantes.eliminado', $vacante, $antes, null);
    }

    // ------------------------------------------------------------------ Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{0: array<string, mixed>, 1: bool, 2: list<int>}
     */
    private function validar(User $actor, array $entrada, ?Vacante $actual, string $permiso): array
    {
        foreach (['titulo', 'horario', 'experiencia', 'contacto_nombre', 'contacto_correo'] as $campo) {
            $entrada[$campo] = trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($entrada[$campo] ?? null))) ?: null;
        }
        foreach (['sueldo_min', 'sueldo_max'] as $campo) {
            $entrada[$campo] = isset($entrada[$campo]) && is_string($entrada[$campo]) ? (str_replace([',', '$', ' '], '', $entrada[$campo]) ?: null) : ($entrada[$campo] ?? null);
        }
        if (isset($entrada['contacto_telefono']) && is_string($entrada['contacto_telefono'])) {
            $entrada['contacto_telefono'] = preg_replace('/[\s\-().]+/', '', $entrada['contacto_telefono']) ?: null;
        }
        foreach (['requisitos', 'prestaciones'] as $campo) {
            $entrada[$campo] = $this->renglones($entrada[$campo] ?? null);
        }
        $entrada = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $entrada);

        $d = Validator::make($entrada, [
            'titulo' => ['required', 'string', 'min:3', 'max:150'],
            'puesto_id' => ['nullable', 'integer'],
            'departamento_id' => ['nullable', 'integer'],
            'plazas' => ['required', 'integer', 'min:1', 'max:999'],
            'tipo_contrato' => ['nullable', Rule::in(array_keys(Vacante::CONTRATOS))],
            'jornada' => ['nullable', Rule::in(array_keys(Vacante::JORNADAS))],
            'turno_id' => ['nullable', 'integer'],
            'horario' => ['nullable', 'string', 'max:150'],
            'sueldo_min' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'sueldo_max' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'sueldo_periodo' => ['nullable', Rule::in(array_keys(Vacante::PERIODOS))],
            'sueldo_a_tratar' => ['nullable', 'boolean'],
            'descripcion' => ['nullable', 'string', 'max:3000'],
            'requisitos' => ['array', 'max:'.self::MAX_RENGLONES],
            'requisitos.*' => ['string', 'max:200'],
            'prestaciones' => ['array', 'max:'.self::MAX_RENGLONES],
            'prestaciones.*' => ['string', 'max:200'],
            'escolaridad_minima' => ['nullable', Rule::in(array_keys(Candidato::ESCOLARIDAD))],
            'experiencia' => ['nullable', 'string', 'max:150'],
            'fecha_publicacion' => ['nullable', 'date_format:Y-m-d', 'after:2020-01-01', 'before:2100-01-01'],
            'fecha_cierre' => ['nullable', 'date_format:Y-m-d', 'after:2020-01-01', 'before:2100-01-01'],
            'contacto_nombre' => ['nullable', 'string', 'max:150'],
            'contacto_telefono' => ['nullable', 'regex:/^\d{10,15}$/'],
            'contacto_correo' => ['nullable', 'email:rfc', 'max:150'],
            'todas_las_sedes' => ['nullable', 'boolean'],
            'sedes' => ['nullable', 'array'],
            'sedes.*' => ['integer'],
        ], [
            'titulo.required' => 'Escribe el título de la vacante (ej. «Camarista»).',
            'titulo.min' => 'El título es muy corto.',
            'plazas.*' => 'Número de plazas: escribe un número de 1 a 999.',
            'tipo_contrato.*' => 'Elige el tipo de contrato de la lista.',
            'jornada.*' => 'Elige la jornada de la lista.',
            'sueldo_min.*' => 'El sueldo es un número (sin letras).',
            'sueldo_max.*' => 'El sueldo es un número (sin letras).',
            'requisitos.max' => 'Máximo '.self::MAX_RENGLONES.' requisitos.',
            'prestaciones.max' => 'Máximo '.self::MAX_RENGLONES.' prestaciones.',
            'requisitos.*.max' => 'Cada requisito admite máximo 200 caracteres.',
            'prestaciones.*.max' => 'Cada prestación admite máximo 200 caracteres.',
            'escolaridad_minima.*' => 'Elige la escolaridad mínima de la lista.',
            'fecha_publicacion.*' => 'Revisa la fecha de publicación.',
            'fecha_cierre.*' => 'Revisa la fecha de cierre.',
            'contacto_telefono.regex' => 'El teléfono de contacto lleva de 10 a 15 números.',
            'contacto_correo.email' => 'Revisa el correo de contacto: parece incompleto.',
            '*.max' => 'Ese dato es demasiado largo.',
        ])->validate();

        if (isset($d['sueldo_min'], $d['sueldo_max']) && (float) $d['sueldo_min'] > (float) $d['sueldo_max']) {
            throw ValidationException::withMessages(['sueldo_max' => 'El sueldo máximo no puede ser menor que el mínimo.']);
        }
        if (isset($d['fecha_publicacion'], $d['fecha_cierre']) && $d['fecha_cierre'] < $d['fecha_publicacion']) {
            throw ValidationException::withMessages(['fecha_cierre' => 'La fecha de cierre no puede ser antes de la de publicación.']);
        }
        if (! empty($d['puesto_id']) && ! Puesto::where('activo', true)->whereKey((int) $d['puesto_id'])->exists()) {
            throw ValidationException::withMessages(['puesto_id' => 'Elige un puesto activo de la lista.']);
        }
        if (! empty($d['departamento_id']) && ! Departamento::where('activo', true)->whereKey((int) $d['departamento_id'])->exists()) {
            throw ValidationException::withMessages(['departamento_id' => 'Elige un departamento activo de la lista.']);
        }
        if (! empty($d['turno_id']) && ! Turno::where('activo', true)->whereKey((int) $d['turno_id'])->exists()) {
            throw ValidationException::withMessages(['turno_id' => 'Elige un turno activo de la lista.']);
        }
        if ($actual === null || $actual->estado !== 'cerrada') {
            $this->tituloLibre($d['titulo'], $actual?->id);
        }

        // Sedes: «todas» solo con alcance de empresa; con alcance de sede, solo las suyas
        $empresa = $this->autorizador->alcanceDeEmpresa($actor, $permiso);
        $todas = filter_var($d['todas_las_sedes'] ?? false, FILTER_VALIDATE_BOOL);
        if ($todas && ! $empresa) {
            throw ValidationException::withMessages(['sedes' => '«Todas las sedes» es solo para quien tiene alcance de empresa: marca tus sedes.']);
        }
        $elegibles = $this->sedesParaElegir($actor, $permiso)->pluck('id')->all();
        $pedidas = array_values(array_unique(array_map('intval', $d['sedes'] ?? [])));
        if (array_diff($pedidas, $elegibles) !== []) {
            throw ValidationException::withMessages(['sedes' => 'Elige sedes activas de la lista.']);
        }
        if (! $todas && $pedidas === []) {
            throw ValidationException::withMessages(['sedes' => 'Marca al menos una sede o «Todas las sedes».']);
        }

        $datos = [
            'titulo' => $d['titulo'], 'puesto_id' => $d['puesto_id'] ?? null, 'departamento_id' => $d['departamento_id'] ?? null, 'plazas' => (int) $d['plazas'],
            'tipo_contrato' => $d['tipo_contrato'] ?? null, 'jornada' => $d['jornada'] ?? null, 'turno_id' => $d['turno_id'] ?? null, 'horario' => $d['horario'] ?? null,
            'sueldo_min' => $d['sueldo_min'] ?? null, 'sueldo_max' => $d['sueldo_max'] ?? null, 'sueldo_periodo' => $d['sueldo_periodo'] ?? 'mensual',
            'sueldo_a_tratar' => filter_var($d['sueldo_a_tratar'] ?? false, FILTER_VALIDATE_BOOL),
            'descripcion' => isset($d['descripcion']) ? trim(str_replace("\r\n", "\n", $d['descripcion'])) : null,
            'requisitos' => $d['requisitos'] ?: null, 'prestaciones' => $d['prestaciones'] ?: null,
            'escolaridad_minima' => $d['escolaridad_minima'] ?? null, 'experiencia' => $d['experiencia'] ?? null,
            'fecha_publicacion' => $d['fecha_publicacion'] ?? null, 'fecha_cierre' => $d['fecha_cierre'] ?? null,
            'contacto_nombre' => $d['contacto_nombre'] ?? null, 'contacto_telefono' => $d['contacto_telefono'] ?? null, 'contacto_correo' => $d['contacto_correo'] ?? null,
        ];
        // Las sedes de otras sedes (fuera de su alcance) o desactivadas que ya tenía se conservan
        if ($actual !== null && ! $todas) {
            $conservar = $actual->sedes()->whereNotIn('sedes.id', $elegibles)->pluck('sedes.id')->all();
            $pedidas = array_values(array_unique([...$pedidas, ...$conservar]));
        }

        return [$datos, $todas, $todas ? [] : $pedidas];
    }

    private function tituloLibre(string $titulo, ?int $excepto): void
    {
        $existe = Vacante::where('estado', '!=', 'cerrada')->whereRaw('LOWER(titulo) = ?', [mb_strtolower($titulo)])
            ->when($excepto !== null, fn ($q) => $q->where('id', '!=', $excepto))->exists();
        if ($existe) {
            throw ValidationException::withMessages(['titulo' => 'Ya hay una vacante abierta con ese título. Edítala (por ejemplo, sube las plazas) o cierra la anterior.']);
        }
    }

    /**
     * Texto «uno por renglón» (o lista) → lista limpia.
     *
     * @return list<string>
     */
    public function renglones(mixed $valor): array
    {
        $lista = is_array($valor) ? $valor : preg_split('/\r\n|\r|\n/', (string) ($valor ?? ''));

        return array_values(array_filter(array_map(fn ($r) => is_string($r) ? trim((string) preg_replace('/^[\s\-•*·]+/u', '', $r)) : '', $lista ?: []),
            fn ($r) => $r !== ''));
    }

    private function codigoNuevo(): string
    {
        do {
            $codigo = Str::lower(Str::random(10));
        } while (Vacante::withoutGlobalScopes()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    /** Hoy en la zona de la empresa (para publicar y vencer vacantes). */
    public static function hoy(Empresa $empresa): string
    {
        return now($empresa->zona_horaria ?: 'America/Mexico_City')->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function foto(Vacante $v): array
    {
        return $v->only(['titulo', 'puesto_id', 'departamento_id', 'plazas', 'todas_las_sedes', 'estado', 'cierre_motivo', 'fecha_publicacion', 'fecha_cierre'])
            + ['sedes' => $v->sedes()->orderBy('sedes.id')->pluck('sedes.id')->all()];
    }
}
