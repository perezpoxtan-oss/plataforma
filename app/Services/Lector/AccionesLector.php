<?php

namespace App\Services\Lector;

use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0014: al escanear un QR impreso FUERA de un formulario (/e/{código}),
 * en lugar de solo abrir la ficha se ofrece la acción más probable:
 *
 *  - una llave prestada → «Recibir» (y quién la tiene, con su garantía);
 *  - una llave en caseta → «Prestar» (abre «Prestar Llave» con la llave ya elegida);
 *  - un equipo en una responsiva → «Recibir» en OK (o abrir la responsiva si viene dañado o falta).
 *
 * Un gafete en uso ya lleva directo a «Dar salida» (Gafete::urlLector). Sin
 * acción probable (o sin permiso para hacerla), se abre la ficha como siempre.
 * Cada acción la vuelve a revisar su módulo (permiso, sede y alcance).
 */
class AccionesLector
{
    public function __construct(
        private readonly AdministradorPrestamosLlaves $prestamos,
        private readonly AdministradorResponsivas $responsivas,
    ) {}

    /**
     * Debe correr con la empresa de trabajo ya fijada en el Tenant.
     *
     * @param  array{tipo: string, id: int, titulo: string, detalle: ?string, url: string}  $encontrado
     * @return array{icono: string, tipo: string, titulo: string, detalle: ?string, estado: ?string, acciones: list<array<string, mixed>>, ficha: string}|null
     */
    public function para(User $actor, array $encontrado): ?array
    {
        $registro = match ($encontrado['tipo']) {
            'llave' => Llave::find($encontrado['id']),
            'equipo' => Equipo::with('tipo:id,nombre')->find($encontrado['id']),
            default => null,
        };
        if ($registro === null) {
            return null;
        }
        $base = ['titulo' => $encontrado['titulo'], 'detalle' => $encontrado['detalle'] ?? null, 'ficha' => $encontrado['url']];
        $resultado = $registro instanceof Llave ? $this->deLlave($actor, $registro, $base) : $this->deEquipo($actor, $registro, $base);

        return $resultado === null || $resultado['acciones'] === [] ? null : $resultado;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function deLlave(User $actor, Llave $llave, array $base): ?array
    {
        $prestamo = PrestamoLlave::vigentes()->with('colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno')->where('llave_id', $llave->id)->first();
        if ($prestamo !== null) {
            if (! $this->enAlcance($actor, 'prestamo_llaves.editar', $prestamo)) {
                return null;
            }

            return $base + ['icono' => 'bi-key-fill', 'tipo' => 'Llave prestada',
                'estado' => 'La tiene '.($prestamo->colaborador?->nombreCompleto() ?? '—').' desde el '.app(HoraLocal::class)->formatear($prestamo->prestado_en, 'd/m H:i')
                    .'. Garantía: '.$prestamo->textoGarantia().'.',
                'acciones' => [[
                    'etiqueta' => 'Recibir', 'icono' => 'bi-box-arrow-in-down', 'metodo' => 'PATCH', 'principal' => true,
                    'url' => route('prestamo_llaves.recibir', $prestamo->id),
                    'nota' => $prestamo->tipo_garantia === 'ninguna' ? null : 'Después, devuelve su garantía: '.$prestamo->textoGarantia().'.',
                ]]];
        }
        if (! $llave->activo || ! $actor->can('prestamo_llaves.crear')
            || ! $this->prestamos->sedesParaPrestar($actor)->contains('id', (int) $llave->sede_id)) {
            return null;
        }

        return $base + ['icono' => 'bi-key', 'tipo' => 'Llave en caseta', 'estado' => 'Está en caseta, lista para prestarse.',
            'acciones' => [[
                'etiqueta' => 'Prestar', 'icono' => 'bi-key', 'metodo' => 'GET', 'principal' => true,
                'url' => route('prestamo_llaves.index', ['prestar' => $llave->id]),
                'nota' => 'Se abre «Prestar Llave» con esta llave elegida: escanea el gafete de quien se la lleva.',
            ]]];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function deEquipo(User $actor, Equipo $equipo, array $base): ?array
    {
        $fila = EquipoResponsiva::whereNull('devuelto_en')->where('equipo_id', $equipo->id)
            ->with('responsiva.colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno')->first();
        $responsiva = $fila?->responsiva;
        if ($fila === null || $responsiva === null || ! $responsiva->enCampo() || ! $actor->can('responsivas.editar')
            || ! $this->responsivas->limitar(Responsiva::query(), $actor, 'responsivas.editar')->whereKey($responsiva->id)->exists()) {
            return null;
        }

        return $base + ['icono' => 'bi-box-seam', 'tipo' => 'Equipo en responsiva',
            'estado' => 'Está a cargo de '.($responsiva->colaborador?->nombreCompleto() ?? '—').' en la responsiva '.$responsiva->folio.' ('.$fila->etiquetaModalidad().').',
            'acciones' => [
                ['etiqueta' => 'Recibir (OK)', 'icono' => 'bi-box-arrow-in-down-left', 'metodo' => 'PATCH', 'principal' => true,
                    'url' => route('responsivas.recibir', $responsiva->id), 'campos' => ['equipos[]' => (string) $fila->id],
                    'nota' => 'Regresa en buen estado y vuelve a DISPONIBLE.'],
                ['etiqueta' => 'Viene dañado o falta: abrir la responsiva', 'icono' => 'bi-tools', 'metodo' => 'GET', 'principal' => false,
                    'url' => route('responsivas.index').'#responsiva-'.$responsiva->id, 'nota' => null],
            ]];
    }

    private function enAlcance(User $actor, string $permiso, Model $prestamo): bool
    {
        return $actor->can($permiso) && $this->prestamos->limitar(PrestamoLlave::query(), $actor, $permiso)->whereKey($prestamo->getKey())->exists();
    }
}
