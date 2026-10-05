<?php

namespace App\Services\Lector;

use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\Lector\Etiqueta;
use App\Support\Lector\Identificable;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Lector universal: recibe lo que entregó cualquier lector (QR con cámara,
 * NFC del celular, lector RFID/NFC o de código de barras por USB o Bluetooth,
 * o lo que se teclee) y encuentra a qué registro corresponde.
 *
 * Solo busca en los tipos que se piden (config/lector.php) y que el usuario
 * puede ver, siempre dentro de la empresa de trabajo.
 */
class Lector
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * @return array<string, class-string<Model&Identificable>>
     */
    public function tipos(): array
    {
        return config('lector.tipos', []);
    }

    /**
     * @param  list<string>|null  $tipos  null = todos los registrados
     * @return Collection<int, array{tipo: string, id: int, titulo: string, detalle: ?string, activo: bool, sede_id: ?int, url: string}>
     */
    public function resolver(User $usuario, ?int $empresaId, string $entrada, ?array $tipos = null, int $limite = 5): Collection
    {
        $entrada = trim($entrada);
        if ($empresaId === null || $entrada === '' || mb_strlen($entrada) > Etiqueta::MAXIMO) {
            return collect();
        }

        $codigo = Etiqueta::codigoDeUrl($entrada);
        $candidatos = $codigo !== null ? [] : array_values(array_unique([
            ...Etiqueta::candidatos($entrada),
            mb_strtoupper($entrada),
        ]));

        $clases = collect($this->tipos())
            ->when($tipos !== null, fn ($c) => $c->only($tipos))
            ->filter(fn (string $clase) => $usuario->can($clase::permisoLector()));

        return $this->tenant->conEmpresa($empresaId, fn () => $clases->flatMap(function (string $clase, string $tipo) use ($usuario, $codigo, $candidatos, $limite) {
            // Con alcance de sede solo aparece lo de sus sedes (lo que no tiene sede, como un vehículo o un colaborador corporativo, sí)
            $sedes = $this->autorizador->sedesPermitidas($usuario, $clase::permisoLector());

            return $clase::query()->coincideConLectura($codigo, $candidatos)->limit($limite * 3)->get()
                ->map(fn (Model&Identificable $registro) => ['tipo' => $tipo, 'id' => (int) $registro->getKey()] + $registro->resumenLector() + ['url' => $registro->urlLector()])
                ->filter(fn (array $r) => $sedes === null || $r['sede_id'] === null || in_array($r['sede_id'], $sedes, true));
        })->sortByDesc('activo')->values()->take($limite));
    }
}
