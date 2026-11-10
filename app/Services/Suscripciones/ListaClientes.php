<?php

namespace App\Services\Suscripciones;

use App\Models\Promocion;
use App\Models\Suscripcion;
use App\Services\Comercial\AjustesComerciales;
use App\Support\Entrada;
use Illuminate\Http\Request;

/**
 * Lo que muestran las pestañas Clientes, Por revisar y Promociones del
 * Catálogo comercial (solo Super Administrador; datos de la plataforma).
 */
class ListaClientes
{
    /** Filtros de la pestaña Clientes => texto del botón. */
    public const FILTROS = [
        'todos' => 'Todos',
        'prueba' => 'En prueba',
        'activa' => 'Activas',
        'por_vencer' => 'Por vencer',
        'en_gracia' => 'En gracia',
        'solo_consulta' => 'Solo consulta',
    ];

    public function __construct(private readonly AjustesComerciales $ajustes) {}

    /**
     * @return array<string, mixed>
     */
    public function datos(Request $request): array
    {
        $filtro = Entrada::texto($request->query('cliente_estado'), 'todos');
        $filtro = isset(self::FILTROS[$filtro]) ? $filtro : 'todos';
        $buscar = mb_substr(trim(Entrada::texto($request->query('q'))), 0, 80);
        $dias = $this->ajustes->diasPorVencer();

        $todas = Suscripcion::query()->with(['empresa:id,nombre_comercial,activo,deleted_at', 'plan:id,nombre'])
            ->orderBy('vence_en')->get();
        // El estado que vale es el de las fechas (la tarea diaria lo guarda una vez al día)
        $todas->each(fn (Suscripcion $s) => $s->setAttribute('estado_hoy', $s->estadoEfectivo(null, $dias)));
        $conteos = collect(self::FILTROS)->keys()->mapWithKeys(fn ($f) => [$f => $f === 'todos' ? $todas->count()
            : $todas->filter(fn ($s) => $f === 'solo_consulta' ? in_array($s->estado_hoy, [Suscripcion::SOLO_CONSULTA, Suscripcion::CANCELADA], true) : $s->estado_hoy === $f)->count()]);

        $clientes = $todas
            ->when($filtro !== 'todos', fn ($c) => $c->filter(fn ($s) => $filtro === 'solo_consulta'
                ? in_array($s->estado_hoy, [Suscripcion::SOLO_CONSULTA, Suscripcion::CANCELADA], true) : $s->estado_hoy === $filtro))
            ->when($buscar !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower((string) $s->empresa?->nombre_comercial), mb_strtolower($buscar))))
            ->values();

        return [
            'clientes' => $clientes,
            'clienteEstado' => $filtro,
            'buscarCliente' => $buscar,
            'conteosClientes' => $conteos,
            'porRevisar' => $todas->whereNull('revisado_en')->sortBy('created_at')->values(),
            'promociones' => Promocion::query()->with('planes:id,nombre')->orderByDesc('activo')->orderBy('nombre')->get(),
        ];
    }
}
