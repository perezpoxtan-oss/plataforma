<?php

namespace App\Services\Autorizaciones;

use App\Models\Delegacion;
use App\Models\User;
use App\Support\Tenancy\Tenant;

/**
 * C-12 · «Delegar mis aprobaciones» en cadena (revisión de la Etapa 3a).
 *
 * Una sola regla para todos los módulos (visitas, escalamiento, pases,
 * reservas, entrevistas y Mis pendientes):
 *  - Si A delega en B y B delega en C, la cadena de A es [B, C]: lo de A
 *    le llega al ÚLTIMO de la cadena que pueda aprobarlo en ese módulo; si
 *    ninguno puede, se queda con A.
 *  - Solo cuentan delegaciones activas hoy (vencen solas) con delegado activo:
 *    si alguien de la cadena es dado de baja, la cadena se corta antes de él.
 *  - Si la cadena vuelve sobre sí misma (bucle), nadie de ella está
 *    disponible: lo de cada quien se queda con su titular (nada se pierde).
 *    Delegaciones::crear() impide cerrar bucles nuevos.
 *
 * Una lectura de delegaciones por instancia (la empresa la fija el Tenant).
 */
class CadenaDelegaciones
{
    private ?string $llave = null;

    /** @var array<int, int>|null titular => delegado */
    private ?array $mapa = null;

    /** @var array<int, User> delegados cargados */
    private array $usuarios = [];

    /** @var array<int, list<int>> */
    private array $cadenas = [];

    /** @return array<int, int> titular => delegado (activas, con delegado activo; la más reciente gana) */
    public function mapa(): array
    {
        // Vale para una petición, una empresa y un minuto (los servicios pueden vivir más que la petición)
        $llave = spl_object_id(app('request')).'|'.(app(Tenant::class)->empresaId() ?? 'x').'|'.now()->format('YmdHi');
        if ($this->llave !== $llave) {
            [$this->llave, $this->mapa, $this->usuarios, $this->cadenas] = [$llave, null, [], []];
        }
        if ($this->mapa === null) {
            $this->mapa = [];
            $filas = Delegacion::activas()->with('delegado:id,name,email,activo,empresa_id,es_superadmin')->orderBy('id')->get(['id', 'user_id', 'delegado_id']);
            foreach ($filas as $d) {
                if ($d->delegado === null || ! $d->delegado->activo) {
                    continue;
                }
                $this->mapa[(int) $d->user_id] = (int) $d->delegado_id;
                $this->usuarios[(int) $d->delegado_id] = $d->delegado;
            }
        }

        return $this->mapa;
    }

    /**
     * Quienes reciben lo de $id, en orden ([B, C] si A→B→C). Vacía si no
     * delega o si la cadena forma un bucle.
     *
     * @return list<int>
     */
    public function cadena(int $id): array
    {
        $mapa = $this->mapa();
        if (isset($this->cadenas[$id])) {
            return $this->cadenas[$id];
        }
        $visto = [$id => true];
        $lista = [];
        $actual = $id;
        while (isset($mapa[$actual])) {
            $siguiente = $mapa[$actual];
            if (isset($visto[$siguiente])) {
                return $this->cadenas[$id] = []; // bucle: nadie de la cadena está disponible
            }
            $visto[$siguiente] = true;
            $lista[] = $siguiente;
            $actual = $siguiente;
        }

        return $this->cadenas[$id] = $lista;
    }

    /**
     * A quién le llega lo de $id: el último de su cadena que cumpla $puede
     * (recibe el User); si nadie, $id.
     *
     * @param  callable(User): bool  $puede
     */
    public function destino(int $id, callable $puede): int
    {
        foreach (array_reverse($this->cadena($id)) as $delegado) {
            if ($puede($this->usuario($delegado))) {
                return $delegado;
            }
        }

        return $id;
    }

    /**
     * Titulares cuya cadena pasa por $id (quienes le delegan directa o en cadena), sin él.
     *
     * @return list<int>
     */
    public function titularesDe(int $id): array
    {
        $r = [];
        foreach (array_keys($this->mapa()) as $titular) {
            if ($titular !== $id && in_array($id, $this->cadena($titular), true)) {
                $r[] = $titular;
            }
        }

        return $r;
    }

    /** El delegado (ya cargado con lo necesario para revisar permisos). */
    public function usuario(int $id): User
    {
        $this->mapa();

        return $this->usuarios[$id] ??= User::findOrFail($id);
    }
}
