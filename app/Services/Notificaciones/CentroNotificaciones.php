<?php

namespace App\Services\Notificaciones;

use App\Models\Notificacion;
use App\Models\User;
use App\Support\HoraLocal;
use Illuminate\Support\Collection;

/**
 * Centro de notificaciones (la campana del encabezado). Cada notificación es
 * de un usuario y de una empresa; la campana consulta un JSON pequeño cada
 * 30 s (hosting compartido: sin websockets) y se pausa con la pestaña oculta.
 *
 * Los módulos crean avisos con avisar(); una notificación puede llevar
 * botones de acción (p. ej. [Autorizar ingreso] [Rechazar]) que la lista
 * envía al formulario del módulo: el módulo decide (permiso y alcance).
 * Cuando el asunto se resuelve, resolver() quita los botones a todos.
 */
class CentroNotificaciones
{
    /** Cuántas muestra la campana. */
    public const EN_CAMPANA = 8;

    /** Tipos de notificación => [icono, nivel]. */
    public const TIPOS = [
        'candidato_llegada' => ['bi-person-plus-fill', 'info'],
        'candidato_autocaptura' => ['bi-phone', 'info'],
        'autorizacion_visita' => ['bi-door-open-fill', 'alerta'],
        'autorizacion_candidato' => ['bi-person-workspace', 'alerta'],
        'autorizacion_respuesta' => ['bi-patch-check-fill', 'exito'],
        'delegacion' => ['bi-person-gear', 'info'],
    ];

    public function __construct(private readonly HoraLocal $hora) {}

    /**
     * Crea la misma notificación para varios usuarios (sin repetir).
     *
     * @param  iterable<int>  $usuarios  ids de usuario
     * @param  array{titulo: string, texto?: ?string, url?: ?string, referencia_tipo?: ?string, referencia_id?: ?int, acciones?: ?array}  $datos
     * @return list<int> ids de usuario avisados
     */
    public function avisar(int $empresaId, iterable $usuarios, string $tipo, array $datos): array
    {
        [$icono, $nivel] = self::TIPOS[$tipo] ?? ['bi-bell', 'info'];
        $avisados = [];
        foreach (collect($usuarios)->map(fn ($id) => (int) $id)->filter()->unique() as $userId) {
            $n = new Notificacion([
                'user_id' => $userId,
                'tipo' => $tipo,
                'titulo' => mb_substr($datos['titulo'], 0, 150),
                'texto' => isset($datos['texto']) ? mb_substr((string) $datos['texto'], 0, 500) : null,
                'url' => $datos['url'] ?? null,
                'icono' => $icono,
                'nivel' => $nivel,
                'referencia_tipo' => $datos['referencia_tipo'] ?? null,
                'referencia_id' => $datos['referencia_id'] ?? null,
                'acciones' => $datos['acciones'] ?? null,
            ]);
            $n->forceFill(['empresa_id' => $empresaId])->save();
            $avisados[] = $userId;
        }

        return $avisados;
    }

    /**
     * El asunto ya se resolvió (alguien respondió): las notificaciones de esa
     * referencia pierden sus botones y quedan leídas para todos.
     */
    public function resolver(string $referenciaTipo, int $referenciaId): void
    {
        Notificacion::where('referencia_tipo', $referenciaTipo)->where('referencia_id', $referenciaId)
            ->update(['acciones' => null, 'leida_en' => now(), 'updated_at' => now()]);
    }

    public function noLeidas(User $usuario): int
    {
        return Notificacion::de($usuario)->noLeidas()->count();
    }

    /**
     * Resumen para la campana (JSON).
     *
     * @return array{no_leidas: int, lista: list<array<string, mixed>>}
     */
    public function resumen(User $usuario): array
    {
        return [
            'no_leidas' => $this->noLeidas($usuario),
            'lista' => $this->recientes($usuario)->map(fn (Notificacion $n) => $this->paraLista($n))->values()->all(),
        ];
    }

    /** @return Collection<int, Notificacion> */
    public function recientes(User $usuario, int $cuantas = self::EN_CAMPANA): Collection
    {
        return Notificacion::de($usuario)->orderByRaw('leida_en IS NULL DESC')->orderByDesc('id')->limit($cuantas)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function paraLista(Notificacion $n): array
    {
        return [
            'id' => $n->id,
            'titulo' => $n->titulo,
            'texto' => $n->texto,
            'icono' => $n->icono,
            'nivel' => $n->nivel,
            'leida' => $n->leida_en !== null,
            'hace' => $this->hace($n->created_at),
            'fecha' => $this->hora->formatear($n->created_at),
            'abrir' => route('notificaciones.abrir', $n->id),
            'acciones' => $this->acciones($n),
        ];
    }

    /**
     * Botones de la notificación: cada uno es un formulario POST a la ruta del módulo.
     *
     * @return list<array{etiqueta: string, url: string, campos: array<string, string>, estilo: string}>
     */
    public function acciones(Notificacion $n): array
    {
        $lista = [];
        foreach ((array) $n->acciones as $a) {
            if (! is_array($a) || empty($a['url']) || empty($a['etiqueta'])) {
                continue;
            }
            $lista[] = [
                'etiqueta' => (string) $a['etiqueta'],
                'url' => (string) $a['url'],
                'campos' => array_map('strval', (array) ($a['campos'] ?? [])),
                'estilo' => (string) ($a['estilo'] ?? 'normal'),
            ];
        }

        return $lista;
    }

    public function marcarLeida(Notificacion $n): void
    {
        if ($n->leida_en === null) {
            $n->forceFill(['leida_en' => now()])->save();
        }
    }

    public function marcarTodas(User $usuario): int
    {
        return Notificacion::de($usuario)->noLeidas()->update(['leida_en' => now(), 'updated_at' => now()]);
    }

    public function hace(?\DateTimeInterface $fecha): string
    {
        if ($fecha === null) {
            return '';
        }
        $minutos = (int) max(0, now()->diffInMinutes($fecha, true));

        return match (true) {
            $minutos < 1 => 'Hace un momento',
            $minutos < 60 => "Hace {$minutos} min",
            $minutos < 1440 => 'Hace '.intdiv($minutos, 60).' h',
            default => $this->hora->formatear($fecha, 'd/m H:i'),
        };
    }
}
