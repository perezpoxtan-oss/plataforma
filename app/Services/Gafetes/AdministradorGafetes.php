<?php

namespace App\Services\Gafetes;

use App\Models\Empresa;
use App\Models\Gafete;
use App\Models\Sede;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Inventarios\Vouchers;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Etiqueta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del Inventario de gafetes (réplica de gafete_proceso.php de SEGCAT).
 *
 * Alcance:
 *  - el gafete es de una sede: con alcance de sede solo se ven, generan,
 *    editan e imprimen los de las sedes propias; con alcance "propios", solo
 *    los que el usuario generó (dentro de sus sedes);
 *  - con alcance de empresa, todos.
 *
 * Todas las consultas corren con la empresa de trabajo ya fijada en el
 * Tenant: el filtro de empresa lo pone el modelo, no el programador.
 */
class AdministradorGafetes
{
    /** Tope real del lote (como SEGCAT, sin confiar en el "max" del formulario). */
    public const LOTE_MAXIMO = 50;

    /** Valor de la lista "Tipo de gafete" que pide escribir un tipo nuevo. */
    public const TIPO_NUEVO = '__nuevo__';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly Vouchers $vouchers,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Sedes en las que el actor usa el permiso: null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedesPermitidas(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    /**
     * Limita una consulta de gafetes a lo que el actor puede tocar con el permiso.
     *
     * @param  Builder<Gafete>  $consulta
     * @return Builder<Gafete>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $actor->activo ? ($this->autorizador->permisosEfectivos($actor)[$permiso] ?? null) : null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta
            ->when($efectivo->alcance !== Alcance::Empresa && $efectivo->sedes !== null, fn ($q) => $q->whereIn('gafetes.sede_id', $efectivo->sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('gafetes.creado_por', $actor->id));
    }

    /**
     * Ids que el actor puede tocar con un permiso; null = todos los que ve.
     *
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin) {
            return null;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso];
        if ($efectivo->alcance === Alcance::Empresa || ($efectivo->alcance === Alcance::Sede && $efectivo->sedes === null)) {
            return null;
        }

        return $this->limitar(Gafete::query(), $actor, $permiso)->pluck('gafetes.id')->map(fn ($id) => (int) $id)->all();
    }

    // -------------------------------------------------------------------- Tipos

    /**
     * Tipos de la empresa. La primera vez que se necesitan se crean los de
     * siempre (Visitante, Proveedor, Contratista), como la semilla de SEGCAT.
     *
     * @return Collection<int, TipoGafete>
     */
    public function tipos(?User $actor = null, bool $crearBasicos = false): Collection
    {
        if ($crearBasicos && ! TipoGafete::exists()) {
            DB::transaction(function () use ($actor) {
                foreach (TipoGafete::BASICOS as $nombre) {
                    $tipo = new TipoGafete(['nombre' => $nombre]);
                    $tipo->forceFill(['creado_por' => $actor?->id, 'actualizado_por' => $actor?->id])->save();
                }
            });
        }

        return TipoGafete::orderBy('nombre')->get(['id', 'nombre', 'activo']);
    }

    /**
     * El tipo elegido de la lista o, con "+ Nuevo tipo...", el que se escribió.
     * Si ya existe uno con ese nombre en la empresa se reutiliza (sin importar
     * mayúsculas ni acentos de más): no se duplica el catálogo por accidente.
     */
    private function resolverTipo(User $actor, mixed $id, ?string $nombreNuevo, ?int $actual = null): TipoGafete
    {
        if ($id === self::TIPO_NUEVO) {
            $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) $nombreNuevo));
            if ($nombre === '') {
                throw ValidationException::withMessages(['nombre_tipo_nuevo' => 'Escribe el nombre del tipo nuevo (por ejemplo: Capital Humano).']);
            }
            if (mb_strlen($nombre) > 50) {
                throw ValidationException::withMessages(['nombre_tipo_nuevo' => 'El nombre del tipo es muy largo (máximo 50 caracteres).']);
            }
            if (self::siglas($nombre, '') === '') {
                throw ValidationException::withMessages(['nombre_tipo_nuevo' => 'El nombre del tipo debe llevar letras o números.']);
            }
            $nombre = mb_strtoupper(mb_substr($nombre, 0, 1)).mb_substr($nombre, 1);

            $existente = TipoGafete::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first();
            if ($existente !== null) {
                if (! $existente->activo) {
                    $existente->forceFill(['activo' => true])->save();
                }

                return $existente;
            }

            $tipo = new TipoGafete(['nombre' => $nombre]);
            $tipo->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();
            $this->auditoria->auditar($actor, 'gafetes.tipo_creado', $tipo, null, ['nombre' => $tipo->nombre]);

