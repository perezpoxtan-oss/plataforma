<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Models\PaseSalida;
use App\Models\User;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Relojes\ArticulosPorRevisar;
use App\Support\Entrada;

/**
 * C-19 (B-07, P-07, DD-18): lo que el proveedor o contratista trae y se lleva.
 *
 *  - Al entrar: lista corta y opcional (RegistroAccesos, «¿Trae herramienta o
 *    equipo?»). Si no trae nada, no hay ningún paso de más.
 *  - Al darle salida: la lista se muestra con «Sale con lo mismo» ya marcada.
 *    Si el guardia la desmarca (lleva algo de más o le falta algo), escribe
 *    qué notó y el caso se **canaliza** al supervisor y al jefe de seguridad
 *    de la sede: campana, Mis pendientes y reloj (ArticulosPorRevisar). El
 *    supervisor lo revisa y toca «Atender».
 *  - Si su empresa tiene un pase de salida aprobado en esa sede (equipo de la
 *    empresa que se lleva a reparar, por ejemplo), la tarjeta lo muestra y la
 *    salida queda ligada a ese pase. El pase sigue su circuito en Pases de salida.
 */
class ArticulosDelProveedor
{
    public const TIPO_AVISO = 'acceso_articulos';

    private const MEMORIA = 'c19.pases_abiertos';

    public function __construct(
        private readonly CentroNotificaciones $centro,
        private readonly AdministradorRoles $auditoria,
        private readonly Destinatarios $destinatarios,
    ) {}

    /**
     * Pase de salida aprobado (listo para salir) de la empresa externa del
     * acceso en su sede. Una sola consulta por petición para todas las tarjetas.
     */
    public function paseAbierto(Acceso $a): ?PaseSalida
    {
        if ($a->proveedor_id === null || ! in_array($a->tipo, Acceso::CON_AUTORIZACION, true)) {
            return null;
        }
        $clave = self::MEMORIA.'.'.(int) $a->empresa_id;
        $pases = request()->attributes->get($clave);
        if ($pases === null) {
            $pases = PaseSalida::query()->where('estado', PaseSalida::APROBADO)->whereNotNull('proveedor_id')
                ->orderBy('id')->limit(500)->get(['id', 'sede_id', 'proveedor_id', 'folio', 'motivo', 'estado']);
            request()->attributes->set($clave, $pases);
        }

        return $pases->first(fn (PaseSalida $p) => (int) $p->proveedor_id === (int) $a->proveedor_id && (int) $p->sede_id === (int) $a->sede_id);
    }

    /**
     * Comparación al dar salida. Solo si la pantalla la mostró
     * (articulos_revisados); las pantallas de antes salen como siempre.
     *
     * @param  array<string, mixed>  $opciones  articulos_revisados, sale_con_lo_mismo, articulos_nota, pase_salida_id
     * @return bool ¿se canalizó al supervisor?
     */
    public function alDarSalida(User $actor, Acceso $a, array $opciones): bool
    {
        $cambios = [];
        $pase = $this->paseAbierto($a);
        if ($pase !== null && (int) ($opciones['pase_salida_id'] ?? 0) === (int) $pase->id) {
            $cambios['pase_salida_id'] = $pase->id;
        }
        $revisados = filter_var($opciones['articulos_revisados'] ?? false, FILTER_VALIDATE_BOOLEAN) && $a->articulos()->exists();
        $canalizar = false;
        if ($revisados) {
            $mismo = filter_var($opciones['sale_con_lo_mismo'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $nota = mb_substr(trim((string) preg_replace('/\s+/u', ' ', Entrada::texto($opciones['articulos_nota'] ?? null))), 0, 250);
            $cambios += ['articulos_salida' => $mismo ? 'mismo' : 'distinto', 'articulos_nota' => $mismo || $nota === '' ? null : $nota,
                'articulos_por_revisar' => ! $mismo];
            $canalizar = ! $mismo;
        }
        if ($cambios === []) {
            return false;
        }
        Acceso::whereKey($a->id)->update($cambios + ['actualizado_por' => $actor->id, 'updated_at' => now()]);
        $a->forceFill($cambios);
        $this->auditoria->auditar($actor, 'accesos.articulos_salida', $a, null, array_filter([
            'salida' => $cambios['articulos_salida'] ?? null, 'nota' => $cambios['articulos_nota'] ?? null,
            'trae' => $a->articulos()->get()->map->texto()->all() ?: null, 'pase' => $pase !== null && isset($cambios['pase_salida_id']) ? $pase->folio : null,
        ]));
        if ($canalizar) {
            $this->canalizar($actor, $a);
        }

        return $canalizar;
    }

    /** Aviso inmediato al supervisor y al jefe de seguridad de la sede (con «Atender»). */
    private function canalizar(User $actor, Acceso $a): void
    {
        $a->loadMissing('sede:id,nombre');
        $avisar = $this->destinatarios->conPermiso((int) $a->empresa_id, 'accesos.aprobar', (int) $a->sede_id)
            ->concat(app(Escalamiento::class)->jefesDeSeguridad((int) $a->empresa_id, (int) $a->sede_id))
            ->unique('id')->reject(fn (User $u) => $u->id === $actor->id);
        $this->centro->avisar((int) $a->empresa_id, $avisar->pluck('id'), self::TIPO_AVISO, [
            'titulo' => 'Salió con algo distinto: '.$a->nombre,
            'texto' => ($a->empresa_procedencia ? $a->empresa_procedencia.' · ' : '').'No salió con lo mismo que trajo'.($a->articulos_nota ? ': '.$a->articulos_nota : '')
                .'. Dio la salida '.$actor->name.' en '.($a->sede?->nombre ?? 'la caseta').'. Revisa y toca «Atender».',
            'url' => route('accesos.index', ['pestana' => 'historial', 'q' => $a->nombre]).'#acceso-'.$a->id,
            'referencia_tipo' => ArticulosPorRevisar::REFERENCIA, 'referencia_id' => (int) $a->id,
            'acciones' => [['etiqueta' => 'Atender', 'url' => route('accesos.revisar-articulos', $a->id), 'campos' => ['_method' => 'PATCH'], 'estilo' => 'aceptar']],
        ]);
    }

    /** «Atender»: el supervisor revisó lo que se llevó. */
    public function revisar(User $actor, Acceso $a): void
    {
        if (Acceso::whereKey($a->id)->where('articulos_por_revisar', true)->whereNull('articulos_revisado_en')
            ->update(['articulos_revisado_en' => now(), 'articulos_revisado_por' => $actor->id, 'actualizado_por' => $actor->id, 'updated_at' => now()]) === 0) {
            throw new MovimientoNoPermitido('Esta salida ya se había revisado.');
        }
        $this->centro->resolver(ArticulosPorRevisar::REFERENCIA, (int) $a->id);
        $this->auditoria->auditar($actor, 'accesos.articulos_revisados', $a, ['articulos_por_revisar' => true], ['revisado' => true]);
    }
}
