<?php

namespace App\Services\Procedimientos;

use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\Novedad;
use App\Models\Procedimiento;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * C-31 (D-20): «¿Qué hacer? Ver procedimiento». La empresa asocia un
 * procedimiento publicado a una categoría de Novedades (Robo → «Robo en
 * habitación») o a un tipo de equipo de Protección Civil (Extintor →
 * «Revisión de extintores»). El expediente del ticket y el punto del
 * recorrido muestran el enlace a quien puede ver ese procedimiento.
 *
 * Se guarda en las preferencias de la empresa (sin tabla nueva):
 * preferencias.procedimientos_vinculados = {"novedad:robo": 12, "equipo:EXTINTOR": 7}.
 *
 * Accidente no se ofrece: su formato no se toca (regla del proyecto) y el
 * enlace aparecería dentro de su ventana.
 */
class ProcedimientosVinculados
{
    public const PREFERENCIA = 'procedimientos_vinculados';

    /** Categorías de Novedades que se pueden asociar (las que se eligen al despachar y las automáticas, sin Accidente). */
    public const CATEGORIAS = ['incidente_general', 'habitacion', 'proteccion_civil', 'mantenimiento_pc', 'lost_found', 'robo'];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorProcedimientos $procedimientos,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /** ¿La empresa tiene Procedimientos? (sin el módulo no se ofrece nada) */
    public function disponible(int $empresaId): bool
    {
        return $this->autorizador->moduloActivo($empresaId, 'procedimientos');
    }

    /**
     * Claves que se pueden asociar => texto.
     *
     * @return array<string, string>
     */
    public static function claves(): array
    {
        $claves = [];
        foreach (self::CATEGORIAS as $c) {
            $claves['novedad:'.$c] = Novedad::CATEGORIAS[$c];
        }
        foreach (EquipoPc::CATEGORIAS as $c => $texto) {
            $claves['equipo:'.$c] = $texto;
        }

        return $claves;
    }

    /** @return array<string, int> */
    public function mapa(?Empresa $empresa): array
    {
        $guardado = $empresa?->preferencias[self::PREFERENCIA] ?? [];

        return is_array($guardado) ? array_map('intval', array_intersect_key(array_filter($guardado, 'is_numeric'), self::claves())) : [];
    }

    /**
     * Procedimientos publicados de la empresa que se pueden elegir.
     *
     * @return Collection<int, Procedimiento>
     */
    public function opciones(): Collection
    {
        return Procedimiento::where('estado', Procedimiento::PUBLICADO)->orderBy('titulo')->get(['id', 'clave', 'titulo']);
    }

    /**
     * @param  array<string, mixed>  $entrada  vinculos[clave] = id del procedimiento (vacío = ninguno)
     */
    public function guardar(User $actor, Empresa $empresa, array $entrada): void
    {
        $publicados = $this->opciones()->pluck('id')->all();
        $datos = Validator::make($entrada, [
            'vinculos' => ['nullable', 'array'],
            'vinculos.*' => ['nullable', 'integer', Rule::in($publicados)],
        ], ['vinculos.*.in' => 'Elige un procedimiento publicado de la lista.', 'vinculos.*.integer' => 'Elige un procedimiento publicado de la lista.'])->validate();
        $nuevo = array_map('intval', array_filter(array_intersect_key((array) ($datos['vinculos'] ?? []), self::claves()), fn ($v) => $v !== null && $v !== ''));
        $antes = $this->mapa($empresa);

        DB::transaction(function () use ($empresa, $nuevo) {
            $fresca = Empresa::query()->lockForUpdate()->find($empresa->id);
            $preferencias = is_array($fresca?->preferencias) ? $fresca->preferencias : [];
            if ($nuevo === []) {
                unset($preferencias[self::PREFERENCIA]);
            } else {
                $preferencias[self::PREFERENCIA] = $nuevo;
            }
            $fresca?->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
        });
        $empresa->refresh();
        if ($antes !== $nuevo) {
            $this->auditoria->auditar($actor, 'procedimientos.vinculados', $empresa, $antes, $nuevo);
        }
    }

    /**
     * El procedimiento asociado a una clave, si el actor lo puede ver y sigue publicado.
     */
    public function para(User $actor, ?Empresa $empresa, string $clave): ?Procedimiento
    {
        if ($empresa === null || ! $actor->can('procedimientos.ver') || ! $this->disponible((int) $empresa->id)) {
            return null;
        }
        $id = $this->mapa($empresa)[$clave] ?? null;
        if ($id === null) {
            return null;
        }

        return $this->procedimientos->limitar(Procedimiento::query(), $actor)->where('procedimientos.estado', Procedimiento::PUBLICADO)->find($id);
    }
}
