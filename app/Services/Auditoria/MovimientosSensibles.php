<?php

namespace App\Services\Auditoria;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Bitácora de auditoría, mejoras de estructura (ADR-0016):
 *
 *  - sedeDe(): la sede del registro tocado, para que «Su sede» pueda ver los
 *    movimientos de su sede (si la empresa lo elige en Configuración).
 *  - avisar(): los movimientos sensibles (cambios de roles y permisos,
 *    eliminaciones definitivas, exportaciones con datos personales y
 *    módulos contratados) llegan a la campana de quien ve TODA la bitácora,
 *    si la empresa encendió el aviso «movimientos_sensibles» (apagado por
 *    omisión: nadie cambia de comportamiento sin configurarlo). Los bloqueos
 *    de cuenta ya avisan por su lado (aviso «cuenta_bloqueada»).
 */
class MovimientosSensibles
{
    public const AVISO = 'movimientos_sensibles';

    public const TIPO = 'movimiento_sensible';

    /** Eventos exactos que se avisan. */
    public const EVENTOS = [
        'permisos.rol_actualizado', 'roles.creado', 'roles.actualizado', 'roles.eliminado',
        'empresas.modulos_actualizados', 'configuracion.auditoria',
    ];

    /** Terminaciones de evento que se avisan (cualquier módulo). */
    public const TERMINACIONES = ['.eliminado_definitivo', '.exportado_con_datos_personales'];

    /** @var array<string, bool> */
    private static array $conSede = [];

    public static function esSensible(string $evento): bool
    {
        if (in_array($evento, self::EVENTOS, true)) {
            return true;
        }
        foreach (self::TERMINACIONES as $fin) {
            if (str_ends_with($evento, $fin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sede del registro auditado (si su tabla tiene sede). Nunca impide guardar la bitácora.
     */
    public static function sedeDe(?string $tipo, mixed $id): ?int
    {
        if ($tipo === null || $id === null || ! class_exists($tipo)) {
            return null;
        }
        if ($tipo === Sede::class) {
            return (int) $id;
        }

        try {
            $modelo = new $tipo;
            $tabla = $modelo->getTable();
            self::$conSede[$tabla] ??= Schema::hasColumn($tabla, 'sede_id');
            if (! self::$conSede[$tabla]) {
                return null;
            }
            $sede = DB::table($tabla)->where($modelo->getKeyName(), $id)->value('sede_id');

            return $sede === null ? null : (int) $sede;
        } catch (Throwable) {
            return null;
        }
    }

    public function avisar(Auditoria $fila): void
    {
        if ($fila->empresa_id === null || ! self::esSensible((string) $fila->evento)) {
            return;
        }

        try {
            $empresa = Empresa::find($fila->empresa_id);
            if ($empresa === null || ! $empresa->aviso(self::AVISO)) {
                return;
            }

            $destinatarios = $this->quienesVenTodo((int) $empresa->id, $fila->user_id === null ? null : (int) $fila->user_id);
            if ($destinatarios === []) {
                return;
            }

            $lector = app(LectorAuditoria::class);
            $registro = $lector->registros(collect([$fila]))[$fila->auditable_type.'#'.$fila->auditable_id] ?? null;
            $autor = $fila->user_id === null ? 'El sistema' : (User::whereKey($fila->user_id)->value('name') ?? 'Alguien');
            $titulo = $lector->modulo($fila->evento).' · '.$lector->accion($fila->evento);

            app(Tenant::class)->conEmpresa((int) $empresa->id, fn () => app(CentroNotificaciones::class)->avisar((int) $empresa->id, $destinatarios, self::TIPO, [
                'titulo' => mb_substr('Movimiento sensible: '.$titulo, 0, 150),
                'texto' => $autor.($registro ? ' · '.$registro : '').'. Revisa el detalle en la Bitácora de auditoría.',
                'url' => route('auditoria.index', array_filter(['registro' => LectorAuditoria::alias($fila->auditable_type), 'id' => $fila->auditable_id])),
            ]));
        } catch (Throwable $e) {
            // El aviso nunca impide guardar la bitácora
            report($e);
        }
    }

    /**
     * Usuarios activos de la empresa que ven TODA la bitácora (sin quien hizo el movimiento).
     *
     * @return list<int>
     */
    private function quienesVenTodo(int $empresaId, ?int $autor): array
    {
        $candidatos = User::query()->where('users.empresa_id', $empresaId)->where('users.activo', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('usuario_roles as ur')
                ->join('rol_permisos as rp', 'rp.rol_id', '=', 'ur.rol_id')
                ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
                ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
                ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->whereColumn('ur.user_id', 'users.id')->where('m.clave', 'auditoria')->where('a.clave', 'ver'))
            ->when($autor !== null, fn ($q) => $q->whereKeyNot($autor))
            ->get();

        $autorizador = app(Autorizador::class);

        return $candidatos->filter(fn (User $u) => $autorizador->alcanceDeEmpresa($u, 'auditoria.ver'))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
