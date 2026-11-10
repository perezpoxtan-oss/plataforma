<?php

namespace App\Services\Importacion;

use App\Models\Colaborador;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Padrones\AdministradorProveedores;
use App\Services\Vehiculos\AdministradorVehiculos;
use Illuminate\Database\Eloquent\Model;

/**
 * Padrón vehicular: mismo servicio que «Nuevo Vehículo»
 * (AdministradorVehiculos). Se identifican por sus placas.
 */
class ImportadorVehiculos extends Importador
{
    public function __construct(
        private readonly AdministradorVehiculos $vehiculos,
        private readonly AdministradorProveedores $proveedores,
    ) {}

    public function tipo(): string
    {
        return 'vehiculos';
    }

    public function modulo(): string
    {
        return 'vehiculos';
    }

    public function titulo(): string
    {
        return 'Padrón vehicular';
    }

    public function registro(): string
    {
        return 'vehículo';
    }

    public function rutaLista(): string
    {
        return route('vehiculos.index');
    }

    public function nombreClave(): string
    {
        return 'Placas';
    }

    public function columnas(): array
    {
        return [
            new Columna('placas', 'Placas', true, 'ABC1234', 'Únicas en la empresa; los espacios y guiones se quitan solos.'),
            new Columna('propiedad', 'Categoría', true, 'Particular', '«Particular» o «De una empresa».', alias: ['propiedad', 'de quien es']),
            new Columna('tipo', 'Tipo', true, 'Sedán', implode(', ', Vehiculo::TIPOS).'.', alias: ['tipo de vehiculo', 'estilo']),
            new Columna('descripcion_otro', 'Descripción del tipo', false, '', 'Solo si el tipo es «Otro» (por ejemplo: montacargas).'),
            new Columna('marca', 'Marca', true, 'Nissan'),
            new Columna('modelo', 'Modelo', false, 'Versa'),
            new Columna('color', 'Color', true, 'Blanco'),
            new Columna('capacidad', 'Capacidad (personas)', false, '', 'Solo autobuses, camiones ligeros o de una empresa: de 1 a 99.', alias: ['capacidad']),
            new Columna('numero_economico', 'Número económico', false, '', 'Obligatorio en los de una empresa.', alias: ['economico']),
            new Columna('empresa', 'Empresa propietaria', false, '', 'Obligatoria en los de una empresa; debe existir en Empresas externas.', alias: ['empresa', 'propietaria'], campos: ['proveedor_id']),
            new Columna('colaborador', 'Número de empleado del dueño', false, '', 'Opcional en los particulares: el colaborador dueño del auto.', alias: ['dueno', 'colaborador'], campos: ['colaborador_id']),
        ];
    }

    public function clave(array $fila): ?string
    {
        $placas = Vehiculo::normalizarPlacas($this->valor($fila, 'placas'));

        return $placas === '' ? null : $placas;
    }

    public function existente(array $fila): ?Model
    {
        return $this->vehiculos->conPlacas($this->valor($fila, 'placas'));
    }

    public function describir(Model $registro): string
    {
        /** @var Vehiculo $registro */
        return trim($registro->marca.' '.$registro->modelo.' · '.$registro->color).($registro->activo ? '' : ' (dado de baja)');
    }

    public function editable(User $actor, Model $existente): bool
    {
        $ids = $this->vehiculos->idsEnAlcance($actor, 'vehiculos.editar');

        return $ids === null || in_array((int) $existente->getKey(), $ids, true);
    }

    public function crear(User $actor, int $empresaId, array $fila): Model
    {
        [$datos, $errores] = $this->datos($fila);

        return $this->guardarOReportar($errores, fn () => $this->vehiculos->crear($actor, $empresaId, $datos));
    }

    public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model
    {
        /** @var Vehiculo $existente */
        $actual = $existente->only(['placas', 'propiedad', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id']);

        [$datos, $errores] = $this->datos($fila);

        return $this->guardarOReportar($errores, fn () => $this->vehiculos->actualizar($actor, $empresaId, $existente, $this->encima($actual, $datos)));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function datos(array $fila): array
    {
        $errores = [];
        // (no flecha: $errores va por referencia)
        $intentar = function (\Closure $fn) use (&$errores) {
            return $this->intentar($errores, $fn);
        };

        $datos = [
            'placas' => $this->valor($fila, 'placas'),
            'propiedad' => $intentar(fn () => $this->opcion($this->valor($fila, 'propiedad'), Vehiculo::PROPIEDADES, 'propiedad', 'una categoría válida', [
                'de empresa' => 'empresa', 'empresa' => 'empresa', 'flotilla' => 'empresa', 'propio' => 'particular',
            ])),
            'tipo' => $intentar(fn () => $this->opcion($this->valor($fila, 'tipo'), Vehiculo::TIPOS, 'tipo', 'un tipo de vehículo válido', [
                'sedan' => 'sedan', 'auto' => 'sedan', 'camioneta' => 'suv', 'suv' => 'suv', 'moto' => 'motocicleta', 'camion' => 'camion_ligero',
            ])),
            'descripcion_otro' => $this->valor($fila, 'descripcion_otro'),
            'marca' => $this->valor($fila, 'marca'),
            'modelo' => $this->valor($fila, 'modelo'),
            'color' => $this->valor($fila, 'color'),
            'capacidad' => $this->valor($fila, 'capacidad'),
            'numero_economico' => $this->valor($fila, 'numero_economico'),
        ];

        $empresa = $this->valor($fila, 'empresa');
        if ($empresa !== null) {
            $proveedor = $this->proveedores->buscarPorNombre($empresa);
            if ($proveedor === null) {
                $errores['empresa'] = ["No existe la empresa «{$empresa}» en Empresas externas: impórtala o regístrala primero."];
            }
            $datos['proveedor_id'] = $proveedor?->id;
        }

        $dueno = $this->valor($fila, 'colaborador');
        if ($dueno !== null) {
            $colaborador = Colaborador::where('num_empleado', mb_strtoupper($dueno))->first();
            if ($colaborador === null) {
                $errores['colaborador'] = ["No existe un colaborador con el número de empleado «{$dueno}»."];
            }
            $datos['colaborador_id'] = $colaborador?->id;
        }

        return [$datos, $errores];
    }
}