            return $tipo;
        }

        $tipo = is_numeric($id) ? TipoGafete::find((int) $id) : null;
        if ($tipo === null || (! $tipo->activo && $tipo->id !== $actual)) {
            throw ValidationException::withMessages(['tipo_gafete_id' => 'Elige el tipo de gafete de la lista.']);
        }

        return $tipo;
    }

    // ---------------------------------------------------------------- Escritura

    /**
     * "Generar lote": Sede, Tipo y Cantidad (1 a 50). La nomenclatura sigue
     * a la última que exista con el mismo prefijo.
     *
     * @param  array<string, mixed>  $entrada
     * @return Collection<int, Gafete>
     */
    public function generarLote(User $actor, int $empresaId, array $entrada): Collection
    {
        $datos = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'tipo_gafete_id' => ['required'],
            'nombre_tipo_nuevo' => ['nullable', 'string', 'max:50'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:'.self::LOTE_MAXIMO],
        ], [
            'sede_id.required' => 'Elige la sede donde se usarán los gafetes.',
            'tipo_gafete_id.required' => 'Elige el tipo de gafete.',
            'cantidad.required' => 'Indica cuántos gafetes vas a crear.',
            'cantidad.integer' => 'La cantidad debe ser un número entero.',
            'cantidad.min' => 'La cantidad debe ser de 1 a '.self::LOTE_MAXIMO.' gafetes.',
            'cantidad.max' => 'La cantidad debe ser de 1 a '.self::LOTE_MAXIMO.' gafetes por lote.',
            'nombre_tipo_nuevo.max' => 'El nombre del tipo es muy largo (máximo 50 caracteres).',
        ])->validate();

        $permitidas = $this->sedesPermitidas($actor, 'gafetes.crear');
        $sede = Sede::where('activo', true)->find((int) $datos['sede_id']);
        if ($sede === null || ($permitidas !== null && ! in_array($sede->id, $permitidas, true))) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista (solo puedes generar gafetes para tus sedes).']);
        }

        $empresa = Empresa::findOrFail($empresaId);

        [$tipo, $gafetes] = DB::transaction(function () use ($actor, $datos, $sede, $empresa) {
            $tipo = $this->resolverTipo($actor, $datos['tipo_gafete_id'], $datos['nombre_tipo_nuevo'] ?? null);
            $prefijo = self::prefijo($empresa->nombre_comercial, $sede->codigo, $tipo->nombre);
            $siguiente = $this->ultimoConsecutivo($prefijo) + 1;

            $gafetes = collect();
            for ($i = 0; $i < (int) $datos['cantidad']; $i++, $siguiente++) {
                $gafete = new Gafete([
                    'sede_id' => $sede->id,
                    'tipo_gafete_id' => $tipo->id,
                    'nomenclatura' => $prefijo.str_pad((string) $siguiente, 3, '0', STR_PAD_LEFT),
                    'consecutivo' => $siguiente,
                ]);
                $gafete->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();
                $gafetes->push($gafete);
            }

            return [$tipo, $gafetes];
        });

        $this->auditoria->auditar($actor, 'gafetes.lote', $gafetes->first(), null, [
            'sede_id' => $sede->id,
            'tipo' => $tipo->nombre,
            'cantidad' => $gafetes->count(),
            'desde' => $gafetes->first()->nomenclatura,
            'hasta' => $gafetes->last()->nomenclatura,
        ]);

        return $gafetes;
    }

    /**
     * Editar: nomenclatura, tipo y etiqueta NFC / RFID.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Gafete $gafete, array $entrada): Gafete
    {
        $nomenclatura = self::normalizarNomenclatura($entrada['nomenclatura'] ?? null);
        $etiqueta = Etiqueta::normalizar(is_string($entrada['etiqueta_nfc'] ?? null) ? $entrada['etiqueta_nfc'] : null);

        Validator::make(['nomenclatura' => $nomenclatura, 'tipo_gafete_id' => $entrada['tipo_gafete_id'] ?? null, 'etiqueta_nfc' => $etiqueta], [
            'nomenclatura' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9Ñ][A-Z0-9Ñ\-\/. ]*$/u'],
            'tipo_gafete_id' => ['required'],
            'etiqueta_nfc' => ['nullable', 'string', 'max:64'],
        ], [
            'nomenclatura.required' => 'La nomenclatura es obligatoria.',
            'nomenclatura.max' => 'La nomenclatura es muy larga (máximo 50 caracteres).',
            'nomenclatura.regex' => 'La nomenclatura solo lleva letras, números, guiones, puntos o diagonales.',
            'tipo_gafete_id.required' => 'Elige el tipo de gafete.',
            'etiqueta_nfc.max' => 'El número de la etiqueta NFC / RFID es muy largo (máximo 64 caracteres).',
        ])->validate();

        $repetido = Gafete::where('nomenclatura', $nomenclatura)->whereKeyNot($gafete->id)->first();
        if ($repetido !== null) {
            throw ValidationException::withMessages(['nomenclatura' => "Ya existe un gafete con la nomenclatura «{$nomenclatura}» en esta empresa".($repetido->activo ? '.' : ' (dado de baja).')]);
        }

        $ocupada = Gafete::etiquetaOcupada($etiqueta, $gafete->id);
        if ($ocupada !== null) {
            throw ValidationException::withMessages(['etiqueta_nfc' => "Esa etiqueta NFC / RFID ya está asignada al gafete {$ocupada->nomenclatura}. Quítasela primero o usa otra."]);
        }

        $antes = $this->foto($gafete);

        DB::transaction(function () use ($actor, $gafete, $entrada, $nomenclatura, $etiqueta) {
            $tipo = $this->resolverTipo($actor, $entrada['tipo_gafete_id'] ?? null, $entrada['nombre_tipo_nuevo'] ?? null, $gafete->tipo_gafete_id);
            $gafete->fill([
                'nomenclatura' => $nomenclatura,
                'tipo_gafete_id' => $tipo->id,
                'etiqueta_nfc' => $etiqueta === '' ? null : $etiqueta,
            ]);
            $gafete->forceFill(['actualizado_por' => $actor->id])->save();
        });

        $despues = $this->foto($gafete);
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'gafetes.actualizado', $gafete, $antes, $despues);
        }

        return $gafete;
    }

    /**
     * Baja con voucher de reposición (Extraviado, Dañado o Robado). El costo
     * sugerido se recuerda por tipo de gafete.
     *
     * @param  array<string, mixed>  $entrada
     */
    public function darDeBaja(User $actor, Gafete $gafete, array $entrada): VoucherReposicion
    {
        if (! $gafete->activo) {
            throw ValidationException::withMessages(['motivo' => "El gafete {$gafete->nomenclatura} ya está dado de baja."]);
        }

        $datos = $this->vouchers->validar($entrada);
        $gafete->loadMissing('tipo:id,nombre');

        return $this->vouchers->darDeBaja($actor, $gafete, 'gafete', $gafete->nomenclatura, $datos, function () use ($actor, $gafete) {
            $gafete->forceFill(['activo' => false, 'actualizado_por' => $actor->id])->save();
            $this->auditoria->auditar($actor, 'gafetes.desactivado', $gafete, ['activo' => true], ['activo' => false]);
        }, $gafete->tipo?->nombre);
    }

    /**
     * Reactivar (por ejemplo, apareció el gafete). El voucher se conserva.
     */
    public function reactivar(User $actor, Gafete $gafete): void
    {
        if ($gafete->activo) {
            return;
        }
        $gafete->forceFill(['activo' => true, 'actualizado_por' => $actor->id])->save();
        $this->auditoria->auditar($actor, 'gafetes.reactivado', $gafete, ['activo' => false], ['activo' => true]);
    }

    // ------------------------------------------------------------- Nomenclatura

    /**
     * "<3 letras empresa>-<código sede>-<3 letras tipo>-", en mayúsculas y sin acentos.
     */
    public static function prefijo(string $empresa, string $codigoSede, string $tipo): string
    {
        $sede = (string) preg_replace('/[^A-Z0-9-]+/', '', mb_strtoupper(Str::ascii($codigoSede)));

        return self::siglas($empresa, 'EMP').'-'.($sede === '' ? 'SED' : $sede).'-'.self::siglas($tipo, 'GAF').'-';
    }

    /**
     * Las tres primeras letras o números: "Hotel Demo" -> "HOT", "Áreas" -> "ARE".
     */
    public static function siglas(string $texto, string $siNoHay): string
    {
        $limpio = (string) preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(Str::ascii($texto)));

        return $limpio === '' ? $siNoHay : substr($limpio, 0, 3);
    }

    /**
     * Mayor consecutivo ya usado con ese prefijo (aunque sea de otro tipo que
     * empiece con las mismas letras, o de un gafete dado de baja).
     */
    private function ultimoConsecutivo(string $prefijo): int
    {
        return (int) Gafete::where('nomenclatura', 'like', $prefijo.'%')->pluck('nomenclatura')
            ->map(fn (string $n) => substr($n, strlen($prefijo)))
            ->filter(fn (string $resto) => $resto !== '' && ctype_digit($resto))
            ->map(fn (string $resto) => (int) $resto)
            ->max();
    }

    public static function normalizarNomenclatura(mixed $valor): string
    {
        return is_string($valor) ? mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $valor))) : '';
    }

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(Gafete $g): array
    {
        return $g->only(['nomenclatura', 'sede_id', 'tipo_gafete_id', 'etiqueta_nfc', 'activo']);
    }
}
