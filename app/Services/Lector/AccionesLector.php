<?php

namespace App\Services\Lector;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\EquipoResponsiva;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\Persona;
use App\Models\PrestamoLlave;
use App\Models\Proveedor;
use App\Models\Responsiva;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Gafetes\AdministradorGafetes;
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
 * C-44 (Etapa 8): un colaborador, un vehículo, una persona del padrón o una
 * empresa externa ofrecen lo que hace la caseta: «Dar salida» si está en
 * sitio (abre la salida a la vista ya buscada, con su revisión de gafete e
 * identificación) o «Nuevo ingreso» (abre el registro con la persona o el
 * vehículo ya elegidos). Un registro con el acceso restringido solo lo avisa.
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
        private readonly ConsultaAccesos $accesos,
    ) {}

    /** Tipos del lector que se registran en la Bitácora de accesos: tipo => columna de accesos. */
    public const DE_CASETA = ['colaborador' => 'colaborador_id', 'vehiculo' => 'vehiculo_id', 'persona' => 'persona_id', 'proveedor' => 'proveedor_id'];

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
            'gafete' => Gafete::find($encontrado['id']),
            'colaborador' => Colaborador::find($encontrado['id']),
            'vehiculo' => Vehiculo::find($encontrado['id']),
            'persona' => Persona::find($encontrado['id']),
            'proveedor' => Proveedor::find($encontrado['id']),
            default => null,
        };
        if ($registro === null) {
            return null;
        }
        $base = ['titulo' => $encontrado['titulo'], 'detalle' => $encontrado['detalle'] ?? null, 'ficha' => $encontrado['url']];
        $resultado = match (true) {
            $registro instanceof Llave => $this->deLlave($actor, $registro, $base),
            $registro instanceof Equipo => $this->deEquipo($actor, $registro, $base),
            $registro instanceof Gafete => $this->deGafete($actor, $registro, $base),
            default => $this->deCaseta($actor, $encontrado['tipo'], $registro, $base),
        };

        // Un aviso sin acción (acceso restringido) también se muestra: la caseta debe verlo antes de abrir la ficha
        return $resultado === null || ($resultado['acciones'] === [] && empty($resultado['aviso'])) ? null : $resultado;
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
                'estado' => 'La tiene '.$prestamo->nombreReceptor().' desde el '.app(HoraLocal::class)->formatear($prestamo->prestado_en, 'd/m H:i')
                    .'. Garantía: '.$prestamo->textoGarantia().'.',
                'acciones' => [[
                    'etiqueta' => 'Recibir', 'icono' => 'bi-box-arrow-in-down', 'metodo' => 'PATCH', 'principal' => true,
                    'url' => route('prestamo_llaves.recibir', $prestamo->id),
                    // C-45 (DD-19): recepción común OK / Dañado / No regresó (OK marcado)
                    'recepcion' => ['noRegreso' => true],
                    'nota' => $prestamo->tipo_garantia === 'ninguna' ? null : 'Después, devuelve su garantía: '.$prestamo->textoGarantia().'.',
                ]]];
        }
        // Revisión Etapa 2 (R2-02): una llave «No regresó» no está en caseta; si apareció, se recibe
        if ($llave->activo && $llave->noRegreso()) {
            $ultimo = PrestamoLlave::where('llave_id', $llave->id)->where('estado', PrestamoLlave::NO_REGRESO)->where('anulado', false)
                ->latest('prestado_en')->first();
            if ($ultimo === null || ! $this->enAlcance($actor, 'prestamo_llaves.editar', $ultimo)) {
                return null;
            }

            return $base + ['icono' => 'bi-exclamation-octagon', 'tipo' => 'Llave que no regresó',
                'estado' => 'No regresó: la tenía '.$ultimo->nombreReceptor().' desde el '.app(HoraLocal::class)->formatear($ultimo->prestado_en, 'd/m H:i').'. Si apareció, recíbela.',
                'acciones' => [[
                    'etiqueta' => 'Recibir', 'icono' => 'bi-box-arrow-in-down', 'metodo' => 'PATCH', 'principal' => true,
                    'url' => route('prestamo_llaves.recibir', $ultimo->id),
                    'recepcion' => ['noRegreso' => false],
                    'nota' => $ultimo->tipo_garantia === 'ninguna' ? null : 'Después, devuelve su garantía: '.$ultimo->textoGarantia().'.',
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
            'estado' => 'Está a cargo de '.$responsiva->nombreReceptor().' en la responsiva '.$responsiva->folio.' ('.$fila->etiquetaModalidad().').',
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

    /**
     * C-45 (DD-19): un gafete «No regresó» que apareció se recibe aquí (OK o
     * dañado). El gafete en uso sigue yendo a «Dar salida» (Gafete::urlLector).
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function deGafete(User $actor, Gafete $gafete, array $base): ?array
    {
        if (! $gafete->activo || ! $gafete->noRegreso() || ! ($actor->can('gafetes.editar') || $actor->can('accesos.editar'))
            || ! app(AdministradorGafetes::class)->limitar(Gafete::query(), $actor, 'gafetes.ver')->whereKey($gafete->id)->exists()) {
            return null;
        }

        return $base + ['icono' => 'bi-exclamation-octagon', 'tipo' => 'Gafete que no regresó',
            'estado' => 'Está marcado «No regresó». Si ya lo devolvieron, recíbelo.',
            'acciones' => [[
                'etiqueta' => 'Recibir', 'icono' => 'bi-box-arrow-in-down', 'metodo' => 'PATCH', 'principal' => true,
                'url' => route('gafetes.recibir', $gafete->id), 'recepcion' => ['noRegreso' => false],
                'nota' => 'Vuelve a DISPONIBLE para prestarse en caseta.',
            ]]];
    }

    /**
     * C-44: colaborador, vehículo, persona o empresa externa → «Dar salida» o «Nuevo ingreso».
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function deCaseta(User $actor, string $tipo, Model $registro, array $base): ?array
    {
        $columna = self::DE_CASETA[$tipo] ?? null;
        if ($columna === null) {
            return null;
        }
        $iconos = ['colaborador' => 'bi-person-badge', 'vehiculo' => 'bi-car-front-fill', 'persona' => 'bi-person-vcard', 'proveedor' => 'bi-building'];
        $nombres = ['colaborador' => 'Colaborador', 'vehiculo' => 'Vehículo', 'persona' => 'Persona externa', 'proveedor' => 'Empresa externa'];
        $base += ['icono' => $iconos[$tipo], 'tipo' => $nombres[$tipo]];

        // ¿Está dentro? Su acceso abierto en las sedes donde da salidas
        if ($actor->can('accesos.editar')) {
            $acceso = $this->accesos->limitar(Acceso::query(), $actor, 'accesos.editar')
                ->where('accesos.estado', 'en_sitio')->where('accesos.movimiento', 'entrada')
                ->where('accesos.'.$columna, $registro->getKey())->latest('accesos.entrada_at')->first();
            if ($acceso !== null) {
                $busqueda = $tipo === 'vehiculo' && $acceso->placas ? $acceso->placas : $acceso->nombre;

                return $base + [
                    'estado' => 'Está en sitio desde el '.app(HoraLocal::class)->formatear($acceso->entrada_at, 'd/m H:i')
                        .($tipo === 'proveedor' || $tipo === 'vehiculo' ? ' ('.$acceso->nombre.')' : '').'.',
                    'acciones' => [[
                        'etiqueta' => 'Dar salida', 'icono' => 'bi-box-arrow-right', 'metodo' => 'GET', 'principal' => true,
                        'url' => route('accesos.index', ['salida' => $busqueda]),
                        'nota' => 'Se abre la Bitácora de accesos con su tarjeta: revisa el gafete y la identificación antes de confirmar.',
                    ]]];
            }
        }

        // El acceso restringido se avisa aquí mismo (la caseta lo ve antes que nada)
        if (method_exists($registro, 'restringido') && $registro->restringido()) {
            return $base + ['estado' => rtrim($registro->textoRestriccion(), '.').'.', 'aviso' => true, 'acciones' => []];
        }
        if (! (bool) $registro->getAttribute('activo') || ! $actor->can('accesos.crear')
            || $this->accesos->sedesParaElegir($actor, 'accesos.crear')->isEmpty()) {
            return null;
        }

        return $base + ['estado' => 'No está en sitio.',
            'acciones' => [[
                'etiqueta' => 'Nuevo ingreso', 'icono' => 'bi-person-plus-fill', 'metodo' => 'GET', 'principal' => true,
                'url' => route('accesos.ingreso-desde-lector', [$tipo, $registro->getKey()]),
                'nota' => $tipo === 'vehiculo'
                    ? 'Se abre el registro de ingreso con el vehículo ya elegido: completa quién viene en él.'
                    : 'Se abre el registro de ingreso con sus datos ya elegidos: revisa y guarda.',
            ]]];
    }
}
