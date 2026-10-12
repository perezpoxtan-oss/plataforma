<?php

namespace App\Services\Lector;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Http\RedirectResponse;

/**
 * Mejoras padrones (ADR-0015): un solo camino de impresión de etiquetas.
 *
 * Cada padrón (Llaves, Gafetes, Vehículos, Equipos de seguridad, Equipos de
 * Protección Civil) tiene su hoja propia (llavero 6.5 × 3.5 cm, gafete doble
 * vista, calcomanía…). La empresa decide en Etiquetas QR → Plantillas:
 *
 *  - «modulo» (por omisión, como siempre): cada padrón imprime su hoja propia;
 *    la hoja ofrece «Imprimir con una plantilla» para ir a Etiquetas QR.
 *  - «plantillas»: los botones de imprimir de los padrones llevan directo a la
 *    hoja de Etiquetas QR con la plantilla de siempre de ese tipo (la última
 *    que usó la persona o la predeterminada), y la impresión queda en el
 *    Historial con «Reimprimir». Si la persona no puede usar Etiquetas QR, se
 *    imprime la hoja propia del padrón.
 *
 * Se guarda en empresas.preferencias['padrones']['impresion'].
 * Debe correr con la empresa de trabajo ya fijada en el Tenant.
 */
class ImpresionDesdePadron
{
    public const MODULO = 'modulo';

    public const PLANTILLAS = 'plantillas';

    public const OPCIONES = [
        self::MODULO => 'Hoja propia de cada padrón (como siempre)',
        self::PLANTILLAS => 'Plantillas de Etiquetas QR (un solo camino, con historial y reimpresión)',
    ];

    public function __construct(
        private readonly EtiquetasMasivas $etiquetas,
        private readonly PlantillasEtiquetas $plantillas,
        private readonly ImpresionesEtiquetas $impresiones,
        private readonly AdministradorRoles $auditoria,
    ) {}

    public function modo(?int $empresaId): string
    {
        $valor = $empresaId === null ? null : (Empresa::whereKey($empresaId)->first(['id', 'preferencias'])?->preferencias['padrones']['impresion'] ?? null);

        return isset(self::OPCIONES[$valor]) ? $valor : self::MODULO;
    }

    /**
     * Si la empresa imprime con plantillas: registra la impresión de esos
     * registros en Etiquetas QR y devuelve la redirección a su hoja; si no
     * (o la persona no puede usar Etiquetas QR, o nada se puede imprimir), null.
     *
     * @param  list<int>  $ids
     */
    public function redirigir(User $actor, int $empresaId, string $tipo, array $ids): ?RedirectResponse
    {
        if ($this->modo($empresaId) !== self::PLANTILLAS || ! $actor->can('etiquetas_qr.ver') || $ids === []) {
            return null;
        }
        $disponibles = $this->plantillas->disponibles($actor);
        $plantilla = $this->plantillas->preelegida($actor, $disponibles, $tipo);
        $etiquetas = $plantilla === null ? collect() : $this->etiquetas->paraImprimir($actor, array_map(fn (int $id) => $tipo.'-'.$id, $ids));
        if ($plantilla === null || $etiquetas->isEmpty()) {
            return null;
        }
        $impresion = $this->impresiones->registrar($actor, $plantilla, $etiquetas);

        return redirect()->route('etiquetas.impresion', array_filter([
            'impresion' => $impresion->id,
            'recortadas' => count($ids) > EtiquetasMasivas::MAXIMO ? 1 : null,
        ]));
    }

    public function guardar(User $actor, Empresa $empresa, string $modo): void
    {
        abort_unless(isset(self::OPCIONES[$modo]), 422);
        $antes = $this->modo((int) $empresa->id);
        $preferencias = $empresa->preferencias ?? [];
        $preferencias['padrones'] = array_merge($preferencias['padrones'] ?? [], ['impresion' => $modo]);
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $this->auditoria->auditar($actor, 'etiquetas_qr.configurado', $empresa, ['impresion_padrones' => $antes], ['impresion_padrones' => $modo]);
    }
}
